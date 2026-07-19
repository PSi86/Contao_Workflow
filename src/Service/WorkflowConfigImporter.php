<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Materialises a portable workflow configuration (see WorkflowConfigExporter for the
 * format) into the database: the workflow itself plus its answer fields and PDF rules,
 * and – on request – the embedded letterhead (master) and the e-mail templates
 * (Notification Center). The created workflow has NO source file, so it is reported as
 * "not runnable" until the user attaches one – which is the intended behaviour for
 * imported presets.
 *
 * Used by the back end import (uploaded configuration files) and, with a source
 * file, by the demo seeder ({@see DemoWorkflowSeeder}).
 */
class WorkflowConfigImporter
{
    public const FORMAT = 'contao-workflow-config';
    // v2: option "statement" texts, per-question pdfStatement/prefill, display
    // questions instead of the removed workflow inputFields (v1 documents are
    // still accepted; their inputFields import as read-only questions).
    // v3: per-question readOnly flag replaces the v2 "display" type (mapped on
    // import), "number" type, workflow introText.
    // v4: per-question "description" (form-only) and "showStatementInForm" flag,
    // "explanation" question type (a static text paragraph).
    // v5: workflow form page (id + name), letterhead id and notification ids are
    // exported so a re-import on the same site re-links to the existing element
    // (id + name must match); an element that cannot be linked is recorded in
    // tl_workflow.importIssues and flagged red in the edit mask.
    public const VERSION = 5;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Materialises the configuration. Nothing is overwritten and nothing is created
     * under an already used name: a conflicting workflow title aborts the whole import
     * (so no orphaned letterhead/templates are left behind), while a conflicting
     * letterhead or e-mail template is skipped individually and reported back.
     *
     * @param array<string, mixed> $config a decoded configuration document
     *
     * @return array{workflow: WorkflowModel, skippedMaster: string|null, skippedNotifications: array<int, string>, importIssues: array<int, string>}
     *
     * @throws \InvalidArgumentException on an unknown/unsupported document
     * @throws \RuntimeException         when the workflow title is already taken
     */
    public function materialize(
        array $config,
        bool $createMaster = false,
        bool $createNotifications = false,
        ?string $sourceUuid = null,
    ): array {
        $this->framework->initialize();
        $this->assertValid($config);

        $workflowTitle = (string) $config['workflow']['title'];

        if ($this->workflowTitleExists($workflowTitle)) {
            throw new \RuntimeException(sprintf(
                'Es existiert bereits ein Workflow mit dem Titel „%s". Bitte den vorhandenen Workflow '
                .'umbenennen oder den Titel in der JSON-Datei ändern und erneut importieren.',
                $workflowTitle,
            ));
        }

        // Letterhead, e-mail templates and form page are re-linked strictly (id + name
        // must match an existing element) and otherwise created (letterhead/templates,
        // on request) or left unlinked. Everything that cannot be linked is collected in
        // $importIssues and flagged red in the edit mask.
        [$masterId, $skippedMaster] = $this->resolveMaster($config['master'] ?? null, $createMaster);

        [$nc, $skippedNotifications] = $this->resolveNotifications($config['notifications'] ?? null, $createNotifications);

        $formPage = $this->resolveFormPageId((array) $config['workflow']);

        $importIssues = $this->collectImportIssues($config, $formPage, $masterId, $nc);

        $workflowId = $this->createWorkflow((array) $config['workflow'], $masterId, $nc, $formPage, $importIssues, $sourceUuid);

        // v1 compatibility: the former read-only workflow "inputFields" become
        // read-only text fields, rendered before the other questions.
        $questions = (array) ($config['questions'] ?? []);

        foreach (array_reverse($this->legacyInputFields((array) $config['workflow'])) as $field) {
            array_unshift($questions, ['label' => $field, 'type' => 'text', 'readOnly' => true, 'storageField' => $field]);
        }

        $this->createQuestions($workflowId, $questions);
        $this->createRules($workflowId, (array) ($config['rules'] ?? []));

        $workflow = WorkflowModel::findByPk($workflowId);

        if (null === $workflow) {
            throw new \RuntimeException('Imported workflow could not be loaded.');
        }

        return [
            'workflow'             => $workflow,
            'skippedMaster'        => $skippedMaster,
            'skippedNotifications' => $skippedNotifications,
            'importIssues'         => $importIssues,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function assertValid(array $config): void
    {
        if (self::FORMAT !== ($config['format'] ?? null)) {
            throw new \InvalidArgumentException('Unbekanntes Format – keine Workflow-Konfiguration.');
        }

        if ((int) ($config['version'] ?? 0) > self::VERSION) {
            throw new \InvalidArgumentException('Die Konfiguration stammt aus einer neueren Bundle-Version.');
        }

        if (!\is_array($config['workflow'] ?? null) || '' === trim((string) ($config['workflow']['title'] ?? ''))) {
            throw new \InvalidArgumentException('Der Konfiguration fehlt ein Workflow mit Titel.');
        }
    }

    /**
     * @param array<string, mixed> $master
     */
    private function createMaster(array $master): int
    {
        $pdfData = [];

        foreach ((array) ($master['pdfData'] ?? []) as $pair) {
            $key = trim((string) ($pair['key'] ?? ''));

            if ('' !== $key) {
                $pdfData[] = ['key' => $key, 'value' => (string) ($pair['value'] ?? '')];
            }
        }

        $this->connection->executeStatement(
            'INSERT INTO tl_workflow_master (tstamp, title, masterTemplate, pdfLogo, pdfData) '
            .'VALUES (UNIX_TIMESTAMP(), ?, ?, NULL, ?)',
            [
                (string) ($master['title'] ?? 'Briefpapier'),
                (string) ($master['masterTemplate'] ?? 'pdf_master_generic'),
                serialize($pdfData),
            ],
        );

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Resolves the workflow's letterhead: re-links to an existing letterhead when the
     * exported id AND title match one (regardless of the "create letterhead" option),
     * otherwise – on request – creates a fresh one from the embedded config. A title
     * that is taken by a *different* letterhead is neither hijacked nor duplicated: it
     * is reported as skipped and the workflow stays unlinked (flagged red).
     *
     * @param mixed $ref the exported master reference (array) or null
     *
     * @return array{0: int, 1: string|null} [masterId, skippedMasterTitle]
     */
    private function resolveMaster($ref, bool $createMaster): array
    {
        if (!\is_array($ref)) {
            return [0, null];
        }

        $refId = (int) ($ref['id'] ?? 0);
        $refTitle = (string) ($ref['title'] ?? 'Briefpapier');

        // Same site: re-link to the exact letterhead the workflow used before.
        if ($this->referenceMatches('tl_workflow_master', $refId, $refTitle)) {
            return [$refId, null];
        }

        if (!$createMaster) {
            return [0, null];
        }

        if ($this->masterTitleExists($refTitle)) {
            return [0, $refTitle];
        }

        return [$this->createMaster($ref), null];
    }

    /**
     * Resolves the three workflow e-mail templates (invite/reminder/result). Each is
     * re-linked to an existing notification when the exported id AND title match one
     * (independently of the "create templates" option, so a same-site re-import keeps
     * its notifications); otherwise – on request – a fresh template is created in the
     * Notification Center (reusing an e-mail gateway, creating one only if none exists).
     * A title taken by a different notification is skipped (never duplicated).
     *
     * @param mixed $notifications the exported notifications map (array) or null
     *
     * @return array{0: array<string, int>, 1: array<int, string>} [ids keyed by kind, skipped titles]
     */
    private function resolveNotifications($notifications, bool $createNotifications): array
    {
        $nc = ['invite' => 0, 'reminder' => 0, 'result' => 0];
        $skipped = [];

        if (!\is_array($notifications)) {
            return [$nc, $skipped];
        }

        $gateway = null;

        foreach (['invite', 'reminder', 'result'] as $kind) {
            if (!\is_array($notifications[$kind] ?? null)) {
                continue;
            }

            $tpl = $notifications[$kind];
            $refId = (int) ($tpl['id'] ?? 0);
            $title = (string) ($tpl['title'] ?? ucfirst($kind));

            // Same site: re-link to the exact notification the workflow used before.
            if ($this->referenceMatches('tl_nc_notification', $refId, $title)) {
                $nc[$kind] = $refId;

                continue;
            }

            if (!$createNotifications) {
                continue;
            }

            // Re-checked per insert, so even identical titles within the same document
            // are not duplicated (the first one wins, the rest are skipped).
            if ($this->notificationTitleExists($title)) {
                $skipped[] = $title;

                continue;
            }

            $gateway ??= $this->resolveGateway();
            $nc[$kind] = $this->createNotification($kind, (array) $tpl, $gateway);
        }

        return [$nc, $skipped];
    }

    /**
     * Creates one workflow e-mail template (a notification with an e-mail message and a
     * German fallback language) and returns its new id.
     *
     * @param array<string, mixed> $tpl
     */
    private function createNotification(string $kind, array $tpl, int $gateway): int
    {
        $this->connection->executeStatement(
            "INSERT INTO tl_nc_notification (tstamp, title, type) VALUES (UNIX_TIMESTAMP(), ?, 'workflow')",
            [(string) ($tpl['title'] ?? ucfirst($kind))],
        );
        $notificationId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            'INSERT INTO tl_nc_message (pid, tstamp, sorting, title, gateway, email_template, published) '
            ."VALUES (?, UNIX_TIMESTAMP(), 128, 'E-Mail', ?, 'mail_default', 1)",
            [$notificationId, $gateway],
        );
        $messageId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            'INSERT INTO tl_nc_language '
            .'(pid, tstamp, language, fallback, recipients, email_sender_name, email_sender_address, '
            .'email_subject, email_mode, email_text, attachment_tokens) '
            ."VALUES (?, UNIX_TIMESTAMP(), 'de', 1, '##email##', ?, ?, ?, 'textOnly', ?, ?)",
            [
                $messageId,
                (string) ($tpl['senderName'] ?? 'Workflow'),
                (string) ($tpl['senderAddress'] ?? $this->defaultSenderAddress()),
                (string) ($tpl['subject'] ?? ''),
                (string) ($tpl['text'] ?? ''),
                (string) ($tpl['attachmentTokens'] ?? ('result' === $kind ? '##attachment##' : '')),
            ],
        );

        return $notificationId;
    }

    /**
     * Default e-mail sender for a materialized notification when the config does not specify
     * one: derived from the site's root domain (noreply@<dns>). Empty when no root has a
     * fixed domain — the Notification Center then falls back to the system admin address,
     * which beats shipping an undeliverable placeholder like noreply@example.com.
     */
    private function defaultSenderAddress(): string
    {
        $dns = (string) ($this->connection->fetchOne(
            "SELECT dns FROM tl_page WHERE type = 'root' AND dns <> '' ORDER BY sorting LIMIT 1",
        ) ?: '');

        return '' !== $dns ? 'noreply@'.$dns : '';
    }

    /**
     * Resolves the workflow's form page id: keeps the exported page id only when a page
     * with that id AND name exists (same site). A missing page is kept as a dangling id
     * so nothing is silently lost; a page whose name differs is dropped to 0 so the
     * workflow never points at an unrelated page. Either way it is recorded as an import
     * issue and flagged red.
     *
     * @param array<string, mixed> $wf
     */
    private function resolveFormPageId(array $wf): int
    {
        $pageId = (int) ($wf['formPage'] ?? 0);

        if ($pageId <= 0) {
            return 0;
        }

        if ($this->referenceMatches('tl_page', $pageId, (string) ($wf['formPageName'] ?? ''))) {
            return $pageId;
        }

        // Missing page → keep the id (dangling, turns red); existing page with another
        // name → 0 (do not hijack a foreign page).
        return $this->recordExists('tl_page', $pageId) ? 0 : $pageId;
    }

    /**
     * Reference fields whose exported value could not be linked to an existing element
     * on this site (form page, letterhead, notifications). Persisted in
     * tl_workflow.importIssues and used to flag exactly those fields red in the edit
     * mask – only import-caused gaps, not the empty fields of a manually built workflow.
     *
     * @param array<string, mixed> $config
     * @param array<string, int>   $nc
     *
     * @return array<int, string>
     */
    private function collectImportIssues(array $config, int $formPage, int $masterId, array $nc): array
    {
        $issues = [];
        $wf = (array) ($config['workflow'] ?? []);

        // A form page was exported but could not be linked (id + name mismatch/missing).
        if ((int) ($wf['formPage'] ?? 0) > 0 && !$this->referenceMatches('tl_page', $formPage, (string) ($wf['formPageName'] ?? ''))) {
            $issues[] = 'formPage';
        }

        if (\is_array($config['master'] ?? null) && $masterId <= 0) {
            $issues[] = 'master';
        }

        foreach (['invite' => 'ncInvite', 'reminder' => 'ncReminder', 'result' => 'ncResult'] as $kind => $field) {
            if (\is_array($config['notifications'][$kind] ?? null) && ($nc[$kind] ?? 0) <= 0) {
                $issues[] = $field;
            }
        }

        return $issues;
    }

    /**
     * Whether a row with the given id exists in $table AND its title equals $title.
     * $table is an internal constant (never user input), so it is safe to interpolate.
     */
    private function referenceMatches(string $table, int $id, string $title): bool
    {
        if ($id <= 0) {
            return false;
        }

        $found = $this->connection->fetchOne("SELECT title FROM $table WHERE id = ?", [$id]);

        return false !== $found && (string) $found === $title;
    }

    private function recordExists(string $table, int $id): bool
    {
        return $id > 0 && false !== $this->connection->fetchOne("SELECT id FROM $table WHERE id = ?", [$id]);
    }

    private function workflowTitleExists(string $title): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT id FROM tl_workflow WHERE title = ? LIMIT 1',
            [$title],
        );
    }

    private function masterTitleExists(string $title): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT id FROM tl_workflow_master WHERE title = ? LIMIT 1',
            [$title],
        );
    }

    private function notificationTitleExists(string $title): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT id FROM tl_nc_notification WHERE title = ? LIMIT 1',
            [$title],
        );
    }

    private function resolveGateway(): int
    {
        $existing = $this->connection->fetchOne(
            "SELECT id FROM tl_nc_gateway WHERE type = 'mailer' ORDER BY id LIMIT 1",
        );

        if (false !== $existing) {
            return (int) $existing;
        }

        $this->connection->executeStatement(
            "INSERT INTO tl_nc_gateway (tstamp, title, type, mailerTransport) VALUES (UNIX_TIMESTAMP(), 'E-Mail', 'mailer', '')",
        );

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $wf
     * @param array<string, int>   $nc
     * @param array<int, string>   $importIssues reference fields that could not be linked
     */
    private function createWorkflow(array $wf, int $masterId, array $nc, int $formPage, array $importIssues, ?string $sourceUuid): int
    {
        $steps = array_values(array_filter(array_map('trim', (array) ($wf['steps'] ?? []))));
        $steps = $steps ?: WorkflowStatus::DEFAULT_STEPS;

        $this->connection->executeStatement(
            'INSERT INTO tl_workflow '
            .'(tstamp, title, published, steps, sourceFile, sourceSheet, headerRow, emailField, '
            .'requireSignature, formPage, master, pdfBodyType, pdfBodyTemplate, pdfTitle, introText, pdfSignatureDate, '
            .'pdfSignatureLocation, pdfFileName, ncInvite, ncReminder, ncResult, importIssues) '
            .'VALUES (UNIX_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) $wf['title'],
                ($wf['published'] ?? true) ? '1' : '',
                serialize($steps),
                $sourceUuid,
                (string) ($wf['sourceSheet'] ?? ''),
                max(1, (int) ($wf['headerRow'] ?? 1)),
                (string) ($wf['emailField'] ?? ''),
                ($wf['requireSignature'] ?? false) ? '1' : '',
                $formPage,
                $masterId,
                (string) ($wf['pdfBodyType'] ?? 'letter'),
                (string) ($wf['pdfBodyTemplate'] ?? ''),
                (string) ($wf['pdfTitle'] ?? ''),
                (string) ($wf['introText'] ?? ''),
                (string) ($wf['pdfSignatureDate'] ?? ''),
                (string) ($wf['pdfSignatureLocation'] ?? ''),
                (string) ($wf['pdfFileName'] ?? ''),
                $nc['invite'] ?? 0,
                $nc['reminder'] ?? 0,
                $nc['result'] ?? 0,
                $importIssues ? serialize($importIssues) : null,
            ],
        );

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Legacy (v1) read-only display columns of the workflow document.
     *
     * @param array<string, mixed> $wf
     *
     * @return array<int, string>
     */
    private function legacyInputFields(array $wf): array
    {
        return array_values(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            (array) ($wf['inputFields'] ?? []),
        )));
    }

    /**
     * @param array<int, mixed> $questions
     */
    private function createQuestions(int $workflowId, array $questions): void
    {
        $sorting = 0;

        foreach ($questions as $q) {
            if (!\is_array($q)) {
                continue;
            }

            $sorting += 128;
            $options = [];

            foreach ((array) ($q['options'] ?? []) as $opt) {
                $value = trim((string) ($opt['value'] ?? ''));

                if ('' !== $value) {
                    $options[] = [
                        'value'     => $value,
                        'label'     => (string) ($opt['label'] ?? $value),
                        'statement' => (string) ($opt['statement'] ?? ''),
                    ];
                }
            }

            $type = (string) ($q['type'] ?? 'text');
            $readOnly = (bool) ($q['readOnly'] ?? false);

            // v2 compatibility: the short-lived "display" type is now a
            // read-only text field.
            if ('display' === $type) {
                $type = 'text';
                $readOnly = true;
            }

            // Legacy configs (v1–v3) have no "showStatementInForm"; default it to on so
            // their fields keep showing the document-text hint in the form.
            $showStatement = ($q['showStatementInForm'] ?? true) ? '1' : '';

            $this->connection->executeStatement(
                'INSERT INTO tl_workflow_question (pid, sorting, tstamp, label, type, storageField, mandatory, prefill, readOnly, hideInForm, description, showStatementInForm, pdfStatement, options) '
                .'VALUES (?, ?, UNIX_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $workflowId,
                    $sorting,
                    (string) ($q['label'] ?? ''),
                    $type,
                    (string) ($q['storageField'] ?? ''),
                    ($q['mandatory'] ?? false) ? '1' : '',
                    ($q['prefill'] ?? false) ? '1' : '',
                    $readOnly ? '1' : '',
                    ($q['hideInForm'] ?? false) ? '1' : '',
                    (string) ($q['description'] ?? ''),
                    $showStatement,
                    (string) ($q['pdfStatement'] ?? ''),
                    $options ? serialize($options) : null,
                ],
            );
        }
    }

    /**
     * @param array<int, mixed> $rules
     */
    private function createRules(int $workflowId, array $rules): void
    {
        $sorting = 0;

        foreach ($rules as $r) {
            if (!\is_array($r)) {
                continue;
            }

            $sorting += 128;
            $conditions = [];

            foreach ((array) ($r['conditions'] ?? []) as $cond) {
                $field = trim((string) ($cond['field'] ?? ''));
                $operator = trim((string) ($cond['operator'] ?? ''));

                if ('' !== $field && '' !== $operator) {
                    $conditions[] = ['field' => $field, 'operator' => $operator, 'value' => (string) ($cond['value'] ?? '')];
                }
            }

            $this->connection->executeStatement(
                'INSERT INTO tl_workflow_rule (pid, sorting, tstamp, title, isDefault, conditions, pdfBody) '
                .'VALUES (?, ?, UNIX_TIMESTAMP(), ?, ?, ?, ?)',
                [
                    $workflowId,
                    $sorting,
                    (string) ($r['title'] ?? ''),
                    ($r['isDefault'] ?? false) ? '1' : '',
                    serialize($conditions),
                    (string) ($r['pdfBody'] ?? ''),
                ],
            );
        }
    }
}
