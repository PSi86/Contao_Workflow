<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Contao\FilesModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

/**
 * Whether a source file can be downloaded without a login (3.6.2). The rule is Contao's: a
 * ".public" marker in the folder or in any parent publishes everything below it. A check that
 * looks only at the file's own folder misses the most common case – a source file in a
 * subfolder of a published "files/verein".
 */
final class SourceFilePrivacyTest extends SourceFileTestCase
{
    public function testAFileInAProtectedFolderIsNotPublic(): void
    {
        $this->touch('files/intern/liste.xlsx');

        $this->assertNull($this->privacy()->publicFolderOf('files/intern/liste.xlsx'));
    }

    public function testAFileInAPublishedFolderIsPublic(): void
    {
        $this->touch('files/verein/liste.xlsx');
        $this->publish('files/verein');

        $this->assertSame('files/verein', $this->privacy()->publicFolderOf('files/verein/liste.xlsx'));
    }

    public function testAPublishedParentPublishesEverySubfolder(): void
    {
        $this->touch('files/verein/2026/mitglieder/liste.xlsx');
        $this->publish('files/verein');

        $this->assertSame('files/verein', $this->privacy()->publicFolderOf('files/verein/2026/mitglieder/liste.xlsx'));
    }

    public function testAPublishedFolderIsItsOwnPublicFolder(): void
    {
        $this->touch('files/verein/liste.xlsx');
        $this->publish('files/verein');

        $this->assertSame('files/verein', $this->privacy()->publicFolderOf('files/verein'));
    }

    public function testAPathThatDoesNotExistYetIsJudgedByItsFolder(): void
    {
        $this->publish('files');

        $privacy = $this->privacy();

        $this->assertSame('files', $privacy->publicFolderOf('files/workflow-quellen'));
        $this->assertSame('files', $privacy->publicFolderOf('files/workflow-quellen/neu.csv'));
    }

    public function testTheProtectedFolderLivesBelowTheUploadPath(): void
    {
        $this->assertSame('files/workflow-quellen', $this->privacy()->protectedFolder());
    }

    public function testWorkflowsUsingAFileOrAnythingBelowAFolder(): void
    {
        $this->addWorkflow(1, 'Zweiter', 'files/verein/liste.xlsx');
        $this->addWorkflow(2, 'Erster', 'files/verein/2026/andere.csv');
        $this->addWorkflow(3, 'Fremd', 'files/vereinsheim/liste.xlsx');

        $privacy = $this->privacy();

        $this->assertSame([1], array_column($privacy->workflowsUsing('files/verein/liste.xlsx'), 'id'));
        // Ordered by title; "files/vereinsheim" only shares a prefix of the name, not the folder.
        $this->assertSame(['Erster', 'Zweiter'], array_column($privacy->workflowsUsing('files/verein'), 'title'));
        $this->assertSame([], $privacy->workflowsUsing('files/vere'));
    }

    public function testTheCacheOfSourcePathsCanBeReset(): void
    {
        $privacy = $this->privacy();
        $this->assertSame([], $privacy->workflowsUsing('files/a.csv'));

        $this->addWorkflow(1, 'Neu', 'files/a.csv');
        $this->assertSame([], $privacy->workflowsUsing('files/a.csv'), 'cached within a request');

        $privacy->reset();
        $this->assertSame([1], array_column($privacy->workflowsUsing('files/a.csv'), 'id'));
    }

    public function testTheProtectedFolderAndItsParentsAndContents(): void
    {
        $privacy = $this->privacy();

        $this->assertTrue($privacy->containsProtectedFolder('files/workflow-quellen'));
        $this->assertTrue($privacy->containsProtectedFolder('files'));
        $this->assertFalse($privacy->containsProtectedFolder('files/workflow-quellen/alt'));
        $this->assertFalse($privacy->containsProtectedFolder('files/workflow'));

        $this->assertTrue($privacy->isInProtectedFolder('files/workflow-quellen/alt/liste.csv'));
        $this->assertTrue($privacy->isInProtectedFolder('files/workflow-quellen'));
        $this->assertFalse($privacy->isInProtectedFolder('files/workflow-quellen-alt/liste.csv'));
    }

    public function testThePublicUrlPathIsEncodedPerSegment(): void
    {
        $this->assertSame('/files/verein/Mitglieder%202026%20%28neu%29.xlsx', $this->privacy()->publicUrlPath('files/verein/Mitglieder 2026 (neu).xlsx'));
    }

    public function testTheSourceFileOfAWorkflow(): void
    {
        $this->touch('files/verein/liste.xlsx');
        $this->publish('files/verein');
        $this->adapters[FilesModel::class] = fn (string $method): ?FilesModel => 'findByUuid' === $method ? $this->filesModel('files/verein/liste.xlsx') : null;

        $workflow = $this->createMock(WorkflowModel::class);
        $workflow->method('__get')->willReturnCallback(static fn (string $key): mixed => 'sourceFile' === $key ? 'some-uuid' : null);

        $privacy = $this->privacy();

        $this->assertSame('files/verein/liste.xlsx', $privacy->sourcePath($workflow));
        $this->assertSame('files/verein', $privacy->publicFolderOfWorkflow($workflow));
    }
}
