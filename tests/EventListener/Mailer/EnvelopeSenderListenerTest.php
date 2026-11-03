<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\EventListener\Mailer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\EventListener\Mailer\EnvelopeSenderListener;
use Psimandl\WorkflowBundle\Service\Bounce\BounceMailbox;
use Psimandl\WorkflowBundle\Service\WorkflowMailContext;
use Psr\Log\AbstractLogger;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Terminal42\NotificationCenterBundle\Gateway\MailerGateway;

/**
 * The envelope sender (SMTP MAIL FROM) decides where a bounce goes. Workflow mails must carry
 * the bounce mailbox there, at send time, while From and Reply-To stay as the notification
 * set them; every other mail must be left alone.
 */
final class EnvelopeSenderListenerTest extends TestCase
{
    private const PARCEL_ID = 'c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1c0ffee00ab0ad4b1';

    private const BOUNCE_DSN = 'imap://bounces%40example.org:secret@mail.example.org:993?ssl=true';

    private Connection $connection;

    private WorkflowMailContext $context;

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSendTable(withEnvelopeSender: true);
        $this->context = new WorkflowMailContext();
        $this->warnings = [];
    }

    public function testQueueTimeEventIsIgnored(): void
    {
        $this->logParcel();
        $event = $this->event(queued: true);

        $this->listener()->onMessage($event);

        self::assertSame('verwaltung@example.org', $event->getEnvelope()->getSender()->getAddress());
        self::assertSame('', $this->recordedEnvelopeSender());
    }

    public function testLoggedParcelGetsBounceMailboxAsEnvelopeSender(): void
    {
        $this->logParcel();
        $event = $this->event();

        $this->listener()->onMessage($event);

        self::assertSame('bounces@example.org', $event->getEnvelope()->getSender()->getAddress());
        self::assertSame('bounces@example.org', $this->recordedEnvelopeSender());

        // From and Reply-To are what people see and answer – untouched.
        $message = $event->getMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('verwaltung@example.org', $message->getFrom()[0]->getAddress());
        self::assertSame('antwort@example.org', $message->getReplyTo()[0]->getAddress());

        // Recipients are not affected by setting the sender.
        self::assertSame(['trainer@example.net'], array_map(
            static fn ($address) => $address->getAddress(),
            $event->getEnvelope()->getRecipients(),
        ));
    }

    public function testNotificationCenterMailOfAnotherModuleIsLeftAlone(): void
    {
        // Parcel id present, but not a workflow parcel and no workflow send in progress.
        $event = $this->event();

        $this->listener()->onMessage($event);

        self::assertSame('verwaltung@example.org', $event->getEnvelope()->getSender()->getAddress());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_workflow_send'));
    }

    public function testMailWithoutParcelIdIsLeftAlone(): void
    {
        $this->context->set(9, 42, WorkflowMailContext::KIND_INVITE, 'trainer@example.net', 7);
        $event = $this->event(parcelId: null);

        $this->listener()->onMessage($event);

        self::assertSame('verwaltung@example.org', $event->getEnvelope()->getSender()->getAddress());
    }

    public function testWithoutBounceAddressNothingChanges(): void
    {
        $this->logParcel();
        $event = $this->event();

        $this->listener(new BounceMailbox('imap://m0123456:secret@w0123456.kasserver.com:993'))->onMessage($event);

        self::assertSame('verwaltung@example.org', $event->getEnvelope()->getSender()->getAddress());
        self::assertSame('', $this->recordedEnvelopeSender());
    }

    public function testSynchronousSendCreatesTheRowFromTheContext(): void
    {
        // Synchronous delivery: the event fires inside NotificationDispatcher's send call,
        // before the dispatcher has logged the parcel.
        $this->context->set(9, 42, WorkflowMailContext::KIND_INVITE, 'trainer@example.net', 7);
        $event = $this->event();

        $this->listener()->onMessage($event);

        self::assertSame('bounces@example.org', $event->getEnvelope()->getSender()->getAddress());

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_workflow_send WHERE parcelId = ?', [self::PARCEL_ID]);
        self::assertIsArray($row);
        self::assertSame(42, (int) $row['entryId']);
        self::assertSame(9, (int) $row['workflowId']);
        self::assertSame('invite', $row['kind']);
        self::assertSame('trainer@example.net', $row['recipient']);
        self::assertSame('queued', $row['state']);
        self::assertSame('bounces@example.org', $row['envelopeSender']);
    }

    public function testMissingColumnDoesNotStopTheMail(): void
    {
        // The bundle was updated but the database not yet: the column does not exist.
        $this->connection->executeStatement('DROP TABLE tl_workflow_send');
        $this->createSendTable(withEnvelopeSender: false);
        $this->logParcel();
        $event = $this->event();

        $this->listener()->onMessage($event);

        self::assertSame('bounces@example.org', $event->getEnvelope()->getSender()->getAddress());
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('could not record envelope sender', $this->warnings[0]);
    }

    private function listener(?BounceMailbox $mailbox = null): EnvelopeSenderListener
    {
        $warnings = &$this->warnings;
        $logger = new class($warnings) extends AbstractLogger {
            /** @param list<string> $warnings */
            public function __construct(private array &$warnings)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };

        return new EnvelopeSenderListener(
            $this->connection,
            $this->context,
            $mailbox ?? new BounceMailbox(self::BOUNCE_DSN),
            $logger,
        );
    }

    private function event(bool $queued = false, ?string $parcelId = self::PARCEL_ID): MessageEvent
    {
        $email = (new Email())
            ->from('verwaltung@example.org')
            ->replyTo('antwort@example.org')
            ->to('trainer@example.net')
            ->subject('Einladung')
            ->text('Hallo')
        ;

        if (null !== $parcelId) {
            $email->getHeaders()->addTextHeader(MailerGateway::MESSAGE_IDENTIFIER_HEADER, $parcelId);
        }

        return new MessageEvent($email, Envelope::create($email), 'workflow', $queued);
    }

    private function logParcel(): void
    {
        $this->connection->insert('tl_workflow_send', [
            'parcelId' => self::PARCEL_ID,
            'entryId' => 42,
            'workflowId' => 9,
            'kind' => 'invite',
            'recipient' => 'trainer@example.net',
            'state' => 'queued',
        ]);
    }

    private function recordedEnvelopeSender(): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT envelopeSender FROM tl_workflow_send WHERE parcelId = ?',
            [self::PARCEL_ID],
        );
    }

    private function createSendTable(bool $withEnvelopeSender): void
    {
        $envelopeSender = $withEnvelopeSender ? ", envelopeSender TEXT NOT NULL DEFAULT ''" : '';

        $this->connection->executeStatement(<<<SQL
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
                bounceCode TEXT    NOT NULL DEFAULT ''
                {$envelopeSender},
                UNIQUE (parcelId)
            )
            SQL);
    }
}
