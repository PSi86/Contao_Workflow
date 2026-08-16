<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Excel\CellReader;
use Psimandl\WorkflowBundle\Excel\ColumnCompatibility;
use Psimandl\WorkflowBundle\Excel\ColumnFormatAnalyzer;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Imports the configured sheet of a workflow's source file into tl_workflow_entry.
 *
 * The import is idempotent: rows are matched to existing entries by e-mail and UPDATED in
 * place (never duplicated). Entries that have already answered are left alone entirely —
 * their data backs an already issued PDF. Clearing respondedAt (a manual status reset)
 * releases such an entry for a full re-import.
 *
 * It always runs, even when the source file is unchanged: re-importing is how the original
 * source values are restored after a reset. The file checksum is still recorded, but only to
 * drive the "source changed, re-import needed" hint (see WorkflowValidator::isReimportNeeded).
 *
 * Hidden rows are skipped: hiding rows in the source file is how a run is narrowed down to
 * the people it is meant for. What that means for rows imported earlier is the run's mode
 * (see MODE_ADD / MODE_ABSOLUTE).
 */
class SpreadsheetImporter
{
    /**
     * Add and update, delete nothing. Entries whose row is now hidden or gone from the file
     * keep existing (and keep being mailed) – they are only counted and reported.
     */
    public const MODE_ADD = 'add';

    /**
     * The file decides who takes part: entries this run did not see – hidden row or row gone
     * – are deleted afterwards, together with their generated PDF. Entries that ARE in the
     * file and have already answered stay frozen either way; their data is what an issued
     * document was built from.
     */
    public const MODE_ABSOLUTE = 'absolute';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TokenGenerator $tokenGenerator,
        private readonly SpreadsheetInspector $inspector,
        private readonly PlaceholderResolver $placeholderResolver,
        private readonly CellReader $cellReader,
        private readonly ColumnFormatAnalyzer $formatAnalyzer,
        private readonly ColumnCompatibility $columnCompatibility,
        private readonly PdfStorage $pdfStorage,
        private readonly ImportLog $log,
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Runs an import and records it in the import log – both outcomes.
     *
     * The log is written here rather than in the callers so every route into an import (back
     * end, console) is covered by construction, and so a run that fails halfway is recorded
     * as well: it may have written entries before it stopped, and "nothing happened, and
     * why" is what one looks for afterwards.
     *
     * @param string $mode self::MODE_ADD or self::MODE_ABSOLUTE
     *
     * @return array{inserted: int, updated: int, protected: int, total: int, collisions: array<string, array<int, string>>, formatProblems: array<int, string>, formulaProblems: array<int, string>, hidden: int, hiddenKnown: int, duplicates: int, missing: int, removed: int, removedAnswered: int, sharedRows: int}
     *
     * @throws \RuntimeException when the source file is missing or has no columns
     */
    public function import(WorkflowModel $workflow, string $mode = self::MODE_ADD): array
    {
        // Resolved before the run, so a failure over an unreadable file is still logged
        // against the file it was about.
        $sourceFile = $this->inspector->resolvePath($workflow) ?? '';

        try {
            $result = $this->run($workflow, $mode);
        } catch (\Throwable $exception) {
            $this->log->recordFailure($workflow, $mode, $exception, $sourceFile);

            throw $exception;
        } finally {
            // The parsed workbook is the largest object in the process; a console run over
            // several workflows must not carry one file into the next.
            $this->inspector->releaseSheet();
        }

        $this->log->recordSuccess($workflow, $mode, $result, $sourceFile);

        return $result;
    }

