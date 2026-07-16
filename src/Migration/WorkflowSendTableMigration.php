<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

/**
 * Introduces the durable correlation table tl_workflow_send and retires the single-slot
 * tl_workflow_entry.sendParcelId / .sendKind columns.
 *
 * Contao runs migrations before the schema diff, so the table cannot exist yet when this
 * migration needs to move data into it — hence the explicit CREATE TABLE. Any minor
 * difference to the DCA definition (collation, engine) is reconciled by the schema diff
 * that runs right after this migration in the same contao:migrate call.
 *
 * Existing in-flight parcels are carried over as "queued"; the next send result (or a
 * later bounce) updates them via WorkflowMailResultListener. The old columns are then
 * dropped (pattern: {@see DropLegacyColumnsMigration}).
 */
class WorkflowSendTableMigration extends AbstractMigration
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

        return \in_array('sendparcelid', $columns, true);
    }

    public function run(): MigrationResult
    {
        $this->connection->executeStatement(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS tl_workflow_send (
                    id          int(10) unsigned NOT NULL auto_increment,
                    tstamp      int(10) unsigned NOT NULL default 0,
                    parcelId    varchar(64)  NOT NULL default '',
                    entryId     int(10) unsigned NOT NULL default 0,
                    workflowId  int(10) unsigned NOT NULL default 0,
                    kind        varchar(16)  NOT NULL default '',
                    recipient   varchar(255) NOT NULL default '',
                    state       varchar(16)  NOT NULL default '',
                    queuedAt    int(10) unsigned NOT NULL default 0,
                    sentAt      int(10) unsigned NOT NULL default 0,
                    bouncedAt   int(10) unsigned NOT NULL default 0,
                    error       text NULL,
                    bounceCode  varchar(255) NOT NULL default '',
                    PRIMARY KEY (id),
                    UNIQUE KEY parcelid (parcelId),
                    KEY entryid (entryId),
                    KEY recipient_state (recipient, state)
                ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL,
        );

        // Carry over any parcel that is still correlated on an entry. It is treated as
        // "queued"; a pending send result or bounce will move it on from there.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT IGNORE INTO tl_workflow_send
                    (tstamp, parcelId, entryId, workflowId, kind, recipient, state, queuedAt)
                SELECT ?, e.sendParcelId, e.id, e.pid, e.sendKind, e.email, 'queued', e.tstamp
                FROM tl_workflow_entry e
                WHERE e.sendParcelId <> ''
                SQL,
            [time()],
        );

        $this->connection->executeStatement('ALTER TABLE tl_workflow_entry DROP COLUMN IF EXISTS `sendParcelId`');
        $this->connection->executeStatement('ALTER TABLE tl_workflow_entry DROP COLUMN IF EXISTS `sendKind`');

        return $this->createResult(true, 'Created tl_workflow_send and migrated the in-flight parcel correlation off tl_workflow_entry.');
    }
}
