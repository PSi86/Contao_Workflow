<?php

declare(strict_types=1);

use Contao\DC_Table;

/*
 * One row per import run: when, by whom, in which mode, against which file – and what came
 * of it.
 *
 * The reason it exists: the result of an import depends on the runs before it (row numbers,
 * frozen answers, the chosen mode). Without a record, a state can only be guessed at
 * backwards from the data, and a mistake that surfaces two runs later cannot be explained at
 * all. The log is the one place that answers "how did it get like this?".
 *
 * The source file is recorded by name AND checksum, so two runs can be told apart even when
 * the file was overwritten in place under the same name – the case that produces the most
 * confusing results.
 *
 * Written by Service\ImportLog (from SpreadsheetImporter, on success and on failure), shown
 * read-only in the workflow edit mask (ImportLogListener), never edited. Kept bounded by
 * Cron\PurgeWorkflowImportLogCron and removed with its workflow (WorkflowDeleteListener).
 */
$GLOBALS['TL_DCA']['tl_workflow_import'] = [
    'config' => [
        'dataContainer' => DC_Table::class,
        'ptable'        => 'tl_workflow',
        'sql' => [
            'keys' => [
                'id'         => 'primary',
                'pid,tstamp' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => [
            'sql' => 'int(10) unsigned NOT NULL auto_increment',
        ],
        'pid' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        // When the run finished.
        'tstamp' => [
            'sql' => "int(10) unsigned NOT NULL default 0",
        ],
        // add|absolute (SpreadsheetImporter::MODE_*).
        'mode' => [
            'sql' => "varchar(16) NOT NULL default ''",
        ],
        // ok|failed – a failed run is logged too; "nothing happened, and why" is exactly
        // what one looks for afterwards.
        'state' => [
            'sql' => "varchar(8) NOT NULL default ''",
        ],
        // Back end user name, or "CLI" for a console run.
        'triggeredBy' => [
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        // Path of the source file at the time of the run …
        'sourceFile' => [
            'sql' => "varchar(255) NOT NULL default ''",
        ],
        // … and its checksum: the only way to tell two runs apart when the file was
        // overwritten in place under the same name.
        'sourceHash' => [
            'sql' => "varchar(32) NOT NULL default ''",
        ],
        'sourceSheet' => [
            'sql' => "varchar(128) NOT NULL default ''",
        ],
        // JSON: the counters of the run (inserted, updated, protected, hidden, …). A blob
        // rather than a column each, so a new counter needs no schema change; nothing
        // queries them, they are read back for display.
        'summary' => [
            'sql' => 'text NULL',
        ],
        // JSON list: the messages the run produced (skipped rows, format and formula
        // problems, ambiguous columns) – the same sentences the back end showed at the time.
        'problems' => [
            'sql' => 'text NULL',
        ],
        // Why the run failed (state = failed).
        'error' => [
            'sql' => 'text NULL',
        ],
    ],
];
