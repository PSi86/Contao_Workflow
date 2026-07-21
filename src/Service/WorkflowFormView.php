<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Excel\NumberFormat;
use Psimandl\WorkflowBundle\Excel\ValueFormatter;
use Psimandl\WorkflowBundle\Excel\ValueParser;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Builds the answer-field view rows for the workflow form template. Extracted
 * from WorkflowFormController so the exact same rendering data feeds both the
 * live front-end form and the back-end form preview – the preview is then
 * guaranteed to match the real form field for field.
 */
class WorkflowFormView
{
    public function __construct(
        private readonly DocumentBodyComposer $bodyComposer,
        private readonly ValueParser $valueParser,
        private readonly ValueFormatter $formatter,
    ) {
    }

    /**
     * @param array<int, QuestionModel> $questions
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildQuestionViews(array $questions, WorkflowModel $workflow, EntryModel $entry): array
    {
        $views = [];
        $data = $entry->getData();
        $extra = $workflow->getMasterVars();
        $email = (string) $entry->email;

        foreach ($questions as $question) {
            // "Aktuelle Zeit" fields flagged hidden never appear in the form –
            // they are filled automatically on submission.
            if ($question->isHiddenInForm()) {
                continue;
            }

            // "Erklärung": a static text block (paragraph), no input. The text is
            // resolved server-side (same as the document) and shown as flowing text.
            if ($question->isExplanation()) {
                $views[] = [
                    'id'          => (int) $question->id,
                    'type'        => 'explanation',
                    'label'       => (string) $question->label,
                    'description' => $this->bodyComposer->resolveFormText($question->getDescription(), $workflow, $data, $extra, $email),
                    'text'        => $this->bodyComposer->formatBlock($this->bodyComposer->renderStatement($question, '', $data, $extra, $email, (string) $workflow->title)),
                ];

                continue;
            }

            $storage = trim((string) $question->storageField);

            // The statement parts let the form show exactly the text the
            // document will contain (live-updated in the browser).
            $parts = $this->bodyComposer->statementParts($question, $workflow, $data, $extra, $email);

            $options = [];

            foreach ($question->getOptions() as $option) {
                // The option's document text ("Textbaustein") as safe HTML (data-statement,
                // rendered by the live hint); the visible label stays plain (template escapes it).
                $option['statement'] = $this->bodyComposer->formatInline($parts['options'][$option['value']] ?? $option['label']);
                $options[] = $option;
            }

            $autoValue = $question->isCurrentTime() ? date('d.m.Y') : '';
            $readOnly = $question->isReadOnly() && !$question->isCurrentTime();
            $storedValue = '' !== $storage ? trim((string) ($data[$storage] ?? '')) : '';

            // Static statement for fields the user cannot change (the JS hint
            // only tracks editable inputs).
            $staticValue = '' !== $autoValue ? $autoValue : ($readOnly ? $storedValue : '');

            $views[] = [
                'id'                => (int) $question->id,
                'label'             => (string) $question->label,
                'type'              => (string) $question->type,
                'description'       => $this->bodyComposer->resolveFormText($question->getDescription(), $workflow, $data, $extra, $email),
                'mandatory'         => !$readOnly && $question->isMandatory(),
                'multiple'          => $question->isMultiple(),
                'readOnly'          => $readOnly,
                'options'           => $options,
                'autoValue'         => $autoValue,
                'initial'           => $this->resolveInitialValue($question, $data),
                // Hint only when a statement was explicitly configured AND the field
                // is set to preview it in the form – without an explicit statement the
                // visible label/option text counts verbatim anyway.
                'hasStatement'      => $question->hasExplicitStatement() && $question->showsStatementInForm(),
                // Safe HTML: the ##answer## template (live-substituted by the browser) and
                // the server-rendered static statement carry inline formatting ([b]/[i]/[u]).
                'statementTemplate' => $this->bodyComposer->formatInline($parts['template']),
                'statement'         => '' !== $staticValue
                    ? $this->bodyComposer->formatInline($this->bodyComposer->renderStatement($question, $staticValue, $data, $extra, $email, (string) $workflow->title))
                    : '',
                // Number fields carry their column's format into the markup so the browser
                // formats the live preview exactly like the PDF will. Null for every
                // other type.
                'numberFormat'      => $question->isNumber() ? $this->numberFormat($question, $storedValue)->toArray() : null,
            ];
        }

        return $views;
    }

    /**
     * The format a number field renders with: the snapshot taken when the field was saved,
     * or – for a field configured before snapshots existed – the format recovered from the
     * stored value itself. Only a field with neither falls back to a plain integer.
     */
    private function numberFormat(QuestionModel $question, string $storedValue): NumberFormat
    {
        // The snapshot is the only per-FIELD answer; it is refreshed on every import
        // (SpreadsheetImporter::refreshNumberFormats). Without one, the format used to be
        // guessed from this one participant's value, which made the same field behave
        // differently per participant – 2 decimals for "3.000,00 €", none for an empty or
        // round value – and then fall back to integers, silently dropping what was typed.
        // "General" keeps whatever decimals the value carries and forces nothing.
        return $question->getNumberFormat() ?? NumberFormat::general();
    }

