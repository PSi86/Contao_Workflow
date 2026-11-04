<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Widget;

use Contao\UploadableWidgetInterface;
use Contao\Widget;
use Psimandl\WorkflowBundle\Service\SourceFileStore;

/**
 * "Datei hochladen" under the source-file picker of the edit mask (3.6.2): a plain file input.
 *
 * Choosing a file submits the mask at once, exactly like picking a file in the picker above
 * (tl_workflow.sourceFile has submitOnChange): the columns of the new file are needed for the
 * fields below it. The file itself is taken over by the save callback of sourceFile
 * (SourceFileSelectionListener::takeUpload), which stores it in the protected folder – this
 * widget never stores a value of its own (no column, $blnSubmitInput = false).
 *
 * Implementing UploadableWidgetInterface is what makes Contao send the edit form as
 * multipart/form-data (DataContainer::row()); without it the browser would send the file name
 * only.
 */
class SourceUploadWidget extends Widget implements UploadableWidgetInterface
{
    protected $blnSubmitInput = false;

    protected $strTemplate = 'be_widget';

    public function validate(): void
    {
        // Nothing to validate here: see the class comment.
    }

    public function generate(): string
    {
        $accept = implode(',', array_map(static fn (string $extension): string => '.'.$extension, SourceFileStore::EXTENSIONS));

        return sprintf(
            '<input type="file" name="%s" id="ctrl_%s" class="tl_upload_field" accept="%s" onchange="if(this.value){Backend.autoSubmit(this.form)}">',
            $this->strName,
            $this->strId,
            $accept,
        );
    }
}
