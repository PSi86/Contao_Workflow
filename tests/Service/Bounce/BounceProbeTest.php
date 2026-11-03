<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Bounce;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Bounce\BounceProbe;
use Psimandl\WorkflowBundle\Service\Bounce\BounceSchedule;

/**
 * What the overview says about an end-to-end test, derived from nothing but its send row.
 */
final class BounceProbeTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testQueuedUntilTheWorkerSendsIt(): void
    {
        $test = BounceProbe::describe($this->row(['state' => 'queued', 'queuedAt' => self::NOW - 30]), self::NOW);

        self::assertSame(BounceProbe::STATE_QUEUED, $test['state']);
        self::assertSame(self::NOW - 30 + BounceSchedule::TEST_WINDOW, $test['until']);
    }

    public function testWaitingOnceSent(): void
    {
        $test = BounceProbe::describe($this->row(['state' => 'sent', 'queuedAt' => self::NOW - 300, 'sentAt' => self::NOW - 290]), self::NOW);

        self::assertSame(BounceProbe::STATE_WAITING, $test['state']);
        self::assertSame('bounces@example.org', $test['envelopeSender']);
    }

    public function testBouncedWithTheTimeFromSendToBounce(): void
    {
        $test = BounceProbe::describe($this->row([
            'state' => 'bounced',
            'queuedAt' => self::NOW - 300,
            'sentAt' => self::NOW - 290,
            'bouncedAt' => self::NOW - 242,
        ]), self::NOW);

        self::assertSame(BounceProbe::STATE_BOUNCED, $test['state']);
        self::assertSame(48, $test['seconds']);
    }

    public function testFailedCarriesTheError(): void
    {
        $test = BounceProbe::describe($this->row([
            'state' => 'failed',
            'queuedAt' => self::NOW - 300,
            'error' => '553 5.7.1 Sender address rejected',
        ]), self::NOW);

        self::assertSame(BounceProbe::STATE_FAILED, $test['state']);
        self::assertSame('553 5.7.1 Sender address rejected', $test['error']);
    }

    public function testNoBounceWithinTheWindowIsATimeout(): void
    {
        $queuedAt = self::NOW - BounceSchedule::TEST_WINDOW - 1;
        $test = BounceProbe::describe($this->row(['state' => 'sent', 'queuedAt' => $queuedAt, 'sentAt' => $queuedAt + 5]), self::NOW);

        self::assertSame(BounceProbe::STATE_TIMEOUT, $test['state']);
    }

    public function testATestOlderThanAWeekIsNotShown(): void
    {
        self::assertNull(BounceProbe::describe($this->row(['state' => 'bounced', 'queuedAt' => self::NOW - BounceProbe::SHOW_FOR - 1]), self::NOW));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function row(array $values): array
    {
        return $values + [
            'state' => '',
            'recipient' => 'wf-bouncetest-0123456789ab@bounce-test.invalid',
            'queuedAt' => 0,
            'sentAt' => 0,
            'bouncedAt' => 0,
            'error' => null,
            'envelopeSender' => 'bounces@example.org',
            'title' => 'Übungsleiter 2026',
        ];
    }
}
