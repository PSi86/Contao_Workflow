<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\EntryReset;
use Psimandl\WorkflowBundle\Service\TokenGenerator;
use Psimandl\WorkflowBundle\Service\WorkflowStatus;

/**
 * The participant reset and the address-change rules, against a real (in-memory) database:
 *
 *  - "Alle Teilnehmer zurücksetzen" takes the whole run back to "imported", not only the
 *    answered entries, and clears the send bookkeeping with it – but an address that is
 *    known to be undeliverable stays blocked until the address itself is changed.
 *  - Changing an entry's address voids everything that belonged to the old one. An entry
 *    that has not answered yet starts over: back to "imported", and a new link, because the
 *    old invitation may have reached somebody else.
 */
final class EntryResetTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE tl_workflow_entry (
                id           INTEGER PRIMARY KEY,
                pid          INTEGER NOT NULL DEFAULT 0,
                tstamp       INTEGER NOT NULL DEFAULT 0,
                token        TEXT    NOT NULL DEFAULT '',
                status       INTEGER NOT NULL DEFAULT 0,
                email        TEXT    NOT NULL DEFAULT '',
                data         BLOB    NULL,
                pdfPath      TEXT    NOT NULL DEFAULT '',
                sentAt       INTEGER NOT NULL DEFAULT 0,
                respondedAt  INTEGER NOT NULL DEFAULT 0,
                sendError    TEXT    NULL,
                sendErrorAt  INTEGER NOT NULL DEFAULT 0,
                bounceHard   TEXT    NOT NULL DEFAULT '',
                bounceInfo   TEXT    NOT NULL DEFAULT '',
                resultDoneAt INTEGER NOT NULL DEFAULT 0,
                resultError  TEXT    NOT NULL DEFAULT ''
            )
            SQL);
    }

    public function testResetTakesEveryEntryOfTheWorkflowBackToImported(): void
    {
        $this->entry(1, ['status' => WorkflowStatus::STATUS_RESPONDED, 'respondedAt' => 100, 'resultDoneAt' => 110, 'sentAt' => 50]);
        $this->entry(2, ['status' => WorkflowStatus::STATUS_INVITED, 'sentAt' => 50]);
        $this->entry(3, ['status' => WorkflowStatus::STATUS_IMPORTED, 'sendError' => 'Einladung: Connection refused', 'sendErrorAt' => 60]);
        $this->entry(4, ['status' => WorkflowStatus::STATUS_IMPORTED]);
        $this->entry(5, ['pid' => 8, 'status' => WorkflowStatus::STATUS_INVITED, 'sentAt' => 50]);

        self::assertSame(3, $this->reset()->countResettable(7), 'answered, invited and failed entries all carry run state');

        $affected = $this->reset()->resetWorkflow(7);

        self::assertSame(3, $affected, 'the untouched entry is not counted');

        foreach ([1, 2, 3] as $id) {
            $row = $this->row($id);
            self::assertSame(WorkflowStatus::STATUS_IMPORTED, (int) $row['status'], "entry $id is back at imported");
            self::assertSame(0, (int) $row['respondedAt'], "entry $id: answer time cleared");
            self::assertSame(0, (int) $row['resultDoneAt'], "entry $id: confirmation state cleared");
            self::assertSame(0, (int) $row['sentAt'], "entry $id: send time cleared");
            self::assertSame('', (string) $row['sendError'], "entry $id: send error cleared");
            self::assertSame(0, (int) $row['sendErrorAt'], "entry $id: send error time cleared");
        }

        self::assertSame(WorkflowStatus::STATUS_INVITED, (int) $this->row(5)['status'], 'another workflow is not touched');
        self::assertSame(0, $this->reset()->countResettable(7), 'nothing left to reset');
    }

    public function testResetKeepsAddressBlocksAnswersAndLinks(): void
    {
        $this->entry(1, [
            'status' => WorkflowStatus::STATUS_INVITED,
            'sentAt' => 50,
            'token' => 'keep-me',
            'data' => serialize(['Name' => 'Muster']),
            'pdfPath' => 'workflow_pdfs/7/x.pdf',
            'bounceHard' => '1',
            'bounceInfo' => 'x@example.org – 550 5.1.1 user unknown',
        ]);

        $this->reset()->resetWorkflow(7);

        $row = $this->row(1);
        self::assertSame('1', (string) $row['bounceHard'], 'a dead address stays blocked across a reset');
        self::assertSame('x@example.org – 550 5.1.1 user unknown', (string) $row['bounceInfo']);
        self::assertSame('keep-me', (string) $row['token'], 'the participant link survives');
        self::assertSame(['Name' => 'Muster'], unserialize((string) $row['data']), 'the answer data survives');
        self::assertSame('workflow_pdfs/7/x.pdf', (string) $row['pdfPath'], 'the document reference survives');
    }

    public function testAddressChangeRestartsAnUnansweredEntryWithANewLink(): void
    {
        $this->entry(1, [
            'status' => WorkflowStatus::STATUS_INVITED,
            'sentAt' => 50,
            'token' => 'old-token',
            'bounceHard' => '1',
            'bounceInfo' => 'old@example.org – 550 5.1.1',
            'sendError' => 'Erinnerung: timeout',
            'sendErrorAt' => 60,
        ]);

        $this->reset()->applyAddressChange(1);

        $row = $this->row(1);
        self::assertSame(WorkflowStatus::STATUS_IMPORTED, (int) $row['status'], 'the next invitation run writes to the new address');
        self::assertSame(0, (int) $row['sentAt']);
        self::assertSame('', (string) $row['bounceHard'], 'the block belonged to the old address');
        self::assertSame('', (string) $row['bounceInfo']);
        self::assertSame('', (string) $row['sendError'], 'the error belonged to the old address');
        self::assertSame(0, (int) $row['sendErrorAt']);
        self::assertNotSame('old-token', (string) $row['token'], 'the old link must stop working');
        self::assertNotSame('', (string) $row['token'], 'the entry gets a new link');
    }

    public function testAddressChangeKeepsAnAnswerAndReopensAConfirmationThatDidNotArrive(): void
    {
        $this->entry(1, [
            'status' => WorkflowStatus::STATUS_RESPONDED,
            'respondedAt' => 100,
            'resultDoneAt' => 110,
            'token' => 'answered-token',
            'bounceHard' => '1',
            'bounceInfo' => 'old@example.org – 550 5.1.1',
        ]);

        $this->reset()->applyAddressChange(1);

        $row = $this->row(1);
        self::assertSame(WorkflowStatus::STATUS_RESPONDED, (int) $row['status'], 'a corrected address does not void the answer');
        self::assertSame(100, (int) $row['respondedAt']);
        self::assertSame('answered-token', (string) $row['token'], 'an answered entry keeps its link');
        self::assertSame('', (string) $row['bounceHard']);
        self::assertSame(0, (int) $row['resultDoneAt'], 'the confirmation never arrived, so it is outstanding again');
    }

    public function testAddressChangeLeavesADeliveredConfirmationAlone(): void
    {
        $this->entry(1, [
            'status' => WorkflowStatus::STATUS_RESPONDED,
            'respondedAt' => 100,
            'resultDoneAt' => 110,
            'token' => 'answered-token',
        ]);

        $this->reset()->applyAddressChange(1);

        self::assertSame(110, (int) $this->row(1)['resultDoneAt'], 'nothing failed, so nothing is re-sent');
    }

    private function reset(): EntryReset
    {
        return new EntryReset($this->connection, new TokenGenerator());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function entry(int $id, array $values): void
    {
        $this->connection->insert('tl_workflow_entry', $values + ['id' => $id, 'pid' => 7, 'email' => "p$id@example.org"]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        return $this->connection->fetchAssociative('SELECT * FROM tl_workflow_entry WHERE id = ?', [$id]) ?: [];
    }
}
