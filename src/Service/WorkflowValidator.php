<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Checks whether a workflow is consistent enough to run. A workflow that has no
 * (readable) source file, or whose configured columns are missing from it, must
 * never be executed (import, send, export, PDF) – typically right after a copy,
 * before a new source file has been loaded.
 */
class WorkflowValidator
{
    public function __construct(private readonly SpreadsheetInspector $inspector)
    {
    }

    /**
     * Human-readable problems; an empty list means the workflow may run.
     *
     * @return array<int, string>
     */
    public function getProblems(WorkflowModel $workflow): array
    {
        if (!$workflow->sourceFile) {
            return ['Es ist keine Quelldatei ausgewählt – der Workflow kann erst nach dem Laden einer Quelldatei ausgeführt werden.'];
        }

        $headers = array_keys($this->inspector->getHeaderOptions($workflow));

        if ([] === $headers) {
            return ['Die Quelldatei ist nicht lesbar oder enthält keine Spalten.'];
        }

        $problems = [];
        $email = trim((string) $workflow->emailField);

        if ('' === $email) {
            $problems[] = 'Es ist keine E-Mail-Spalte gewählt.';
        } elseif (!\in_array($email, $headers, true)) {
            $problems[] = sprintf('Die E-Mail-Spalte „%s" fehlt in der Quelldatei.', $email);
        }

        foreach ($workflow->getQuestions() as $question) {
            $field = trim((string) $question->storageField);

            if ('' !== $field && !\in_array($field, $headers, true)) {
                $problems[] = sprintf('Das Speicherfeld „%s" (Antwortfeld „%s") fehlt in der Quelldatei.', $field, (string) $question->label);
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
            $problems[] = sprintf('Die PDF-Regel „%s" verwendet das unbekannte Feld „%s".', $ruleTitle, $field);
        }

        return $problems;
    }

    public function isRunnable(WorkflowModel $workflow): bool
    {
        return [] === $this->getProblems($workflow);
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
