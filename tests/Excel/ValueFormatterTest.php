<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Excel;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Excel\FormatCodeParser;
use Psimandl\WorkflowBundle\Excel\NumberFormat;
use Psimandl\WorkflowBundle\Excel\ValueFormatter;
use Psimandl\WorkflowBundle\Excel\ValueParser;

/**
 * The one place that turns a number into something a human reads. Since the formatted
 * string *is* the stored value, format() must be exactly reversible by
 * {@see ValueParser} – that round trip is what the whole storage model rests on.
 */
final class ValueFormatterTest extends TestCase
{
    private ValueFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ValueFormatter();
    }

    public function testGermanConventions(): void
    {
        $currency = NumberFormat::number(2, true, '€');

        $this->assertSame('3.000,00 €', $this->formatter->format(3000.0, $currency));
        $this->assertSame('1.234.567,89 €', $this->formatter->format(1234567.89, $currency));
        $this->assertSame('-1.234,50 €', $this->formatter->format(-1234.5, $currency));
    }

    /**
     * The "Benutzerdefiniert" mask from the report: grouping, no decimals. 1234 must read
     * as "1.234" – with a thousands dot, not a decimal comma.
     */
    public function testGroupedIntegerKeepsThousandsDot(): void
    {
        $this->assertSame('1.234', $this->formatter->format(1234.0, NumberFormat::number(0, true)));
    }

    public function testGroupingIsOnlyAppliedWhenTheMaskGroups(): void
    {
        $this->assertSame('1234,50', $this->formatter->format(1234.5, NumberFormat::number(2, false)));
        $this->assertSame('1.234,50', $this->formatter->format(1234.5, NumberFormat::number(2, true)));
    }

    /**
     * A "number" question edits the bare number – the symbol stays on the stored value
     * but never reaches the input field.
     */
    public function testCurrencyCanBeStripped(): void
    {
        $format = NumberFormat::number(2, true, '€');

        $this->assertSame('3.000,00 €', $this->formatter->format(3000.0, $format));
        $this->assertSame('3.000,00', $this->formatter->format(3000.0, $format, false));
    }

    /**
     * "General" carries no fixed decimals: an integer stays an integer ("3000" must never
     * become "3.000,00"), and a decimal only gets its separator localised – which is what
     * German Excel shows for such a cell.
     */
    public function testGeneralKeepsTheValuesOwnDigits(): void
    {
        $general = NumberFormat::general();

        $this->assertSame('3000', $this->formatter->format(3000.0, $general));
        $this->assertSame('3000,5', $this->formatter->format(3000.5, $general));
        $this->assertSame('0,25', $this->formatter->format(0.25, $general));
    }

    /**
     * Percent, scientific, fraction, date and text are not re-rendered – the caller keeps
     * PhpSpreadsheet's own output for them.
     */
    public function testNonNumericKindsAreNotRendered(): void
    {
        foreach ([
            NumberFormat::KIND_PERCENT,
            NumberFormat::KIND_SCIENTIFIC,
            NumberFormat::KIND_FRACTION,
            NumberFormat::KIND_DATETIME,
            NumberFormat::KIND_TEXT,
        ] as $kind) {
            $this->assertNull($this->formatter->format(1.5, NumberFormat::of($kind)), $kind);
        }
    }

    /**
     * The property the storage model depends on: whatever we print, we can read back.
     *
     * @dataProvider roundTripCases
     */
    public function testFormatIsReversible(float $value, string $mask): void
    {
        $format = (new FormatCodeParser())->parse($mask);
        $printed = $this->formatter->format($value, $format);

        $this->assertNotNull($printed);
        $this->assertSame($value, (new ValueParser())->parse($printed), sprintf('"%s" (%s)', $printed, $mask));
    }

    /**
     * @return array<string, array{0: float, 1: string}>
     */
    public static function roundTripCases(): array
    {
        return [
            'grouped integer'   => [1234.0, '#,##0'],
            'grouped millions'  => [1234567.0, '#,##0'],
            'currency'          => [3000.0, '#,##0.00 [$€-407]'],
            'currency decimals' => [1234.5, '#,##0.00 €'],
            'no grouping'       => [1234.5, '0.00'],
            'negative'          => [-1234.5, '#,##0.00'],
            'zero'              => [0.0, '#,##0.00'],
            'general integer'   => [3000.0, 'General'],
            'general decimal'   => [3000.5, 'General'],
            'small decimal'     => [0.25, 'General'],
        ];
    }
}
