<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Contao\CoreBundle\InsertTag\InsertTagParser;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\PlaceholderResolver;

/**
 * fileSlug() turns a workflow title into a download file name. The umlaut case is the point:
 * a plain character-class replace eats it silently, so "EStG Übungsleiter" used to become
 * "EStG_bungsleiter" – a file named after a workflow nobody can find again.
 */
final class PlaceholderResolverFileSlugTest extends TestCase
{
    private PlaceholderResolver $resolver;

    protected function setUp(): void
    {
        // fileSlug() is pure string work; the insert-tag parser is only used by the
        // token-rendering methods.
        $this->resolver = new PlaceholderResolver($this->createMock(InsertTagParser::class));
    }

    /**
     * @dataProvider titles
     */
    public function testFileSlug(string $title, string $expected): void
    {
        $this->assertSame($expected, $this->resolver->fileSlug($title));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function titles(): array
    {
        return [
            // The real titles from the production source.
            'umlaut'            => ['EStG Übungsleiter', 'EStG_Uebungsleiter'],
            'plain'             => ['Verzicht Ehrenamtspauschale', 'Verzicht_Ehrenamtspauschale'],
            'sharp s'           => ['Straßenfest', 'Strassenfest'],
            'all umlauts'       => ['äöü ÄÖÜ ß', 'aeoeue_AeOeUe_ss'],

            // An all-caps acronym gets "Ue", not "UE": the transliteration is a plain
            // character map and cannot know that "ÜLP" is an acronym. Accepted – a file name
            // stays readable, and context-aware casing is not worth the complexity here.
            'acronym'           => ['Übungsleiter ÜLP 2026', 'Uebungsleiter_UeLP_2026'],

            // Capitalisation is kept – unlike normalize(), which lower-cases for ##tokens##.
            'keeps case'        => ['CamelCase Titel', 'CamelCase_Titel'],

            // Separator runs must not pile up.
            'dash with spaces'  => ['Demo - Einverständnis', 'Demo_Einverstaendnis'],
            'punctuation'       => ['Verzicht: Ablehnung (2026)', 'Verzicht_Ablehnung_2026'],
            'slashes'           => ['Trainer / Übungsleiter', 'Trainer_Uebungsleiter'],
            'multiple spaces'   => ['A   B', 'A_B'],
            'trims separators'  => ['  - Titel -  ', 'Titel'],

            // Nothing usable left -> caller falls back to a generic name.
            'only punctuation'  => ['!!!', ''],
            'empty'             => ['', ''],
        ];
    }

    /**
     * A slug must never carry a path separator into a Content-Disposition header.
     */
    public function testStripsPathSeparators(): void
    {
        $this->assertSame('etc_passwd', $this->resolver->fileSlug('../etc/passwd'));
        $this->assertSame('a_b', $this->resolver->fileSlug('a\\b'));
    }

    /**
     * A hyphen inside a word is not a separator run and has to survive – only the runs the
     * replacements produce get collapsed.
     */
    public function testKeepsAWordInternalHyphen(): void
    {
        $this->assertSame('Anti-Aging-Kurs', $this->resolver->fileSlug('Anti-Aging-Kurs'));
    }

    /**
     * The capital umlauts were changed to transliterate as "Ue" (they used to yield "ue",
     * which a case-preserving file name shows off mid-word). normalize() lower-cases
     * afterwards, so its output must be unchanged by that – it is what ##token## matching
     * resolves against, and a shift there would silently break every placeholder.
     */
    public function testNormalizeIsUnaffectedByTheCapitalTransliteration(): void
    {
        $this->assertSame('hoehe_der_uelp', $this->resolver->normalize('Höhe der ÜLP'));
        $this->assertSame('uebungsleiter', $this->resolver->normalize('Übungsleiter'));
        $this->assertSame('strasse', $this->resolver->normalize('Straße'));
        $this->assertSame('aeoeue', $this->resolver->normalize('ÄÖÜ'));
    }
}
