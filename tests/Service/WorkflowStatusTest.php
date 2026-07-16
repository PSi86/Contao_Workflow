<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;

/**
 * The dashboard's "stuck in the queue" alarm (AP3) must count only mails that have been
 * waiting long enough to be suspicious, and only while still queued — a mail that has since
 * been sent, failed or bounced is no longer stuck.
 */
final class WorkflowStatusTest extends TestCase
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
                state    TEXT NOT NULL DEFAULT '',
                queuedAt INTEGER NOT NULL DEFAULT 0
            )
            SQL);
    }

    public function testCountsOnlyLongQueuedMails(): void
    {
        $this->insert('queued', time() - 1800);   // 30 min: stuck
        $this->insert('queued', time() - 1200);   // 20 min: stuck
        $this->insert('queued', time() - 120);    // 2 min: still within the grace window
        $this->insert('sent', time() - 1800);     // long ago but already delivered
        $this->insert('failed', time() - 1800);   // long ago but already failed

        $status = new WorkflowStatus($this->connection);

        self::assertSame(2, $status->countStuckQueued());
        self::assertSame(3, $status->countStuckQueued(60), 'a smaller threshold widens the window');
    }

    private function insert(string $state, int $queuedAt): void
    {
        $this->connection->insert('tl_workflow_send', ['state' => $state, 'queuedAt' => $queuedAt]);
    }
}
