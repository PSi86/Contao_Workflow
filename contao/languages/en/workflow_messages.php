<?php

declare(strict_types=1);

// Backend overview module (be_workflow_dashboard) + workflow-backend.js.
$GLOBALS['TL_LANG']['workflow_dashboard'] = [
    'heading'          => 'Workflow – Overview',
    'no_workflows'     => 'No workflow has been created yet. Please create a workflow under “Workflows” first.',
    'unpublished'      => '(not published)',
    'not_runnable'     => '⚠ not runnable',
    'not_runnable_msg' => 'This workflow cannot run:',
    'stuck_queue'      => '⚠ %d e-mail(s) have been queued for sending for over 15 minutes without a result. Is the cron/worker running? See DEPLOYMENT.md, section 2 (setting up the worker/cron in production).',
    // Bounce detection (Service\Bounce\BounceHealth): a notice banner when no mailbox is
    // configured; an error banner (%1$s = reason, %2$s = time of the last check) when the
    // configured mailbox cannot be reached.
    'bounce_unconfigured' => 'ℹ No bounce mailbox is configured (or the configuration did not load – after changing .env.local, rebuild the production cache). In this state, delivery failures and bounces cannot be detected. See DEPLOYMENT.md, section 3c.',
    'bounce_error'        => '⚠ The configured bounce mailbox cannot be reached: %1$s Delivery failures and bounces are currently not detected. Please check WORKFLOW_BOUNCE_IMAP_DSN (host, port, user, password formatting). (Last checked: %2$s)',
    'hard_bounces'     => 'Invalid addresses (%d) – permanently undeliverable (bounce)',
    'col_reason'       => 'Reason',
    'hard_bounces_hint'=> 'These addresses do not exist (hard bounces) and are excluded from invitations and reminders. Correct the entry’s e-mail address to bring it back in.',
    'no_import'        => '⚠ The workflow is configured, but <strong>no import has run yet</strong> – there are no responses yet. Please use “Run import” first.',
    'reimport_needed'  => '⚠ The source file was changed but not imported yet. Until you do, the form and PDF preview show the old data and number formats – please “Run import” to load the current data and field formatting.',
    'completed'        => 'received',
    'open'             => 'open',
    'total'            => 'total',
    'col_step'         => 'Step',
    'col_status'       => 'Status',
    'col_count'        => 'Count',
    'btn_edit'         => 'Edit',
    'btn_import'       => 'Run import',
    'btn_send'         => 'Send e-mails',
    'btn_log'          => 'Import log',
    'log_hint'         => 'What each run did – time, mode, source file with its checksum, and the messages of the day. The outcome of an import depends on the runs before it; this is the record of how the current state came about.',
    'btn_download'     => 'Data download',
    'btn_export_xlsx'  => 'Excel (XLSX)',
    'btn_export_csv'   => 'CSV',
    'btn_pdfs'         => 'PDFs',
    'download_data_hint' => 'All participants with their current data, in the columns and order of the source file.',
    'download_csv_hint'  => 'The same data as a plain text file, e.g. for import into other programs.',
    'download_pdfs_hint' => 'Every document generated for this workflow so far.',
    'download_run'       => 'Download',
    // Result of the selection (also used by workflow-backend.js via data-*).
    'download_result_xlsx' => 'Result: one Excel file (.xlsx).',
    'download_result_csv'  => 'Result: one CSV file (.csv).',
    'download_result_zip'  => 'Result: one ZIP archive (.zip) with the selection.',
    'download_result_none' => 'Please select at least one item.',
    // The import dialog is disabled (see be_workflow_dashboard.html5) – "Run import" starts the
    // additive run directly. Its four texts stay for a possible reactivation. "import_add" and
    // "import_absolute" are NOT unused: the import log labels historic absolute runs with them.
    'import_intro'     => '<strong>Hidden rows are skipped either way</strong> – they are never imported. The mode only decides what happens to <em>existing</em> entries whose row is now hidden or missing from the file:',
    'import_add'       => 'Add',
    'import_add_hint'  => 'Such entries remain and are still mailed. Nothing is deleted.',
    'import_absolute'  => 'Absolute',
    'import_absolute_hint' => 'Such entries are deleted, including any generated PDFs – so the source file decides who takes part. Already answered entries that ARE in the file stay untouched.',
    'import_absolute_confirm' => 'Absolute import: entries no longer visible in the source file will be deleted – including answered ones and their PDFs. Continue?',
    'pending'          => 'Open items',
    'selection'        => 'Selection:',
    'sel_all'          => 'All',
    'sel_none'         => 'Clear',
    'col_email'        => 'E-mail',
    'col_name'         => 'Name',
    'col_vorname'      => 'First name',
    'col_abteilung'    => 'Department',
    'col_delivery'     => 'Delivery',
    'delivery_sent'    => 'Sent',
    'delivery_sent_hint' => 'Sent – no error so far. “Sent” means accepted, not guaranteed delivered; a later bounce will show up here automatically.',
    'delivery_error'   => 'Send error',
    'delivery_bounce'  => 'Undeliverable',
    'close'            => 'Close',
    'mode_auto'        => 'Automatic (recipients by status)',
    'mode_manual'      => 'Manual selection (checked participants)',
    'send_invites'     => 'Send invitations',
    'send_reminders'   => 'Send reminders',
    'send_confirmations' => 'Send confirmation',
    'send_now'         => 'Send now',
    'back'             => 'Back',
    'no_pending'       => 'No open items.',
    'delivery_pending'      => 'Pending',
    'delivery_pending_hint' => 'Response recorded, but the confirmation (PDF + e-mail) has not been produced yet. A retry runs automatically; or use “Re-send confirmation”.',
    // Used by workflow-backend.js (via data-* attributes).
    'hint_manual'      => 'Only the checked participants with a matching status are included.',
    'hint_auto'        => 'Recipients are selected automatically by status.',
    'no_recipients'    => 'There are no matching recipients for this action.',
    'confirm_invite'   => 'The following %count% recipients will receive the invitation:',
    'confirm_reminder' => 'The following %count% recipients will receive the reminder:',
    'confirm_confirmation' => 'The following %count% recipients will receive the confirmation (the PDF is regenerated):',
];

