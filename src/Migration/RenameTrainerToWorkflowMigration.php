<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Renames the bundle's database tables from the old "tl_trainer_*" prefix to the
 * new "tl_workflow*" naming, updates the Notification Center type and migrates
 * the PDF storage path. Runs once in the first migration pass of contao:migrate,
 * before the schema diff – so the diff sees the already-renamed tables instead of
 * creating empty new ones (and dropping the old ones with --with-deletes).
 *
 * Gated strictly on "old main table exists AND new main table is missing".
 */
class RenameTrainerToWorkflowMigration extends AbstractMigration
{
    private const TABLE_MAP = [
        'tl_trainer_workflow' => 'tl_workflow',
        'tl_trainer_entry'    => 'tl_workflow_entry',
        'tl_trainer_question' => 'tl_workflow_question',
        'tl_trainer_rule'     => 'tl_workflow_rule',
        'tl_trainer_master'   => 'tl_workflow_master',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir,
    ) {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        return $schemaManager->tablesExist(['tl_trainer_workflow'])
            && !$schemaManager->tablesExist(['tl_workflow']);
    }

    public function run(): MigrationResult
    {
        $schemaManager = $this->connection->createSchemaManager();
        $renamed = 0;

        foreach (self::TABLE_MAP as $old => $new) {
            if ($schemaManager->tablesExist([$old]) && !$schemaManager->tablesExist([$new])) {
                $this->connection->executeStatement(sprintf('RENAME TABLE `%s` TO `%s`', $old, $new));
                ++$renamed;
            }
        }

        // Notification Center type was renamed trainer_workflow -> workflow.
        if ($schemaManager->tablesExist(['tl_nc_notification'])) {
            $this->connection->executeStatement(
                "UPDATE tl_nc_notification SET type = 'workflow' WHERE type = 'trainer_workflow'",
            );
        }

        // Front end module type was renamed trainer_form -> workflow_form.
        if ($schemaManager->tablesExist(['tl_module'])) {
            $this->connection->executeStatement(
                "UPDATE tl_module SET type = 'workflow_form' WHERE type = 'trainer_form'",
            );
        }

        // Stored PDF paths moved from var/trainer_pdfs to var/workflow_pdfs.
        if ($schemaManager->tablesExist(['tl_workflow_entry'])) {
            $this->connection->executeStatement(
                "UPDATE tl_workflow_entry
                 SET pdfPath = REPLACE(pdfPath, 'var/trainer_pdfs/', 'var/workflow_pdfs/')
                 WHERE pdfPath LIKE 'var/trainer_pdfs/%'",
            );
        }

        $this->movePdfStorage();

        return $this->createResult(true, sprintf('Renamed %d table(s) from tl_trainer_* to tl_workflow*.', $renamed));
    }

    private function movePdfStorage(): void
    {
        $old = $this->projectDir.'/var/trainer_pdfs';
        $new = $this->projectDir.'/var/workflow_pdfs';

        if (is_dir($old) && !is_dir($new)) {
            @rename($old, $new);
        }
    }
}
