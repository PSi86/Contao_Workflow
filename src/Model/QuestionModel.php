<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Model;

use Contao\Model;
use Contao\Model\Collection;
use Contao\StringUtil;
use Psimandl\WorkflowBundle\Excel\NumberFormat;

/**
 * Reads and writes tl_workflow_question (one configurable answer field of a workflow).
 *
 * @property int    $id
 * @property int    $pid          Workflow id (tl_workflow.id).
 * @property int    $sorting
 * @property int    $tstamp
 * @property string $label        Question/field label shown in the form.
 * @property string $type         text | textarea | number | date | select | radio | checkbox | currentTime | explanation.
 * @property string $storageField Source column the answer value is written into.
 * @property string $mandatory    Whether an answer is required ("1"/"").
 * @property string $hideInForm   "Aktuelle Zeit" only: hide the field in the form ("1"/"").
 * @property string $options      Serialized list of [value, label, statement] option rows.
 * @property string $pdfStatement Statement template (##answer## = entered value / option statement); "Erklärung": the text.
 * @property string $description  Optional description shown in the form only (never in the document).
 * @property string $showStatementInForm Whether the document text is previewed in the form ("1"/"").
 * @property string $prefill      Prefill the field with the stored data value ("1"/"").
 * @property string $readOnly     Show the stored data value read-only ("1"/"").
 * @property string $numberFormat JSON snapshot of the storage column's Excel format ("number" only).
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
     * Static explanatory text ("Erklärung"): no input, no stored value. Its text
     * (pdfStatement) is rendered as a paragraph in the form and carried into the
     * document (##text_all##).
     */
    public function isExplanation(): bool
    {
        return 'explanation' === (string) $this->type;
    }

    public function isNumber(): bool
    {
        return 'number' === (string) $this->type;
    }

    /**
     * The storage column's Excel format, snapshotted when the field was saved (see
     * AnswerConfigListener::validateNumberColumn).
     *
     * Null for a field configured before the snapshot existed. Callers must then recover
     * the format from a stored value (ValueParser::inferFormat) rather than assume a
     * default – guessing would re-render "3.000,00 €" as "3000" and drop the very
     * formatting this field is supposed to preserve.
     */
    public function getNumberFormat(): ?NumberFormat
    {
        $snapshot = trim((string) $this->numberFormat);

        if ('' === $snapshot) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);

            return NumberFormat::fromArray($data);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Optional description shown in the form below the label (never in the
     * document); empty when none is configured.
     */
    public function getDescription(): string
    {
        return trim((string) $this->description);
    }

    /**
     * Whether the document text ("Textbaustein") is previewed in the form
     * ("So erscheint dies im Dokument"). The column defaults to "1" (SQL default and
     * importer), so existing fields keep the hint; an explicit uncheck hides it.
     */
    public function showsStatementInForm(): bool
    {
        return '1' === (string) $this->showStatementInForm;
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
     * Statement template of a value-based question; ##answer## marks the spot
     * for the entered value. Default: "<label>: ##answer##". Choice questions
     * carry their document texts per option instead.
     */
    public function getStatementTemplate(): string
    {
        $template = trim((string) $this->pdfStatement);

        // "Erklärung" is static text: the pdfStatement is the paragraph itself, with
        // no "<label>: ##answer##" fallback (there is no answer).
        if ($this->isExplanation()) {
            return $template;
        }

        return '' !== $template ? $template : trim((string) $this->label).': ##answer##';
    }

    /**
     * Whether a document statement was explicitly configured – per option for
     * choice questions (their pdfStatement is hidden and must not count),
     * pdfStatement otherwise. Only then does the form show the "this is how it
     * appears in the document" hint, and ##text_all## adds a blank line before
     * the statement – without explicit statements the visible label/option
     * text counts verbatim anyway.
     */
    public function hasExplicitStatement(): bool
    {
        // "Erklärung": its text is always a standalone paragraph in ##text_all##.
        if ($this->isExplanation()) {
            return '' !== trim((string) $this->pdfStatement);
        }

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
