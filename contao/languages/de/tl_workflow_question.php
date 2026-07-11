<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow_question']['question_legend'] = 'Formularfeld';

$GLOBALS['TL_LANG']['tl_workflow_question']['label']        = ['Überschrift', 'Überschrift des Formularfelds, die im Formular angezeigt wird. Bei „Erklärung“ nur die interne Bezeichnung (wird nicht angezeigt).'];
$GLOBALS['TL_LANG']['tl_workflow_question']['type']         = ['Typ', 'Art des Formularfelds. Je nach Typ werden passende Felder ein-/ausgeblendet.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['storageField'] = ['Speicherfeld', 'Spalte der Quelldatei, in die der gewählte Wert geschrieben wird.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['mandatory']    = ['Pflichtfeld', 'Das Feld muss im Formular ausgefüllt werden.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['description']  = ['Beschreibung', 'Optionaler Hinweistext, der nur im Formular unter der Überschrift angezeigt wird (nur wenn nicht leer). Er erscheint nie im Dokument. Platzhalter, {{Insert-Tags}} und Formatierung ([b]fett[/b], [i]kursiv[/i], [u]unterstrichen[/u]) erlaubt.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['prefill']      = ['Mit Wert aus den Daten vorbelegen', 'Das Feld wird mit dem gespeicherten Wert (aus der Quelldatei bzw. einer früheren Antwort) vorbelegt und bleibt editierbar. Passt der Wert bei Auswahlfeldern zu keiner Option, bleibt das Feld leer.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['readOnly']     = ['Schreibgeschützt', 'Das Feld zeigt den gespeicherten Wert aus den Daten an, kann aber nicht geändert werden (wird beim Absenden weder geprüft noch gespeichert). Pflichtfeld und Vorbelegung sind dann ohne Wirkung.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['showStatementInForm'] = ['Textbaustein im Formular anzeigen', 'Zeigt den Dokument-Text („So erscheint dies im Dokument“) im Formular an. Ausschalten, um ihn der ausfüllenden Person nicht anzuzeigen.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['hideInForm']   = ['Feld im Formular ausblenden', 'Das Feld wird im Formular nicht angezeigt und beim Absenden automatisch mit dem aktuellen Datum gefüllt.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['options']      = ['Optionen', 'Auswahlmöglichkeiten. „Wert“ wird gespeichert, „Options-Text“ wird angezeigt, „Dokument-Text“ erscheint im Dokument (leer = Options-Text gilt wörtlich). Formatierung im Dokument-Text: [b]fett[/b], [i]kursiv[/i], [u]unterstrichen[/u].'];
$GLOBALS['TL_LANG']['tl_workflow_question']['pdfStatement'] = ['Dokument-Text (Textbaustein)', 'Satz, der für dieses Feld im Dokument erscheint; ##answer## steht für den eingegebenen Wert, andere ##Platzhalter## und {{Insert-Tags}} funktionieren wie gewohnt. Leer = „Überschrift: Wert“. Einbindung im Dokument-Text über ##text_<speicherfeld>## bzw. ##text_all##. Auswahlfelder pflegen den Dokument-Text je Option. Bei „Erklärung“ ist dies der angezeigte Textabsatz. Formatierung: [b]fett[/b], [i]kursiv[/i], [u]unterstrichen[/u].'];

$GLOBALS['TL_LANG']['tl_workflow_question']['option_value']     = 'Wert (gespeichert)';
$GLOBALS['TL_LANG']['tl_workflow_question']['option_label']     = 'Options-Text';
$GLOBALS['TL_LANG']['tl_workflow_question']['option_statement'] = 'Dokument-Text (leer = Options-Text)';

$GLOBALS['TL_LANG']['tl_workflow_question']['typeOptions'] = [
    'text'     => 'Freitext (einzeilig)',
    'textarea' => 'Freitext (mehrzeilig)',
    'number'   => 'Zahl',
    'date'     => 'Datum',
    'select'   => 'Dropdown',
    'radio'    => 'Radio-Buttons',
    'checkbox' => 'Checkboxen (Mehrfachauswahl)',
    'currentTime' => 'Aktuelle Zeit (automatisch ausgefüllt)',
    'explanation' => 'Erklärung (Textabsatz, kein Eingabefeld)',
];

$GLOBALS['TL_LANG']['tl_workflow_question']['new']    = ['Neues Formularfeld', 'Formularfeld hinzufügen.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['edit']   = ['Bearbeiten', 'Formularfeld bearbeiten.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['copy']   = ['Kopieren', 'Formularfeld kopieren.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['delete'] = ['Löschen', 'Formularfeld löschen.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['show']   = ['Details', 'Formularfeld anzeigen.'];
