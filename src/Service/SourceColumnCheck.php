<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Keeps the source file exchangeable while participants are answering, but only against a
 * file with the identical column set.
 *
 * Swapping in a fresh export is the normal way to add participants or correct data mid-run,
 * so locking the file outright (as the other source settings are locked, see
 * WorkflowLockListener) would be needlessly strict. What must not change are the columns:
 * the e-mail column, the answer storage fields, the rule conditions and the signature fields
 * all address the data by column name, and the answers already on record are filed under
 * those names.
 *
 * One rule for every way a new file arrives: picked in the edit mask
 * (SourceFileGuardListener), uploaded there or in the overview (3.6.2).
 */
class SourceColumnCheck
{
    public function __construct(
        private readonly WorkflowLock $lock,
        private readonly SpreadsheetInspector $inspector,
    ) {
    }

    /**
     * @param string|null $candidatePath absolute path of the new file; null when it cannot be
     *                                   resolved (which counts as "no columns")
     *
     * @throws \RuntimeException when the workflow is locked and the columns differ
     */
    public function assertSameColumns(WorkflowModel $workflow, ?string $candidatePath): void
    {
        if (!$this->lock->isLocked((int) $workflow->id)) {
            return;
        }

        $current = array_values($this->inspector->getHeaders($workflow));

        // No readable columns so far means there is nothing to stay compatible with.
        if ([] === $current) {
            return;
        }

        $new = null === $candidatePath
            ? []
            : array_values($this->inspector->headersOfFile($candidatePath, (string) $workflow->sourceSheet, (int) $workflow->headerRow));

        if ($new === $current) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Die neue Quelldatei hat andere Spalten als die bisherige. Solange Antworten '
            .'vorliegen, kann die Datei nur gegen eine mit exakt denselben Spalten getauscht '
            .'werden – sonst verlieren die bereits erfassten Antworten ihren Bezug. %s '
            .'Entweder die Datei anpassen oder alle Teilnehmer zurücksetzen (Abschnitt '
            .'„Zurücksetzen“ am Ende der Bearbeitungsmaske).',
            $this->describeDifference($current, $new),
        ));
    }

    /**
     * @param array<int, string> $current
     * @param array<int, string> $new
     */
    private function describeDifference(array $current, array $new): string
    {
        if ([] === $new) {
            return 'Aus der neuen Datei konnten keine Spalten gelesen werden.';
        }

        $missing = array_diff($current, $new);
        $added = array_diff($new, $current);
        $parts = [];

        if ([] !== $missing) {
            $parts[] = 'fehlt: „'.implode('", „', $missing).'"';
        }

        if ([] !== $added) {
            $parts[] = 'neu: „'.implode('", „', $added).'"';
        }

        // Same names, so the order must be what differs – the columns are addressed by name,
        // but a reordered file signals a different export and is rejected just as well.
        if ([] === $parts) {
            $parts[] = 'gleiche Spalten, aber in anderer Reihenfolge';
        }

        return ucfirst(implode('; ', $parts)).'.';
    }
}
