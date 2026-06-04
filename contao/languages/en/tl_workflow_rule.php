<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow_rule']['rule_legend'] = 'Rule';
$GLOBALS['TL_LANG']['tl_workflow_rule']['text_legend'] = 'Letter text';

$GLOBALS['TL_LANG']['tl_workflow_rule']['title']      = ['Name', 'Rule name, e.g. "Accept" or "Reject" (for overview only).'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['isDefault']  = ['Default text', 'This text always applies when no other rule matches (else case). Enabling it hides the conditions. There may be only ONE default-text rule – place it last.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['conditions'] = ['Conditions', 'All conditions must match (AND) for this text to be used.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['pdfBody']    = ['Body text', 'Text used in the PDF when this rule applies. Heading, logo, signature and footer come from the workflow/stationery. Placeholders: ##column## (incl. answer fields), ##datum##, ##email## and PDF variables (##Jahr##, ##Verein## …).'];

$GLOBALS['TL_LANG']['tl_workflow_rule']['cond_field']    = 'Answer field';
$GLOBALS['TL_LANG']['tl_workflow_rule']['cond_operator'] = 'Operator';
$GLOBALS['TL_LANG']['tl_workflow_rule']['cond_value']    = 'Comparison value';
$GLOBALS['TL_LANG']['tl_workflow_rule']['unknownOption'] = 'Unknown option: %s';

$GLOBALS['TL_LANG']['tl_workflow_rule']['operatorOptions'] = [
    'eq'       => 'equals (=)',
    'neq'      => 'not equal (≠)',
    'lt'       => 'less than (<)',
    'lte'      => 'less or equal (≤)',
    'gt'       => 'greater than (>)',
    'gte'      => 'greater or equal (≥)',
    'contains' => 'contains',
    'empty'    => 'is empty',
    'notempty' => 'is not empty',
];

$GLOBALS['TL_LANG']['tl_workflow_rule']['untitled']        = 'Rule';
$GLOBALS['TL_LANG']['tl_workflow_rule']['alwaysLabel']     = 'default text';
$GLOBALS['TL_LANG']['tl_workflow_rule']['condAnd']         = 'and';
$GLOBALS['TL_LANG']['tl_workflow_rule']['defaultRuleError'] = 'There may be only one rule without conditions (default text). Please add a condition or edit the existing default-text rule.';
$GLOBALS['TL_LANG']['tl_workflow_rule']['defaultMissing']   = 'Note: there is no default-text rule (without conditions). If no condition matches an entry, the body text stays empty. Add a rule without conditions as the default text.';

$GLOBALS['TL_LANG']['tl_workflow_rule']['new']    = ['New rule', 'Add a PDF rule.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['edit']   = ['Edit', 'Edit rule.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['copy']   = ['Copy', 'Copy rule.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['delete'] = ['Delete', 'Delete rule.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['show']   = ['Details', 'Show rule.'];
