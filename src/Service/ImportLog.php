<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\BackendUser;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Writes and reads the import log (tl_workflow_import): one row per run.
 *
 * An import's outcome depends on the runs before it – row numbers follow the file, answered
 * entries are frozen, the mode decides about entries the run did not see. Without a record,
 * a state can only be guessed at backwards from the data, and a mistake that surfaces two
 * runs later cannot be explained at all.
 *
 * Logging never breaks an import: a run that produced entries must not be reported as failed
 * because its bookkeeping row could not be written.
 */
class ImportLog
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ImportSummary $summary,
    ) {
    }

    /**
     * @param array<string, mixed> $result as returned by SpreadsheetImporter::import()
     */
    public function recordSuccess(WorkflowModel $workflow, string $mode, array $result, string $sourceFile): void
    {
        $this->write($workflow, $mode, 'ok', $sourceFile, [
            'summary'  => $this->summaryOf($result),
            'problems' => $this->encode($this->summary->problems($result)),
        ]);
    }

    /**
     * A failed run is logged too: "nothing happened, and why" is exactly what one looks for
     * afterwards – and a run that aborts halfway may still have written entries.
     */
    public function recordFailure(WorkflowModel $workflow, string $mode, \Throwable $exception, string $sourceFile): void
    {
        $this->write($workflow, $mode, 'failed', $sourceFile, [
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * The most recent runs of a workflow, newest first.
     *
     * @return array<int, array<string, mixed>> rows with 'summary'/'problems' decoded
     */
    public function recent(int $workflowId, int $limit = 10): array
    {
        if ($workflowId < 1 || !$this->tableExists()) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_workflow_import WHERE pid = ? ORDER BY tstamp DESC, id DESC LIMIT '.max(1, $limit),
            [$workflowId],
        );

        foreach ($rows as &$row) {
            $row['summary'] = $this->decode((string) ($row['summary'] ?? ''));
            $row['problems'] = $this->decode((string) ($row['problems'] ?? ''));
        }

        return $rows;
    }

    /**
     * How many runs a workflow has on record (for "… und N weitere").
     */
    public function count(int $workflowId): int
    {
        if ($workflowId < 1 || !$this->tableExists()) {
            return 0;
        }

        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_workflow_import WHERE pid = ?', [$workflowId]);
    }

    public function deleteFor(int $workflowId): void
    {
        if ($workflowId > 0 && $this->tableExists()) {
            $this->connection->delete('tl_workflow_import', ['pid' => $workflowId]);
        }
    }

    /**
     * @param array<string, string> $extra
     */
    private function write(WorkflowModel $workflow, string $mode, string $state, string $sourceFile, array $extra): void
    {
        if (!$this->tableExists()) {
            return;
        }

        try {
            $this->connection->insert('tl_workflow_import', array_merge([
                'pid'         => (int) $workflow->id,
                'tstamp'      => time(),
                'mode'        => $mode,
                'state'       => $state,
                'triggeredBy' => $this->currentUser(),
                'sourceFile'  => $sourceFile,
                // Of the file as it is now: two runs against the same name but different
                // content are otherwise indistinguishable in hindsight.
                'sourceHash'  => '' !== $sourceFile && is_file($sourceFile) ? (string) md5_file($sourceFile) : '',
                'sourceSheet' => (string) $workflow->sourceSheet,
            ], $extra));
        } catch (\Throwable) {
            // Bookkeeping must not decide whether an import counts as successful.
        }
    }

    /**
     * The counters of a run, without the message lists (those are stored rendered).
     *
     * @param array<string, mixed> $result
     */
    private function summaryOf(array $result): string
    {
        return $this->encode(array_filter($result, static fn (mixed $value): bool => \is_int($value)));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return '';
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $json): array
    {
        if ('' === $json) {
            return [];
        }

        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($value) ? $value : [];
    }

    /**
     * Back end user name, or "CLI" when no one is logged in (console run, cron).
     */
    private function currentUser(): string
    {
        $user = $this->security->getUser();

        return $user instanceof BackendUser ? (string) $user->username : 'CLI';
    }

    /**
     * The table arrives with the schema update, which runs after the migrations – an import
     * triggered in between must not fail over a missing log table.
     */
    private function tableExists(): bool
    {
        static $exists = null;

        if (null === $exists) {
            $exists = $this->connection->createSchemaManager()->tablesExist(['tl_workflow_import']);
        }

        return $exists;
    }
}
