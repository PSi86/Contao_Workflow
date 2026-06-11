<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * The short-lived "display" question type became the per-type "Schreibgeschützt"
 * (readOnly) option: converts existing display questions into read-only text
 * fields. Adds the readOnly column itself when the schema diff has not run yet
 * (migrations execute before the schema update).
 */
class DisplayQuestionsToReadOnlyMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_workflow_question'])) {
            return false;
        }

        return false !== $this->connection->fetchOne("SELECT id FROM tl_workflow_question WHERE type = 'display' LIMIT 1");
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            "ALTER TABLE tl_workflow_question ADD COLUMN IF NOT EXISTS readOnly char(1) NOT NULL default ''",
        );

        $converted = $this->connection->executeStatement(
            "UPDATE tl_workflow_question SET type = 'text', readOnly = '1' WHERE type = 'display'",
        );

        return $this->createResult(true, sprintf('Converted %d display question(s) to read-only text fields.', $converted));
    }
}
