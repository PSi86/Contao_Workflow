<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Bounce;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
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
 * The cron must never throw: an uncaught exception here would abort the remaining Contao
 * cron jobs. Every failure is logged and swallowed.
 */
#[AsCronJob('*/15 * * * *')]
class BounceCollector
{
    private const PROCESSED_FOLDER = 'INBOX/Processed';

    // Shared hosting has tight time limits; cap the work per run. Anything left over is
    // picked up on the next run.
    private const BATCH_LIMIT = 100;

    public function __construct(
        private readonly BounceParser $parser,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly ?string $bounceImapDsn = null,
    ) {
    }

    public function __invoke(): void
    {
        $dsn = trim((string) $this->bounceImapDsn);

        if ('' === $dsn) {
            return; // Not configured: the bounce feature is off.
        }

        try {
            $config = $this->configFromDsn($dsn);
        } catch (\Throwable $e) {
            $this->logger->warning('Workflow bounce collector: invalid WORKFLOW_BOUNCE_IMAP_DSN ('.$e->getMessage().').');

            return;
        }

        try {
            $client = (new ClientManager())->make($config);
            $client->connect();

            $inbox = $client->getFolder('INBOX');

            if (null === $inbox) {
                $this->logger->warning('Workflow bounce collector: INBOX not found.');
                $client->disconnect();

                return;
            }

            $this->ensureProcessedFolder($client);

            $messages = $inbox->messages()->limit(self::BATCH_LIMIT)->setFetchBody(true)->leaveUnread()->get();

            foreach ($messages as $message) {
                try {
                    // Correlate first; a hard bounce is idempotent, so even if the move below
                    // fails and the message is seen again next run, no harm is done.
                    $this->handleRaw($this->rawMessage($message));
                    $message->move(self::PROCESSED_FOLDER, true);
                } catch (\Throwable $e) {
                    $this->logger->warning('Workflow bounce collector: could not process a message ('.$e->getMessage().').');
                }
            }

            $client->disconnect();
        } catch (\Throwable $e) {
            $this->logger->warning('Workflow bounce collector: IMAP error ('.$e->getMessage().').');
        }
    }

    /**
     * Parses one raw message and applies its bounces to the send log. Public and free of any
     * IMAP dependency so it can be unit-tested against fixtures and a real database.
     */
    public function handleRaw(string $raw): void
    {
        foreach ($this->parser->parse($raw) as $report) {
            if ($report->isHardBounce()) {
                $this->recordHardBounce($report);
            } elseif ($report->isSoftBounce()) {
                // A temporary problem (greylisting, mailbox full, delayed retry): the final
                // outcome is still open, so the state is left untouched — only logged.
                $this->logger->info(\sprintf(
                    'Workflow bounce collector: soft bounce for %s (status %s), state left unchanged.',
                    $report->recipient,
                    $report->status,
                ));
            }
        }
    }

    private function recordHardBounce(BounceReport $report): void
    {
        $send = $this->locateSend($report);

        if (null === $send) {
            $this->logger->warning(\sprintf(
                'Workflow bounce collector: hard bounce for %s could not be correlated (parcel id %s).',
                $report->recipient,
                $report->parcelId ?? '—',
            ));

            return;
        }

        $now = time();
        $code = $this->shorten('' !== $report->diagnosticCode ? $report->diagnosticCode : 'Status '.$report->status);

        // Move the send-log row to its terminal "bounced" state. Idempotent.
        $this->connection->executeStatement(
            "UPDATE tl_workflow_send SET state = 'bounced', bounceCode = ?, bouncedAt = ?, tstamp = ? WHERE id = ?",
            [$code, $now, $now, (int) $send['id']],
        );

        // Flag the entry as an invalid address: it is excluded from further invitation and
        // reminder runs (see EntryModel::findByWorkflowAndStatus) and shown in its own
        // dashboard box, separate from retryable transport errors (sendError).
        $this->connection->executeStatement(
            "UPDATE tl_workflow_entry SET bounceHard = '1', bounceInfo = ?, tstamp = ? WHERE id = ?",
            [$this->shorten($report->recipient.' – '.$code), $now, (int) $send['entryId']],
        );
    }

    /**
     * @return array{id: int, entryId: int}|null
     */
    private function locateSend(BounceReport $report): ?array
    {
        if (null !== $report->parcelId) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, entryId FROM tl_workflow_send WHERE parcelId = ? LIMIT 1',
                [$report->parcelId],
            );

            if (false !== $row) {
                return ['id' => (int) $row['id'], 'entryId' => (int) $row['entryId']];
            }

            // Parcel id present but unknown (purged, or a mail we did not send): do not guess.
            return null;
        }

        // Rule 5: no parcel id (the MTA truncated the original past the header). Fall back to
        // the most recent successfully sent mail to this recipient, and record that we guessed.
        $row = $this->connection->fetchAssociative(
            "SELECT id, entryId FROM tl_workflow_send WHERE recipient = ? AND state = 'sent' ORDER BY sentAt DESC, id DESC LIMIT 1",
            [$report->recipient],
        );

        if (false === $row) {
            return null;
        }

        $this->logger->info(\sprintf(
            'Workflow bounce collector: hard bounce for %s correlated by recipient (no parcel id in the bounce).',
            $report->recipient,
        ));

        return ['id' => (int) $row['id'], 'entryId' => (int) $row['entryId']];
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