    /**
     * Prefill value of a question from the entry data (Excel source value or a
     * previously stored answer). Choice values must match a configured option
     * (exactly, then trimmed/case-insensitively); on no match the prefill is
     * discarded – never silently invent a selection.
     *
     * @param array<string, mixed> $data
     *
     * @return string|array<int, string>
     */
    private function resolveInitialValue(QuestionModel $question, array $data): string|array
    {
        $empty = $question->isMultiple() ? [] : '';

        // Read-only fields always show the stored value; otherwise the prefill
        // flag decides.
        if (!$question->isPrefilled() && !$question->isReadOnly()) {
            return $empty;
        }

        $storage = trim((string) $question->storageField);
        $value = '' !== $storage ? trim((string) ($data[$storage] ?? '')) : '';

        if ('' === $value) {
            return $empty;
        }

        // A number field edits the bare number: the currency symbol carries no numeric
        // meaning, so it never reaches the input. It stays on the stored value and is put
        // back on submission, which keeps the column uniform between imported and
        // answered rows.
        if ($question->isNumber()) {
            $number = $this->valueParser->parse($value);

            return null !== $number
                ? (string) $this->formatter->format($number, $this->numberFormat($question, $value), false)
                : $value;
        }

        if ($question->isMultiple()) {
            $values = [];

            foreach (preg_split('/\s*,\s*/', $value) ?: [] as $single) {
                $match = $this->matchOption($question, $single);

                if (null !== $match && !\in_array($match, $values, true)) {
                    $values[] = $match;
                }
            }

            return $values;
        }

        if ($question->hasOptions()) {
            return $this->matchOption($question, $value) ?? '';
        }

        // The HTML date input needs ISO format; unparseable values are discarded.
        if ('date' === (string) $question->type) {
            return $this->toIsoDate($value);
        }

        return $value;
    }

    /**
     * Canonical option value for a prefill candidate, or null when it matches
     * no configured option.
     */
    private function matchOption(QuestionModel $question, string $value): ?string
    {
        $allowed = $question->getAllowedValues();

        if (\in_array($value, $allowed, true)) {
            return $value;
        }

        foreach ($allowed as $candidate) {
            if (0 === strcasecmp(trim($candidate), $value)) {
                return $candidate;
            }
        }

        return null;
    }

    private function toIsoDate(string $value): string
    {
        foreach (['Y-m-d', 'd.m.Y', 'j.n.Y', 'm/d/Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);

            if ($date instanceof \DateTime) {
                return $date->format('Y-m-d');
            }
        }

        return '';
    }
}
