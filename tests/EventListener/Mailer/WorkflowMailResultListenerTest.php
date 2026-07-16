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
 * Regression cover for AP1: the parcel correlation must only be cleared once a send has
 * actually succeeded. A failed send is retried by Symfony Messenger with the *same* parcel
 * id, so clearing on failure strands the later SentMessageEvent and leaves the entry stuck
 * at "imported" with a send error even though the mail went out.
 *
 * The listener talks to the database exclusively through a Doctrine DBAL connection, so the
 * tests run it against an in-memory SQLite database. That exercises the real SQL and, more
 * importantly, the stateful interaction between the two receipts, which a mocked connection
 * could not capture.
 */
final class WorkflowMailResultListenerTest extends TestCase
{
    private const PARCEL_ID = 'ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_entry (
                id           INTEGER PRIMARY KEY,
                status       INTEGER NOT NULL DEFAULT 0,
                sendParcelId TEXT    NOT NULL DEFAULT '',
                sendKind     TEXT    NOT NULL DEFAULT '',
                sendError    TEXT    NOT NULL DEFAULT '',
                sendErrorAt  INTEGER NOT NULL DEFAULT 0,
                sentAt       INTEGER NOT NULL DEFAULT 0,
                tstamp       INTEGER NOT NULL DEFAULT 0
            )
            SQL);
    }

    public function testRetriedSendAfterAFailureStillAdvancesTheEntry(): void
    {
        $this->givenImportedInvitation();

        // First worker attempt fails (e.g. the All-Inkl three-connection SMTP cap).
        $this->fireFailed(self::PARCEL_ID, 'Connection could not be established with host');

        $afterFailure = $this->entry();
        self::assertSame(WorkflowStatus::STATUS_IMPORTED, (int) $afterFailure['status'], 'a failed send must not advance the step');
        self::assertNotSame('', (string) $afterFailure['sendError'], 'the failure must be recorded');
        self::assertSame(self::PARCEL_ID, (string) $afterFailure['sendParcelId'], 'correlation must survive a failure so the retry can be mapped back');
        self::assertSame(WorkflowMailContext::KIND_INVITE, (string) $afterFailure['sendKind']);

        // Symfony Messenger retries with the same parcel id and the retry succeeds.
        $this->fireDelivered(self::PARCEL_ID);

        $afterDelivery = $this->entry();
        self::assertSame(WorkflowStatus::STATUS_INVITED, (int) $afterDelivery['status'], 'the successful retry must advance to "invited"');
        self::assertSame('', (string) $afterDelivery['sendError'], 'the earlier error must be cleared on success');
        self::assertSame(0, (int) $afterDelivery['sendErrorAt']);
        self::assertGreaterThan(0, (int) $afterDelivery['sentAt'], 'a delivered invitation records the send time');
        self::assertSame('', (string) $afterDelivery['sendParcelId'], 'correlation is consumed only on success');
        self::assertSame('', (string) $afterDelivery['sendKind']);
    }

    public function testASuccessfulInvitationConsumesTheCorrelation(): void
    {
        $this->givenImportedInvitation();

        $this->fireDelivered(self::PARCEL_ID);

        $entry = $this->entry();
        self::assertSame(WorkflowStatus::STATUS_INVITED, (int) $entry['status']);
        self::assertSame('', (string) $entry['sendParcelId']);
        self::assertSame('', (string) $entry['sendKind']);
    }

    public function testALateDuplicateReceiptForAConsumedCorrelationIsIgnored(): void
    {
        $this->givenImportedInvitation();
        $this->fireDelivered(self::PARCEL_ID);

        // A duplicate/late receipt arrives for the same parcel id; nothing correlates to it
        // any more, so the entry must be left exactly as it was (no second advance).
        $this->fireDelivered(self::PARCEL_ID);

        self::assertSame(WorkflowStatus::STATUS_INVITED, (int) $this->entry()['status']);
    }

    private function givenImportedInvitation(): void
    {
        $this->connection->insert('tl_workflow_entry', [
            'id' => 1,
            'status' => WorkflowStatus::STATUS_IMPORTED,
            'sendParcelId' => self::PARCEL_ID,
            'sendKind' => WorkflowMailContext::KIND_INVITE,
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
        return new WorkflowMailResultListener($this->connection, new WorkflowMailContext());
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_workflow_entry WHERE id = 1') ?: [];
    }
}
