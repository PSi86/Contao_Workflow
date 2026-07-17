<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Excel;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Excel\ValueParser;

/**
 * The parser carries the guarantee the whole storage model rests on: in German the dot is
 * never a decimal separator, so "1.234" is 1234. Getting this wrong is not cosmetic – it
 * is the factor-1000 error that made a number field falsify its value.
 */
final class ValueParserTest extends TestCase
{
    private ValueParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ValueParser();
    }

    /**
     * @dataProvider values
     */
    public function testParse(string $input, ?float $expected): void
    {
        $this->assertSame($expected, $this->parser->parse($input));
    }

    /**
     * @return array<string, array{0: string, 1: float|null}>
     */
    public static function values(): array
    {
        return [
            // The regression: grouping must not be read as decimals.
            'grouped integer'        => ['1.234', 1234.0],
            'grouped millions'       => ['1.234.567', 1234567.0],
            'grouped with decimals'  => ['1.234,50', 1234.5],
            'german decimals'        => ['1234,5', 1234.5],
            'plain integer'          => ['1234', 1234.0],

            // Currency and whitespace are ignored.
            'currency symbol'        => ['3.000,00 €', 3000.0],
            'iso code'               => ['3.000,00 EUR', 3000.0],
            'currency prefix'        => ['$1,234.50', 1234.5],
            'non breaking space'     => ["1.234,50\u{00A0}€", 1234.5],

            // Lenient participant input – not our own notation, but obvious in meaning.
            'dot decimals'           => ['1234.5', 1234.5],
            'short dot decimals'     => ['12.34', 12.34],
            'english grouping'       => ['1,234,567', 1234567.0],
            'leading zero'           => ['0,5', 0.5],
            'negative'               => ['-1.234,50', -1234.5],
            'explicit plus'          => ['+42', 42.0],
            'surrounding space'      => ['  42  ', 42.0],

            // Nothing parsable.
            'empty'                  => ['', null],
            'text'                   => ['Beispiel', null],
            'currency only'          => ['€', null],
            'malformed'              => ['1-2-3', null],
        ];
    }

    /**
     * "1.234" would be 1.234 if the dot were read as a decimal point – a value wrong by a
     * factor of 1000. This is the exact case the number field used to get wrong.
     */
    public function testGroupedIntegerIsNotADecimal(): void
    {
        $this->assertSame(1234.0, $this->parser->parse('1.234'));
        $this->assertNotSame(1.234, $this->parser->parse('1.234'));
    }

    /**
     * A dot that cannot be grouping (wrong digit count) has to be a decimal point – this
     * is what keeps lenient input working without breaking the rule above.
     */
    public function testUngroupableDotStaysDecimal(): void
    {
        $this->assertSame(1234.5, $this->parser->parse('1234.5'), 'Four leading digits cannot be a group.');
        $this->assertSame(12.345678, $this->parser->parse('12.345678'), 'Six trailing digits cannot be a group.');
    }

    /**
     * When both separators appear the last one is the decimal – so our own "1.234,50" and
     * a pasted English "1,234.50" both mean the same number.
     */
    public function testLastSeparatorWins(): void
    {
        $this->assertSame(1234.5, $this->parser->parse('1.234,50'));
        $this->assertSame(1234.5, $this->parser->parse('1,234.50'));
    }

    public function testIsNumeric(): void
    {
        $this->assertTrue($this->parser->isNumeric('1.234,50 €'));
        $this->assertFalse($this->parser->isNumeric(''));
        $this->assertFalse($this->parser->isNumeric('Beispiel'));
    }

    /**
     * The upgrade path for number fields that predate the format snapshot: their column's
     * format has to be recovered from a stored value, or they would be re-rendered with a
     * default and lose their decimals and grouping.
     *
     * @dataProvider inferCases
     */
    public function testInferFormat(string $stored, int $decimals, bool $grouping, string $currency): void
    {
        $format = $this->parser->inferFormat($stored);

        $this->assertNotNull($format);
        $this->assertSame($decimals, $format->decimals, 'decimals');
        $this->assertSame($grouping, $format->grouping, 'grouping');
        $this->assertSame($currency, $format->currency, 'currency');
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: bool, 3: string}>
     */
    public static function inferCases(): array
    {
        return [
            'currency'         => ['3.000,00 €', 2, true, '€'],
            'iso code'         => ['3.000,00 EUR', 2, true, 'EUR'],
            'grouped integer'  => ['1.234', 0, true, ''],
            'plain integer'    => ['3000', 0, false, ''],
            'decimals only'    => ['1234,50', 2, false, ''],
            'grouped decimals' => ['1.234.567,89', 2, true, ''],
        ];
    }

    public function testInferFormatReturnsNullWithoutANumber(): void
    {
        $this->assertNull($this->parser->inferFormat('Beispiel'));
        $this->assertNull($this->parser->inferFormat(''));
    }

    /**
     * The property that makes the fallback safe: re-rendering a stored value with its own
     * inferred format reproduces it exactly, so an untouched field never drifts.
     */
    public function testInferredFormatReproducesTheValue(): void
    {
        $formatter = new \Psimandl\WorkflowBundle\Excel\ValueFormatter();

        foreach (['3.000,00 €', '1.234', '3000', '1234,50', '1.234.567,89'] as $stored) {
            $format = $this->parser->inferFormat($stored);
            $number = $this->parser->parse($stored);

            $this->assertNotNull($format);
            $this->assertNotNull($number);
            $this->assertSame($stored, $formatter->format($number, $format), $stored);
        }
    }
}
