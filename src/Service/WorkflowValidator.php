<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Excel\ColumnCompatibility;
use Psimandl\WorkflowBundle\Excel\ColumnFormatAnalyzer;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Checks whether a workflow is consistent enough to run. A workflow that has no
 * (readable) source file, or whose configured columns are missing from it, must
 * never be executed (import, send, export, PDF) – typically right after a copy,
 * before a new source file has been loaded.
 */
class WorkflowValidator
{
    /**
     * tl_workflow fields that store the name of a source column. They share one rule: a
     * non-empty value has to be among the current headers, or it is orphaned.
     */
    private const COLUMN_FIELDS = ['emailField', 'pdfSignatureDate', 'pdfSignatureLocation'];

    public function __construct(
        private readonly SpreadsheetInspector $inspector,
        private readonly LinkGenerator $linkGenerator,
        private readonly Connection $connection,
        private readonly ColumnFormatAnalyzer $columnAnalyzer,
        private readonly ColumnCompatibility $columnCompatibility,
    ) {
    }

    /**
     * Human-readable problems; an empty list means the workflow may run.
     *
     * @return array<int, string>
     */
    public function getProblems(WorkflowModel $workflow): array
    {
        System::loadLanguageFile('workflow_messages');

        if (!$workflow->sourceFile) {
            return [$this->msg('no_source')];
        }

        // A named sheet that the file does not contain is the most likely reason for "no
        // columns" after the source file was swapped – say so instead of the generic message.
        $sheet = trim((string) $workflow->sourceSheet);
        $sheets = $this->inspector->getSheetNames($workflow);

        if ('' !== $sheet && [] !== $sheets && !\in_array($sheet, $sheets, true)) {
            // Joined without quotes: the message supplies them, and they differ per language.
            return [sprintf($this->msg('sheet_missing'), $sheet, implode(', ', $sheets))];
        }

        $headers = array_keys($this->inspector->getHeaderOptions($workflow));

        if ([] === $headers) {
            return [$this->msg('source_unreadable')];
        }

        $problems = [];
        $email = trim((string) $workflow->emailField);

        if ('' === $email) {
            $problems[] = $this->msg('no_email_col');
        } elseif (!\in_array($email, $headers, true)) {
            $problems[] = sprintf($this->msg('email_col_missing'), $email);
        }

        foreach ($workflow->getQuestions() as $question) {
            $field = trim((string) $question->storageField);

            if ('' === $field) {
                continue;
            }

            if (!\in_array($field, $headers, true)) {
                $problems[] = sprintf($this->msg('storage_missing'), $field, (string) $question->label);

                continue;
            }

            // The column is checked when the field is saved, but the source file can be
            // swapped afterwards – a new file with three decimals would otherwise only
            // surface as silently rounded values in the finished documents.
            if ($question->isNumber()) {
                foreach ($this->numberColumnProblems($workflow, $question, $field) as $problem) {
                    $problems[] = $problem;
                }
            }
        }

        $unknownRuleFields = [];

        foreach ($workflow->getRules() as $rule) {
            foreach ($rule->getConditions() as $condition) {
                $field = $condition['field'];

                if (!\in_array($field, $headers, true) && !isset($unknownRuleFields[$field])) {
                    $unknownRuleFields[$field] = (string) $rule->title;
                }
            }
        }

        foreach ($unknownRuleFields as $field => $ruleTitle) {
            $problems[] = sprintf($this->msg('rule_unknown_field'), $ruleTitle, $field);
        }

        // A deleted letterhead (dangling master id) breaks the produced document: the
        // PDF silently falls back to the default template without the configured
        // letterhead. Treated as "not runnable" so it is fixed before running.
        if ((int) $workflow->master > 0 && !$this->recordExists('tl_workflow_master', (int) $workflow->master)) {
            $problems[] = $this->msg('master_missing');
        }

        return $problems;
    }

    /**
     * Formatting problems of a "number" field's storage column, prefixed with the field so
     * the message is actionable from the workflow list ("Antwortfeld „Betrag": …").
     *
     * @return array<int, string>
     */
    private function numberColumnProblems(WorkflowModel $workflow, QuestionModel $question, string $column): array
    {
        $result = $this->columnCompatibility->checkNumberColumn(
            $column,
            $this->columnAnalyzer->analyze($workflow, $column),
        );

        if ($result->isCompatible()) {
            return [];
        }

        return [sprintf('Antwortfeld „%s": %s', (string) $question->label, implode(' ', $result->problems))];
    }

