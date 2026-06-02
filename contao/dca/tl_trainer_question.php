<?php

declare(strict_types=1);

use Contao\DC_Table;
use Psimandl\TrainerWorkflowBundle\EventListener\DataContainer\AnswerConfigListener;

$GLOBALS['TL_DCA']['tl_trainer_question'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ptable'           => 'tl_trainer_workflow',
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id'  => 'primary',
                'pid' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'         => 4,
            'fields'       => ['sorting'],
            'headerFields' => ['title'],
            'panelLayout'  => 'limit',
            'child_record_callback' => [AnswerConfigListener::class, 'renderQuestionRecord'],
        ],
        'global_operations' => [
            'all' => [
                'href'       => 'act=select',
                'class'      => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"',
            ],
        ],
        'operations' => [
            'edit'   => ['href' => 'act=edit', 'icon' => 'edit.svg'],
            'copy'   => ['href' => 'act=copy', 'icon' => 'copy.svg'],
            'delete' => [
                'href'       => 'act=delete',
                'icon'       => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\''.($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? 'Delete?').'\'))return false;Backend.getScrollOffset()"',
            ],
            'show'   => ['href' => 'act=show', 'icon' => 'show.svg'],
        ],
    ],
    'palettes' => [
        '__selector__' => ['type'],
        'default'      => '{question_legend},label,type,storageField,mandatory',
    ],
    'subpalettes' => [
        'type_select'   => 'options',
        'type_radio'    => 'options',
        'type_checkbox' => 'options',
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'pid' => [
            'foreignKey' => 'tl_trainer_workflow.title',
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'belongsTo', 'load' => 'lazy'],
        ],
        'sorting' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'label' => [
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'text',
            'eval'      => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'type' => [
            'exclude'   => true,
            'inputType' => 'select',
            'options'   => ['text', 'textarea', 'select', 'radio', 'checkbox', 'date'],
            'reference' => &$GLOBALS['TL_LANG']['tl_trainer_question']['typeOptions'],
            'eval'      => ['mandatory' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(16) NOT NULL default 'text'",
        ],
        'storageField' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['mandatory' => true, 'includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(128) NOT NULL default ''",
        ],
        'mandatory' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['tl_class' => 'w50 m12'],
            'sql'       => "char(1) NOT NULL default ''",
        ],
        'options' => [
            'exclude'   => true,
            'inputType' => 'multiColumnWizard',
            'eval'      => [
                'tl_class'     => 'clr',
                'columnFields' => [
                    'value' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_trainer_question']['option_value'],
                        'inputType' => 'text',
                        'eval'      => ['mandatory' => true, 'style' => 'width:220px'],
                    ],
                    'label' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_trainer_question']['option_label'],
                        'inputType' => 'text',
                        'eval'      => ['mandatory' => true, 'style' => 'width:420px'],
                    ],
                ],
            ],
            'sql' => 'blob NULL',
        ],
    ],
];
