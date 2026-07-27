<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Excel;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Excel\ColumnCompatibility;
use Psimandl\WorkflowBundle\Excel\NumberFormat;

/**
 * The gate that keeps a "number" question off a column it cannot round-trip. Both
 * directions matter: a real incompatibility has to be refused with a message that names
 * the row, and a perfectly ordinary column must not be refused – a false alarm here blocks
 * the user from saving a field that would have worked.
 */
final class ColumnCompatibilityTest extends TestCase
{
    private ColumnCompatibility $check;

    protected function setUp(): void
    {
        $this->check = new ColumnCompatibility();
    }

    /**
     * @param array<int, array{0: int, 1: NumberFormat, 2: float|null, 3?: string}> $spec
     *
     * @return array<int, array{row: int, format: NumberFormat, mask: string, value: float|null, text: string, empty: bool}>
     */
    private static function cells(array $spec): array
    {
        return array_map(
            static fn (array $c): array => [
                'row'    => $c[0],
                'format' => $c[1],
                'mask'   => $c[3] ?? '#,##0.00',
                'value'  => $c[2],
                'text'   => null === $c[2] ? 'k. A.' : (string) $c[2],
                'empty'  => false,
            ],
            $spec,
        );
    }

    /**
     * Cells with no value at all, carrying only their mask.
     *
     * @param array<int, int> $rows
     *
     * @return array<int, array{row: int, format: NumberFormat, mask: string, value: float|null, text: string, empty: bool}>
     */
    private static function emptyCells(array $rows, NumberFormat $format, string $mask = '#,##0.00'): array
    {
        return array_map(
            static fn (int $row): array => [
                'row'    => $row,
                'format' => $format,
                'mask'   => $mask,
                'value'  => null,
                'text'   => '',
                'empty'  => true,
            ],
            $rows,
        );
    }

    public function testCurrencyColumnIsAccepted(): void
    {
        $euro = NumberFormat::number(2, true, '€');

        $result = $this->check->checkNumberColumn('Betrag', self::cells([
            [2, $euro, 3000.0],
            [3, $euro, 1234.5],
        ]));

        $this->assertTrue($result->isCompatible());
        $this->assertSame(2, $result->format?->decimals);
        $this->assertTrue($result->format?->grouping);
        $this->assertSame('€', $result->format?->currency, 'The symbol stays on the stored value.');
    }

    /**
     * The "Benutzerdefiniert" mask from the report: grouping, no decimals – perfectly fine.
     */
    public function testGroupedIntegerColumnIsAccepted(): void
    {
        $result = $this->check->checkNumberColumn('Anzahl', self::cells([
            [2, NumberFormat::number(0, true), 1234.0],
            [3, NumberFormat::number(0, true), 5678.0],
        ]));

        $this->assertTrue($result->isCompatible());
        $this->assertSame(0, $result->format?->decimals);
        $this->assertTrue($result->format?->grouping);
    }

    public function testThreeDecimalsAreRefusedWithRowAndMask(): void
    {
        $result = $this->check->checkNumberColumn('Betrag', self::cells([
            [2, NumberFormat::number(3, true), 1.234, '#,##0.000'],
        ]));

        $this->assertFalse($result->isCompatible());
        $this->assertNull($result->format);
        $this->assertStringContainsString('Zeile 2', $result->problems[0]);
        $this->assertStringContainsString('3 Nachkommastelle', $result->problems[0]);
        $this->assertStringContainsString('#,##0.000', $result->problems[0]);
        $this->assertStringContainsString(
            'Freitext',
            $result->problems[array_key_last($result->problems)],
            'The alternative must be offered.',
        );
    }

    public function testOneDecimalIsRefused(): void
    {
        $result = $this->check->checkNumberColumn('Betrag', self::cells([
            [2, NumberFormat::number(1, false), 1.5, '0.0'],
        ]));

        $this->assertFalse($result->isCompatible());
    }

