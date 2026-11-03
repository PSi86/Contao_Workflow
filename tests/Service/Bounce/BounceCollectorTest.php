<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Bounce;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Contao\CoreBundle\Cron\Cron;
use Contao\CoreBundle\Exception\CronExecutionSkippedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Bounce\BounceCollector;
use Psimandl\WorkflowBundle\Service\Bounce\BounceHealth;
use Psimandl\WorkflowBundle\Service\Bounce\BounceParser;
use Psimandl\WorkflowBundle\Service\Bounce\BounceSchedule;
use Psimandl\WorkflowBundle\Service\CronHealth;
use Psimandl\WorkflowBundle\Service\Delivery\DeliveryRecorder;
use Psimandl\WorkflowBundle\Service\Delivery\SendFailureClassifier;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Covers the bounce-to-database mapping of the collector and its decisions – which message is
 * moved to Processed, where reading resumes, how many messages stay for a person – the parts
 * that have no IMAP dependency. The IMAP transport itself (connect, fetch, move to Processed)
 * needs a live mailbox and is verified operationally instead.
 */
final class BounceCollectorTest extends TestCase
{
    private const HARD_PARCEL = 'ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00';
    private const HARD_RECIPIENT = 'sdfdfsdfsd@wherever-we-are.com';
    private const MAILBOX = 'imap://bounces%40example.org@mail.example.org:993';

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
                email      TEXT NOT NULL DEFAULT '',
                bounceHard TEXT NOT NULL DEFAULT '',
                bounceInfo TEXT NOT NULL DEFAULT '',
                tstamp     INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $this->connection->executeStatement('CREATE TABLE tl_workflow_cron_health (id INTEGER PRIMARY KEY, tstamp INTEGER NOT NULL DEFAULT 0, lastUrlAt INTEGER NOT NULL DEFAULT 0, lastCliAt INTEGER NOT NULL DEFAULT 0)');
        $this->connection->executeStatement("CREATE TABLE tl_workflow_bounce_health (id INTEGER PRIMARY KEY, tstamp INTEGER NOT NULL DEFAULT 0, state TEXT NOT NULL DEFAULT '', message TEXT NULL)");

