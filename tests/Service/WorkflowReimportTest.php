<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Excel\ColumnCompatibility;
use Psimandl\WorkflowBundle\Excel\ColumnFormatAnalyzer;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\LinkGenerator;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;
use Psimandl\WorkflowBundle\Service\WorkflowValidator;

/**
 * isReimportNeeded() drives the "source changed, re-import" hint shown on the edit mask and the
 * overview. It must fire exactly when the current file differs from what the last import
 * recorded — and stay quiet for a never-imported or unchanged file, so the hint is trustworthy.
 */
final class WorkflowReimportTest extends TestCase
{
    private Connection $connection;
    private string $file;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->file = (string) tempnam(sys_get_temp_dir(), 'wf_src_');
        file_put_contents($this->file, 'the current source file contents');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /**
     * @param array<string, mixed> $values tl_workflow field values
     */
    private function isReimportNeeded(array $values, ?string $resolvedPath): bool
    {
        $workflow = $this->createMock(WorkflowModel::class);
        $workflow->method('__get')->willReturnCallback(static fn (string $k): mixed => $values[$k] ?? '');

        $inspector = $this->createMock(SpreadsheetInspector::class);
        $inspector->method('resolvePath')->willReturn($resolvedPath);

        $validator = new WorkflowValidator(
            $inspector,
            $this->createMock(LinkGenerator::class),
            $this->connection,
            $this->createMock(ColumnFormatAnalyzer::class),
            new ColumnCompatibility(),
        );

        return $validator->isReimportNeeded($workflow);
    }

    public function testChangedFileNeedsReimport(): void
    {
        // A prior import recorded a DIFFERENT checksum than the file now has.
        $needed = $this->isReimportNeeded(
            ['sourceFile' => 'uuid', 'sourceHash' => 'stale-hash-from-the-old-file'],
            $this->file,
        );

        $this->assertTrue($needed);
    }

    public function testUnchangedFileDoesNotNeedReimport(): void
    {
        $needed = $this->isReimportNeeded(
            ['sourceFile' => 'uuid', 'sourceHash' => md5_file($this->file)],
            $this->file,
        );

        $this->assertFalse($needed);
    }

    /**
     * Never imported (empty sourceHash): the separate "run import first" hint covers this, so
     * the "source changed" hint must stay quiet – otherwise both fire at once.
     */
    public function testNeverImportedDoesNotNeedReimport(): void
    {
        $needed = $this->isReimportNeeded(['sourceFile' => 'uuid', 'sourceHash' => ''], $this->file);

        $this->assertFalse($needed);
    }

    public function testNoSourceFileDoesNotNeedReimport(): void
    {
        $needed = $this->isReimportNeeded(['sourceFile' => '', 'sourceHash' => 'anything'], null);

        $this->assertFalse($needed);
    }

    /**
     * A file that no longer resolves is a separate problem (getProblems() reports "no source"),
     * not a re-import prompt.
     */
    public function testMissingFileDoesNotNeedReimport(): void
    {
        $needed = $this->isReimportNeeded(['sourceFile' => 'uuid', 'sourceHash' => 'x'], null);

        $this->assertFalse($needed);
    }
}
