<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\ImportLog;
use Psimandl\WorkflowBundle\Service\PdfStorage;
use Psimandl\WorkflowBundle\Service\WorkflowPurger;

/**
 * Everything that hangs off a workflow but is not one of its ctables, so Contao's cascade never
 * touches it: the import log, the send log and the generated documents.
 *
 * The two callers used to disagree about that list – the back-end delete forgot the send log,
 * the demo restore forgot all three – and what they forgot stayed behind pointing at a workflow
 * id that no longer resolves. This pins the list itself.
 */
final class WorkflowPurgerTest extends TestCase
{
    private Connection $connection;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE tl_workflow_send (id INTEGER PRIMARY KEY, workflowId INTEGER, state TEXT)',
        );

        $this->projectDir = sys_get_temp_dir().'/wf_purge_'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/var/workflow_pdfs/7', 0o777, true);
        file_put_contents($this->projectDir.'/var/workflow_pdfs/7/doc.pdf', '%PDF');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            exec('rm -rf '.escapeshellarg($this->projectDir));
        }
    }

    private function purger(ImportLog $log): WorkflowPurger
    {
        return new WorkflowPurger($this->connection, $log, new PdfStorage($this->projectDir));
    }

    public function testRemovesDocumentsImportLogAndSendLog(): void
    {
        $this->connection->insert('tl_workflow_send', ['id' => 1, 'workflowId' => 7, 'state' => 'queued']);
        $this->connection->insert('tl_workflow_send', ['id' => 2, 'workflowId' => 7, 'state' => 'sent']);
        $this->connection->insert('tl_workflow_send', ['id' => 3, 'workflowId' => 8, 'state' => 'sent']);

        $log = $this->createMock(ImportLog::class);
        $log->expects($this->once())->method('deleteFor')->with(7);

        $this->purger($log)->purge(7);

        $this->assertDirectoryDoesNotExist($this->projectDir.'/var/workflow_pdfs/7');
        $this->assertSame(
            ['3'],
            array_map('strval', $this->connection->fetchFirstColumn('SELECT id FROM tl_workflow_send')),
            'only the other workflow\'s send rows survive',
        );
    }

    /**
     * A send row that never reached a terminal state is kept for good by the purge cron (it only
     * expires sent/failed/bounced), so the workflow's own deletion is the one chance to remove it.
     */
    public function testRemovesSendRowsInAnyState(): void
    {
        $this->connection->insert('tl_workflow_send', ['id' => 1, 'workflowId' => 7, 'state' => 'queued']);

        $this->purger($this->createMock(ImportLog::class))->purge(7);

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_workflow_send'));
    }

    public function testIgnoresAnInvalidId(): void
    {
        $this->connection->insert('tl_workflow_send', ['id' => 1, 'workflowId' => 7, 'state' => 'sent']);

        $log = $this->createMock(ImportLog::class);
        $log->expects($this->never())->method('deleteFor');

        $this->purger($log)->purge(0);

        $this->assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_workflow_send'));
        $this->assertDirectoryExists($this->projectDir.'/var/workflow_pdfs/7');
    }

    /**
     * Called for a workflow that produced neither documents nor log rows – the normal case for a
     * workflow deleted right after it was created.
     */
    public function testIsSafeWhenThereIsNothingToRemove(): void
    {
        $this->purger($this->createMock(ImportLog::class))->purge(99);

        $this->assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_workflow_send'));
    }
}
