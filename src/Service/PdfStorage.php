<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Stores generated PDFs outside of the public web root.
 *
 * Files live under %kernel.project_dir%/var/workflow_pdfs/<workflowId>/<token>.pdf
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
        return $this->projectDir.'/var/workflow_pdfs';
    }

    public function getWorkflowDir(int $workflowId): string
    {
        return $this->getBaseDir().'/'.$workflowId;
    }

    /**
     * Stores the PDF bytes under a configurable base name (sanitized; the entry
     * token is the fallback) and returns the project-relative path. On collision
     * with another entry's file a short token is appended; re-generating the same
     * entry overwrites its own existing file (existingRelativePath).
     */
    public function store(int $workflowId, string $baseName, string $token, string $pdfContents, string $existingRelativePath = ''): string
    {
        $dir = $this->getWorkflowDir($workflowId);
        $this->filesystem->mkdir($dir);

        // Re-generation: overwrite the entry's own file (must live in this dir).
        if ('' !== $existingRelativePath) {
            $absExisting = $this->getAbsolutePath($existingRelativePath);

            if (str_starts_with($absExisting, $dir.'/')) {
                $this->filesystem->dumpFile($absExisting, $pdfContents);

                return $existingRelativePath;
            }
        }

        $name = $this->uniqueName($dir, '' !== $baseName ? $baseName : $token, $token);
        $this->filesystem->dumpFile($dir.'/'.$name.'.pdf', $pdfContents);

        return 'var/workflow_pdfs/'.$workflowId.'/'.$name.'.pdf';
    }

    /**
     * Returns a file base name (without extension) that does not yet exist in the
     * directory, appending an increasingly long token suffix on collision.
     */
    private function uniqueName(string $dir, string $base, string $token): string
    {
        if (!$this->filesystem->exists($dir.'/'.$base.'.pdf')) {
            return $base;
        }

        for ($len = 4; $len <= \strlen($token); $len += 2) {
            $candidate = $base.'_'.substr($token, 0, $len);

            if (!$this->filesystem->exists($dir.'/'.$candidate.'.pdf')) {
                return $candidate;
            }
        }

        return $base.'_'.$token;
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
