<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\EventListener\RequestTimingListener;
use Psimandl\WorkflowBundle\Service\BackgroundActivity;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

/**
 * Slow requests and slow work after a request end up in the system log, with what caused
 * them; normal ones leave no trace.
 */
final class RequestTimingListenerTest extends TestCase
{
    private const T0 = 1_800_000_000.0;

    private float $now = self::T0;

    /** @var list<string> */
    private array $logged = [];

    private BackgroundActivity $activity;

    private RequestTimingListener $listener;

    protected function setUp(): void
    {
        $logged = &$this->logged;
        $logger = new class($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = (string) $message;
            }
        };

        $this->activity = new BackgroundActivity();
        $this->listener = new RequestTimingListener($logger, $this->activity, fn (): float => $this->now);
    }

    public function testANormalRequestLeavesNoTrace(): void
    {
        $request = $this->request('/contao', 'do=workflow_overview', self::T0);
        $this->now = self::T0 + 2.5;

        $this->listener->onResponse($this->responseEvent($request));
        $this->listener->onTerminateStart($this->terminateEvent($request));
        $this->now += 1.0;
        $this->listener->onTerminateEnd($this->terminateEvent($request));

        self::assertSame([], $this->logged);
    }

    public function testASlowPageIsLoggedWithoutItsToken(): void
    {
        $request = $this->request('/contao', 'do=workflow_overview&rt=SECRETTOKEN', self::T0);
        $this->now = self::T0 + 12.04;

        $this->listener->onResponse($this->responseEvent($request));

        self::assertCount(1, $this->logged);
        self::assertSame('Langsamer Seitenaufbau: 12,0 s für GET /contao?do=workflow_overview.', $this->logged[0]);
    }

    public function testASlowCronCallNamesWhatTookTheTime(): void
    {
        $request = $this->request('/_contao/cron', '', self::T0);
        $request->attributes->set('_route', 'contao_frontend_cron');
        $this->activity->record('Bounce-Abruf', 20.04);
        $this->now = self::T0 + 21.0;

        $this->listener->onResponse($this->responseEvent($request));

        self::assertSame(['Cronjob-Aufruf über /_contao/cron dauerte 21,0 s – darin: Bounce-Abruf 20,0 s.'], $this->logged);
    }

    public function testSlowWorkAfterThePageCountsTheMailsOfThatPhaseOnly(): void
    {
        $request = $this->request('/contao/workflow/send/9', '', self::T0);
        $this->now = self::T0 + 1.0;

        // A mail sent while the page was built does not belong to the background phase.
        $this->listener->onSentMessage($this->sent());
        $this->listener->onResponse($this->responseEvent($request));

        $this->listener->onTerminateStart($this->terminateEvent($request));
        $this->listener->onSentMessage($this->sent());
        $this->listener->onSentMessage($this->sent());
        $this->listener->onFailedMessage(new FailedMessageEvent(new RawMessage('x'), new \RuntimeException('421')));
        $this->now += 28.0;
        $this->listener->onTerminateEnd($this->terminateEvent($request));

        self::assertCount(1, $this->logged);
        self::assertStringStartsWith('Hintergrundarbeit nach GET /contao/workflow/send/9 dauerte 28,0 s (2 Mail(s) versendet, 1 fehlgeschlagen).', $this->logged[0]);
    }

    private function request(string $path, string $query, float $start): Request
    {
        $request = Request::create($path.('' !== $query ? '?'.$query : ''));
        $request->server->set('REQUEST_TIME_FLOAT', $start);

        return $request;
    }

    private function responseEvent(Request $request): ResponseEvent
    {
        return new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new Response());
    }

    private function terminateEvent(Request $request): TerminateEvent
    {
        return new TerminateEvent($this->createMock(HttpKernelInterface::class), $request, new Response());
    }

    private function sent(): SentMessageEvent
    {
        return new SentMessageEvent(new SentMessage(new RawMessage('x'), new Envelope(new Address('a@example.org'), [new Address('b@example.org')])));
    }
}
