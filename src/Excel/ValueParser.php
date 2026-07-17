<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Excel;

/**
 * Reads a number back out of a string – a stored cell value ("3.000,00 €"), a rule
 * comparison value, or whatever a participant typed into a number field.
 *
 * German is unambiguous here: the dot is *never* a decimal separator, so every dot in a
 * string this bundle produced is thousands grouping. "1.234" is 1234, not 1.234 – which
 * is exactly the guarantee that lets the formatted string stay the stored value
 * ({@see ValueFormatter}).
 *
 * Participant input is parsed leniently, because someone typing into a form does not
 * follow our conventions: "1234", "1234,5", "1.234,50" and even "1234.5" all resolve to
 * the number they obviously mean. Currency symbols and spaces are ignored.
 */
class ValueParser
{
    /**
     * A German number with grouping and no decimals ("1.234", "1.234.567"): every dot is
     * followed by exactly three digits and the leading group has at most three.
     */
    private const GROUPED = '/^[+-]?\d{1,3}(\.\d{3})+$/';

    /**
     * The numeric value of $value, or null when it holds no parsable number.
     */
    public function parse(string $value): ?float
    {
        // Strip everything that cannot belong to a number: currency symbols ("€",
        // "EUR"), spaces and non-breaking spaces. This is where "ignore the currency
        // symbol in a number field" is implemented.
        $digits = preg_replace('/[^\d,.\-+]/u', '', trim($value));

        if (null === $digits || '' === $digits) {
            return null;
        }

        $hasComma = str_contains($digits, ',');
        $hasDot = str_contains($digits, '.');

        if ($hasComma && $hasDot) {
            // Both present: whichever comes last is the decimal separator, the other
            // groups. Covers our own "1.234,50" as well as a pasted English "1,234.50".
            $digits = strrpos($digits, ',') > strrpos($digits, '.')
                ? str_replace(',', '.', str_replace('.', '', $digits))
                : str_replace(',', '', $digits);
        } elseif ($hasComma) {
            // A single comma is the German decimal separator; several can only group.
            $digits = substr_count($digits, ',') > 1
                ? str_replace(',', '', $digits)
                : str_replace(',', '.', $digits);
        } elseif ($hasDot && preg_match(self::GROUPED, $digits)) {
            // Valid grouping ("1.234") – the dots are separators. Without this the value
            // would silently become 1.234 and be wrong by a factor of 1000.
            $digits = str_replace('.', '', $digits);
        }

        // Anything else keeps its dot as a decimal separator ("1234.5" – lenient input).
        return is_numeric($digits) ? (float) $digits : null;
    }

    /**
     * Whether $value holds a parsable number (an empty string does not).
     */
    public function isNumeric(string $value): bool
    {
        return null !== $this->parse($value);
    }

    /**
     * Recovers the format of an already formatted value ("3.000,00 €" → 2 decimals,
     * grouping, "€").
     *
     * This is the fallback for a number field configured before the format snapshot
     * existed: its column's format is unknown, but the stored value was produced by that
     * very format, so it can be read back off the value. Without it such a field would be
     * re-rendered with a default format and quietly lose its decimals and grouping.
     *
     * Only meaningful for strings this bundle wrote (German conventions); returns null
     * when there is no number in $value.
     */
    public function inferFormat(string $value): ?NumberFormat
    {
        if (null === $this->parse($value)) {
            return null;
        }

        // Whatever is neither digit, separator, sign nor space is the currency symbol.
        $currency = trim(preg_replace('/[\d.,+\-\s\x{00A0}]/u', '', trim($value)) ?? '');
        $digits = preg_replace('/[^\d,.\-+]/u', '', trim($value)) ?? '';

        $comma = strrpos($digits, ',');
        $decimals = false === $comma ? 0 : \strlen($digits) - $comma - 1;
        $integerPart = false === $comma ? $digits : substr($digits, 0, $comma);

        return NumberFormat::number($decimals, str_contains($integerPart, '.'), $currency);
    }
}
