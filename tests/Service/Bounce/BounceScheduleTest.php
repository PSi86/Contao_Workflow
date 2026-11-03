<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Bounce;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Bounce\BounceSchedule;

/**
 * The mailbox is read often right after mails went out, less often while answers are
 * outstanding, and rarely when nothing can bounce any more.
 */
final class BounceScheduleTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement("CREATE TABLE tl_workflow_send (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT '', queuedAt INTEGER NOT NULL DEFAULT 0, sentAt INTEGER NOT NULL DEFAULT 0)");
        $this->connection->executeStatement("CREATE TABLE tl_workflow_entry (id INTEGER PRIMARY KEY AUTOINCREMENT, status INTEGER NOT NULL DEFAULT 0, respondedAt INTEGER NOT NULL DEFAULT 0, bounceHard TEXT NOT NULL DEFAULT '')");
    }

    public function testARunningEndToEndTestIsCheckedEveryMinute(): void
    {
        $this->connection->insert('tl_workflow_send', ['kind' => 'test', 'state' => 'sent', 'queuedAt' => self::NOW - 600, 'sentAt' => self::NOW - 590]);

        $current = $this->schedule()->current(self::NOW);

        self::assertSame(BounceSchedule::TIER_TEST, $current['tier']);
        self::assertSame(60, $current['interval']);
    }

    public function testAnAnsweredOrExpiredTestNoLongerSpeedsUpTheChecks(): void
    {
        // The bounce has arrived …
        $this->connection->insert('tl_workflow_send', ['kind' => 'test', 'state' => 'bounced', 'queuedAt' => self::NOW - 600, 'sentAt' => self::NOW - 590]);
        // … and an older test gave up after 30 minutes.
        $this->connection->insert('tl_workflow_send', ['kind' => 'test', 'state' => 'sent', 'queuedAt' => self::NOW - 4000, 'sentAt' => self::NOW - 3990]);

        // The test mail itself still counts as a recent mail.
        self::assertSame(BounceSchedule::TIER_RECENT, $this->schedule()->current(self::NOW)['tier']);
    }

    public function testNothingSentAndNothingOutstandingIsIdle(): void
    {
        $this->connection->insert('tl_workflow_entry', ['status' => 0]);

        $current = $this->schedule()->current(self::NOW);

        self::assertSame(BounceSchedule::TIER_IDLE, $current['tier']);
        self::assertSame(7200, $current['interval']);
    }

    public function testAMailInTheLastHalfHourIsRecent(): void
    {
        $this->connection->insert('tl_workflow_send', ['queuedAt' => self::NOW - 1500, 'sentAt' => self::NOW - 1200]);

        self::assertSame(BounceSchedule::TIER_RECENT, $this->schedule()->current(self::NOW)['tier']);
        self::assertSame(120, $this->schedule()->current(self::NOW)['interval']);
    }

    public function testAMailStillQueuedCountsAsRecent(): void
    {
        $this->connection->insert('tl_workflow_send', ['queuedAt' => self::NOW - 60, 'sentAt' => 0]);

        self::assertSame(BounceSchedule::TIER_RECENT, $this->schedule()->current(self::NOW)['tier']);
    }

    public function testUnansweredInvitationsAfterTheRecentWindowAreOutstanding(): void
    {
        $this->connection->insert('tl_workflow_send', ['queuedAt' => self::NOW - 7200, 'sentAt' => self::NOW - 7100]);
        $this->connection->insert('tl_workflow_entry', ['status' => 1]);

        $current = $this->schedule()->current(self::NOW);

        self::assertSame(BounceSchedule::TIER_OUTSTANDING, $current['tier']);
        self::assertSame(900, $current['interval']);
        self::assertSame(1, $current['outstanding']);
    }

    public function testAnsweredOrBlockedEntriesAreNotOutstanding(): void
    {
        $this->connection->insert('tl_workflow_entry', ['status' => 2, 'respondedAt' => self::NOW - 100]);
        $this->connection->insert('tl_workflow_entry', ['status' => 1, 'bounceHard' => '1']);

        self::assertSame(BounceSchedule::TIER_IDLE, $this->schedule()->current(self::NOW)['tier']);
    }

    public function testDueFollowsTheTierWithSomeSlackForCronJitter(): void
    {
        $this->connection->insert('tl_workflow_send', ['queuedAt' => self::NOW - 300, 'sentAt' => self::NOW - 290]);

        self::assertTrue($this->schedule()->isDue(0, self::NOW), 'never checked: due');
        self::assertFalse($this->schedule()->isDue(self::NOW - 60, self::NOW), 'one minute ago: not yet');
        self::assertTrue($this->schedule()->isDue(self::NOW - 118, self::NOW), 'a cron call two seconds early still counts');
    }

    private function schedule(): BounceSchedule
    {
        return new BounceSchedule($this->connection);
    }
}
