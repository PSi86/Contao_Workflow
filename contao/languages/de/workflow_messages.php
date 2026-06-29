<?php

declare(strict_types=1);

// Backend overview module (be_workflow_dashboard) + workflow-backend.js.
$GLOBALS['TL_LANG']['workflow_dashboard'] = [
    'heading'          => 'Workflow – Übersicht',
    'no_workflows'     => 'Es ist noch kein Workflow angelegt. Bitte zuerst unter „Workflows“ einen Workflow erstellen.',
    'unpublished'      => '(nicht veröffentlicht)',
    'not_runnable'     => '⚠ nicht ausführbar',
    'not_runnable_msg' => 'Dieser Workflow kann nicht ausgeführt werden:',
    'no_import'        => '⚠ Der Workflow ist konfiguriert, aber es wurde noch <strong>kein Import ausgeführt</strong> – es liegen noch keine Antworten vor. Bitte zuerst „Import ausführen“.',
    'completed'        => 'eingegangen',
    'open'             => 'offen',
    'total'            => 'gesamt',
    'col_step'         => 'Schritt',
    'col_status'       => 'Status',
    'col_count'        => 'Anzahl',
    'btn_edit'         => 'Bearbeiten',
    'btn_import'       => 'Import ausführen',
    'btn_send'         => 'E-Mails senden',
    'btn_export_xlsx'  => 'Export (XLSX)',
    'btn_export_csv'   => 'Export (CSV)',
    'btn_pdfs'         => 'PDFs herunterladen',
    'pending'          => 'Ausstehende Antworten',
    'selection'        => 'Auswahl:',
    'sel_all'          => 'Alle',
    'sel_none'         => 'Alle aufheben',
    'col_email'        => 'E-Mail',
    'col_name'         => 'Name',
    'col_vorname'      => 'Vorname',
    'close'            => 'Schließen',
    'mode_auto'        => 'Automatisch (Adressaten nach Status)',
    'mode_manual'      => 'Manuelle Auswahl (markierte Teilnehmer)',
    'send_invites'     => 'Einladungen senden',
    'send_reminders'   => 'Erinnerungen senden',
    'send_now'         => 'Jetzt senden',
    'back'             => 'Zurück',
    'no_pending'       => 'Keine ausstehenden Antworten.',
    // Used by workflow-backend.js (via data-* attributes).
    'hint_manual'      => 'Es werden nur die markierten Teilnehmer mit passendem Status berücksichtigt.',
    'hint_auto'        => 'Die Adressaten werden automatisch nach Status gewählt.',
    'no_recipients'    => 'Es gibt keine passenden Empfänger für diese Aktion.',
    'confirm_invite'   => 'Folgende %count% Empfänger erhalten die Einladung:',
    'confirm_reminder' => 'Folgende %count% Empfänger erhalten die Erinnerung:',
];

// WorkflowValidator::getProblems() – shown on the overview (and the edit mask).
$GLOBALS['TL_LANG']['workflow_validator'] = [
    'no_source'          => 'Es ist keine Quelldatei ausgewählt – der Workflow kann erst nach dem Laden einer Quelldatei ausgeführt werden.',
    'source_unreadable'  => 'Die Quelldatei ist nicht lesbar oder enthält keine Spalten.',
    'no_email_col'       => 'Es ist keine E-Mail-Spalte gewählt.',
    'email_col_missing'  => 'Die E-Mail-Spalte „%s“ fehlt in der Quelldatei.',
    'storage_missing'    => 'Das Speicherfeld „%s“ (Antwortfeld „%s“) fehlt in der Quelldatei.',
    'rule_unknown_field' => 'Die PDF-Regel „%s“ verwendet das unbekannte Feld „%s“.',
];
