<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Checks whether a workflow is consistent enough to run. A workflow that has no
 * (readable) source file, or whose configured columns are missing from it, must
 * never be executed (import, send, export, PDF) – typically right after a copy,
 * before a new source file has been loaded.
 */
class WorkflowValidator
{
    public function __construct(
        private readonly SpreadsheetInspector $inspector,
        private readonly LinkGenerator $linkGenerator,
        private readonly Connection $connection,
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

            if ('' !== $field && !\in_array($field, $headers, true)) {
                $problems[] = sprintf($this->msg('storage_missing'), $field, (string) $question->label);
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

    private function msg(string $key): string
    {
        return (string) ($GLOBALS['TL_LANG']['workflow_validator'][$key] ?? $key);
    }

    public function isRunnable(WorkflowModel $workflow): bool
    {
        return [] === $this->getProblems($workflow);
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
        $headerDependent = ['sourceSheet', 'emailField', 'questions', 'rules'];

        if (!$workflow->sourceFile) {
            return $headerDependent;
        }

        $headers = array_keys($this->inspector->getHeaderOptions($workflow));

        if ([] === $headers) {
            return $headerDependent;
        }

        $orphaned = [];

        if ('' !== trim((string) $workflow->emailField) && !\in_array(trim((string) $workflow->emailField), $headers, true)) {
            $orphaned[] = 'emailField';
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
