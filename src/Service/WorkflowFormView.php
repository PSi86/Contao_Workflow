<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

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
                    'description' => $question->getDescription(),
                    'text'        => $this->bodyComposer->renderStatement($question, '', $data, $extra, $email, (string) $workflow->title),
                ];

                continue;
            }

            $storage = trim((string) $question->storageField);

            // The statement parts let the form show exactly the text the
            // document will contain (live-updated in the browser).
            $parts = $this->bodyComposer->statementParts($question, $workflow, $data, $extra, $email);

            $options = [];

            foreach ($question->getOptions() as $option) {
                $option['statement'] = $parts['options'][$option['value']] ?? $option['label'];
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
                'description'       => $question->getDescription(),
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
                'statementTemplate' => $parts['template'],
                'statement'         => '' !== $staticValue
                    ? $this->bodyComposer->renderStatement($question, $staticValue, $data, $extra, $email, (string) $workflow->title)
                    : '',
            ];
        }

        return $views;
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
