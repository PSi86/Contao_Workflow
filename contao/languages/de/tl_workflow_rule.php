<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow_rule']['rule_legend'] = 'Regel';
$GLOBALS['TL_LANG']['tl_workflow_rule']['text_legend'] = 'Dokument-Text';

$GLOBALS['TL_LANG']['tl_workflow_rule']['title']      = ['Bezeichnung', 'Name der Regel, z. B. „Zustimmung" oder „Ablehnung" (nur zur Übersicht).'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['isDefault']  = ['Standardtext', 'Dieser Text gilt immer, wenn keine andere Regel zutrifft (Sonst-Fall). Bei aktivierter Option entfallen die Bedingungen. Es darf nur EINE Standardtext-Regel geben – stellen Sie sie ans Ende. Hinweis: Eine Änderung wird sofort gespeichert.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['conditions'] = ['Bedingungen', 'Alle Bedingungen müssen zutreffen (UND), damit dieser Text genutzt wird.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['pdfBody']    = ['Dokument-Text', 'Text, der ins Dokument kommt, wenn diese Regel greift. Überschrift, Logo, Unterschrift und Footer kommen aus dem Workflow bzw. Briefpapier. Platzhalter: ##data_<feld>## (Spalten inkl. Formularfelder, z. B. ##data_verzicht##), ##letterhead_<variable>## (Briefpapier-Variablen, z. B. ##letterhead_verein##, ##letterhead_ort##), ##system_year## / ##system_today## (aktuelles Jahr/Datum), ##email##. {{Insert-Tags}} sind ebenfalls erlaubt.'];

$GLOBALS['TL_LANG']['tl_workflow_rule']['cond_field']    = 'Formularfeld';
$GLOBALS['TL_LANG']['tl_workflow_rule']['cond_operator'] = 'Operator';
$GLOBALS['TL_LANG']['tl_workflow_rule']['cond_value']    = 'Vergleichswert';
$GLOBALS['TL_LANG']['tl_workflow_rule']['unknownOption'] = 'Unbekannte Option: %s';

$GLOBALS['TL_LANG']['tl_workflow_rule']['operatorOptions'] = [
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

$GLOBALS['TL_LANG']['tl_workflow_rule']['untitled']        = 'Regel';
$GLOBALS['TL_LANG']['tl_workflow_rule']['alwaysLabel']     = 'Standardtext';
$GLOBALS['TL_LANG']['tl_workflow_rule']['condAnd']         = 'und';
$GLOBALS['TL_LANG']['tl_workflow_rule']['defaultRuleError'] = 'Es darf nur eine Regel ohne Bedingung (Standardtext) geben. Bitte fügen Sie eine Bedingung hinzu oder bearbeiten Sie die bestehende Standardtext-Regel.';
$GLOBALS['TL_LANG']['tl_workflow_rule']['defaultMissing']   = 'Hinweis: Es gibt keine Standardtext-Regel (ohne Bedingung). Treffen bei einem Eintrag keine Bedingungen zu, bleibt der Brieftext leer. Legen Sie eine Regel ohne Bedingung als Standardtext an.';

$GLOBALS['TL_LANG']['tl_workflow_rule']['new']    = ['Neuer Dokument-Text', 'Dokument-Text (Regel) hinzufügen.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['edit']   = ['Bearbeiten', 'Regel bearbeiten.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['copy']   = ['Kopieren', 'Regel kopieren.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['delete'] = ['Löschen', 'Regel löschen.'];
$GLOBALS['TL_LANG']['tl_workflow_rule']['show']   = ['Details', 'Regel anzeigen.'];
