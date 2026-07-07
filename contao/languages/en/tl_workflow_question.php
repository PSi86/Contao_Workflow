<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow_question']['question_legend'] = 'Form field';

$GLOBALS['TL_LANG']['tl_workflow_question']['label']        = ['Heading', 'Heading of the form field shown in the form. For "Explanation" it is only the internal name (not shown).'];
$GLOBALS['TL_LANG']['tl_workflow_question']['type']         = ['Type', 'Type of the form field. Depending on the type the relevant fields are shown/hidden.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['storageField'] = ['Storage column', 'Source column the selected value is written into.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['mandatory']    = ['Mandatory', 'The field must be filled in the form.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['description']  = ['Description', 'Optional hint shown only in the form below the heading (only when not empty). It never appears in the document.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['prefill']      = ['Prefill with the stored value', 'The field is prefilled with the stored value (from the source file or a previous answer) and stays editable. If the value of a choice field matches no option, the field starts empty.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['readOnly']     = ['Read-only', 'The field shows the stored data value but cannot be changed (neither validated nor stored on submission). Mandatory and prefill have no effect then.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['showStatementInForm'] = ['Show statement in the form', 'Shows the document text ("This is how it appears in the document") in the form. Turn off to hide it from the person filling in the form.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['hideInForm']   = ['Hide field in the form', 'The field is not shown in the form and is filled automatically with the current date on submission.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['options']      = ['Options', 'Available choices. "Value" is stored, "Option text" is displayed, "Document text" appears in the document (empty = option text counts verbatim).'];
$GLOBALS['TL_LANG']['tl_workflow_question']['pdfStatement'] = ['Document text (statement)', 'Sentence that appears in the document for this field; ##answer## stands for the entered value, other ##tokens## and {{insert tags}} resolve as usual. Empty = "heading: value". Reference it in the document text via ##text_<storage-column>## or ##text_all##. Choice fields maintain the document text per option. For "Explanation" this is the displayed text paragraph.'];

$GLOBALS['TL_LANG']['tl_workflow_question']['option_value']     = 'Value (stored)';
$GLOBALS['TL_LANG']['tl_workflow_question']['option_label']     = 'Option text';
$GLOBALS['TL_LANG']['tl_workflow_question']['option_statement'] = 'Document text (empty = option text)';

$GLOBALS['TL_LANG']['tl_workflow_question']['typeOptions'] = [
    'text'     => 'Free text (single line)',
    'textarea' => 'Free text (multi line)',
    'number'   => 'Number',
    'date'     => 'Date',
    'select'   => 'Dropdown',
    'radio'    => 'Radio buttons',
    'checkbox' => 'Checkboxes (multi-select)',
    'currentTime' => 'Current time (filled automatically)',
    'explanation' => 'Explanation (text paragraph, no input)',
];

$GLOBALS['TL_LANG']['tl_workflow_question']['new']    = ['New form field', 'Add a form field.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['edit']   = ['Edit', 'Edit form field.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['copy']   = ['Copy', 'Copy form field.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['delete'] = ['Delete', 'Delete form field.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['show']   = ['Details', 'Show form field.'];
