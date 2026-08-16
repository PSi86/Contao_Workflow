<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow_question']['question_legend'] = 'Formularfeld';

$GLOBALS['TL_LANG']['tl_workflow_question']['label']        = ['Überschrift', 'Überschrift des Formularfelds, die im Formular angezeigt wird. Bei „Erklärung“ nur die interne Bezeichnung (wird nicht angezeigt).'];
$GLOBALS['TL_LANG']['tl_workflow_question']['type']         = ['Typ', 'Art des Formularfelds. Je nach Typ werden passende Felder ein-/ausgeblendet.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['storageField'] = ['Speicherfeld', 'Spalte der Quelldatei, in die der gewählte Wert geschrieben wird.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['numberDecimals'] = ['Nachkommastellen', 'Nur beim Typ „Zahl“. Leer = aus der Spalte der Quelldatei übernehmen (deren Zellformat). Ein Wert legt die Nachkommastellen fest und gilt vor dem Format der Quelldatei – sinnvoll, wenn die Spalte in der Quelldatei leer ist oder uneinheitlich formatiert. Tausenderpunkt und Währungszeichen kommen weiterhin aus der Quelldatei.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['mandatory']    = ['Pflichtfeld', 'Das Feld muss im Formular ausgefüllt werden.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['description']  = ['Beschreibung', 'Optionaler Hinweistext, der nur im Formular unter der Überschrift angezeigt wird (nur wenn nicht leer). Er erscheint nie im Dokument. Platzhalter, {{Insert-Tags}} und Formatierung ([b]fett[/b], [i]kursiv[/i], [u]unterstrichen[/u]) erlaubt.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['prefill']      = ['Mit Wert aus den Daten vorbelegen', 'Das Feld wird mit dem gespeicherten Wert (aus der Quelldatei bzw. einer früheren Antwort) vorbelegt und bleibt editierbar. Passt der Wert bei Auswahlfeldern zu keiner Option, bleibt das Feld leer.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['readOnly']     = ['Schreibgeschützt', 'Das Feld zeigt den gespeicherten Wert aus den Daten an, kann aber nicht geändert werden (wird beim Absenden weder geprüft noch gespeichert). Pflichtfeld und Vorbelegung sind dann ohne Wirkung.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['showStatementInForm'] = ['Textbaustein im Formular anzeigen', 'Zeigt den Dokument-Text („So erscheint dies im Dokument“) im Formular an. Ausschalten, um ihn der ausfüllenden Person nicht anzuzeigen.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['hideInForm']   = ['Feld im Formular ausblenden', 'Das Feld wird im Formular nicht angezeigt und beim Absenden automatisch mit dem aktuellen Datum gefüllt.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['options']      = ['Optionen', 'Auswahlmöglichkeiten. „Wert“ wird gespeichert, „Options-Text“ wird angezeigt, „Dokument-Text“ erscheint im Dokument (leer = Options-Text gilt wörtlich). Formatierung im Dokument-Text: [b]fett[/b], [i]kursiv[/i], [u]unterstrichen[/u].'];
$GLOBALS['TL_LANG']['tl_workflow_question']['pdfStatement'] = ['Dokument-Text (Textbaustein)', 'Satz, der für dieses Feld im Dokument erscheint; ##answer## steht für den eingegebenen Wert, andere ##Platzhalter## und {{Insert-Tags}} funktionieren wie gewohnt. Leer = „Überschrift: Wert“. Einbindung im Dokument-Text über ##text_<speicherfeld>## bzw. ##text_all##. Auswahlfelder pflegen den Dokument-Text je Option. Bei „Erklärung“ ist dies der angezeigte Textabsatz. Eine Leerzeile am Ende bleibt erhalten und setzt den Baustein im Dokument vom nächsten ab; im Formular wird sie nicht angezeigt. Formatierung: [b]fett[/b], [i]kursiv[/i], [u]unterstrichen[/u].'];

// Bedingte Formularfelder („Sichtbarkeit“).
$GLOBALS['TL_LANG']['tl_workflow_question']['condition_legend'] = 'Sichtbarkeit';

$GLOBALS['TL_LANG']['tl_workflow_question']['conditionMode']  = ['Anzeigen', 'Steuert, ob dieses Feld im Formular erscheint. „Immer anzeigen“ = wie bisher. Andernfalls wird die Sichtbarkeit während des Ausfüllens laufend neu geprüft. Ein Pflichtfeld muss nur ausgefüllt werden, solange es sichtbar ist; ein ausgeblendetes Feld wird beim Absenden nicht gespeichert und seine Speicherspalte wird geleert.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['conditionLogic'] = ['Verknüpfung', 'Wie die Bedingungen zusammenwirken.'];
$GLOBALS['TL_LANG']['tl_workflow_question']['conditions']     = ['Bedingungen', 'Es können nur Felder geprüft werden, die in der Liste WEITER OBEN stehen und ein Speicherfeld haben. Ein ausgeblendetes Feld zählt für nachfolgende Bedingungen als leer. Bei Checkboxen (Mehrfachauswahl) prüft „enthält“, ob eine bestimmte Option angehakt ist.'];

$GLOBALS['TL_LANG']['tl_workflow_question']['conditionAlways'] = 'immer anzeigen';

$GLOBALS['TL_LANG']['tl_workflow_question']['conditionModeOptions'] = [
    'show' => 'nur anzeigen, wenn …',
    'hide' => 'ausblenden, wenn …',
];

$GLOBALS['TL_LANG']['tl_workflow_question']['conditionLogicOptions'] = [
    'and' => 'alle Bedingungen müssen zutreffen (UND)',
    'or'  => 'eine Bedingung genügt (ODER)',
];

$GLOBALS['TL_LANG']['tl_workflow_question']['cond_field']    = 'Feld';
$GLOBALS['TL_LANG']['tl_workflow_question']['cond_operator'] = 'Operator';
$GLOBALS['TL_LANG']['tl_workflow_question']['cond_value']    = 'Vergleichswert';
$GLOBALS['TL_LANG']['tl_workflow_question']['unknownOption'] = 'Unbekannte Option: %s';

// Auswahlliste des Vergleichswerts (workflow-condition-value.js): Bei Auswahlfeldern werden
// die Optionen des Bedingungsfelds angeboten – „Options-Text (gespeicherter Wert)“. Der
// Schalter daneben wechselt in beide Richtungen zwischen Liste und Freitext.
$GLOBALS['TL_LANG']['tl_workflow_question']['condValueOffList'] = '%s (nicht in der Optionsliste)';
$GLOBALS['TL_LANG']['tl_workflow_question']['condValueFree']    = 'Freitext eingeben';
$GLOBALS['TL_LANG']['tl_workflow_question']['condValueList']    = 'Aus Liste wählen';
$GLOBALS['TL_LANG']['tl_workflow_question']['condValueInert']   = 'Dieser Operator verwendet keinen Vergleichswert.';

// Meldungen der Bedingungsprüfung (save_callback) – Fehler verhindern das Speichern,
// Hinweise nicht.
$GLOBALS['TL_LANG']['tl_workflow_question']['condSelfRef']      = 'Ein Feld kann sich nicht auf sich selbst beziehen (Bedingung „%s“).';
$GLOBALS['TL_LANG']['tl_workflow_question']['condForwardRef']   = 'Die Bedingung verweist auf das Feld „%s“, das in der Liste NICHT weiter oben steht. Bedingungen dürfen sich nur auf vorangehende Felder beziehen – bitte die Reihenfolge der Formularfelder anpassen.';
$GLOBALS['TL_LANG']['tl_workflow_question']['condEmpty']        = 'Bitte mindestens eine vollständige Bedingung angeben (Feld und Operator) oder „immer anzeigen“ wählen.';
$GLOBALS['TL_LANG']['tl_workflow_question']['condValueUnknown'] = 'Hinweis: Der Vergleichswert „%s“ kommt bei den Optionen des Feldes „%s“ nicht vor. Verglichen wird der gespeicherte Wert (Spalte „Wert“ der Optionsliste), nicht der angezeigte Options-Text.';
$GLOBALS['TL_LANG']['tl_workflow_question']['condTypeHint']     = 'Hinweis: Beim Feld „%s“ (Zahl/Datum) ist derzeit nur „ist leer“/„ist nicht leer“ zuverlässig. Ein Wertvergleich kann an der Schreibweise scheitern (z. B. „1.000,00 €“ gegenüber „1000“).';

// Abhängigkeitsanzeige in der Formularfelder-Liste.
$GLOBALS['TL_LANG']['tl_workflow_question']['depTrigger']    = 'Steuert: %s';
$GLOBALS['TL_LANG']['tl_workflow_question']['depShowIf']     = 'anzeigen, wenn %s';
$GLOBALS['TL_LANG']['tl_workflow_question']['depHideIf']     = 'ausblenden, wenn %s';
$GLOBALS['TL_LANG']['tl_workflow_question']['depAnd']        = 'und';
$GLOBALS['TL_LANG']['tl_workflow_question']['depOr']         = 'oder';
$GLOBALS['TL_LANG']['tl_workflow_question']['depOrderError'] = 'Dieses Feld steht vor seinem Auslösefeld – so lässt sich die Reihenfolge nicht speichern.';
$GLOBALS['TL_LANG']['tl_workflow_question']['condOrderViolation'] = 'Das Formularfeld „%s“ hängt vom Feld mit der Speicherspalte „%s“ ab und muss in der Liste UNTER diesem stehen. Die geänderte Reihenfolge wurde deshalb nicht übernommen.';
$GLOBALS['TL_LANG']['tl_workflow_question']['depColumn']     = 'Abhängigkeit';

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

// Leer-Option des Auswahlfelds „Nachkommastellen".
$GLOBALS['TL_LANG']['tl_workflow_question']['decimalsAuto'] = 'automatisch (aus der Quelldatei)';
