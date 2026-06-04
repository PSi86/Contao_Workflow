<?php

declare(strict_types=1);

use Contao\DC_Table;
use Psimandl\WorkflowBundle\EventListener\DataContainer\AnswerConfigListener;

$GLOBALS['TL_DCA']['tl_workflow'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        // Questions and rules are copied with the workflow (act=copy); entries are
        // not (tl_workflow_entry sets doNotCopyRecords). All three cascade on delete.
        'ctable'           => ['tl_workflow_entry', 'tl_workflow_question', 'tl_workflow_rule'],
        'switchToEdit'     => true,
        'enableVersioning' => true,
        'sql' => [
            'keys' => [
                'id' => 'primary',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'           => 1,
            'fields'         => ['tstamp DESC'],
            'flag'           => 12,
            // Newest first, but as a flat list (no tstamp group headers).
            'disableGrouping' => true,
            'panelLayout'    => 'search,limit',
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
            'edit' => [
                'href' => 'act=edit',
                'icon' => 'header.svg',
            ],
            'entries' => [
                'href' => 'table=tl_workflow_entry',
                'icon' => 'edit.svg',
            ],
            'copy' => [
                'href' => 'act=copy',
                'icon' => 'copy.svg',
            ],
            'delete' => [
                'href'       => 'act=delete',
                'icon'       => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\''.($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? 'Delete?').'\'))return false;Backend.getScrollOffset()"',
            ],
            'show' => [
                'href' => 'act=show',
                'icon' => 'show.svg',
            ],
        ],
    ],
    'palettes' => [
        '__selector__' => ['pdfBodyType', 'requireSignature'],
        'default' => '{title_legend},title,published;{steps_legend},steps;{source_legend},sourceFile,sourceSheet,headerRow,emailField;{form_legend},inputFields,formPage,requireSignature,questions;{pdf_legend},master,pdfFileName,pdfBodyType;{notification_legend},ncInvite,ncReminder,ncResult',
    ],
    'subpalettes' => [
        // The signature-line fields only matter when a signature is required.
        'requireSignature'     => 'pdfSignatureDate,pdfSignatureLocation',
        // Letter mode: shared heading + the rules that carry the body texts.
        // Template mode: just the template file (it handles branching itself).
        'pdfBodyType_letter'   => 'pdfTitle,rules',
        'pdfBodyType_template' => 'pdfBodyTemplate',
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'title' => [
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'text',
            'eval'      => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'published' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            // A copy must be reviewed before it runs, so it starts unpublished.
            'eval'      => ['tl_class' => 'w50 m12', 'doNotCopy' => true],
            'sql'       => "char(1) NOT NULL default ''",
        ],
        'steps' => [
            'exclude'   => true,
            'inputType' => 'listWizard',
            'eval'      => ['mandatory' => true, 'tl_class' => 'clr'],
            'sql'       => 'blob NULL',
        ],
        'sourceFile' => [
            'exclude'   => true,
            'inputType' => 'fileTree',
            'eval'      => [
                'fieldType'      => 'radio',
                'files'          => true,
                'filesOnly'      => true,
                'extensions'     => 'csv,xlsx,xls',
                'submitOnChange' => true,
                'tl_class'       => 'clr',
                // A copy must load its own source file before it can run.
                'doNotCopy'      => true,
            ],
            'sql' => 'binary(16) NULL',
        ],
        'sourceSheet' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(128) NOT NULL default ''",
        ],
        'headerRow' => [
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['rgxp' => 'natural', 'mandatory' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql'       => "smallint(5) unsigned NOT NULL default 1",
        ],
        'emailField' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(128) NOT NULL default ''",
        ],
        'inputFields' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['multiple' => true, 'tl_class' => 'clr'],
            'sql'       => 'blob NULL',
        ],
        'requireSignature' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['submitOnChange' => true, 'tl_class' => 'w50 m12'],
            'sql'       => "char(1) NOT NULL default '1'",
        ],
        'formPage' => [
            'exclude'   => true,
            'inputType' => 'pageTree',
            'eval'      => ['fieldType' => 'radio', 'tl_class' => 'clr', 'mandatory' => true],
            'sql'       => "int(10) unsigned NOT NULL default 0",
        ],
        // Answer fields (tl_workflow_question), embedded in the edit mask.
        // hideButton: only the inline list (with its own new/edit/delete that open
        // clean record popups) – NOT the main button, which would open the foreign
        // table's mode-4 parent list (recursive "edit workflow" header).
        'questions' => [
            'exclude'   => true,
            'inputType' => 'dcaWizard',
            'foreignTable' => 'tl_workflow_question',
            'foreignField' => 'pid',
            'eval'      => [
                'tl_class'          => 'clr',
                'hideButton'        => true,
                'fields'            => ['label', 'type', 'storageField', 'mandatory'],
                'orderField'        => 'sorting',
                'showOperations'    => true,
                'operations'        => ['edit', 'delete', 'new'],
                'global_operations' => ['new'],
            ],
        ],
        // PDF rules (tl_workflow_rule), embedded in the edit mask (letter mode only).
        // Custom list_callback shows label + readable conditions ("(Standardtext)"
        // for a rule without conditions) and keeps the edit/delete operations.
        'rules' => [
            'exclude'   => true,
            'inputType' => 'dcaWizard',
            'foreignTable' => 'tl_workflow_rule',
            'foreignField' => 'pid',
            'eval'      => [
                'tl_class'          => 'clr',
                'hideButton'        => true,
                'orderField'        => 'sorting',
                'operations'        => ['edit', 'delete'],
                'global_operations' => ['new'],
                'list_callback'     => [AnswerConfigListener::class, 'renderRulesList'],
            ],
        ],
        'master' => [
            'exclude'    => true,
            'inputType'  => 'select',
            'foreignKey' => 'tl_workflow_master.title',
            'eval'       => ['mandatory' => true, 'chosen' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        // Data field whose value is printed as the signature date in the PDF
        // (typically an "Aktuelle Zeit" answer field). Empty = no date printed.
        'pdfSignatureDate' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(128) NOT NULL default ''",
        ],
        // Source column whose value is printed as the place in the signature line
        // (e.g. the participant's town). Empty = no place printed.
        'pdfSignatureLocation' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(128) NOT NULL default ''",
        ],
        // File name pattern of the generated PDF, with placeholders. Sanitized to a
        // safe name; a short token is appended on collision. Empty = entry token.
        'pdfFileName' => [
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['maxlength' => 255, 'tl_class' => 'clr'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'pdfBodyType' => [
            'exclude'   => true,
            'inputType' => 'select',
            'options'   => ['letter', 'template'],
            'reference' => &$GLOBALS['TL_LANG']['tl_workflow']['pdfBodyTypeOptions'],
            'eval'      => ['submitOnChange' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(16) NOT NULL default 'letter'",
        ],
        'pdfTitle' => [
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['maxlength' => 255, 'tl_class' => 'clr'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'pdfBodyTemplate' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(64) NOT NULL default ''",
        ],
        // Internal: checksum of the last imported source file (change detection).
        'sourceHash' => [
            'eval' => ['doNotCopy' => true],
            'sql'  => "varchar(64) NOT NULL default ''",
        ],
        'ncInvite' => [
            'exclude'    => true,
            'inputType'  => 'select',
            'foreignKey' => 'tl_nc_notification.title',
            'eval'       => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'ncReminder' => [
            'exclude'    => true,
            'inputType'  => 'select',
            'foreignKey' => 'tl_nc_notification.title',
            'eval'       => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'ncResult' => [
            'exclude'    => true,
            'inputType'  => 'select',
            'foreignKey' => 'tl_nc_notification.title',
            'eval'       => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
    ],
];
