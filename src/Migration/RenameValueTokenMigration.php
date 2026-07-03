<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Renames the field-local document-text slot ##value## to ##answer## in existing
 * answer-field configurations. The per-question template lives in the plain-text
 * pdfStatement column (safe to REPLACE in SQL); option statements live in the
 * serialized options blob and are rewritten in PHP so the serialized string
 * length prefixes stay valid (##value## and ##answer## differ in length, a raw
 * SQL REPLACE on the blob would corrupt it).
 */
class RenameValueTokenMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        // Gate on the actual columns, not just the table: on an upgrade the table
        // can exist before the schema diff adds pdfStatement (e.g. an older schema,
        // or right after the Trainer->Workflow table rename), and a LIKE on a
        // missing column aborts contao:migrate with "Unknown column".
        if (!$this->columnsExist('tl_workflow_question', ['pdfStatement', 'options'])) {
            return false;
        }

        return false !== $this->connection->fetchOne(
            "SELECT id FROM tl_workflow_question WHERE pdfStatement LIKE '%##value##%' OR options LIKE '%##value##%' LIMIT 1",
        );
    }

    /**
     * True only if $table exists AND every column in $columns is present. Used to
     * keep shouldRun()/run() from touching columns the schema diff has not created
     * yet on an upgrade.
     *
     * @param array<int, string> $columns
     */
    private function columnsExist(string $table, array $columns): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist([$table])) {
            return false;
        }

        $existing = array_map('strtolower', array_keys($schemaManager->listTableColumns($table)));

        foreach ($columns as $column) {
            if (!\in_array(strtolower($column), $existing, true)) {
                return false;
            }
        }

        return true;
    }

    public function run(): MigrationResult
    {
        $templates = (int) $this->connection->executeStatement(
            "UPDATE tl_workflow_question SET pdfStatement = REPLACE(pdfStatement, '##value##', '##answer##') WHERE pdfStatement LIKE '%##value##%'",
        );

        $options = $this->migrateOptionBlobs();

        return $this->createResult(
            true,
            sprintf('Renamed ##value## to ##answer## in %d statement template(s) and %d option set(s).', $templates, $options),
        );
    }

    /**
     * Rewrites the ##value## token inside the serialized options blob in PHP, so
     * the serialized string-length prefixes stay correct.
     */
    private function migrateOptionBlobs(): int
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, options FROM tl_workflow_question WHERE options LIKE '%##value##%'",
        );

        $changed = 0;

        foreach ($rows as $row) {
            $options = @unserialize((string) $row['options'], ['allowed_classes' => false]);

            if (!\is_array($options)) {
                continue;
            }

            foreach ($options as &$option) {
                if (\is_array($option) && isset($option['statement']) && \is_string($option['statement'])) {
                    $option['statement'] = str_replace('##value##', '##answer##', $option['statement']);
                }
            }
            unset($option);

            $this->connection->update(
                'tl_workflow_question',
                ['options' => serialize($options)],
                ['id' => (int) $row['id']],
            );
            ++$changed;
        }

        return $changed;
    }
}
