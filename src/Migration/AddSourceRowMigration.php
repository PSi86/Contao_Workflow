<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Adds tl_workflow_entry.sourceRow – the row an entry came from in the source sheet – so
 * an export can reproduce the order of the imported file instead of sorting by e-mail.
 *
 * The backfill relies on a property of the old importer: it walked the sheet top to
 * bottom and inserted every new row immediately, so within a workflow the auto-increment
 * id already IS the import order. Numbering existing entries by id therefore restores the
 * original order exactly, without needing the (possibly long gone) source file. The
 * numbers are only a relative order until the next import overwrites them with the real
 * sheet rows.
 *
 * Migrations run before the schema diff, so the column is added here explicitly; the
 * schema diff afterwards just confirms it matches the DCA.
 */
class AddSourceRowMigration extends AbstractMigration
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

        return !\in_array('sourcerow', $columns, true);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            'ALTER TABLE tl_workflow_entry ADD COLUMN sourceRow int(10) unsigned NOT NULL DEFAULT 0',
        );

        // Number per workflow, starting at 2 – row 1 is the header row of a sheet, so the
        // backfilled values stay in the same range the importer produces.
        $backfilled = 0;

        foreach ($this->connection->fetchFirstColumn('SELECT DISTINCT pid FROM tl_workflow_entry') as $pid) {
            $ids = $this->connection->fetchFirstColumn(
                'SELECT id FROM tl_workflow_entry WHERE pid = ? ORDER BY id ASC',
                [(int) $pid],
            );

            foreach ($ids as $index => $id) {
                $this->connection->update(
                    'tl_workflow_entry',
                    ['sourceRow' => $index + 2],
                    ['id' => (int) $id],
                );
                ++$backfilled;
            }
        }

        return $this->createResult(
            true,
            sprintf('Added tl_workflow_entry.sourceRow and backfilled %d entries in import order.', $backfilled),
        );
    }
}
