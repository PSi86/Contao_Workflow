<?php

declare(strict_types=1);

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_trainer_workflow'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'ctable'           => ['tl_trainer_entry'],
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
            'mode'        => 1,
            'fields'      => ['title'],
            'flag'        => 1,
            'panelLayout' => 'search,limit',
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
            'questions' => [
                'href' => 'table=tl_trainer_question',
                'icon' => 'tablewizard.svg',
            ],
            'rules' => [
                'href' => 'table=tl_trainer_rule',
                'icon' => 'settings.svg',
            ],
            'entries' => [
                'href' => 'table=tl_trainer_entry',
                'icon' => 'edit.svg',
            ],
            'edit' => [
                'href' => 'act=edit',
                'icon' => 'header.svg',
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
        '__selector__' => ['pdfBodyType'],
        'default' => '{title_legend},title,published;{steps_legend},steps;{source_legend},sourceFile,sourceSheet,headerRow,emailField;{form_legend},inputFields,formPage,requireSignature;{pdf_legend},master,pdfBodyType;{notification_legend},ncInvite,ncReminder,ncResult',
    ],
    'subpalettes' => [
        'pdfBodyType_letter'   => 'pdfTitle,pdfBody',
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
            'eval'      => ['tl_class' => 'w50 m12'],
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
            'eval'      => ['tl_class' => 'w50 m12'],
            'sql'       => "char(1) NOT NULL default '1'",
        ],
        'formPage' => [
            'exclude'   => true,
            'inputType' => 'pageTree',
            'eval'      => ['fieldType' => 'radio', 'tl_class' => 'clr', 'mandatory' => true],
            'sql'       => "int(10) unsigned NOT NULL default 0",
        ],
        // Legacy columns of the old fixed accept/reject decision. Kept (SQL only,
        // no UI) so ConfigurableAnswersMigration can read them; removable in a
        // later release once all installs are migrated.
        'labelAccept' => [
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        'labelReject' => [
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        'decisionField' => [
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        'dateField' => [
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        'master' => [
            'exclude'    => true,
            'inputType'  => 'select',
            'foreignKey' => 'tl_trainer_master.title',
            'eval'       => ['mandatory' => true, 'chosen' => true, 'includeBlankOption' => true, 'tl_class' => 'w50'],
            'sql'        => "int(10) unsigned NOT NULL default 0",
            'relation'   => ['type' => 'hasOne', 'load' => 'lazy'],
        ],
        'pdfBodyType' => [
            'exclude'   => true,
            'inputType' => 'select',
            'options'   => ['letter', 'template'],
            'reference' => &$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBodyTypeOptions'],
            'eval'      => ['submitOnChange' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(16) NOT NULL default 'letter'",
        ],
        'pdfTitle' => [
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['maxlength' => 255, 'tl_class' => 'clr'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'pdfBody' => [
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['rows' => 8, 'tl_class' => 'clr'],
            'sql'       => 'text NULL',
        ],
        // Legacy column (old per-decision reject letter body). SQL only, no UI.
        'pdfBodyReject' => [
            'sql' => 'text NULL',
        ],
        'pdfBodyTemplate' => [
            'exclude'   => true,
            'inputType' => 'select',
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(64) NOT NULL default ''",
        ],
        // Internal: checksum of the last imported source file (change detection).
        'sourceHash' => [
            'sql' => "varchar(64) NOT NULL default ''",
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
