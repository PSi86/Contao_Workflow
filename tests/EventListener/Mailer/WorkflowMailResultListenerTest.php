<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\EventListener\Mailer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\EventListener\Mailer\WorkflowMailResultListener;
use Psimandl\WorkflowBundle\Service\WorkflowMailContext;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;
use Terminal42\NotificationCenterBundle\Event\AsynchronousReceiptEvent;
use Terminal42\NotificationCenterBundle\Receipt\AsynchronousReceipt;

/**
 * Cover for the durable send log (AP2) and the correlation regression it grew out of (AP1):
 *
 *  - A failed send keeps its tl_workflow_send row (state "failed"), so a Symfony Messenger
 *    retry that reuses the same parcel id is still mapped back to the entry when the
 *    SentMessageEvent finally arrives — instead of the entry being stranded at "imported"
 *    with a send error and re-invited on the next run.
 *  - A delivered invitation moves the row to "sent" and advances the entry to "invited";
 *    the row is never deleted, so a later bounce can be correlated to it by parcel id.
 *
 * These tests exercise the asynchronous path (the production path: the row already exists
 * when the receipt arrives), which uses portable SQL and therefore runs against in-memory
 * SQLite. The synchronous fallback and the dispatcher upsert use MariaDB-specific
 * "ON DUPLICATE KEY UPDATE" and are verified end-to-end against the real database.
 */
final class WorkflowMailResultListenerTest extends TestCase
{
    private const PARCEL_ID = 'ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00';

    private Connection $connection;

    private WorkflowMailContext $context;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_entry (
                id          INTEGER PRIMARY KEY,
                status      INTEGER NOT NULL DEFAULT 0,
                sendError   TEXT    NOT NULL DEFAULT '',
                sendErrorAt INTEGER NOT NULL DEFAULT 0,
                sentAt      INTEGER NOT NULL DEFAULT 0,
                tstamp      INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_send (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                tstamp     INTEGER NOT NULL DEFAULT 0,
                parcelId   TEXT    NOT NULL DEFAULT '',
                entryId    INTEGER NOT NULL DEFAULT 0,
                workflowId INTEGER NOT NULL DEFAULT 0,
                kind       TEXT    NOT NULL DEFAULT '',
                recipient  TEXT    NOT NULL DEFAULT '',
                state      TEXT    NOT NULL DEFAULT '',
                queuedAt   INTEGER NOT NULL DEFAULT 0,
                sentAt     INTEGER NOT NULL DEFAULT 0,
                bouncedAt  INTEGER NOT NULL DEFAULT 0,
                error      TEXT,
                bounceCode TEXT    NOT NULL DEFAULT '',
                UNIQUE (parcelId)
            )
            SQL);

        $this->context = new WorkflowMailContext();
    }

    public function testRetriedSendAfterAFailureIsStillMappedToTheEntry(): void
    {
        $this->givenQueuedInvitation();

        // First worker attempt fails (e.g. the All-Inkl three-connection SMTP cap).
        $this->fireFailed(self::PARCEL_ID, 'Connection could not be established with host');

        $send = $this->send();
        self::assertNotFalse($send, 'the send row must survive a failure so a retry can be mapped back');
        self::assertSame('failed', (string) $send['state'], 'the send row records the failure');
        self::assertNotSame('', (string) $send['error'], 'the transport error is stored');

        $entry = $this->entry();
        self::assertSame(WorkflowStatus::STATUS_IMPORTED, (int) $entry['status'], 'a failed send must not advance the step');
        self::assertNotSame('', (string) $entry['sendError'], 'the failure is flagged on the entry');

        // Symfony Messenger retries with the same parcel id and the retry succeeds.
        $this->fireDelivered(self::PARCEL_ID);

        $send = $this->send();
        self::assertSame('sent', (string) $send['state']);
        self::assertGreaterThan(0, (int) $send['sentAt']);
        self::assertNull($send['error'], 'the error is cleared once the send succeeds');

        $entry = $this->entry();
        self::assertSame(WorkflowStatus::STATUS_INVITED, (int) $entry['status'], 'the successful retry advances to "invited"');
        self::assertSame('', (string) $entry['sendError'], 'the earlier error is cleared on success');
        self::assertSame(0, (int) $entry['sendErrorAt']);
        self::assertGreaterThan(0, (int) $entry['sentAt']);
    }

    public function testDeliveredInvitationMarksTheSendRowSentAndAdvancesTheEntry(): void
    {
        $this->givenQueuedInvitation();

        $this->fireDelivered(self::PARCEL_ID);

        self::assertSame('sent', (string) $this->send()['state']);
        self::assertSame(WorkflowStatus::STATUS_INVITED, (int) $this->entry()['status']);
    }

    public function testReceiptForAnUnknownParcelWithoutContextIsIgnored(): void
    {
        // No send row, no active context: nothing to correlate to.
        $this->fireDelivered(self::PARCEL_ID);

        self::assertFalse($this->send(), 'no row is invented for an uncorrelated receipt');
    }

    private function givenQueuedInvitation(): void
    {
        $this->connection->insert('tl_workflow_entry', [
            'id' => 1,
            'status' => WorkflowStatus::STATUS_IMPORTED,
        ]);

        $this->connection->insert('tl_workflow_send', [
            'parcelId' => self::PARCEL_ID,
            'entryId' => 1,
            'workflowId' => 7,
            'kind' => WorkflowMailContext::KIND_INVITE,
            'recipient' => 'person@example.org',
            'state' => 'queued',
            'queuedAt' => time(),
        ]);
    }

    private function fireDelivered(string $identifier): void
    {
        $this->listener()->onAsynchronousReceipt(
            new AsynchronousReceiptEvent(AsynchronousReceipt::createForSuccessfulDelivery($identifier)),
        );
    }

    private function fireFailed(string $identifier, string $message): void
    {
        $this->listener()->onAsynchronousReceipt(
            new AsynchronousReceiptEvent(
                AsynchronousReceipt::createForUnsuccessfulDelivery($identifier, new \RuntimeException($message)),
            ),
        );
    }

    private function listener(): WorkflowMailResultListener
    {
        return new WorkflowMailResultListener($this->connection, $this->context);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_workflow_entry WHERE id = 1') ?: [];
    }

    /**
     * @return array<string, mixed>|false
     */
    private function send(): array|false
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_workflow_send WHERE parcelId = ?', [self::PARCEL_ID]);
    }
}
