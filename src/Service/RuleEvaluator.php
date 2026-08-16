<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\RuleModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Selects the letter body for an entry by evaluating a workflow's rules against
 * the stored answers (letter mode only). Rules are checked in sorting order; the
 * first rule whose conditions all match wins. A rule flagged "isDefault" always
 * matches and thus acts as the "Standardtext"/else case. Returns null when none.
 */
class RuleEvaluator
{
    public function __construct(private readonly ConditionMatcher $matcher)
    {
    }

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

            // Shared with the conditional form fields, so "ist gleich" cannot mean one thing
            // in the form and another in the document (see ConditionMatcher).
            if (!$this->matcher->matches($actual, $condition['operator'], $condition['value'])) {
                return false;
            }
        }

        return true;
    }
}
