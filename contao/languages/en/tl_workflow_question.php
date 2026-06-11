<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow_question']['question_legend'] = 'Answer field';

$GLOBALS['TL_LANG']['tl_workflow_question']['label']        = ['Label', 'Question/field label shown in the form.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['type']         = ['Type', 'Type of the answer field. Note: a change is saved immediately.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['storageField'] = ['Storage column', 'Source column the selected value is written into (mandatory).'];
$GLOBALS['TL_LANG']['tl_workflow_question']['mandatory']    = ['Mandatory', 'The field must be filled in the form.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['prefill']      = ['Prefill with the stored value', 'The field is prefilled with the stored value (from the source file or a previous answer) and stays editable. If the value of a choice field matches no option, the field starts empty.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['readOnly']     = ['Read-only', 'The field shows the stored data value but cannot be changed (neither validated nor stored on submission). Mandatory and prefill have no effect then.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['hideInForm']   = ['Hide field in the form', 'The field is not shown in the form and is filled automatically with the current date on submission.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['options']      = ['Options', 'Available choices. "Value" is stored, "Option text" is displayed, "Document text" appears in the PDF (empty = option text counts verbatim).'];
$GLOBALS['TL_LANG']['tl_workflow_question']['pdfStatement'] = ['Document text (statement)', 'Sentence that appears in the document for this field; ##value## stands for the entered value, other ##tokens## resolve as usual. Empty = "label: value". Reference it in the PDF text via ##stmt_<storage-column>## or ##stmt_all##. Choice fields maintain the document text per option.'];

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
];

$GLOBALS['TL_LANG']['tl_workflow_question']['new']    = ['New answer field', 'Add an answer field.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['edit']   = ['Edit', 'Edit answer field.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['copy']   = ['Copy', 'Copy answer field.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['delete'] = ['Delete', 'Delete answer field.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['show']   = ['Details', 'Show answer field.'];
