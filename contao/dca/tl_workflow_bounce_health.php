<?php

declare(strict_types=1);

use Contao\DC_Table;

/*
 * Single-row store for the bounce collector's last verdict (see Service\Bounce\BounceHealth).
 *
 * The collector runs as a background cron; the back-end overview renders on demand. This row
 * is the bridge: the cron writes whether the mailbox was reachable, the overview reads it to
 * show an error banner — without doing an IMAP connection on a page load. Always id = 1.
 *
 * Not edited in the back end. Defined via DCA (not just a CREATE-TABLE migration) so the
 * schema diff on contao:migrate creates it and never proposes to drop it.
 */
$GLOBALS['TL_DCA']['tl_workflow_bounce_health'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'sql' => [
            'keys' => [
                'id' => 'primary',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        // When this verdict was recorded.
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        // ok | error | unconfigured (BounceHealth::STATE_*).
        'state' => [
            'sql' => "varchar(16) NOT NULL default ''",
        ],
        // Error detail shown in the overview banner (empty unless state = error).
        'message' => [
            'sql' => 'text NULL',
        ],
    ],
];
