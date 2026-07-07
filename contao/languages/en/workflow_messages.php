<?php

declare(strict_types=1);

// Backend overview module (be_workflow_dashboard) + workflow-backend.js.
$GLOBALS['TL_LANG']['workflow_dashboard'] = [
    'heading'          => 'Workflow – Overview',
    'no_workflows'     => 'No workflow has been created yet. Please create a workflow under “Workflows” first.',
    'unpublished'      => '(not published)',
    'not_runnable'     => '⚠ not runnable',
    'not_runnable_msg' => 'This workflow cannot run:',
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
    'pending'          => 'Pending responses',
    'selection'        => 'Selection:',
    'sel_all'          => 'All',
    'sel_none'         => 'Clear',
    'col_email'        => 'E-mail',
    'col_name'         => 'Name',
    'col_vorname'      => 'First name',
    'col_abteilung'    => 'Department',
    'close'            => 'Close',
    'mode_auto'        => 'Automatic (recipients by status)',
    'mode_manual'      => 'Manual selection (checked participants)',
    'send_invites'     => 'Send invitations',
    'send_reminders'   => 'Send reminders',
    'send_now'         => 'Send now',
    'back'             => 'Back',
    'no_pending'       => 'No pending responses.',
    // Used by workflow-backend.js (via data-* attributes).
    'hint_manual'      => 'Only the checked participants with a matching status are included.',
    'hint_auto'        => 'Recipients are selected automatically by status.',
    'no_recipients'    => 'There are no matching recipients for this action.',
    'confirm_invite'   => 'The following %count% recipients will receive the invitation:',
    'confirm_reminder' => 'The following %count% recipients will receive the reminder:',
];

// WorkflowValidator::getProblems() – shown on the overview (and the edit mask).
$GLOBALS['TL_LANG']['workflow_validator'] = [
    'no_source'          => 'No source file selected – the workflow can only run after a source file has been loaded.',
    'source_unreadable'  => 'The source file is not readable or contains no columns.',
    'no_email_col'       => 'No e-mail column selected.',
    'email_col_missing'  => 'The e-mail column “%s” is missing from the source file.',
    'storage_missing'    => 'The storage field “%s” (form field “%s”) is missing from the source file.',
    'rule_unknown_field' => 'The document text “%s” uses the unknown field “%s”.',
];
