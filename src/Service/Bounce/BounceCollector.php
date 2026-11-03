<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

use Contao\CoreBundle\Cron\Cron;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Contao\CoreBundle\Exception\CronExecutionSkippedException;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
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
 * Since 3.6.0 the collector moves only bounces of workflow mails to INBOX/Processed and leaves
 * everything else in the INBOX, where a person can see it; the overview shows how many such
 * messages there are. A watermark makes sure each message is looked at exactly once.
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
     * Only bounces of workflow mails are moved to INBOX/Processed; everything else stays in the
     * INBOX for a person to see ({@see handleRaw()}). Each message is looked at exactly once:
     * the watermark (mailbox, UIDVALIDITY, highest UID looked at) is advanced after every
     * message, and a run reads only messages above it. $trackProgress is false when the
     * command tests a different mailbox (--dsn): then the whole INBOX is read and the
     * configured mailbox's watermark is left alone.
     *
     * Never throws — every failure becomes the returned {@see BounceOutcome} (and, if given, is
     * reported). The caller decides what to persist/log about it ({@see reconcileHealth()}),
     * which keeps this method free of the "log once per state change" concern.
     *
     * @param (\Closure(string, string): void)|null $report ($level in info|comment|error, $message)
     */
    public function collect(string $dsn, bool $dryRun = false, ?\Closure $report = null, bool $trackProgress = true): BounceOutcome
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

        $counts = ['total' => 0, 'hard' => 0, 'former' => 0, 'soft' => 0, 'unmatched' => 0, 'skipped' => 0, 'moved' => 0, 'kept' => 0, 'inbox' => 0];
        $persist = $trackProgress && !$dryRun;

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

            // STATUS before anything selects the INBOX: clients must not use it on the
            // selected mailbox. It gives the message count, UIDVALIDITY and the next UID
            // without fetching anything.
            $status = $inbox->status();
            $inboxCount = (int) ($status['messages'] ?? 0);
            $uidValidity = (int) ($status['uidvalidity'] ?? 0);
            $uidNext = (int) ($status['uidnext'] ?? 0);

            $mailbox = $this->mailboxKey($config);
            $lastUid = $trackProgress ? self::resumeAfter($this->health->readScan(), $mailbox, $uidValidity) : 0;

            if (!$dryRun) {
                $this->ensureProcessedFolder($client);
            }

            $messages = [];
            $pending = 0;

            // Fetch only when there is something above the watermark. This also keeps an empty
            // INBOX from being asked for UIDs at all.
            if ($inboxCount > 0 && ($uidNext <= 0 || $uidNext - 1 > $lastUid)) {
                $messages = $inbox->messages()->limit(self::BATCH_LIMIT)->setFetchBody(true)->leaveUnread()->getByUidGreater($lastUid);
                $pending = (int) ($messages->total() ?? \count($messages));
            }

            $note('info', \sprintf(
                '%d Nachricht(en) in der INBOX, davon %d neu seit der letzten Prüfung (UID > %d)%s.',
                $inboxCount,
                $pending,
                $lastUid,
                $dryRun ? ' – Testlauf, es wird nichts verändert' : '',
            ));

            $own = 0;

            foreach ($messages as $message) {
                $uid = (int) $message->getUid();

                try {
                    $verdict = $this->handleRaw($this->rawMessage($message), $dryRun, $note);
                } catch (DbalException $e) {
                    // Database trouble is usually passing: stop here and look at this message
                    // again on the next run (the watermark stays below it).
                    $this->logMessageError($note, 'Datenbankfehler, Nachricht wird beim nächsten Abruf erneut geprüft – '.$e->getMessage());
                    break;
                } catch (\Throwable $e) {
                    // An unreadable message would fail again on every run: leave it in the INBOX
                    // for a person and move on.
                    $this->logMessageError($note, 'Nachricht nicht lesbar, bleibt im Posteingang – '.$e->getMessage());
                    $verdict = ['own' => false, 'outcomes' => []];
                }

                ++$counts['total'];

                foreach ($verdict['outcomes'] as $outcome) {
                    match ($outcome) {
                        'hard', 'former', 'soft', 'unmatched' => ++$counts[$outcome],
                        'nodsn' => ++$counts['skipped'],
                        default => null,
                    };
                }

                if ($verdict['own']) {
                    ++$own;

                    if (!$dryRun) {
                        try {
                            $message->move(self::PROCESSED_FOLDER, true);
                            ++$counts['moved'];
                        } catch (\Throwable $e) {
                            // Already applied (idempotent); only the move failed.
                            $this->logMessageError($note, 'Bounce ausgewertet, aber nicht nach Processed verschoben, bleibt im Posteingang – '.$e->getMessage());
                        }
                    }
                } else {
                    ++$counts['kept'];
                }

                $lastUid = max($lastUid, $uid);

                if ($persist) {
                    $this->health->recordScan($mailbox, $uidValidity, $lastUid);
                }
            }

            $client->disconnect();

            // What the INBOX holds after this run, apart from messages not looked at yet:
            // computed from the STATUS count, so messages a person deleted in the meantime
            // are no longer counted.
            $counts['inbox'] = self::leftInInbox($inboxCount, $dryRun ? $own : $counts['moved'], $pending, $counts['total']);

            if ($persist) {
                $this->health->recordScan($mailbox, $uidValidity, $lastUid, $counts['inbox']);
            }

            $note('info', \sprintf(
                'Fertig. %d Nachricht(en) im Posteingang gehören zu keiner Workflow-Mail%s.',
                $counts['inbox'],
                $dryRun ? ' (nach einem echten Lauf)' : '',
            ));

            // Only log a run that actually looked at something. An empty mailbox is the
            // normal state, so a line every 15 minutes would bury the entries that matter —
            // and the system log is the one diagnostic channel available without CLI access.
            // Errors are logged regardless, and "workflow:bounce:collect" reports every step
            // on demand, so liveness stays checkable without the heartbeat.
            if (!$dryRun && $counts['total'] > 0) {
                $this->systemLog(\sprintf(
                    'Bounce-Postfach geprüft (%s:%d): %d neue Nachricht(en), %d hart markiert, %d für eine inzwischen geänderte Adresse, %d weich, %d ohne Zuordnung, %d ohne DSN; %d nach Processed verschoben, %d im Posteingang belassen.',
                    $config['host'],
                    $config['port'],
                    $counts['total'],
                    $counts['hard'],
                    $counts['former'],
                    $counts['soft'],
                    $counts['unmatched'],
                    $counts['skipped'],
                    $counts['moved'],
                    $counts['kept'],
                ));
            }

            return BounceOutcome::ok($counts);
        } catch (\Throwable $e) {
            $note('error', 'IMAP-Fehler: '.$e->getMessage());

            return BounceOutcome::configError('IMAP-Fehler ('.$e->getMessage().').');
        }
    }

    /**
     * The UID after which to read: the stored watermark, but only while it belongs to this
     * mailbox and its UIDVALIDITY is unchanged. A different mailbox (configuration changed) or
     * a new UIDVALIDITY (the server renumbered the messages) starts a full pass.
     *
     * @param array{mailbox: string, uidValidity: int, lastUid: int, foreignCount: int} $scan
     */
    public static function resumeAfter(array $scan, string $mailbox, int $uidValidity): int
    {
        if ($uidValidity <= 0 || $scan['mailbox'] !== $mailbox || $scan['uidValidity'] !== $uidValidity) {
            return 0;
        }

        return max(0, $scan['lastUid']);
    }

    /**
     * Messages in the INBOX after a run that were looked at and left there: the count at the
     * start, minus the bounces moved out, minus the new messages not looked at yet (batch
     * limit, or a run stopped by a database error).
     */
    public static function leftInInbox(int $inboxCount, int $moved, int $pending, int $examined): int
    {
        return max(0, $inboxCount - $moved - max(0, $pending - $examined));
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
     * Handles one raw message: parses it, finds the workflow mail each report is about and,
     * unless $dryRun, applies the reports to the send log. Public and free of any IMAP
     * dependency so it can be unit-tested against fixtures and a real database.
     *
     * Returns whether the message is ours – a delivery status notification about at least one
     * workflow mail this installation logged. Only such a message is moved to the Processed
     * folder. Anything else stays in the INBOX for a person to see: replies and out-of-office
     * notes that reached the bounce mailbox, and bounces of mails this installation did not
     * send (another sender pointing at the mailbox, another installation, a send row already
     * purged).
     *
     * @param (\Closure(string, string): void)|null $note
     *
     * @return array{own: bool, outcomes: list<string>} one outcome per report
     *                                                  (hard|former|soft|unmatched|none), or
     *                                                  ['nodsn'] for a message without a DSN
     */
    public function handleRaw(string $raw, bool $dryRun = false, ?\Closure $note = null): array
    {
        $note ??= static function (string $level, string $message): void {
        };

        $reports = $this->parser->parse($raw);

        if ([] === $reports) {
            $note('comment', 'Keine Unzustellbarkeitsmeldung (kein DSN) – bleibt im Posteingang.');

            return ['own' => false, 'outcomes' => ['nodsn']];
        }

        $sends = array_map($this->locateSend(...), $reports);
        $own = [] !== array_filter($sends);
        $outcomes = [];

        foreach ($reports as $i => $bounce) {
            $send = $sends[$i];

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
                    $note(null !== $send ? 'info' : 'error', '  → '.(null !== $send ? 'würde Eintrag '.$send['entryId'].' als unzustellbar markieren' : 'keine passende Versandzeile gefunden – nicht zuordenbar'));
                }

                $outcomes[] = $bounce->isHardBounce() ? (null !== $send ? 'hard' : 'unmatched') : ($bounce->isSoftBounce() ? 'soft' : 'none');

                continue;
            }

            $outcomes[] = $this->applyReport($bounce, $send);
        }

        $note(
            $own ? 'info' : 'comment',
            $own
                ? '  → Bounce einer Workflow-Mail'.($dryRun ? ', würde nach Processed verschoben' : ', wird nach Processed verschoben').'.'
                : '  → gehört zu keiner Workflow-Mail dieser Installation – bleibt im Posteingang.',
        );

        return ['own' => $own, 'outcomes' => $outcomes];
    }

    /**
     * @param array{id: int, entryId: int, recipient: string}|null $send the located send row
     *
     * @return string one of hard|former|unmatched|soft|none
     */
    private function applyReport(BounceReport $report, ?array $send): string
    {
        if ($report->isHardBounce()) {
            return $this->recordHardBounce($report, $send);
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
     * @param array{id: int, entryId: int, recipient: string}|null $send the located send row
     *
     * @return string hard (address blocked) | former (the entry's address has changed since) |
     *                unmatched (no send row found)
     */
    private function recordHardBounce(BounceReport $report, ?array $send): string
    {
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

    /**
     * Identifies the mailbox the watermark belongs to (no secret in it).
     *
     * @param array<string, mixed> $config
     */
    private function mailboxKey(array $config): string
    {
        return \sprintf('imap://%s@%s:%d', rawurlencode((string) $config['username']), (string) $config['host'], (int) $config['port']);
    }

    /**
     * @param \Closure(string, string): void $note
     */
    private function logMessageError(\Closure $note, string $message): void
    {
        $this->logger->warning('Workflow bounce collector: '.$message);
        $note('error', $message);
        $this->systemLog('Bounce-Collector: '.$message, LogLevel::ERROR);
    }

    private function ensureProcessedFolder(object $client): void
    {
        try {
            if (null === $client->getFolderByPath(self::PROCESSED_FOLDER, false, true)) {
                // No EXPUNGE afterwards (webklex' default): no mailbox is selected yet, so the
                // server answers "BAD No mailbox selected" although the folder was created.
                $client->createFolder(self::PROCESSED_FOLDER, false);
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
