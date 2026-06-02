<?php

declare(strict_types=1);

namespace Psimandl\TrainerWorkflowBundle\Service;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Stores generated PDFs outside of the public web root.
 *
 * Files live under %kernel.project_dir%/var/trainer_pdfs/<workflowId>/<token>.pdf
 * and are never directly reachable; they are streamed through authenticated
 * backend routes only.
 */
class PdfStorage
{
    private readonly Filesystem $filesystem;

    public function __construct(private readonly string $projectDir)
    {
        $this->filesystem = new Filesystem();
    }

    public function getBaseDir(): string
    {
        return $this->projectDir.'/var/trainer_pdfs';
    }

    public function getWorkflowDir(int $workflowId): string
    {
        return $this->getBaseDir().'/'.$workflowId;
    }

    /**
     * Stores the PDF bytes and returns the path relative to the project dir.
     */
    public function store(int $workflowId, string $token, string $pdfContents): string
    {
        $dir = $this->getWorkflowDir($workflowId);
        $this->filesystem->mkdir($dir);

        $absolute = $dir.'/'.$token.'.pdf';
        $this->filesystem->dumpFile($absolute, $pdfContents);

        return 'var/trainer_pdfs/'.$workflowId.'/'.$token.'.pdf';
    }

    public function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir.'/'.ltrim($relativePath, '/');
    }

    public function exists(string $relativePath): bool
    {
        return '' !== $relativePath && $this->filesystem->exists($this->getAbsolutePath($relativePath));
    }
}
