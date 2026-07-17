<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Excel;

/**
 * Renders a numeric value with German conventions (grouping ".", decimal ",") according
 * to a {@see NumberFormat}.
 *
 * This is the *only* place in the bundle that turns a number into a string for a human.
 * Everything a participant ever sees – the imported cell, the form field, the live
 * preview, the PDF, the export – comes from here, so the four can no longer disagree.
 *
 * Counterpart of {@see ValueParser}; format(parse($s)) === $s for every string this
 * class produced.
 */
class ValueFormatter
{
    public const DECIMAL_SEPARATOR = ',';
    public const THOUSANDS_SEPARATOR = '.';

    /**
     * The German rendering of $value, or null when the format is one this bundle does
     * not re-render (percent, scientific, fraction, date/time, text) – the caller then
     * keeps PhpSpreadsheet's own formatted output.
     *
     * @param bool $withCurrency false strips the symbol – a "number" question edits the
     *                           bare number, the symbol only lives on the stored value
     */
    public function format(float $value, NumberFormat $format, bool $withCurrency = true): ?string
    {
        if (!$format->isNumeric()) {
            return null;
        }

        // "General": keep exactly the digits the value carries (an integer stays an
        // integer, "3000" never becomes "3.000,00") and only localise the separator.
        // German Excel shows such a cell with a comma, so this matches the source file.
        if (null === $format->decimals) {
            return str_replace('.', self::DECIMAL_SEPARATOR, $this->plain($value));
        }

        $number = number_format(
            $value,
            $format->decimals,
            self::DECIMAL_SEPARATOR,
            $format->grouping ? self::THOUSANDS_SEPARATOR : '',
        );

        if ($withCurrency && $format->hasCurrency()) {
            return $number.' '.$format->currency;
        }

        return $number;
    }

    /**
     * The shortest faithful rendering of a float. PHP casts locale-independently since
     * 8.0, so this always uses "." and matches PhpSpreadsheet's "General" output.
     */
    private function plain(float $value): string
    {
        $plain = (string) $value;

        // Scientific notation would leak an "E+21" into the document; render such a
        // value in full instead and drop the padding zeros.
        if (false !== stripos($plain, 'E')) {
            $plain = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }

        return $plain;
    }
}
