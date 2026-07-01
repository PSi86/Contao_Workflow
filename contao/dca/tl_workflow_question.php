<?php

declare(strict_types=1);

use Contao\DC_Table;
use Psimandl\WorkflowBundle\EventListener\DataContainer\AnswerConfigListener;

$GLOBALS['TL_DCA']['tl_workflow_question'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ptable'           => 'tl_workflow',
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
        // No __selector__/subpalettes: the type-dependent fields are always in the
        // palette and shown/hidden client-side from the "type" select
        // (data-wf-toggle, workflow-field-toggle.js) – so changing the type no
        // longer submits/saves the record. Value types show one document text
        // (pdfStatement), choice types the options wizard, "Aktuelle Zeit" the
        // hideInForm flag; for "Aktuelle Zeit" the mandatory/prefill/readOnly flags
        // are hidden (they are meaningless there).
        'default' => '{question_legend},label,type,storageField,mandatory,prefill,readOnly,options,pdfStatement,hideInForm',
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
            // data-wf-toggle: shows the fields relevant to the chosen type (client-
            // side, no save). See workflow-field-toggle.js. Fields not listed for a
            // type are hidden and disabled (not posted/validated).
            'eval'      => [
                'mandatory'      => true,
                'tl_class'       => 'w50',
                'data-wf-toggle' => '{"mode":"select","map":{'
                    .'"text":["mandatory","prefill","readOnly","pdfStatement"],'
                    .'"textarea":["mandatory","prefill","readOnly","pdfStatement"],'
                    .'"number":["mandatory","prefill","readOnly","pdfStatement"],'
                    .'"date":["mandatory","prefill","readOnly","pdfStatement"],'
                    .'"select":["mandatory","prefill","readOnly","options"],'
                    .'"radio":["mandatory","prefill","readOnly","options"],'
                    .'"checkbox":["mandatory","prefill","readOnly","options"],'
                    .'"currentTime":["hideInForm","pdfStatement"]}}',
            ],
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
        // (never validated, never stored back). Available for every type. A
        // read-only field already shows the stored value, so "prefill" is
        // redundant there – data-wf-toggle hides it while read-only is checked
        // (combined with the type toggle via workflow-field-toggle.js).
        'readOnly' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['tl_class' => 'w50 m12', 'data-wf-toggle' => '{"mode":"checkbox","off":["prefill"]}'],
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
                    // No fixed pixel widths: the columns are sized by CSS so the
                    // wizard (incl. its row buttons) always fits the dialog width –
                    // see .multicolumnwizard rules in workflow-backend.css.
                    'value' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_workflow_question']['option_value'],
                        'inputType' => 'text',
                        'eval'      => ['mandatory' => true, 'decodeEntities' => true],
                    ],
                    'label' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_workflow_question']['option_label'],
                        'inputType' => 'text',
                        'eval'      => ['mandatory' => true, 'decodeEntities' => true],
                    ],
                    // Document text ("Textbaustein") of the option; empty means
                    // the visible label counts verbatim in the document.
                    'statement' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_workflow_question']['option_statement'],
                        'inputType' => 'text',
                        'eval'      => ['decodeEntities' => true],
                    ],
                ],
            ],
            'sql' => 'blob NULL',
        ],
        // Statement template of a value-based question; ##answer## marks the
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
