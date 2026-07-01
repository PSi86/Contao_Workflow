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
            // unique: blocks saving a duplicate title (and empties the title on copy, so a
            // duplicated letterhead must be given a free name before it can be saved).
            'eval'      => ['mandatory' => true, 'unique' => true, 'decodeEntities' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'masterTemplate' => [
            'exclude'   => true,
            'inputType' => 'select',
            // No submitOnChange (that would persist the record at once). The
            // template's PDF variables are suggested by fields.pdfData.load on the
            // next load of the record – i.e. after it is saved – so nothing is
            // written before the user clicks "save".
            'eval'      => ['includeBlankOption' => true, 'chosen' => true, 'tl_class' => 'w50'],
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
        // Template-driven variable editor (own widget PdfVarsWidget +
        // workflow-pdf-vars.js): labelled value fields for the variables the
        // selected master template declares, rebuilt instantly on template change
        // (no save), plus a custom-variable section. Same serialized key/value
        // storage as before. cleanPdfData (save) drops empty rows and decodes
        // entities (so "(", "#", "##tokens##" survive – see decodeEntities note).
        'pdfData' => [
            'exclude'   => true,
            'inputType' => 'wfPdfVars',
            'eval'      => ['tl_class' => 'clr'],
            'sql'       => 'blob NULL',
        ],
    ],
];