    /**
     * Non-blocking warnings about the notification sender address — the original root cause
     * of silent bounce loss: a sender domain with no MX record (a placeholder like
     * example.com, or a typo such as .de instead of .com) makes the envelope sender
     * undeliverable, and every bounce for it disappears unnoticed while the send itself looks
     * perfectly healthy. A mismatch with the website domain is flagged too (SPF/DKIM/DMARC
     * alignment). These are hints on the edit mask, not run blockers.
     *
     * @return array<int, string>
     */
    public function getSenderWarnings(WorkflowModel $workflow): array
    {
        System::loadLanguageFile('workflow_messages');

        return $this->senderWarnings([
            (int) $workflow->ncInvite,
            (int) $workflow->ncReminder,
            (int) $workflow->ncResult,
        ]);
    }

    /**
     * Core of {@see getSenderWarnings()}, decoupled from the model so it is unit-testable.
     *
     * @param array<int, int> $notificationIds
     *
     * @return array<int, string>
     */
    public function senderWarnings(array $notificationIds): array
    {
        $siteDomains = $this->siteDomains();
        $warnings = [];
        $seen = [];

        foreach ($notificationIds as $notificationId) {
            if ($notificationId <= 0 || !$this->recordExists('tl_nc_notification', $notificationId)) {
                continue;
            }

            $sender = $this->senderAddress($notificationId);

            // An empty sender means the Notification Center falls back to the system admin
            // address; nothing to warn about here.
            if ('' === $sender) {
                continue;
            }

            $domain = $this->domainOf($sender);

            if ('' === $domain || isset($seen[$domain])) {
                continue;
            }

            $seen[$domain] = true;

            // example.com and friends resolve and even carry an MX (IANA reserves them), yet
            // they black-hole mail — so they must be caught explicitly, before the MX check.
            if ($this->isPlaceholderDomain($domain)) {
                $warnings[] = sprintf($this->msg('sender_placeholder'), $sender, $domain);

                continue;
            }

            if (!$this->hasMxRecord($domain)) {
                $warnings[] = sprintf($this->msg('sender_no_mx'), $sender, $domain);

                continue;
            }

            if ([] !== $siteDomains && !$this->matchesAnyDomain($domain, $siteDomains)) {
                $warnings[] = sprintf($this->msg('sender_domain_mismatch'), $domain, implode(', ', $siteDomains));
            }
        }

        return $warnings;
    }

    /**
     * The sender e-mail configured on a Notification Center notification
     * (tl_nc_notification → tl_nc_message → tl_nc_language.email_sender_address), preferring
     * the fallback language.
     */
    private function senderAddress(int $notificationId): string
    {
        return (string) ($this->connection->fetchOne(
            'SELECT l.email_sender_address FROM tl_nc_language l '
            .'INNER JOIN tl_nc_message m ON m.id = l.pid '
            ."WHERE m.pid = ? AND l.email_sender_address <> '' "
            .'ORDER BY l.fallback DESC LIMIT 1',
            [$notificationId],
        ) ?: '');
    }

