<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Reads structural information (sheet names, column headers) from a workflow's
 * source spreadsheet. Used by the back end field pickers and the importer so all
 * three agree on the exact (de-duplicated) column names.
 */
class SpreadsheetInspector
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<int, string> list of worksheet names
     */
    public function getSheetNames(WorkflowModel $workflow): array
    {
        $path = $this->resolvePath($workflow);

        if (null === $path) {
            return [];
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader->listWorksheetNames($path);
    }

    /**
     * Column index (1-based) => header name, in column order, empty headers
     * skipped and duplicates de-duplicated ("Name", "Name (2)", …).
     *
     * @return array<int, string>
     */
    public function getHeaders(WorkflowModel $workflow): array
    {
        $path = $this->resolvePath($workflow);

        if (null === $path) {
            return [];
        }

        $sheetName = (string) $workflow->sourceSheet;
        $headerRow = max(1, (int) $workflow->headerRow);

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        if ('' !== $sheetName) {
            $reader->setLoadSheetsOnly([$sheetName]);
        }

        $spreadsheet = $reader->load($path);
        $sheet = '' !== $sheetName ? $spreadsheet->getSheetByName($sheetName) : null;
        $sheet ??= $spreadsheet->getActiveSheet();

        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $headers = [];
        $seen = [];

        for ($c = 1; $c <= $highestCol; ++$c) {
            $letter = Coordinate::stringFromColumnIndex($c);
            $name = trim((string) $sheet->getCell($letter.$headerRow)->getValue());

            if ('' === $name) {
                continue;
            }

            $base = $name;
            $i = 2;
            while (isset($seen[$name])) {
                $name = $base.' ('.$i.')';
                ++$i;
            }

            $seen[$name] = true;
            $headers[$c] = $name;
        }

        return $headers;
    }

    /**
     * Header names only, in column order (for option pickers).
     *
     * @return array<string, string> name => name
     */
    public function getHeaderOptions(WorkflowModel $workflow): array
    {
        $names = array_values($this->getHeaders($workflow));

        return $names ? array_combine($names, $names) : [];
    }

    private function resolvePath(WorkflowModel $workflow): ?string
    {
        if (!$workflow->sourceFile) {
            return null;
        }

        $this->framework->initialize();

        $file = $this->framework->getAdapter(FilesModel::class)->findByUuid($workflow->sourceFile);

        if (null === $file) {
            return null;
        }

        $path = $this->projectDir.'/'.$file->path;

        return is_file($path) ? $path : null;
    }
}
