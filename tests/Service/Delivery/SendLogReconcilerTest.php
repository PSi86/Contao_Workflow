<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Delivery;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Delivery\DeliveryRecorder;
use Psimandl\WorkflowBundle\Service\Delivery\SendFailureClassifier;
use Psimandl\WorkflowBundle\Service\Delivery\SendLogReconciler;
use Psr\Log\NullLogger;

/**
 * A send-log row that stays "queued" although the mail queue is empty can never get a result
 * any more. It is resolved as failed, so the overview stops asking whether the cron runs and
 * the entry shows the problem instead.
 */
final class SendLogReconcilerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_send (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tstamp INTEGER NOT NULL DEFAULT 0,
                parcelId TEXT NOT NULL DEFAULT '', entryId INTEGER NOT NULL DEFAULT 0,
                kind TEXT NOT NULL DEFAULT '', recipient TEXT NOT NULL DEFAULT '',
                state TEXT NOT NULL DEFAULT '', queuedAt INTEGER NOT NULL DEFAULT 0, error TEXT NULL
            )
            SQL);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_entry (
                id INTEGER PRIMARY KEY, email TEXT NOT NULL DEFAULT '', bounceHard TEXT NOT NULL DEFAULT '',
                bounceInfo TEXT NOT NULL DEFAULT '', sendError TEXT NULL, sendErrorAt INTEGER NOT NULL DEFAULT 0,
                tstamp INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $this->connection->executeStatement('CREATE TABLE tl_message_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT, queue_name TEXT NOT NULL)');

        $this->connection->insert('tl_workflow_entry', ['id' => 1, 'email' => 'p@example.org']);
        $this->connection->insert('tl_workflow_send', ['parcelId' => 'OLD', 'entryId' => 1, 'kind' => 'invite', 'recipient' => 'p@example.org', 'state' => 'queued', 'queuedAt' => time() - 7200]);
        $this->connection->insert('tl_workflow_send', ['parcelId' => 'FRESH', 'entryId' => 1, 'kind' => 'invite', 'recipient' => 'p@example.org', 'state' => 'queued', 'queuedAt' => time() - 60]);
    }

    public function testOldQueuedRowsAreResolvedWhenTheMailQueueIsEmpty(): void
    {
        // A message that ended in the failure queue was reported by the mailer already.
        $this->connection->insert('tl_message_queue', ['body' => 'x', 'queue_name' => 'failure']);

        self::assertSame(1, $this->reconciler()->resolveOrphans());

        self::assertSame('failed', $this->state('OLD'));
        self::assertSame('queued', $this->state('FRESH'), 'a recent row may still be on its way');
        self::assertStringContainsString('Einladung', (string) $this->connection->fetchOne('SELECT sendError FROM tl_workflow_entry WHERE id = 1'));
    }

    /**
     * The case found on production: an invitation to "name@hotmail" (no top-level domain).
     * The Notification Center drops such a recipient, so the mail can never have been queued –
     * the row is resolved even while other mails wait, and the address is blocked with the
     * real reason instead of a generic "no result".
     */
    public function testAnInvalidRecipientIsBlockedWithTheRealReason(): void
    {
        $this->connection->insert('tl_workflow_entry', ['id' => 2, 'email' => 'kahnttlehrgang@hotmail']);
        $this->connection->insert('tl_workflow_send', ['parcelId' => 'INVALID', 'entryId' => 2, 'kind' => 'invite', 'recipient' => 'kahnttlehrgang@hotmail', 'state' => 'queued', 'queuedAt' => time() - 7200]);
        $this->connection->insert('tl_message_queue', ['body' => 'x', 'queue_name' => 'prio_high']);

        self::assertSame(1, $this->reconciler()->resolveOrphans());

        self::assertSame('failed', $this->state('INVALID'));
        self::assertSame('queued', $this->state('OLD'), 'a valid address still waits while the queue is busy');

        $entry = $this->connection->fetchAssociative('SELECT bounceHard, bounceInfo FROM tl_workflow_entry WHERE id = 2');
        self::assertSame('1', (string) $entry['bounceHard']);
        self::assertStringContainsString('Ungültige Adresse', (string) $entry['bounceInfo']);
    }

    public function testNothingIsResolvedWhileMailsAreStillQueued(): void
    {
        $this->connection->insert('tl_message_queue', ['body' => 'x', 'queue_name' => 'prio_high']);

        self::assertSame(0, $this->reconciler()->resolveOrphans());
        self::assertSame('queued', $this->state('OLD'));
    }

    public function testWithoutAMessageQueueTableNothingIsDecided(): void
    {
        $this->connection->executeStatement('DROP TABLE tl_message_queue');

        self::assertSame(0, $this->reconciler()->resolveOrphans());
        self::assertSame('queued', $this->state('OLD'));
    }

    private function reconciler(): SendLogReconciler
    {
        return new SendLogReconciler(
            $this->connection,
            new DeliveryRecorder($this->connection, new SendFailureClassifier()),
            new NullLogger(),
        );
    }

    private function state(string $parcelId): string
    {
        return (string) $this->connection->fetchOne('SELECT state FROM tl_workflow_send WHERE parcelId = ?', [$parcelId]);
    }
}
