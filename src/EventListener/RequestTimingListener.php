<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener;

use Contao\CoreBundle\Monolog\ContaoContext;
use Psimandl\WorkflowBundle\Service\BackgroundActivity;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;

/**
 * Writes a system-log entry when a request, or the work Contao does after it, takes long.
 *
 * Without a CLI on shared hosting, "a back-end page sometimes takes 30 seconds" cannot be
 * traced to a cause. Two phases are measured separately, because they hurt differently:
 *
 *  - Building the response (request start → kernel.response). Slow here = the visitor waits.
 *    For /_contao/cron this is the cron jobs themselves, logged as such.
 *  - The work after the response (kernel.terminate): Contao's cron when started by a page
 *    view, and the mail queue (web worker). Under PHP-FPM the response is already delivered
 *    (Response::send() calls fastcgi_finish_request()), so nobody waits for this request – but
 *    the PHP process stays busy, and the next click may wait for a free one.
 *
 * If a page took long and neither entry exists, the time went by outside PHP (a busy server,
 * a full process pool) – which is the third answer this listener makes possible.
 *
 * Only the slow cases are written; a normal request costs two microtime() calls.
 */
class RequestTimingListener
{
    /** Seconds from which a phase is worth a log entry. */
    public const THRESHOLD = 10.0;

    private float $terminateStart = 0.0;

    private bool $inTerminate = false;

    private int $sent = 0;

    private int $failed = 0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock seconds as float; injectable for tests
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly BackgroundActivity $activity,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Late, so the time of every other response listener is included.
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -4096)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $start = (float) $request->server->get('REQUEST_TIME_FLOAT', 0);
        $notes = $this->activity->drain();

        if ($start <= 0) {
            return;
        }

        $seconds = ($this->clock)() - $start;

        if ($seconds < self::THRESHOLD) {
            return;
        }

        $this->log(
            'contao_frontend_cron' === $request->attributes->get('_route')
                ? \sprintf('Cronjob-Aufruf über /_contao/cron dauerte %s s%s.', $this->seconds($seconds), $this->detail($notes))
                : \sprintf('Langsamer Seitenaufbau: %s s für %s%s.', $this->seconds($seconds), $this->describe($request), $this->detail($notes)),
        );
    }

    /**
     * Before Contao's cron listener and its web worker (priority -2048).
     */
    #[AsEventListener(event: KernelEvents::TERMINATE, priority: 4096)]
    public function onTerminateStart(TerminateEvent $event): void
    {
        $this->terminateStart = ($this->clock)();
        $this->inTerminate = true;
        $this->sent = 0;
        $this->failed = 0;
        $this->activity->drain();
    }

    /**
     * After the web worker has finished.
     */
    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -4096)]
    public function onTerminateEnd(TerminateEvent $event): void
    {
        $this->inTerminate = false;
        $notes = $this->activity->drain();
        $seconds = ($this->clock)() - $this->terminateStart;

        if ($this->terminateStart <= 0 || $seconds < self::THRESHOLD) {
            return;
        }

        $this->log(\sprintf(
            'Hintergrundarbeit nach %s dauerte %s s (%d Mail(s) versendet, %d fehlgeschlagen)%s. Der Besucher wartet darauf nicht, der PHP-Prozess ist aber so lange belegt.',
            $this->describe($event->getRequest()),
            $this->seconds($seconds),
            $this->sent,
            $this->failed,
            $this->detail($notes),
        ));
    }

    #[AsEventListener]
    public function onSentMessage(SentMessageEvent $event): void
    {
        if ($this->inTerminate) {
            ++$this->sent;
        }
    }

    #[AsEventListener]
    public function onFailedMessage(FailedMessageEvent $event): void
    {
        if ($this->inTerminate) {
            ++$this->failed;
        }
    }

    private function describe(Request $request): string
    {
        $uri = $request->getPathInfo();
        $query = (string) $request->getQueryString();

        // The token in a back-end action URL says nothing and belongs in no log.
        $query = (string) preg_replace('/(^|&)(rt|REQUEST_TOKEN)=[^&]*/', '', $query);
        $query = ltrim($query, '&');

        return $request->getMethod().' '.$uri.('' !== $query ? '?'.$query : '');
    }

    /**
     * @param list<array{string, float}> $notes
     */
    private function detail(array $notes): string
    {
        if ([] === $notes) {
            return '';
        }

        $parts = array_map(fn (array $n): string => $n[0].' '.$this->seconds($n[1]).' s', $notes);

        return ' – darin: '.implode(', ', $parts);
    }

    private function seconds(float $seconds): string
    {
        return number_format($seconds, 1, ',', '');
    }

    private function log(string $message): void
    {
        $this->logger->log(LogLevel::WARNING, $message, [
            'contao' => new ContaoContext(self::class, ContaoContext::GENERAL),
        ]);
    }
}
