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
            // Capital umlauts transliterate in caps (ÄÖÜ -> AEOEUE) for the case-preserving
            // file name; the lower-cased token must be unaffected by that, because it is what
            // every ##data_*## reference resolves against.
            'all caps umlaut' => ['ÄÖÜ', 'aeoeue'],
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

    /**
     * ascii() is the fallback of a download header, so it keeps capitalisation – and must not
     * drop a character the way a plain character-class replace did ("EStG Übungsleiter" once
     * became "EStG_bungsleiter").
     *
     * @dataProvider asciiNames
     */
    public function testAsciiPreservesCase(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->slugger->ascii($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function asciiNames(): array
    {
        return [
            'umlaut'           => ['EStG Übungsleiter', 'EStG_Uebungsleiter'],
            'cyrillic'         => ['Отдел Кадров', 'Otdel_Kadrov'],
            'sharp s'          => ['Straßenfest', 'Strassenfest'],
            'all umlauts'      => ['äöü ÄÖÜ ß', 'aeoeue_AEOEUE_ss'],
            'acronym'          => ['Übungsleiter ÜLP 2026', 'Uebungsleiter_UELP_2026'],
            'keeps case'       => ['CamelCase Titel', 'CamelCase_Titel'],
            'dash with spaces' => ['Demo - Einverständnis', 'Demo_Einverstaendnis'],
            'punctuation'      => ['Verzicht: Ablehnung (2026)', 'Verzicht_Ablehnung_2026'],
            'hyphen'           => ['Anti-Aging-Kurs', 'Anti_Aging_Kurs'],
            'multiple spaces'  => ['A   B', 'A_B'],
            'trims separators' => ['  - Titel -  ', 'Titel'],
        ];
    }

    /**
     * fileName() names the documents this bundle writes to disk. Keeping the umlauts is the
     * point of it; everything else it does is about staying a name a file system accepts.
     */
    public function testFileNameKeepsOriginalCharacters(): void
    {
        $this->assertSame('Verzicht_Müller_Jürgen', $this->slugger->fileName('Verzicht Müller Jürgen'));
        $this->assertSame('Отдел_кадров', $this->slugger->fileName('Отдел кадров'));
        $this->assertSame('人事部_2026', $this->slugger->fileName('人事部 2026'));
    }

    /**
     * A decomposed umlaut (NFD, as macOS produces) is a letter plus a combining mark. Dropping
     * the mark would silently turn "Müller" into "Muller" – or rather "Mu_ller".
     */
    public function testFileNameKeepsDecomposedUmlauts(): void
    {
        $composed = "M\u{00FC}ller";      // NFC: single "ü"
        $decomposed = "Mu\u{0308}ller";   // NFD: "u" + combining diaeresis

        $this->assertSame($composed, $this->slugger->fileName($composed));
        $this->assertSame($decomposed, $this->slugger->fileName($decomposed));
    }

    /**
     * A file name must fit the file system's byte limit (NAME_MAX is 255 bytes, and the caller
     * still appends ".pdf" plus a possible collision suffix) – and cutting it must never split
     * a multi-byte character, which would leave an unusable, invalid UTF-8 name.
     */
    public function testFileNameStaysWithinTheByteBudget(): void
    {
        $name = $this->slugger->fileName(str_repeat('人', 200));

        $this->assertLessThanOrEqual(180, \strlen($name));
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
        $this->assertSame($name, trim($name, '_-'));
    }

    public function testFileNameCannotEscapeItsDirectory(): void
    {
        foreach (['../etc/passwd', 'a/b\\c', '..', '.'] as $evil) {
            $name = $this->slugger->fileName($evil);

            $this->assertStringNotContainsString('/', $name);
            $this->assertStringNotContainsString('\\', $name);
            $this->assertStringNotContainsString('.', $name);
        }
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
        $this->assertSame('', $this->slugger->ascii(''));
        $this->assertSame('', $this->slugger->token('—'));
        $this->assertSame('', $this->slugger->unicode('/// \\\\'));
        $this->assertSame('', $this->slugger->fileName('!!!'));
    }
}
