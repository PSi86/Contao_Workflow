<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FilesModel;
use Doctrine\DBAL\Connection;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether a source file can be downloaded without a login, and which workflows read which file
 * (3.6.2, docs/dev/PLAN-source-file-privacy.md).
 *
 * A source file holds the personal data of every participant. In Contao a folder is served to
 * the web when it, or one of its parents, contains a ".public" marker file (Contao's own
 * Folder::isUnprotected() walks up the same way). Everything here reads the file system, never
 * a stored flag, so a folder published behind Contao's back (FTP, the hoster's file manager) is
 * caught as well.
 *
 * The protected folder ({@see protectedFolder()}) is where the bundle puts every file it
 * receives – uploads and files it moves out of a public folder. It is never published (see
 * FileManagerGuardListener).
 */
class SourceFilePrivacy implements ResetInterface
{
    /** Name of the protected folder, directly below the upload path ("files"). */
    public const FOLDER = 'workflow-quellen';

    /** @var array<string, list<array{id: int, title: string}>>|null project-relative source path => workflows reading it */
    private ?array $sources = null;

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly string $projectDir,
        private readonly string $uploadPath,
    ) {
    }

    /**
     * Project-relative path of the protected folder, e.g. "files/workflow-quellen". It may not
     * exist yet: the bundle creates it on first use.
     */
    public function protectedFolder(): string
    {
        return trim(str_replace('\\', '/', $this->uploadPath), '/').'/'.self::FOLDER;
    }

    /**
     * The folder whose ".public" marker serves $path to the web – $path itself when it is such
     * a folder – or null when $path is protected.
     *
     * @param string $path project-relative; need not exist (a file about to be written)
     */
    public function publicFolderOf(string $path): ?string
    {
        $path = $this->normalize($path);
        $folder = is_dir($this->projectDir.'/'.$path) ? $path : \dirname($path);

        while ('' !== $folder && '.' !== $folder && '/' !== $folder) {
            if (is_file($this->projectDir.'/'.$folder.'/.public')) {
                return $folder;
            }

            $folder = \dirname($folder);
        }

        return null;
    }

    /**
     * Project-relative path of the workflow's source file, or null when none is set or its
     * record is gone from the file manager.
     */
    public function sourcePath(WorkflowModel $workflow): ?string
    {
        if (!$workflow->sourceFile) {
            return null;
        }

        $this->framework->initialize();
        $file = $this->framework->getAdapter(FilesModel::class)->findByUuid($workflow->sourceFile);

        return null !== $file ? (string) $file->path : null;
    }

    /**
     * The folder that makes the workflow's source file downloadable without a login, or null.
     */
    public function publicFolderOfWorkflow(WorkflowModel $workflow): ?string
    {
        $path = $this->sourcePath($workflow);

        return null === $path ? null : $this->publicFolderOf($path);
    }

    /**
     * The workflows whose source file is $path or – when $path is a folder – lies anywhere below
     * it, ordered by title.
     *
     * @return list<array{id: int, title: string, path: string}>
     */
    public function workflowsUsing(string $path): array
    {
        $path = $this->normalize($path);
        $found = [];

        foreach ($this->sources() as $source => $workflows) {
            if ($source === $path || str_starts_with($source, $path.'/')) {
                foreach ($workflows as $workflow) {
                    $found[] = $workflow + ['path' => $source];
                }
            }
        }

        usort($found, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']) ?: $a['id'] <=> $b['id']);

        return $found;
    }

    /**
     * True when $path is the protected folder or one of its parents. Such a folder is never
     * moved and never published, whether or not a workflow uses a file in it right now.
     */
    public function containsProtectedFolder(string $path): bool
    {
        $path = $this->normalize($path);
        $folder = $this->protectedFolder();

        return $path === $folder || str_starts_with($folder, $path.'/');
    }

    /**
     * True when $path lies in the protected folder (at any depth) or is it. Nothing inside it
     * may be published either.
     */
    public function isInProtectedFolder(string $path): bool
    {
        $path = $this->normalize($path);
        $folder = $this->protectedFolder();

        return $path === $folder || str_starts_with($path, $folder.'/');
    }

    /**
     * The public URL path of a file in a public folder ("/files/verein/liste.xlsx"): Contao
     * serves "files/…" under the same path below the web root.
     */
    public function publicUrlPath(string $path): string
    {
        return '/'.implode('/', array_map('rawurlencode', explode('/', $this->normalize($path))));
    }

    /**
     * Forgets the cached source paths – after the bundle has stored or moved a file, and between
     * requests of a long-running worker.
     */
    public function reset(): void
    {
        $this->sources = null;
    }

    /**
     * @return array<string, list<array{id: int, title: string}>>
     */
    private function sources(): array
    {
        if (null === $this->sources) {
            $this->sources = [];

            $rows = $this->connection->fetchAllAssociative(
                'SELECT w.id, w.title, f.path FROM tl_workflow w INNER JOIN tl_files f ON f.uuid = w.sourceFile WHERE w.sourceFile IS NOT NULL',
            );

            foreach ($rows as $row) {
                $this->sources[$this->normalize((string) $row['path'])][] = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
            }
        }

        return $this->sources;
    }

    private function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
