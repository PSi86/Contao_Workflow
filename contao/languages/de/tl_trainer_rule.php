<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_trainer_rule']['rule_legend'] = 'Regel';
$GLOBALS['TL_LANG']['tl_trainer_rule']['text_legend'] = 'Brieftext';

$GLOBALS['TL_LANG']['tl_trainer_rule']['title']      = ['Bezeichnung', 'Name der Regel, z. B. „Zustimmung" oder „Ablehnung" (nur zur Übersicht).'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['isDefault']  = ['Standardtext', 'Dieser Text gilt immer, wenn keine andere Regel zutrifft (Sonst-Fall). Bei aktivierter Option entfallen die Bedingungen. Es darf nur EINE Standardtext-Regel geben – stellen Sie sie ans Ende.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['conditions'] = ['Bedingungen', 'Alle Bedingungen müssen zutreffen (UND), damit dieser Text genutzt wird.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['pdfBody']    = ['Brieftext', 'Text, der ins PDF kommt, wenn diese Regel greift. Überschrift, Logo, Unterschrift und Footer kommen aus dem Workflow bzw. Briefkopf. Platzhalter: ##Spaltenname## (inkl. Antwortfelder), ##datum##, ##email## sowie PDF-Variablen (##Jahr##, ##Verein## …).'];

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

$GLOBALS['TL_LANG']['tl_trainer_rule']['untitled']        = 'Regel';
$GLOBALS['TL_LANG']['tl_trainer_rule']['alwaysLabel']     = 'Standardtext';
$GLOBALS['TL_LANG']['tl_trainer_rule']['condAnd']         = 'und';
$GLOBALS['TL_LANG']['tl_trainer_rule']['defaultRuleError'] = 'Es darf nur eine Regel ohne Bedingung (Standardtext) geben. Bitte fügen Sie eine Bedingung hinzu oder bearbeiten Sie die bestehende Standardtext-Regel.';
$GLOBALS['TL_LANG']['tl_trainer_rule']['defaultMissing']   = 'Hinweis: Es gibt keine Standardtext-Regel (ohne Bedingung). Treffen bei einem Eintrag keine Bedingungen zu, bleibt der Brieftext leer. Legen Sie eine Regel ohne Bedingung als Standardtext an.';

$GLOBALS['TL_LANG']['tl_trainer_rule']['new']    = ['Neue Regel', 'PDF-Regel hinzufügen.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['edit']   = ['Bearbeiten', 'Regel bearbeiten.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['copy']   = ['Kopieren', 'Regel kopieren.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['delete'] = ['Löschen', 'Regel löschen.'];
$GLOBALS['TL_LANG']['tl_trainer_rule']['show']   = ['Details', 'Regel anzeigen.'];
