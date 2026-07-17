<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Bounce;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Bounce\BounceCollector;
use Psimandl\WorkflowBundle\Service\Bounce\BounceHealth;
use Psimandl\WorkflowBundle\Service\Bounce\BounceParser;
use Psr\Log\NullLogger;

/**
 * Covers the bounce-to-database mapping of the collector — the part that has no IMAP
 * dependency and can be driven against a real database. The IMAP transport itself (connect,
 * fetch, move to Processed) needs a live mailbox and is verified operationally instead.
 */
final class BounceCollectorTest extends TestCase
{
    private const HARD_PARCEL = 'ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00';
    private const HARD_RECIPIENT = 'sdfdfsdfsd@wherever-we-are.com';

    private Connection $connection;

    private BounceCollector $collector;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_send (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                parcelId   TEXT NOT NULL DEFAULT '',
                entryId    INTEGER NOT NULL DEFAULT 0,
                recipient  TEXT NOT NULL DEFAULT '',
                state      TEXT NOT NULL DEFAULT '',
                sentAt     INTEGER NOT NULL DEFAULT 0,
                bouncedAt  INTEGER NOT NULL DEFAULT 0,
                bounceCode TEXT NOT NULL DEFAULT '',
                tstamp     INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_entry (
                id         INTEGER PRIMARY KEY,
                bounceHard TEXT NOT NULL DEFAULT '',
                bounceInfo TEXT NOT NULL DEFAULT '',
                tstamp     INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $this->collector = new BounceCollector(
            new BounceParser(),
            $this->connection,
            new NullLogger(),
            new BounceHealth($this->connection),
            null,
        );
    }

    /**
     * An empty DSN must be reported as "unconfigured" without any IMAP attempt — this is the
     * off-by-default state, not an error.
     */
    public function testEmptyDsnReportsUnconfiguredWithoutConnecting(): void
    {
        $noted = [];
        $outcome = $this->collector->collect('', false, static function (string $level, string $message) use (&$noted): void {
            $noted[] = [$level, $message];
        });

        self::assertSame(BounceHealth::STATE_UNCONFIGURED, $outcome->state);
        self::assertNotEmpty($noted, 'the operator is told the mailbox is unconfigured');
    }

    /**
     * A malformed DSN is a config error (the "wrong password formatting" class of problem),
     * surfaced as such rather than thrown.
     */
    public function testMalformedDsnReportsConfigError(): void
    {
        $outcome = $this->collector->collect('not-a-valid-dsn');

        self::assertSame(BounceHealth::STATE_CONFIG_ERROR, $outcome->state);
        self::assertNotSame('', $outcome->message);
    }

    public function testHardBounceMovesTheSendRowToBouncedAndFlagsTheEntry(): void
    {
        $this->insertSend(self::HARD_PARCEL, self::HARD_RECIPIENT, 1);
        $this->insertEntry(1);

        $this->collector->handleRaw($this->fixture('bounce-hard-550.eml'));

        $send = $this->sendRow(self::HARD_PARCEL);
        self::assertSame('bounced', (string) $send['state']);
        self::assertGreaterThan(0, (int) $send['bouncedAt']);
        self::assertStringContainsString('User unknown', (string) $send['bounceCode']);

        $entry = $this->connection->fetchAssociative('SELECT * FROM tl_workflow_entry WHERE id = 1');
        self::assertSame('1', (string) $entry['bounceHard'], 'the entry is flagged as an invalid address');
        self::assertStringContainsString(self::HARD_RECIPIENT, (string) $entry['bounceInfo']);
    }

    public function testSoftBounceLeavesTheStateUnchanged(): void
    {
        $this->insertSend('PARCELSOFT0000000', 'late@example.com', 2);

        $this->collector->handleRaw($this->dsn('late@example.com', 'delayed', '4.4.1', 'PARCELSOFT0000000'));

        self::assertSame('sent', (string) $this->sendRow('PARCELSOFT0000000')['state']);
    }

    public function testHardBounceWithoutParcelIdFallsBackToTheRecipient(): void
    {
        $this->insertSend('PARCELX0000000000', 'dead@example.com', 3);

        // No embedded original → the parser reports a null parcel id.
        $this->collector->handleRaw($this->dsn('dead@example.com', 'failed', '5.1.1', null));

        self::assertSame('bounced', (string) $this->sendRow('PARCELX0000000000')['state']);
    }

    public function testUnknownParcelIdIsNotGuessed(): void
    {
        $this->insertSend('KNOWNPARCEL000000', 'x@example.com', 4);

        // Parcel id is present in the bounce but not in the database: must not be correlated.
        $this->collector->handleRaw($this->dsn('x@example.com', 'failed', '5.1.1', 'UNKNOWNPARCEL0000'));

        self::assertSame('sent', (string) $this->sendRow('KNOWNPARCEL000000')['state']);
    }

    public function testOrdinaryMailChangesNothing(): void
    {
        $this->insertSend('P00000000000000000', 'x@example.com', 5);

        $this->collector->handleRaw($this->fixture('delivered-reminder.eml'));

        self::assertSame('sent', (string) $this->sendRow('P00000000000000000')['state']);
    }

    private function insertSend(string $parcelId, string $recipient, int $entryId): void
    {
        $this->connection->insert('tl_workflow_send', [
            'parcelId' => $parcelId,
            'entryId' => $entryId,
            'recipient' => $recipient,
            'state' => 'sent',
            'sentAt' => time(),
        ]);
    }

    private function insertEntry(int $id): void
    {
        $this->connection->insert('tl_workflow_entry', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendRow(string $parcelId): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_workflow_send WHERE parcelId = ?', [$parcelId]) ?: [];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/'.$name);
    }

    private function dsn(string $recipient, string $action, string $status, ?string $parcelId): string
    {
        $b = 'BOUNDARY99abc';

        $embedded = '';

        if (null !== $parcelId) {
            $embedded = "--$b\n"
                ."Content-Type: message/rfc822\n\n"
                ."Notification-Center-Parcel-ID: $parcelId\n"
                ."From: noreply@example.com\nTo: $recipient\nSubject: Erinnerung\n\n"
                ."Body.\n";
        }

        return "From: MAILER-DAEMON@host\nTo: noreply@example.com\n"
            ."Content-Type: multipart/report; report-type=delivery-status; boundary=\"$b\"\n\n"
            ."--$b\nContent-Type: text/plain\n\nDelivery failed.\n\n"
            ."--$b\nContent-Type: message/delivery-status\n\n"
            ."Reporting-MTA: dns; host\n\n"
            ."Final-Recipient: rfc822; $recipient\nAction: $action\nStatus: $status\n"
            ."Diagnostic-Code: smtp; 550 5.1.1 User unknown\n\n"
            .$embedded
            ."--$b--\n";
    }
}
