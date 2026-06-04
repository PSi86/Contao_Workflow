<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
    ) {
    }

    /**
     * @return array{content: string, filename: string, contentType: string}
     */
    public function export(WorkflowModel $workflow, string $format = 'xlsx'): array
    {
        $this->framework->initialize();

        $entries = $this->framework->getAdapter(EntryModel::class)
            ->findBy('pid', (int) $workflow->id, ['order' => 'email']);

        // Columns = original source headers (in order); fall back to the union
        // of stored data keys if the source file is unavailable.
        $headers = array_values($this->inspector->getHeaders($workflow));
        if ([] === $headers) {
            $headers = $this->collectDataKeys($entries);
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');

        $rowNumber = 2;
        if (null !== $entries) {
            foreach ($entries as $entry) {
                $data = $entry->getData();
                $row = [];
                foreach ($headers as $h) {
                    $row[] = $data[$h] ?? '';
                }
                $sheet->fromArray($row, null, 'A'.$rowNumber);
                ++$rowNumber;
            }
        }

        return 'csv' === $format
            ? $this->writeFile($spreadsheet, 'Csv', 'text/csv', $workflow, 'csv')
            : $this->writeFile($spreadsheet, 'Xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $workflow, 'xlsx');
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
     * @return array{content: string, filename: string, contentType: string}
     */
    private function writeFile(Spreadsheet $spreadsheet, string $writerType, string $contentType, WorkflowModel $workflow, string $extension): array
    {
        $writer = IOFactory::createWriter($spreadsheet, $writerType);

        $tmp = tempnam(sys_get_temp_dir(), 'tw_export_');
        $writer->save($tmp);
        $content = (string) file_get_contents($tmp);
        unlink($tmp);

        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $workflow->title) ?: 'workflow';

        return [
            'content'     => $content,
            'filename'    => sprintf('%s_%s.%s', $slug, date('Ymd_His'), $extension),
            'contentType' => $contentType,
        ];
    }
}