        $this->collector = $this->collector();
    }

    /**
     * Started by a page view while a real cron calls Contao, the run is handed back – no IMAP
     * login inside a visitor's request.
     */
    public function testPageViewRunIsLeftToARealCron(): void
    {
        $this->cronHealth()->recordCall(CronHealth::TRIGGER_URL);

        $this->expectException(CronExecutionSkippedException::class);

        ($this->collector)(Cron::SCOPE_WEB);
    }

    /**
     * Not due yet (checked a minute ago, nothing sent): the minutely call does nothing – the
     * recorded verdict, and thus the time of the last check, stays as it was.
     */
    public function testNoCheckBeforeTheScheduleSaysSo(): void
    {
        $checkedAt = time() - 60;
        $this->connection->insert('tl_workflow_bounce_health', ['id' => 1, 'tstamp' => $checkedAt, 'state' => 'unconfigured']);

        ($this->collector)(Cron::SCOPE_CLI);

        self::assertSame($checkedAt, (int) $this->connection->fetchOne('SELECT tstamp FROM tl_workflow_bounce_health WHERE id = 1'));
    }

    public function testADueCheckRecordsItsVerdict(): void
    {
        ($this->collector)(Cron::SCOPE_CLI);

        $row = $this->connection->fetchAssociative('SELECT state, tstamp FROM tl_workflow_bounce_health WHERE id = 1');
        self::assertSame(BounceHealth::STATE_UNCONFIGURED, (string) $row['state'], 'no DSN in this test');
        self::assertGreaterThan(0, (int) $row['tstamp']);
    }

    private function collector(): BounceCollector
    {
        return new BounceCollector(
            new BounceParser(),
            $this->connection,
            new NullLogger(),
            new BounceHealth($this->connection),
            new DeliveryRecorder($this->connection, new SendFailureClassifier()),
            new BounceSchedule($this->connection),
            $this->cronHealth(),
            new LockFactory(new InMemoryStore()),
            null,
        );
    }

    private function cronHealth(): CronHealth
    {
        return new CronHealth($this->connection, new RequestStack(), new NullLogger());
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

        $verdict = $this->collector->handleRaw($this->fixture('bounce-hard-550.eml'));

        self::assertSame(['own' => true, 'outcomes' => ['hard']], $verdict, 'a bounce of a workflow mail is moved to Processed');

        $send = $this->sendRow(self::HARD_PARCEL);
        self::assertSame('bounced', (string) $send['state']);
        self::assertGreaterThan(0, (int) $send['bouncedAt']);
        self::assertStringContainsString('User unknown', (string) $send['bounceCode']);

        $entry = $this->connection->fetchAssociative('SELECT * FROM tl_workflow_entry WHERE id = 1');
        self::assertSame('1', (string) $entry['bounceHard'], 'the entry is flagged as an invalid address');
        self::assertStringContainsString(self::HARD_RECIPIENT, (string) $entry['bounceInfo']);
    }

    /**
     * The bounce of a mail to the OLD address arrives after the admin corrected it. The send
     * log records the bounce, but the corrected address must not be blocked.
     */
    public function testBounceForAFormerAddressDoesNotBlockTheCorrectedOne(): void
    {
        $this->insertSend(self::HARD_PARCEL, self::HARD_RECIPIENT, 1);
        $this->insertEntry(1, 'corrected@example.org');

        $this->collector->handleRaw($this->fixture('bounce-hard-550.eml'));

        self::assertSame('bounced', (string) $this->sendRow(self::HARD_PARCEL)['state'], 'the send log keeps the truth');

        $entry = $this->connection->fetchAssociative('SELECT * FROM tl_workflow_entry WHERE id = 1');
        self::assertSame('', (string) $entry['bounceHard'], 'the corrected address stays usable');
    }

    public function testSoftBounceLeavesTheStateUnchanged(): void
    {
        $this->insertSend('PARCELSOFT0000000', 'late@example.com', 2);

        $verdict = $this->collector->handleRaw($this->dsn('late@example.com', 'delayed', '4.4.1', 'PARCELSOFT0000000'));

        self::assertSame(['own' => true, 'outcomes' => ['soft']], $verdict, 'a delay notice of a workflow mail is ours too');
        self::assertSame('sent', (string) $this->sendRow('PARCELSOFT0000000')['state']);
    }

    public function testHardBounceWithoutParcelIdFallsBackToTheRecipient(): void
    {
        $this->insertSend('PARCELX0000000000', 'dead@example.com', 3);

        // No embedded original → the parser reports a null parcel id.
        $verdict = $this->collector->handleRaw($this->dsn('dead@example.com', 'failed', '5.1.1', null));

        self::assertTrue($verdict['own']);
        self::assertSame('bounced', (string) $this->sendRow('PARCELX0000000000')['state']);
    }

    /**
     * A bounce whose parcel id is not in the send log belongs to someone else (another sender
     * pointing at the mailbox, another installation) or to a purged row: it stays in the INBOX.
     */
    public function testUnknownParcelIdIsNotGuessed(): void
    {
        $this->insertSend('KNOWNPARCEL000000', 'x@example.com', 4);

        // Parcel id is present in the bounce but not in the database: must not be correlated.
        $verdict = $this->collector->handleRaw($this->dsn('x@example.com', 'failed', '5.1.1', 'UNKNOWNPARCEL0000'));

        self::assertSame(['own' => false, 'outcomes' => ['unmatched']], $verdict);
        self::assertSame('sent', (string) $this->sendRow('KNOWNPARCEL000000')['state']);
    }

    public function testBounceOfAForeignMailWithoutParcelIdStaysInTheInbox(): void
    {
        // Like the DSNs of the All-Inkl probe: no parcel id, and no workflow mail went to them.
        $verdict = $this->collector->handleRaw($this->dsn('wf-bouncetest-3777b18fd49185fb@gmail.com', 'failed', '5.1.1', null));

        self::assertSame(['own' => false, 'outcomes' => ['unmatched']], $verdict);
    }

    /**
     * Replies and out-of-office notes that reach the bounce mailbox are for a person: they stay
     * in the INBOX (until 3.5.0 they were moved to Processed, where nobody looked).
     */
    public function testOrdinaryMailStaysInTheInboxAndChangesNothing(): void
    {
        $this->insertSend('P00000000000000000', 'x@example.com', 5);

        $verdict = $this->collector->handleRaw($this->fixture('delivered-reminder.eml'));

        self::assertSame(['own' => false, 'outcomes' => ['nodsn']], $verdict);
        self::assertSame('sent', (string) $this->sendRow('P00000000000000000')['state']);
    }

    public function testDryRunDecidesWithoutWriting(): void
    {
        $this->insertSend(self::HARD_PARCEL, self::HARD_RECIPIENT, 1);
        $this->insertEntry(1);

        $verdict = $this->collector->handleRaw($this->fixture('bounce-hard-550.eml'), true);

        self::assertSame(['own' => true, 'outcomes' => ['hard']], $verdict);
        self::assertSame('sent', (string) $this->sendRow(self::HARD_PARCEL)['state']);
        self::assertSame('', (string) $this->connection->fetchOne('SELECT bounceHard FROM tl_workflow_entry WHERE id = 1'));
    }

    /**
     * @return iterable<string, array{array{mailbox: string, uidValidity: int, lastUid: int, foreignCount: int}, int}>
     */
    public static function watermarks(): iterable
    {
        $scan = static fn (string $mailbox, int $validity, int $lastUid): array => ['mailbox' => $mailbox, 'uidValidity' => $validity, 'lastUid' => $lastUid, 'foreignCount' => 0];

        yield 'same mailbox, same UIDVALIDITY: continue' => [$scan(self::MAILBOX, 1700000000, 42), 42];
        yield 'nothing recorded yet' => [$scan('', 0, 0), 0];
        yield 'mailbox configuration changed' => [$scan('imap://other%40example.org@mail.example.org:993', 1700000000, 42), 0];
        yield 'server renumbered the messages' => [$scan(self::MAILBOX, 1600000000, 42), 0];
    }

    /**
     * @param array{mailbox: string, uidValidity: int, lastUid: int, foreignCount: int} $scan
     */
    #[DataProvider('watermarks')]
    public function testResumeAfterTheWatermarkOnlyForTheSameMailbox(array $scan, int $expected): void
    {
        self::assertSame($expected, BounceCollector::resumeAfter($scan, self::MAILBOX, 1700000000));
    }

    public function testNoWatermarkWithoutUidValidity(): void
    {
        $scan = ['mailbox' => self::MAILBOX, 'uidValidity' => 0, 'lastUid' => 42, 'foreignCount' => 0];

        self::assertSame(0, BounceCollector::resumeAfter($scan, self::MAILBOX, 0));
    }

    public function testLeftInInbox(): void
    {
        // 10 in the INBOX, 3 new, all looked at, 2 bounces moved out: 8 remain for a person.
        self::assertSame(8, BounceCollector::leftInInbox(10, 2, 3, 3));
        // Batch limit: 120 new, 100 looked at, 10 moved – the 20 not looked at are not counted.
        self::assertSame(120, BounceCollector::leftInInbox(150, 10, 120, 100));
        // A person deleted messages during the run: never negative.
        self::assertSame(0, BounceCollector::leftInInbox(1, 2, 0, 0));
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

    private function insertEntry(int $id, string $email = self::HARD_RECIPIENT): void
    {
        $this->connection->insert('tl_workflow_entry', ['id' => $id, 'email' => $email]);
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
