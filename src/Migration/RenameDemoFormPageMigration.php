<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Renames the former demo-specific form page (and its article, module and theme) to the
 * generic, shared naming "Workflow-Formular" / alias "workflow-formular". The page is
 * usable by every workflow (the token in the URL identifies the entry and workflow), so a
 * demo-only name was misleading.
 *
 * The page id stays the same, so any workflow's "form page" reference keeps working – only
 * the alias (and thus the URL) changes to "/workflow-formular/<token>". Renaming in place
 * (instead of letting the seeder create a second page under the new alias) avoids a
 * duplicate on existing installations.
 *
 * Gated on the old page alias existing while the new one does not; each rename is guarded
 * individually so a pre-existing record under the new name is never clobbered.
 */
class RenameDemoFormPageMigration extends AbstractMigration
{
    private const OLD_ALIAS = 'workflow-formular-demo';
    private const NEW_ALIAS = 'workflow-formular';
    private const OLD_TITLE = 'Workflow-Formular (Demo)';
    private const NEW_TITLE = 'Workflow-Formular';
    private const OLD_THEME = 'Workflow Demo';
    private const NEW_THEME = 'Workflow';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['tl_page'])) {
            return false;
        }

        return $this->aliasExists('tl_page', self::OLD_ALIAS)
            && !$this->aliasExists('tl_page', self::NEW_ALIAS);
    }

    public function run(): MigrationResult
    {
        $schemaManager = $this->connection->createSchemaManager();

        // Page: alias + title (only when the target alias is still free).
        if (!$this->aliasExists('tl_page', self::NEW_ALIAS)) {
            $this->connection->executeStatement(
                'UPDATE tl_page SET alias = ?, title = ? WHERE alias = ?',
                [self::NEW_ALIAS, self::NEW_TITLE, self::OLD_ALIAS],
            );
        }

        // Article hosting the module: alias + title.
        if ($schemaManager->tablesExist(['tl_article']) && !$this->aliasExists('tl_article', self::NEW_ALIAS)) {
            $this->connection->executeStatement(
                'UPDATE tl_article SET alias = ?, title = ? WHERE alias = ?',
                [self::NEW_ALIAS, self::NEW_TITLE, self::OLD_ALIAS],
            );
        }

        // The workflow_form module name.
        if ($schemaManager->tablesExist(['tl_module'])) {
            $this->connection->executeStatement(
                "UPDATE tl_module SET name = ? WHERE name = ? AND type = 'workflow_form'",
                [self::NEW_TITLE, self::OLD_TITLE],
            );
        }

        // The theme holding the module (skip if a "Workflow" theme already exists).
        if ($schemaManager->tablesExist(['tl_theme'])
            && false === $this->connection->fetchOne('SELECT id FROM tl_theme WHERE name = ? LIMIT 1', [self::NEW_THEME])
        ) {
            $this->connection->executeStatement(
                'UPDATE tl_theme SET name = ? WHERE name = ?',
                [self::NEW_THEME, self::OLD_THEME],
            );
        }

        return $this->createResult(true, 'Renamed the demo form page to the shared "workflow-formular" page.');
    }

    private function aliasExists(string $table, string $alias): bool
    {
        return false !== $this->connection->fetchOne("SELECT id FROM $table WHERE alias = ? LIMIT 1", [$alias]);
    }
}
