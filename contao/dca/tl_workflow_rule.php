<?php

declare(strict_types=1);

use Contao\DC_Table;
use Psimandl\WorkflowBundle\EventListener\DataContainer\AnswerConfigListener;

$GLOBALS['TL_DCA']['tl_workflow_rule'] = [
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
        // "conditions" is always in the palette and hidden client-side when isDefault
        // is checked (data-wf-toggle, workflow-field-toggle.js). On save it is cleared
        // for a default rule (AnswerConfigListener::clearConditionsForDefaultRule), so
        // no submitOnChange/reload-and-save is needed.
        'default' => '{rule_legend},title,isDefault,conditions;{text_legend},pdfBody',
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
        'title' => [
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'text',
            'eval'      => ['decodeEntities' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'isDefault' => [
            'exclude'   => true,
            'inputType' => 'checkbox',
            // data-wf-toggle: hide the conditions wizard while checked (the default
            // rule always applies) – client-side, no save. See workflow-field-toggle.js.
            'eval'      => ['tl_class' => 'w50 m12', 'data-wf-toggle' => '{"mode":"checkbox","off":["conditions"]}'],
            'sql'       => "char(1) NOT NULL default ''",
        ],
        'conditions' => [
            'exclude'   => true,
            'inputType' => 'multiColumnWizard',
            // Cleared on save when the rule is the default rule, so a rule toggled to
            // "default" does not keep orphaned (and never evaluated) conditions.
            'save_callback' => [[AnswerConfigListener::class, 'clearConditionsForDefaultRule']],
            'eval'      => [
                'tl_class'     => 'clr',
                'columnFields' => [
                    'field' => [
                        'label'            => &$GLOBALS['TL_LANG']['tl_workflow_rule']['cond_field'],
                        'inputType'        => 'select',
                        'options_callback' => [AnswerConfigListener::class, 'getStorageFieldOptions'],
                        'eval'             => ['includeBlankOption' => true, 'style' => 'width:220px'],
                    ],
                    'operator' => [
                        'label'            => &$GLOBALS['TL_LANG']['tl_workflow_rule']['cond_operator'],
                        'inputType'        => 'select',
                        'options'          => &$GLOBALS['TL_LANG']['tl_workflow_rule']['operatorOptions'],
                        'eval'             => ['style' => 'width:160px'],
                    ],
                    'value' => [
                        'label'     => &$GLOBALS['TL_LANG']['tl_workflow_rule']['cond_value'],
                        'inputType' => 'text',
                        'eval'      => ['decodeEntities' => true, 'style' => 'width:220px'],
                    ],
                ],
            ],
            'sql' => 'blob NULL',
        ],
        'pdfBody' => [
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['mandatory' => true, 'decodeEntities' => true, 'rows' => 8, 'tl_class' => 'clr'],
            'sql'       => 'text NULL',
        ],
    ],
];
