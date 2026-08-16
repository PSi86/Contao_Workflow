<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Removes everything that belongs to a workflow but is not one of its ctables.
 *
 * Contao's cascade only covers what `tl_workflow.ctable` lists – entries, answer fields and
 * rules. The bookkeeping that hangs off a workflow (import log, send log) and its generated
 * documents on disk are outside that cascade, so somebody has to say so explicitly.
 *
 * That "somebody" used to be each caller, and the two callers disagreed: deleting a workflow in
 * the back end removed the import log and the PDFs but not the send log, and restoring the demo
 * removed none of the three – it deletes its predecessor with plain SQL, well past the
 * config.ondelete callback. Both left rows behind that point at a workflow id nobody can look
 * up any more; the import log's purge cron keeps them capped but never gets rid of them, and a
 * send-log row that never reached a terminal state is kept for good by design.
 *
 * One list, one place: whatever gets added to a workflow next only has to be dropped here.
 */
class WorkflowPurger
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ImportLog $importLog,
        private readonly PdfStorage $pdfStorage,
    ) {
    }

    /**
     * Everything of workflow $workflowId that its ctables do not cover. Safe to call for a
     * workflow that has none of it, and safe to call before the row itself is deleted.
     */
    public function purge(int $workflowId): void
    {
        if ($workflowId < 1) {
            return;
        }

        $this->pdfStorage->deleteWorkflowDir($workflowId);
        $this->importLog->deleteFor($workflowId);

        // The send log outlives single runs on purpose (it is what a late bounce is matched
        // against), but not the workflow it belongs to.
        if ($this->connection->createSchemaManager()->tablesExist(['tl_workflow_send'])) {
            $this->connection->delete('tl_workflow_send', ['workflowId' => $workflowId]);
        }
    }
}
