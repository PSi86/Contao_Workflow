<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Generic status overview for a workflow.
 *
 * The status is a plain integer that is incremented by one on every step.
 * A workflow only needs to know the ordered list of step labels and how many
 * there are; "answered" means the entry reached the final step index.
 */
class WorkflowStatus
{
    // Well-known status values used across the bundle.
    public const STATUS_IMPORTED = 0;
    public const STATUS_INVITED = 1;
    public const STATUS_RESPONDED = 2;

    /**
     * Labels for the three status values, indexed by the value itself. The single source for
     * them — the model fallback, the demo seeder and the config importer all read it here.
     *
     * These are not configurable per workflow: the status values are fixed, so a per-workflow
     * list could only relabel them, while its length or order silently redefined what the
     * numbers mean. tl_workflow.steps still exists for stored data and the config format.
     */
    public const DEFAULT_STEPS = ['Importiert', 'Eingeladen', 'Beantwortet'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Number of entries per status value for the given workflow.
     *
     * @return array<int, int> status => count
     */
    public function countByStatus(int $workflowId): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT status, COUNT(*) AS cnt FROM tl_workflow_entry WHERE pid = ? GROUP BY status',
            [$workflowId],
        );

        $counts = [];
        foreach ($rows as $status => $count) {
            $counts[(int) $status] = (int) $count;
        }

        return $counts;
    }

    /**
     * Every headline figure of one workflow, derived from a single GROUP BY query.
     *
     * The overview used to ask for them one by one – countByStatus, countCompleted, countOpen
     * (which is countTotal + countCompleted again) and getBreakdown (countByStatus again) –
     * six round trips per row for numbers that all come out of the same tally. The individual
     * methods stay: they are the readable way to ask a single question, and nothing else in
     * the bundle needs more than one at a time.
     *
     * @return array{byStatus: array<int, int>, total: int, completed: int, open: int, breakdown: array<int, array{index: int, label: string, count: int}>}
     */
    public function summary(WorkflowModel $workflow): array
    {
        $byStatus = $this->countByStatus((int) $workflow->id);
        $final = $workflow->getFinalStatus();

        $total = array_sum($byStatus);
        $completed = 0;

        foreach ($byStatus as $status => $count) {
            if ($status >= $final) {
                $completed += $count;
            }
        }

        $breakdown = [];

        foreach ($workflow->getSteps() as $index => $label) {
            $breakdown[] = ['index' => $index, 'label' => $label, 'count' => $byStatus[$index] ?? 0];
        }

        return [
            'byStatus'  => $byStatus,
            'total'     => $total,
            'completed' => $completed,
            'open'      => $total - $completed,
            'breakdown' => $breakdown,
        ];
    }

    public function countTotal(int $workflowId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_workflow_entry WHERE pid = ?',
            [$workflowId],
        );
    }

    /**
     * Entries that reached the final step (answered).
     */
    public function countCompleted(WorkflowModel $workflow): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_workflow_entry WHERE pid = ? AND status >= ?',
            [(int) $workflow->id, $workflow->getFinalStatus()],
        );
    }

    /**
     * Entries that have not reached the final step yet (still open).
     */
    public function countOpen(WorkflowModel $workflow): int
    {
        return $this->countTotal((int) $workflow->id) - $this->countCompleted($workflow);
    }

    /**
     * Status breakdown labelled with the workflow's step names.
     *
     * @return array<int, array{index: int, label: string, count: int}>
     */
    public function getBreakdown(WorkflowModel $workflow): array
    {
        $steps = $workflow->getSteps();
        $counts = $this->countByStatus((int) $workflow->id);

        $breakdown = [];
        foreach ($steps as $index => $label) {
            $breakdown[] = [
                'index' => $index,
                'label' => $label,
                'count' => $counts[$index] ?? 0,
            ];
        }

        return $breakdown;
    }

    public function getStepLabel(WorkflowModel $workflow, int $status): string
    {
        $steps = $workflow->getSteps();

        return $steps[$status] ?? (string) $status;
    }

    /**
     * Number of mails still sitting in the queue (never picked up by a worker) for longer
     * than the given age. A non-zero count almost always means the cron/worker is not
     * running — the queued mails will neither go out nor turn into a send error on their own.
     */
    public function countStuckQueued(int $olderThanSeconds = 900): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_workflow_send WHERE state = 'queued' AND queuedAt > 0 AND queuedAt < ?",
            [time() - $olderThanSeconds],
        );
    }

    /**
     * Entries with a confirmed hard bounce (invalid address). Shown in their own dashboard
     * box, separate from retryable transport errors, and excluded from further mail runs.
     *
     * @return array<int, array{email: string, info: string}>
     */
    public function getHardBounces(int $workflowId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT email, bounceInfo FROM tl_workflow_entry WHERE pid = ? AND bounceHard = '1' ORDER BY email",
            [$workflowId],
        );

        return array_map(
            static fn (array $row): array => [
                'email' => (string) $row['email'],
                'info'  => (string) $row['bounceInfo'],
            ],
            $rows,
        );
    }

    /**
     * Entries whose last mail send failed (any status), so the back end can flag them.
     *
     * @return array<int, array{email: string, error: string, at: int}>
     */
    public function getSendErrors(int $workflowId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT email, sendError, sendErrorAt FROM tl_workflow_entry "
            ."WHERE pid = ? AND sendError IS NOT NULL AND sendError != '' ORDER BY sendErrorAt DESC",
            [$workflowId],
        );

        return array_map(
            static fn (array $row): array => [
                'email' => (string) $row['email'],
                'error' => (string) $row['sendError'],
                'at'    => (int) $row['sendErrorAt'],
            ],
            $rows,
        );
    }
}
