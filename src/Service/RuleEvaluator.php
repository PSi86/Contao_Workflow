<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Service;

use Psimandl\TrainerWorkflowBundle\Model\EntryModel;
use Psimandl\TrainerWorkflowBundle\Model\RuleModel;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;

/**
 * Selects the letter body for an entry by evaluating a workflow's rules against
 * the stored answers (letter mode only). Rules are checked in sorting order; the
 * first rule whose conditions all match wins. A rule flagged "isDefault" always
 * matches and thus acts as the "Standardtext"/else case. Returns null when none.
 */
class RuleEvaluator
{
    public function resolveRule(WorkflowModel $workflow, EntryModel $entry): ?RuleModel
    {
        $data = $entry->getData();

        foreach ($workflow->getRules() as $rule) {
            if ($this->matches($rule, $data)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function matches(RuleModel $rule, array $data): bool
    {
        // The "Standardtext" rule always matches (it is the explicit else case).
        if ($rule->isDefaultRule()) {
            return true;
        }

        $conditions = $rule->getConditions();

        // A non-default rule without (complete) conditions never matches.
        if ([] === $conditions) {
            return false;
        }

        foreach ($conditions as $condition) {
            $actual = (string) ($data[$condition['field']] ?? '');

            if (!$this->evaluate($actual, $condition['operator'], $condition['value'])) {
                return false;
            }
        }

        return true;
    }

    private function evaluate(string $actual, string $operator, string $expected): bool
    {
        switch ($operator) {
            case 'empty':
                return '' === trim($actual);
            case 'notempty':
                return '' !== trim($actual);
            case 'contains':
                return '' !== $expected && false !== mb_stripos($actual, $expected);
        }

        // Numeric comparison when both sides are numeric, string comparison otherwise.
        if (is_numeric(trim($actual)) && is_numeric(trim($expected))) {
            $a = (float) $actual;
            $b = (float) $expected;

            return match ($operator) {
                'eq'  => $a === $b,
                'neq' => $a !== $b,
                'lt'  => $a < $b,
                'lte' => $a <= $b,
                'gt'  => $a > $b,
                'gte' => $a >= $b,
                default => false,
            };
        }

        $cmp = strcmp($actual, $expected);

        return match ($operator) {
            'eq'  => $actual === $expected,
            'neq' => $actual !== $expected,
            'lt'  => $cmp < 0,
            'lte' => $cmp <= 0,
            'gt'  => $cmp > 0,
            'gte' => $cmp >= 0,
            default => false,
        };
    }
}
