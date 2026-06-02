<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Model;

use Contao\Model;
use Contao\Model\Collection;
use Contao\StringUtil;

/**
 * Reads and writes tl_trainer_rule (a conditional PDF body selection rule).
 *
 * Rules are evaluated in sorting order; the first rule whose conditions all
 * match selects its body template. If no rule matches, the workflow's default
 * PDF configuration is used.
 *
 * @property int    $id
 * @property int    $pid          Workflow id (tl_trainer_workflow.id).
 * @property int    $sorting      Priority (lower = checked first).
 * @property int    $tstamp
 * @property string $title        Optional rule label.
 * @property string $conditions   Serialized list of [field, operator, value] conditions (AND-combined).
 * @property string $bodyTemplate Body template to use when the rule matches.
 */
class RuleModel extends Model
{
    protected static $strTable = 'tl_trainer_rule';

    /**
     * @return Collection<RuleModel>|array<RuleModel>|null
     */
    public static function findByWorkflow(int $workflowId)
    {
        return static::findBy('pid', $workflowId, ['order' => 'sorting']);
    }

    /**
     * Conditions as a list of field/operator/value triples (incomplete rows skipped).
     *
     * @return array<int, array{field: string, operator: string, value: string}>
     */
    public function getConditions(): array
    {
        $conditions = [];

        foreach (StringUtil::deserialize($this->conditions, true) as $row) {
            $field = trim((string) ($row['field'] ?? ''));
            $operator = trim((string) ($row['operator'] ?? ''));

            if ('' === $field || '' === $operator) {
                continue;
            }

            $conditions[] = [
                'field'    => $field,
                'operator' => $operator,
                'value'    => (string) ($row['value'] ?? ''),
            ];
        }

        return $conditions;
    }

    public function getBodyTemplate(): string
    {
        return (string) $this->bodyTemplate;
    }
}
