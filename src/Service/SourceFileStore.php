<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Service;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Dbafs;
use Contao\FilesModel;
use Contao\FileUpload;
use Contao\StringUtil;
use Contao\System;
use Contao\Validator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Puts source files where nobody can download them: in the protected folder
 * ({@see SourceFilePrivacy::protectedFolder()}), which the bundle creates on first use and
 * refuses to write to while it is public (3.6.2).
 *
 * Two ways in, one rule for names: nothing is ever overwritten (decision E12). A name that is
 * already taken gets a counter ("Mitglieder-2.xlsx") – overwriting would delete the content of
 * the file that was there, and that file may be another workflow's source. Nothing is deleted
 * either (E5); the one exception is an upload this class rejects itself, which never became a
 * file of the file manager.
 */
class SourceFileStore
{
    /** The file types a source file can have (the same list as tl_workflow.sourceFile). */
    public const EXTENSIONS = ['csv', 'xlsx', 'xls'];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly SourceFilePrivacy $privacy,
        private readonly SpreadsheetInspector $inspector,
        // Contao's own channel for file actions: the entries land in the system log, next to
        // the ones the file manager writes – where one looks when reconstructing who moved what.
        #[Autowire(service: 'monolog.logger.contao.files')]
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Stores an uploaded source file in the protected folder and registers it in the file
     * manager.
     *
     * $check runs on the stored file before it is registered (the column check of a locked
     * workflow, say); when it throws, the file is removed again and the exception passes on –
     * a refused upload leaves nothing behind.
     *
     * @param (callable(string): void)|null $check receives the absolute path
     *
     * @return array{file: FilesModel, path: string, requested: string, renamed: bool}
     *
     * @throws SourceFileException when the upload is not a usable source file or the folder is public
     * @throws \Throwable          whatever $check throws
     */
    public function store(UploadedFile $upload, ?callable $check = null): array
    {
        $this->loadLanguage();

        $requested = $this->validName($upload);
        $folder = $this->folder();
        $name = $this->freeName($folder, $requested);
        $absolute = $this->projectDir.'/'.$folder.'/'.$name;

        try {
            $upload->move(\dirname($absolute), $name);
        } catch (\Throwable $e) {
            throw new SourceFileException('upload_failed', [$e->getMessage()], $e);
        }

        try {
            $this->assertReadable($absolute);

            if (null !== $check) {
                $check($absolute);
            }
        } catch (\Throwable $e) {
            @unlink($absolute);

            throw $e;
        }

        return $this->register($folder.'/'.$name, $requested, $name);
    }

    /**
     * Moves a file into the protected folder. The file manager record moves with it
     * (Dbafs::moveResource keeps the UUID), so every workflow that reads it keeps reading it.
     *
     * @param string $path project-relative path of the file
     *
     * @return array{file: FilesModel, path: string, requested: string, renamed: bool}
     *
     * @throws SourceFileException
     */
    public function moveToProtected(string $path): array
    {
        $this->loadLanguage();

        $path = trim(str_replace('\\', '/', $path), '/');
        $source = $this->projectDir.'/'.$path;

        if (!is_file($source)) {
            throw new SourceFileException('move_missing', [$path]);
        }

        $folder = $this->folder();
        $requested = basename($path);
        $name = $this->freeName($folder, $requested);
        $destination = $folder.'/'.$name;

        if (!@rename($source, $this->projectDir.'/'.$destination)) {
            throw new SourceFileException('move_failed', [$path]);
        }

        /** @var Dbafs $dbafs */
        $dbafs = $this->framework->getAdapter(Dbafs::class);
        $file = $dbafs->moveResource($path, $destination);
        $this->privacy->reset();

        if (!$file instanceof FilesModel) {
            throw new SourceFileException('move_failed', [$path]);
        }

        $this->logger->info(sprintf('Workflow source file "%s" has been moved to the protected folder as "%s"', $path, $destination));

        return ['file' => $file, 'path' => $destination, 'requested' => $requested, 'renamed' => $name !== $requested];
    }

