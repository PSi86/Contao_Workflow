<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use Contao\Dbafs;
use Contao\FileUpload;
use Psimandl\WorkflowBundle\Service\SourceFileException;
use Psimandl\WorkflowBundle\Service\SourceFileStore;
use Psimandl\WorkflowBundle\Service\SpreadsheetInspector;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where an uploaded or moved source file ends up (3.6.2): always in the protected folder,
 * never over an existing file (E12), and a refused upload leaves nothing behind.
 */
final class SourceFileStoreTest extends SourceFileTestCase
{
    private const CSV = "E-Mail;Name\nanna@example.org;Anna\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapters[FileUpload::class] = static fn (string $method): mixed => 'getMaxUploadSize' === $method ? 1048576 : null;
        $this->adapters[Dbafs::class] = fn (string $method, array $args): mixed => match ($method) {
            'addResource' => $this->filesModel($args[0], 'uuid:'.$args[0]),
            'moveResource' => $this->filesModel($args[1], 'kept-uuid'),
            default => null,
        };
    }

    public function testAnUploadGoesIntoTheProtectedFolder(): void
    {
        $stored = $this->store()->store($this->upload('Mitglieder.csv'));

        $this->assertSame('files/workflow-quellen/Mitglieder.csv', $stored['path']);
        $this->assertFalse($stored['renamed']);
        $this->assertStringEqualsFile($this->projectDir.'/files/workflow-quellen/Mitglieder.csv', self::CSV);
        $this->assertSame([['files/workflow-quellen/Mitglieder.csv']], $this->callsOf(Dbafs::class, 'addResource'));
        $this->assertSame('uuid:files/workflow-quellen/Mitglieder.csv', $stored['file']->uuid);
    }

    public function testAnExistingFileIsNeverOverwritten(): void
    {
        $this->touch('files/workflow-quellen/Mitglieder.csv', 'the older export');

        $second = $this->store()->store($this->upload('Mitglieder.csv'));
        $third = $this->store()->store($this->upload('Mitglieder.csv'));

        $this->assertSame('files/workflow-quellen/Mitglieder-2.csv', $second['path']);
        $this->assertSame('files/workflow-quellen/Mitglieder-3.csv', $third['path']);
        $this->assertTrue($second['renamed']);
        $this->assertSame('Mitglieder.csv', $second['requested']);
        $this->assertStringEqualsFile($this->projectDir.'/files/workflow-quellen/Mitglieder.csv', 'the older export');
    }

    public function testTheNameIsMadeSafeLikeContaoDoes(): void
    {
        $stored = $this->store()->store($this->upload('Liste: 2026?.csv'));

        $this->assertSame('files/workflow-quellen/Liste- 2026-.csv', $stored['path']);
    }

    public function testOnlySpreadsheetsAreAccepted(): void
    {
        $this->assertRefused('upload_type', fn () => $this->store()->store($this->upload('liste.pdf')));
        $this->assertDirectoryDoesNotExist($this->projectDir.'/files/workflow-quellen', 'refused before anything is written');
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->assertRefused('upload_empty', fn () => $this->store()->store($this->upload('leer.csv', '')));
    }

    public function testAFileAboveTheUploadLimitIsRefused(): void
    {
        $this->adapters[FileUpload::class] = static fn (): int => 10;

        $this->assertRefused('upload_too_big', fn () => $this->store()->store($this->upload('gross.csv')));
    }

    public function testARefusedCheckLeavesNothingBehind(): void
    {
        $store = $this->store();

        try {
            $store->store($this->upload('Mitglieder.csv'), static function (string $path): void {
                throw new \RuntimeException('other columns: '.basename($path));
            });
            $this->fail('The check must stop the upload.');
        } catch (\RuntimeException $e) {
            $this->assertSame('other columns: Mitglieder.csv', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->projectDir.'/files/workflow-quellen/Mitglieder.csv');
        $this->assertSame([], $this->callsOf(Dbafs::class, 'addResource'), 'a refused upload is not registered');
    }

    public function testThePathHandedToTheCheckIsTheStoredFile(): void
    {
        $seen = null;
        $this->store()->store($this->upload('Mitglieder.csv'), static function (string $path) use (&$seen): void {
            $seen = file_get_contents($path);
        });

        $this->assertSame(self::CSV, $seen);
    }

    public function testNothingIsStoredWhileTheProtectedFolderIsPublic(): void
    {
        $this->touch('files/workflow-quellen/alt.csv');
        $this->publish('files/workflow-quellen');

        $this->assertRefused('folder_public', fn () => $this->store()->store($this->upload('Mitglieder.csv')));
        $this->assertFileDoesNotExist($this->projectDir.'/files/workflow-quellen/Mitglieder.csv');
    }

    public function testNothingIsStoredWhileAParentOfTheProtectedFolderIsPublic(): void
    {
        $this->publish('files');

        $this->assertRefused('folder_public', fn () => $this->store()->store($this->upload('Mitglieder.csv')));
        $this->assertDirectoryDoesNotExist($this->projectDir.'/files/workflow-quellen');
    }

    public function testMovingKeepsTheRecordAndNeverOverwrites(): void
    {
        $this->touch('files/verein/liste.xlsx', 'public data');
        $this->touch('files/workflow-quellen/liste.xlsx', 'another file');

        $moved = $this->store()->moveToProtected('files/verein/liste.xlsx');

        $this->assertSame('files/workflow-quellen/liste-2.xlsx', $moved['path']);
        $this->assertTrue($moved['renamed']);
        $this->assertFileDoesNotExist($this->projectDir.'/files/verein/liste.xlsx');
        $this->assertStringEqualsFile($this->projectDir.'/files/workflow-quellen/liste-2.xlsx', 'public data');
        $this->assertStringEqualsFile($this->projectDir.'/files/workflow-quellen/liste.xlsx', 'another file');
        $this->assertSame([['files/verein/liste.xlsx', 'files/workflow-quellen/liste-2.xlsx']], $this->callsOf(Dbafs::class, 'moveResource'));
        $this->assertSame('kept-uuid', $moved['file']->uuid);
    }

    public function testMovingAMissingFileIsRefused(): void
    {
        $this->assertRefused('move_missing', fn () => $this->store()->moveToProtected('files/verein/weg.xlsx'));
    }

    public function testFreeNamesForFilesWithoutExtension(): void
    {
        $this->touch('files/workflow-quellen/LIESMICH');

        $this->assertSame('LIESMICH-2', $this->store()->freeName('files/workflow-quellen', 'LIESMICH'));
        $this->assertSame('neu.csv', $this->store()->freeName('files/workflow-quellen', 'neu.csv'));
    }

    private function store(): SourceFileStore
    {
        $framework = $this->framework();

        return new SourceFileStore(
            $framework,
            $this->privacy(),
            new SpreadsheetInspector($framework, $this->projectDir),
            new NullLogger(),
            $this->projectDir,
        );
    }

    private function upload(string $name, string $content = self::CSV): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'wf_upload_');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function assertRefused(string $key, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the refusal "'.$key.'".');
        } catch (SourceFileException $e) {
            $this->assertSame($key, $e->key);
            $this->assertNotSame($key, $e->getMessage(), 'the message is the text, not the key');
        }
    }
}
