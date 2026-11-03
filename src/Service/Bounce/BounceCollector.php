<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

use Contao\CoreBundle\Cron\Cron;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Contao\CoreBundle\Exception\CronExecutionSkippedException;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Service\BackgroundActivity;
use Psimandl\WorkflowBundle\Service\CronHealth;
use Psimandl\WorkflowBundle\Service\Delivery\DeliveryRecorder;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Lock\LockFactory;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Collects asynchronous bounces (DSNs) that a "250 OK" hand-off can never reveal: the
 * receiving MTA rejects a message minutes to hours later and mails a delivery status
 * notification back to the return path. Nothing in the application ever sees it — it just
 * sits in the bounce mailbox. This cron reads that mailbox, parses each message with
 * {@see BounceParser} and correlates hard bounces back to their tl_workflow_send row (and,
 * denormalized, the entry) by parcel id.
 *
 * Configuration is an IMAP DSN in .env.local, e.g.
 *   WORKFLOW_BOUNCE_IMAP_DSN=imap://noreply%40example.com:PASS@wXXXX.kasserver.com:993?ssl=true
 * When it is empty the collector is a no-op — the feature is simply off.
 *
 * How often the mailbox is read is decided by {@see BounceSchedule}: the job is called every
 * minute and reads the mailbox only when the current tier says so (every 2 minutes right after
 * a send, every 15 while invitations are unanswered, every 2 hours otherwise). The back end's
 * "Jetzt prüfen" button reads it immediately ({@see checkNow()}); a lock keeps that and a cron
 * run from reading the same messages at the same time.
 *
 * The cron must never throw: an uncaught exception here would abort the remaining Contao
 * cron jobs. Every failure is logged and swallowed. The one deliberate exception is
 * CronExecutionSkippedException, which tells Contao to give the run back (see __invoke()).
 */
#[AsCronJob('minutely')]
class BounceCollector
{
    private const PROCESSED_FOLDER = 'INBOX/Processed';

    // Shared hosting has tight time limits; cap the work per run. Anything left over is
    // picked up on the next run.
    private const BATCH_LIMIT = 100;

    // Long enough for a full batch over a slow connection; released as soon as a run ends.
    private const LOCK_TTL = 300;

    public function __construct(
        private readonly BounceParser $parser,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly BounceHealth $health,
        private readonly DeliveryRecorder $recorder,
        private readonly BounceSchedule $schedule,
        private readonly CronHealth $cronHealth,
        private readonly LockFactory $lockFactory,
        private readonly ?string $bounceImapDsn = null,
        private readonly ?BackgroundActivity $activity = null,
    ) {
    }

    /**
     * The minutely cron entry point.
     *
     * Started by a page view while a real cron is alive, the run is handed back to Contao
     * (CronExecutionSkippedException restores the job's last-run time) instead of logging in
     * to IMAP inside a visitor's request; the next call of the real cron does it.
     */
    public function __invoke(string $scope = Cron::SCOPE_CLI): void
    {
        if ($this->cronHealth->shouldDefer($scope)) {
            throw new CronExecutionSkippedException();
        }

        if (!$this->schedule->isDue($this->health->read()['checkedAt'])) {
            return;
        }

        $this->checkNow();
    }

    /**
     * Reads the mailbox now, applies the bounces and records the verdict. Used by the cron and
     * the back end's "Jetzt prüfen" button. Returns null when another run holds the lock.
     */
    public function checkNow(): ?BounceOutcome
    {
        $lock = $this->lockFactory->createLock('psimandl_workflow_bounce_collect', self::LOCK_TTL);

        if (!$lock->acquire()) {
            return null;
        }

        $started = microtime(true);

        try {
            $outcome = $this->collect(trim((string) $this->bounceImapDsn));
            $this->reconcileHealth($outcome);

            return $outcome;
        } finally {
            $lock->release();
            // Attributes a slow request to the IMAP check (see RequestTimingListener).
            $this->activity?->record('Bounce-Abruf', microtime(true) - $started);
        }
    }

