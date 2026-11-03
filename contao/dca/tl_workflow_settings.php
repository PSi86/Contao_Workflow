<?php

declare(strict_types=1);

use Contao\DC_Table;

/*
 * Single-row store for settings made in the workflow overview (3.6.0: the bounce mailbox, see
 * Service\Bounce\BounceSettings). Always id = 1.
 *
 * Not edited through a DCA form; the overview dialog writes it. Defined via DCA so the schema
 * diff on contao:migrate creates it and never proposes to drop it.
 */
$GLOBALS['TL_DCA']['tl_workflow_settings'] = [
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
        // Address of the bounce mailbox: the envelope sender of every workflow mail.
        'bounceAddress' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'imapHost' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        'imapPort' => [
            'sql' => "int(10) unsigned NOT NULL default 993",
        ],
        // ssl | tls | none
        'imapEncryption' => [
            'sql' => "varchar(8) NOT NULL default 'ssl'",
        ],
        'imapUser' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        // libsodium secretbox, key derived from APP_SECRET (Service\Bounce\PasswordCipher).
        'imapPasswordEnc' => [
            'sql' => 'text NULL',
        ],
    ],
];
