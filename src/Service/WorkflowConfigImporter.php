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
    public const VERSION = 1;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $config a decoded configuration document
     *
     * @throws \InvalidArgumentException on an unknown/unsupported document
     */
    public function materialize(
        array $config,
        bool $createMaster = false,
        bool $createNotifications = false,
        ?string $sourceUuid = null,
    ): WorkflowModel {
        $this->framework->initialize();
        $this->assertValid($config);

        $masterId = 0;

        if ($createMaster && \is_array($config['master'] ?? null)) {
            $masterId = $this->createMaster($config['master']);
        }

        $nc = ['invite' => 0, 'reminder' => 0, 'result' => 0];

        if ($createNotifications && \is_array($config['notifications'] ?? null)) {
            $nc = array_merge($nc, $this->createNotifications($config['notifications']));
        }

        $workflowId = $this->createWorkflow((array) $config['workflow'], $masterId, $nc, $sourceUuid);
        $this->createQuestions($workflowId, (array) ($config['questions'] ?? []));
        $this->createRules($workflowId, (array) ($config['rules'] ?? []));

        $workflow = WorkflowModel::findByPk($workflowId);

        if (null === $workflow) {
            throw new \RuntimeException('Imported workflow could not be loaded.');
        }

        return $workflow;
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
     * Creates the three workflow e-mail templates (invite/reminder/result) in the
     * Notification Center, reusing an existing e-mail gateway (creating one only if
     * none exists). Returns the new notification ids keyed by invite|reminder|result.
     *
     * @param array<string, mixed> $notifications
     *
     * @return array<string, int>
     */
    private function createNotifications(array $notifications): array
    {
        $gateway = $this->resolveGateway();
        $ids = [];

        foreach (['invite', 'reminder', 'result'] as $kind) {
            if (!\is_array($notifications[$kind] ?? null)) {
                continue;
            }

            $tpl = $notifications[$kind];

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
                    (string) ($tpl['senderAddress'] ?? 'noreply@example.com'),
                    (string) ($tpl['subject'] ?? ''),
                    (string) ($tpl['text'] ?? ''),
                    (string) ($tpl['attachmentTokens'] ?? ('result' === $kind ? '##attachment##' : '')),
                ],
            );

            $ids[$kind] = $notificationId;
        }

        return $ids;
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
     */
    private function createWorkflow(array $wf, int $masterId, array $nc, ?string $sourceUuid): int
    {
        $steps = array_values(array_filter(array_map('trim', (array) ($wf['steps'] ?? []))));
        $steps = $steps ?: ['Importiert', 'Eingeladen', 'Beantwortet'];
        $inputFields = array_values(array_filter(array_map('trim', (array) ($wf['inputFields'] ?? []))));

        $this->connection->executeStatement(
            'INSERT INTO tl_workflow '
            .'(tstamp, title, published, steps, sourceFile, sourceSheet, headerRow, emailField, inputFields, '
            .'requireSignature, formPage, master, pdfBodyType, pdfBodyTemplate, pdfTitle, pdfSignatureDate, '
            .'pdfSignatureLocation, pdfFileName, ncInvite, ncReminder, ncResult) '
            .'VALUES (UNIX_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) $wf['title'],
                ($wf['published'] ?? true) ? '1' : '',
                serialize($steps),
                $sourceUuid,
                (string) ($wf['sourceSheet'] ?? ''),
                max(1, (int) ($wf['headerRow'] ?? 1)),
                (string) ($wf['emailField'] ?? ''),
                serialize($inputFields),
                ($wf['requireSignature'] ?? false) ? '1' : '',
                $masterId,
                (string) ($wf['pdfBodyType'] ?? 'letter'),
                (string) ($wf['pdfBodyTemplate'] ?? ''),
                (string) ($wf['pdfTitle'] ?? ''),
                (string) ($wf['pdfSignatureDate'] ?? ''),
                (string) ($wf['pdfSignatureLocation'] ?? ''),
                (string) ($wf['pdfFileName'] ?? ''),
                $nc['invite'] ?? 0,
                $nc['reminder'] ?? 0,
                $nc['result'] ?? 0,
            ],
        );

        return (int) $this->connection->lastInsertId();
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
                    $options[] = ['value' => $value, 'label' => (string) ($opt['label'] ?? $value)];
                }
            }

            $this->connection->executeStatement(
                'INSERT INTO tl_workflow_question (pid, sorting, tstamp, label, type, storageField, mandatory, hideInForm, options) '
                .'VALUES (?, ?, UNIX_TIMESTAMP(), ?, ?, ?, ?, ?, ?)',
                [
                    $workflowId,
                    $sorting,
                    (string) ($q['label'] ?? ''),
                    (string) ($q['type'] ?? 'text'),
                    (string) ($q['storageField'] ?? ''),
                    ($q['mandatory'] ?? false) ? '1' : '',
                    ($q['hideInForm'] ?? false) ? '1' : '',
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
