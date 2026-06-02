<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_trainer_question']['question_legend'] = 'Answer field';

$GLOBALS['TL_LANG']['tl_trainer_question']['label']        = ['Label', 'Question/field label shown in the form.'];
$GLOBALS['TL_LANG']['tl_trainer_question']['type']         = ['Type', 'Type of the answer field.'];
$GLOBALS['TL_LANG']['tl_trainer_question']['storageField'] = ['Storage column', 'Source column the selected value is written into (mandatory).'];
$GLOBALS['TL_LANG']['tl_trainer_question']['mandatory']    = ['Mandatory', 'The field must be filled in the form.'];
$GLOBALS['TL_LANG']['tl_trainer_question']['options']      = ['Options', 'Available choices. "Value" is stored, "Option text" is displayed.'];

$GLOBALS['TL_LANG']['tl_trainer_question']['option_value'] = 'Value (stored)';
$GLOBALS['TL_LANG']['tl_trainer_question']['option_label'] = 'Option text';

$GLOBALS['TL_LANG']['tl_trainer_question']['typeOptions'] = [
    'text'     => 'Free text (single line)',
    'textarea' => 'Free text (multi line)',
    'select'   => 'Dropdown',
    'radio'    => 'Radio buttons',
    'checkbox' => 'Checkboxes (multi-select)',
    'date'     => 'Date',
];

$GLOBALS['TL_LANG']['tl_trainer_question']['new']    = ['New answer field', 'Add an answer field.'];
$GLOBALS['TL_LANG']['tl_trainer_question']['edit']   = ['Edit', 'Edit answer field.'];
$GLOBALS['TL_LANG']['tl_trainer_question']['copy']   = ['Copy', 'Copy answer field.'];
$GLOBALS['TL_LANG']['tl_trainer_question']['delete'] = ['Delete', 'Delete answer field.'];
$GLOBALS['TL_LANG']['tl_trainer_question']['show']   = ['Details', 'Show answer field.'];
