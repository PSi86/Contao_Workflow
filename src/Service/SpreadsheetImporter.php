<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psimandl\TrainerWorkflowBundle\Model\EntryModel;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;

/**
 * Imports the configured sheet of a workflow's source file into tl_trainer_entry.
 *
 * The import is idempotent: rows are matched to existing entries by e-mail and
 * UPDATED in place (never duplicated). Already answered entries keep their
 * response (status, signature and the stored answer columns). A re-import of an
 * unchanged file is skipped (checksum comparison); pass $force to override.
 */
class SpreadsheetImporter
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly TokenGenerator $tokenGenerator,
        private readonly SpreadsheetInspector $inspector,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{skipped: bool, inserted: int, updated: int, total: int}
     *
     * @throws \RuntimeException when the source file is missing or has no columns
     */
    public function import(WorkflowModel $workflow, bool $force = false): array
    {
        $this->framework->initialize();

        $headers = $this->inspector->getHeaders($workflow);

        if ([] === $headers) {
            throw new \RuntimeException('Es konnten keine Spalten aus der Quelldatei gelesen werden.');
        }

        $path = $this->resolveSourcePath($workflow);
        $hash = (string) md5_file($path);

        $existing = $this->indexExistingByEmail((int) $workflow->id);

        // Skip a redundant re-import of an unchanged file (but always run the
        // very first import, even if a stale hash happens to match).
        if (!$force && [] !== $existing && $hash === (string) $workflow->sourceHash) {
            return ['skipped' => true, 'inserted' => 0, 'updated' => 0, 'total' => \count($existing)];
        }

        $emailHeader = $this->resolveEmailHeader($workflow, $headers);
        $finalStatus = $workflow->getFinalStatus();
        $outputColumns = $workflow->getStorageFields();

        $sheetName = (string) $workflow->sourceSheet;
        $headerRow = max(1, (int) $workflow->headerRow);

        $reader = IOFactory::createReaderForFile($path);
        if ('' !== $sheetName) {
            $reader->setLoadSheetsOnly([$sheetName]);
        }
        $spreadsheet = $reader->load($path);
        $sheet = ('' !== $sheetName ? $spreadsheet->getSheetByName($sheetName) : null) ?? $spreadsheet->getActiveSheet();

        $highestRow = $sheet->getHighestDataRow();
        $inserted = 0;
        $updated = 0;
        $seen = [];

        for ($r = $headerRow + 1; $r <= $highestRow; ++$r) {
            $data = [];
            foreach ($headers as $colIndex => $name) {
                $letter = Coordinate::stringFromColumnIndex($colIndex);
                $data[$name] = trim((string) $sheet->getCell($letter.$r)->getFormattedValue());
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

                // Preserve response-derived output columns of answered entries.
                if ((int) $entry->status >= $finalStatus && $finalStatus > 0) {
                    $old = $entry->getData();
                    foreach ($outputColumns as $col) {
                        if (isset($old[$col])) {
                            $data[$col] = $old[$col];
                        }
                    }
                }

                $entry->email = $email;
                $entry->data = serialize($data);
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
                $entry->save();
                ++$inserted;
            }
        }

        $workflow->sourceHash = $hash;
        $workflow->tstamp = time();
        $workflow->save();

        return [
            'skipped'  => false,
            'inserted' => $inserted,
            'updated'  => $updated,
            'total'    => \count($existing) + $inserted,
        ];
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
