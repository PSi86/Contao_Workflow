<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Converts the legacy tl_workflow.inputFields setting (source columns shown
 * read-only above the questions) into "display" answer fields, then drops the
 * column. The display questions are inserted before the existing questions so
 * the rendered form keeps its order. Runs before the schema update, so the
 * column still exists on the first migrate after deploying this version.
 */
class InputFieldsToDisplayQuestionsMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_workflow', 'tl_workflow_question'])) {
            return false;
        }

        $columns = array_map('strtolower', array_keys($schemaManager->listTableColumns('tl_workflow')));

        return \in_array('inputfields', $columns, true);
    }

    public function run(): MigrationResult
    {
        $workflows = $this->connection->fetchAllAssociative('SELECT id, inputFields FROM tl_workflow');
        $created = 0;

        foreach ($workflows as $workflow) {
            $fields = $this->extractFields($workflow['inputFields'] ?? null);

            if ([] === $fields) {
                continue;
            }

            $workflowId = (int) $workflow['id'];

            // Insert before the existing questions (sorting is unsigned, so
            // renumber everything instead of going below the current minimum).
            $sorting = 0;

            foreach ($fields as $field) {
                $this->connection->insert('tl_workflow_question', [
                    'pid'          => $workflowId,
                    'tstamp'       => time(),
                    'sorting'      => $sorting += 64,
                    'label'        => $field,
                    'type'         => 'display',
                    'storageField' => $field,
                ]);

                ++$created;
            }

            $existing = $this->connection->fetchFirstColumn(
                "SELECT id FROM tl_workflow_question WHERE pid = ? AND type != 'display' ORDER BY sorting",
                [$workflowId],
            );

            foreach ($existing as $id) {
                $this->connection->update('tl_workflow_question', ['sorting' => $sorting += 64], ['id' => (int) $id]);
            }
        }

        $this->connection->executeStatement('ALTER TABLE tl_workflow DROP COLUMN `inputFields`');

        return $this->createResult(true, sprintf('Converted inputFields into %d display question(s).', $created));
    }

    /**
     * @return array<int, string>
     */
    private function extractFields(mixed $value): array
    {
        if (!\is_string($value) || '' === $value) {
            return [];
        }

        $fields = @unserialize($value, ['allowed_classes' => false]);

        if (!\is_array($fields)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($name): string => trim((string) $name), $fields),
            static fn (string $name): bool => '' !== $name,
        ));
    }
}
