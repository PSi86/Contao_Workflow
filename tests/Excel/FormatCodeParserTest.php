<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Excel;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Excel\FormatCodeParser;
use Psimandl\WorkflowBundle\Excel\NumberFormat;

/**
 * Excel stores a format code with US conventions no matter which locale authored the
 * file, so the code must be read as a description (decimals, grouping, currency) rather
 * than printed. These cases are the ones that actually occur in the source files: the
 * "Benutzerdefiniert" masks, the currency masks in their three notations, and the kinds
 * we deliberately do not re-render.
 */
final class FormatCodeParserTest extends TestCase
{
    private FormatCodeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FormatCodeParser();
    }

    /**
     * @dataProvider numberMasks
     */
    public function testReadsDecimalsAndGrouping(string $mask, int $decimals, bool $grouping): void
    {
        $format = $this->parser->parse($mask);

        $this->assertSame(NumberFormat::KIND_NUMBER, $format->kind);
        $this->assertSame($decimals, $format->decimals);
        $this->assertSame($grouping, $format->grouping);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: bool}>
     */
    public static function numberMasks(): array
    {
        return [
            // The "Benutzerdefiniert" mask from the report: grouping, no decimals.
            'grouped integer'      => ['#,##0', 0, true],
            'grouped two decimals' => ['#,##0.00', 2, true],
            'plain integer'        => ['0', 0, false],
            'plain two decimals'   => ['0.00', 2, false],
            'three decimals'       => ['#,##0.000', 3, true],
            'one decimal'          => ['0.0', 1, false],
            // Only the positive section defines the format.
            'negative section'     => ['#,##0.00;[Red]-#,##0.00', 2, true],
            'zero placeholders'    => ['#,##0.00;-#,##0.00;"-"', 2, true],
            // Excel's built-in "Buchhaltung" mask, verbatim from the real source file.
            'accounting'           => ['_-* #,##0.00\ "€"_-;\-* #,##0.00\ "€"_-;_-* "-"??\ "€"_-;_-@_-', 2, true],
        ];
    }

    /**
     * The mask Excel writes for its built-in "Buchhaltung" format – the one actually used
     * in the production source file. Its padding tokens (_-, *, \) and its "-"?? zero
     * section must not confuse the reader.
     */
    public function testRealAccountingMask(): void
    {
        $format = $this->parser->parse('_-* #,##0.00\ "€"_-;\-* #,##0.00\ "€"_-;_-* "-"??\ "€"_-;_-@_-');

        $this->assertSame(NumberFormat::KIND_NUMBER, $format->kind);
        $this->assertSame(2, $format->decimals);
        $this->assertTrue($format->grouping);
        $this->assertSame('€', $format->currency);
    }

    /**
     * @dataProvider currencyMasks
     */
    public function testExtractsCurrencySymbol(string $mask, string $symbol): void
    {
        $this->assertSame($symbol, $this->parser->parse($mask)->currency);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function currencyMasks(): array
    {
        return [
            'locale marker'   => ['#,##0.00 [$€-407]', '€'],
            'locale de-DE'    => ['#,##0.00 [$€-de-DE]', '€'],
            'bare sign'       => ['#,##0.00 €', '€'],
            'dollar prefix'   => ['$#,##0.00', '$'],
            'quoted iso code' => ['#,##0.00 "eur"', 'EUR'],
            // "[$-407]" is a plain locale marker without a symbol.
            'locale only'     => ['[$-407]#,##0.00', ''],
            'no currency'     => ['#,##0.00', ''],
        ];
    }

    /**
     * @dataProvider specialMasks
     */
    public function testClassifiesFormatsWeDoNotRerender(string $mask, string $kind): void
    {
        $format = $this->parser->parse($mask);

        $this->assertSame($kind, $format->kind);
        $this->assertFalse($format->isNumeric(), 'Such a format must keep PhpSpreadsheet\'s output.');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function specialMasks(): array
    {
        return [
            'percent'          => ['0.00%', NumberFormat::KIND_PERCENT],
            'scientific'       => ['0.00E+00', NumberFormat::KIND_SCIENTIFIC],
            'fraction'         => ['# ?/?', NumberFormat::KIND_FRACTION],
            'date'             => ['dd.mm.yyyy', NumberFormat::KIND_DATETIME],
            'us date'          => ['m/d/yyyy', NumberFormat::KIND_DATETIME],
            'text'             => ['@', NumberFormat::KIND_TEXT],
        ];
    }

    public function testGeneralHasNoFixedDecimals(): void
    {
        foreach (['', 'General', 'GENERAL'] as $mask) {
            $format = $this->parser->parse($mask);

            $this->assertSame(NumberFormat::KIND_GENERAL, $format->kind);
            $this->assertNull($format->decimals, 'General keeps whatever decimals the value carries.');
            $this->assertTrue($format->isNumeric());
        }
    }

    /**
     * A currency mask must never be mistaken for a date just because its quoted ISO code
     * or locale marker contains letters that look like date tokens ("d", "m", "y").
     */
    public function testCurrencyMaskIsNotADate(): void
    {
        foreach (['#,##0.00 "USD"', '#,##0.00 [$€-de-DE]', '#,##0.00 "EUR"'] as $mask) {
            $this->assertSame(NumberFormat::KIND_NUMBER, $this->parser->parse($mask)->kind, $mask);
        }
    }
}
