<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date;

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
        $raw = $cell->getValue();

        if (!is_numeric($raw)) {
            // Text, or a formula – getFormattedValue() resolves the latter.
            return trim((string) $cell->getFormattedValue());
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
        if ((float) $raw >= 1.0 && Date::isDateTime($cell)) {
            $date = Date::excelToDateTimeObject((float) $raw);
            $hasTime = '000000' !== $date->format('His');

            return $date->format($hasTime ? 'd.m.Y H:i' : 'd.m.Y');
        }

        if (!Date::isDateTime($cell)) {
            $formatted = $this->formatter->format((float) $raw, $this->formatOf($cell));

            // null = a kind we deliberately do not re-render (percent, scientific,
            // fraction); keep PhpSpreadsheet's own output for it.
            if (null !== $formatted) {
                return $formatted;
            }
        }

        return trim((string) $cell->getFormattedValue());
    }

    /**
     * The format of a cell, straight from its number-format code.
     */
    public function formatOf(Cell $cell): NumberFormat
    {
        return $this->formatParser->parse((string) $cell->getStyle()->getNumberFormat()->getFormatCode());
    }
}
