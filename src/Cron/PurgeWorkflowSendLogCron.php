<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;

/**
 * Keeps the durable send log (tl_workflow_send) from growing without bound.
 *
 * The rows are deliberately append-only during a mail's life so a bounce (DSN) arriving
 * hours to a few days later can still be correlated by parcel id. Once a row has reached a
 * terminal state (sent / failed / bounced) and has aged well past that bounce window, its
 * correlation job is done and it is purged.
 *
 * Rows still in "queued" are never purged here: a queue that never drains is a real problem
 * the dashboard surfaces (AP3), not something to sweep away.
 */
#[AsCronJob('daily')]
class PurgeWorkflowSendLogCron
{
    // 90 days: far beyond the few days an MTA keeps retrying before it emits a final bounce,
    // with comfortable headroom for after-the-fact auditing.
    private const RETENTION_SECONDS = 60 * 60 * 24 * 90;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM tl_workflow_send WHERE state IN ('sent', 'failed', 'bounced') AND tstamp < ?",
            [time() - self::RETENTION_SECONDS],
        );
    }
}
