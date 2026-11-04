<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Psimandl\WorkflowBundle\Service\FileManagerGuard;

/**
 * What the file manager may not do to source files (3.6.2, E8, E9, E13): move or delete what a
 * workflow reads, move the protected folder, publish anything holding a source file or lying
 * in the protected folder. Everything else stays as Contao has it.
 */
final class FileManagerGuardTest extends SourceFileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->addWorkflow(1, 'Trainer 2026', 'files/verein/2026/liste.xlsx');
        $this->addWorkflow(2, 'Ehrenamt <neu>', 'files/verein/2026/liste.xlsx');
        $this->addWorkflow(3, 'Jugend', 'files/workflow-quellen/jugend.csv');
    }

    public function testASourceFileCannotBeMoved(): void
    {
        $refusal = $this->guard()->moveRefusal('files/verein/2026/liste.xlsx');

        $this->assertStringStartsWith('Quelldatei von: ', (string) $refusal);
        $this->assertStringContainsString('Trainer 2026', (string) $refusal);
        $this->assertStringContainsString('Ehrenamt &lt;neu&gt;', (string) $refusal, 'titles are escaped');
    }

    public function testAFolderHoldingASourceFileCannotBeMoved(): void
    {
        $this->assertStringStartsWith('Enthält Quelldateien von: ', (string) $this->guard()->moveRefusal('files/verein'));
    }

    public function testTheProtectedFolderCannotBeMovedEvenWhenEmpty(): void
    {
        $this->connection->executeStatement('DELETE FROM tl_workflow WHERE id = 3');

        $this->assertStringStartsWith('Ordner für die Quelldateien', (string) $this->guard()->moveRefusal('files/workflow-quellen'));
    }

    public function testEverythingElseCanBeMoved(): void
    {
        $guard = $this->guard();

        $this->assertNull($guard->moveRefusal('files/verein/2026/andere.xlsx'));
        $this->assertNull($guard->moveRefusal('files/vereinsheim'));
        $this->assertNull($guard->moveRefusal('files/workflow-quellen/unbenutzt.csv'), 'an unused file may leave the folder');
    }

    public function testWhatAWorkflowReadsCannotBeDeleted(): void
    {
        $guard = $this->guard();

        $this->assertStringStartsWith('Quelldatei von: ', (string) $guard->deleteRefusal('files/workflow-quellen/jugend.csv'));
        $this->assertStringStartsWith('Enthält Quelldateien von: ', (string) $guard->deleteRefusal('files/verein'));
    }

    public function testUnusedFilesCanBeDeleted(): void
    {
        $guard = $this->guard();

        // E13: everything that can be deleted is unused – and everything unused can be deleted,
        // the protected folder included once it holds no source file.
        $this->assertNull($guard->deleteRefusal('files/workflow-quellen/alt.csv'));
        $this->connection->executeStatement('DELETE FROM tl_workflow WHERE id = 3');
        $this->assertNull($this->guard()->deleteRefusal('files/workflow-quellen'));
    }

    public function testAFolderHoldingASourceFileIsNotPublished(): void
    {
        $refusal = (string) $this->guard()->publishRefusal('files/verein');

        $this->assertStringContainsString('„files/verein“ wurde nicht veröffentlicht', $refusal);
        $this->assertStringContainsString('Trainer 2026', $refusal);
    }

    public function testNothingInOrAboveTheProtectedFolderIsPublished(): void
    {
        $this->connection->executeStatement('DELETE FROM tl_workflow WHERE id = 3');
        $guard = $this->guard();

        $this->assertStringContainsString('für die Quelldateien der Workflows bestimmt', (string) $guard->publishRefusal('files/workflow-quellen'));
        $this->assertStringContainsString('für die Quelldateien der Workflows bestimmt', (string) $guard->publishRefusal('files/workflow-quellen/archiv'));
    }

    public function testOtherFoldersArePublishedAsBefore(): void
    {
        $this->assertNull($this->guard()->publishRefusal('files/bilder'));
    }

    private function guard(): FileManagerGuard
    {
        return new FileManagerGuard($this->framework(), $this->privacy());
    }
}
