<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\ImportLog;
use Psimandl\WorkflowBundle\Service\SourceFileStatus;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;

/**
 * The summary shown under the source-file picker. It exists for one specific mistake: a
 * corrected export is uploaded under a slightly different name, lands beside the configured
 * file instead of replacing it, and every import keeps reading the untouched original —
 * successfully, and therefore silently.
 *
 * Two verdicts break that silence, and both are pinned here: "unchanged since the last import"
 * on a file one believes to have just replaced, and "the last import read a different file".
 */
final class SourceFileStatusTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'wf_status_');
        file_put_contents($this->file, 'current contents');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * @param array<string, mixed>      $values tl_workflow field values
     * @param array<string, mixed>|null $last   the last successful run on record
     *
     * @return array<string, mixed>
     */
    private function describe(array $values, ?array $last, ?string $path = null): array
    {
        $workflow = $this->createMock(WorkflowModel::class);
        $workflow->method('__get')->willReturnCallback(
            static fn (string $k): mixed => $values[$k] ?? ('sourceFile' === $k ? 'uuid' : ''),
        );

        $inspector = $this->createMock(SpreadsheetInspector::class);
        $inspector->method('resolvePath')->willReturn(3 === \func_num_args() ? $path : $this->file);
        $inspector->method('fileHash')->willReturnCallback(static fn (string $p): string => (string) @md5_file($p));

        $log = $this->createMock(ImportLog::class);
        $log->method('lastSuccessful')->willReturn($last);

        return (new SourceFileStatus($inspector, $log, sys_get_temp_dir()))->describe($workflow);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function lastRun(array $overrides = []): array
    {
        return array_merge([
            'tstamp'     => 1_700_000_000,
            'sourceFile' => $this->file,
            'sourceHash' => (string) md5_file($this->file),
        ], $overrides);
    }

    public function testNoSourceFile(): void
    {
        $info = $this->describe(['sourceFile' => ''], null);

        $this->assertSame(SourceFileStatus::STATE_NONE, $info['state']);
    }

    public function testUnresolvableFile(): void
    {
        $info = $this->describe([], null, null);

        $this->assertSame(SourceFileStatus::STATE_MISSING, $info['state']);
    }

    public function testNeverImported(): void
    {
        $info = $this->describe(['sourceHash' => ''], null);

        $this->assertSame(SourceFileStatus::STATE_NEVER_IMPORTED, $info['state']);
        $this->assertFalse($info['otherFile']);
    }

    /**
     * The important one: the file is byte for byte what the last run read. Harmless on its own —
     * and the giveaway for someone who just "replaced" it.
     */
    public function testUnchangedSinceTheLastImport(): void
    {
        $info = $this->describe([], $this->lastRun());

        $this->assertSame(SourceFileStatus::STATE_CURRENT, $info['state']);
        $this->assertSame(1_700_000_000, $info['lastImportAt']);
        $this->assertFalse($info['otherFile']);
    }

    public function testChangedSinceTheLastImport(): void
    {
        $info = $this->describe([], $this->lastRun(['sourceHash' => 'the-hash-of-the-old-version']));

        $this->assertSame(SourceFileStatus::STATE_CHANGED, $info['state']);
    }

    /**
     * The second verdict: the data on record was produced from another file entirely — the
     * "uploaded next to it" mistake, caught by comparing the paths.
     */
    public function testLastImportReadADifferentFile(): void
    {
        $info = $this->describe([], $this->lastRun([
            'sourceFile' => sys_get_temp_dir().'/Basistabelle 2026.xlsx',
            'sourceHash' => 'whatever-that-file-contained',
        ]));

        $this->assertTrue($info['otherFile']);
        $this->assertSame('Basistabelle 2026.xlsx', $info['lastImportPath']);
        $this->assertSame(SourceFileStatus::STATE_CHANGED, $info['state']);
    }

    /**
     * Without a log row (purged, or a run predating the log) tl_workflow.sourceHash still says
     * whether an import ever ran, so the verdict must not fall back to "never imported".
     */
    public function testFallsBackToTheStoredChecksum(): void
    {
        $info = $this->describe(['sourceHash' => (string) md5_file($this->file)], null);

        $this->assertSame(SourceFileStatus::STATE_CURRENT, $info['state']);
        $this->assertSame(0, $info['lastImportAt']);
        $this->assertFalse($info['otherFile'], 'no recorded path means nothing to compare against');
    }

    public function testPathsAreProjectRelative(): void
    {
        $info = $this->describe([], $this->lastRun());

        $this->assertSame(basename($this->file), $info['path']);
        $this->assertStringNotContainsString(sys_get_temp_dir(), $info['path']);
    }
}
