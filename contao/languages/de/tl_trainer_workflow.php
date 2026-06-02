<?php

declare(strict_types=1);

$GLOBALS['TL_LANG']['tl_trainer_workflow']['title_legend']        = 'Titel';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['steps_legend']        = 'Schritte / Status';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['source_legend']       = 'Quelldaten';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['form_legend']         = 'Formular';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdf_legend']          = 'PDF';
$GLOBALS['TL_LANG']['tl_trainer_workflow']['notification_legend'] = 'Benachrichtigungen';

$GLOBALS['TL_LANG']['tl_trainer_workflow']['title']       = ['Titel', 'Name des Workflows.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['published']   = ['Veröffentlicht', 'Nur veröffentlichte Workflows nehmen Antworten entgegen.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['steps']       = ['Schritte', 'Geordnete Liste der Schritt-Bezeichnungen (Status 0, 1, 2 …).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['sourceFile']    = ['Quelldatei', 'CSV- oder XLSX-Datei. Nach dem Auswählen speichern, damit die Spalten erkannt werden.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['sourceSheet']   = ['Tabellenblatt', 'Welches Blatt der Mappe ausgelesen wird (leer = aktives Blatt).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['headerRow']     = ['Kopfzeile', 'Zeilennummer mit den Spaltenüberschriften (Standard 1).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['emailField']    = ['E-Mail-Spalte', 'Spalte mit der E-Mail-Adresse (Empfänger der Einladung).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['inputFields']   = ['Anzeige-Felder (Input)', 'Spalten, die im Formular vorausgefüllt und schreibgeschützt angezeigt werden.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['formPage']      = ['Formularseite', 'Seite, auf der das Trainer-Formular-Modul liegt (Ziel der Links).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['requireSignature'] = ['Unterschrift verlangen', 'Wenn aktiv, muss im Formular unterschrieben werden (Unterschrift wird ins PDF eingebettet).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBodyType']     = ['PDF-Inhalt', 'Einfacher Brief (im Backend editierbar) oder eine spezielle Body-Vorlage (Datei).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBodyTypeOptions'] = ['letter' => 'Einfacher Brief (Text im Backend)', 'template' => 'Spezielle Vorlage (Datei)'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfTitle']        = ['Überschrift', 'Titel des Briefs. Platzhalter erlaubt, z. B. ##Vorname## ##Name##, ##Jahr##.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBody']         = ['Brieftext', 'Haupttext (Standard, wenn keine Regel greift). Platzhalter: ##Spaltenname## (inkl. Antwortfelder), ##datum##, ##email## sowie PDF-Variablen (##Jahr##, ##Verein## …).'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['pdfBodyTemplate'] = ['Body-Vorlage', 'Standard-Body-Vorlage (Template „pdf_body_*"), wenn keine PDF-Regel greift. Die passenden PDF-Variablen werden danach automatisch vorgeschlagen.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['master']        = ['Briefkopf-Vorlage', 'Briefkopf/Master (Vorlage, Logo, Variablen). Wird unter „Briefkopf-Vorlagen" gepflegt.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['ncInvite']    = ['Einladungs-Benachrichtigung', 'Notification Center: Einladung mit individuellem Link.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['ncReminder']  = ['Erinnerungs-Benachrichtigung', 'Notification Center: Erinnerung bei ausstehender Antwort.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['ncResult']    = ['Ergebnis-Benachrichtigung', 'Notification Center: Ergebnis-Mail mit angehängtem PDF.'];

$GLOBALS['TL_LANG']['tl_trainer_workflow']['questions'] = ['Antwortfelder', 'Antwortfelder dieses Workflows konfigurieren.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['rules']    = ['PDF-Regeln', 'PDF-Regeln (Vorlagenauswahl nach Antworten) dieses Workflows verwalten.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['entries'] = ['Einträge', 'Einträge dieses Workflows verwalten.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['edit']    = ['Bearbeiten', 'Workflow bearbeiten.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['copy']    = ['Kopieren', 'Workflow kopieren.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['delete']  = ['Löschen', 'Workflow löschen.'];
$GLOBALS['TL_LANG']['tl_trainer_workflow']['show']    = ['Details', 'Workflow-Details anzeigen.'];
