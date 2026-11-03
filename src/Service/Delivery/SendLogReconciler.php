<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service\Delivery;

use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\Validator;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Resolves send-log rows that are "queued" but can never get a result any more.
 *
 * A row is written as "queued" when a mail is handed to the mailer; the worker's send result
 * moves it on. Up to 3.4.1 a mail that failed while being handed over (e.g. the template's
 * sender address did not resolve) was logged as "queued" as well, although it never reached the
 * queue. Such a row waits forever and makes the overview ask whether the cron runs.
 *
 * Two rules, both only for rows older than the grace period:
 *
 *  - A recipient that fails Contao's address check can never have been queued: the
 *    Notification Center drops it, and a mail without a recipient fails before it is queued
 *    (production, 3.4.1: "kahnttlehrgang@hotmail"). Such a row is resolved at once and the
 *    address blocked with that reason – the same outcome NotificationDispatcher now produces
 *    up front.
 *  - Any other row: when Contao's message queue holds no message outside its failure queue,
 *    nothing is on its way and the row can no longer change. A message in the failure queue
 *    does not count – its failure was already reported by the mailer and recorded. Without
 *    the queue table (another messenger transport) nothing can be concluded and nothing is
 *    changed.
 *
 * Self-correcting: should a mail resolved here still go out later, its send result updates the
 * row and the entry as usual.
 */
class SendLogReconciler
{
    private const QUEUE_TABLE = 'tl_message_queue';

    private const GRACE = 3600;

    public function __construct(
        private readonly Connection $connection,
        private readonly DeliveryRecorder $recorder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int number of rows resolved as failed
     */
    public function resolveOrphans(?int $now = null): int
    {
        $now ??= time();

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, entryId, kind, recipient FROM tl_workflow_send WHERE state = 'queued' AND queuedAt > 0 AND queuedAt < ?",
                [$now - self::GRACE],
            );

            // Whether anything can still be on its way. Unknown without the queue table.
            $queueEmpty = $this->connection->createSchemaManager()->tablesExist([self::QUEUE_TABLE])
                && 0 === (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.self::QUEUE_TABLE." WHERE queue_name <> 'failure'");
        } catch (\Throwable) {
            return 0;
        }

        $invalid = 0;
        $unresolved = 0;

        foreach ($rows as $row) {
            $recipient = trim((string) $row['recipient']);

            // An address that fails Contao's check was dropped by the Notification Center: the
            // mail had no recipient and was certainly never queued, whatever else is waiting.
            // Blocked with the same reason as NotificationDispatcher gives up front.
            if ('' !== $recipient && !Validator::isEmail($recipient)) {
                if ($this->markFailed((int) $row['id'], 'Ungültige Adresse – die Mail wurde nie eingereiht.', $now)) {
                    $this->recorder->blockAddress(
                        (int) $row['entryId'],
                        $recipient,
                        \sprintf('Ungültige Adresse „%s“ – bitte die Schreibweise prüfen.', $recipient),
                    );
                    ++$invalid;
                }

                continue;
            }

            if (!$queueEmpty) {
                continue;
            }

            $message = 'Kein Versandergebnis: die Mail lag nicht mehr in der Warteschlange und wurde vermutlich nie eingereiht (siehe System-Log zum Zeitpunkt des Versands).';

            if ($this->markFailed((int) $row['id'], $message, $now)) {
                $this->recorder->failed((int) $row['entryId'], (string) $row['kind'], $recipient, $message);
                ++$unresolved;
            }
        }

        if ($invalid > 0) {
            $this->log(\sprintf(
                '%d Versandzeile(n) mit ungültig geschriebener Empfängeradresse aufgelöst: Diese Mails wurden nie eingereiht. Die Adressen sind gesperrt, bis sie am Eintrag korrigiert werden.',
                $invalid,
            ));
        }

        if ($unresolved > 0) {
            $this->log(\sprintf(
                '%d Versandzeile(n) ohne Ergebnis als fehlgeschlagen markiert: die Mail-Warteschlange ist leer, die Mails wurden also nie eingereiht. Die betroffenen Einträge zeigen den Fehler und werden beim nächsten Versand erneut angeschrieben.',
                $unresolved,
            ));
        }

        return $invalid + $unresolved;
    }

    private function markFailed(int $id, string $error, int $now): bool
    {
        return $this->connection->executeStatement(
            "UPDATE tl_workflow_send SET state = 'failed', error = ?, tstamp = ? WHERE id = ? AND state = 'queued'",
            [$error, $now, $id],
        ) > 0;
    }

    private function log(string $message): void
    {
        $this->logger->log(LogLevel::WARNING, $message, [
            'contao' => new ContaoContext(self::class.'::resolveOrphans', ContaoContext::GENERAL),
        ]);
    }
}
