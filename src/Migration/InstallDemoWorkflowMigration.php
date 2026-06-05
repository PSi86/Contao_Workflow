<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Service\DemoWorkflowSeeder;

/**
 * Creates the synthetic demo workflow ONCE on a fresh installation, so the back end
 * is not empty after installing the bundle. Guarded by a marker file in var/ that
 * survives plugin updates AND the deletion of the demo – so updates never re-create
 * it. The demo can always be (re-)created from the "restore demo" button in the back
 * end overview (DemoWorkflowSeeder).
 *
 * Runs in the second pass of contao:migrate: the first pass creates the tables via the
 * schema diff, then this migration seeds the data. A seeding error never aborts the
 * migration (installation must not break); the marker is written regardless, so the
 * auto-install happens at most once.
 */
class InstallDemoWorkflowMigration extends AbstractMigration
{
    private const REQUIRED_TABLES = [
        'tl_workflow',
        'tl_workflow_master',
        'tl_workflow_question',
        'tl_workflow_rule',
        'tl_workflow_entry',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly DemoWorkflowSeeder $seeder,
        private readonly string $projectDir,
    ) {
    }

    public function shouldRun(): bool
    {
        if (is_file($this->markerFile())) {
            return false;
        }

        return $this->connection->createSchemaManager()->tablesExist(self::REQUIRED_TABLES);
    }

    public function run(): MigrationResult
    {
        $this->framework->initialize();

        try {
            $workflow = $this->seeder->seed();
            $message = sprintf('Demo-Workflow „%s" angelegt.', (string) $workflow->title);
        } catch (\Throwable $e) {
            $message = 'Demo-Workflow konnte nicht angelegt werden ('.$e->getMessage()
                .') – im Back end über „Demo-Workflow wiederherstellen" nachholbar.';
        }

        // Mark as installed regardless, so plugin updates never re-create the demo.
        @file_put_contents($this->markerFile(), date('c')."\n");

        return $this->createResult(true, $message);
    }

    private function markerFile(): string
    {
        return $this->projectDir.'/var/workflow_demo_installed';
    }
}
