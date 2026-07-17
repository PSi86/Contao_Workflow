<?php

declare(strict_types=1);

use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_workflow_entry'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable'        => 'tl_workflow',
        // Entries belong to the imported source data and are never copied when the
        // workflow is duplicated (they still cascade on delete via the parent ctable).
        'doNotCopyRecords' => true,
        'sql' => [
            'keys' => [
                'id'    => 'primary',
                'pid'   => 'index',
                'token' => 'unique',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'         => 4,
            'fields'       => ['email'],
            'headerFields' => ['title'],
            'panelLayout'  => 'filter;search,limit',
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
                'icon' => 'edit.svg',
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
        'default' => '{personal_legend},email,status,token;{response_legend},signature;{data_legend},data;{document_legend},pdfPath',
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
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'token' => [
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['doNotCopy' => true, 'readonly' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(64) NOT NULL default ''",
        ],
        'status' => [
            'exclude'   => true,
            'filter'    => true,
            'inputType' => 'text',
            'eval'      => ['rgxp' => 'natural', 'tl_class' => 'w50'],
            'sql'       => "int(10) unsigned NOT NULL default 0",
        ],
        'email' => [
            'exclude'   => true,
            'search'    => true,
            'inputType' => 'text',
            'eval'      => ['rgxp' => 'email', 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'signature' => [
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['readonly' => true, 'tl_class' => 'clr'],
            'sql'       => 'longtext NULL',
        ],
        'data' => [
            'exclude'   => true,
            'inputType' => 'textarea',
            'eval'      => ['readonly' => true, 'rows' => 8, 'tl_class' => 'clr'],
            'sql'       => 'blob NULL',
        ],
        'pdfPath' => [
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['readonly' => true, 'tl_class' => 'w50'],
            'sql'       => "varchar(255) NOT NULL default ''",
        ],
        'sentAt' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'respondedAt' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        // Internal: last mail send failure (kind + message); empty when the last send
        // succeeded. Written by WorkflowMailResultListener, shown in the dashboard.
        'sendError' => [
            'eval' => ['doNotCopy' => true],
            'sql'  => 'text NULL',
        ],
        'sendErrorAt' => [
            'eval' => ['doNotCopy' => true],
            'sql'  => "int(10) unsigned NOT NULL default 0",
        ],
        // A permanent delivery failure (hard bounce) was received for this address. Set by
        // BounceCollector; excludes the entry from further invitation/reminder runs until the
        // address is corrected (EntryListener resets these on an e-mail change). Deliberately
        // separate from sendError: a transport error is retryable, an invalid address needs a
        // human. bounceInfo carries the recipient + diagnostic code for the dashboard.
        'bounceHard' => [
            'eval' => ['doNotCopy' => true],
            'sql'  => "char(1) NOT NULL default ''",
        ],
        'bounceInfo' => [
            'eval' => ['doNotCopy' => true],
            'sql'  => "varchar(255) NOT NULL default ''",
        ],
        // Post-response processing: PDF generated AND result mail enqueued successfully.
        // 0 while still open/failed; a workflow is only "fully done" (and drops out of the
        // "Offene Vorgänge" list) once this is set and there is no send error / hard bounce.
        // Fed by SubmissionProcessor::produceConfirmation() and the retry cron.
        'resultDoneAt' => [
            'eval' => ['doNotCopy' => true],
            'sql'  => "int(10) unsigned NOT NULL default 0",
        ],
        'resultError' => [
            'eval' => ['doNotCopy' => true],
            'sql'  => "varchar(255) NOT NULL default ''",
        ],
        // The in-flight parcel id and its kind used to live here (one slot per entry).
        // They now live in the durable tl_workflow_send table so a mail can be correlated
        // to its (asynchronous) send result and to a later bounce; see
        // WorkflowSendTableMigration. sendError / sendErrorAt / sentAt stay here as
        // denormalized display fields for the dashboard, fed from tl_workflow_send.
    ],
];
