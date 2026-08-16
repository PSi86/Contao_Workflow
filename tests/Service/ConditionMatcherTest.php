<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Excel\ValueParser;
use Psimandl\WorkflowBundle\Service\ConditionMatcher;

/**
 * The matcher is shared by the PDF rules and the conditional form fields, so a change here
 * moves both at once – which is the point, and the reason it is pinned down this thoroughly.
 *
 * The number cases guard the original bug the rules had: a stored value carries its column's
 * formatting ("3.000,00 €"), so a naive string comparison made "500" the larger number.
 */
final class ConditionMatcherTest extends TestCase
{
    private ConditionMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ConditionMatcher(new ValueParser());
    }

    /**
     * @dataProvider comparisons
     */
    public function testMatches(string $actual, string $operator, string $expected, bool $result): void
    {
        $this->assertSame($result, $this->matcher->matches($actual, $operator, $expected));
    }

    public static function comparisons(): array
    {
        return [
            // The five operators the conditional form fields offer (E5): plain strings, so the
            // browser can mirror them without a number or date parser.
            'eq hit'                 => ['ja', 'eq', 'ja', true],
            'eq is case sensitive'   => ['Ja', 'eq', 'ja', false],
            'eq miss'                => ['nein', 'eq', 'ja', false],
            'neq hit'                => ['nein', 'neq', 'ja', true],
            'neq miss'               => ['ja', 'neq', 'ja', false],
            'contains is case insensitive' => ['Bahn, Rad', 'contains', 'rad', true],
            'contains miss'          => ['Bahn', 'contains', 'Rad', false],
            'contains empty needle never matches' => ['Bahn', 'contains', '', false],
            'empty on blank'         => ['', 'empty', '', true],
            'empty on whitespace'    => ['   ', 'empty', '', true],
            'empty on value'         => ['0', 'empty', '', false],
            'notempty on value'      => ['nein', 'notempty', '', true],
            'notempty on blank'      => ['', 'notempty', '', false],

            // Ordering operators – PDF rules only, but they must keep working.
            'gt numeric'             => ['3.000,00 €', 'gt', '500', true],
            'lt numeric'             => ['500', 'lt', '3.000,00 €', true],
            'gte equal numbers'      => ['1.000', 'gte', '1000', true],
            'lte string fallback'    => ['abc', 'lte', 'abd', true],
            'eq across formats'      => ['3.000,00 €', 'eq', '3000', true],
            'unknown operator'       => ['ja', 'wat', 'ja', false],
        ];
    }
}
