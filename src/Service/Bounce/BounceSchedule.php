<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

use Doctrine\DBAL\Connection;

/**
 * Decides how often the bounce mailbox is read, depending on what can be in it.
 *
 * Bounces follow mails: most arrive within minutes of a send, late ones (a receiving server
 * that kept retrying) within days. A fixed interval is either too slow right after a send or
 * wasteful during the weeks nothing happens. Four tiers:
 *
 *  - test:        an end-to-end test ({@see BounceProbe}) waits for its bounce, for at most
 *                 30 minutes → every minute;
 *  - recent:      a mail was sent or queued in the last 30 minutes → every 2 minutes;
 *  - outstanding: invitations are out and unanswered → every 15 minutes;
 *  - idle:        nothing is outstanding (nothing sent yet, or everybody answered) → every
 *                 2 hours, which still picks up a late bounce of an earlier mail.
 *
 * The collector is registered as a minutely cron job and asks isDue() on each call, so the
 * effective interval is the tier's, never finer than the cron's own (with a three-minute
 * server cron, "every 2 minutes" becomes every 3).
 */
class BounceSchedule
{
    public const TIER_TEST = 'test';

    public const TIER_RECENT = 'recent';

    public const TIER_OUTSTANDING = 'outstanding';

    public const TIER_IDLE = 'idle';

    public const RECENT_WINDOW = 1800;

    /** How long an end-to-end test waits for its bounce. */
    public const TEST_WINDOW = 1800;

    private const INTERVALS = [
        self::TIER_TEST        => 60,
        self::TIER_RECENT      => 120,
        self::TIER_OUTSTANDING => 900,
        self::TIER_IDLE        => 7200,
    ];

    /**
     * The cron is called roughly every minute, a few seconds either way. Without this slack a
     * check at 09:40:03 would miss the call at 09:42:01 and wait for 09:43.
     */
    private const SLACK = 30;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{tier: string, interval: int, lastMailAt: int, outstanding: int}
     */
    public function current(?int $now = null): array
    {
        $now ??= time();

        try {
            $lastMailAt = (int) $this->connection->fetchOne(
                'SELECT MAX(CASE WHEN sentAt > queuedAt THEN sentAt ELSE queuedAt END) FROM tl_workflow_send',
            );

            // Invited (or further) but not answered, and not known to be undeliverable.
            $outstanding = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM tl_workflow_entry WHERE status >= 1 AND respondedAt = 0 AND bounceHard = ''",
            );

        } catch (\Throwable) {
            // Tables not there yet: check at the most relaxed pace rather than not at all.
            $lastMailAt = 0;
            $outstanding = 0;
        }

        try {
            // An end-to-end test still waiting for its bounce. Asked separately, so a problem
            // here cannot take the other tiers down with it.
            $testAt = (int) $this->connection->fetchOne(
                "SELECT MAX(queuedAt) FROM tl_workflow_send WHERE kind = 'test' AND state IN ('queued', 'sent')",
            );
        } catch (\Throwable) {
            $testAt = 0;
        }

        $tier = match (true) {
            $testAt > 0 && $now - $testAt <= self::TEST_WINDOW => self::TIER_TEST,
            $lastMailAt > 0 && $now - $lastMailAt <= self::RECENT_WINDOW => self::TIER_RECENT,
            $outstanding > 0 => self::TIER_OUTSTANDING,
            default => self::TIER_IDLE,
        };

        return [
            'tier'        => $tier,
            'interval'    => self::INTERVALS[$tier],
            'lastMailAt'  => $lastMailAt,
            'outstanding' => $outstanding,
        ];
    }

    /**
     * Whether the mailbox should be read now, given when it was last read (0 = never).
     */
    public function isDue(int $lastCheckAt, ?int $now = null): bool
    {
        $now ??= time();

        return $lastCheckAt <= 0 || $now - $lastCheckAt >= $this->current($now)['interval'] - self::SLACK;
    }
}
