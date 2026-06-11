<?php

declare(strict_types=1);

use Contao\DC_Table;
use Psimandl\WorkflowBundle\EventListener\DataContainer\AnswerConfigListener;

$GLOBALS['TL_DCA']['tl_workflow_question'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ptable'           => 'tl_workflow',
        'enableVersioning' => true,
        'onload_callback'  => [[AnswerConfigListener::class, 'hideMandatoryForCurrentTime']],
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
        'default'      => '{question_legend},label,type,storageField,mandatory,prefill,readOnly',
    ],
    'subpalettes' => [
        // Value types carry ONE document text; choice types carry it PER OPTION
        // (a per-question text would override the option texts).
        'type_text'        => 'pdfStatement',
        'type_textarea'    => 'pdfStatement',
        'type_number'      => 'pdfStatement',
        'type_date'        => 'pdfStatement',
        'type_select'      => 'options',
        'type_radio'       => 'options',
        'type_checkbox'    => 'options',
        'type_currentTime' => 'hideInForm,pdfStatement',
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'pid' => [
            'foreignKey' => 'tl_workflow.title',
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
            'eval'      => ['mandatory' => true, 'decodeEntities' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'type' => [
            'exclude'   => true,
            'inputType' => 'select',
            'options'   => ['text', 'textarea', 'number', 'date', 'select', 'radio', 'checkbox', 'currentTime'],
            'reference' => &$GLOBALS['TL_LANG']['tl_workflow_question']['typeOptions'],
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
        // Prefill the (editable) field with the stored data value (Excel source
        // value or previous answer) – "output field = input field".
        'prefill' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['tl_class' => 'w50 m12'],
            'sql'       => "char(1) NOT NULL default ''",
        ],
        // Read-only: the field shows the stored data value but cannot be edited
        // (never validated, never stored back). Available for every type.
        'readOnly' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['tl_class' => 'w50 m12'],
            'sql'       => "char(1) NOT NULL default ''",
        ],
        // "Aktuelle Zeit" only: leave the field out of the public form (it is
        // filled automatically on submission).
        'hideInForm' => [
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
                        'label'     => &$GLOBALS['TL_LANG']['tl_workflow_question']['option_value'],
                        'inputType' => 'text',
                        'eval'      => ['mandatory' => true, 'decodeEntities' => true, 'style' => 'width:160px'],
                    ],
                    'label' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_workflow_question']['option_label'],
                        'inputType' => 'text',
                        'eval'      => ['mandatory' => true, 'decodeEntities' => true, 'style' => 'width:280px'],
                    ],
                    // Document text ("Textbaustein") of the option; empty means
                    // the visible label counts verbatim in the document.
                    'statement' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_workflow_question']['option_statement'],
                        'inputType' => 'text',
                        'eval'      => ['decodeEntities' => true, 'style' => 'width:380px'],
                    ],
                ],
            ],
            'sql' => 'blob NULL',
        ],
        // Statement template of a value-based question; ##value## marks the
        // entered value, other ##tokens## resolve as usual. Empty = "<label>: <value>".
        'pdfStatement' => [
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'textarea',
            'eval'      => ['decodeEntities' => true, 'style' => 'height:60px', 'tl_class' => 'clr'],
            'sql'       => 'text NULL',
        ],
    ],
];
