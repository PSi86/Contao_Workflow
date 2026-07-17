<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Cron;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Cron\PurgeWorkflowSendLogCron;

/**
 * The retention cron must bound the durable send log without erasing anything still useful:
 * only aged terminal rows are purged, recent rows and any "queued" row (a stuck-queue signal)
 * are kept.
 */
final class PurgeWorkflowSendLogCronTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_send (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                parcelId TEXT NOT NULL DEFAULT '',
                state    TEXT NOT NULL DEFAULT '',
                tstamp   INTEGER NOT NULL DEFAULT 0
            )
            SQL);
    }

    public function testPurgesOnlyAgedTerminalRows(): void
    {
        $old = time() - 60 * 60 * 24 * 120; // 120 days ago, past the 90-day retention
        $recent = time() - 60 * 60 * 24 * 10; // 10 days ago, within retention

        $this->insert('old-sent', 'sent', $old);
        $this->insert('old-failed', 'failed', $old);
        $this->insert('old-bounced', 'bounced', $old);
        $this->insert('old-queued', 'queued', $old);
        $this->insert('recent-sent', 'sent', $recent);

        (new PurgeWorkflowSendLogCron($this->connection))();

        $remaining = $this->connection->fetchFirstColumn('SELECT parcelId FROM tl_workflow_send ORDER BY parcelId');

        self::assertSame(['old-queued', 'recent-sent'], $remaining);
    }

    private function insert(string $parcelId, string $state, int $tstamp): void
    {
        $this->connection->insert('tl_workflow_send', [
            'parcelId' => $parcelId,
            'state' => $state,
            'tstamp' => $tstamp,
        ]);
    }
}
