<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_workflow']['title_legend']        = 'Titel';
$GLOBALS['TL_LANG']['tl_workflow']['steps_legend']        = 'Schritte / Status';
$GLOBALS['TL_LANG']['tl_workflow']['source_legend']       = 'Quelldaten';
$GLOBALS['TL_LANG']['tl_workflow']['content_legend']      = 'Inhalt (Formular & Dokument)';
$GLOBALS['TL_LANG']['tl_workflow']['form_legend']         = 'Formular & Formularfelder';
$GLOBALS['TL_LANG']['tl_workflow']['pdf_legend']          = 'Dokument-Einstellungen';
$GLOBALS['TL_LANG']['tl_workflow']['notification_legend'] = 'Benachrichtigungen';
$GLOBALS['TL_LANG']['tl_workflow']['reset_legend']        = 'Zurücksetzen';

$GLOBALS['TL_LANG']['tl_workflow']['title']       = ['Titel', 'Name des Workflows.'];
$GLOBALS['TL_LANG']['tl_workflow']['published']   = ['Veröffentlicht', 'Nur veröffentlichte Workflows nehmen Antworten entgegen.'];
$GLOBALS['TL_LANG']['tl_workflow']['steps']       = ['Schritte', 'Geordnete Liste der Schritt-Bezeichnungen (Status 0, 1, 2 …).'];
$GLOBALS['TL_LANG']['tl_workflow']['sourceFile']    = ['Quelldatei', 'CSV- oder XLSX-Datei. Beim Auswählen werden die Spalten erkannt; die Auswahl wird dabei sofort gespeichert.'];
$GLOBALS['TL_LANG']['tl_workflow']['sourceSheet']   = ['Tabellenblatt', 'Welches Blatt der Mappe ausgelesen wird (leer = aktives Blatt). Hinweis: Eine Änderung wird sofort gespeichert.'];
$GLOBALS['TL_LANG']['tl_workflow']['headerRow']     = ['Kopfzeile', 'Zeilennummer mit den Spaltenüberschriften (Standard 1). Hinweis: Eine Änderung wird sofort gespeichert.'];
$GLOBALS['TL_LANG']['tl_workflow']['emailField']    = ['E-Mail-Spalte', 'Spalte mit der E-Mail-Adresse (Empfänger der Einladung).'];
$GLOBALS['TL_LANG']['tl_workflow']['formPage']      = ['Formularseite', 'Seite, auf der das Workflow-Formular-Modul liegt (Ziel der Links).'];
$GLOBALS['TL_LANG']['tl_workflow']['requireSignature'] = ['Unterschrift benötigt', 'Wenn aktiv, muss im Formular unterschrieben werden (Unterschrift wird ins PDF eingebettet).'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfBodyType']     = ['Dokument-Inhalt', 'Einfacher Brief: Die Dokument-Texte werden über die Regeln gepflegt (je nach Antwort). Spezielle Vorlage: eine Datei, die alles selbst regelt – dann gibt es keine Dokument-Texte.'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfBodyTypeOptions'] = ['letter' => 'Einfacher Brief (Dokument-Texte über Regeln)', 'template' => 'Spezielle Vorlage (Datei)'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfTitle']        = ['Überschrift', 'Überschrift, die im Formular und im Dokument angezeigt wird. Platzhalter (##data_vorname##, ##system_year##) und {{Insert-Tags}} erlaubt.'];
$GLOBALS['TL_LANG']['tl_workflow']['introText']       = ['Einleitungstext', 'Optionaler Text, der im Formular und im Dokument nach der Überschrift angezeigt wird. Platzhalter und {{Insert-Tags}} erlaubt. Formatierung: [b]fett[/b], [i]kursiv[/i], [u]unterstrichen[/u].'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfBodyTemplate'] = ['Body-Vorlage', 'Body-Vorlage (Datei „pdf_body_*"). Die Vorlage enthält ihre eigene Logik – Dokument-Texte entfallen.'];
$GLOBALS['TL_LANG']['tl_workflow']['master']        = ['Briefpapier', 'Briefpapier (Kopf/Fuß, Logo, Variablen). Wird unter „Briefpapier" gepflegt.'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfSignatureDate'] = ['Datum für Unterschriftszeile', 'Datenfeld, dessen Wert als Datum in der Unterschriftszeile des PDFs gedruckt wird (z. B. ein „Aktuelle Zeit"-Antwortfeld). Leer = kein Datum.'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfSignatureLocation'] = ['Ort für Unterschriftszeile', 'Datenfeld (z. B. Wohnort der Person aus der Quelldatei), dessen Wert als Ort in der Unterschriftszeile des PDFs gedruckt wird. Leer = kein Ort.'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfFileName'] = ['PDF-Dateiname', 'Muster für den Dateinamen des PDFs, Platzhalter und {{Insert-Tags}} erlaubt, z. B. Verzicht_##data_name##_##data_vorname##. Wird zu einem sicheren Dateinamen bereinigt; bei Namensgleichheit wird automatisch ein kurzer Token angehängt. Leer = Token.'];
$GLOBALS['TL_LANG']['tl_workflow']['formPreview'] = ['Formular-Vorschau', 'Öffnet eine Ansicht des Formulars mit Beispieldaten (Absenden deaktiviert).'];
$GLOBALS['TL_LANG']['tl_workflow']['pdfPreview']  = ['PDF-Vorschau', 'Öffnet das generierte PDF mit Beispieldaten in einem neuen Tab.'];
$GLOBALS['TL_LANG']['tl_workflow']['resetEntries'] = ['Teilnehmer zurücksetzen', 'Verwirft alle erfassten Antworten und gibt die gesperrten Quell-Einstellungen wieder frei.'];
$GLOBALS['TL_LANG']['tl_workflow']['ncInvite']    = ['Einladungs-Benachrichtigung', 'Notification Center: Einladung mit individuellem Link.'];
$GLOBALS['TL_LANG']['tl_workflow']['ncReminder']  = ['Erinnerungs-Benachrichtigung', 'Notification Center: Erinnerung bei ausstehender Antwort.'];
$GLOBALS['TL_LANG']['tl_workflow']['ncResult']    = ['Ergebnis-Benachrichtigung', 'Notification Center: Ergebnis-Mail mit angehängtem PDF.'];

$GLOBALS['TL_LANG']['tl_workflow']['questions'] = ['Formularfelder', 'Felder, die die Person im Formular ausfüllt. „Neu" legt ein Feld an, „Bearbeiten" öffnet es im Dialog. Die Reihenfolge lässt sich direkt in der Liste per Drag & Drop (Griff links) ändern; sie wird beim Speichern des Workflows übernommen.'];
$GLOBALS['TL_LANG']['tl_workflow']['questionOrder'] = ['Reihenfolge der Formularfelder', 'Technisches Feld (ausgeblendet): hält die per Drag & Drop gewählte Reihenfolge für die Versionierung.'];
$GLOBALS['TL_LANG']['tl_workflow']['questionsEmpty'] = 'Noch keine Formularfelder. Mit „Neu" anlegen.';
$GLOBALS['TL_LANG']['tl_workflow']['questionsDrag']  = 'Ziehen, um die Reihenfolge zu ändern';
$GLOBALS['TL_LANG']['tl_workflow']['rules']    = ['Dokument-Texte', 'Jede Regel = Bedingungen + Dokument-Text. Die erste passende Regel liefert den Text; eine Regel OHNE Bedingung wird als „(Standardtext)" geführt und gilt immer (ans Ende stellen, nur eine erlaubt).'];
$GLOBALS['TL_LANG']['tl_workflow']['rulesEmpty'] = 'Noch keine Dokument-Texte. Mit „Neu" anlegen – eine Regel ohne Bedingung ist der „(Standardtext)".';
$GLOBALS['TL_LANG']['tl_workflow']['entries'] = ['Einträge', 'Einträge dieses Workflows verwalten.'];
$GLOBALS['TL_LANG']['tl_workflow']['exportConfig'] = ['Konfiguration herunterladen', 'Workflow-Konfiguration als JSON-Datei herunterladen.'];
$GLOBALS['TL_LANG']['tl_workflow']['edit']    = ['Bearbeiten', 'Workflow bearbeiten.'];
$GLOBALS['TL_LANG']['tl_workflow']['copy']    = ['Kopieren', 'Workflow kopieren.'];
$GLOBALS['TL_LANG']['tl_workflow']['delete']  = ['Löschen', 'Workflow löschen.'];
$GLOBALS['TL_LANG']['tl_workflow']['show']    = ['Details', 'Workflow-Details anzeigen.'];
