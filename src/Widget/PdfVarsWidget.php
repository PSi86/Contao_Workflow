<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\Widget;

use Contao\StringUtil;
use Contao\Widget;

/**
 * Back end widget for a master's "PDF-Variablen" (tl_workflow_master.pdfData).
 *
 * Replaces the generic key/value MultiColumnWizard with a template-driven editor:
 * the JS (public/workflow-pdf-vars.js) renders one labelled value field per
 * variable the selected master template declares ($GLOBALS['TL_WORKFLOW_PDF_VARS'])
 * and rebuilds them INSTANTLY when the template select changes – no save needed –
 * plus a small section for custom variables. Nothing is persisted before the user
 * clicks "save".
 *
 * Storage stays the exact MCW format: a serialized list of ['key'=>…,'value'=>…]
 * pairs (inputs named pdfData[i][key]/[value]) → MasterModel::getPdfData(),
 * PlaceholderResolver, export/import and the PDF rendering are unaffected, and the
 * field is versioned like any other column. Empty rows are dropped on save
 * (WorkflowOptionsListener::cleanPdfData).
 */
class PdfVarsWidget extends Widget
{
    protected $blnSubmitInput = true;

    protected $strTemplate = 'be_widget';

    /**
     * @param array<string, mixed> $arrAttributes
     */
    public function __construct($arrAttributes = null)
    {
        parent::__construct($arrAttributes);

        $GLOBALS['TL_CSS']['wf_backend'] = 'bundles/contaoworkflow/workflow-backend.css';
        $GLOBALS['TL_JAVASCRIPT']['wf_pdfvars'] = 'bundles/contaoworkflow/workflow-pdf-vars.js|static';
    }

    public function generate(): string
    {
        $registry = $GLOBALS['TL_WORKFLOW_PDF_VARS'] ?? [];
        $lang = $GLOBALS['TL_LANG']['tl_workflow_master'] ?? [];

        $rows = '';
        $i = 0;

        foreach (StringUtil::deserialize($this->varValue, true) as $pair) {
            $key = trim((string) ($pair['key'] ?? ''));

            if ('' === $key) {
                continue;
            }

            $rows .= $this->renderRow($i++, $key, (string) ($pair['value'] ?? ''));
        }

        return sprintf(
            '<div class="wf-pdfvars" data-name="%s" data-template-field="ctrl_masterTemplate" data-registry="%s"'
            .' data-label-template="%s" data-label-custom="%s" data-label-key="%s" data-label-value="%s"'
            .' data-label-add="%s" data-label-remove="%s">'
            .'<div class="wf-pdfvars-rows">%s</div>'
            .'<p class="wf-pdfvars-foot"><button type="button" class="tl_submit wf-pdfvars-add">%s</button></p>'
            .'</div>',
            StringUtil::specialchars($this->strName),
            StringUtil::specialchars(json_encode($registry, JSON_THROW_ON_ERROR)),
            StringUtil::specialchars((string) ($lang['pdfData_groupTemplate'] ?? 'Variablen der Vorlage')),
            StringUtil::specialchars((string) ($lang['pdfData_groupCustom'] ?? 'Eigene Variablen')),
            StringUtil::specialchars((string) ($lang['pdfData_key'] ?? 'Variable')),
            StringUtil::specialchars((string) ($lang['pdfData_value'] ?? 'Wert')),
            StringUtil::specialchars((string) ($lang['pdfData_add'] ?? 'Variable hinzufügen')),
            StringUtil::specialchars((string) ($lang['pdfData_remove'] ?? 'Entfernen')),
            $rows,
            StringUtil::specialchars((string) ($lang['pdfData_add'] ?? 'Variable hinzufügen')),
        );
    }

    /**
     * One key/value row (no-JS fallback + initial data source; the JS reads these
     * rows, then re-renders them grouped/labelled).
     */
    private function renderRow(int $i, string $key, string $value): string
    {
        return sprintf(
            '<div class="wf-pdfvars-row" data-row>'
            .'<input type="text" name="%1$s[%2$d][key]" value="%3$s" class="tl_text wf-pdfvars-k">'
            .'<textarea name="%1$s[%2$d][value]" rows="2" class="tl_textarea wf-pdfvars-v">%4$s</textarea>'
            .'</div>',
            StringUtil::specialchars($this->strName),
            $i,
            StringUtil::specialchars($key),
            StringUtil::specialchars($value),
        );
    }
}
