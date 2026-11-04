<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

use Doctrine\DBAL\Connection;

/**
 * Remembers the bounce collector's last verdict across runs, and knows whether the mailbox
 * is configured at all.
 *
 * The collector runs in the background every 15 minutes; the back-end overview renders on
 * demand. So the overview cannot ask "is the mailbox reachable right now" without persisting
 * what the last run found — connecting to IMAP on a page load is exactly the slow network
 * call that must stay off a hot path. This service is that memory: one row in
 * tl_workflow_bounce_health, upserted by {@see BounceCollector::reconcileHealth()} and read
 * by the overview.
 *
 * "Is it configured?" is a separate question answered live by {@see BounceMailbox} (the bound
 * DSN, or the back-end settings – one small query) — no cron run needed, so a site whose
 * config did not load is flagged immediately.
 *
 * Every database access is guarded: right after a deploy the table may not exist yet (the
 * schema diff runs on contao:migrate), and the collector's contract is to never throw.
 */
class BounceHealth
{
    /** The mailbox was reached and read. */
    public const STATE_OK = 'ok';

    /** A DSN is set, but the mailbox could not be reached (bad credentials, host, folder …). */
    public const STATE_CONFIG_ERROR = 'error';

    /** No DSN is set (or it did not load) – the feature is off and bounces go unnoticed. */
    public const STATE_UNCONFIGURED = 'unconfigured';

    /** Single-row store: the health is global, not per-workflow. */
    private const ROW_ID = 1;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?BounceMailbox $mailbox = null,
    ) {
    }

    /**
     * Whether a usable bounce mailbox is set up. Cheap (no IMAP), so the overview can ask on
     * every render. False means "never set up", "set in .env.local but not loaded" (e.g. a
     * Managed Edition with .env.local.php that never picked up .env.local) or "stored password
     * no longer readable" ({@see BounceMailbox::passwordUnreadable()}) – all of them mean
     * bounces go undetected.
     */
    public function isConfigured(): bool
    {
        return null !== $this->mailbox && '' !== $this->mailbox->imapDsn();
    }

    /**
     * Persists the latest run's state and returns the PREVIOUS state (null if never recorded),
     * so the caller logs only on a real change instead of once every 15 minutes.
     */
    public function record(string $state, string $message = ''): ?string
    {
        try {
            $previous = $this->connection->fetchOne(
                'SELECT state FROM tl_workflow_bounce_health WHERE id = ?',
                [self::ROW_ID],
            );

            // SELECT-then-INSERT/UPDATE instead of an "ON DUPLICATE KEY" upsert: it is portable
            // (the tests run on SQLite) and we already hold the previous state for the return.
            if (false === $previous) {
                $this->connection->insert('tl_workflow_bounce_health', [
                    'id'      => self::ROW_ID,
                    'tstamp'  => time(),
                    'state'   => $state,
                    'message' => $message,
                ]);

                return null;
            }

            $this->connection->update(
                'tl_workflow_bounce_health',
                ['tstamp' => time(), 'state' => $state, 'message' => $message],
                ['id' => self::ROW_ID],
            );

            // The row may have been created by recordScan() during this very first run; an
            // empty state still means "no verdict recorded yet".
            return '' === (string) $previous ? null : (string) $previous;
        } catch (\Throwable) {
            // Table not there yet, or the DB is momentarily unavailable: the run's real work
            // (applying bounces) already happened; losing the health note is not worth throwing
            // out of a cron.
            return null;
        }
    }

    /**
     * The last recorded verdict, or an empty default when nothing has been recorded yet (or the
     * table does not exist). An empty state means "no error to report".
     *
     * @return array{state: string, message: string, checkedAt: int}
     */
    public function read(): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT state, message, tstamp FROM tl_workflow_bounce_health WHERE id = ?',
                [self::ROW_ID],
            );
        } catch (\Throwable) {
            $row = false;
        }

        if (false === $row) {
            return ['state' => '', 'message' => '', 'checkedAt' => 0];
        }

        return [
            'state'     => (string) $row['state'],
            'message'   => (string) ($row['message'] ?? ''),
            'checkedAt' => (int) $row['tstamp'],
        ];
    }

    /**
     * Where the collector stopped reading the INBOX (the watermark: the mailbox, its
     * UIDVALIDITY and the highest UID already looked at) and how many messages the last run
     * left in the INBOX because they are not bounces of workflow mails.
     *
     * Zeros when nothing is recorded yet, or when the columns do not exist yet (the database
     * was not updated after the bundle update): the collector then reads the whole INBOX.
     *
     * @return array{mailbox: string, uidValidity: int, lastUid: int, foreignCount: int}
     */
    public function readScan(): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT scanMailbox, uidValidity, lastUid, foreignCount FROM tl_workflow_bounce_health WHERE id = ?',
                [self::ROW_ID],
            );
        } catch (\Throwable) {
            $row = false;
        }

        if (false === $row) {
            return ['mailbox' => '', 'uidValidity' => 0, 'lastUid' => 0, 'foreignCount' => 0];
        }

        return [
            'mailbox'      => (string) $row['scanMailbox'],
            'uidValidity'  => (int) $row['uidValidity'],
            'lastUid'      => (int) $row['lastUid'],
            'foreignCount' => (int) $row['foreignCount'],
        ];
    }

    /**
     * Stores the watermark, and – at the end of a run – the number of messages left in the
     * INBOX. Never throws, for the same reason as {@see record()}; a lost watermark only means
     * the next run looks at the same messages again, which is harmless.
     */
    public function recordScan(string $mailbox, int $uidValidity, int $lastUid, ?int $foreignCount = null): void
    {
        $values = ['scanMailbox' => $mailbox, 'uidValidity' => $uidValidity, 'lastUid' => $lastUid];

        if (null !== $foreignCount) {
            $values['foreignCount'] = $foreignCount;
        }

        try {
            $exists = false !== $this->connection->fetchOne(
                'SELECT id FROM tl_workflow_bounce_health WHERE id = ?',
                [self::ROW_ID],
            );

            if ($exists) {
                $this->connection->update('tl_workflow_bounce_health', $values, ['id' => self::ROW_ID]);
            } else {
                $this->connection->insert('tl_workflow_bounce_health', ['id' => self::ROW_ID] + $values);
            }
        } catch (\Throwable) {
            // See the doc comment.
        }
    }
}
