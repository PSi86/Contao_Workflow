<?php

declare(strict_types=1);

namespace Psimandl\WorkflowBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\Input;

/**
 * Loads the client-side field-toggle asset on the workflow and PDF-rule edit
 * masks. The asset (public/workflow-field-toggle.js) shows/hides the conditional
 * fields that used to be Contao subpalettes, driven by the selector field's
 * data-wf-toggle attribute – without the submitOnChange/toggleSubpalette that
 * would persist the record before the user clicks "save".
 */
class FieldToggleListener
{
    #[AsCallback(table: 'tl_workflow', target: 'config.onload')]
    public function enableForWorkflow(DataContainer $dc): void
    {
        $this->loadAsset();
    }

    #[AsCallback(table: 'tl_workflow_rule', target: 'config.onload')]
    public function enableForRule(DataContainer $dc): void
    {
        $this->loadAsset();
    }

    #[AsCallback(table: 'tl_workflow_question', target: 'config.onload')]
    public function enableForQuestion(DataContainer $dc): void
    {
        $this->loadAsset();
    }

    /**
     * Registers the asset only on the actual edit mask, so the parent list view
     * stays untouched (mirrors PlaceholderHelperListener).
     */
    private function loadAsset(): void
    {
        if (!\in_array((string) Input::get('act'), ['edit', 'editAll'], true)) {
            return;
        }

        $GLOBALS['TL_JAVASCRIPT']['wf_field_toggle'] = 'bundles/contaoworkflow/workflow-field-toggle.js|static';
    }
}
