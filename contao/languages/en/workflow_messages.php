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
    'bounce_unconfigured' => 'ℹ No bounce mailbox is set up. An administrator can set it up below under “Bounce mailbox” (or via WORKFLOW_BOUNCE_IMAP_DSN in .env.local – after changing it there, rebuild the production cache). In this state, delivery failures and bounces cannot be detected. See DEPLOYMENT.md, section 3c.',
    'bounce_error'        => '⚠ The bounce mailbox that is set up cannot be reached: %1$s Delivery failures and bounces are currently not detected. Please check the access data (below under “Bounce mailbox”, or WORKFLOW_BOUNCE_IMAP_DSN: host, port, user, password formatting). (Last checked: %2$s)',
    // Dialog “Bounce mailbox” (administrators only). mailbox_env: %1$s = server, %2$s = port,
    // %3$s = user, %4$s = bounce address.
    'mailbox_title'       => 'Bounce mailbox',
    'mailbox_intro'       => 'This mailbox receives the delivery status notifications (bounces) of all workflow mails: its address is set as the bounce address (envelope sender) on every send. Sender and “Reply-To” of the notifications stay as they are – replies from people therefore still go there, not here. The bundle reads this mailbox itself.',
    'mailbox_env'         => 'Set by WORKFLOW_BOUNCE_IMAP_DSN in .env.local: server %1$s:%2$d, user %3$s, bounce address %4$s. This setting takes precedence; it can only be changed here once the variable is removed.',
    'mailbox_no_address'  => 'none (the user is not an e-mail address – set WORKFLOW_BOUNCE_ADDRESS)',
    'mailbox_address'     => 'Address of the bounce mailbox',
    'mailbox_host'        => 'IMAP server',
    'mailbox_port'        => 'Port',
    'mailbox_encryption'  => 'Encryption',
    'mailbox_enc_ssl'     => 'SSL/TLS (usually 993)',
    'mailbox_enc_tls'     => 'STARTTLS (usually 143)',
    'mailbox_enc_none'    => 'none (not recommended)',
    'mailbox_user'        => 'User',
    'mailbox_user_placeholder' => 'empty = the address',
    'mailbox_password'    => 'Password',
    'mailbox_password_kept' => 'stored – leave empty to keep it',
    'mailbox_save_check'  => 'Save and check mailbox',
    'mailbox_save'        => 'Save only',
    'mailbox_clear'       => 'Remove',
    'mailbox_clear_confirm' => 'Remove the bounce mailbox? Bounces are then no longer detected until one is set up again.',
    'mailbox_hints'       => 'Notes: use the address for bounces only – never as sender or “Reply-To” of a notification. Turn off the spam filter of this mailbox. Every installation (e.g. test and production) needs a mailbox of its own. The password is stored encrypted in the database; the key comes from APP_SECRET.',
    'mailbox_saved'       => 'Bounce mailbox saved.',
    'mailbox_cleared'     => 'Bounce mailbox removed.',
    'mailbox_env_locked'  => 'The bounce mailbox is set by WORKFLOW_BOUNCE_IMAP_DSN in .env.local and cannot be changed here.',
    'mailbox_password_unreadable' => '⚠ The stored password of the bounce mailbox can no longer be decrypted (was APP_SECRET changed?). Until it is entered again under “Bounce mailbox”, bounces are not detected.',
    'mailbox_password_reenter' => 'The stored password can no longer be decrypted – please enter it again.',
    'mailbox_err_address' => 'Please enter a valid address of the bounce mailbox.',
    'mailbox_err_host'    => 'Please enter the IMAP server (e.g. w0123456.kasserver.com).',
    'mailbox_err_port'    => 'The port must be a number between 1 and 65535.',
    'mailbox_err_encryption' => 'Unknown encryption.',
    'mailbox_err_user'    => 'Please enter the user.',
    'mailbox_err_password'=> 'Please enter the password.',
    'mailbox_err_sodium'  => 'The PHP extension sodium is missing; the password cannot be stored encrypted. Please use WORKFLOW_BOUNCE_IMAP_DSN in .env.local instead.',
    // Status line cron / bounce check (DashboardModule::cronStatus). Cron: %1$s = time,
    // %2$s = "… ago", %3$s = trigger (/_contao/cron or contao:cron).
    'cron_last'        => 'Cron: last called on %1$s (%2$s, via %3$s).',
    'cron_stale'       => '⚠ Cron: last call on %1$s (%2$s, via %3$s) – none since. The mail queue, the bounce check and the confirmation retry then only run on page views – and only after the page has been delivered. See DEPLOYMENT.md, section 2a.',
    'cron_never'       => '⚠ Cron: no call via /_contao/cron or contao:cron has been detected yet. The mail queue, the bounce check and the confirmation retry then only run on page views – and only after the page has been delivered. See DEPLOYMENT.md, section 2a.',
    // Bounce: %1$s = last check, %2$s = next check, %3$s = reason for the interval.
    'bounce_status'    => 'Bounce mailbox: last checked %1$s, next check from %2$s – %3$s.',
    'bounce_never'     => 'never',
    'bounce_next_due'  => 'the next cron call',
    'bounce_tier_recent'      => 'every 2 minutes, because mails were sent %s',
    'bounce_tier_outstanding' => 'every 15 minutes while %d invitation(s) are unanswered',
    'bounce_tier_idle'        => 'every 2 hours, as no answers are outstanding',
    'bounce_without_cron'     => 'Without a cron job this only happens on the next page view.',
    'bounce_check_now'  => 'Check now',
    'bounce_check_busy' => 'The bounce mailbox is already being checked – please try again in a minute.',
    'bounce_check_error' => 'Bounce mailbox not reachable: %s Please check the access data (below under “Bounce mailbox”, or WORKFLOW_BOUNCE_IMAP_DSN).',
    'bounce_check_done' => 'Bounce mailbox checked: %1$d new message(s) – %2$d address(es) blocked as undeliverable, %3$d temporary notice(s), %4$d without a matching entry, %5$d without a delivery status notification.',
    // Status line and “Check now”: %d = messages in the INBOX of the bounce mailbox that are
    // not bounces of workflow mails (the bounce collector leaves them there).
    // End-to-end test of bounce detection (Service\Bounce\BounceProbe). Status lines:
    // %1$s = start, %2$s = workflow; queued: %3$s = test address; waiting: %3$s = sent at,
    // %4$s = bounce address, %5$s = waiting until; ok: %3$s = duration, %4$s = bounce address;
    // failed: %3$s = error; timeout: %3$s = bounce address.
    'bounce_tier_test'        => 'every minute while the end-to-end test waits for its bounce',
    'bounce_test_label'       => 'Test bounce detection with the invitation of',
    'bounce_test_start'       => 'Start end-to-end test',
    'bounce_test_hint'        => 'Sends the invitation of the chosen workflow along its real mail path to an address that does not exist (…@bounce-test.invalid) and waits up to 30 minutes for the bounce to arrive in the bounce mailbox. No entry is changed. CC/BCC recipients of the invitation receive the test mail as well.',
    'bounce_test_started'     => 'Test mail to %s handed over. The bounce mailbox is now checked every minute; the result appears in the status line.',
    'bounce_test_unconfigured'=> 'Without a bounce mailbox the test cannot find a bounce. Please set up the bounce mailbox first (below under “Bounce mailbox” or via WORKFLOW_BOUNCE_IMAP_DSN, DEPLOYMENT.md, section 3c).',
    'bounce_test_no_invite'   => 'The workflow “%s” has no invitation notification – there is nothing to test with.',
    'bounce_test_no_workflow' => 'Workflow not found.',
    'bounce_test_not_handed_over' => 'The test mail to %s could not be handed over to the mailer. Details in the status line and the system log.',
    'bounce_test_no_envelope' => 'none – the bounce goes to the sender',
    'bounce_test_queued'      => 'End-to-end test of %1$s (workflow “%2$s”): test mail to %3$s queued, not sent yet – that happens on the next cron call.',
    'bounce_test_waiting'     => 'End-to-end test of %1$s (workflow “%2$s”): test mail sent at %3$s, bounce address %4$s. Waiting for the bounce; the mailbox is checked every minute until %5$s.',
    'bounce_test_ok'          => '✔ End-to-end test of %1$s (workflow “%2$s”): the bounce arrived in the bounce mailbox after %3$s and was matched. Bounce address: %4$s.',
    'bounce_test_failed'      => '⚠ End-to-end test of %1$s (workflow “%2$s”): sending failed – %3$s',
    'bounce_test_timeout'     => '⚠ End-to-end test of %1$s (workflow “%2$s”): no bounce arrived in the bounce mailbox within 30 minutes. Bounce address: %3$s. Possible causes: the bounce address is not the mailbox being read, or a spam filter sorts bounces out (DEPLOYMENT.md, section 3c).',
    'bounce_foreign'    => 'The INBOX of the bounce mailbox holds %d message(s) that are not bounces of workflow mails (e.g. replies, out-of-office notes or bounces of other senders). They stay there. Does a sender or “Reply-To” address point at this mailbox? Please look at them in webmail and delete them afterwards.',
    'ago_now'          => 'less than a minute ago',
    'ago_minutes'      => '%d min ago',
    'ago_hours'        => '%d h ago',
    'ago_days'         => '%d days ago',
    'hard_bounces'     =>'Invalid addresses (%d) – permanently undeliverable (bounce)',
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
