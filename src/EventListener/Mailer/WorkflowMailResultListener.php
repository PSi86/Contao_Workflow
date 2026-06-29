<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\Mailer;

use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Service\WorkflowMailContext;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Terminal42\NotificationCenterBundle\Event\AsynchronousReceiptEvent;

/**
 * Updates a workflow entry based on the *actual* mail send result instead of optimistically
 * at dispatch time. The Notification Center reports the real (often asynchronous) result via
 * {@see AsynchronousReceiptEvent}, carrying the parcel id that {@see NotificationDispatcher}
 * stored on the entry at dispatch. For the rare synchronous transport the event fires within
 * the send call before that id is persisted, so the live {@see WorkflowMailContext} is used
 * as a fallback.
 *
 *  - On success: a recorded send error is cleared. An invitation advances the entry from
 *    "imported" to "invited"; a reminder records the send time; a result mail changes nothing.
 *  - On failure: the workflow step is left untouched (a failed send is never counted as done)
 *    and the error is stored so the back end dashboard can flag the entry.
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

        // Async: the entry stored this parcel id at dispatch. Sync: the event fires within
        // the send call before persisting, so fall back to the still-active context.
        $row = $this->connection->fetchAssociative(
            'SELECT id, status, sendKind FROM tl_workflow_entry WHERE sendParcelId = ? LIMIT 1',
            [$identifier],
        );

        if (false !== $row) {
            $entryId = (int) $row['id'];
            $kind = (string) $row['sendKind'];
            $statusValue = (int) $row['status'];
        } elseif ($this->context->isActive()) {
            $entryId = (int) $this->context->getEntryId();
            $kind = (string) $this->context->getKind();
            $statusValue = (int) $this->connection->fetchOne('SELECT status FROM tl_workflow_entry WHERE id = ?', [$entryId]);
        } else {
            return;
        }

        if ($receipt->wasDelivered()) {
            $this->onDelivered($entryId, $kind, $statusValue);
        } else {
            $this->onFailed($entryId, $kind, $receipt->getException());
        }

        // Correlation consumed.
        $this->connection->executeStatement(
            "UPDATE tl_workflow_entry SET sendParcelId = '', sendKind = '' WHERE id = ?",
            [$entryId],
        );
    }

    private function onDelivered(int $entryId, string $kind, int $statusValue): void
    {
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

    private function onFailed(int $entryId, string $kind, ?\Throwable $error): void
    {
        $message = null !== $error ? $error->getMessage() : 'Unbekannter Fehler';

        $this->connection->executeStatement(
            'UPDATE tl_workflow_entry SET sendError = ?, sendErrorAt = ?, tstamp = ? WHERE id = ?',
            [$this->kindLabel($kind).': '.$this->shorten($message), time(), time(), $entryId],
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
