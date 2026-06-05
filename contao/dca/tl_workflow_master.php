<?php

declare(strict_types=1);

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_workflow_master'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
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
        'default' => '{title_legend},title;{master_legend},masterTemplate,pdfLogo,pdfData',
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
            'eval'      => ['mandatory' => true, 'decodeEntities' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'masterTemplate' => [
            'exclude'   => true,
            'inputType' => 'select',
            // submitOnChange: selecting a template immediately suggests its PDF
            // variables (fields.pdfData.load). Side effect: the selection is saved
            // at once – the field's help text points this out.
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'submitOnChange' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(64) NOT NULL default ''",
        ],
        'pdfLogo' => [
            'exclude'   => true,
            'inputType' => 'fileTree',
            'eval'      => [
                'fieldType'  => 'radio',
                'files'      => true,
                'filesOnly'  => true,
                'extensions' => 'png,jpg,jpeg,gif,svg',
                'tl_class'   => 'clr',
            ],
            'sql' => 'binary(16) NULL',
        ],
        'pdfData' => [
            'exclude'   => true,
            'inputType' => 'multiColumnWizard',
            'eval'      => [
                'tl_class'     => 'clr',
                'columnFields' => [
                    'key' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_workflow_master']['pdfData_key'],
                        'inputType' => 'text',
                        'eval'      => ['decodeEntities' => true, 'style' => 'width:180px'],
                    ],
                    'value' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_workflow_master']['pdfData_value'],
                        'inputType' => 'textarea',
                        'eval'      => ['decodeEntities' => true, 'style' => 'width:460px', 'rows' => 3],
                    ],
                ],
            ],
            'sql' => 'blob NULL',
        ],
    ],
];