    /**
     * The run itself. Everything it reports travels in the returned array – import() turns
     * that into the log entry.
     *
     * @param string $mode self::MODE_ADD or self::MODE_ABSOLUTE
     *
     * @return array{inserted: int, updated: int, protected: int, total: int, collisions: array<string, array<int, string>>, formatProblems: array<int, string>, formulaProblems: array<int, string>, hidden: int, hiddenKnown: int, duplicates: int, missing: int, removed: int, removedAnswered: int, sharedRows: int}
     *
     * @throws \RuntimeException when the source file is missing or has no columns
     */
    private function run(WorkflowModel $workflow, string $mode): array
    {
        $this->framework->initialize();

        // Checked before the columns: a sheet that is not in the file yields no columns
        // either, and "no columns" would send the user looking in the wrong place. This is
        // the case after the source file was overwritten in place with an export whose sheet
        // is named differently — the setting still points at the old name.
        $this->assertSheetExists($workflow);

        $headers = $this->inspector->getHeaders($workflow);

        if ([] === $headers) {
            throw new \RuntimeException('Es konnten keine Spalten aus der Quelldatei gelesen werden.');
        }

        // Columns whose names normalize to the same placeholder slug: only the
        // first is reachable via ##data_<slug>##, the rest are reported so the
        // user can disambiguate them in the source file.
        $collisions = $this->placeholderResolver->slugCollisions($headers);

        // The number columns are re-read here, not when the back end field is saved. The
        // format belongs to the DATA, so it has to be refreshed whenever the data enters the
        // system – otherwise overwriting the source file in place behaves differently from
        // picking a newly named file (the latter saves the workflow and thereby happened to
        // refresh the snapshot), and a workflow whose settings are locked never got a
        // refreshed format at all.
        $formatProblems = $this->refreshNumberFormats($workflow, $headers);

        $path = $this->resolveSourcePath($workflow);
        $hash = (string) md5_file($path);

        $existing = $this->indexExistingByEmail((int) $workflow->id);

        $emailHeader = $this->resolveEmailHeader($workflow, $headers);
        $finalStatus = $workflow->getFinalStatus();

        $sheetName = (string) $workflow->sourceSheet;
        $headerRow = max(1, (int) $workflow->headerRow);

        // Not read-data-only: the number formats are needed (see CellReader). Cannot be null
        // here – assertSheetExists() has already ruled out the only case that returns null.
        // The format refresh above parsed the same file with the same flags, so this shares
        // that parse instead of repeating it.
        $sheet = $this->inspector->loadSheet($path, $sheetName, false);

        if (null === $sheet) {
            throw new \RuntimeException(sprintf('Das Tabellenblatt „%s" ist in der Quelldatei nicht enthalten.', $sheetName));
        }

        $highestRow = $sheet->getHighestDataRow();
        $inserted = 0;
        $updated = 0;
        $protected = 0;
        $hidden = 0;
        $hiddenKnown = 0;
        $duplicates = 0;
        $seen = [];

        // Column letter of the e-mail, needed to look at a hidden row without reading the
        // whole row: the address is all that is asked of it.
        $emailIndex = null !== $emailHeader ? array_search($emailHeader, $headers, true) : false;
        $emailLetter = false === $emailIndex ? null : Coordinate::stringFromColumnIndex((int) $emailIndex);

        /** @var array<string, array<string, array<int, int>>> $formulaIssues column => problem => rows */
        $formulaIssues = [];

        for ($r = $headerRow + 1; $r <= $highestRow; ++$r) {
            // A hidden row is not part of this run. It is counted (and, when it was imported
            // before, counted separately) so the result can say what was left out instead of
            // quietly importing fewer people than the file has rows.
            if ($this->inspector->isRowHidden($sheet, $r)) {
                $hiddenEmail = null !== $emailLetter
                    ? $this->cellReader->read($sheet->getCell($emailLetter.$r))
                    : '';

                // A hidden totals or spacer row is not worth mentioning.
                if ('' !== $hiddenEmail) {
                    ++$hidden;

                    if (isset($existing[mb_strtolower($hiddenEmail)])) {
                        ++$hiddenKnown;
                    }
                }

                continue;
            }

            $data = [];
            $rowIssues = [];

            foreach ($headers as $colIndex => $name) {
                $letter = Coordinate::stringFromColumnIndex($colIndex);
                $cell = $sheet->getCell($letter.$r);
                $data[$name] = $this->cellReader->read($cell);

                // Formulas are not evaluated (see CellReader): a cell whose result the file
                // does not carry imports as empty. Collected per row and only kept for rows
                // that are actually stored, so a totals row or an already answered
                // participant does not produce a warning about data nobody imported.
                if (null !== $problem = $this->cellReader->formulaProblem($cell)) {
                    $rowIssues[$name] = $problem;
                }
            }

            $email = null !== $emailHeader ? ($data[$emailHeader] ?? '') : '';

            // Skip totals/empty rows: a real participant needs an e-mail address.
            if ('' === $email) {
                continue;
            }

            $key = mb_strtolower($email);

            // Guard against duplicate e-mails within the same source file. Only the first
            // row of such a pair becomes an entry – the address is the identity here, so
            // the second one cannot be told apart from it. Counted, because silently
            // dropping a row of the file is exactly the kind of thing nobody notices.
            if (isset($seen[$key])) {
                ++$duplicates;

                continue;
            }
            $seen[$key] = true;

            if (isset($existing[$key])) {
                $entry = $existing[$key];

                // An answered entry is frozen: its data is what the already issued PDF was
                // built from, so a later source-file edit must not rewrite it. The row number
                // still follows the current file, otherwise the export order drifts apart.
                // respondedAt is the honest marker here — unlike the status it cannot be
                // invalidated by editing the workflow's step list. The status is kept as a
                // fallback for entries predating respondedAt.
                if ((int) $entry->respondedAt > 0 || ($finalStatus > 0 && (int) $entry->status >= $finalStatus)) {
                    $entry->sourceRow = $r;
                    $entry->save();
                    ++$protected;

                    continue;
                }

                $entry->email = $email;
                $entry->data = serialize($data);
                // Re-imported rows follow the new file, so the row number is refreshed
                // even for entries that already existed.
                $entry->sourceRow = $r;
                $entry->tstamp = time();
                $entry->save();
                ++$updated;

                $this->collectIssues($formulaIssues, $rowIssues, $r);
            } else {
                $entry = new EntryModel();
                $entry->pid = (int) $workflow->id;
                $entry->tstamp = time();
                $entry->token = $this->tokenGenerator->generate();
                $entry->status = WorkflowStatus::STATUS_IMPORTED;
                $entry->email = $email;
                $entry->data = serialize($data);
                $entry->sourceRow = $r;
                $entry->save();
                ++$inserted;

                $this->collectIssues($formulaIssues, $rowIssues, $r);
            }
        }

        // Entries this run never met: their row is hidden, or it is gone from the file (an
        // address changed in the source counts as gone – the address is the identity, so a
        // changed one reads as a different person).
        $untouched = array_diff_key($existing, $seen);
        $removed = 0;
        $removedAnswered = 0;

        if (self::MODE_ABSOLUTE === $mode) {
            foreach ($untouched as $entry) {
                if ((int) $entry->respondedAt > 0 || ($finalStatus > 0 && (int) $entry->status >= $finalStatus)) {
                    ++$removedAnswered;
                }

                // The document belongs to the entry; leaving it behind would keep it in the
                // PDF bundle of a workflow whose participant no longer exists.
                $this->pdfStorage->deleteFile((string) $entry->pdfPath);
                $entry->delete();
                ++$removed;
            }
        }

        $workflow->sourceHash = $hash;
        $workflow->tstamp = time();
        $workflow->save();

        return [
            'inserted'        => $inserted,
            'updated'         => $updated,
            'protected'       => $protected,
            'total'           => \count($existing) + $inserted - $removed,
            'collisions'      => $collisions,
            'formatProblems'  => $formatProblems,
            'formulaProblems' => $this->describeFormulaIssues($formulaIssues),
            'hidden'          => $hidden,
            'hiddenKnown'     => $hiddenKnown,
            'duplicates'      => $duplicates,
            'missing'         => \count($untouched),
            'removed'         => $removed,
            'removedAnswered' => $removedAnswered,
            'sharedRows'      => $this->countSharedRows((int) $workflow->id),
        ];
    }