    /**
     * @dataProvider unsupportedKinds
     */
    public function testUnsupportedFormatsAreRefused(string $kind, string $label): void
    {
        $result = $this->check->checkNumberColumn('Quote', self::cells([
            [7, NumberFormat::of($kind), 0.5],
        ]));

        $this->assertFalse($result->isCompatible());
        $this->assertStringContainsString('Zeile 7', $result->problems[0]);
        $this->assertStringContainsString($label, $result->problems[0]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function unsupportedKinds(): array
    {
        return [
            'percent'    => [NumberFormat::KIND_PERCENT, 'Prozent'],
            'scientific' => [NumberFormat::KIND_SCIENTIFIC, 'wissenschaftlich'],
            'fraction'   => [NumberFormat::KIND_FRACTION, 'Bruch'],
            'date'       => [NumberFormat::KIND_DATETIME, 'Datum/Zeit'],
        ];
    }

    public function testTextInANumberColumnIsRefused(): void
    {
        $result = $this->check->checkNumberColumn('Betrag', self::cells([
            [2, NumberFormat::number(2, true), 3000.0],
            [5, NumberFormat::number(2, true), null],
        ]));

        $this->assertFalse($result->isCompatible());
        $this->assertStringContainsString('Zeile 5', $result->problems[0]);
        $this->assertStringContainsString('k. A.', $result->problems[0]);
    }

    public function testMixedDecimalsAreRefused(): void
    {
        $result = $this->check->checkNumberColumn('Betrag', self::cells([
            [2, NumberFormat::number(0, true), 3000.0],
            [3, NumberFormat::number(2, true), 1234.5],
        ]));

        $this->assertFalse($result->isCompatible());
        $this->assertStringContainsString('mischt', implode(' ', $result->problems));
    }

    /**
     * A hand-typed integer in an otherwise formatted column is "General" – it fits both 0
     * and 2 decimals, so it must not count as a conflict. Refusing this would be a false
     * alarm on a very common sheet.
     */
    public function testGeneralIntegersDoNotConflict(): void
    {
        $result = $this->check->checkNumberColumn('Betrag', self::cells([
            [2, NumberFormat::number(2, true, '€'), 3000.0],
            [3, NumberFormat::general(), 500.0],
        ]));

        $this->assertTrue($result->isCompatible(), implode(' | ', $result->problems));
        $this->assertSame(2, $result->format?->decimals);
    }

    /**
     * A "General" cell that really does carry decimals pins them – and one decimal is not
     * round-trippable.
     */
    public function testGeneralWithOneDecimalIsRefused(): void
    {
        $result = $this->check->checkNumberColumn('Betrag', self::cells([
            [4, NumberFormat::general(), 3000.5],
        ]));

        $this->assertFalse($result->isCompatible());
        $this->assertStringContainsString('Zeile 4', $result->problems[0]);
    }

    public function testAllGeneralIntegerColumnIsAccepted(): void
    {
        $result = $this->check->checkNumberColumn('Anzahl', self::cells([
            [2, NumberFormat::general(), 12.0],
            [3, NumberFormat::general(), 7.0],
        ]));

        $this->assertTrue($result->isCompatible());
        $this->assertSame(0, $result->format?->decimals);
        $this->assertFalse($result->format?->grouping);
    }

    public function testEmptyColumnIsAccepted(): void
    {
        $result = $this->check->checkNumberColumn('Betrag', []);

        $this->assertTrue($result->isCompatible());
        $this->assertSame(0, $result->format?->decimals);
    }

    /**
     * The reported bug: an answer column is empty in the source by definition – the
     * participants are the ones filling it in – so the mask of its empty cells is the only
     * statement about the column there is. Ignoring it turned "Währung, 2 Nachkommastellen"
     * into "ganzzahlig, keine Währung", and an entered 25,25 was stored as 25.
     */
    public function testEmptyCellsDeclareTheColumnFormat(): void
    {
        $result = $this->check->checkNumberColumn(
            'Betrag',
            self::emptyCells([2, 3, 4], NumberFormat::number(2, true, '€')),
        );

        $this->assertTrue($result->isCompatible());
        $this->assertSame(2, $result->format?->decimals);
        $this->assertTrue($result->format?->grouping);
        $this->assertSame('€', $result->format?->currency);
    }

    /**
     * A cell without a value cannot be wrong – whatever it is formatted as. Otherwise an
     * empty cell left over as text would refuse a column that holds perfectly good numbers.
     */
    public function testEmptyCellsNeverRefuseAColumn(): void
    {
        $result = $this->check->checkNumberColumn('Betrag', array_merge(
            self::cells([[2, NumberFormat::number(2, true, '€'), 3000.0]]),
            self::emptyCells([3], NumberFormat::of(NumberFormat::KIND_TEXT), '@'),
            self::emptyCells([4], NumberFormat::number(3, false), '#,##0.000'),
        ));

        $this->assertTrue($result->isCompatible(), implode(' | ', $result->problems));
        $this->assertSame(2, $result->format?->decimals);
    }

    /**
     * Where values exist they are the better witness: they carry their own mask, and it is
     * the one the stored strings were produced with.
     */
    public function testValuesWinOverTheEmptyDeclaration(): void
    {
        $result = $this->check->checkNumberColumn('Anzahl', array_merge(
            self::cells([[2, NumberFormat::number(0, false), 12.0, '#,##0']]),
            self::emptyCells([3], NumberFormat::number(2, true, '€')),
        ));

        $this->assertTrue($result->isCompatible());
        $this->assertSame(0, $result->format?->decimals);
        $this->assertSame('', $result->format?->currency);
    }

    /**
     * Configured decimals answer the question the decimal rules exist for, so they lift
     * them: a column with mixed or unusual decimals becomes usable instead of trapping the
     * user, who cannot change the source file.
     */
    public function testConfiguredDecimalsLiftTheDecimalRules(): void
    {
        $cells = self::cells([
            [2, NumberFormat::number(1, false), 1.5, '0.0'],
            [3, NumberFormat::number(3, false), 1.234, '0.000'],
        ]);

        $this->assertFalse($this->check->checkNumberColumn('Betrag', $cells)->isCompatible());

        $result = $this->check->checkNumberColumn('Betrag', $cells, 3);

        $this->assertTrue($result->isCompatible(), implode(' | ', $result->problems));
        $this->assertSame(3, $result->format?->decimals);
    }

    /**
     * They lift the decimal rules only. A column that holds text or percentages still
     * cannot back a number field – no setting makes those values numeric.
     */
    public function testConfiguredDecimalsDoNotSilenceRealProblems(): void
    {
        $text = $this->check->checkNumberColumn('Betrag', self::cells([
            [5, NumberFormat::number(2, true), null],
        ]), 2);

        $this->assertFalse($text->isCompatible());
        $this->assertStringContainsString('Zeile 5', $text->problems[0]);

        $percent = $this->check->checkNumberColumn('Quote', self::cells([
            [7, NumberFormat::of(NumberFormat::KIND_PERCENT), 0.5],
        ]), 2);

        $this->assertFalse($percent->isCompatible());
    }

    /**
     * 500 bad rows must not produce 500 messages.
     */
    public function testManyBadRowsAreSummarised(): void
    {
        $spec = [];

        for ($row = 2; $row <= 60; ++$row) {
            $spec[] = [$row, NumberFormat::number(3, true), 1.234, '#,##0.000'];
        }

        $result = $this->check->checkNumberColumn('Betrag', self::cells($spec));

        $this->assertFalse($result->isCompatible());
        $this->assertCount(2, $result->problems, 'One message plus the "Freitext" hint.');
        $this->assertStringContainsString('und 56 weiteren', $result->problems[0]);
    }
}
