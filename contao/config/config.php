<?php

declare(strict_types=1);

use Psimandl\WorkflowBundle\Controller\Backend\DashboardModule;
use Psimandl\WorkflowBundle\Model\EntryModel;
use Psimandl\WorkflowBundle\Model\MasterModel;
use Psimandl\WorkflowBundle\Model\QuestionModel;
use Psimandl\WorkflowBundle\Model\RuleModel;
use Psimandl\WorkflowBundle\Model\WorkflowModel;

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
];
