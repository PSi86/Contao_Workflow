<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Model\QuestionModel;

/**
 * Decides which form fields are visible for a given set of answers ("bedingte
 * Formularfelder"). The single authority behind four consumers: the rendered form
 * ({@see WorkflowFormView}), the submission (WorkflowFormController), the document
 * ({@see DocumentBodyComposer}) and the back-end preview. The browser only mirrors this
 * decision live – it never owns it, or a field could be hidden on screen and still be
 * validated and stored.
 *
 * A condition names the STORAGE COLUMN of a preceding field, so the whole evaluation is a
 * single pass over the data array: no cycles are possible, and the visibility can be
 * recomputed later from the stored data alone (which is what a regenerated PDF does).
 *
 * Fail-open by design: a field whose configuration is incomplete or unreadable stays VISIBLE.
 * A field that silently disappears is the one failure mode nobody notices; an extra field is
 * immediately obvious and costs nothing.
 */
class FieldVisibility
{
    public function __construct(private readonly ConditionMatcher $matcher)
    {
    }

    /**
     * Whether one field is visible for a data snapshot. Used while a submission is processed,
     * where the snapshot grows answer by answer – which works precisely because a condition
     * may only reference a PRECEDING field.
     *
     * @param array<string, mixed> $data
     */
    public function isVisible(QuestionModel $question, array $data): bool
    {
        if (!$question->isConditional()) {
            return true;
        }

        $matched = $this->conditionsMatch($question, $data);

        return 'hide' === $question->getConditionMode() ? !$matched : $matched;
    }

    /**
     * Visibility of every field of a workflow, with the cascade applied: the value of a
     * hidden field counts as EMPTY for the conditions that follow it. Without that, hiding a
     * question would leave its (stale or prefilled) value behind as a trigger for the next
     * one, and a chain of conditions would come apart at the first hidden link.
     *
     * @param array<int, QuestionModel> $questions in list (sorting) order
     * @param array<string, mixed>      $data
     *
     * @return array<int, bool> question id => visible
     */
    public function resolve(array $questions, array $data): array
    {
        $visible = [];
        $effective = $data;

        foreach ($questions as $question) {
            $isVisible = $this->isVisible($question, $effective);
            $visible[(int) $question->id] = $isVisible;

            if ($isVisible) {
                continue;
            }

            $storage = trim((string) $question->storageField);

            if ('' !== $storage) {
                $effective[$storage] = '';
            }
        }

        return $visible;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function conditionsMatch(QuestionModel $question, array $data): bool
    {
        $isOr = 'or' === $question->getConditionLogic();

        foreach ($question->getConditions() as $condition) {
            $actual = (string) ($data[$condition['field']] ?? '');
            $hit = $this->matcher->matches($actual, $condition['operator'], $condition['value']);

            if ($isOr && $hit) {
                return true;
            }

            if (!$isOr && !$hit) {
                return false;
            }
        }

        // AND with every condition met, or OR with none – isConditional() guarantees there
        // was at least one condition to look at.
        return !$isOr;
    }
}
