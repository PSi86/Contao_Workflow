<?php

declare(strict_types=1);

use Contao\DC_Table;
use Psimandl\TrainerWorkflowBundle\EventListener\DataContainer\AnswerConfigListener;

$GLOBALS['TL_DCA']['tl_trainer_rule'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ptable'           => 'tl_trainer_workflow',
        'enableVersioning' => true,
        'onload_callback'  => [[AnswerConfigListener::class, 'hideConditionsForDefaultRule']],
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
        ],
        'label' => [
            'fields' => ['title'],
            'format' => '%s',
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
        // "conditions" is removed from the palette at runtime when isDefault is set
        // (see AnswerConfigListener::hideConditionsForDefaultRule).
        'default' => '{rule_legend},title,isDefault,conditions;{text_legend},pdfBody',
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
        'title' => [
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'text',
            'eval'      => ['maxlength' => 255, 'tl_class' => 'w50'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'isDefault' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['submitOnChange' => true, 'tl_class' => 'w50 m12'],
            'sql'       => "char(1) NOT NULL default ''",
        ],
        'conditions' => [
            'exclude'   => true,
            'inputType' => 'multiColumnWizard',
            'eval'      => [
                'tl_class'     => 'clr',
                'columnFields' => [
                    'field' => [
                        'label'            => &$GLOBALS['TL_LANG']['tl_trainer_rule']['cond_field'],
                        'inputType'        => 'select',
                        'options_callback' => [AnswerConfigListener::class, 'getStorageFieldOptions'],
                        'eval'             => ['includeBlankOption' => true, 'style' => 'width:220px'],
                    ],
                    'operator' => [
                        'label'            => &$GLOBALS['TL_LANG']['tl_trainer_rule']['cond_operator'],
                        'inputType'        => 'select',
                        'options'          => &$GLOBALS['TL_LANG']['tl_trainer_rule']['operatorOptions'],
                        'eval'             => ['style' => 'width:160px'],
                    ],
                    'value' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_trainer_rule']['cond_value'],
                        'inputType' => 'text',
                        'eval'      => ['style' => 'width:220px'],
                    ],
                ],
            ],
            'sql' => 'blob NULL',
        ],
        'pdfBody' => [
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['mandatory' => true, 'rows' => 8, 'tl_class' => 'clr'],
            'sql'       => 'text NULL',
        ],
    ],
];
