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
     * @return array<int, array{row: int, format: NumberFormat, mask: string, value: float|null, text: string}>
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
            ],
            $spec,
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