    /**
     * Persists the run's verdict and logs ONLY when it changed since the last run. The always-
     * visible signal is the overview banner (fed from the same stored verdict); the system log
     * is the audit trail of transitions, so logging every 15 minutes would just be noise. The
     * severity matches the state: a set-but-unreachable mailbox is an ERROR that needs fixing,
     * a missing DSN is a WARNING (the feature is simply off), and a recovered mailbox is an
     * informational note.
     */
    public function reconcileHealth(BounceOutcome $outcome): void
    {
        $previous = $this->health->record($outcome->state, $outcome->message);

        if ($previous === $outcome->state) {
            return;
        }

        match ($outcome->state) {
            BounceHealth::STATE_UNCONFIGURED => $this->systemLog(
                'Bounce-Postfach nicht konfiguriert: WORKFLOW_BOUNCE_IMAP_DSN ist leer oder wurde nicht geladen. '
                .'In diesem Zustand werden Zustellfehler und Bounces nicht erkannt.',
                LogLevel::WARNING,
            ),
            BounceHealth::STATE_CONFIG_ERROR => $this->systemLog(
                'Bounce-Postfach nicht erreichbar: '.$outcome->message.' '
                .'Zustellfehler und Bounces werden derzeit nicht erkannt – bitte WORKFLOW_BOUNCE_IMAP_DSN prüfen.',
                LogLevel::ERROR,
            ),
            // Only worth a line as a RECOVERY: null means the first-ever run was already fine.
            BounceHealth::STATE_OK => null === $previous
                ? null
                : $this->systemLog('Bounce-Postfach wieder erreichbar.', LogLevel::INFO),
            default => null,
        };
    }

