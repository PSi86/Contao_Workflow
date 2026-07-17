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
 * "Is it configured?" is a separate, cheap question answered live from the bound DSN — no
 * cron run needed, so a site whose config did not load is flagged immediately.
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
        private readonly ?string $bounceImapDsn = null,
    ) {
    }

    /**
     * Whether a bounce mailbox DSN is present and loaded. Cheap (no I/O), so the overview can
     * ask on every render. An empty result means either "never configured" or "configured but
     * not loaded" (e.g. a Managed Edition with .env.local.php that never picked up .env.local)
     * – the application cannot tell the two apart, and both mean bounces go undetected.
     */
    public function isConfigured(): bool
    {
        return '' !== trim((string) $this->bounceImapDsn);
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

            return (string) $previous;
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
}