    /**
     * How many source rows are claimed by more than one entry.
     *
     * The export order is the stored row number (tl_workflow_entry.sourceRow), refreshed on
     * every row a run touches. An entry the run did not touch keeps the number of the run
     * that last saw it, and a new participant can meanwhile have moved into that row – then
     * two entries sort to the same position and their order is decided by age alone. That is
     * not wrong data, but it is the one way the export order can stop mirroring the file, so
     * it is reported rather than left to be discovered.
     */
    private function countSharedRows(int $workflowId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT sourceRow FROM tl_workflow_entry '
            .'WHERE pid = ? AND sourceRow > 0 GROUP BY sourceRow HAVING COUNT(*) > 1) shared',
            [$workflowId],
        );
    }

    /**
     * @param array<string, array<string, array<int, int>>> $issues    column => problem => rows
     * @param array<string, string>                         $rowIssues column => problem
     */
    private function collectIssues(array &$issues, array $rowIssues, int $row): void
    {
        foreach ($rowIssues as $column => $problem) {
            $issues[$column][$problem][] = $row;
        }
    }

    /**
     * One sentence per column and kind of problem, naming a few rows – a column with 300
     * broken formulas must not produce 300 messages.
     *
     * @param array<string, array<string, array<int, int>>> $issues column => problem => rows
     *
     * @return array<int, string>
     */
    private function describeFormulaIssues(array $issues): array
    {
        $messages = [];

        foreach ($issues as $column => $problems) {
            foreach ($problems as $problem => $rows) {
                $shown = \array_slice($rows, 0, 3);
                $rest = \count($rows) - \count($shown);

                $messages[] = sprintf(
                    'Spalte „%s": Formel ohne verwertbares Ergebnis (%s) in Zeile %s%s – %s leer importiert.',
                    $column,
                    $problem,
                    implode(', ', array_map('strval', $shown)),
                    $rest > 0 ? sprintf(' und %d weiteren', $rest) : '',
                    1 === \count($rows) ? 'das Feld wurde' : 'die Felder wurden',
                );
            }
        }

        return $messages;
    }


    /**
     * Re-reads the Excel format of every "number" question's column and stores it on the
     * question, so form, live preview, PDF and export all render the value the same way.
     *
     * Deliberately here and not only in the back end: the format describes the DATA, so it has
     * to follow the data. Doing it only in the field's save callback made the result depend on
     * whether someone happened to save the workflow — and once the source settings are locked
     * (answers exist), that callback cannot run at all.
     *
     * A column that cannot back a number field keeps its previous format and is reported; the
     * import itself must not fail over a formatting question, the participants' data is the
     * point of it.
     *
     * @param array<int, string> $headers
     *
     * @return array<int, string> problems, one per unusable column
     */
    private function refreshNumberFormats(WorkflowModel $workflow, array $headers): array
    {
        $problems = [];

        foreach ($workflow->getQuestions() as $question) {
            if (!$question->isNumber()) {
                continue;
            }

            $column = trim((string) $question->storageField);

            if ('' === $column || !\in_array($column, $headers, true)) {
                continue;
            }

            $result = $this->columnCompatibility->checkNumberColumn(
                $column,
                $this->formatAnalyzer->analyze($workflow, $column),
                $question->getNumberDecimals(),
            );

            if (!$result->isCompatible() || null === $result->format) {
                $problems[] = sprintf('Feld „%s": %s', (string) $question->label, implode(' ', $result->problems));

                continue;
            }

            $this->connection->update(
                'tl_workflow_question',
                ['numberFormat' => json_encode($result->format->toArray(), JSON_THROW_ON_ERROR)],
                ['id' => (int) $question->id],
            );
        }

        return $problems;
    }

    /**
     * Refuses the import when the configured sheet is not in the file, naming the sheets that
     * are — otherwise the user only learns that no columns could be read.
     */
    private function assertSheetExists(WorkflowModel $workflow): void
    {
        $sheetName = trim((string) $workflow->sourceSheet);

        if ('' === $sheetName) {
            return;
        }

        $available = $this->inspector->getSheetNames($workflow);

        if ([] === $available || \in_array($sheetName, $available, true)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Das eingestellte Tabellenblatt „%s" gibt es in der Quelldatei nicht. Vorhanden ist: '
            .'„%s". Bitte das Tabellenblatt in den Workflow-Einstellungen anpassen.',
            $sheetName,
            implode(', ', $available),
        ));
    }

    /**
     * @return array<string, EntryModel> lower-cased e-mail => entry
     */
    private function indexExistingByEmail(int $workflowId): array
    {
        $entries = $this->framework->getAdapter(EntryModel::class)->findBy('pid', $workflowId);
        $map = [];

        if (null !== $entries) {
            foreach ($entries as $entry) {
                $key = mb_strtolower(trim((string) $entry->email));
                if ('' !== $key && !isset($map[$key])) {
                    $map[$key] = $entry;
                }
            }
        }

        return $map;
    }

    private function resolveSourcePath(WorkflowModel $workflow): string
    {
        if (!$workflow->sourceFile) {
            throw new \RuntimeException('Es ist keine Quelldatei hinterlegt.');
        }

        $file = $this->framework->getAdapter(FilesModel::class)->findByUuid($workflow->sourceFile);

        if (null === $file) {
            throw new \RuntimeException('Die hinterlegte Quelldatei wurde nicht gefunden.');
        }

        $path = $this->projectDir.'/'.$file->path;

        if (!is_file($path)) {
            throw new \RuntimeException('Die Quelldatei existiert nicht: '.$file->path);
        }

        return $path;
    }

    /**
     * @param array<int, string> $headers column index => header name
     */
    private function resolveEmailHeader(WorkflowModel $workflow, array $headers): ?string
    {
        $configured = (string) $workflow->emailField;

        if ('' !== $configured && \in_array($configured, $headers, true)) {
            return $configured;
        }

        foreach ($headers as $name) {
            if (preg_match('/e-?mail/i', $name)) {
                return $name;
            }
        }

        return null;
    }
}
