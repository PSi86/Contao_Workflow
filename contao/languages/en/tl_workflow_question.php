<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow_question']['question_legend'] = 'Form field';

$GLOBALS['TL_LANG']['tl_workflow_question']['label']        = ['Heading', 'Heading of the form field shown in the form. For "Explanation" it is only the internal name (not shown).'];
$GLOBALS['TL_LANG']['tl_workflow_question']['type']         = ['Type', 'Type of the form field. Depending on the type the relevant fields are shown/hidden.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['storageField'] = ['Storage column', 'Source column the selected value is written into.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['numberDecimals'] = ['Decimals', 'Type "Number" only. Empty = take them from the source column (its cell format). A value fixes the decimals and wins over the source file – useful when the column is empty there or formatted inconsistently. Thousands separator and currency symbol still come from the source file.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['mandatory']    = ['Mandatory', 'The field must be filled in the form.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['description']  = ['Description', 'Optional hint shown only in the form below the heading (only when not empty). It never appears in the document. Placeholders, {{insert tags}} and formatting ([b]bold[/b], [i]italic[/i], [u]underline[/u]) allowed.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['prefill']      = ['Prefill with the stored value', 'The field is prefilled with the stored value (from the source file or a previous answer) and stays editable. If the value of a choice field matches no option, the field starts empty.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['readOnly']     = ['Read-only', 'The field shows the stored data value but cannot be changed (neither validated nor stored on submission). Mandatory and prefill have no effect then.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['showStatementInForm'] = ['Show statement in the form', 'Shows the document text ("This is how it appears in the document") in the form. Turn off to hide it from the person filling in the form.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['hideInForm']   = ['Hide field in the form', 'The field is not shown in the form and is filled automatically with the current date on submission.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['options']      = ['Options', 'Available choices. "Value" is stored, "Option text" is displayed, "Document text" appears in the document (empty = option text counts verbatim). Formatting in the document text: [b]bold[/b], [i]italic[/i], [u]underline[/u].'];
$GLOBALS['TL_LANG']['tl_workflow_question']['pdfStatement'] = ['Document text (statement)', 'Sentence that appears in the document for this field; ##answer## stands for the entered value, other ##tokens## and {{insert tags}} resolve as usual. Empty = "heading: value". Reference it in the document text via ##text_<storage-column>## or ##text_all##. Choice fields maintain the document text per option. For "Explanation" this is the displayed text paragraph. A blank line at the end is kept and sets the statement apart from the next one in the document; the form does not show it. Formatting: [b]bold[/b], [i]italic[/i], [u]underline[/u].'];

// Conditional form fields ("visibility").
$GLOBALS['TL_LANG']['tl_workflow_question']['condition_legend'] = 'Visibility';

$GLOBALS['TL_LANG']['tl_workflow_question']['conditionMode']  = ['Display', 'Controls whether this field appears in the form. "Always" = as before. Otherwise the visibility is re-evaluated while the form is filled in. A mandatory field only has to be filled while it is visible; a hidden field is not stored on submission and its storage column is cleared.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['conditionLogic'] = ['Combination', 'How the conditions work together.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['conditions']     = ['Conditions', 'Only fields ABOVE this one in the list that have a storage column can be checked. A hidden field counts as empty for the conditions that follow it. For checkboxes (multi-select) "contains" checks whether a particular option is ticked.'];

$GLOBALS['TL_LANG']['tl_workflow_question']['conditionAlways'] = 'always';

$GLOBALS['TL_LANG']['tl_workflow_question']['conditionModeOptions'] = [
    'show' => 'only show when …',
    'hide' => 'hide when …',
];

$GLOBALS['TL_LANG']['tl_workflow_question']['conditionLogicOptions'] = [
    'and' => 'all conditions must match (AND)',
    'or'  => 'one condition is enough (OR)',
];

$GLOBALS['TL_LANG']['tl_workflow_question']['cond_field']    = 'Field';
$GLOBALS['TL_LANG']['tl_workflow_question']['cond_operator'] = 'Operator';
$GLOBALS['TL_LANG']['tl_workflow_question']['cond_value']    = 'Comparison value';
$GLOBALS['TL_LANG']['tl_workflow_question']['unknownOption'] = 'Unknown option: %s';

// Value dropdown (workflow-condition-value.js): the options of the trigger field are offered
// as "option text (stored value)". The switch next to it moves between list and free text in
// both directions.
$GLOBALS['TL_LANG']['tl_workflow_question']['condValueOffList'] = '%s (not in the option list)';
$GLOBALS['TL_LANG']['tl_workflow_question']['condValueFree']    = 'Enter a free value';
$GLOBALS['TL_LANG']['tl_workflow_question']['condValueList']    = 'Choose from the list';
$GLOBALS['TL_LANG']['tl_workflow_question']['condValueInert']   = 'This operator does not use a comparison value.';

$GLOBALS['TL_LANG']['tl_workflow_question']['condSelfRef']      = 'A field cannot depend on itself (condition "%s").';
$GLOBALS['TL_LANG']['tl_workflow_question']['condForwardRef']   = 'The condition refers to the field "%s", which is NOT above this one in the list. Conditions may only refer to preceding fields – please adjust the order of the form fields.';
$GLOBALS['TL_LANG']['tl_workflow_question']['condEmpty']        = 'Please provide at least one complete condition (field and operator) or select "always".';
$GLOBALS['TL_LANG']['tl_workflow_question']['condValueUnknown'] = 'Note: the comparison value "%s" is not among the options of the field "%s". The STORED value is compared (the "value" column of the option list), not the displayed option text.';
$GLOBALS['TL_LANG']['tl_workflow_question']['condTypeHint']     = 'Note: for the field "%s" (number/date) only "is empty"/"is not empty" is currently reliable. A value comparison may fail on the spelling (e.g. "1.000,00 €" versus "1000").';

$GLOBALS['TL_LANG']['tl_workflow_question']['depTrigger']    = 'Controls: %s';
$GLOBALS['TL_LANG']['tl_workflow_question']['depShowIf']     = 'shown when %s';
$GLOBALS['TL_LANG']['tl_workflow_question']['depHideIf']     = 'hidden when %s';
$GLOBALS['TL_LANG']['tl_workflow_question']['depAnd']        = 'and';
$GLOBALS['TL_LANG']['tl_workflow_question']['depOr']         = 'or';
$GLOBALS['TL_LANG']['tl_workflow_question']['depOrderError'] = 'This field is placed before the field it depends on – the order cannot be saved like this.';
$GLOBALS['TL_LANG']['tl_workflow_question']['condOrderViolation'] = 'The form field "%s" depends on the field with the storage column "%s" and must be placed BELOW it in the list. The changed order was therefore not applied.';
$GLOBALS['TL_LANG']['tl_workflow_question']['depColumn']     = 'Dependency';

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

// Blank option of the "Decimals" select.
$GLOBALS['TL_LANG']['tl_workflow_question']['decimalsAuto'] = 'automatic (from the source file)';
