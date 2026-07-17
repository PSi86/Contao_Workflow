<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Slugger;

/**
 * The one slug engine behind every ##token##, file name and download-header fallback. Two
 * properties must hold: for German it is bit-identical to the old hand-rolled table (so no
 * existing ##data_*## reference breaks), and for any other script it produces a real slug
 * instead of the empty string the old code collapsed everything non-Latin to.
 */
final class SluggerTest extends TestCase
{
    private Slugger $slugger;

    protected function setUp(): void
    {
        $this->slugger = new Slugger();
    }

    /**
     * The tokens the old normalize() produced for the real production column names. These are
     * the exact strings existing PDF templates reference, so any drift here breaks live
     * workflows.
     *
     * @dataProvider germanTokens
     */
    public function testGermanTokensAreUnchanged(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->slugger->token($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function germanTokens(): array
    {
        return [
            'umlaut phrase'   => ['Höhe der ÜLP', 'hoehe_der_uelp'],
            'acronym'         => ['Übungsleiter ÜLP 2026', 'uebungsleiter_uelp_2026'],
            'sharp s'         => ['Straße', 'strasse'],
            'sharp s word'    => ['Fußball', 'fussball'],
            'o umlaut'        => ['Öffnungszeiten', 'oeffnungszeiten'],
            'a umlaut'        => ['Änderung', 'aenderung'],
            'hyphen'          => ['E-Mail', 'e_mail'],
            'trailing colon'  => ['Stundenlohn:', 'stundenlohn'],
            'plain'           => ['Geburtsdatum', 'geburtsdatum'],
            'multi word'      => ['Tätigkeit in Abteilung', 'taetigkeit_in_abteilung'],
        ];
    }

    /**
     * The whole point of the change: non-Latin scripts used to reduce to "" (so every column
     * collided on ##data_##). Now each gets a distinct, non-empty slug.
     *
     * @dataProvider internationalTokens
     */
    public function testInternationalTokensAreNotEmpty(string $input, string $expected): void
    {
        $slug = $this->slugger->token($input);

        $this->assertNotSame('', $slug, 'a non-Latin name must not collapse to an empty slug');
        $this->assertSame($expected, $slug);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function internationalTokens(): array
    {
        return [
            'cyrillic'      => ['Отдел', 'otdel'],
            'cyrillic word' => ['Зарплата', 'zarplata'],
            'greek'         => ['Μέγεθος', 'megethos'],
            'accented'      => ['Málaga', 'malaga'],
            'cjk'           => ['人事部', 'ren_shi_bu'],
        ];
    }

    /**
     * Two distinct non-Latin names must produce two distinct slugs — the old code collapsed
     * both to "" and lost one to the collision.
     */
    public function testDistinctNonLatinNamesDoNotCollide(): void
    {
        $this->assertNotSame($this->slugger->token('Отдел'), $this->slugger->token('Зарплата'));
    }

    public function testAsciiPreservesCase(): void
    {
        $this->assertSame('EStG_Uebungsleiter', $this->slugger->ascii('EStG Übungsleiter'));
        $this->assertSame('Otdel_Kadrov', $this->slugger->ascii('Отдел Кадров'));
    }

    /**
     * unicode() keeps the original letters (umlauts, any script) for the RFC 5987 download
     * name; only spaces/punctuation/path separators become the separator.
     */
    public function testUnicodeKeepsLetters(): void
    {
        $this->assertSame('EStG_Übungsleiter', $this->slugger->unicode('EStG Übungsleiter'));
        $this->assertSame('Отдел_кадров', $this->slugger->unicode('Отдел кадров'));
        $this->assertSame('人事部', $this->slugger->unicode('人事部'));
    }

    /**
     * A download name must never carry a path separator (it would break the header / traversal).
     * Both ascii() and unicode() have to neutralise them.
     */
    public function testPathSeparatorsAreStripped(): void
    {
        foreach (['../etc/passwd', 'a/b\\c'] as $evil) {
            $this->assertStringNotContainsString('/', $this->slugger->unicode($evil));
            $this->assertStringNotContainsString('\\', $this->slugger->unicode($evil));
            $this->assertStringNotContainsString('/', $this->slugger->ascii($evil));
        }
    }

    /**
     * A name that has nothing usable left returns '' so the caller can fall back to a generic
     * name (never a raw empty download filename).
     */
    public function testUnusableInputReturnsEmpty(): void
    {
        $this->assertSame('', $this->slugger->ascii('!!!'));
        $this->assertSame('', $this->slugger->token('—'));
        $this->assertSame('', $this->slugger->unicode('/// \\\\'));
    }
}
