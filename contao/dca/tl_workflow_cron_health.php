<?php

declare(strict_types=1);

use Contao\DC_Table;

/*
 * Single-row store for when the cron was last CALLED (see Service\CronHealth): by a server
 * cron job on the /_contao/cron URL, or by "contao:cron" on the command line. Always id = 1.
 *
 * Contao also runs its cron jobs at the end of ordinary page views, so "a cron job ran" says
 * nothing about whether the server cron works. These two timestamps do: they are written only
 * by those two triggers. The overview reads them for its status line.
 *
 * Not edited in the back end. Defined via DCA so the schema diff on contao:migrate creates
 * the table and never proposes to drop it.
 */
$GLOBALS['TL_DCA']['tl_workflow_cron_health'] = [
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
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        // Last request to /_contao/cron (a server cron job calling the URL).
        'lastUrlAt' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        // Last run of "contao:cron" on the command line.
        'lastCliAt' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
    ],
];
