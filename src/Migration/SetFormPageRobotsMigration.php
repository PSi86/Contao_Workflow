<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Ensures the shared workflow form page carries robots = "noindex,nofollow": it is reached
 * only via individual token links and must never be indexed by search engines. Covers both
 * the current alias and the former demo alias, so it is independent of the rename migration's
 * order within the same migrate run.
 *
 * Gated on such a page existing without the correct robots value; idempotent.
 */
class SetFormPageRobotsMigration extends AbstractMigration
{
    private const ROBOTS = 'noindex,nofollow';
    private const ALIASES = ['workflow-formular', 'workflow-formular-demo'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['tl_page'])) {
            return false;
        }

        return false !== $this->connection->fetchOne(
            "SELECT id FROM tl_page WHERE alias IN (?) AND robots != ? LIMIT 1",
            [self::ALIASES, self::ROBOTS],
            [\Doctrine\DBAL\ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING],
        );
    }

    public function run(): MigrationResult
    {
        $count = $this->connection->executeStatement(
            "UPDATE tl_page SET robots = ? WHERE alias IN (?) AND robots != ?",
            [self::ROBOTS, self::ALIASES, self::ROBOTS],
            [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::STRING, \Doctrine\DBAL\ParameterType::STRING],
        );

        return $this->createResult(true, sprintf('Set robots=noindex,nofollow on %d workflow form page(s).', $count));
    }
}
