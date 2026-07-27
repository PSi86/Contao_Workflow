<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;

/**
 * Keeps the import log (tl_workflow_import) bounded – per workflow, not by age.
 *
 * Age is the wrong yardstick here: a workflow that runs once a year would lose its whole
 * history, while a nightly import would still pile up. What the log is for is explaining the
 * current state, and that reaches back a handful of runs. Keeping the newest KEEP_PER_WORKFLOW
 * of them covers any realistic look-back and cannot grow without bound.
 */
#[AsCronJob('daily')]
class PurgeWorkflowImportLogCron
{
    private const KEEP_PER_WORKFLOW = 100;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(): void
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['tl_workflow_import'])) {
            return;
        }

        foreach ($this->connection->fetchFirstColumn('SELECT DISTINCT pid FROM tl_workflow_import') as $pid) {
            // The id of the oldest row that may stay; everything below it goes. Done per
            // workflow because "the newest N" is meaningless across workflows.
            $cutoff = $this->connection->fetchOne(
                'SELECT id FROM tl_workflow_import WHERE pid = ? ORDER BY id DESC LIMIT 1 OFFSET '.self::KEEP_PER_WORKFLOW,
                [(int) $pid],
            );

            if (false !== $cutoff && null !== $cutoff) {
                $this->connection->executeStatement(
                    'DELETE FROM tl_workflow_import WHERE pid = ? AND id <= ?',
                    [(int) $pid, (int) $cutoff],
                );
            }
        }
    }
}
