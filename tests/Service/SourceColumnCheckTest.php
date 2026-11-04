<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SourceColumnCheck;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;
use Psimandl\WorkflowBundle\Service\WorkflowLock;

/**
 * The one column rule for every way a new source file arrives (picked, uploaded in the mask,
 * uploaded in the overview): while answers exist, only a file with the identical columns.
 */
final class SourceColumnCheckTest extends TestCase
{
    private const CURRENT = ['E-Mail', 'Name', 'Abteilung'];

    public function testAnUnlockedWorkflowTakesAnyFile(): void
    {
        $this->check(false, ['Ganz', 'anders'])->assertSameColumns($this->workflow(), '/tmp/new.csv');
        $this->addToAssertionCount(1);
    }

    public function testALockedWorkflowTakesTheSameColumns(): void
    {
        $this->check(true, self::CURRENT)->assertSameColumns($this->workflow(), '/tmp/new.csv');
        $this->addToAssertionCount(1);
    }

    public function testALockedWorkflowRefusesOtherColumnsAndSaysWhich(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/fehlt: „Abteilung"; neu: „Sparte"/i');

        $this->check(true, ['E-Mail', 'Name', 'Sparte'])->assertSameColumns($this->workflow(), '/tmp/new.csv');
    }

    public function testALockedWorkflowRefusesAFileWithoutColumns(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/keine Spalten gelesen/');

        $this->check(true, [])->assertSameColumns($this->workflow(), null);
    }

    public function testTheNewFileIsReadWithTheWorkflowsSheetAndHeaderRow(): void
    {
        $inspector = $this->createMock(SpreadsheetInspector::class);
        $inspector->method('getHeaders')->willReturn(self::CURRENT);
        $inspector->expects($this->once())->method('headersOfFile')->with('/tmp/new.xlsx', 'Liste', 3)->willReturn(self::CURRENT);

        (new SourceColumnCheck($this->lock(true), $inspector))->assertSameColumns($this->workflow(), '/tmp/new.xlsx');
    }

    /**
     * @param array<int, string> $newHeaders
     */
    private function check(bool $locked, array $newHeaders): SourceColumnCheck
    {
        $inspector = $this->createMock(SpreadsheetInspector::class);
        $inspector->method('getHeaders')->willReturn(self::CURRENT);
        $inspector->method('headersOfFile')->willReturn($newHeaders);

        return new SourceColumnCheck($this->lock($locked), $inspector);
    }

    private function lock(bool $locked): WorkflowLock
    {
        $lock = $this->createMock(WorkflowLock::class);
        $lock->method('isLocked')->willReturn($locked);

        return $lock;
    }

    private function workflow(): WorkflowModel
    {
        $workflow = $this->createMock(WorkflowModel::class);
        $workflow->method('__get')->willReturnCallback(static fn (string $key): mixed => ['id' => 7, 'sourceSheet' => 'Liste', 'headerRow' => 3][$key] ?? null);

        return $workflow;
    }
}
