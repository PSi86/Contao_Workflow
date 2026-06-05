<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\System;
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

        if ((int) $workflow->ncInvite <= 0 && (int) $workflow->ncReminder <= 0) {
            $blockers[] = 'keine E-Mail-Benachrichtigung (Einladung/Erinnerung) zugeordnet';
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
        $headerDependent = ['sourceSheet', 'emailField', 'inputFields', 'questions', 'rules'];

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

        foreach ($workflow->getInputFields() as $field) {
            if (!\in_array($field, $headers, true)) {
                $orphaned[] = 'inputFields';
                break;
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
}
