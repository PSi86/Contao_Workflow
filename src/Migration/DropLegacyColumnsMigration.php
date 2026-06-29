<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Drops the leftover columns of the old fixed accept/reject decision once the
 * configurable answer fields / PDF rules have fully replaced them. They were
 * kept SQL-only for the (now removed) ConfigurableAnswersMigration; without them
 * the workflow "Details" view no longer shows stale pdfBody/pdfBodyReject fields.
 */
class DropLegacyColumnsMigration extends AbstractMigration
{
    private const WORKFLOW_COLUMNS = [
        'labelAccept',
        'labelReject',
        'decisionField',
        'dateField',
        'pdfBody',
        'pdfBodyReject',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if ($schemaManager->tablesExist(['tl_workflow'])) {
            $columns = array_map('strtolower', array_keys($schemaManager->listTableColumns('tl_workflow')));

            foreach (self::WORKFLOW_COLUMNS as $column) {
                if (\in_array(strtolower($column), $columns, true)) {
                    return true;
                }
            }
        }

        if ($schemaManager->tablesExist(['tl_workflow_entry'])) {
            $columns = array_map('strtolower', array_keys($schemaManager->listTableColumns('tl_workflow_entry')));

            if (\in_array('decision', $columns, true)) {
                return true;
            }
        }

        return false;
    }

    public function run(): MigrationResult
    {
        foreach (self::WORKFLOW_COLUMNS as $column) {
            $this->connection->executeStatement(sprintf('ALTER TABLE tl_workflow DROP COLUMN IF EXISTS `%s`', $column));
        }

        $this->connection->executeStatement('ALTER TABLE tl_workflow_entry DROP COLUMN IF EXISTS `decision`');

        return $this->createResult(true, 'Dropped legacy decision/letter columns.');
    }
}
