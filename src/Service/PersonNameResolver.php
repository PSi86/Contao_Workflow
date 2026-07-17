<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

/**
 * Detects the first-name / last-name columns of an entry's data by matching the
 * source-column names against alias lists, so a full participant name can be
 * built even when the columns are not literally called "Vorname"/"Name" (e.g.
 * "Nachname", "Surname", "First name"). Shared by the PDF signature line and the
 * back-end overview so both agree on which columns hold the name.
 *
 * Column names are compared normalized (lower-cased, every non-alphanumeric
 * character stripped), so "First Name", "first_name", "E-Mail" etc. all match.
 */
class PersonNameResolver
{
    public function __construct(private readonly Slugger $slugger)
    {
    }

    /** In priority order; the first matching column of the data wins. */
    public const FIRST_NAME_ALIASES = ['vorname', 'rufname', 'firstname', 'givenname', 'forename', 'christianname', 'prename'];

    /** The generic "name" is the last fallback so a dedicated surname column wins over it. */
    public const LAST_NAME_ALIASES = ['nachname', 'familienname', 'zuname', 'surname', 'lastname', 'familyname', 'name'];

    /**
     * "<first name> <last name>" of an entry's data (either part may be missing).
     *
     * @param array<string, mixed> $data
     */
    public function fullName(array $data): string
    {
        return trim($this->firstName($data).' '.$this->lastName($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function firstName(array $data): string
    {
        return $this->value($data, self::FIRST_NAME_ALIASES);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function lastName(array $data): string
    {
        return $this->value($data, self::LAST_NAME_ALIASES);
    }

    /**
     * The first source column whose normalized name matches one of the aliases
     * (in alias priority order), or null when none matches.
     *
     * @param array<int, string> $keys    available source column names
     * @param array<int, string> $aliases normalized candidate names, most specific first
     */
    public function detectColumn(array $keys, array $aliases): ?string
    {
        $normalized = [];

        foreach ($keys as $key) {
            $normalized[$this->normalize((string) $key)] = (string) $key;
        }

        foreach ($aliases as $alias) {
            if (isset($normalized[$alias])) {
                return $normalized[$alias];
            }
        }

        return null;
    }

    public function normalize(string $value): string
    {
        // Transliterate first (via the shared slugger), THEN strip separators: the aliases are
        // ASCII, so a header must be reduced to ASCII to match. Without the transliteration an
        // umlaut header ("Übungsleiter") lost its first letters ("bungsleiter") and stopped
        // matching; a non-Latin header collapsed to "" and collided with every other.
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($this->slugger->ascii($value), 'UTF-8'));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string>   $aliases
     */
    private function value(array $data, array $aliases): string
    {
        $column = $this->detectColumn(array_keys($data), $aliases);

        return null !== $column ? trim((string) ($data[$column] ?? '')) : '';
    }
}
