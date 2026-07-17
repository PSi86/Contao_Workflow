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
    'completed'        => 'received',
    'open'             => 'open',
    'total'            => 'total',
    'col_step'         => 'Step',
    'col_status'       => 'Status',
    'col_count'        => 'Count',
    'btn_edit'         => 'Edit',
    'btn_import'       => 'Run import',
    'btn_send'         => 'Send e-mails',
    'btn_export_xlsx'  => 'Export (XLSX)',
    'btn_export_csv'   => 'Export (CSV)',
    'btn_pdfs'         => 'Download PDFs',
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
    'no_email_col'       => 'No e-mail column selected.',
    'email_col_missing'  => 'The e-mail column “%s” is missing from the source file.',
    'storage_missing'    => 'The storage field “%s” (form field “%s”) is missing from the source file.',
    'rule_unknown_field' => 'The document text “%s” uses the unknown field “%s”.',
    'master_missing'     => 'The assigned letterhead no longer exists (it was deleted) – please assign a valid letterhead.',
    'sender_placeholder'    => 'Sender address “%s” uses an example/placeholder domain (“%s”). Mail to such addresses is not delivered and bounce messages vanish unnoticed. Please set a real sender address on your own domain (in the Notification Center).',
    'sender_no_mx'          => 'Sender address “%s”: the domain “%s” has no MX record in DNS. Mail from this sender is undeliverable and bounce messages vanish unnoticed. Please set a real, sendable sender address (in the Notification Center).',
    'sender_domain_mismatch'=> 'The sender domain “%s” differs from the website domain (%s). Please check the SPF/DKIM/DMARC alignment, otherwise mail may be treated as spam.',
];