    /**
     * Reads the bounce mailbox and applies the bounces. Shared by the cron ({@see __invoke()})
     * and the workflow:bounce:collect command. The optional reporter surfaces each step on the
     * console for diagnosis; $dryRun inspects the mailbox without moving mails or writing to
     * the database.
     *
     * Never throws — every failure becomes the returned {@see BounceOutcome} (and, if given, is
     * reported). The caller decides what to persist/log about it ({@see reconcileHealth()}),
     * which keeps this method free of the "log once per state change" concern.
     *
     * @param (\Closure(string, string): void)|null $report ($level in info|comment|error, $message)
     */
    public function collect(string $dsn, bool $dryRun = false, ?\Closure $report = null): BounceOutcome
    {
        $note = static function (string $level, string $message) use ($report): void {
            if (null !== $report) {
                $report($level, $message);
            }
        };

        if ('' === $dsn) {
            $note('comment', 'Kein Bounce-Postfach konfiguriert (WORKFLOW_BOUNCE_IMAP_DSN ist leer).');

            return BounceOutcome::unconfigured();
        }

        try {
            $config = $this->configFromDsn($dsn);
        } catch (\Throwable $e) {
            $note('error', 'Ungültige DSN: '.$e->getMessage());

            return BounceOutcome::configError('Ungültige DSN ('.$e->getMessage().').');
        }

        $total = 0;
        $hard = 0;
        $former = 0;
        $soft = 0;
        $unmatched = 0;
        $skipped = 0;

        try {
            $client = (new ClientManager())->make($config);
            $client->connect();
            $note('info', 'Verbunden mit '.$config['host'].':'.$config['port'].' (Verschlüsselung: '.($config['encryption'] ?: 'keine').').');

            $inbox = $client->getFolder('INBOX');

            if (null === $inbox) {
                $note('error', 'INBOX nicht gefunden.');
                $client->disconnect();

                return BounceOutcome::configError('INBOX nicht gefunden.');
            }

            if (!$dryRun) {
                $this->ensureProcessedFolder($client);
            }

            // whereAll() forces "UID SEARCH ALL": without an explicit criterion webklex sends
            // an empty search, which strict IMAP servers (All-Inkl/Dovecot) reject with
            // "BAD ... Missing search parameters".
            $messages = $inbox->messages()->whereAll()->limit(self::BATCH_LIMIT)->setFetchBody(true)->leaveUnread()->get();
            $total = \count($messages);
            $note('info', $total.' Nachricht(en) in der INBOX'.($dryRun ? ' (Testlauf – es wird nichts verändert)' : '').'.');

            foreach ($messages as $message) {
                try {
                    // Correlate first; a hard bounce is idempotent, so even if the move below
                    // fails and the message is seen again next run, no harm is done.
                    $reports = $this->parser->parse($this->rawMessage($message));

                    if ([] === $reports) {
                        ++$skipped;
                        $note('comment', 'Übersprungen: keine Unzustellbarkeitsmeldung (kein DSN).');

                        if (!$dryRun) {
                            $message->move(self::PROCESSED_FOLDER, true);
                        }

                        continue;
                    }

                    foreach ($reports as $bounce) {
                        $note('info', \sprintf(
                            '%s — %s, Status %s, Parcel %s%s',
                            $bounce->recipient,
                            $bounce->action,
                            $bounce->status,
                            $bounce->parcelId ?? '—',
                            $bounce->isHardBounce() ? ' [HARD]' : ($bounce->isSoftBounce() ? ' [soft]' : ''),
                        ));

                        if ($dryRun) {
                            if ($bounce->isHardBounce()) {
                                $send = $this->locateSend($bounce);
                                $note($send ? 'info' : 'error', '  → '.($send ? 'würde Eintrag '.$send['entryId'].' als unzustellbar markieren' : 'keine passende Versandzeile gefunden – nicht zuordenbar'));
                            }

                            continue;
                        }

                        match ($this->applyReport($bounce)) {
                            'hard' => ++$hard,
                            'former' => ++$former,
                            'unmatched' => ++$unmatched,
                            'soft' => ++$soft,
                            default => null,
                        };
                    }

                    if (!$dryRun) {
                        $message->move(self::PROCESSED_FOLDER, true);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Workflow bounce collector: could not process a message ('.$e->getMessage().').');
                    $note('error', 'Fehler bei einer Nachricht: '.$e->getMessage());
                    $this->systemLog('Bounce-Collector: Fehler bei einer Nachricht – '.$e->getMessage(), LogLevel::ERROR);
                }
            }

            $client->disconnect();
            $note('info', 'Fertig.');

            // Only log a run that actually did something. An empty mailbox is the normal
            // state, so a line every 15 minutes would bury the entries that matter — and the
            // system log is the one diagnostic channel available without CLI access. A run
            // with messages IS worth a line even when none carried a DSN: those messages were
            // still moved out of the INBOX. Errors are logged regardless (see the catch
            // blocks), and "workflow:bounce:collect" reports every step on demand, so
            // liveness stays checkable without the heartbeat.
            if (!$dryRun && $total > 0) {
                $this->systemLog(\sprintf(
                    'Bounce-Postfach geprüft (%s:%d): %d Nachricht(en), %d hart markiert, %d für eine inzwischen geänderte Adresse, %d weich, %d ohne Zuordnung, %d ohne DSN.',
                    $config['host'],
                    $config['port'],
                    $total,
                    $hard,
                    $former,
                    $soft,
                    $unmatched,
                    $skipped,
                ));
            }

            return BounceOutcome::ok([
                'total' => $total,
                'hard' => $hard,
                'former' => $former,
                'soft' => $soft,
                'unmatched' => $unmatched,
                'skipped' => $skipped,
            ]);
        } catch (\Throwable $e) {
            $note('error', 'IMAP-Fehler: '.$e->getMessage());

            return BounceOutcome::configError('IMAP-Fehler ('.$e->getMessage().').');
        }
    }

    /**
     * Writes to the Contao system log (tl_log), which is visible in the back end under
     * System → System log without any CLI access — the only diagnostic channel on hosting
     * where the console is unavailable. Unlike the default app log (fingers_crossed, only
     * flushed on an error), these entries are recorded immediately.
     *
     * The PSR level sets the severity and the shown category: an error is filed under ERROR,
     * a warning under GENERAL (so a missing DSN does not read as a fault), everything else
     * under CRON.
     */
    private function systemLog(string $message, string $level = LogLevel::INFO): void
    {
        $action = match ($level) {
            LogLevel::ERROR => ContaoContext::ERROR,
            LogLevel::WARNING => ContaoContext::GENERAL,
            default => ContaoContext::CRON,
        };

        $this->logger->log($level, $message, ['contao' => new ContaoContext(self::class.'::collect', $action)]);
    }

    /**
     * Parses one raw message and applies its bounces to the send log. Public and free of any
     * IMAP dependency so it can be unit-tested against fixtures and a real database.
     */
    public function handleRaw(string $raw): void
    {
        foreach ($this->parser->parse($raw) as $report) {
            $this->applyReport($report);
        }
    }

    /**
     * @return string one of hard|former|unmatched|soft|none
     */
    private function applyReport(BounceReport $report): string
    {
        if ($report->isHardBounce()) {
            return $this->recordHardBounce($report);
        }

        if ($report->isSoftBounce()) {
            // A temporary problem (greylisting, mailbox full, delayed retry): the final
            // outcome is still open, so the state is left untouched — only logged.
            $this->logger->info(\sprintf(
                'Workflow bounce collector: soft bounce for %s (status %s), state left unchanged.',
                $report->recipient,
                $report->status,
            ));

            return 'soft';
        }

        return 'none';
    }

    /**
     * @return string hard (address blocked) | former (the entry's address has changed since) |
     *                unmatched (no send row found)
     */
    private function recordHardBounce(BounceReport $report): string
    {
        $send = $this->locateSend($report);

        if (null === $send) {
            $this->logger->warning(\sprintf(
                'Workflow bounce collector: hard bounce for %s could not be correlated (parcel id %s).',
                $report->recipient,
                $report->parcelId ?? '—',
            ));
            $this->systemLog(
                \sprintf('Harter Bounce für %s konnte keinem Versand zugeordnet werden (Parcel-ID %s).', $report->recipient, $report->parcelId ?? '—'),
                LogLevel::ERROR,
            );

            return 'unmatched';
        }

        $now = time();
        $code = $this->shorten('' !== $report->diagnosticCode ? $report->diagnosticCode : 'Status '.$report->status);

        // Move the send-log row to its terminal "bounced" state. Idempotent.
        $this->connection->executeStatement(
            "UPDATE tl_workflow_send SET state = 'bounced', bounceCode = ?, bouncedAt = ?, tstamp = ? WHERE id = ?",
            [$code, $now, $now, (int) $send['id']],
        );

        // Block the address: excluded from further invitation and reminder runs and shown as
        // "Unzustellbar" – but only while the entry still has the address the mail went to. A
        // late bounce for an address the admin has corrected since must not block the new one.
        $recipient = '' !== $send['recipient'] ? $send['recipient'] : $report->recipient;

        if (!$this->recorder->blockAddress($send['entryId'], $recipient, $report->recipient.' – '.$code)) {
            $this->systemLog(\sprintf(
                'Bounce für %s (Eintrag %d) betrifft eine inzwischen geänderte Adresse – keine Sperre (%s).',
                $report->recipient,
                $send['entryId'],
                $code,
            ));

            return 'former';
        }

        $this->systemLog(\sprintf('Unzustellbar (Bounce): Eintrag %d – %s (%s).', $send['entryId'], $report->recipient, $code));

        return 'hard';
    }

    /**
     * @return array{id: int, entryId: int, recipient: string}|null
     */
    private function locateSend(BounceReport $report): ?array
    {
        if (null !== $report->parcelId) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, entryId, recipient FROM tl_workflow_send WHERE parcelId = ? LIMIT 1',
                [$report->parcelId],
            );

            if (false !== $row) {
                return ['id' => (int) $row['id'], 'entryId' => (int) $row['entryId'], 'recipient' => (string) $row['recipient']];
            }

            // Parcel id present but unknown (purged, or a mail we did not send): do not guess.
            return null;
        }

