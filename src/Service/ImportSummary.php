<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

/**
 * Puts the outcome of an import run into words – once, for everyone who reports it.
 *
 * The back end message after a run and the import log entry read the same, because they come
 * from here. That matters beyond tidiness: the log is meant to explain a state weeks later,
 * and it can only do that if it says exactly what the user was told at the time.
 *
 * Pure: it takes the importer's result array and returns strings. The German lives here (the
 * back end is German-first for these messages); the console command speaks its own English.
 */
class ImportSummary
{
    /**
     * The one-line outcome ("3 neu hinzugefügt, 12 aktualisiert …").
     *
     * @param array<string, mixed> $result as returned by SpreadsheetImporter::import()
     */
    public function headline(array $result, string $mode): string
    {
        return sprintf(
            '%d neu hinzugefügt, %d aktualisiert%s%s (gesamt %d).',
            $this->count($result, 'inserted'),
            $this->count($result, 'updated'),
            $this->count($result, 'protected') > 0
                ? sprintf(', %d unverändert (bereits beantwortet)', $this->count($result, 'protected'))
                : '',
            $this->count($result, 'removed') > 0
                ? sprintf(', %d gelöscht', $this->count($result, 'removed'))
                : '',
            $this->count($result, 'total'),
        );
    }

    /**
     * Everything the run left out or cleaned up, one sentence each – empty when a run was
     * plain sailing.
     *
     * All of it used to happen silently: rows skipped because their address already
     * appeared, entries whose row is gone from the file (they stay and keep being mailed),
     * and rows hidden in the source file. The last item is about the export order: an entry
     * a run did not touch keeps its old row number, which a new participant may meanwhile
     * occupy.
     *
     * @param array<string, mixed> $result
     *
     * @return array<int, string>
     */
    public function notes(array $result, string $mode): array
    {
        $notes = [];

        if ($this->count($result, 'hidden') > 0) {
            $notes[] = sprintf(
                '%d ausgeblendete Zeile(n) übersprungen%s.',
                $this->count($result, 'hidden'),
                $this->count($result, 'hiddenKnown') > 0
                    ? sprintf(', davon %d bereits früher importiert', $this->count($result, 'hiddenKnown'))
                    : '',
            );
        }

        if ($this->count($result, 'duplicates') > 0) {
            $notes[] = sprintf(
                '%d Zeile(n) übersprungen, deren E-Mail-Adresse in der Datei mehrfach vorkommt – '
                .'nur die erste wird importiert.',
                $this->count($result, 'duplicates'),
            );
        }

        if (SpreadsheetImporter::MODE_ABSOLUTE === $mode) {
            if ($this->count($result, 'removed') > 0) {
                $notes[] = sprintf(
                    '%d Eintrag/Einträge gelöscht, die in der Quelldatei nicht (mehr) sichtbar vorkommen%s.',
                    $this->count($result, 'removed'),
                    $this->count($result, 'removedAnswered') > 0
                        ? sprintf(', darunter %d bereits beantwortete (inklusive erzeugter PDFs)', $this->count($result, 'removedAnswered'))
                        : '',
                );
            }
        } elseif ($this->count($result, 'missing') > 0) {
            $notes[] = sprintf(
                '%d vorhandene(r) Eintrag/Einträge kommen in der Quelldatei nicht (mehr) sichtbar vor. '
                .'Im Modus „additiv" bleiben sie bestehen und werden weiterhin angeschrieben; der Modus '
                .'„absolut" entfernt sie.',
                $this->count($result, 'missing'),
            );
        }

        if ($this->count($result, 'sharedRows') > 0) {
            $notes[] = sprintf(
                'Achtung: %d Zeilennummer(n) sind doppelt belegt. Das passiert, wenn ein Eintrag nicht '
                .'mehr in der Datei steht und ein neuer Teilnehmer inzwischen seine Zeile einnimmt – im '
                .'Export entscheidet dort das Alter des Eintrags über die Reihenfolge.',
                $this->count($result, 'sharedRows'),
            );
        }

        return $notes;
    }

    /**
     * The problems a run reported: unusable number formats, formulas without a stored
     * result, ambiguous column names. Kept apart from the notes because these are faults of
     * the source file, not decisions of the run.
     *
     * @param array<string, mixed> $result
     *
     * @return array<int, string>
     */
    public function problems(array $result): array
    {
        $problems = [];

        // Verbatim: the importer's formula messages already name what they are about
        // ("Spalte „X": Formel ohne verwertbares Ergebnis …"). A prefix would repeat it.
        foreach ((array) ($result['formulaProblems'] ?? []) as $problem) {
            $problems[] = (string) $problem;
        }

        foreach ((array) ($result['formatProblems'] ?? []) as $problem) {
            $problems[] = 'Zahlenformat nicht übernommen – '.$problem;
        }

        foreach ((array) ($result['collisions'] ?? []) as $slug => $names) {
            $names = array_map('strval', (array) $names);
            $problems[] = sprintf(
                'Mehrdeutiger Platzhalter ##data_%s##: verwendet „%s", ignoriert „%s".',
                (string) $slug,
                $names[0] ?? '',
                implode('", „', \array_slice($names, 1)),
            );
        }

        return $problems;
    }

    /**
     * The counters worth showing in the log, label => value, zeros dropped (except the two
     * that carry the run: added and updated).
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, int>
     */
    public function counts(array $result): array
    {
        $labels = [
            'inserted'   => 'neu',
            'updated'    => 'aktualisiert',
            'protected'  => 'eingefroren',
            'removed'    => 'gelöscht',
            'hidden'     => 'ausgeblendet übersprungen',
            'duplicates' => 'doppelte Adresse übersprungen',
            'missing'    => 'nicht in der Datei',
            'sharedRows' => 'doppelte Zeilennummern',
            'total'      => 'gesamt',
        ];

        $counts = [];

        foreach ($labels as $key => $label) {
            $value = $this->count($result, $key);

            if ($value > 0 || \in_array($key, ['inserted', 'updated', 'total'], true)) {
                $counts[$label] = $value;
            }
        }

        return $counts;
    }

    /**
     * A stored summary may predate a counter (the blob is written as it was), so every read
     * goes through here instead of assuming the key exists.
     *
     * @param array<string, mixed> $result
     */
    private function count(array $result, string $key): int
    {
        return (int) ($result[$key] ?? 0);
    }
}
