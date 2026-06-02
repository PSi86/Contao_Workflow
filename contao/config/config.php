<?php

declare(strict_types=1);

use Psimandl\TrainerWorkflowBundle\Controller\Backend\DashboardModule;
use Psimandl\TrainerWorkflowBundle\Model\EntryModel;
use Psimandl\TrainerWorkflowBundle\Model\MasterModel;
use Psimandl\TrainerWorkflowBundle\Model\QuestionModel;
use Psimandl\TrainerWorkflowBundle\Model\RuleModel;
use Psimandl\TrainerWorkflowBundle\Model\WorkflowModel;

/*
 * Back end modules.
 */
$GLOBALS['BE_MOD']['trainer'] = [
    'trainer_overview' => [
        'callback' => DashboardModule::class,
    ],
    'trainer_workflow' => [
        'tables' => ['tl_trainer_workflow', 'tl_trainer_question', 'tl_trainer_rule', 'tl_trainer_entry'],
    ],
    'trainer_master' => [
        'tables' => ['tl_trainer_master'],
    ],
];

/*
 * Models.
 */
$GLOBALS['TL_MODELS']['tl_trainer_workflow'] = WorkflowModel::class;
$GLOBALS['TL_MODELS']['tl_trainer_question'] = QuestionModel::class;
$GLOBALS['TL_MODELS']['tl_trainer_rule'] = RuleModel::class;
$GLOBALS['TL_MODELS']['tl_trainer_entry'] = EntryModel::class;
$GLOBALS['TL_MODELS']['tl_trainer_master'] = MasterModel::class;

/*
 * PDF variables offered per master (letterhead) template (key => default value).
 * When a master template is selected, these keys are suggested in the master's
 * "PDF-Variablen" field. Add an entry here when you add a new pdf_master*
 * template that needs static variables.
 */
$GLOBALS['TL_TRAINER_PDF_VARS'] = [
    'pdf_master' => ['Jahr' => date('Y'), 'Verein' => '', 'Ort' => '', 'Footer' => ''],
];
