<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

/**
 * One per-recipient result parsed out of a delivery status notification (DSN / bounce).
 *
 * A single bounce mail can carry several of these (one per failed recipient). The decision
 * whether a bounce is permanent is taken from {@see $action} plus the FIRST digit of the
 * SMTP status ({@see $statusClass}) only — Postfix is known to write a generic "5.0.0" even
 * when the remote MTA reported a more specific "5.1.1", so the {@see $diagnosticCode} is
 * display text, never a decision input.
 */
final class BounceReport
{
    public function __construct(
        public readonly ?string $parcelId,      // Notification-Center-Parcel-ID from the embedded original, if present
        public readonly string $recipient,      // Final-Recipient, without the "rfc822;" address-type prefix
        public readonly string $action,         // failed|delayed|delivered|relayed|expanded
        public readonly int $statusClass,       // 2|4|5 — the first digit of the Status field (0 if unknown)
        public readonly string $status,         // raw status, e.g. "5.0.0"
        public readonly string $diagnosticCode, // human-readable diagnostic, e.g. "smtp; 550 5.1.1 User unknown"
    ) {
    }

    /**
     * Permanent failure: the address is dead and must not be mailed again.
     */
    public function isHardBounce(): bool
    {
        return 'failed' === $this->action && 5 === $this->statusClass;
    }

    /**
     * Temporary problem (greylisting, mailbox full, delayed retry): the final outcome may
     * still be delivery or a later hard bounce, so it must not change the entry's state.
     */
    public function isSoftBounce(): bool
    {
        return 4 === $this->statusClass || 'delayed' === $this->action;
    }
}
