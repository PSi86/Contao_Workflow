<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Psimandl\WorkflowBundle\Service\PdfStorage;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Where the generated documents end up. Two properties matter beyond "the file is written":
 * a second entry must never overwrite another one's document, and re-generating the same entry
 * must overwrite its own instead of littering the directory – the stored path in
 * tl_workflow_entry.pdfPath has to keep pointing at the current file either way.
 *
 * The names arrive from Slugger::fileName(), so they carry umlauts and any other script; the
 * collision handling has to work on those unchanged.
 */
final class PdfStorageTest extends TestCase
{
    private string $projectDir;
    private PdfStorage $storage;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/wf_pdfs_'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->projectDir);

        $this->storage = new PdfStorage($this->projectDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    public function testStoresUnderTheGivenUnicodeName(): void
    {
        $path = $this->storage->store(7, 'Verzicht_Müller_Jürgen', 'token-a', '%PDF-1.4');

        $this->assertSame('var/workflow_pdfs/7/Verzicht_Müller_Jürgen.pdf', $path);
        $this->assertSame('%PDF-1.4', file_get_contents($this->storage->getAbsolutePath($path)));
    }

    /**
     * Two participants with the same name: the second document gets a suffix rather than
     * silently replacing the first.
     */
    public function testCollidingNamesGetASuffix(): void
    {
        $first = $this->storage->store(7, 'Verzicht_Müller', 'aaaabbbbccccdddd', 'one');
        $second = $this->storage->store(7, 'Verzicht_Müller', 'eeeeffffgggghhhh', 'two');

        $this->assertNotSame($first, $second);
        $this->assertSame('one', file_get_contents($this->storage->getAbsolutePath($first)));
        $this->assertSame('two', file_get_contents($this->storage->getAbsolutePath($second)));
        $this->assertSame(2, $this->storage->countWorkflowPdfs(7));
    }

    /**
     * Re-generating an entry's document (a corrected answer, a changed template) must land on
     * the same file – otherwise every regeneration would leave an orphan behind and the entry's
     * stored path would drift.
     */
    public function testRegenerationOverwritesTheEntrysOwnFile(): void
    {
        $first = $this->storage->store(7, 'Verzicht_Müller', 'token-a', 'one');
        $again = $this->storage->store(7, 'Verzicht_Müller', 'token-a', 'two', $first);

        $this->assertSame($first, $again);
        $this->assertSame('two', file_get_contents($this->storage->getAbsolutePath($first)));
        $this->assertSame(1, $this->storage->countWorkflowPdfs(7));
    }

    public function testEmptyBaseNameFallsBackToTheToken(): void
    {
        $path = $this->storage->store(7, '', 'token-a', 'x');

        $this->assertSame('var/workflow_pdfs/7/token-a.pdf', $path);
    }

    /**
     * The base name is sanitised upstream (Slugger::fileName), but the directory boundary is
     * what protects the rest of the installation, so it is pinned here as well.
     */
    public function testFilesStayInsideTheWorkflowDirectory(): void
    {
        $path = $this->storage->store(7, '..'.\DIRECTORY_SEPARATOR.'escaped', 'token-a', 'x');
        $absolute = realpath($this->storage->getAbsolutePath($path));

        $this->assertNotFalse($absolute);
        $this->assertStringStartsWith((string) realpath($this->storage->getWorkflowDir(7)), $absolute);
    }
}