    private function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        return false === $at ? '' : strtolower(trim(substr($email, $at + 1)));
    }

    /**
     * Distinct DNS domains of the site's root pages. Empty when every root serves any domain
     * (no fixed dns), in which case a domain-mismatch check is not meaningful.
     *
     * @return array<int, string>
     */
    private function siteDomains(): array
    {
        return array_values(array_filter(array_map(
            static fn ($dns): string => strtolower(trim((string) $dns)),
            $this->connection->fetchFirstColumn("SELECT DISTINCT dns FROM tl_page WHERE type = 'root' AND dns <> ''"),
        )));
    }

    /**
     * @param array<int, string> $domains
     */
    private function matchesAnyDomain(string $domain, array $domains): bool
    {
        foreach ($domains as $candidate) {
            // Exact, or one is a subdomain of the other (mail.example.com vs example.com).
            if ($domain === $candidate || str_ends_with($domain, '.'.$candidate) || str_ends_with($candidate, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * RFC 2606 / 6761 reserved example and testing domains. They are never real senders.
     */
    private function isPlaceholderDomain(string $domain): bool
    {
        if (\in_array($domain, ['example.com', 'example.net', 'example.org', 'example.edu'], true)) {
            return true;
        }

        foreach (['.example', '.invalid', '.test', '.localhost'] as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the domain has an MX record. Wrapped so tests can drive it without live DNS.
     */
    protected function hasMxRecord(string $domain): bool
    {
        return '' !== $domain && @checkdnsrr($domain, 'MX');
    }

    private function msg(string $key): string
    {
        return (string) ($GLOBALS['TL_LANG']['workflow_validator'][$key] ?? $key);
    }

    public function isRunnable(WorkflowModel $workflow): bool
    {
        return [] === $this->getProblems($workflow);
    }

    /**
     * Whether the source file has changed since the last import, so the stored entries (and,
     * crucially, the snapshotted number formats) are stale until a re-import runs. Until then
     * the form/PDF preview keeps showing the old data and formatting.
     *
     * Detected by comparing the current file's checksum with the one the importer recorded on
     * its last successful run (tl_workflow.sourceHash). Deliberately scoped to "changed AFTER an
     * import": a never-imported workflow (empty sourceHash) is guided by the separate "run
     * import" hint, and a missing/unreadable file is a separate problem ({@see getProblems()}).
     */
    public function isReimportNeeded(WorkflowModel $workflow): bool
    {
        if (!$workflow->sourceFile || '' === (string) $workflow->sourceHash) {
            return false;
        }

        $path = $this->inspector->resolvePath($workflow);

        if (null === $path) {
            return false;
        }

        $hash = @md5_file($path);

        return false !== $hash && $hash !== (string) $workflow->sourceHash;
    }

    /**
     * Reasons why invitations/reminders cannot be sent (in addition to being runnable):
     * a valid form page is required for the link, and at least one notification must be
     * assigned. An empty list means sending is possible.
     *
     * @return array<int, string>
     */
    public function getSendBlockers(WorkflowModel $workflow): array
    {
        $blockers = [];

        if (null === $this->linkGenerator->resolveFormPage($workflow)) {
            $blockers[] = 'keine (gültige) Formularseite zugeordnet – ohne sie kann kein Einladungslink erzeugt werden';
        }

        // Without this an unpublished workflow mails out links that every recipient then finds
        // "ungültig" (WorkflowFormController checks published before anything else) – a mistake
        // that is invisible at send time and only surfaces through participants asking.
        if (!$workflow->published) {
            $blockers[] = 'der Workflow ist nicht veröffentlicht – die versendeten Links würden allen Teilnehmern als „ungültig" angezeigt';
        }

        // Require an EXISTING notification, not just a non-zero id: a deleted
        // notification (dangling id) would otherwise pass and fail at send time.
        if (!$this->recordExists('tl_nc_notification', (int) $workflow->ncInvite)
            && !$this->recordExists('tl_nc_notification', (int) $workflow->ncReminder)
        ) {
            $blockers[] = 'keine gültige E-Mail-Benachrichtigung (Einladung/Erinnerung) zugeordnet';
        }

        return $blockers;
    }

    public function canSend(WorkflowModel $workflow): bool
    {
        return $this->isRunnable($workflow) && [] === $this->getSendBlockers($workflow);
    }

    /**
     * tl_workflow fields whose stored value cannot be resolved against the current
     * source columns – marked in the edit mask. When there is no source file at
     * all, every header-dependent field is flagged.
     *
     * @return array<int, string>
     */
    public function orphanedFields(WorkflowModel $workflow): array
    {
        $headerDependent = ['sourceSheet', 'emailField', 'pdfSignatureDate', 'pdfSignatureLocation', 'questions', 'rules'];

        if (!$workflow->sourceFile) {
            return $headerDependent;
        }

        $headers = array_keys($this->inspector->getHeaderOptions($workflow));

        if ([] === $headers) {
            return $headerDependent;
        }

        $orphaned = [];

        // Every field whose stored value is a source column name, checked the same way. The
        // two signature-line fields belong here as much as emailField does: they name a
        // column too, and a copy (which drops the source file) leaves them pointing at
        // nothing.
        foreach (self::COLUMN_FIELDS as $field) {
            $value = trim((string) $workflow->{$field});

            if ('' !== $value && !\in_array($value, $headers, true)) {
                $orphaned[] = $field;
            }
        }

        foreach ($workflow->getQuestions() as $question) {
            $field = trim((string) $question->storageField);

            if ('' !== $field && !\in_array($field, $headers, true)) {
                $orphaned[] = 'questions';
                break;
            }
        }

        foreach ($workflow->getRules() as $rule) {
            foreach ($rule->getConditions() as $condition) {
                if (!\in_array($condition['field'], $headers, true)) {
                    $orphaned[] = 'rules';
                    break 2;
                }
            }
        }

        return $orphaned;
    }

    /**
     * Reference fields (form page, letterhead, notifications) whose stored id points to
     * a record that does not exist – e.g. a letterhead or notification that was deleted
     * afterwards, so the select shows Contao's "Unbekannte Option: <id>". Independent of
     * how the value was set (manual or import). An empty (0) reference is NOT dangling –
     * it is simply unset.
     *
     * @return array<int, string>
     */
    public function danglingReferences(WorkflowModel $workflow): array
    {
        $fields = [];

        if ((int) $workflow->formPage > 0 && !$this->recordExists('tl_page', (int) $workflow->formPage)) {
            $fields[] = 'formPage';
        }

        if ((int) $workflow->master > 0 && !$this->recordExists('tl_workflow_master', (int) $workflow->master)) {
            $fields[] = 'master';
        }

        foreach (['ncInvite', 'ncReminder', 'ncResult'] as $field) {
            if ((int) $workflow->{$field} > 0 && !$this->recordExists('tl_nc_notification', (int) $workflow->{$field})) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * All reference fields that should be flagged red in the edit mask: dangling ones
     * (deleted target) plus import-recorded gaps that could not be linked and stored a
     * 0 (dangling ids are already covered by danglingReferences). The union, de-duped.
     *
     * @return array<int, string>
     */
    public function invalidReferences(WorkflowModel $workflow): array
    {
        $fields = $this->danglingReferences($workflow);

        foreach ($this->unresolvedImportReferences($workflow) as $field) {
            if (!\in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Reference fields (form page, letterhead, notifications) that a configuration
     * import recorded as unlinked (tl_workflow.importIssues) and that STILL do not
     * resolve to an existing record on this site. These are outlined red in the edit
     * mask so the user re-selects them; the list is pruned on save
     * ({@see pruneImportReferenceIssues()}).
     *
     * @return array<int, string>
     */
    public function unresolvedImportReferences(WorkflowModel $workflow): array
    {
        $flagged = StringUtil::deserialize($workflow->importIssues, true);

        return array_values(array_filter(
            $flagged,
            fn ($field): bool => \is_string($field) && !$this->referenceResolves($workflow, $field),
        ));
    }

    /**
     * Recomputes tl_workflow.importIssues, dropping references the user has since
     * fixed. Called on save; returns the remaining unresolved fields.
     *
     * @return array<int, string>
     */
    public function pruneImportReferenceIssues(WorkflowModel $workflow): array
    {
        // Nothing flagged → nothing to prune (avoids a write on every workflow save).
        if ([] === StringUtil::deserialize($workflow->importIssues, true)) {
            return [];
        }

        $remaining = $this->unresolvedImportReferences($workflow);

        $this->connection->update(
            'tl_workflow',
            ['importIssues' => $remaining ? serialize($remaining) : null],
            ['id' => (int) $workflow->id],
        );

        return $remaining;
    }

    private function referenceResolves(WorkflowModel $workflow, string $field): bool
    {
        return match ($field) {
            'formPage' => null !== $this->linkGenerator->resolveFormPage($workflow),
            'master' => $this->recordExists('tl_workflow_master', (int) $workflow->master),
            'ncInvite', 'ncReminder', 'ncResult' => $this->recordExists('tl_nc_notification', (int) $workflow->{$field}),
            default => true,
        };
    }

    private function recordExists(string $table, int $id): bool
    {
        return $id > 0 && false !== $this->connection->fetchOne("SELECT id FROM $table WHERE id = ?", [$id]);
    }
}
