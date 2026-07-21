<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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
     * A reader for $path, restricted to $sheetName – but only when the file actually contains
     * that sheet. Returns null when it does not.
     *
     * Restricting to a sheet the file does not have makes PhpSpreadsheet load *zero*
     * worksheets and then fail deep inside the reader ("You tried to set a sheet active by the
     * out of bounds index: 0"). That is reachable with a plain configuration mistake – swap
     * the source file for one whose sheet is named differently – and must not surface as a
     * crash. listWorksheetNames() only reads the workbook index, not the cells, so checking
     * first is cheap.
     *
     * $dataOnly must stay false wherever number formats are needed (import, format analysis);
     * with it, PhpSpreadsheet drops the formats and every number would be re-interpreted.
     */
    public function readerFor(string $path, string $sheetName, bool $dataOnly): ?IReader
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly($dataOnly);

        if ('' === $sheetName) {
            return $reader;
        }

        if (!\in_array($sheetName, $reader->listWorksheetNames($path), true)) {
            return null;
        }

        $reader->setLoadSheetsOnly([$sheetName]);

        return $reader;
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
        $reader = $this->readerFor($path, $sheetName, true);

        // Configured sheet not in the file – the validator reports that as its own problem.
        if (null === $reader) {
            return [];
        }

        return $this->headersOf($this->sheetOf($reader->load($path), $sheetName), max(1, (int) $workflow->headerRow));
    }

    /**
     * The header row of an already loaded sheet, de-duplicated. Split out so every reader
     * (headers, importer, format analyzer) derives the exact same column names from the
     * same rule – a second implementation would silently drift apart on duplicates.
     *
     * @return array<int, string> column index (1-based) => header name
     */
    public function headersOf(Worksheet $sheet, int $headerRow): array
    {
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
     * The configured sheet of a loaded spreadsheet, falling back to the active one.
     */
    public function sheetOf(Spreadsheet $spreadsheet, string $sheetName): Worksheet
    {
        $sheet = '' !== $sheetName ? $spreadsheet->getSheetByName($sheetName) : null;

        return $sheet ?? $spreadsheet->getActiveSheet();
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

    /**
     * Absolute path of the workflow's source file, or null when it is unset or gone.
     */
    public function resolvePath(WorkflowModel $workflow): ?string
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
