<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The single place that turns a free-text name (a workflow title, a column header, a person's
 * name) into a slug — for a `##token##` id, an on-disk file name, or the ASCII fallback of a
 * download header.
 *
 * Before this existed, six separate `preg_replace('/[^A-Za-z0-9…]/', …)` reducers each did
 * their own thing, and all of them shared a transliteration table that knew exactly seven
 * German characters (ä ö ü Ä Ö Ü ß). Every other script — Cyrillic, Greek, CJK, accented
 * Latin — was stripped to nothing, so a non-Latin column name collapsed to an empty slug and
 * collided with every other one, and a non-Latin (or umlaut) title lost characters from its
 * download name.
 *
 * Symfony's locale-aware {@see AsciiSlugger} fixes both at once: with the German locale it
 * expands ä→ae, ü→ue, ß→ss (bit-for-bit what the old table did, so existing tokens keep
 * resolving) and it transliterates any other script generically (Отдел→otdel, 人事部→ren_shi_bu,
 * Θέση→these). A pure value object – no state, safe to share.
 */
class Slugger
{
    private readonly AsciiSlugger $slugger;

    public function __construct()
    {
        // German locale: it only adds the German digraph expansion (ü→ue …); every other
        // script still gets the generic transliteration, so this stays international.
        $this->slugger = new AsciiSlugger('de');
    }

    /**
     * A `##token##` name: lowercase ASCII, words joined by "_". Bit-identical to the former
     * PlaceholderResolver::normalize() for German input – proven against the real column names
     * – so no existing ##data_*## / ##letterhead_*## / ##text_*## reference breaks.
     */
    public function token(string $name): string
    {
        return strtolower($this->ascii($name));
    }

    /**
     * A file-name component: ASCII, case preserved, words joined by $separator. For on-disk
     * names (PDFs, ZIP members) and as the ASCII fallback of a download header, where the
     * bytes must be plain ASCII.
     */
    public function ascii(string $name, string $separator = '_'): string
    {
        return trim((string) $this->slugger->slug($name, $separator), $separator.'-');
    }

    /**
     * A file-name component that KEEPS its Unicode letters and digits (umlauts, Cyrillic, CJK
     * …), replacing only spaces, punctuation and path-unsafe characters with $separator. For
     * the human-facing download name, emitted as an RFC 5987 `filename*=UTF-8''…` header with
     * {@see ascii()} as the ASCII fallback – so a browser downloads "Übungsleiter …" verbatim
     * instead of a stripped or transliterated name.
     */
    public function unicode(string $name, string $separator = '_'): string
    {
        // Keep Unicode letters (\p{L}) and numbers (\p{N}); collapse everything else — spaces,
        // punctuation, control chars, and crucially the path separators / \ — into $separator.
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', $separator, $name) ?? '';

        return trim($slug, $separator.'-');
    }
}
