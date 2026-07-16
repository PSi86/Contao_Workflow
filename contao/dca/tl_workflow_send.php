<?php

declare(strict_types=1);

use Contao\DC_Table;

/*
 * Durable per-mail send log. One row per Notification Center parcel id, written when a
 * workflow mail (invitation, reminder, result) is dispatched and updated with the real,
 * often asynchronous, send result. The row is NEVER deleted: a bounce (DSN) for a mail
 * arrives minutes to hours after the send result, and it is correlated back to the
 * originating mail purely via the parcel id.
 *
 * This table replaces the single-slot tl_workflow_entry.sendParcelId / .sendKind columns,
 * which could only track one in-flight mail per entry and were cleared exactly when they
 * became useful for bounce matching.
 *
 * Not edited in the back end; it is maintained by NotificationDispatcher (insert on
 * dispatch) and WorkflowMailResultListener (state update). The denormalized display fields
 * tl_workflow_entry.sendError / .sendErrorAt / .sentAt are still fed from here.
 *
 * State machine:
 *   queued --SentMessageEvent----> sent ----DSN 5.x.x (AP5)---> bounced
 *     |                             ^
 *     |                             | retry succeeds
 *     +---FailedMessageEvent--> failed
 */
$GLOBALS['TL_DCA']['tl_workflow_send'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'sql' => [
            'keys' => [
                'id'               => 'primary',
                'parcelId'         => 'unique',
                'entryId'          => 'index',
                'recipient,state'  => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        // Notification Center parcel id; the correlation key for send results and bounces.
        'parcelId' => [
            'sql' => "varchar(64) NOT NULL default ''",
        ],
        'entryId' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'workflowId' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        // invite|reminder|result
        'kind' => [
            'sql' => "varchar(16) NOT NULL default ''",
        ],
        'recipient' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        // queued|sent|failed|bounced
        'state' => [
            'sql' => "varchar(16) NOT NULL default ''",
        ],
        'queuedAt' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'sentAt' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        'bouncedAt' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        // Transport error message (failed state).
        'error' => [
            'sql' => 'text NULL',
        ],
        // Diagnostic code of a bounce (DSN), shortened. Filled in AP5.
        'bounceCode' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
    ],
];
