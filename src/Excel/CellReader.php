<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat as ExcelNumberFormat;

/**
 * The single funnel from a spreadsheet cell to the string this bundle stores.
 *
 * Everything that reads a source file goes through here, so a value can never pick up a
 * different spelling depending on which code path touched it. What comes out is what the
 * participant sees in the form, in the PDF and in the export – it is stored exactly once
 * and never re-formatted afterwards.
 */
class CellReader
{
    /**
     * The error literals a spreadsheet stores in place of a result. They are values in the
     * file, but not data: carrying "#NV" into a document, a PDF and the export would be
     * worse than an empty field plus a warning naming the row.
     */
    private const ERROR_VALUES = [
        // English literals as stored by Excel/LibreOffice …
        '#NULL!', '#DIV/0!', '#VALUE!', '#REF!', '#NAME?', '#NUM!', '#N/A',
        '#GETTING_DATA', '#SPILL!', '#CALC!', '#FIELD!', '#BLOCKED!', '#CONNECT!', '#BUSY!',
        // … plus the German ones, which a German Excel writes into the file verbatim.
        '#NV', '#WERT!', '#BEZUG!', '#ZAHL!',
    ];

    public function __construct(
        private readonly FormatCodeParser $formatParser,
        private readonly ValueFormatter $formatter,
    ) {
    }

    /**
     * The value of a source cell as a trimmed string.
     */
    public function read(Cell $cell): string
    {
        // A formula is never evaluated here: only the result the spreadsheet program itself
        // stored in the file is used (see rawValue). Without a usable one the cell reads as
        // empty and the caller reports it – recalculating would need the engine to
        // understand every function in the file, and where it does not the import would
        // either abort (CalculationException) or store a number the file never showed.
        if ($cell->isFormula()) {
            $cached = $this->rawValue($cell);

            return null === $cached ? '' : $this->render($cell, $cached, true);
        }

        return $this->render($cell, $cell->getValue(), false);
    }

    /**
     * The value a cell contributes, before formatting: for a formula cell the result stored
     * in the file (null when there is none to work with), otherwise the cell's own value.
     *
     * The format analysis judges columns by this, so a formula column is measured by its
     * results – not by the formula text, which would read as "text instead of a number".
     */
    public function rawValue(Cell $cell): mixed
    {
        if (!$cell->isFormula()) {
            return $cell->getValue();
        }

        $cached = $cell->getOldCalculatedValue();

        if (null === $cached || $this->isErrorValue($cached)) {
            return null;
        }

        return \is_scalar($cached) ? $cached : null;
    }

    /**
     * Why a formula cell yields no value ("kein gespeichertes Ergebnis", "Fehlerwert „#NV""),
     * or null when the cell is fine – every non-formula cell is.
     *
     * The importer groups these per column into one warning instead of failing: a broken
     * formula is a problem of the source file, and the participants' data is the point of
     * the import.
     */
    public function formulaProblem(Cell $cell): ?string
    {
        if (!$cell->isFormula()) {
            return null;
        }

        $cached = $cell->getOldCalculatedValue();

        if (null === $cached) {
            return 'kein gespeichertes Ergebnis';
        }

        return $this->isErrorValue($cached) ? sprintf('Fehlerwert „%s"', trim((string) $cached)) : null;
    }

    /**
     * The format of a cell, straight from its number-format code.
     */
    public function formatOf(Cell $cell): NumberFormat
    {
        return $this->formatParser->parse((string) $cell->getStyle()->getNumberFormat()->getFormatCode());
    }

    private function isErrorValue(mixed $value): bool
    {
        return \is_string($value) && \in_array(strtoupper(trim($value)), self::ERROR_VALUES, true);
    }

    /**
     * Turns a cell's raw value into the stored string, applying the cell's number format.
     *
     * $isFormula only decides how the untouched kinds fall back: a formula cell must not go
     * through getFormattedValue(), which would evaluate the formula again – it formats the
     * stored result with the same routine instead.
     */
    private function render(Cell $cell, mixed $raw, bool $isFormula): string
    {
        if (!is_numeric($raw)) {
            // Text (a formula's stored text result, or a plain text cell). Both go through
            // the same formatter, so a formula's result is spelled like a literal cell's –
            // including the TRUE/FALSE a boolean result prints as.
            return $isFormula ? $this->applyMask($cell, $raw) : trim((string) $cell->getFormattedValue());
        }

        // Excel date cells carry a serial number plus a (possibly locale-specific, e.g.
        // US "m/d/yyyy") number format; getFormattedValue() would print that raw format,
        // so a birthday stored as an Excel date ends up as "12/17/1955". Normalise every
        // date/date-time cell to the German d.m.Y (or d.m.Y H:i when a time part is
        // present), matching the format used everywhere else in the workflow.
        //
        // Date::isDateTime() is also true for pure time / duration formats (serial < 1, a
        // fraction of a day); those must keep their formatted value ("12:00") instead of
        // becoming a 1899 epoch date.
        // The value is passed explicitly: without it Date::isDateTime() falls back to
        // getCalculatedValue() and would evaluate a formula cell after all.
        if ((float) $raw >= 1.0 && Date::isDateTime($cell, $raw)) {
            $date = Date::excelToDateTimeObject((float) $raw);
            $hasTime = '000000' !== $date->format('His');

            return $date->format($hasTime ? 'd.m.Y H:i' : 'd.m.Y');
        }

        if (!Date::isDateTime($cell, $raw)) {
            $formatted = $this->formatter->format((float) $raw, $this->formatOf($cell));

            // null = a kind we deliberately do not re-render (percent, scientific,
            // fraction); keep the spreadsheet's own output for it.
            if (null !== $formatted) {
                return $formatted;
            }
        }

        return $isFormula ? $this->applyMask($cell, $raw) : trim((string) $cell->getFormattedValue());
    }

    /**
     * What getFormattedValue() produces, minus the recalculation: the cell's number-format
     * code applied to an already known value.
     */
    private function applyMask(Cell $cell, mixed $value): string
    {
        return trim((string) ExcelNumberFormat::toFormattedString(
            $value,
            (string) $cell->getStyle()->getNumberFormat()->getFormatCode(true),
        ));
    }
}
