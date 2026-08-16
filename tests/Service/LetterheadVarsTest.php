<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\LetterheadVars;

/**
 * The list of addressable letterhead variables – used both for the ##letterhead_*## suggestions
 * and for picking the signature place. Its one non-obvious rule is the exclusion of the "layout"
 * group: those keys are page margins and font sizes, so offering them would let a millimetre
 * value end up in the letter.
 *
 * collect() is the rule without the model lookup, which is the only part that needs Contao.
 */
final class LetterheadVarsTest extends TestCase
{
    private LetterheadVars $vars;

    /** @var array<string, mixed> the registry a master template declares */
    private array $declared = [
        'Verein'    => '',
        'Ort'       => '',
        'Footer'    => 'Standardfuß',
        'MarginTop' => ['default' => '34', 'label' => 'Rand oben (mm)', 'group' => 'layout'],
        'FontSize'  => ['default' => '11', 'label' => 'Schriftgröße (pt)', 'group' => 'layout'],
    ];

    protected function setUp(): void
    {
        $this->vars = new LetterheadVars();
    }

    public function testLayoutVariablesAreExcluded(): void
    {
        // Even a layout key that HAS a stored value stays out – it is still a page metric.
        $keys = $this->vars->collect(['Verein' => 'TSV', 'MarginTop' => '40'], $this->declared);

        $this->assertContains('Verein', $keys);
        $this->assertContains('Ort', $keys);
        $this->assertContains('Footer', $keys);
        $this->assertNotContains('MarginTop', $keys);
        $this->assertNotContains('FontSize', $keys);
    }

    /**
     * A key filled in on the letterhead comes first; a key the template merely declares still
     * belongs in the list – it exists, it is only empty.
     */
    public function testStoredKeysComeBeforeDeclaredOnes(): void
    {
        $keys = $this->vars->collect(['Ort' => 'Korntal', 'Verein' => 'TSV'], $this->declared);

        $this->assertSame(['Ort', 'Verein', 'Footer'], $keys);
    }

    public function testKeysAreNotDuplicated(): void
    {
        $keys = $this->vars->collect(['Verein' => 'TSV', 'Ort' => 'Korntal', 'Footer' => 'x'], $this->declared);

        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertSame(['Verein', 'Ort', 'Footer'], $keys);
    }

    /**
     * A letterhead may carry keys its template never declared (it was switched afterwards).
     * They still hold a value that the document can print, so they stay addressable.
     */
    public function testStoredKeysOutsideTheRegistryAreKept(): void
    {
        $keys = $this->vars->collect(['Sondertext' => 'x'], $this->declared);

        $this->assertContains('Sondertext', $keys);
    }

    public function testNothingConfiguredYieldsNoKeys(): void
    {
        $this->assertSame([], $this->vars->collect([], []));
    }
}
