<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Excel;

use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Turns an Excel number-format code ("#,##0.00 [$€-407]") into a {@see NumberFormat}.
 *
 * Excel stores a format code with US conventions – "," groups thousands and "." marks
 * the decimals – regardless of the locale the file was authored in. So the code cannot
 * be printed as-is for a German document; it has to be read as a *description* (how many
 * decimals, does it group, which currency) and the value re-rendered from that. This
 * class is the only place that understands the code.
 */
class FormatCodeParser
{
    public function parse(string $mask): NumberFormat
    {
        $mask = trim($mask);

        // No explicit format – a plain number keeps the decimals it happens to carry.
        if ('' === $mask || 'GENERAL' === strtoupper($mask)) {
            return NumberFormat::general();
        }

        if ('@' === $mask) {
            return NumberFormat::of(NumberFormat::KIND_TEXT);
        }

        $currency = $this->currencySymbol($mask);

        // Classification must ignore bracketed markers: the letters in "[$€-de-DE]" or
        // "[Red]" are locale/colour tokens, not format tokens. Without stripping them the
        // "E-" of "de-DE" reads as a scientific format.
        $tokens = preg_replace('/\[[^\]]*\]/u', '', $mask) ?? $mask;

        // Percent scales by 100, scientific and fraction have their own notation – none of
        // them is re-rendered here, they keep PhpSpreadsheet's output.
        if (str_contains($tokens, '%')) {
            return NumberFormat::of(NumberFormat::KIND_PERCENT);
        }

        if (preg_match('/E[+-]/', $tokens)) {
            return NumberFormat::of(NumberFormat::KIND_SCIENTIFIC);
        }

        if (str_contains($tokens, '?/')) {
            return NumberFormat::of(NumberFormat::KIND_FRACTION);
        }

        // A currency mask is never a date; checking the symbol first keeps
        // isDateTimeFormatCode() away from masks like "#,##0.00 \"USD\"", whose
        // letters it could otherwise read as date tokens.
        if ('' === $currency && Date::isDateTimeFormatCode($mask)) {
            return NumberFormat::of(NumberFormat::KIND_DATETIME);
        }

        // The positive section (before the first ";") defines decimals and grouping.
        $positive = explode(';', $tokens)[0];

        $decimals = 0;

        if (preg_match('/\.([0#]+)/', $positive, $m)) {
            $decimals = \strlen($m[1]);
        }

        // Group thousands only when the mask does (a "," inside the integer part).
        $integerPart = explode('.', $positive)[0];

        return NumberFormat::number($decimals, str_contains($integerPart, ','), $currency);
    }

    /**
     * The currency symbol carried by a number-format code, or an empty string for a
     * plain number format. Handles a locale currency marker ("[$€-407]"), a bare
     * currency sign anywhere in the mask ("€", "$", …) and a quoted ISO code ("EUR").
     */
    public function currencySymbol(string $mask): string
    {
        // "[$€-407]" – the symbol is everything up to the locale id. A bare "[$-407]"
        // carries no symbol and is skipped by excluding "-" from the match.
        if (preg_match('/\[\$([^\]\-]+)/u', $mask, $m)) {
            return trim($m[1]);
        }

        // Only now look for a bare sign, and only outside the bracketed markers –
        // otherwise the "$" of a pure locale marker like "[$-407]" is mistaken for a
        // currency symbol and every plain number grows a "$".
        $bare = preg_replace('/\[[^\]]*\]/u', '', $mask) ?? $mask;

        if (preg_match('/[€$£¥₣]/u', $bare, $m)) {
            return $m[0];
        }

        if (preg_match('/"\s*([A-Za-z]{3})\s*"/', $bare, $m)) {
            return strtoupper($m[1]);
        }

        return '';
    }
}
