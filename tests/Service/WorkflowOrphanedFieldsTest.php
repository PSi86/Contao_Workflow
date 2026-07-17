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
 * orphanedFields() drives the red outlines in the edit mask. It has to name EVERY field whose
 * value is a source column – copying a workflow drops its source file (doNotCopy), so all of
 * them point at nothing and the user must see which ones to redo.
 *
 * The two signature-line fields were missing from that list, so they stayed unmarked on a copy
 * while every other source-dependent field turned red.
 */
final class WorkflowOrphanedFieldsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    /**
     * @param array<string, string> $values  tl_workflow field values
     * @param array<int, string>    $headers source columns the file has
     */
    private function orphaned(array $values, array $headers, bool $hasSourceFile = true): array
    {
        $workflow = $this->createMock(WorkflowModel::class);
        $workflow->method('__get')->willReturnCallback(
            static fn (string $key): mixed => $values[$key] ?? ($hasSourceFile && 'sourceFile' === $key ? 'uuid' : ''),
        );
        $workflow->method('getQuestions')->willReturn([]);
        $workflow->method('getRules')->willReturn([]);

        $inspector = $this->createMock(SpreadsheetInspector::class);
        $inspector->method('getHeaderOptions')->willReturn(array_combine($headers, $headers) ?: []);

        $validator = new WorkflowValidator(
            $inspector,
            $this->createMock(LinkGenerator::class),
            $this->connection,
            $this->createMock(ColumnFormatAnalyzer::class),
            new ColumnCompatibility(),
        );

        return $validator->orphanedFields($workflow);
    }

    /**
     * Without a source file nothing can resolve, so every column-dependent field is flagged –
     * this is the state right after a copy, and the exact case that was reported.
     */
    public function testCopyWithoutSourceFileFlagsBothSignatureFields(): void
    {
        $orphaned = $this->orphaned(
            ['pdfSignatureDate' => 'Datum Verzicht', 'pdfSignatureLocation' => 'Wohnort'],
            [],
            false,
        );

        $this->assertContains('pdfSignatureDate', $orphaned);
        $this->assertContains('pdfSignatureLocation', $orphaned);
        // The fields that already worked must keep working.
        $this->assertContains('emailField', $orphaned);
        $this->assertContains('questions', $orphaned);
        $this->assertContains('rules', $orphaned);
        $this->assertContains('sourceSheet', $orphaned);
    }

    /**
     * A healthy workflow must stay unmarked – a false red outline is its own bug.
     */
    public function testResolvableColumnsAreNotFlagged(): void
    {
        $orphaned = $this->orphaned(
            [
                'emailField'           => 'E-Mail',
                'pdfSignatureDate'     => 'Datum Verzicht',
                'pdfSignatureLocation' => 'Wohnort',
            ],
            ['E-Mail', 'Datum Verzicht', 'Wohnort'],
        );

        $this->assertSame([], $orphaned);
    }

    /**
     * A stale value on an otherwise readable file is flagged per field, not wholesale.
     */
    public function testOnlyTheUnresolvableFieldIsFlagged(): void
    {
        $orphaned = $this->orphaned(
            [
                'emailField'           => 'E-Mail',
                'pdfSignatureDate'     => 'Datum Verzicht',
                'pdfSignatureLocation' => 'Ort von frueher',
            ],
            ['E-Mail', 'Datum Verzicht', 'Wohnort'],
        );

        $this->assertSame(['pdfSignatureLocation'], $orphaned);
    }

    /**
     * An empty value is not orphaned – it is simply unset (both signature fields are optional,
     * "leer = kein Datum/Ort").
     */
    public function testEmptyValuesAreNotFlagged(): void
    {
        $orphaned = $this->orphaned(
            ['emailField' => 'E-Mail', 'pdfSignatureDate' => '', 'pdfSignatureLocation' => ''],
            ['E-Mail'],
        );

        $this->assertSame([], $orphaned);
    }
}