        // Rule 5: no parcel id (the MTA truncated the original past the header). Fall back to
        // the most recent successfully sent mail to this recipient, and record that we guessed.
        $row = $this->connection->fetchAssociative(
            "SELECT id, entryId, recipient FROM tl_workflow_send WHERE recipient = ? AND state = 'sent' ORDER BY sentAt DESC, id DESC LIMIT 1",
            [$report->recipient],
        );

        if (false === $row) {
            return null;
        }

        $this->logger->info(\sprintf(
            'Workflow bounce collector: hard bounce for %s correlated by recipient (no parcel id in the bounce).',
            $report->recipient,
        ));

        return ['id' => (int) $row['id'], 'entryId' => (int) $row['entryId'], 'recipient' => (string) $row['recipient']];
    }

    /**
     * @return array<string, mixed>
     */
    private function configFromDsn(string $dsn): array
    {
        $parts = parse_url($dsn);

        if (false === $parts || !isset($parts['host'])) {
            throw new \InvalidArgumentException('the DSN has no host');
        }

        $query = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $encryption = 'ssl';

        if (isset($query['tls']) && filter_var($query['tls'], FILTER_VALIDATE_BOOLEAN)) {
            $encryption = 'tls';
        } elseif (isset($query['ssl']) && !filter_var($query['ssl'], FILTER_VALIDATE_BOOLEAN)) {
            $encryption = false;
        }

        $validateCert = !isset($query['validate_cert']) || filter_var($query['validate_cert'], FILTER_VALIDATE_BOOLEAN);

        return [
            'host' => $parts['host'],
            'port' => (int) ($parts['port'] ?? 993),
            'protocol' => 'imap',
            'encryption' => $encryption,
            'validate_cert' => $validateCert,
            'username' => isset($parts['user']) ? urldecode($parts['user']) : '',
            'password' => isset($parts['pass']) ? urldecode($parts['pass']) : '',
            'authentication' => null,
            // Fail fast instead of hanging the (web) cron on an unreachable mailbox.
            'timeout' => 20,
        ];
    }

    private function ensureProcessedFolder(object $client): void
    {
        try {
            if (null === $client->getFolderByPath(self::PROCESSED_FOLDER, false, true)) {
                $client->createFolder(self::PROCESSED_FOLDER);
            }
        } catch (\Throwable $e) {
            // If the folder cannot be ensured, the per-message move will fail and be logged.
            $this->logger->warning('Workflow bounce collector: could not ensure the Processed folder ('.$e->getMessage().').');
        }
    }

    private function rawMessage(Message $message): string
    {
        $headers = rtrim($message->getHeader()?->raw ?? '', "\r\n");

        return $headers."\r\n\r\n".$message->getRawBody();
    }

    private function shorten(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 240 ? mb_substr($text, 0, 240).'…' : $text;
    }
}
