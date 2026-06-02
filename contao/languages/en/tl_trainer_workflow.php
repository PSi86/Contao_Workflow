<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_trainer_workflow']['title_legend']        = 'Title';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['steps_legend']        = 'Steps / status';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['source_legend']       = 'Source data';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['form_legend']         = 'Form';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdf_legend']          = 'PDF';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['notification_legend'] = 'Notifications';

$GLOBALS['TL_LANG']['tl_trainer_workflow']['title']        = ['Title', 'Name of the workflow.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['published']    = ['Published', 'Only published workflows accept responses.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['steps']        = ['Steps', 'Ordered list of step labels (status 0, 1, 2 …).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['sourceFile']    = ['Source file', 'CSV or XLSX file. Save after selecting so the columns are detected.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['sourceSheet']   = ['Worksheet', 'Which sheet of the workbook to read (empty = active sheet).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['headerRow']     = ['Header row', 'Row number that holds the column headers (default 1).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['emailField']    = ['E-mail column', 'Column holding the e-mail address (invitation recipient).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['inputFields']   = ['Display fields (input)', 'Columns shown prefilled and read-only in the form.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['formPage']      = ['Form page', 'Page that hosts the trainer form module (link target).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['requireSignature'] = ['Require signature', 'When enabled, the form requires a signature (embedded into the PDF).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBodyType']     = ['PDF content', 'Simple letter (editable in the back end) or a specific body template (file).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBodyTypeOptions'] = ['letter' => 'Simple letter (text in back end)', 'template' => 'Specific template (file)'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfTitle']        = ['Heading', 'Letter title. Placeholders allowed, e.g. ##Vorname## ##Name##, ##Jahr##.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBody']         = ['Body text', 'Main text (default when no rule matches). Placeholders: ##column## (incl. answer fields), ##datum##, ##email## and PDF variables (##Jahr##, ##Verein## …).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBodyTemplate'] = ['Body template', 'Default body template ("pdf_body_*") when no PDF rule matches. Its PDF variables are then suggested automatically.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['master']        = ['Letterhead', 'Letterhead/master (template, logo, variables). Maintained under "Letterhead templates".'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['ncInvite']     = ['Invitation notification', 'Notification Center: invitation with the individual link.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['ncReminder']   = ['Reminder notification', 'Notification Center: reminder for pending responses.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['ncResult']     = ['Result notification', 'Notification Center: result mail with the attached PDF.'];

$GLOBALS['TL_LANG']['tl_trainer_workflow']['entries'] = ['Entries', 'Manage the entries of this workflow.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['edit']    = ['Edit', 'Edit the workflow.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['copy']    = ['Copy', 'Copy the workflow.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['delete']  = ['Delete', 'Delete the workflow.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['show']    = ['Details', 'Show workflow details.'];
