<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Exports all entries of a workflow as XLSX/CSV, reproducing the source columns
 * (in their original order) refilled with the current data — including the
 * updated output columns (e.g. Verzicht / Datum Verzicht).
 */
class SpreadsheetExporter
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SpreadsheetInspector $inspector,
        private readonly Slugger $slugger,
    ) {
    }

    /**
     * @return array{content: string, filename: string, filenameFallback: string, contentType: string}
     */
    public function export(WorkflowModel $workflow, string $format = 'xlsx'): array
    {
        $this->framework->initialize();

        // Row order mirrors the source file (sourceRow = the row the entry was imported
        // from), so an export can be diffed against the original. "id" only breaks the tie
        // for entries that predate the column and were never re-imported.
        $entries = $this->framework->getAdapter(EntryModel::class)
            ->findBy('pid', (int) $workflow->id, ['order' => 'sourceRow, id']);

        // Columns = original source headers (in order); fall back to the union
        // of stored data keys if the source file is unavailable.
        $headers = array_values($this->inspector->getHeaders($workflow));
        if ([] === $headers) {
            $headers = $this->collectDataKeys($entries);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $this->writeRow($sheet, 1, $headers);

        $rowNumber = 2;
        if (null !== $entries) {
            foreach ($entries as $entry) {
                $data = $entry->getData();
                $row = [];
                foreach ($headers as $h) {
                    $row[] = (string) ($data[$h] ?? '');
                }
                $this->writeRow($sheet, $rowNumber, $row);
                ++$rowNumber;
            }
        }

        return 'csv' === $format
            ? $this->writeFile($spreadsheet, 'Csv', 'text/csv', $workflow, 'csv')
            : $this->writeFile($spreadsheet, 'Xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $workflow, 'xlsx');
    }

    /**
     * Writes one row (1-based row number) as explicit string cells, neutralising
     * spreadsheet formula injection: a value starting with = + - @ (or a control
     * character) is otherwise executed as a formula when the export is opened in
     * Excel/LibreOffice (e.g. =WEBSERVICE(…)/=HYPERLINK(…) exfiltrating row data,
     * with participant-submitted answers as the untrusted source). Such values are
     * prefixed with a single quote so they stay text in both XLSX and CSV; genuine
     * numbers are left untouched.
     *
     * @param array<int, string> $values
     */
    private function writeRow(Worksheet $sheet, int $rowNumber, array $values): void
    {
        $col = 1;

        foreach ($values as $value) {
            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($col).$rowNumber,
                $this->neutralizeFormula((string) $value),
                DataType::TYPE_STRING,
            );
            ++$col;
        }
    }

    private function neutralizeFormula(string $value): string
    {
        if ('' !== $value && 1 === preg_match('/^[=+\-@\t\r]/', $value) && !is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param \Contao\Model\Collection<EntryModel>|array<EntryModel>|null $entries
     *
     * @return array<int, string>
     */
    private function collectDataKeys($entries): array
    {
        $keys = [];

        if (null !== $entries) {
            foreach ($entries as $entry) {
                foreach (array_keys($entry->getData()) as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * @return array{content: string, filename: string, filenameFallback: string, contentType: string}
     */
    private function writeFile(Spreadsheet $spreadsheet, string $writerType, string $contentType, WorkflowModel $workflow, string $extension): array
    {
        $writer = IOFactory::createWriter($spreadsheet, $writerType);

        $tmp = tempnam(sys_get_temp_dir(), 'tw_export_');
        $writer->save($tmp);
        $content = (string) file_get_contents($tmp);
        unlink($tmp);

        // Two spellings of the same name: the download keeps the title's own characters (umlauts,
        // any script) via the RFC 5987 filename* header, with an ASCII transliteration as the
        // fallback for old clients. Neither swallows a character.
        $title = (string) $workflow->title;
        $stamp = date('Ymd_His');

        return [
            'content'          => $content,
            'filename'         => sprintf('%s_%s.%s', $this->slugger->unicode($title) ?: 'Workflow', $stamp, $extension),
            'filenameFallback' => sprintf('%s_%s.%s', $this->slugger->ascii($title) ?: 'Workflow', $stamp, $extension),
            'contentType'      => $contentType,
        ];
    }
}
