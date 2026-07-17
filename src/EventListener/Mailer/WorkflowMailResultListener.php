<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\Mailer;

use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Service\WorkflowMailContext;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;
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
 *  - The denormalized display fields on the entry: a successful invitation advances the
 *    entry from "imported" to "invited"; a reminder records the send time; a failure stores
 *    the error so the back end dashboard can flag the entry.
 *
 * For the rare synchronous transport the receipt event fires within the send call, before
 * the tl_workflow_send row is inserted, so the live {@see WorkflowMailContext} is used as a
 * fallback and the row is created here; {@see NotificationDispatcher::rememberParcel()} then
 * upserts it idempotently without resetting the state written here.
 */
class WorkflowMailResultListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly WorkflowMailContext $context,
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
            'SELECT entryId, kind FROM tl_workflow_send WHERE parcelId = ? LIMIT 1',
            [$identifier],
        );

        if (false !== $row) {
            $entryId = (int) $row['entryId'];
            $kind = (string) $row['kind'];
            $rowExists = true;
        } elseif ($this->context->isActive()) {
            $entryId = (int) $this->context->getEntryId();
            $kind = (string) $this->context->getKind();
            $rowExists = false;
        } else {
            return;
        }

        if ($receipt->wasDelivered()) {
            $this->writeSendResult($identifier, $rowExists, 'sent', null);
            $this->markEntryDelivered($entryId, $kind);
        } else {
            $message = $this->shorten($receipt->getException()?->getMessage() ?? 'Unbekannter Fehler');
            $this->writeSendResult($identifier, $rowExists, 'failed', $message);
            $this->markEntryFailed($entryId, $kind, $message);
        }
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

    private function markEntryDelivered(int $entryId, string $kind): void
    {
        $statusValue = (int) $this->connection->fetchOne('SELECT status FROM tl_workflow_entry WHERE id = ?', [$entryId]);

        // A successful send always clears a previously recorded failure.
        $set = ["sendError = ''", 'sendErrorAt = 0'];
        $params = [];

        if (WorkflowMailContext::KIND_INVITE === $kind && WorkflowStatus::STATUS_IMPORTED === $statusValue) {
            $set[] = 'status = ?';
            $params[] = WorkflowStatus::STATUS_INVITED;
            $set[] = 'sentAt = ?';
            $params[] = time();
        } elseif (WorkflowMailContext::KIND_REMINDER === $kind) {
            $set[] = 'sentAt = ?';
            $params[] = time();
        }

        $set[] = 'tstamp = ?';
        $params[] = time();
        $params[] = $entryId;

        $this->connection->executeStatement(
            'UPDATE tl_workflow_entry SET '.implode(', ', $set).' WHERE id = ?',
            $params,
        );
    }

    private function markEntryFailed(int $entryId, string $kind, string $message): void
    {
        $this->connection->executeStatement(
            'UPDATE tl_workflow_entry SET sendError = ?, sendErrorAt = ?, tstamp = ? WHERE id = ?',
            [$this->kindLabel($kind).': '.$message, time(), time(), $entryId],
        );
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            WorkflowMailContext::KIND_INVITE => 'Einladung',
            WorkflowMailContext::KIND_REMINDER => 'Erinnerung',
            WorkflowMailContext::KIND_RESULT => 'Ergebnis-Mail',
            default => 'Versand',
        };
    }

    private function shorten(string $message): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_strlen($message) > 240 ? mb_substr($message, 0, 240).'…' : $message;
    }
}
