<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Model;

use Contao\Model;
use Contao\Model\Collection;
use Contao\StringUtil;

/**
 * Reads and writes tl_workflow_question (one configurable answer field of a workflow).
 *
 * @property int    $id
 * @property int    $pid          Workflow id (tl_workflow.id).
 * @property int    $sorting
 * @property int    $tstamp
 * @property string $label        Question/field label shown in the form.
 * @property string $type         text | textarea | select | radio | checkbox | date.
 * @property string $storageField Source column the answer value is written into.
 * @property string $mandatory    Whether an answer is required ("1"/"").
 * @property string $hideInForm   "Aktuelle Zeit" only: hide the field in the form ("1"/"").
 * @property string $options      Serialized list of [value, label] option pairs.
 */
class QuestionModel extends Model
{
    protected static $strTable = 'tl_workflow_question';

    /**
     * @return Collection<QuestionModel>|array<QuestionModel>|null
     */
    public static function findByWorkflow(int $workflowId)
    {
        return static::findBy('pid', $workflowId, ['order' => 'sorting']);
    }

    /**
     * Whether this question type presents a fixed set of options.
     */
    public function hasOptions(): bool
    {
        return \in_array($this->type, ['select', 'radio', 'checkbox'], true);
    }

    public function isMultiple(): bool
    {
        return 'checkbox' === $this->type;
    }

    public function isMandatory(): bool
    {
        return '1' === (string) $this->mandatory;
    }

    /**
     * Auto-filled date field ("Aktuelle Zeit"): its value is set to the current
     * date on submission, never taken from the form.
     */
    public function isCurrentTime(): bool
    {
        return 'currentTime' === (string) $this->type;
    }

    /**
     * Whether the field is left out of the public form (auto-filled only).
     */
    public function isHiddenInForm(): bool
    {
        return $this->isCurrentTime() && '1' === (string) $this->hideInForm;
    }

    /**
     * Configured options as a list of value/label pairs (empty values skipped).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getOptions(): array
    {
        $options = [];

        foreach (StringUtil::deserialize($this->options, true) as $row) {
            $value = trim((string) ($row['value'] ?? ''));

            if ('' === $value) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $options[] = ['value' => $value, 'label' => '' !== $label ? $label : $value];
        }

        return $options;
    }

    /**
     * Allowed stored values of an option-based question.
     *
     * @return array<int, string>
     */
    public function getAllowedValues(): array
    {
        return array_map(static fn (array $o): string => $o['value'], $this->getOptions());
    }
}
