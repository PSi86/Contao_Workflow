<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Tells whether a workflow's data-defining settings are frozen because participants have
 * already answered.
 *
 * Once an answer exists, changing the source file, the sheet, the header row, the e-mail
 * column or a question's storage field silently destroys or orphans that answer on the next
 * import — and the answers back documents that were already issued and sent. Those settings
 * are therefore locked for the rest of the run.
 *
 * The lock releases itself: it is derived from the entries, not stored as a flag, so resetting
 * every participant (which voids their answers explicitly) unlocks the workflow again. For the
 * next run the intended path is a copy of the workflow, which starts without a source file and
 * without entries.
 */
class WorkflowLock
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function isLocked(int $workflowId): bool
    {
        return $this->answeredCount($workflowId) > 0;
    }

    /**
     * Participants whose answer is on record. respondedAt is the honest marker: it is written
     * exactly once, when the form is submitted, and cleared only by an explicit reset.
     */
    public function answeredCount(int $workflowId): int
    {
        if ($workflowId < 1) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_workflow_entry WHERE pid = ? AND respondedAt > 0',
            [$workflowId],
        );
    }
}
