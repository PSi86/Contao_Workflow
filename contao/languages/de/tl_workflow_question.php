<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow_question']['question_legend'] = 'Antwortfeld';

$GLOBALS['TL_LANG']['tl_workflow_question']['label']        = ['Beschriftung', 'Frage-/Feldbeschriftung, die im Formular angezeigt wird.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['type']         = ['Typ', 'Art des Antwortfelds. Hinweis: Eine Änderung wird sofort gespeichert.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['storageField'] = ['Speicherfeld', 'Spalte der Quelldatei, in die der gewählte Wert geschrieben wird (Pflicht).'];
$GLOBALS['TL_LANG']['tl_workflow_question']['mandatory']    = ['Pflichtfeld', 'Das Feld muss im Formular ausgefüllt werden.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['hideInForm']   = ['Feld im Formular ausblenden', 'Das Feld wird im Formular nicht angezeigt und beim Absenden automatisch mit dem aktuellen Datum gefüllt.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['options']      = ['Optionen', 'Auswahlmöglichkeiten. „Wert“ wird gespeichert, „Options-Text“ wird angezeigt.'];

$GLOBALS['TL_LANG']['tl_workflow_question']['option_value'] = 'Wert (gespeichert)';
$GLOBALS['TL_LANG']['tl_workflow_question']['option_label'] = 'Options-Text';

$GLOBALS['TL_LANG']['tl_workflow_question']['typeOptions'] = [
    'text'     => 'Freitext (einzeilig)',
    'textarea' => 'Freitext (mehrzeilig)',
    'select'   => 'Dropdown',
    'radio'    => 'Radio-Buttons',
    'checkbox' => 'Checkboxen (Mehrfachauswahl)',
    'date'     => 'Datum',
    'currentTime' => 'Aktuelle Zeit (automatisch ausgefüllt)',
];

$GLOBALS['TL_LANG']['tl_workflow_question']['new']    = ['Neues Antwortfeld', 'Antwortfeld hinzufügen.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['edit']   = ['Bearbeiten', 'Antwortfeld bearbeiten.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['copy']   = ['Kopieren', 'Antwortfeld kopieren.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['delete'] = ['Löschen', 'Antwortfeld löschen.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['show']   = ['Details', 'Antwortfeld anzeigen.'];
