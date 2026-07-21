<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;

/**
 * Reads every cell of one source column together with its number format.
 *
 * Note the deliberate absence of setReadDataOnly(true): that flag makes PhpSpreadsheet
 * skip the style layer entirely, so the format codes – the very thing this class is after
 * – would all come back empty. It is why the back-end pickers (which do set the flag)
 * could never have validated a column's formatting. Reading styles is expensive, so this
 * runs only when a "number" question is saved, and its verdict is snapshotted onto the
 * question afterwards.
 */
class ColumnFormatAnalyzer
{
    public function __construct(
        private readonly SpreadsheetInspector $inspector,
        private readonly FormatCodeParser $formatParser,
    ) {
    }

    /**
     * Every data cell of $header, in sheet order.
     *
     * @return array<int, array{row: int, format: NumberFormat, mask: string, value: float|null, text: string}>
     *                              row   = 1-based sheet row (for the error messages)
     *                              mask  = the raw format code, quoted back at the user
     *                              value = null when the cell holds no number
     */
    public function analyze(WorkflowModel $workflow, string $header): array
    {
        $path = $this->inspector->resolvePath($workflow);

        if (null === $path) {
            return [];
        }

        $sheetName = (string) $workflow->sourceSheet;
        $headerRow = max(1, (int) $workflow->headerRow);

        // Not read-data-only: this class exists to look at the number formats. Null means the
        // configured sheet is not in the file – nothing to analyse, and the validator reports
        // the missing sheet on its own.
        $reader = $this->inspector->readerFor($path, $sheetName, false);

        if (null === $reader) {
            return [];
        }

        $sheet = $this->inspector->sheetOf($reader->load($path), $sheetName);
        $headers = $this->inspector->headersOf($sheet, $headerRow);
        $column = array_search($header, $headers, true);

        if (false === $column) {
            return [];
        }

        $letter = Coordinate::stringFromColumnIndex((int) $column);
        $emailLetter = $this->emailColumnLetter($workflow, $headers);
        $highestRow = $sheet->getHighestDataRow();
        $cells = [];

        for ($r = $headerRow + 1; $r <= $highestRow; ++$r) {
            // Judge only the rows the importer actually imports. A sheet's totals row
            // ("Summe: 16,800.00 €") has no e-mail and is skipped there, so flagging its
            // formatting would refuse a perfectly good column over a cell that never
            // becomes an entry.
            if (null !== $emailLetter && '' === trim((string) $sheet->getCell($emailLetter.$r)->getFormattedValue())) {
                continue;
            }

            $cell = $sheet->getCell($letter.$r);
            $raw = $cell->getValue();
            $text = trim((string) $cell->getFormattedValue());

            // Empty cells constrain nothing – a participant may simply have no value yet.
            if ('' === $text && (null === $raw || '' === $raw)) {
                continue;
            }

            $mask = (string) $cell->getStyle()->getNumberFormat()->getFormatCode();

            $cells[] = [
                'row'    => $r,
                'format' => $this->formatParser->parse($mask),
                'mask'   => $mask,
                'value'  => is_numeric($raw) ? (float) $raw : null,
                'text'   => $text,
            ];
        }

        return $cells;
    }

    /**
     * Column letter of the workflow's e-mail column, or null when there is none – the same
     * resolution the importer uses (configured field first, then a header that looks like
     * an e-mail), so both agree on which rows are real participants.
     *
     * @param array<int, string> $headers column index => header name
     */
    private function emailColumnLetter(WorkflowModel $workflow, array $headers): ?string
    {
        $configured = (string) $workflow->emailField;
        $index = '' !== $configured ? array_search($configured, $headers, true) : false;

        if (false === $index) {
            foreach ($headers as $columnIndex => $name) {
                if (preg_match('/e-?mail/i', $name)) {
                    $index = $columnIndex;
                    break;
                }
            }
        }

        return false === $index ? null : Coordinate::stringFromColumnIndex((int) $index);
    }
}
