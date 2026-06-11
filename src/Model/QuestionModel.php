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
 * @property string $type         text | textarea | number | date | select | radio | checkbox | currentTime.
 * @property string $storageField Source column the answer value is written into.
 * @property string $mandatory    Whether an answer is required ("1"/"").
 * @property string $hideInForm   "Aktuelle Zeit" only: hide the field in the form ("1"/"").
 * @property string $options      Serialized list of [value, label, statement] option rows.
 * @property string $pdfStatement Statement template (##value## = entered value / option statement).
 * @property string $prefill      Prefill the field with the stored data value ("1"/"").
 * @property string $readOnly     Show the stored data value read-only ("1"/"").
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
     * Read-only field: shows the stored data value, never validated, never
     * stored back. Available for every type.
     */
    public function isReadOnly(): bool
    {
        return '1' === (string) $this->readOnly;
    }

    /**
     * Whether the (editable) field is prefilled with the value currently stored
     * in the entry data (Excel source value or previous answer).
     */
    public function isPrefilled(): bool
    {
        return '1' === (string) $this->prefill;
    }

    /**
     * Whether the field is left out of the public form (auto-filled only).
     */
    public function isHiddenInForm(): bool
    {
        return $this->isCurrentTime() && '1' === (string) $this->hideInForm;
    }

    /**
     * Configured options as a list of value/label/statement rows (empty values
     * skipped). The statement is the option's document text ("Textbaustein");
     * empty means the visible label counts verbatim.
     *
     * @return array<int, array{value: string, label: string, statement: string}>
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
            $options[] = [
                'value'     => $value,
                'label'     => '' !== $label ? $label : $value,
                'statement' => trim((string) ($row['statement'] ?? '')),
            ];
        }

        return $options;
    }

    /**
     * Document text of one option: the configured statement, falling back to
     * the visible option label (what the participant saw is what the document
     * says). Unknown/legacy stored values fall back to the raw value.
     */
    public function getOptionStatement(string $value): string
    {
        foreach ($this->getOptions() as $option) {
            if ($option['value'] === $value) {
                return '' !== $option['statement'] ? $option['statement'] : $option['label'];
            }
        }

        return $value;
    }

    /**
     * Statement template of a value-based question; ##value## marks the spot
     * for the entered value. Default: "<label>: ##value##". Choice questions
     * carry their document texts per option instead.
     */
    public function getStatementTemplate(): string
    {
        $template = trim((string) $this->pdfStatement);

        return '' !== $template ? $template : trim((string) $this->label).': ##value##';
    }

    /**
     * Whether a document statement was explicitly configured – per option for
     * choice questions (their pdfStatement is hidden and must not count),
     * pdfStatement otherwise. Only then does the form show the "this is how it
     * appears in the document" hint, and ##stmt_all## adds a blank line before
     * the statement – without explicit statements the visible label/option
     * text counts verbatim anyway.
     */
    public function hasExplicitStatement(): bool
    {
        if ($this->hasOptions()) {
            foreach ($this->getOptions() as $option) {
                if ('' !== $option['statement']) {
                    return true;
                }
            }

            return false;
        }

        return '' !== trim((string) $this->pdfStatement);
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
