<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Adds the confirmation-processing columns (resultDoneAt / resultError) and — crucially —
 * marks every already-answered entry as "done" on upgrade.
 *
 * Without that data fix, the new "Offene Vorgänge" list (which shows anything not fully
 * finished, i.e. resultDoneAt = 0) would suddenly list every historically answered entry,
 * and the retry cron would try to re-generate the PDF and re-send the result mail to
 * everyone who ever responded. Marking the existing responses done means only NEW
 * submissions that fail after this upgrade are treated as open.
 *
 * Migrations run before the schema diff, so the columns are added here explicitly; the
 * schema diff afterwards just confirms they match the DCA.
 */
class AddResultFieldsMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_workflow_entry'])) {
            return false;
        }

        $columns = array_map('strtolower', array_keys($schemaManager->listTableColumns('tl_workflow_entry')));

        return !\in_array('resultdoneat', $columns, true);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            "ALTER TABLE tl_workflow_entry ADD COLUMN resultDoneAt int(10) unsigned NOT NULL DEFAULT 0",
        );
        $this->connection->executeStatement(
            "ALTER TABLE tl_workflow_entry ADD COLUMN resultError varchar(255) NOT NULL DEFAULT ''",
        );

        // Mark all existing answered entries as done so they neither flood the new list nor
        // trigger a re-send of their result mail. The exact value only has to be non-zero.
        $this->connection->executeStatement(
            'UPDATE tl_workflow_entry SET resultDoneAt = UNIX_TIMESTAMP() WHERE respondedAt > 0 OR status >= ?',
            [2],
        );

        return $this->createResult(true, 'Added resultDoneAt/resultError and marked existing responses as done.');
    }
}
