<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Psimandl\WorkflowBundle\Service\PdfStorage;

/**
 * Removes a workflow's generated PDF documents from disk when the workflow itself
 * is deleted. Contao's cascade (ctable) only drops the DB child rows (entries,
 * questions, rules); the files under var/workflow_pdfs/<id>/ would otherwise be
 * left behind as orphans. Also enriches the delete confirmation so the (now
 * irreversible) removal of those PDFs is spelled out before the click.
 */
class WorkflowDeleteListener
{
    public function __construct(private readonly PdfStorage $pdfStorage)
    {
    }

    /**
     * Fires per deleted workflow (single delete and batch "delete all" alike),
     * before the DB rows are removed. Files are gone for good afterwards – the
     * DB-level tl_undo record cannot restore them, which is the intended
     * "delete the PDFs together with the workflow" behaviour.
     */
    #[AsCallback(table: 'tl_workflow', target: 'config.ondelete')]
    public function purgeStoredPdfs(DataContainer $dc): void
    {
        $id = (int) $dc->id;

        if ($id > 0) {
            $this->pdfStorage->deleteWorkflowDir($id);
        }
    }

    /**
     * Spells out in the delete confirmation that the workflow's generated PDF
     * documents are removed as well, including their number. Referenced by class
     * name in tl_workflow.php (list.operations.delete) and resolved via
     * System::importStatic(); the service must therefore be public.
     */
    public function renderDeleteButton(DataContainerOperation $operation): void
    {
        $count = $this->pdfStorage->countWorkflowPdfs((int) $operation->getRecord()['id']);

        $confirm = $count > 0
            ? sprintf(
                'Diesen Workflow wirklich löschen? Dabei werden auch alle Einträge sowie '
                .'%d bereits erzeugte PDF-Dokument(e) unwiderruflich gelöscht.',
                $count,
            )
            : 'Diesen Workflow wirklich löschen? Dabei werden auch alle zugehörigen Einträge unwiderruflich gelöscht.';

        // Append (do not replace) so Contao's own class="delete" on the operation is
        // kept. The value is inserted verbatim into the <a> tag, no further sprintf.
        $operation['attributes'] = (string) ($operation['attributes'] ?? '').sprintf(
            ' onclick="if(!confirm(\'%s\'))return false;Backend.getScrollOffset()"',
            str_replace(["'", "\n", "\r"], ["\\'", ' ', ''], $confirm),
        );
    }
}
