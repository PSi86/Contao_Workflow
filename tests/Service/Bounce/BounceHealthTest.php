<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service\Bounce;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\Bounce\BounceHealth;

/**
 * The memory that lets the background cron and the on-demand overview agree on the mailbox
 * state without the overview ever opening a connection. Two things matter: the stored verdict
 * survives across runs (one row, overwritten), and record() reports the PREVIOUS state so the
 * cron can log only on a real change instead of every 15 minutes.
 */
final class BounceHealthTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_bounce_health (
                id      INTEGER PRIMARY KEY,
                tstamp  INTEGER NOT NULL DEFAULT 0,
                state   TEXT NOT NULL DEFAULT '',
                message TEXT NULL
            )
            SQL);
    }

    public function testIsConfiguredReflectsTheDsn(): void
    {
        self::assertFalse((new BounceHealth($this->connection, null))->isConfigured());
        self::assertFalse((new BounceHealth($this->connection, '   '))->isConfigured());
        self::assertTrue((new BounceHealth($this->connection, 'imap://u:p@host:993'))->isConfigured());
    }

    public function testRecordReturnsThePreviousState(): void
    {
        $health = new BounceHealth($this->connection);

        // First ever record: no previous state.
        self::assertNull($health->record(BounceHealth::STATE_OK));
        // Now the previous state is what we just wrote.
        self::assertSame(BounceHealth::STATE_OK, $health->record(BounceHealth::STATE_CONFIG_ERROR, 'boom'));
        self::assertSame(BounceHealth::STATE_CONFIG_ERROR, $health->record(BounceHealth::STATE_OK));
    }

    public function testRecordKeepsASingleRow(): void
    {
        $health = new BounceHealth($this->connection);

        $health->record(BounceHealth::STATE_OK);
        $health->record(BounceHealth::STATE_CONFIG_ERROR, 'x');
        $health->record(BounceHealth::STATE_UNCONFIGURED);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_workflow_bounce_health'));
    }

    public function testReadReturnsTheLastVerdict(): void
    {
        $health = new BounceHealth($this->connection);
        $health->record(BounceHealth::STATE_CONFIG_ERROR, 'IMAP-Fehler (auth).');

        $state = $health->read();

        self::assertSame(BounceHealth::STATE_CONFIG_ERROR, $state['state']);
        self::assertSame('IMAP-Fehler (auth).', $state['message']);
        self::assertGreaterThan(0, $state['checkedAt']);
    }

    public function testReadDefaultsWhenNothingRecorded(): void
    {
        $state = (new BounceHealth($this->connection))->read();

        self::assertSame('', $state['state']);
        self::assertSame(0, $state['checkedAt']);
    }

    /**
     * The collector must never throw; a missing table (right after a deploy, before the schema
     * diff created it) has to degrade to "no data", not blow up the cron or the overview.
     */
    public function testMissingTableDegradesGracefully(): void
    {
        $this->connection->executeStatement('DROP TABLE tl_workflow_bounce_health');
        $health = new BounceHealth($this->connection);

        self::assertNull($health->record(BounceHealth::STATE_OK));
        self::assertSame('', $health->read()['state']);
    }
}
