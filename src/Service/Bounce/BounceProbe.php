<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\NotificationDispatcher;
use Psimandl\WorkflowBundle\Service\WorkflowMailContext;

/**
 * End-to-end test of bounce detection: sends the invitation of a workflow – through its real
 * Notification Center gateway, transport, sender and envelope sender – to an address that
 * cannot exist, and watches for the bounce in the bounce mailbox.
 *
 * The address is under .invalid, a top-level domain reserved never to resolve (RFC 2606):
 * the provider's mail server accepts the mail and then reports it undeliverable (on All-Inkl
 * within about a second). A recipient on the provider's own server would not do – it is
 * refused during the SMTP dialogue, and no bounce is written.
 *
 * The test lives entirely in tl_workflow_send (kind "test", entryId 0): the dispatcher logs
 * it, the send result moves it to sent or failed, the collector marks it bounced. While it
 * waits, the collector reads the mailbox every minute ({@see BounceSchedule::TIER_TEST}).
 */
class BounceProbe
{
    public const START_STARTED = 'started';
    public const START_UNCONFIGURED = 'unconfigured';
    public const START_NO_INVITE = 'no_invite';
    public const START_FAILED = 'failed';

    /** Handed over to the mailer, not sent yet. */
    public const STATE_QUEUED = 'queued';

    /** Sent; the bounce has not been found yet. */
    public const STATE_WAITING = 'waiting';

    /** The bounce arrived in the bounce mailbox and was matched to the test mail. */
    public const STATE_BOUNCED = 'bounced';

    /** Sending failed (refused by the mail server, or never handed over). */
    public const STATE_FAILED = 'failed';

    /** No bounce within {@see BounceSchedule::TEST_WINDOW}. */
    public const STATE_TIMEOUT = 'timeout';

    /** How long the last test is shown in the overview. */
    public const SHOW_FOR = 7 * 86400;

    public function __construct(
        private readonly Connection $connection,
        private readonly NotificationDispatcher $dispatcher,
        private readonly BounceHealth $health,
    ) {
    }

    /**
     * @return array{status: string, address: string} status: one of the START_* constants
     */
    public function start(WorkflowModel $workflow): array
    {
        if (!$this->health->isConfigured()) {
            return ['status' => self::START_UNCONFIGURED, 'address' => ''];
        }

        if ((int) $workflow->ncInvite <= 0) {
            return ['status' => self::START_NO_INVITE, 'address' => ''];
        }

        $address = 'wf-bouncetest-'.bin2hex(random_bytes(6)).'@bounce-test.invalid';

        return [
            'status' => $this->dispatcher->sendTest($workflow, $address) ? self::START_STARTED : self::START_FAILED,
            'address' => $address,
        ];
    }

    /**
     * The most recent test as shown in the overview, or null when there is none in the last
     * {@see SHOW_FOR} seconds (or the table lacks the 3.6.0 columns).
     *
     * @return array{state: string, workflow: string, recipient: string, startedAt: int, sentAt: int, seconds: int, until: int, error: string, envelopeSender: string}|null
     */
    public function latest(?int $now = null): ?array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT s.state, s.recipient, s.queuedAt, s.sentAt, s.bouncedAt, s.error, s.envelopeSender, w.title
                   FROM tl_workflow_send s
                   LEFT JOIN tl_workflow w ON w.id = s.workflowId
                  WHERE s.kind = ?
                  ORDER BY s.queuedAt DESC, s.id DESC
                  LIMIT 1',
                [WorkflowMailContext::KIND_TEST],
            );
        } catch (\Throwable) {
            return null;
        }

        return false === $row ? null : self::describe($row, $now ?? time());
    }

    /**
     * Turns a test's send row into what the overview says about it. Pure, for the tests.
     *
     * @param array<string, mixed> $row
     *
     * @return array{state: string, workflow: string, recipient: string, startedAt: int, sentAt: int, seconds: int, until: int, error: string, envelopeSender: string}|null
     */
    public static function describe(array $row, int $now): ?array
    {
        $startedAt = (int) ($row['queuedAt'] ?? 0);

        if ($startedAt <= 0 || $now - $startedAt > self::SHOW_FOR) {
            return null;
        }

        $sentAt = (int) ($row['sentAt'] ?? 0);
        $bouncedAt = (int) ($row['bouncedAt'] ?? 0);
        $until = $startedAt + BounceSchedule::TEST_WINDOW;

        $state = match ((string) ($row['state'] ?? '')) {
            'bounced' => self::STATE_BOUNCED,
            'failed' => self::STATE_FAILED,
            default => $now > $until ? self::STATE_TIMEOUT : ($sentAt > 0 ? self::STATE_WAITING : self::STATE_QUEUED),
        };

        return [
            'state' => $state,
            'workflow' => (string) ($row['title'] ?? ''),
            'recipient' => (string) ($row['recipient'] ?? ''),
            'startedAt' => $startedAt,
            'sentAt' => $sentAt,
            'seconds' => self::STATE_BOUNCED === $state ? max(0, $bouncedAt - ($sentAt > 0 ? $sentAt : $startedAt)) : 0,
            'until' => $until,
            'error' => trim((string) ($row['error'] ?? '')),
            'envelopeSender' => (string) ($row['envelopeSender'] ?? ''),
        ];
    }
}
