<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Contao\CoreBundle\Cron\Cron;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\CronHealth;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Whether a real cron calls Contao, told apart from page views that merely run the cron at
 * kernel.terminate; logged on change only.
 */
final class CronHealthTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    private RequestStack $requestStack;

    /** @var list<array{string, string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE tl_workflow_cron_health (id INTEGER PRIMARY KEY, tstamp INTEGER NOT NULL DEFAULT 0, lastUrlAt INTEGER NOT NULL DEFAULT 0, lastCliAt INTEGER NOT NULL DEFAULT 0)');
        $this->requestStack = new RequestStack();
    }

    public function testFirstCallIsRecordedAndLoggedOnce(): void
    {
        $health = $this->health();

        $health->recordCall(CronHealth::TRIGGER_URL, self::NOW - 120);
        $health->recordCall(CronHealth::TRIGGER_URL, self::NOW - 60);
        $health->recordCall(CronHealth::TRIGGER_URL, self::NOW);

        $state = $health->read();
        self::assertSame(self::NOW, $state['lastUrlAt']);
        self::assertSame(CronHealth::TRIGGER_URL, $state['trigger']);
        self::assertCount(1, $this->logged, 'regular calls are not logged');
        self::assertStringContainsString('erster Aufruf über /_contao/cron', $this->logged[0][1]);
    }

    public function testACallAfterAGapIsLoggedWithItsLength(): void
    {
        $health = $this->health();

        $health->recordCall(CronHealth::TRIGGER_URL, self::NOW - 3600);
        $health->recordCall(CronHealth::TRIGGER_URL, self::NOW);

        self::assertCount(2, $this->logged);
        self::assertSame('warning', $this->logged[1][0]);
        self::assertStringContainsString('Pause von 60 Minuten', $this->logged[1][1]);
    }

    public function testAliveOnlyWhileTheLastCallIsRecent(): void
    {
        $health = $this->health();

        self::assertFalse($health->isAlive(self::NOW), 'never called');

        $health->recordCall(CronHealth::TRIGGER_CLI, self::NOW - 300);
        self::assertTrue($health->isAlive(self::NOW));
        self::assertSame(CronHealth::TRIGGER_CLI, $health->read()['trigger']);
        self::assertFalse($health->isAlive(self::NOW + CronHealth::ALIVE_FOR), 'stale after ten minutes');
    }

    public function testPageViewTriggerIsTheWebScopeOutsideTheCronRoute(): void
    {
        $health = $this->health();

        self::assertFalse($health->isPageViewTrigger(Cron::SCOPE_CLI), 'contao:cron');
        self::assertTrue($health->isPageViewTrigger(Cron::SCOPE_WEB), 'kernel.terminate: the request is already off the stack');

        $cronRequest = Request::create('/_contao/cron');
        $cronRequest->attributes->set('_route', 'contao_frontend_cron');
        $this->requestStack->push($cronRequest);

        self::assertFalse($health->isPageViewTrigger(Cron::SCOPE_WEB), 'the cron URL itself');
    }

    public function testDeferOnlyPageViewsAndOnlyWhileARealCronIsAlive(): void
    {
        $health = $this->health();

        self::assertFalse($health->shouldDefer(Cron::SCOPE_WEB), 'without a real cron the page views must do the work');

        $health->recordCall(CronHealth::TRIGGER_URL, time());

        self::assertTrue($health->shouldDefer(Cron::SCOPE_WEB));
        self::assertFalse($health->shouldDefer(Cron::SCOPE_CLI));
    }

    public function testMissingTableNeverThrows(): void
    {
        $this->connection->executeStatement('DROP TABLE tl_workflow_cron_health');
        $health = $this->health();

        $health->recordCall(CronHealth::TRIGGER_URL, self::NOW);

        self::assertSame(0, $health->read()['lastAt']);
        self::assertFalse($health->isAlive(self::NOW));
    }

    private function health(): CronHealth
    {
        $logged = &$this->logged;

        $logger = new class($logged) extends AbstractLogger {
            /** @param list<array{string, string}> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = [(string) $level, (string) $message];
            }
        };

        return new CronHealth($this->connection, $this->requestStack, $logger);
    }
}
