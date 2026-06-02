<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Converts the legacy fixed accept/reject decision of each workflow into the new
 * configurable answer fields:
 *
 *  - a radio answer field (storage = old decisionField) with two options
 *    "ja" (labelAccept) and "nein" (labelReject),
 *  - a date answer field (storage = old dateField) when one was configured,
 *  - requireSignature enabled (the old form always required a signature).
 *
 * Runs in the second migration pass of contao:migrate, i.e. after the schema
 * diff has created tl_trainer_question; the legacy columns are kept (SQL only)
 * on tl_trainer_workflow so they can still be read here.
 */
class ConfigurableAnswersMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_trainer_workflow', 'tl_trainer_question'])) {
            return false;
        }

        $columns = array_keys($schemaManager->listTableColumns('tl_trainer_workflow'));

        if (!\in_array('decisionfield', array_map('strtolower', $columns), true)) {
            return false;
        }

        return [] !== $this->findPendingWorkflows();
    }

    public function run(): MigrationResult
    {
        $count = 0;

        foreach ($this->findPendingWorkflows() as $workflow) {
            $this->convertWorkflow($workflow);
            ++$count;
        }

        return $this->createResult(true, sprintf('Converted %d workflow(s) to configurable answer fields.', $count));
    }

    /**
     * Workflows that still carry a legacy decision/date configuration but have no
     * answer fields yet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findPendingWorkflows(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT w.id, w.decisionField, w.dateField, w.labelAccept, w.labelReject
             FROM tl_trainer_workflow w
             WHERE (w.decisionField != '' OR w.dateField != '')
               AND NOT EXISTS (SELECT 1 FROM tl_trainer_question q WHERE q.pid = w.id)",
        );
    }

    /**
     * @param array<string, mixed> $workflow
     */
    private function convertWorkflow(array $workflow): void
    {
        $now = time();
        $workflowId = (int) $workflow['id'];
        $sorting = 0;

        if ('' !== (string) $workflow['decisionField']) {
            $options = [
                ['value' => 'ja', 'label' => (string) ($workflow['labelAccept'] ?: 'Annehmen')],
                ['value' => 'nein', 'label' => (string) ($workflow['labelReject'] ?: 'Ablehnen')],
            ];

            $this->connection->insert('tl_trainer_question', [
                'pid'          => $workflowId,
                'sorting'      => $sorting += 128,
                'tstamp'       => $now,
                'label'        => 'Entscheidung',
                'type'         => 'radio',
                'storageField' => (string) $workflow['decisionField'],
                'mandatory'    => '1',
                'options'      => serialize($options),
            ]);
        }

        if ('' !== (string) $workflow['dateField']) {
            $this->connection->insert('tl_trainer_question', [
                'pid'          => $workflowId,
                'sorting'      => $sorting += 128,
                'tstamp'       => $now,
                'label'        => 'Datum',
                'type'         => 'date',
                'storageField' => (string) $workflow['dateField'],
                'mandatory'    => '',
                'options'      => null,
            ]);
        }

        $this->connection->update(
            'tl_trainer_workflow',
            ['requireSignature' => '1', 'tstamp' => $now],
            ['id' => $workflowId],
        );
    }
}
