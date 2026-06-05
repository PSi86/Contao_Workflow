<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow']['title_legend']        = 'Title';
$GLOBALS['TL_LANG']['tl_workflow']['steps_legend']        = 'Steps / status';
$GLOBALS['TL_LANG']['tl_workflow']['source_legend']       = 'Source data';
$GLOBALS['TL_LANG']['tl_workflow']['form_legend']         = 'Form & answer fields';
$GLOBALS['TL_LANG']['tl_workflow']['pdf_legend']          = 'PDF content';
$GLOBALS['TL_LANG']['tl_workflow']['notification_legend'] = 'Notifications';

$GLOBALS['TL_LANG']['tl_workflow']['title']        = ['Title', 'Name of the workflow.'];
$GLOBALS['TL_LANG']['tl_workflow']['published']    = ['Published', 'Only published workflows accept responses.'];
$GLOBALS['TL_LANG']['tl_workflow']['steps']        = ['Steps', 'Ordered list of step labels (status 0, 1, 2 …).'];
$GLOBALS['TL_LANG']['tl_workflow']['sourceFile']    = ['Source file', 'CSV or XLSX file. Selecting it detects the columns; the selection is saved immediately.'];
$GLOBALS['TL_LANG']['tl_workflow']['sourceSheet']   = ['Worksheet', 'Which sheet of the workbook to read (empty = active sheet). Note: a change is saved immediately.'];
$GLOBALS['TL_LANG']['tl_workflow']['headerRow']     = ['Header row', 'Row number that holds the column headers (default 1). Note: a change is saved immediately.'];
$GLOBALS['TL_LANG']['tl_workflow']['emailField']    = ['E-mail column', 'Column holding the e-mail address (invitation recipient).'];
$GLOBALS['TL_LANG']['tl_workflow']['inputFields']   = ['Display fields (input)', 'Columns shown prefilled and read-only in the form.'];
$GLOBALS['TL_LANG']['tl_workflow']['formPage']      = ['Form page', 'Page that hosts the workflow form module (link target).'];
$GLOBALS['TL_LANG']['tl_workflow']['requireSignature'] = ['Require signature', 'When enabled, the form requires a signature (embedded into the PDF).'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfBodyType']     = ['PDF content', 'Simple letter: the body texts are maintained via PDF rules (per answer). Specific template: a file that handles everything itself – then there are no PDF rules. Note: a change is saved immediately.'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfBodyTypeOptions'] = ['letter' => 'Simple letter (texts via PDF rules)', 'template' => 'Specific template (file)'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfTitle']        = ['Heading', 'Shared heading for all letter variants. Placeholders allowed, e.g. ##data_vorname## ##data_name##, ##var_jahr##.'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfBodyTemplate'] = ['Body template', 'Body template (file "pdf_body_*"). The template contains its own logic – PDF rules do not apply. Note: a change is saved immediately.'];
$GLOBALS['TL_LANG']['tl_workflow']['master']        = ['Stationery', 'Stationery (header/footer, logo, variables). Maintained under "Stationery".'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfSignatureDate'] = ['Signature date field', 'Data field whose value is printed as the date in the PDF signature line (e.g. a "Current time" answer field). Empty = no date.'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfSignatureLocation'] = ['Signature place field', "Data field (e.g. the participant's town from the source file) whose value is printed as the place in the PDF signature line. Empty = no place."];
$GLOBALS['TL_LANG']['tl_workflow']['pdfFileName'] = ['PDF file name', 'Pattern for the generated PDF file name, placeholders allowed, e.g. Verzicht_##data_name##_##data_vorname##. Sanitized to a safe name; a short token is appended on collision. Empty = token.'];
$GLOBALS['TL_LANG']['tl_workflow']['ncInvite']     = ['Invitation notification', 'Notification Center: invitation with the individual link.'];
$GLOBALS['TL_LANG']['tl_workflow']['ncReminder']   = ['Reminder notification', 'Notification Center: reminder for pending responses.'];
$GLOBALS['TL_LANG']['tl_workflow']['ncResult']     = ['Result notification', 'Notification Center: result mail with the attached PDF.'];

$GLOBALS['TL_LANG']['tl_workflow']['questions'] = ['Answer fields', 'Fields the recipient fills in the form. "New" adds a field, "Edit" opens it in a dialog.'];
$GLOBALS['TL_LANG']['tl_workflow']['rules']    = ['PDF rules (letter texts)', 'Each rule = conditions + a body text. The first matching rule provides the text; a rule WITHOUT conditions is marked "(default text)" and always applies (place it last, only one allowed).'];
$GLOBALS['TL_LANG']['tl_workflow']['rulesEmpty'] = 'No body texts yet. Add one with "New" – a rule without conditions is the "(default text)".';
$GLOBALS['TL_LANG']['tl_workflow']['entries'] = ['Entries', 'Manage the entries of this workflow.'];
$GLOBALS['TL_LANG']['tl_workflow']['edit']    = ['Edit', 'Edit the workflow.'];
$GLOBALS['TL_LANG']['tl_workflow']['copy']    = ['Copy', 'Copy the workflow.'];
$GLOBALS['TL_LANG']['tl_workflow']['delete']  = ['Delete', 'Delete the workflow.'];
$GLOBALS['TL_LANG']['tl_workflow']['show']    = ['Details', 'Show workflow details.'];
