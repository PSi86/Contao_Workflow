<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Renames the placeholder namespaces to match the UI terminology:
 *   ##var_<slug>##   ->  ##letterhead_<slug>##   (Briefpapier / stationery)
 *   ##stmt_<slug>##  ->  ##text_<slug>##         (Dokument-Texte / statements)
 *   ##stmt_all##     ->  ##text_all##
 *
 * Rewrites existing configurations: rule bodies, the workflow heading/intro and
 * question statement templates (plain-text columns, safe to REPLACE in SQL), the
 * per-option statements inside the serialized options blob (rewritten in PHP so
 * the serialized string-length prefixes stay valid – ##var_ and ##letterhead_
 * differ in length), and the workflow Notification-Center mail texts (scoped to
 * notifications of type "workflow" so unrelated messages are never touched).
 */
class RenameVarStmtTokensMigration extends AbstractMigration
{
    private const REPLACEMENTS = [
        '##var_' => '##letterhead_',
        '##stmt_' => '##text_',
    ];

    /** @var array<int, array{0: string, 1: string}> table => column (plain-text) */
    private const PLAIN_COLUMNS = [
        ['tl_workflow', 'pdfTitle'],
        ['tl_workflow', 'introText'],
        ['tl_workflow_rule', 'pdfBody'],
        ['tl_workflow_question', 'pdfStatement'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        foreach ($this->plainTargets($schemaManager) as [$table, $column]) {
            if ($this->columnHasLegacyToken($table, $column)) {
                return true;
            }
        }

        if ($this->ncTablesExist($schemaManager)
            && ($this->columnHasLegacyToken('tl_nc_language', 'email_text')
                || $this->columnHasLegacyToken('tl_nc_language', 'email_subject'))
        ) {
            return true;
        }

        return false;
    }

    public function run(): MigrationResult
    {
        $schemaManager = $this->connection->createSchemaManager();
        $rows = 0;

        foreach ($this->plainTargets($schemaManager) as [$table, $column]) {
            $rows += $this->replaceInColumn($table, $column);
        }

        $options = $this->migrateOptionBlobs($schemaManager);
        $mails = $this->migrateNotificationTexts($schemaManager);

        return $this->createResult(true, sprintf(
            'Renamed ##var_/##stmt_ tokens to ##letterhead_/##text_ in %d text field(s), %d option set(s) and %d notification text(s).',
            $rows,
            $options,
            $mails,
        ));
    }

    /**
     * @param \Doctrine\DBAL\Schema\AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform> $schemaManager
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function plainTargets(object $schemaManager): array
    {
        // Filter on the actual column, not just the table: on an upgrade the table
        // can exist before the schema diff adds a newer column (e.g. introText /
        // pdfStatement), and a LIKE on a missing column aborts contao:migrate with
        // "Unknown column".
        return array_values(array_filter(
            self::PLAIN_COLUMNS,
            fn (array $target): bool => $this->columnExists($schemaManager, $target[0], $target[1]),
        ));
    }

    private function columnExists(object $schemaManager, string $table, string $column): bool
    {
        if (!$schemaManager->tablesExist([$table])) {
            return false;
        }

        $existing = array_map('strtolower', array_keys($schemaManager->listTableColumns($table)));

        return \in_array(strtolower($column), $existing, true);
    }

    private function ncTablesExist(object $schemaManager): bool
    {
        return $schemaManager->tablesExist(['tl_nc_language', 'tl_nc_message', 'tl_nc_notification']);
    }

    private function columnHasLegacyToken(string $table, string $column): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM `$table` WHERE `$column` LIKE '%##var\_%' OR `$column` LIKE '%##stmt\_%' LIMIT 1",
        );
    }

    private function replaceInColumn(string $table, string $column): int
    {
        return (int) $this->connection->executeStatement(
            "UPDATE `$table` SET `$column` = REPLACE(REPLACE(`$column`, '##var_', '##letterhead_'), '##stmt_', '##text_') "
            ."WHERE `$column` LIKE '%##var\_%' OR `$column` LIKE '%##stmt\_%'",
        );
    }

    private function migrateOptionBlobs(object $schemaManager): int
    {
        if (!$schemaManager->tablesExist(['tl_workflow_question'])) {
            return 0;
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, options FROM tl_workflow_question WHERE options LIKE '%##var\_%' OR options LIKE '%##stmt\_%'",
        );

        $changed = 0;

        foreach ($rows as $row) {
            $options = @unserialize((string) $row['options'], ['allowed_classes' => false]);

            if (!\is_array($options)) {
                continue;
            }

            foreach ($options as &$option) {
                if (\is_array($option) && isset($option['statement']) && \is_string($option['statement'])) {
                    $option['statement'] = strtr($option['statement'], self::REPLACEMENTS);
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

    private function migrateNotificationTexts(object $schemaManager): int
    {
        if (!$this->ncTablesExist($schemaManager)) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'UPDATE tl_nc_language l '
            .'INNER JOIN tl_nc_message m ON m.id = l.pid '
            .'INNER JOIN tl_nc_notification n ON n.id = m.pid '
            ."SET l.email_text = REPLACE(REPLACE(l.email_text, '##var_', '##letterhead_'), '##stmt_', '##text_'), "
            ."l.email_subject = REPLACE(REPLACE(l.email_subject, '##var_', '##letterhead_'), '##stmt_', '##text_') "
            ."WHERE n.type = 'workflow' AND ("
            ."l.email_text LIKE '%##var\_%' OR l.email_text LIKE '%##stmt\_%' "
            ."OR l.email_subject LIKE '%##var\_%' OR l.email_subject LIKE '%##stmt\_%')",
        );
    }
}
