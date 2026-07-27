<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\ImportSummary;
use Psimandl\WorkflowBundle\Service\SpreadsheetImporter;

/**
 * The words an import run is reported in – used by the back end message AND by the import
 * log. They have to say the same thing: the log exists to explain a state weeks later, which
 * it can only do if it repeats what the user was told at the time.
 *
 * The second contract is tolerance: a log entry is a stored snapshot, so a summary read back
 * may predate a counter that exists today. Nothing here may assume a key.
 */
final class ImportSummaryTest extends TestCase
{
    private ImportSummary $summary;

    protected function setUp(): void
    {
        $this->summary = new ImportSummary();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function outcome(array $overrides = []): array
    {
        return array_merge([
            'inserted' => 0, 'updated' => 0, 'protected' => 0, 'total' => 0,
            'hidden' => 0, 'hiddenKnown' => 0, 'duplicates' => 0, 'missing' => 0,
            'removed' => 0, 'removedAnswered' => 0, 'sharedRows' => 0,
            'collisions' => [], 'formatProblems' => [], 'formulaProblems' => [],
        ], $overrides);
    }

    public function testHeadlineNamesWhatHappened(): void
    {
        $headline = $this->summary->headline(
            self::outcome(['inserted' => 3, 'updated' => 12, 'protected' => 5, 'total' => 20]),
            SpreadsheetImporter::MODE_ADD,
        );

        $this->assertSame('3 neu hinzugefügt, 12 aktualisiert, 5 unverändert (bereits beantwortet) (gesamt 20).', $headline);
    }

    /**
     * Nothing frozen, nothing deleted: those clauses stay out instead of reading "0".
     */
    public function testHeadlineOmitsWhatDidNotHappen(): void
    {
        $headline = $this->summary->headline(
            self::outcome(['inserted' => 2, 'total' => 2]),
            SpreadsheetImporter::MODE_ADD,
        );

        $this->assertSame('2 neu hinzugefügt, 0 aktualisiert (gesamt 2).', $headline);
    }

    public function testQuietRunHasNoNotes(): void
    {
        $this->assertSame([], $this->summary->notes(self::outcome(['inserted' => 5, 'total' => 5]), SpreadsheetImporter::MODE_ADD));
    }

    public function testHiddenRowsAreReportedWithTheKnownOnes(): void
    {
        $notes = $this->summary->notes(self::outcome(['hidden' => 4, 'hiddenKnown' => 2]), SpreadsheetImporter::MODE_ADD);

        $this->assertCount(1, $notes);
        $this->assertStringContainsString('4 ausgeblendete Zeile(n) übersprungen', $notes[0]);
        $this->assertStringContainsString('davon 2 bereits früher importiert', $notes[0]);
    }

    /**
     * The same situation reads differently per mode – that IS the difference between them,
     * and the log has to preserve it for a run that happened long ago.
     */
    public function testTheModeDecidesHowMissingEntriesAreReported(): void
    {
        $additive = $this->summary->notes(self::outcome(['missing' => 3]), SpreadsheetImporter::MODE_ADD);
        $this->assertStringContainsString('bleiben sie bestehen', str_replace('Im Modus „additiv"', 'bleiben sie bestehen', $additive[0]));
        $this->assertStringContainsString('3 vorhandene(r) Eintrag', $additive[0]);

        // In absolute mode the entries are gone, so "missing" is not the story – "removed" is.
        $absolute = $this->summary->notes(
            self::outcome(['missing' => 3, 'removed' => 3, 'removedAnswered' => 1]),
            SpreadsheetImporter::MODE_ABSOLUTE,
        );

        $this->assertCount(1, $absolute);
        $this->assertStringContainsString('3 Eintrag/Einträge gelöscht', $absolute[0]);
        $this->assertStringContainsString('darunter 1 bereits beantwortete', $absolute[0]);
    }

    public function testSharedRowNumbersAreCalledOut(): void
    {
        $notes = $this->summary->notes(self::outcome(['sharedRows' => 2]), SpreadsheetImporter::MODE_ADD);

        $this->assertStringContainsString('2 Zeilennummer(n) sind doppelt belegt', $notes[0]);
    }

    public function testProblemsCarryTheirOrigin(): void
    {
        $problems = $this->summary->problems(self::outcome([
            'formulaProblems' => ['Spalte „Lohn": Formel ohne verwertbares Ergebnis …'],
            'formatProblems'  => ['Feld „Betrag": …'],
            'collisions'      => ['stundenlohn' => ['Stundenlohn', 'Stundenlohn:']],
        ]));

        $this->assertCount(3, $problems);
        // Verbatim – the formula message says what it is; a prefix would repeat it.
        $this->assertSame('Spalte „Lohn": Formel ohne verwertbares Ergebnis …', $problems[0]);
        $this->assertStringStartsWith('Zahlenformat nicht übernommen', $problems[1]);
        $this->assertStringContainsString('##data_stundenlohn##', $problems[2]);
        $this->assertStringContainsString('ignoriert „Stundenlohn:"', $problems[2]);
    }

    /**
     * A stored summary is a snapshot: one written before a counter existed must still render.
     */
    public function testAnOldSummaryWithoutTodaysCountersStillRenders(): void
    {
        $old = ['inserted' => 2, 'updated' => 1, 'total' => 3];

        $this->assertSame('2 neu hinzugefügt, 1 aktualisiert (gesamt 3).', $this->summary->headline($old, 'add'));
        $this->assertSame([], $this->summary->notes($old, 'add'));
        $this->assertSame([], $this->summary->problems($old));
        $this->assertSame(['neu' => 2, 'aktualisiert' => 1, 'gesamt' => 3], $this->summary->counts($old));
    }

    /**
     * The log table shows the counters that carry a run plus whatever actually happened –
     * a column of zeros would bury the one number that matters.
     */
    public function testCountsKeepTheCoreAndDropIdleZeros(): void
    {
        $counts = $this->summary->counts(self::outcome([
            'inserted' => 0, 'updated' => 4, 'total' => 4, 'hidden' => 2,
        ]));

        $this->assertSame(['neu' => 0, 'aktualisiert' => 4, 'ausgeblendet übersprungen' => 2, 'gesamt' => 4], $counts);
    }
}
