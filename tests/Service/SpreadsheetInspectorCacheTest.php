<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;

/**
 * The inspector answers the same structural questions many times per back-end request – the
 * edit mask alone used to parse one source file about seven times. The cache that fixes that
 * is only safe as long as it can never serve an answer that outlived its file, so both halves
 * are pinned here: the same file is parsed once, a replaced file is parsed again.
 */
final class SpreadsheetInspectorCacheTest extends TestCase
{
    private string $file;
    private CountingInspector $inspector;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'wf_sheet_').'.xlsx';
        $this->write(['E-Mail', 'Vorname', 'Nachname']);

        $this->inspector = new CountingInspector(
            $this->createMock(ContaoFramework::class),
            sys_get_temp_dir(),
            $this->file,
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * @param array<int, string> $headers
     */
    private function write(array $headers): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Daten');

        foreach ($headers as $i => $name) {
            $sheet->setCellValue([$i + 1, 1], $name);
        }

        $sheet->setCellValue([1, 2], 'a@example.org');

        (new XlsxWriter($spreadsheet))->save($this->file);
    }

    private function workflow(): WorkflowModel
    {
        $values = ['sourceFile' => 'uuid', 'sourceSheet' => 'Daten', 'headerRow' => 1];

        $workflow = $this->createMock(WorkflowModel::class);
        $workflow->method('__get')->willReturnCallback(static fn (string $k): mixed => $values[$k] ?? '');

        return $workflow;
    }

    public function testHeadersAreParsedOncePerRequest(): void
    {
        $workflow = $this->workflow();

        $first = $this->inspector->getHeaders($workflow);
        $second = $this->inspector->getHeaders($workflow);

        $this->assertSame(['E-Mail', 'Vorname', 'Nachname'], array_values($first));
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->inspector->readers, 'the second call must be served from the cache');
    }

    /**
     * The guard that makes the cache safe: a file replaced on disk (a new export written over
     * the old one) changes mtime/size, which is part of every cache key.
     */
    public function testReplacedFileIsReadAgain(): void
    {
        $workflow = $this->workflow();

        $this->assertSame(['E-Mail', 'Vorname', 'Nachname'], array_values($this->inspector->getHeaders($workflow)));

        $this->write(['E-Mail', 'Abteilung']);
        touch($this->file, time() + 5);

        $this->assertSame(['E-Mail', 'Abteilung'], array_values($this->inspector->getHeaders($workflow)));
    }

    /**
     * A workbook parsed WITH the style layer is a superset of a data-only one, so the header
     * lookup that follows a format analysis must not trigger a second parse – that pairing is
     * exactly what the edit mask does.
     */
    public function testStyledParseAlsoServesDataOnlyRequests(): void
    {
        $this->inspector->loadSheet($this->file, 'Daten', false);
        $this->inspector->loadSheet($this->file, 'Daten', true);
        $this->inspector->getHeaders($this->workflow());

        $this->assertSame(1, $this->inspector->readers);
    }

    public function testReleaseSheetDropsTheWorkbook(): void
    {
        $this->inspector->loadSheet($this->file, 'Daten', false);
        $this->inspector->releaseSheet();
        $this->inspector->loadSheet($this->file, 'Daten', false);

        $this->assertSame(2, $this->inspector->readers);
    }

    public function testSheetNamesAreCached(): void
    {
        $this->assertSame(['Daten'], $this->inspector->sheetNamesOf($this->file));
        $this->assertSame(['Daten'], $this->inspector->sheetNamesOf($this->file));
        $this->assertSame(0, $this->inspector->readers, 'listing sheet names must not go through readerFor()');
    }
}

/**
 * Counts the parses. resolvePath() is short-circuited because it is the only part of the
 * inspector that needs the Contao framework (FilesModel) – the caching under test does not.
 */
final class CountingInspector extends SpreadsheetInspector
{
    public int $readers = 0;

    public function __construct(
        ContaoFramework $framework,
        string $projectDir,
        private readonly string $path,
    ) {
        parent::__construct($framework, $projectDir);
    }

    public function resolvePath(WorkflowModel $workflow): ?string
    {
        return $this->path;
    }

    public function readerFor(string $path, string $sheetName, bool $dataOnly): ?IReader
    {
        ++$this->readers;

        return parent::readerFor($path, $sheetName, $dataOnly);
    }
}