    /**
     * The protected folder, created when missing. Refuses while it – or a parent – is public:
     * a file put there would be downloadable the moment it arrives.
     *
     * @throws SourceFileException
     */
    public function folder(): string
    {
        $folder = $this->privacy->protectedFolder();
        $public = $this->privacy->publicFolderOf($folder);

        if (null !== $public) {
            throw new SourceFileException('folder_public', [$folder, $public]);
        }

        $absolute = $this->projectDir.'/'.$folder;

        if (!is_dir($absolute) && !@mkdir($absolute, 0777, true) && !is_dir($absolute)) {
            throw new SourceFileException('folder_create_failed', [$folder]);
        }

        return $folder;
    }

    /**
     * $name, or – when a file or folder of that name exists in $folder – the first free
     * "<name>-2.<ext>", "<name>-3.<ext>", …
     */
    public function freeName(string $folder, string $name): string
    {
        $directory = $this->projectDir.'/'.$folder;

        if (!file_exists($directory.'/'.$name)) {
            return $name;
        }

        $extension = Path::getExtension($name);
        $stem = '' === $extension ? $name : substr($name, 0, -\strlen($extension) - 1);
        $suffix = '' === $extension ? '' : '.'.$extension;

        for ($i = 2; ; ++$i) {
            $candidate = $stem.'-'.$i.$suffix;

            if (!file_exists($directory.'/'.$candidate)) {
                return $candidate;
            }
        }
    }

    /**
     * The client's file name, made safe like Contao's own upload does, and checked for being a
     * source file at all.
     *
     * @throws SourceFileException
     */
    private function validName(UploadedFile $upload): string
    {
        if (!$upload->isValid()) {
            if (\in_array($upload->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
                throw new SourceFileException('upload_too_big', [$this->readableMaxSize()]);
            }

            throw new SourceFileException('upload_failed', [(string) $upload->getError()]);
        }

        if ($upload->getSize() <= 0) {
            throw new SourceFileException('upload_empty');
        }

        if ($upload->getSize() > $this->maxSize()) {
            throw new SourceFileException('upload_too_big', [$this->readableMaxSize()]);
        }

        try {
            $name = StringUtil::sanitizeFileName($upload->getClientOriginalName());
        } catch (\InvalidArgumentException) {
            throw new SourceFileException('upload_name');
        }

        if ('' === trim($name, ' .') || !Validator::isValidFileName($name)) {
            throw new SourceFileException('upload_name');
        }

        if (!\in_array(strtolower(Path::getExtension($name)), self::EXTENSIONS, true)) {
            throw new SourceFileException('upload_type');
        }

        return $name;
    }

    /**
     * @throws SourceFileException
     */
    private function assertReadable(string $absolute): void
    {
        try {
            $sheets = $this->inspector->sheetNamesOf($absolute);
        } catch (\Throwable $e) {
            throw new SourceFileException('upload_unreadable', [$e->getMessage()], $e);
        }

        if ([] === $sheets) {
            throw new SourceFileException('upload_unreadable', ['–']);
        }
    }

    /**
     * @return array{file: FilesModel, path: string, requested: string, renamed: bool}
     */
    private function register(string $path, string $requested, string $name): array
    {
        /** @var Dbafs $dbafs */
        $dbafs = $this->framework->getAdapter(Dbafs::class);
        $file = $dbafs->addResource($path);
        $this->privacy->reset();

        if (!$file instanceof FilesModel || null === $file->uuid) {
            throw new SourceFileException('upload_failed', ['tl_files']);
        }

        $this->logger->info(sprintf('Workflow source file "%s" has been uploaded to the protected folder', $path));

        return ['file' => $file, 'path' => $path, 'requested' => $requested, 'renamed' => $name !== $requested];
    }

    private function maxSize(): int
    {
        $this->framework->initialize();

        return (int) $this->framework->getAdapter(FileUpload::class)->getMaxUploadSize();
    }

    private function readableMaxSize(): string
    {
        $bytes = $this->maxSize();

        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }

    /**
     * The texts of SourceFileException – loaded here, because the console and the import log
     * read the message as it is.
     */
    private function loadLanguage(): void
    {
        $this->framework->initialize();
        $this->framework->getAdapter(System::class)->loadLanguageFile('workflow_messages');
    }
}
