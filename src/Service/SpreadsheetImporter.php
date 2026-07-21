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
 */
class SpreadsheetImporter
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TokenGenerator $tokenGenerator,
        private readonly SpreadsheetInspector $inspector,
        private readonly PlaceholderResolver $placeholderResolver,
        private readonly CellReader $cellReader,
        private readonly ColumnFormatAnalyzer $formatAnalyzer,
        private readonly ColumnCompatibility $columnCompatibility,
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{inserted: int, updated: int, protected: int, total: int, collisions: array<string, array<int, string>>, formatProblems: array<int, string>}
     *
     * @throws \RuntimeException when the source file is missing or has no columns
     */
    public function import(WorkflowModel $workflow): array
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
        $reader = $this->inspector->readerFor($path, $sheetName, false);
        $spreadsheet = $reader->load($path);
        $sheet = ('' !== $sheetName ? $spreadsheet->getSheetByName($sheetName) : null) ?? $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $inserted = 0;
        $updated = 0;
        $protected = 0;
        $seen = [];

        for ($r = $headerRow + 1; $r <= $highestRow; ++$r) {
            $data = [];
            foreach ($headers as $colIndex => $name) {
                $letter = Coordinate::stringFromColumnIndex($colIndex);
                $data[$name] = $this->cellReader->read($sheet->getCell($letter.$r));
            }

            $email = null !== $emailHeader ? ($data[$emailHeader] ?? '') : '';

            // Skip totals/empty rows: a real participant needs an e-mail address.
            if ('' === $email) {
                continue;
            }

            $key = mb_strtolower($email);

            // Guard against duplicate e-mails within the same source file.
            if (isset($seen[$key])) {
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
            }
        }

        $workflow->sourceHash = $hash;
        $workflow->tstamp = time();
        $workflow->save();

        return [
            'inserted'       => $inserted,
            'updated'        => $updated,
            'protected'      => $protected,
            'total'          => \count($existing) + $inserted,
            'collisions'     => $collisions,
            'formatProblems' => $formatProblems,
        ];
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
