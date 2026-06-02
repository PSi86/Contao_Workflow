<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Service;

use Doctrine\DBAL\Connection;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;

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
            'SELECT status, COUNT(*) AS cnt FROM tl_trainer_entry WHERE pid = ? GROUP BY status',
            [$workflowId],
        );

        $counts = [];
        foreach ($rows as $status => $count) {
            $counts[(int) $status] = (int) $count;
        }

        return $counts;
    }

    public function countTotal(int $workflowId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_trainer_entry WHERE pid = ?',
            [$workflowId],
        );
    }

    /**
     * Entries that reached the final step (answered).
     */
    public function countCompleted(WorkflowModel $workflow): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_trainer_entry WHERE pid = ? AND status >= ?',
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
}
