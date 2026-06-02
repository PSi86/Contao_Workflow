<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_trainer_workflow']['title_legend']        = 'Title';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['steps_legend']        = 'Steps / status';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['source_legend']       = 'Source data';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['form_legend']         = 'Form & answer fields';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdf_legend']          = 'PDF content';
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
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBodyType']     = ['PDF content', 'Simple letter: the body texts are maintained via PDF rules (per answer). Specific template: a file that handles everything itself – then there are no PDF rules.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBodyTypeOptions'] = ['letter' => 'Simple letter (texts via PDF rules)', 'template' => 'Specific template (file)'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfTitle']        = ['Heading', 'Shared heading for all letter variants. Placeholders allowed, e.g. ##Vorname## ##Name##, ##Jahr##.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBodyTemplate'] = ['Body template', 'Body template (file "pdf_body_*"). The template contains its own logic – PDF rules do not apply. Its PDF variables are then suggested automatically.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['master']        = ['Letterhead', 'Letterhead/master (template, logo, variables). Maintained under "Letterhead templates".'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['ncInvite']     = ['Invitation notification', 'Notification Center: invitation with the individual link.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['ncReminder']   = ['Reminder notification', 'Notification Center: reminder for pending responses.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['ncResult']     = ['Result notification', 'Notification Center: result mail with the attached PDF.'];

$GLOBALS['TL_LANG']['tl_trainer_workflow']['questions'] = ['Answer fields', 'Fields the trainer fills in the form. "New" adds a field, "Edit" opens it in a dialog.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['rules']    = ['PDF rules (letter texts)', 'Each rule = conditions + a body text. The first matching rule provides the text; a rule WITHOUT conditions is marked "(default text)" and always applies (place it last, only one allowed).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['rulesEmpty'] = 'No body texts yet. Add one with "New" – a rule without conditions is the "(default text)".';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['entries'] = ['Entries', 'Manage the entries of this workflow.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['edit']    = ['Edit', 'Edit the workflow.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['copy']    = ['Copy', 'Copy the workflow.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['delete']  = ['Delete', 'Delete the workflow.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['show']    = ['Details', 'Show workflow details.'];
