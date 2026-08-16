<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * What is known about a workflow's source file right now, and how it relates to the last
 * import that completed.
 *
 * This exists because of a failure mode that looked like a cache and was not one: a corrected
 * export is uploaded, but under a name that differs from the configured file even slightly
 * ("Basistabelle 2026.xlsx" next to "basistabelle-2026.xlsx" – Contao's file-name sanitisation
 * neither lower-cases nor replaces spaces, so it lands beside the old file instead of
 * replacing it). The workflow keeps reading the untouched original, every import reports
 * success, and nothing anywhere contradicts the assumption that the new data is in.
 *
 * The two facts that break that silence are the ones assembled here: **is this file still the
 * one the last import read**, and **is its content still the same**. Both come from the import
 * log, which records path and checksum per run.
 */
class SourceFileStatus
{
    /** No source file configured yet. */
    public const STATE_NONE = 'none';

    /** Configured, but the file cannot be resolved (deleted, moved, permissions). */
    public const STATE_MISSING = 'missing';

    /** The file is there, but no import has ever completed for this workflow. */
    public const STATE_NEVER_IMPORTED = 'never';

    /** The file differs from what the last import read. */
    public const STATE_CHANGED = 'changed';

    /** The file is byte for byte what the last import read. */
    public const STATE_CURRENT = 'current';

    public function __construct(
        private readonly SpreadsheetInspector $inspector,
        private readonly ImportLog $log,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{
     *     state: string,
     *     path: string,
     *     size: int,
     *     modified: int,
     *     hash: string,
     *     lastImportAt: int,
     *     lastImportPath: string,
     *     lastImportHash: string,
     *     otherFile: bool
     * } path/lastImportPath are project-relative; hashes are empty when unknown
     */
    public function describe(WorkflowModel $workflow): array
    {
        $empty = [
            'state' => self::STATE_NONE, 'path' => '', 'size' => 0, 'modified' => 0, 'hash' => '',
            'lastImportAt' => 0, 'lastImportPath' => '', 'lastImportHash' => '', 'otherFile' => false,
        ];

        if (!$workflow->sourceFile) {
            return $empty;
        }

        $path = $this->inspector->resolvePath($workflow);
        $last = $this->log->lastSuccessful((int) $workflow->id);

        $lastPath = null !== $last ? $this->relative((string) ($last['sourceFile'] ?? '')) : '';
        $lastHash = null !== $last ? (string) ($last['sourceHash'] ?? '') : '';
        $lastAt = null !== $last ? (int) ($last['tstamp'] ?? 0) : 0;

        if (null === $path) {
            return array_merge($empty, [
                'state'          => self::STATE_MISSING,
                'lastImportAt'   => $lastAt,
                'lastImportPath' => $lastPath,
                'lastImportHash' => $lastHash,
            ]);
        }

        $hash = $this->inspector->fileHash($path);
        $relative = $this->relative($path);

        // Without a log row, tl_workflow.sourceHash still tells whether an import ever ran –
        // the log may have been purged, or the run predates the log (< 3.2.0).
        $reference = '' !== $lastHash ? $lastHash : (string) $workflow->sourceHash;

        if ('' === $reference) {
            $state = self::STATE_NEVER_IMPORTED;
        } else {
            $state = $hash === $reference ? self::STATE_CURRENT : self::STATE_CHANGED;
        }

        return [
            'state'          => $state,
            'path'           => $relative,
            'size'           => (int) @filesize($path),
            'modified'       => (int) @filemtime($path),
            'hash'           => $hash,
            'lastImportAt'   => $lastAt,
            'lastImportPath' => $lastPath,
            'lastImportHash' => $lastHash,
            // The decisive hint for the "uploaded next to it" mistake: the run that produced
            // the current data read a DIFFERENT file than the one selected now.
            'otherFile'      => '' !== $lastPath && $lastPath !== $relative,
        ];
    }

    /**
     * Project-relative spelling of a stored path. The log records absolute paths (they are the
     * literal thing that was read); showing the server's directory layout in the back end adds
     * noise and, on a shared host, information nobody needs.
     */
    public function relative(string $path): string
    {
        $prefix = rtrim(str_replace('\\', '/', $this->projectDir), '/').'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;
    }
}
