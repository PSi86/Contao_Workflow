<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Delivery;

use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Service\WorkflowMailContext;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;

/**
 * Writes the outcome of a mail onto its entry – the one place that turns "sent", "failed" or
 * "bounced" into the fields the back end shows and the mail runs act on.
 *
 * Used by every path that learns an outcome: the asynchronous send result
 * (WorkflowMailResultListener), a failure while handing the mail over (NotificationDispatcher),
 * a bounce from the mailbox (BounceCollector) and a send that never produced any result
 * (SendLogReconciler).
 *
 * Every write is bound to the address the mail went to. If the entry's address has been changed
 * since, the outcome belongs to the old address and leaves the entry alone: a late "sent" must
 * not mark the new address as invited, and a late bounce must not block it. The send log keeps
 * the outcome either way.
 */
class DeliveryRecorder
{
    // Binds an UPDATE to the recipient of the mail. An empty recipient (rows written before the
    // send log kept one) cannot be checked and is accepted.
    private const SAME_ADDRESS = "(? = '' OR LOWER(TRIM(email)) = LOWER(TRIM(?)))";

    public function __construct(
        private readonly Connection $connection,
        private readonly SendFailureClassifier $classifier,
    ) {
    }

    /**
     * A mail was handed over successfully. Clears an earlier failure; an invitation advances the
     * entry from "imported" to "invited", a reminder records the send time.
     */
    public function delivered(int $entryId, string $kind, string $recipient): void
    {
        // A mail without an entry (end-to-end test) has nothing to record here.
        if ($entryId <= 0) {
            return;
        }

        $now = time();
        $set = "sendError = '', sendErrorAt = 0, tstamp = ?";
        $params = [$now];

        if (WorkflowMailContext::KIND_INVITE === $kind) {
            // Only an entry still at "imported" advances; the CASE keeps this a single UPDATE.
            $set .= ', sentAt = CASE WHEN status = ? THEN ? ELSE sentAt END, status = CASE WHEN status = ? THEN ? ELSE status END';
            array_push($params, WorkflowStatus::STATUS_IMPORTED, $now, WorkflowStatus::STATUS_IMPORTED, WorkflowStatus::STATUS_INVITED);
        } elseif (WorkflowMailContext::KIND_REMINDER === $kind) {
            $set .= ', sentAt = ?';
            $params[] = $now;
        }

        array_push($params, $entryId, $recipient, $recipient);

        $this->connection->executeStatement(
            'UPDATE tl_workflow_entry SET '.$set.' WHERE id = ? AND '.self::SAME_ADDRESS,
            $params,
        );
    }

    /**
     * A mail could not be sent. A permanent rejection of the recipient blocks the address (see
     * SendFailureClassifier); anything else is stored as a send error, shown in the overview
     * and retried by the next run.
     *
     * @return bool true when the address was blocked
     */
    public function failed(int $entryId, string $kind, string $recipient, \Throwable|string $error): bool
    {
        if ($entryId <= 0) {
            return false;
        }

        $exception = $error instanceof \Throwable ? $error : null;
        $rejection = $this->classifier->permanentAddressRejection($exception);

        if (null !== $rejection) {
            return $this->blockAddress($entryId, $recipient, 'Vom Mailserver abgelehnt: '.$rejection);
        }

        $message = $exception?->getMessage() ?? (string) $error;

        $this->connection->executeStatement(
            'UPDATE tl_workflow_entry SET sendError = ?, sendErrorAt = ?, tstamp = ? WHERE id = ? AND '.self::SAME_ADDRESS,
            [$this->kindLabel($kind).': '.self::shorten('' !== trim($message) ? $message : 'Unbekannter Fehler'), time(), time(), $entryId, $recipient, $recipient],
        );

        return false;
    }

    /**
     * Marks the entry's address as undeliverable: excluded from invitation and reminder runs
     * (EntryModel::findByWorkflowAndStatus) until the address is changed, shown as
     * "Unzustellbar" with $info as the reason. Kept across a participant reset.
     *
     * @return bool false when the entry's address is no longer $recipient (nothing was blocked)
     */
    public function blockAddress(int $entryId, string $recipient, string $info): bool
    {
        if ($entryId <= 0) {
            return false;
        }

        return $this->connection->executeStatement(
            "UPDATE tl_workflow_entry SET bounceHard = '1', bounceInfo = ?, tstamp = ? WHERE id = ? AND ".self::SAME_ADDRESS,
            [self::shorten($info), time(), $entryId, $recipient, $recipient],
        ) > 0;
    }

    public static function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 240 ? mb_substr($text, 0, 240).'…' : $text;
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
}
