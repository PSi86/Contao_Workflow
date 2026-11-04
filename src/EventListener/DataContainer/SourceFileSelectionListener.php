<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\FilesModel;
use Contao\StringUtil;
use Contao\System;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Service\SourceColumnCheck;
use Psimandl\WorkflowBundle\Service\SourceFileException;
use Psimandl\WorkflowBundle\Service\SourceFilePermission;
use Psimandl\WorkflowBundle\Service\SourceFilePrivacy;
use Psimandl\WorkflowBundle\Service\SourceFileReport;
use Psimandl\WorkflowBundle\Service\SourceFileStore;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * How a source file gets into the edit mask without ever being public (3.6.2):
 *
 *  1. the field "Datei hochladen" (SourceUploadWidget, a pseudo field right under the picker):
 *     the file goes into the protected folder and becomes the value of tl_workflow.sourceFile;
 *  2. a file picked in Contao's file picker is refused when it lies in a public folder (E10) –
 *     but only a NEW pick: a workflow whose file is already public stays savable, the warning
 *     with the "move" button is what fixes it.
 *
 * Both run as save callbacks of sourceFile, not of the upload field: Contao collects the
 * values of a form and writes them in one go (DC_Table::submit()), so only the callback of the
 * field itself can change what is stored. They run before SourceFileGuardListener (priority
 * 20 and 10 against 0), which then checks the columns of the file that will really be stored.
 */
class SourceFileSelectionListener
{
    /** The pseudo field of the upload, see the DCA. */
    public const UPLOAD_FIELD = 'sourceUpload';

    public function __construct(
        private readonly SourceFileStore $store,
        private readonly SourceFilePrivacy $privacy,
        private readonly SourceColumnCheck $columnCheck,
        private readonly SourceFilePermission $permission,
        private readonly SourceFileReport $report,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * The upload field follows the permission of the source file itself: whoever may not change
     * the source file does not get a second way to do it.
     */
    #[AsCallback(table: 'tl_workflow', target: 'config.onload')]
    public function hideUploadWithoutPermission(): void
    {
        if ($this->permission->canChange()) {
            return;
        }

        foreach ($GLOBALS['TL_DCA']['tl_workflow']['palettes'] ?? [] as $name => $palette) {
            if (\is_string($palette)) {
                $GLOBALS['TL_DCA']['tl_workflow']['palettes'][$name] = str_replace(','.self::UPLOAD_FIELD, '', $palette);
            }
        }
    }

    #[AsCallback(table: 'tl_workflow', target: 'fields.sourceFile.save', priority: 20)]
    public function takeUpload(mixed $value, DataContainer $dc): mixed
    {
        $upload = $this->upload($dc);

        if (null === $upload) {
            return $value;
        }

        $workflow = WorkflowModel::findByPk((int) $dc->id);

        if (null === $workflow || !$this->permission->canChange()) {
            return $value;
        }

        System::loadLanguageFile('workflow_messages');

        $previous = $this->privacy->sourcePath($workflow);
        $stored = $this->store->store($upload, fn (string $path) => $this->columnCheck->assertSameColumns($workflow, $path));

        $this->report->uploaded($stored, $previous, (int) $workflow->id);

        return $stored['file']->uuid;
    }

    #[AsCallback(table: 'tl_workflow', target: 'fields.sourceFile.save', priority: 10)]
    public function rejectPublicFolder(mixed $value, DataContainer $dc): mixed
    {
        if (!$value) {
            return $value;
        }

        $workflow = WorkflowModel::findByPk((int) $dc->id);

        if (null !== $workflow && $this->uuid($workflow->sourceFile) === $this->uuid($value)) {
            return $value;
        }

        $file = FilesModel::findByUuid($value);
        $public = null !== $file ? $this->privacy->publicFolderOf((string) $file->path) : null;

        if (null === $public) {
            return $value;
        }

        System::loadLanguageFile('workflow_messages');

        throw new SourceFileException('select_public', [$public, $this->privacy->protectedFolder()]);
    }

    /**
     * The file sent in the upload field of this record ("sourceUpload", in "edit multiple"
     * "sourceUpload_<id>" – the suffix Contao gives every field of the record).
     */
    private function upload(DataContainer $dc): ?UploadedFile
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        $suffix = substr((string) $dc->inputName, \strlen((string) $dc->field));
        $file = $request->files->get(self::UPLOAD_FIELD.$suffix);

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * A UUID in one spelling: the stored value is binary, a posted one may be the string form.
     */
    private function uuid(mixed $value): string
    {
        $value = (string) $value;

        return 16 === \strlen($value) ? StringUtil::binToUuid($value) : strtolower($value);
    }
}
