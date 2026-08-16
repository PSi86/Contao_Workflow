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
 * isSourceDirty() drives the "run the import" hint shown on the edit mask and in the overview.
 * It must fire exactly when the stored data no longer matches the source file — the file changed
 * after an import, or no import ever ran — and stay quiet for an unchanged one, so the hint is
 * trustworthy.
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
    private function validator(array $values, ?string $resolvedPath): array
    {
        $workflow = $this->createMock(WorkflowModel::class);
        $workflow->method('__get')->willReturnCallback(static fn (string $k): mixed => $values[$k] ?? '');

        $inspector = $this->createMock(SpreadsheetInspector::class);
        $inspector->method('resolvePath')->willReturn($resolvedPath);
        // Both delegate to the file system in the real inspector; only the caching is its own.
        $inspector->method('fileStat')->willReturnCallback(
            static function (string $p): string {
                clearstatcache(true, $p);

                return (int) @filemtime($p).':'.(int) @filesize($p);
            },
        );
        $inspector->method('fileHash')->willReturnCallback(
            static fn (string $p): string => (string) @md5_file($p),
        );

        $validator = new WorkflowValidator(
            $inspector,
            $this->createMock(LinkGenerator::class),
            $this->connection,
            $this->createMock(ColumnFormatAnalyzer::class),
            new ColumnCompatibility(),
        );

        return [$validator, $workflow];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function isSourceDirty(array $values, ?string $resolvedPath): bool
    {
        [$validator, $workflow] = $this->validator($values, $resolvedPath);

        return $validator->isSourceDirty($workflow);
    }

    private function stat(): string
    {
        clearstatcache(true, $this->file);

        return (int) filemtime($this->file).':'.(int) filesize($this->file);
    }

    public function testChangedFileIsDirty(): void
    {
        // A prior import recorded a DIFFERENT checksum than the file now has.
        $dirty = $this->isSourceDirty(
            ['sourceFile' => 'uuid', 'sourceHash' => 'stale-hash-from-the-old-file'],
            $this->file,
        );

        $this->assertTrue($dirty);
    }

    public function testUnchangedFileIsNotDirty(): void
    {
        $dirty = $this->isSourceDirty(
            ['sourceFile' => 'uuid', 'sourceHash' => md5_file($this->file)],
            $this->file,
        );

        $this->assertFalse($dirty);
    }

    /**
     * The reported bug: a freshly created or copied workflow has no checksum (sourceHash is
     * doNotCopy), and the edit mask stayed silent about it. "Never imported" is the very state
     * the hint exists for.
     */
    public function testNeverImportedIsDirty(): void
    {
        $dirty = $this->isSourceDirty(['sourceFile' => 'uuid', 'sourceHash' => ''], $this->file);

        $this->assertTrue($dirty);
    }

    public function testNoSourceFileIsNotDirty(): void
    {
        $dirty = $this->isSourceDirty(['sourceFile' => '', 'sourceHash' => 'anything'], null);

        $this->assertFalse($dirty);
    }

    /**
     * A file that no longer resolves is a separate problem (getProblems() reports "no source"),
     * not an import prompt.
     */
    public function testMissingFileIsNotDirty(): void
    {
        $dirty = $this->isSourceDirty(['sourceFile' => 'uuid', 'sourceHash' => 'x'], null);

        $this->assertFalse($dirty);
    }

    /**
     * The stat shortcut: matching mtime+size means the file was not written since the import,
     * so the checksum is not consulted at all. It must never be able to turn a genuinely
     * changed file into "clean" — that is covered by the next test.
     */
    public function testMatchingStatSkipsTheChecksum(): void
    {
        $dirty = $this->isSourceDirty(
            // A checksum that does NOT match the file: only the stat shortcut can produce
            // "not dirty" here, which is exactly what is being pinned.
            ['sourceFile' => 'uuid', 'sourceHash' => 'nonsense', 'sourceStat' => $this->stat()],
            $this->file,
        );

        $this->assertFalse($dirty);
    }

    public function testChangedFileBeatsAStaleStat(): void
    {
        $before = $this->stat();

        file_put_contents($this->file, 'a new export was written over the old file');
        touch($this->file, time() + 5);

        $dirty = $this->isSourceDirty(
            ['sourceFile' => 'uuid', 'sourceHash' => md5('the current source file contents'), 'sourceStat' => $before],
            $this->file,
        );

        $this->assertTrue($dirty);
    }

    /**
     * Re-saving a file without changing anything (Excel open + save) moves the mtime but not the
     * contents. The stat only shortcuts, it never decides — so the checksum still says "clean".
     */
    public function testResavedButIdenticalFileIsNotDirty(): void
    {
        $hash = (string) md5_file($this->file);
        $before = $this->stat();

        touch($this->file, time() + 5);

        $dirty = $this->isSourceDirty(
            ['sourceFile' => 'uuid', 'sourceHash' => $hash, 'sourceStat' => $before],
            $this->file,
        );

        $this->assertFalse($dirty);
    }

    public function testHasNeverImportedFollowsTheChecksum(): void
    {
        [$fresh, $freshWorkflow] = $this->validator(['sourceFile' => 'uuid', 'sourceHash' => ''], $this->file);
        [$done, $doneWorkflow] = $this->validator(['sourceFile' => 'uuid', 'sourceHash' => 'abc'], $this->file);

        $this->assertTrue($fresh->hasNeverImported($freshWorkflow));
        $this->assertFalse($done->hasNeverImported($doneWorkflow));
    }
}
