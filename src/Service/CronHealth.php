<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Cron\Cron;
use Contao\CoreBundle\Monolog\ContaoContext;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Knows whether a real cron is calling Contao, and whether the current cron run was started
 * by one.
 *
 * Contao runs its cron jobs on three triggers: a server cron job requesting /_contao/cron, the
 * "contao:cron" command, and – unless disabled with contao.cron.web_listener: false – the end
 * of every ordinary page view (kernel.terminate). Only the first two are a cron in the user's
 * sense; the third depends on visitors and runs after the page was delivered, so the page
 * shown is always one step behind. Contao itself records only when each job last ran, not who
 * started it, so "a job ran" cannot tell the three apart. This service can: the two real
 * triggers are recorded the moment they arrive (CronCallListener), page views never are.
 *
 * What it is used for:
 *  - the status line in the overview: "Cronjob zuletzt aufgerufen …", and a warning when the
 *    last call is too old;
 *  - the system log, written only on a change: the first call ever seen, and a call after a
 *    gap (with its length) – a line every minute would bury everything else;
 *  - the bundle's expensive cron jobs, which leave page-view triggers alone while a real cron
 *    is alive (see shouldDefer()), so no visitor's request pays for an IMAP login or a PDF.
 *
 * Every database access is guarded: the table may not exist yet right after a deploy, and
 * recording must never break the request it runs in.
 */
class CronHealth
{
    public const TRIGGER_URL = 'url';

    public const TRIGGER_CLI = 'cli';

    /**
     * A real cron counts as alive while its last call is at most this old. Well above the
     * recommended one- to three-minute interval, so a slow or skipped call does not flap.
     */
    public const ALIVE_FOR = 600;

    private const ROW_ID = 1;

    private const CRON_ROUTE = 'contao_frontend_cron';

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Records a call by a real cron and logs it when it is the first one ever seen or ends a
     * gap longer than ALIVE_FOR.
     */
    public function recordCall(string $trigger, ?int $now = null): void
    {
        $now ??= time();
        $column = self::TRIGGER_CLI === $trigger ? 'lastCliAt' : 'lastUrlAt';

        try {
            $previous = $this->read();

            if (false === $this->connection->fetchOne('SELECT id FROM tl_workflow_cron_health WHERE id = ?', [self::ROW_ID])) {
                $this->connection->insert('tl_workflow_cron_health', ['id' => self::ROW_ID, 'tstamp' => $now, $column => $now]);
            } else {
                $this->connection->update('tl_workflow_cron_health', ['tstamp' => $now, $column => $now], ['id' => self::ROW_ID]);
            }
        } catch (\Throwable) {
            return;
        }

        $via = self::TRIGGER_CLI === $trigger ? 'contao:cron' : '/_contao/cron';

        if (0 === $previous['lastAt']) {
            $this->log(LogLevel::INFO, \sprintf('Cronjob erkannt: erster Aufruf über %s.', $via));
        } elseif ($now - $previous['lastAt'] > self::ALIVE_FOR) {
            $this->log(LogLevel::WARNING, \sprintf(
                'Cronjob wieder aktiv (Aufruf über %s) nach einer Pause von %d Minuten – letzter Aufruf davor: %s.',
                $via,
                intdiv($now - $previous['lastAt'], 60),
                date('d.m.Y H:i', $previous['lastAt']),
            ));
        }
    }

    /**
     * @return array{tstamp: int, lastUrlAt: int, lastCliAt: int, lastAt: int, trigger: string}
     */
    public function read(): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT tstamp, lastUrlAt, lastCliAt FROM tl_workflow_cron_health WHERE id = ?',
                [self::ROW_ID],
            );
        } catch (\Throwable) {
            $row = false;
        }

        $url = false === $row ? 0 : (int) $row['lastUrlAt'];
        $cli = false === $row ? 0 : (int) $row['lastCliAt'];

        return [
            'tstamp'    => false === $row ? 0 : (int) $row['tstamp'],
            'lastUrlAt' => $url,
            'lastCliAt' => $cli,
            'lastAt'    => max($url, $cli),
            'trigger'   => 0 === max($url, $cli) ? '' : ($cli > $url ? self::TRIGGER_CLI : self::TRIGGER_URL),
        ];
    }

    public function isAlive(?int $now = null): bool
    {
        $lastAt = $this->read()['lastAt'];

        return $lastAt > 0 && ($now ?? time()) - $lastAt <= self::ALIVE_FOR;
    }

    /**
     * Whether the cron run in progress was started by an ordinary page view. Contao passes the
     * scope to every cron job; in the web scope, a run started by /_contao/cron happens inside
     * that request, while a page-view run happens at kernel.terminate – after the kernel has
     * already taken the request off the stack.
     */
    public function isPageViewTrigger(string $scope): bool
    {
        if (Cron::SCOPE_WEB !== $scope) {
            return false;
        }

        $request = $this->requestStack->getCurrentRequest();

        return null === $request || self::CRON_ROUTE !== $request->attributes->get('_route');
    }

    /**
     * True when a cron job should leave this run to the real cron: it was started by a page
     * view while a real cron is alive. When the real cron stops, page views take over again,
     * so the work is delayed, never dropped.
     */
    public function shouldDefer(string $scope): bool
    {
        return $this->isPageViewTrigger($scope) && $this->isAlive();
    }

    private function log(string $level, string $message): void
    {
        $this->logger->log($level, $message, [
            'contao' => new ContaoContext(self::class.'::recordCall', LogLevel::WARNING === $level ? ContaoContext::GENERAL : ContaoContext::CRON),
        ]);
    }
}
