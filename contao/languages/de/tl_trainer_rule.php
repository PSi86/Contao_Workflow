<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_trainer_rule']['rule_legend']   = 'Regel';
$GLOBALS['TL_LANG']['tl_trainer_rule']['result_legend'] = 'Ergebnis';

$GLOBALS['TL_LANG']['tl_trainer_rule']['title']        = ['Bezeichnung', 'Optionaler Name der Regel (nur zur Übersicht).'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['conditions']   = ['Bedingungen', 'Alle Bedingungen müssen zutreffen (UND-Verknüpfung), damit die Regel greift.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['bodyTemplate'] = ['PDF-Vorlage', 'Body-Vorlage, die verwendet wird, wenn die Regel greift.'];

$GLOBALS['TL_LANG']['tl_trainer_rule']['cond_field']    = 'Antwortfeld';
$GLOBALS['TL_LANG']['tl_trainer_rule']['cond_operator'] = 'Operator';
$GLOBALS['TL_LANG']['tl_trainer_rule']['cond_value']    = 'Vergleichswert';

$GLOBALS['TL_LANG']['tl_trainer_rule']['operatorOptions'] = [
    'eq'       => 'ist gleich (=)',
    'neq'      => 'ist ungleich (≠)',
    'lt'       => 'kleiner als (<)',
    'lte'      => 'kleiner/gleich (≤)',
    'gt'       => 'größer als (>)',
    'gte'      => 'größer/gleich (≥)',
    'contains' => 'enthält',
    'empty'    => 'ist leer',
    'notempty' => 'ist nicht leer',
];

$GLOBALS['TL_LANG']['tl_trainer_rule']['untitled'] = 'Regel';

$GLOBALS['TL_LANG']['tl_trainer_rule']['new']    = ['Neue Regel', 'PDF-Regel hinzufügen.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['edit']   = ['Bearbeiten', 'Regel bearbeiten.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['copy']   = ['Kopieren', 'Regel kopieren.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['delete'] = ['Löschen', 'Regel löschen.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['show']   = ['Details', 'Regel anzeigen.'];
