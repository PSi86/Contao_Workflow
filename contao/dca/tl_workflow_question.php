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
    // Re-add "save and close" in the dcaWizard modal (Contao drops it there via nb=1).
    'edit' => [
        'buttons_callback' => [[AnswerConfigListener::class, 'addSaveAndClose']],
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
        // are hidden (they are meaningless there). "Erklärung" is a static text
        // block (pdfStatement only) shown as a paragraph in the form and the
        // document – no storage field, no input.
        'default' => '{question_legend},label,type,storageField,mandatory,readOnly,prefill,description,options,pdfStatement,showStatementInForm,hideInForm',
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
            'options'   => ['text', 'textarea', 'number', 'date', 'select', 'radio', 'checkbox', 'currentTime', 'explanation'],
            'reference' => &$GLOBALS['TL_LANG']['tl_workflow_question']['typeOptions'],
            // data-wf-toggle: shows the fields relevant to the chosen type (client-
            // side, no save). See workflow-field-toggle.js. Fields not listed for a
            // type are hidden and disabled (not posted/validated).
            'eval'      => [
                'mandatory'      => true,
                'tl_class'       => 'w50',
                'data-wf-toggle' => '{"mode":"select","map":{'
                    .'"text":["storageField","mandatory","prefill","readOnly","description","pdfStatement","showStatementInForm"],'
                    .'"textarea":["storageField","mandatory","prefill","readOnly","description","pdfStatement","showStatementInForm"],'
                    .'"number":["storageField","mandatory","prefill","readOnly","description","pdfStatement","showStatementInForm"],'
                    .'"date":["storageField","mandatory","prefill","readOnly","description","pdfStatement","showStatementInForm"],'
                    .'"select":["storageField","mandatory","prefill","readOnly","description","options","showStatementInForm"],'
                    .'"radio":["storageField","mandatory","prefill","readOnly","description","options","showStatementInForm"],'
                    .'"checkbox":["storageField","mandatory","prefill","readOnly","description","options","showStatementInForm"],'
                    .'"currentTime":["storageField","hideInForm","pdfStatement"],'
                    .'"explanation":["pdfStatement"]}}',
            ],
            // Warns when the chosen type does not fit the storage column. The strict check
            // sits on storageField, but that field is locked once answers exist and then
            // never posted – without this the mismatch would only surface at import time.
            'save_callback' => [[AnswerConfigListener::class, 'warnOnTypeMismatch']],
            'sql'           => "varchar(16) NOT NULL default 'text'",
        ],
        // Not "mandatory": the "Erklärung" type hides this field (it stores nothing),
        // and Contao would otherwise fail validation on the hidden/disabled field.
        // An input field without a storage column simply does not store its answer;
        // the workflow validator handles that softly (an empty field is skipped).
        'storageField' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            // For type "number" this checks the chosen column's Excel formatting and
            // refuses one a number field cannot round-trip (see validateNumberColumn);
            // on success it snapshots the format into numberFormat.
            'save_callback' => [[AnswerConfigListener::class, 'validateNumberColumn']],
            'sql'       => "varchar(128) NOT NULL default ''",
        ],
        // Snapshot of the storage column's Excel format (JSON), written by the
        // compatibility check when a "number" field is saved. Not user-editable: it
        // describes the source file, not a preference. It exists so the form, the live
        // preview, the PDF and the export can render the value identically without
        // re-reading the source file's style layer (expensive, and the file may be gone).
        'numberFormat' => [
            'sql' => "varchar(128) NOT NULL default ''",
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
        // Optional field description, shown in the FORM only (below the label) and
        // only when not empty. Never printed in the document – purely a hint for the
        // person filling in the form.
        'description' => [
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'textarea',
            'eval'      => ['decodeEntities' => true, 'style' => 'height:60px', 'tl_class' => 'clr'],
            'sql'       => 'text NULL',
        ],
        // Whether the document text ("Textbaustein") is previewed in the front-end
        // form ("So erscheint dies im Dokument"). Default on, so existing fields keep
        // showing the hint; turn off to hide it from the person filling in the form.
        'showStatementInForm' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['tl_class' => 'w50 m12'],
            'sql'       => "char(1) NOT NULL default '1'",
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
                    // the visible label counts verbatim in the document. Multi-line
                    // (usually the longest of the three columns); its column is
                    // widened via CSS (see #ctrl_options in workflow-backend.css).
                    'statement' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_workflow_question']['option_statement'],
                        'inputType' => 'textarea',
                        'eval'      => ['decodeEntities' => true, 'style' => 'height:44px'],
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
