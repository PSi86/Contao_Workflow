<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_trainer_rule']['rule_legend']   = 'Rule';
$GLOBALS['TL_LANG']['tl_trainer_rule']['result_legend'] = 'Result';

$GLOBALS['TL_LANG']['tl_trainer_rule']['title']        = ['Name', 'Optional rule name (for overview only).'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['conditions']   = ['Conditions', 'All conditions must match (AND-combined) for the rule to apply.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['bodyTemplate'] = ['PDF template', 'Body template used when the rule applies.'];

$GLOBALS['TL_LANG']['tl_trainer_rule']['cond_field']    = 'Answer field';
$GLOBALS['TL_LANG']['tl_trainer_rule']['cond_operator'] = 'Operator';
$GLOBALS['TL_LANG']['tl_trainer_rule']['cond_value']    = 'Comparison value';

$GLOBALS['TL_LANG']['tl_trainer_rule']['operatorOptions'] = [
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

$GLOBALS['TL_LANG']['tl_trainer_rule']['untitled'] = 'Rule';

$GLOBALS['TL_LANG']['tl_trainer_rule']['new']    = ['New rule', 'Add a PDF rule.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['edit']   = ['Edit', 'Edit rule.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['copy']   = ['Copy', 'Copy rule.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['delete'] = ['Delete', 'Delete rule.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['show']   = ['Details', 'Show rule.'];
