<?php

declare(strict_types=1);

use Psimandl\WorkflowBundle\Controller\Backend\DashboardModule;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\MasterModel;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\RuleModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;
use Psimandl\WorkflowBundle\Widget\PdfVarsWidget;

/*
 * Back end form widgets.
 */
$GLOBALS['BE_FFL']['wfPdfVars'] = PdfVarsWidget::class;

/*
 * Back end modules.
 */
$GLOBALS['BE_MOD']['workflow'] = [
    'workflow_overview' => [
        'callback' => DashboardModule::class,
    ],
    'workflow_manage' => [
        'tables' => ['tl_workflow', 'tl_workflow_question', 'tl_workflow_rule', 'tl_workflow_entry'],
    ],
    'workflow_master' => [
        'tables' => ['tl_workflow_master'],
    ],
];

/*
 * Models.
 */
$GLOBALS['TL_MODELS']['tl_workflow'] = WorkflowModel::class;
$GLOBALS['TL_MODELS']['tl_workflow_question'] = QuestionModel::class;
$GLOBALS['TL_MODELS']['tl_workflow_rule'] = RuleModel::class;
$GLOBALS['TL_MODELS']['tl_workflow_entry'] = EntryModel::class;
$GLOBALS['TL_MODELS']['tl_workflow_master'] = MasterModel::class;

/*
 * PDF variables offered per master (letterhead) template (key => default value).
 * When a master template is selected, these keys are suggested in the master's
 * "PDF-Variablen" field. Add an entry here when you add a new pdf_master*
 * template that needs static variables.
 */
$GLOBALS['TL_WORKFLOW_PDF_VARS'] = [
    'pdf_master' => ['Jahr' => date('Y'), 'Verein' => '', 'Ort' => '', 'Footer' => ''],
    // Generic, fully variable-driven letterhead (header line + 4 footer columns;
    // footer columns use "|" as line break). Jahr/Verein/Ort feed the body texts.
    'pdf_master_generic' => [
        'HeaderLine' => '',
        'Footer1'    => '',
        'Footer2'    => '',
        'Footer3'    => '',
        'Footer4'    => '',
        'Jahr'       => date('Y'),
        'Verein'     => '',
        'Ort'        => '',
        // Layout metrics – per-Briefpapier adjustable. A declaration may be a plain
        // default (content variable) OR ['default'=>…, 'label'=>…, 'group'=>'layout'].
        // "layout" vars get their own group in the editor, are NOT offered as
        // ##letterhead_*## tokens, and are read+sanitised by PdfGenerator (page
        // margins) and pdf_master_generic.html5 (font sizes, footer spacing).
        // Defaults equal the previously hard-coded values, so an untouched
        // Briefpapier renders exactly as before.
        'MarginTop'        => ['default' => '34',  'label' => 'Rand oben (mm)',            'group' => 'layout'],
        'MarginBottom'     => ['default' => '30',  'label' => 'Rand unten (mm)',           'group' => 'layout'],
        'MarginLeft'       => ['default' => '20',  'label' => 'Rand links (mm)',           'group' => 'layout'],
        'MarginRight'      => ['default' => '20',  'label' => 'Rand rechts (mm)',          'group' => 'layout'],
        'MarginHeader'     => ['default' => '8',   'label' => 'Abstand Kopfzeile (mm)',    'group' => 'layout'],
        'MarginFooter'     => ['default' => '8',   'label' => 'Abstand Fußzeile (mm)',     'group' => 'layout'],
        'FontSizeHeader'   => ['default' => '8',   'label' => 'Schriftgröße Kopfzeile (pt)', 'group' => 'layout'],
        'FontSizeBody'     => ['default' => '11',  'label' => 'Schriftgröße Fließtext (pt)',  'group' => 'layout'],
        'FontSizeFooter'   => ['default' => '7.5', 'label' => 'Schriftgröße Fußzeile (pt)',   'group' => 'layout'],
        'FooterColSpacing' => ['default' => '8',   'label' => 'Spaltenabstand Fußzeile (px)', 'group' => 'layout'],
    ],
];
