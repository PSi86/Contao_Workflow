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
    /** Byte budget of one on-disk file name component, see {@see fileName()}. */
    private const FILE_NAME_MAX_BYTES = 180;

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
     * …), replacing only spaces, punctuation and path-unsafe characters with $separator. Used
     * for every human-facing file name: the download header (RFC 5987 `filename*=UTF-8''…`,
     * with {@see ascii()} as the fallback for old clients) and, via {@see fileName()}, the
     * documents this bundle writes to disk.
     */
    public function unicode(string $name, string $separator = '_'): string
    {
        // Keep Unicode letters (\p{L}), numbers (\p{N}) and combining marks (\p{M}); collapse
        // everything else — spaces, punctuation, control chars, and crucially the path
        // separators / \ — into $separator.
        //
        // \p{M} matters for text that arrives decomposed (NFD), as it does from macOS: there
        // an "ü" is "u" plus a combining diaeresis, and without \p{M} the mark would be
        // dropped and the name silently turn into "u_".
        $slug = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', $separator, $name) ?? '';

        return trim($slug, $separator.'-');
    }

    /**
     * A name for a file this bundle writes to disk – {@see unicode()}, but bounded so it can
     * actually be created.
     *
     * Two limits, because they are not the same one: $maxChars keeps the name readable, while
     * the byte budget is what the file system enforces (NAME_MAX is 255 *bytes* on Linux, and
     * one CJK character costs three of them). Cutting is always done on character boundaries –
     * a byte-wise cut would split a multi-byte character and leave an invalid UTF-8 name.
     *
     * The budget stops well short of 255 because the caller appends to this: ".pdf" and, on a
     * name collision, "_" plus up to 32 hex characters (see PdfStorage::uniqueName()).
     */
    public function fileName(string $name, int $maxChars = 120): string
    {
        $slug = mb_substr($this->unicode($name), 0, $maxChars);

        while ('' !== $slug && \strlen($slug) > self::FILE_NAME_MAX_BYTES) {
            $slug = mb_substr($slug, 0, mb_strlen($slug) - 1);
        }

        return trim($slug, '_-');
    }
}
