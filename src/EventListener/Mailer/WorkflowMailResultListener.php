<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\Mailer;

use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Service\Delivery\DeliveryRecorder;
use Psimandl\WorkflowBundle\Service\WorkflowMailContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Terminal42\NotificationCenterBundle\Event\AsynchronousReceiptEvent;

/**
 * Records the *actual* mail send result instead of the optimistic dispatch-time guess. The
 * Notification Center reports the real (often asynchronous) result via
 * {@see AsynchronousReceiptEvent}, carrying the parcel id that {@see NotificationDispatcher}
 * stored in the durable tl_workflow_send table at dispatch.
 *
 * Two things are updated for each receipt:
 *
 *  - The durable tl_workflow_send row is moved along its state machine (queued → sent /
 *    failed). The row is never deleted, so a bounce (DSN) arriving much later can still be
 *    correlated to it by parcel id.
 *  - The entry, through {@see DeliveryRecorder}: a successful invitation advances it from
 *    "imported" to "invited", a reminder records the send time, a permanent rejection of the
 *    recipient blocks the address, any other failure is stored as a retryable send error.
 *    The entry is only touched while its address is still the one the mail went to.
 *
 * For the rare synchronous transport the receipt event fires within the send call, before
 * the tl_workflow_send row is inserted, so the live {@see WorkflowMailContext} is used as a
 * fallback and the row is created here; {@see NotificationDispatcher::rememberParcel()} then
 * upserts it idempotently without resetting the state written here. With a bounce mailbox
 * address configured, {@see EnvelopeSenderListener} has already created the row at send
 * time, and the row is simply updated.
 */
class WorkflowMailResultListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly WorkflowMailContext $context,
        private readonly DeliveryRecorder $recorder,
    ) {
    }

    #[AsEventListener]
    public function onAsynchronousReceipt(AsynchronousReceiptEvent $event): void
    {
        $receipt = $event->receipt;
        $identifier = $receipt->getIdentifier();

        // Async: the "queued" row was inserted at dispatch, before the worker ran. Sync: the
        // event fires within the send call before that insert, so fall back to the context.
        $row = $this->connection->fetchAssociative(
            'SELECT entryId, kind, recipient FROM tl_workflow_send WHERE parcelId = ? LIMIT 1',
            [$identifier],
        );

        if (false !== $row) {
            $entryId = (int) $row['entryId'];
            $kind = (string) $row['kind'];
            $recipient = (string) $row['recipient'];
            $rowExists = true;
        } elseif ($this->context->isActive()) {
            $entryId = (int) $this->context->getEntryId();
            $kind = (string) $this->context->getKind();
            $recipient = (string) $this->context->getRecipient();
            $rowExists = false;
        } else {
            return;
        }

        if ($receipt->wasDelivered()) {
            $this->writeSendResult($identifier, $rowExists, 'sent', null);
            $this->recorder->delivered($entryId, $kind, $recipient);

            return;
        }

        $error = $receipt->getException() ?? new \RuntimeException('Unbekannter Fehler');
        $this->writeSendResult($identifier, $rowExists, 'failed', DeliveryRecorder::shorten($error->getMessage()));
        $this->recorder->failed($entryId, $kind, $recipient, $error);
    }

    private function writeSendResult(string $parcelId, bool $rowExists, string $state, ?string $error): void
    {
        $now = time();
        $sentAt = 'sent' === $state ? $now : 0;

        if ($rowExists) {
            // Never delete the row: a later bounce is correlated to it by parcel id.
            $this->connection->executeStatement(
                'UPDATE tl_workflow_send SET state = ?, sentAt = ?, error = ?, tstamp = ? WHERE parcelId = ?',
                [$state, $sentAt, $error, $now, $parcelId],
            );

            return;
        }

        // Synchronous fallback: create the row from the still-active context so a later
        // bounce can be correlated. The subsequent rememberParcel() upsert preserves this
        // state. The tight active-context window is what keeps a foreign notification sent
        // in the same request from being mis-attributed here.
        $this->connection->executeStatement(
            'INSERT INTO tl_workflow_send (tstamp, parcelId, entryId, workflowId, kind, recipient, state, queuedAt, sentAt, error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE state = VALUES(state), sentAt = VALUES(sentAt), error = VALUES(error), tstamp = VALUES(tstamp)',
            [
                $now,
                $parcelId,
                (int) $this->context->getEntryId(),
                (int) $this->context->getWorkflowId(),
                (string) $this->context->getKind(),
                (string) $this->context->getRecipient(),
                $state,
                $now,
                $sentAt,
                $error,
            ],
        );
    }
}
