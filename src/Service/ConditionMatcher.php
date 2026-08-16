<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Excel\ValueParser;

/**
 * Compares one stored value against one expected value with one operator. The single place
 * where "ist gleich"/"größer als" are defined, shared by the two features that ask that
 * question: the PDF rules ({@see RuleEvaluator}, full operator set) and the conditional form
 * fields ({@see FieldVisibility}, string operators only – the browser mirrors those live).
 *
 * Without this being shared, the same condition could select a letter body in the document
 * and yet fail to reveal the field the participant filled in.
 */
class ConditionMatcher
{
    public function __construct(private readonly ValueParser $valueParser)
    {
    }

    public function matches(string $actual, string $operator, string $expected): bool
    {
        switch ($operator) {
            case 'empty':
                return '' === trim($actual);
            case 'notempty':
                return '' !== trim($actual);
            case 'contains':
                return '' !== $expected && false !== mb_stripos($actual, $expected);
        }

        // Numeric comparison when both sides hold a number, string comparison otherwise.
        // Parsed rather than cast: a stored value carries its column's formatting
        // ("3.000,00 €"), which is not is_numeric() – so a "greater than" on a currency
        // column used to silently degrade into a strcmp, where "500" > "3.000,00 €".
        $a = $this->valueParser->parse($actual);
        $b = $this->valueParser->parse($expected);

        if (null !== $a && null !== $b) {
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