// WorkflowValidator::getProblems() – shown on the overview (and the edit mask).
$GLOBALS['TL_LANG']['workflow_validator'] = [
    'no_source'          => 'No source file selected – the workflow can only run after a source file has been loaded.',
    'source_unreadable'  => 'The source file is not readable or contains no columns.',
    'sheet_missing'      => 'The configured worksheet "%s" does not exist in the source file. Available: "%s". Please adjust the worksheet.',
    'no_email_col'       => 'No e-mail column selected.',
    'email_col_missing'  => 'The e-mail column “%s” is missing from the source file.',
    'storage_missing'    => 'The storage field “%s” (form field “%s”) is missing from the source file.',
    'rule_unknown_field' => 'The document text “%s” uses the unknown field “%s”.',
    'condition_unknown_field' => 'The visibility condition of the form field “%s” checks the column “%s”, which the source file does not (or no longer) have.',
    'condition_forward_ref'   => 'The visibility condition of the form field “%s” checks the column “%s”, which is filled in later in the form (or not at all). Conditions may only refer to preceding form fields – please adjust the order of the form fields.',
    'master_missing'     => 'The assigned letterhead no longer exists (it was deleted) – please assign a valid letterhead.',
    'sender_placeholder'    => 'Sender address “%s” uses an example/placeholder domain (“%s”). Mail to such addresses is not delivered and bounce messages vanish unnoticed. Please set a real sender address on your own domain (in the Notification Center).',
    'sender_no_mx'          => 'Sender address “%s”: the domain “%s” has no MX record in DNS. Mail from this sender is undeliverable and bounce messages vanish unnoticed. Please set a real, sendable sender address (in the Notification Center).',
    'sender_domain_mismatch'=> 'The sender domain “%s” differs from the website domain (%s). Please check the SPF/DKIM/DMARC alignment, otherwise mail may be treated as spam.',
];

// SourceFileInfoListener – the summary under the source-file picker. "current" is the one that
// matters: whoever believes they have just uploaded a new version and reads "as of the last
// import" here has put the file somewhere else – most often under a slightly different name
// (Contao's upload replaces neither spaces nor capitals, so "Table 2026.xlsx" does not overwrite
// "table-2026.xlsx").
$GLOBALS['TL_LANG']['workflow_source'] = [
    'missing'         => 'The source file cannot be found any more – it was deleted, moved or renamed. Please select it again.',
    'never'           => 'This file has not been imported yet.',
    'changed'         => 'The file was changed after the last import (%s) – the stored data still comes from the previous version.',
    'changed_unknown' => 'The file does not match the state of the last import – the stored data still comes from the previous version.',
    'current'         => 'As of the last import (%s) – the file has not changed since. If a new version was uploaded in the meantime, it was stored under a different name; please check the folder in the file manager.',
    'current_unknown' => 'The file matches the state of the last import.',
    'other_file'      => 'Careful: the last import (%2$s) read a <strong>different</strong> file – "%1$s". The stored data comes from that one, not from the file selected above.',
];

// WorkflowIntegrityListener::flagStaleSource() – hint on the edit mask when the stored data no
// longer matches the source file. Two situations, one rule (WorkflowValidator::isSourceDirty)
// but two wordings: "never imported" reads very differently from "the file changed".
$GLOBALS['TL_LANG']['workflow_reimport'] = [
    'edit_hint'         => 'The source file was changed but not imported yet. Until you do, the form and PDF preview show the old data and number formats. Please run the import to load the current data and field formatting.',
    'first_import_hint' => 'The import has not been run for this workflow yet – there is no participant data. Until you run it, the form and PDF preview show sample data only and no e-mails can be sent.',
    'import_button'     => 'Run import now',
];
