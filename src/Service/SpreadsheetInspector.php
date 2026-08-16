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
 *
 * Everything here is memoised for the lifetime of the request, because the back end asks
 * the same questions many times over: rendering one workflow's edit mask used to parse the
 * same file about seven times (the validator's problem list, the orphaned-field check, the
 * slug-collision warning, the placeholder helper, and the option callbacks of sourceSheet,
 * emailField and the two signature fields), and the overview did it two to three times per
 * workflow. The answers cannot change within a request – nothing here writes the source
 * file – so the cache is a pure cost saving, not a behaviour change. Its keys still carry
 * the file's mtime and size, so a file replaced underneath us can never be answered from a
 * stale entry.
 */
class SpreadsheetInspector
{
    /**
     * Resolved absolute paths, keyed by the source file's UUID – NOT by workflow id:
     * SourceFileGuardListener inspects a clone that carries a different file than the
     * stored record, and both must get their own answer.
     *
     * @var array<string, string|null>
     */
    private array $paths = [];

    /** @var array<string, array<int, string>> */
    private array $sheetNames = [];

    /** @var array<string, array<int, string>> */
    private array $headers = [];

    /**
     * The one parsed workbook kept alive (see loadSheet()). Holding exactly one means the
     * peak memory is unchanged – a second file evicts the first – while every consumer of
     * the same file within a request shares a single parse.
     */
    private ?Spreadsheet $loaded = null;

    private ?string $loadedKey = null;

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

        return null === $path ? [] : $this->sheetNamesOf($path);
    }

    /**
     * Worksheet names of a file. Reads the workbook index only (not the cells), but even
     * that is asked for repeatedly – by the sheet picker, the validator and readerFor() –
     * so it is cached like everything else here.
     *
     * @return array<int, string>
     */
    public function sheetNamesOf(string $path): array
    {
        $key = $this->fileKey($path);

        if (!isset($this->sheetNames[$key])) {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);

            $this->sheetNames[$key] = $reader->listWorksheetNames($path);
        }

        return $this->sheetNames[$key];
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

        // Through the cached list: listWorksheetNames() opens the file, and this method runs
        // before every load – it used to double the number of file openings on its own.
        if (!\in_array($sheetName, $this->sheetNamesOf($path), true)) {
            return null;
        }

        $reader->setLoadSheetsOnly([$sheetName]);

        return $reader;
    }

    /**
     * The configured worksheet of $path, parsed at most once per request.
     *
     * $dataOnly=false keeps the style layer, which is what the number formats live in – it
     * is markedly more expensive, so a workbook already parsed WITH styles also answers a
     * later data-only request: it is a superset, and re-reading it would be pure waste.
     *
     * Returns null when the file does not contain $sheetName (see readerFor()).
     */
    public function loadSheet(string $path, string $sheetName, bool $dataOnly): ?Worksheet
    {
        $base = $this->fileKey($path).'|'.$sheetName;
        $key = $base.'|'.($dataOnly ? 'data' : 'full');

        if (null !== $this->loaded && ($this->loadedKey === $key || ($dataOnly && $this->loadedKey === $base.'|full'))) {
            return $this->sheetOf($this->loaded, $sheetName);
        }

        $reader = $this->readerFor($path, $sheetName, $dataOnly);

        if (null === $reader) {
            return null;
        }

        // Replaces whatever was held before – one workbook at a time.
        $this->loaded = $reader->load($path);
        $this->loadedKey = $key;

        return $this->sheetOf($this->loaded, $sheetName);
    }

    /**
     * Drops the parsed workbook. Called by the long-running consumers (importer, console
     * commands) once they are done with a workflow, so a batch over many workflows does not
     * keep the last file around longer than it needs to.
     */
    public function releaseSheet(): void
    {
        $this->loaded = null;
        $this->loadedKey = null;
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
        $key = $this->fileKey($path).'|'.$sheetName.'|'.$headerRow;

        if (!isset($this->headers[$key])) {
            $sheet = $this->loadSheet($path, $sheetName, true);

            // Configured sheet not in the file – the validator reports that as its own problem.
            $this->headers[$key] = null === $sheet ? [] : $this->headersOf($sheet, $headerRow);
        }

        return $this->headers[$key];
    }

    /**
     * Cache key of a file: its path plus the modification time and size. Those two make the
     * key change whenever the file is replaced, so no answer can outlive the file it was
     * read from – cheap insurance for a couple of stat() calls.
     */
    private function fileKey(string $path): string
    {
        clearstatcache(true, $path);

        return $path.'|'.(int) @filemtime($path).'|'.(int) @filesize($path);
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
     * Whether a sheet row is hidden – manually or by an active auto-filter, which both
     * write the same "hidden" flag into the file.
     *
     * Hiding rows is how a source file gets narrowed down to the people a run is meant for,
     * so a hidden row is not imported. The dimension is only asked for when the file
     * actually carries one for that row; getRowDimension() would otherwise create (and
     * cache) one for every row of the sheet.
     *
     * XLSX and XLS carry the flag; ODS and CSV do not, so there every row counts as visible.
     */
    public function isRowHidden(Worksheet $sheet, int $row): bool
    {
        return $sheet->rowDimensionExists($row) && !$sheet->getRowDimension($row)->getVisible();
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

        $key = bin2hex((string) $workflow->sourceFile);

        if (!\array_key_exists($key, $this->paths)) {
            $this->framework->initialize();

            $file = $this->framework->getAdapter(FilesModel::class)->findByUuid($workflow->sourceFile);
            $path = null !== $file ? $this->projectDir.'/'.$file->path : null;

            $this->paths[$key] = null !== $path && is_file($path) ? $path : null;
        }

        return $this->paths[$key];
    }
}
