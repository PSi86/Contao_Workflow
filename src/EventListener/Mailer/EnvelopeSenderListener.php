<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\Mailer;

use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Service\Bounce\BounceMailbox;
use Psimandl\WorkflowBundle\Service\WorkflowMailContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Terminal42\NotificationCenterBundle\Gateway\MailerGateway;

/**
 * Sends every workflow mail with the bounce mailbox as envelope sender (SMTP MAIL FROM).
 * A bounce (DSN) goes to the envelope sender, never to From or Reply-To, so this is what
 * routes bounces to the mailbox the bounce collector reads, whatever sender the
 * notification uses. From and Reply-To stay untouched: people still see and answer the
 * notification's sender.
 *
 * Only the send-time event counts. The event Mailer::send() fires while queueing carries a
 * copy of message and envelope, and its changes are discarded; the transport fires the
 * send-time event and then sends with exactly the envelope this listener changed.
 *
 * A workflow mail is recognised by the Notification Center parcel id, still present as a
 * header at send time (the Notification Center removes it after sending):
 *
 *  - asynchronous delivery (the usual path): NotificationDispatcher logged the parcel in
 *    tl_workflow_send before the worker sends it;
 *  - synchronous delivery: the event fires within NotificationDispatcher's send call, before
 *    that row exists. The active WorkflowMailContext identifies the mail, and the row is
 *    created here so the envelope sender is recorded; the receipt listener and the
 *    dispatcher's upsert then update that row.
 *
 * tl_workflow_send.envelopeSender records the address that actually went out as MAIL FROM.
 *
 * Runs after Symfony's EnvelopeListener (priority -255), so a global
 * framework.mailer.envelope.sender does not divert the bounces of workflow mails.
 */
class EnvelopeSenderListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly WorkflowMailContext $context,
        private readonly BounceMailbox $mailbox,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(priority: -256)]
    public function onMessage(MessageEvent $event): void
    {
        if ($event->isQueued()) {
            return;
        }

        $message = $event->getMessage();

        if (!$message instanceof Message) {
            return;
        }

        $parcelId = (string) $message->getHeaders()->get(MailerGateway::MESSAGE_IDENTIFIER_HEADER)?->getBodyAsString();

        if ('' === $parcelId) {
            return;
        }

        $bounceAddress = $this->mailbox->address();

        if (null === $bounceAddress) {
            return;
        }

        try {
            $logged = false !== $this->connection->fetchOne(
                'SELECT id FROM tl_workflow_send WHERE parcelId = ? LIMIT 1',
                [$parcelId],
            );
        } catch (\Throwable $e) {
            // Leave the envelope alone rather than block the mail.
            $this->warn('could not look up parcel '.$parcelId.' ('.$e->getMessage().'); envelope sender left unchanged');

            return;
        }

        if (!$logged && !$this->context->isActive()) {
            // A Notification Center mail that is not a workflow mail.
            return;
        }

        $event->getEnvelope()->setSender(new Address($bounceAddress));

        try {
            if ($logged) {
                $this->connection->update(
                    'tl_workflow_send',
                    ['envelopeSender' => $bounceAddress, 'tstamp' => time()],
                    ['parcelId' => $parcelId],
                );
            } else {
                $this->insertSyncRow($parcelId, $bounceAddress);
            }
        } catch (\Throwable $e) {
            // The envelope is already set and the mail goes out correctly; only the record
            // is missing (e.g. the database was not updated after the bundle update).
            $this->warn('could not record envelope sender for parcel '.$parcelId.' ('.$e->getMessage().')');
        }
    }

    /**
     * Synchronous delivery: no row yet, and none can appear concurrently (this very call is
     * sending the parcel), so a plain insert is safe.
     */
    private function insertSyncRow(string $parcelId, string $bounceAddress): void
    {
        $now = time();

        $this->connection->insert('tl_workflow_send', [
            'tstamp' => $now,
            'parcelId' => $parcelId,
            'entryId' => (int) $this->context->getEntryId(),
            'workflowId' => (int) $this->context->getWorkflowId(),
            'kind' => (string) $this->context->getKind(),
            'recipient' => (string) $this->context->getRecipient(),
            'state' => 'queued',
            'queuedAt' => $now,
            'envelopeSender' => $bounceAddress,
        ]);
    }

    private function warn(string $message): void
    {
        $this->logger->warning(
            'Workflow envelope sender: '.$message.'.',
            ['contao' => new ContaoContext(self::class.'::onMessage', ContaoContext::GENERAL)],
        );
    }
}
