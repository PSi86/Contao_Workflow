<?php

declare(strict_types=1);

// Backend overview module (be_workflow_dashboard) + workflow-backend.js.
$GLOBALS['TL_LANG']['workflow_dashboard'] = [
    'heading'          => 'Workflow – Übersicht',
    'no_workflows'     => 'Es ist noch kein Workflow angelegt. Bitte zuerst unter „Workflows“ einen Workflow erstellen.',
    'unpublished'      => '(nicht veröffentlicht)',
    'not_runnable'     => '⚠ nicht ausführbar',
    'not_runnable_msg' => 'Dieser Workflow kann nicht ausgeführt werden:',
    'stuck_queue'      => '⚠ %d E-Mail(s) sind seit über 15 Minuten zum Versand eingereiht, aber noch ohne Ergebnis. Läuft der Cron bzw. der Worker? Siehe DEPLOYMENT.md, Abschnitt 2 (Worker/Cron in Produktion einrichten).',
    // Bounce-Erkennung (Service\Bounce\BounceHealth): Hinweis-Banner, wenn kein Postfach
    // konfiguriert ist; Fehler-Banner (%1$s = Grund, %2$s = Zeitpunkt der letzten Prüfung),
    // wenn das konfigurierte Postfach nicht erreichbar ist.
    'bounce_unconfigured' => 'ℹ Es ist kein Bounce-Postfach eingerichtet. Ein Administrator kann es unten unter „Bounce-Postfach“ einrichten (oder per WORKFLOW_BOUNCE_IMAP_DSN in der .env.local – nach einer Änderung dort den Produktions-Cache neu erzeugen). In diesem Zustand können Zustellfehler und Bounces nicht erkannt werden. Siehe DEPLOYMENT.md, Abschnitt 3c.',
    'bounce_error'        => '⚠ Das eingerichtete Bounce-Postfach ist nicht erreichbar: %1$s Zustellfehler und Bounces werden derzeit nicht erkannt. Bitte die Zugangsdaten prüfen (unten unter „Bounce-Postfach“, bzw. WORKFLOW_BOUNCE_IMAP_DSN: Host, Port, Benutzer, Passwort-Formatierung). (Zuletzt geprüft: %2$s)',
    // Dialog „Bounce-Postfach“ (nur Administratoren). mailbox_env: %1$s = Server, %2$s = Port,
    // %3$s = Benutzer, %4$s = Bounce-Adresse.
    'mailbox_title'       => 'Bounce-Postfach',
    'mailbox_intro'       => 'In dieses Postfach gehen die Unzustellbarkeitsmeldungen (Bounces) aller Workflow-Mails: Seine Adresse wird bei jedem Versand als Bounce-Adresse (Envelope-Sender) gesetzt. Absender und „Antwort an“ der Benachrichtigungen bleiben, wie sie sind – Antworten von Menschen landen also weiterhin dort, nicht hier. Das Bundle ruft das Postfach selbst ab.',
    'mailbox_env'         => 'Festgelegt durch WORKFLOW_BOUNCE_IMAP_DSN in der .env.local: Server %1$s:%2$d, Benutzer %3$s, Bounce-Adresse %4$s. Diese Einstellung hat Vorrang; Änderungen hier sind erst möglich, wenn die Variable entfernt ist.',
    'mailbox_no_address'  => 'keine (der Benutzer ist keine E-Mail-Adresse – WORKFLOW_BOUNCE_ADDRESS setzen)',
    'mailbox_address'     => 'Adresse des Bounce-Postfachs',
    'mailbox_host'        => 'IMAP-Server',
    'mailbox_port'        => 'Port',
    'mailbox_encryption'  => 'Verschlüsselung',
    'mailbox_enc_ssl'     => 'SSL/TLS (üblich: 993)',
    'mailbox_enc_tls'     => 'STARTTLS (üblich: 143)',
    'mailbox_enc_none'    => 'keine (nicht empfohlen)',
    'mailbox_user'        => 'Benutzer',
    'mailbox_user_placeholder' => 'leer = die Adresse',
    'mailbox_password'    => 'Passwort',
    'mailbox_password_kept' => 'gespeichert – leer lassen, um es zu behalten',
    'mailbox_save_check'  => 'Speichern und Postfach prüfen',
    'mailbox_save'        => 'Nur speichern',
    'mailbox_clear'       => 'Entfernen',
    'mailbox_clear_confirm' => 'Bounce-Postfach entfernen? Danach werden Bounces nicht mehr erkannt, bis wieder eines eingerichtet ist.',
    'mailbox_hints'       => 'Hinweise: Die Adresse nur für Bounces verwenden – nie als Absender oder „Antwort an“ einer Benachrichtigung. Den Spamfilter dieses Postfachs ausschalten. Jede Installation (z. B. Test und Produktion) braucht ein eigenes Postfach. Das Passwort wird verschlüsselt in der Datenbank gespeichert; der Schlüssel stammt aus APP_SECRET.',
    'mailbox_saved'       => 'Bounce-Postfach gespeichert.',
    'mailbox_cleared'     => 'Bounce-Postfach entfernt.',
    'mailbox_env_locked'  => 'Das Bounce-Postfach ist per WORKFLOW_BOUNCE_IMAP_DSN in der .env.local festgelegt und kann hier nicht geändert werden.',
    'mailbox_password_unreadable' => '⚠ Das gespeicherte Passwort des Bounce-Postfachs lässt sich nicht mehr entschlüsseln (wurde APP_SECRET geändert?). Bis es unter „Bounce-Postfach“ neu eingegeben ist, werden Bounces nicht erkannt.',
    'mailbox_password_reenter' => 'Das gespeicherte Passwort lässt sich nicht mehr entschlüsseln – bitte neu eingeben.',
    'mailbox_err_address' => 'Bitte eine gültige Adresse des Bounce-Postfachs angeben.',
    'mailbox_err_host'    => 'Bitte den IMAP-Server angeben (z. B. w0123456.kasserver.com).',
    'mailbox_err_port'    => 'Der Port muss eine Zahl zwischen 1 und 65535 sein.',
    'mailbox_err_encryption' => 'Unbekannte Verschlüsselung.',
    'mailbox_err_user'    => 'Bitte den Benutzer angeben.',
    'mailbox_err_password'=> 'Bitte das Passwort angeben.',
    'mailbox_err_sodium'  => 'Die PHP-Erweiterung sodium fehlt; das Passwort kann nicht verschlüsselt gespeichert werden. Bitte stattdessen WORKFLOW_BOUNCE_IMAP_DSN in der .env.local verwenden.',
    // Statuszeile Cronjob / Bounce-Abruf (DashboardModule::cronStatus). Cron: %1$s = Zeitpunkt,
    // %2$s = „vor …“, %3$s = Auslöser (/_contao/cron oder contao:cron).
    'cron_last'        => 'Cronjob: zuletzt aufgerufen am %1$s (%2$s, über %3$s).',
    'cron_stale'       => '⚠ Cronjob: letzter Aufruf am %1$s (%2$s, über %3$s) – seitdem keiner mehr. Mail-Warteschlange, Bounce-Abruf und Nachversand laufen dann nur noch bei Seitenaufrufen – und erst nach deren Auslieferung. Siehe DEPLOYMENT.md, Abschnitt 2a.',
    'cron_never'       => '⚠ Cronjob: es wurde noch kein Aufruf über /_contao/cron oder contao:cron erkannt. Mail-Warteschlange, Bounce-Abruf und Nachversand laufen dann nur bei Seitenaufrufen – und erst nach deren Auslieferung. Siehe DEPLOYMENT.md, Abschnitt 2a.',
    // Bounce: %1$s = letzte Prüfung, %2$s = nächste Prüfung, %3$s = Begründung des Takts.
    'bounce_status'    => 'Bounce-Postfach: zuletzt geprüft %1$s, nächste Prüfung ab %2$s – %3$s.',
    'bounce_never'     => 'noch nie',
    'bounce_next_due'  => 'dem nächsten Cron-Aufruf',
    'bounce_tier_recent'      => 'alle 2 Minuten, weil %s Mails versendet wurden',
    'bounce_tier_outstanding' => 'alle 15 Minuten, solange %d Einladung(en) unbeantwortet sind',
    'bounce_tier_idle'        => 'alle 2 Stunden, da keine Antworten ausstehen',
    'bounce_without_cron'     => 'Ohne Cronjob geschieht das erst beim nächsten Seitenaufruf.',
    'bounce_check_now'  => 'Jetzt prüfen',
    'bounce_check_busy' => 'Das Bounce-Postfach wird gerade bereits geprüft – bitte in einer Minute erneut versuchen.',
    'bounce_check_error' => 'Bounce-Postfach nicht erreichbar: %s Bitte die Zugangsdaten prüfen (unten unter „Bounce-Postfach“, bzw. WORKFLOW_BOUNCE_IMAP_DSN).',
    'bounce_check_done' => 'Bounce-Postfach geprüft: %1$d neue Nachricht(en) – %2$d Adresse(n) als unzustellbar gesperrt, %3$d vorübergehende Meldung(en), %4$d ohne passenden Eintrag, %5$d ohne Unzustellbarkeitsmeldung.',
    // Statuszeile und „Jetzt prüfen“: %d = Nachrichten im Posteingang des Bounce-Postfachs, die
    // keine Bounces von Workflow-Mails sind (der Bounce-Abruf lässt sie liegen).
    // Ende-zu-Ende-Test der Bounce-Erkennung (Service\Bounce\BounceProbe). Statuszeilen:
    // %1$s = Start, %2$s = Workflow; queued: %3$s = Testadresse; waiting: %3$s = versendet um,
    // %4$s = Bounce-Adresse, %5$s = Wartezeit bis; ok: %3$s = Dauer, %4$s = Bounce-Adresse;
    // failed: %3$s = Fehler; timeout: %3$s = Bounce-Adresse.
    'bounce_tier_test'        => 'jede Minute, solange der Ende-zu-Ende-Test auf seinen Bounce wartet',
    'bounce_test_title'       => 'Ende-zu-Ende-Test der Bounce-Erkennung',
    'bounce_test_label'       => 'Bounce-Erkennung testen über die Einladung von',
    'bounce_test_start'       => 'Ende-zu-Ende-Test starten',
    'bounce_test_hint'        => 'Schickt die Einladung des gewählten Workflows über dessen echten Versandweg an eine Adresse, die es nicht gibt (…@bounce-test.invalid), und wartet bis zu 30 Minuten darauf, dass der Bounce im Bounce-Postfach ankommt. Kein Eintrag wird verändert. Empfänger in CC/BCC der Einladung erhalten die Testmail ebenfalls.',
    'bounce_test_started'     => 'Testmail an %s übergeben. Das Bounce-Postfach wird jetzt jede Minute geprüft; das Ergebnis erscheint in der Statuszeile.',
    'bounce_test_unconfigured'=> 'Ohne Bounce-Postfach kann der Test keinen Bounce finden. Bitte zuerst das Bounce-Postfach einrichten (unten unter „Bounce-Postfach“ oder per WORKFLOW_BOUNCE_IMAP_DSN, DEPLOYMENT.md, Abschnitt 3c).',
    'bounce_test_no_invite'   => 'Der Workflow „%s“ hat keine Einladungs-Benachrichtigung – darüber lässt sich nichts testen.',
    'bounce_test_no_workflow' => 'Workflow nicht gefunden.',
    'bounce_test_not_handed_over' => 'Die Testmail an %s konnte nicht an den Mailer übergeben werden. Details in der Statuszeile und im System-Log.',
    'bounce_test_no_envelope' => 'keine – der Bounce geht an den Absender',
    'bounce_test_queued'      => 'Ende-zu-Ende-Test vom %1$s (Workflow „%2$s“): Testmail an %3$s eingereiht, noch nicht versendet – das geschieht beim nächsten Cron-Aufruf.',
    'bounce_test_waiting'     => 'Ende-zu-Ende-Test vom %1$s (Workflow „%2$s“): Testmail um %3$s versendet, Bounce-Adresse %4$s. Warte auf den Bounce; das Postfach wird bis %5$s jede Minute geprüft.',
    'bounce_test_ok'          => '✔ Ende-zu-Ende-Test vom %1$s (Workflow „%2$s“): Der Bounce kam nach %3$s im Bounce-Postfach an und wurde zugeordnet. Bounce-Adresse: %4$s.',
    'bounce_test_failed'      => '⚠ Ende-zu-Ende-Test vom %1$s (Workflow „%2$s“): Versand fehlgeschlagen – %3$s',
    'bounce_test_timeout'     => '⚠ Ende-zu-Ende-Test vom %1$s (Workflow „%2$s“): Nach 30 Minuten ist kein Bounce im Bounce-Postfach angekommen. Bounce-Adresse: %3$s. Mögliche Ursachen: Die Bounce-Adresse ist nicht das abgerufene Postfach, oder ein Spamfilter sortiert Bounces aus (DEPLOYMENT.md, Abschnitt 3c).',
    'bounce_foreign'    => 'Im Posteingang des Bounce-Postfachs liegen %d Nachricht(en), die keine Bounces von Workflow-Mails sind (z. B. Antworten, Abwesenheitsnotizen oder Bounces anderer Absender). Sie bleiben dort liegen. Zeigt ein Absender oder eine „Antwort an“ auf dieses Postfach? Bitte im Webmail ansehen und danach löschen.',
    'ago_now'          => 'vor weniger als einer Minute',
    'ago_minutes'      => 'vor %d Min.',
    'ago_hours'        => 'vor %d Std.',
    'ago_days'         => 'vor %d Tagen',
    'hard_bounces'     =>'Ungültige Adressen (%d) – dauerhaft unzustellbar (Bounce)',
    'col_reason'       => 'Grund',
    'hard_bounces_hint'=> 'Diese Adressen existieren nicht (harte Bounces) und werden von Einladungen und Erinnerungen ausgeschlossen. Korrigiere die E-Mail-Adresse des Eintrags, um ihn wieder aufzunehmen.',
    'no_import'        => '⚠ Der Workflow ist konfiguriert, aber es wurde noch <strong>kein Import ausgeführt</strong> – es liegen noch keine Antworten vor. Bitte zuerst „Import ausführen“.',
    'reimport_needed'  => '⚠ Die Quelldatei wurde geändert, aber noch nicht importiert. Vorschau-Formular und PDF zeigen bis dahin die alten Daten und Zahlenformate – bitte „Import ausführen“, um die aktuellen Daten und Feldformatierungen zu laden.',
    'completed'        => 'eingegangen',
    'open'             => 'offen',
    'total'            => 'gesamt',
    'col_step'         => 'Schritt',
    'col_status'       => 'Status',
    'col_count'        => 'Anzahl',
    'btn_edit'         => 'Bearbeiten',
    'btn_import'       => 'Import ausführen',
    'btn_send'         => 'E-Mails senden',
    'btn_log'          => 'Importprotokoll',
    'log_hint'         => 'Was jeder Lauf getan hat – Zeitpunkt, Modus, Quelldatei samt Prüfsumme und die Meldungen von damals. Das Ergebnis eines Imports hängt von den vorherigen Läufen ab; hier steht, wie der aktuelle Zustand entstanden ist.',
    'btn_download'     => 'Datendownload',
    'btn_export_xlsx'  => 'Excel (XLSX)',
    'btn_export_csv'   => 'CSV',
    'btn_pdfs'         => 'PDFs',
    'download_data_hint' => 'Alle Teilnehmer mit den aktuellen Daten, in den Spalten und der Reihenfolge der Quelldatei.',
    'download_csv_hint'  => 'Dieselben Daten als reine Textdatei, z. B. zum Einlesen in andere Programme.',
    'download_pdfs_hint' => 'Alle bisher für diesen Workflow erzeugten Dokumente.',
    'download_run'       => 'Herunterladen',
    // Ergebnis der Auswahl (auch in workflow-backend.js über data-* verwendet).
    'download_result_xlsx' => 'Ergebnis: eine Excel-Datei (.xlsx).',
    'download_result_csv'  => 'Ergebnis: eine CSV-Datei (.csv).',
    'download_result_zip'  => 'Ergebnis: ein ZIP-Archiv (.zip) mit der Auswahl.',
    'download_result_none' => 'Bitte mindestens einen Eintrag auswählen.',
    // Der Import-Dialog ist stillgelegt (siehe be_workflow_dashboard.html5) – „Import
    // ausführen“ startet direkt den additiven Lauf. Seine vier Texte bleiben für eine
    // Reaktivierung stehen. „import_add“ und „import_absolute“ sind NICHT ungenutzt: das
    // Importprotokoll beschriftet damit auch alte Läufe im absoluten Modus.
    'import_intro'     => '<strong>Ausgeblendete Zeilen werden in beiden Fällen übersprungen</strong> – sie werden nie importiert. Der Modus entscheidet nur, was mit <em>bereits vorhandenen</em> Einträgen geschieht, deren Zeile jetzt ausgeblendet ist oder in der Datei fehlt:',
    'import_add'       => 'Additiv',
    'import_add_hint'  => 'Solche Einträge bleiben bestehen und werden weiterhin angeschrieben. Es wird nichts gelöscht.',
    'import_absolute'  => 'Absolut',
    'import_absolute_hint' => 'Solche Einträge werden gelöscht, samt bereits erzeugter PDFs – die Quelldatei bestimmt also die Teilnehmer. Bereits beantwortete Einträge, die in der Datei stehen, bleiben unverändert.',
    'import_absolute_confirm' => 'Absoluter Import: Einträge, die in der Quelldatei nicht mehr sichtbar sind, werden gelöscht – auch bereits beantwortete samt ihrer PDFs. Fortfahren?',
    'pending'          => 'Offene Vorgänge',
    'selection'        => 'Auswahl:',
    'sel_all'          => 'Alle',
    'sel_none'         => 'Alle aufheben',
    'col_email'        => 'E-Mail',
    'col_name'         => 'Name',
    'col_vorname'      => 'Vorname',
    'col_abteilung'    => 'Abteilung',
    'col_delivery'     => 'Zustellung',
    'delivery_sent'    => 'Versendet',
    'delivery_sent_hint' => 'Versendet – bisher keine Fehlermeldung. „Versendet“ heißt angenommen, nicht garantiert zugestellt; ein späterer Bounce erscheint hier automatisch.',
    'delivery_error'   => 'Versandfehler',
    'delivery_bounce'  => 'Unzustellbar',
    'close'            => 'Schließen',
    'mode_auto'        => 'Automatisch (Adressaten nach Status)',
    'mode_manual'      => 'Manuelle Auswahl (markierte Teilnehmer)',
    'send_invites'     => 'Einladungen senden',
    'send_reminders'   => 'Erinnerungen senden',
    'send_confirmations' => 'Bestätigung senden',
    'send_now'         => 'Jetzt senden',
    'back'             => 'Zurück',
    'no_pending'       => 'Keine offenen Vorgänge.',
    'delivery_pending'      => 'Ausstehend',
    'delivery_pending_hint' => 'Antwort erfasst, aber die Bestätigung (PDF + E-Mail) wurde noch nicht erzeugt. Ein Wiederholversuch läuft automatisch; oder „Bestätigung neu senden" nutzen.',
    // Used by workflow-backend.js (via data-* attributes).
    'hint_manual'      => 'Es werden nur die markierten Teilnehmer mit passendem Status berücksichtigt.',
    'hint_auto'        => 'Die Adressaten werden automatisch nach Status gewählt.',
    'no_recipients'    => 'Es gibt keine passenden Empfänger für diese Aktion.',
    'confirm_invite'   => 'Folgende %count% Empfänger erhalten die Einladung:',
    'confirm_reminder' => 'Folgende %count% Empfänger erhalten die Erinnerung:',
    'confirm_confirmation' => 'Folgende %count% Empfänger erhalten die Bestätigung (PDF wird neu erzeugt):',
];

// WorkflowValidator::getProblems() – shown on the overview (and the edit mask).
$GLOBALS['TL_LANG']['workflow_validator'] = [
    'no_source'          => 'Es ist keine Quelldatei ausgewählt – der Workflow kann erst nach dem Laden einer Quelldatei ausgeführt werden.',
    'source_unreadable'  => 'Die Quelldatei ist nicht lesbar oder enthält keine Spalten.',
    'sheet_missing'      => 'Das eingestellte Tabellenblatt „%s" gibt es in der Quelldatei nicht. Vorhanden ist: „%s". Bitte das Tabellenblatt anpassen.',
    'no_email_col'       => 'Es ist keine E-Mail-Spalte gewählt.',
    'email_col_missing'  => 'Die E-Mail-Spalte „%s“ fehlt in der Quelldatei.',
    'storage_missing'    => 'Das Speicherfeld „%s“ (Formularfeld „%s“) fehlt in der Quelldatei.',
    'rule_unknown_field' => 'Der Dokument-Text „%s“ verwendet das unbekannte Feld „%s“.',
    'condition_unknown_field' => 'Die Sichtbarkeits-Bedingung des Formularfelds „%s“ prüft die Spalte „%s“, die es in der Quelldatei nicht (mehr) gibt.',
    'condition_forward_ref'   => 'Die Sichtbarkeits-Bedingung des Formularfelds „%s“ prüft die Spalte „%s“, die im Formular erst später (oder gar nicht) ausgefüllt wird. Bedingungen dürfen sich nur auf vorangehende Formularfelder beziehen – bitte die Reihenfolge der Formularfelder anpassen.',
    'master_missing'     => 'Das zugeordnete Briefpapier existiert nicht mehr (wurde gelöscht) – bitte ein gültiges Briefpapier zuordnen.',
    'sender_placeholder'    => 'Absenderadresse „%s“ verwendet eine Beispiel-/Platzhalterdomain („%s“). An solche Adressen werden Mails nicht zugestellt, und Unzustellbarkeitsmeldungen (Bounces) verschwinden unbemerkt. Bitte im Notification Center eine echte Absenderadresse eurer Domain setzen.',
    'sender_no_mx'          => 'Absenderadresse „%s“: Die Domain „%s“ hat keinen MX-Eintrag im DNS. Mit diesem Absender sind die Mails unzustellbar, und Unzustellbarkeitsmeldungen (Bounces) verschwinden unbemerkt. Bitte eine echte, versandfähige Absenderadresse setzen (im Notification Center).',
    'sender_domain_mismatch'=> 'Die Absenderdomain „%s“ weicht von der Website-Domain (%s) ab. Bitte die SPF-/DKIM-/DMARC-Ausrichtung prüfen, sonst landen die Mails leicht im Spam.',
];

// SourceFileInfoListener – die Kurzauskunft unter der Quelldatei-Auswahl. „current“ ist der
// wichtigste Text: Wer gerade eine neue Fassung hochgeladen zu haben glaubt und hier „Stand des
// letzten Imports“ liest, hat die Datei woanders abgelegt – der häufigste Fall ist ein
// abweichender Dateiname (Contao ersetzt beim Hochladen weder Leerzeichen noch Großbuchstaben,
// „Tabelle 2026.xlsx“ überschreibt „tabelle-2026.xlsx“ also nicht).
$GLOBALS['TL_LANG']['workflow_source'] = [
    'missing'         => 'Die Quelldatei ist nicht (mehr) auffindbar – sie wurde gelöscht, verschoben oder umbenannt. Bitte erneut auswählen.',
    'never'           => 'Diese Datei wurde noch nicht importiert.',
    'changed'         => 'Die Datei wurde seit dem letzten Import (%s) geändert – die gespeicherten Daten stammen noch aus der vorherigen Fassung.',
    'changed_unknown' => 'Die Datei stimmt nicht mit dem Stand des letzten Imports überein – die gespeicherten Daten stammen noch aus der vorherigen Fassung.',
    'current'         => 'Stand des letzten Imports (%s) – die Datei wurde seitdem nicht verändert. Wurde inzwischen eine neue Fassung hochgeladen, ist sie unter einem anderen Namen abgelegt worden; bitte den Ordner in der Dateiverwaltung prüfen.',
    'current_unknown' => 'Die Datei entspricht dem Stand des letzten Imports.',
    'other_file'      => 'Achtung: Der letzte Import (%2$s) las eine <strong>andere</strong> Datei – „%1$s“. Die gespeicherten Daten stammen von dort, nicht aus der oben ausgewählten Datei.',
];

// MailRouteListener – „Versandweg“ unter den Benachrichtigungen der Bearbeitungsmaske (3.6.0):
// je Benachrichtigung Absender, Antworten, Bounces; warn_*: Probleme (%1$s/%2$s siehe Schlüssel).
$GLOBALS['TL_LANG']['workflow_route'] = [
    'title'           => 'Versandweg',
    'col_from'        => 'Empfänger sehen als Absender',
    'col_answers'     => 'Antworten gehen an',
    'col_bounces'     => 'Bounces gehen an',
    'kind_invite'     => 'Einladung',
    'kind_reminder'   => 'Erinnerung',
    'kind_result'     => 'Ergebnis',
    'from_transport'  => '(vom Transport „%s“ erzwungen)',
    'from_admin'      => 'Admin-Adresse der Website',
    'answers_replyto' => '(„Antwort an“)',
    'answers_from'    => '(= Absender, kein „Antwort an“)',
    'bounces_mailbox' => '(Bounce-Postfach)',
    'bounces_from'    => '(= Absender, kein Bounce-Postfach)',
    'not_mail'        => 'verschickt nicht per E-Mail (Gateway „%s“)',
    'hint'            => 'Aus den gespeicherten Einstellungen; nach dem Ändern einer Benachrichtigung speichern, um die Anzeige zu aktualisieren. Den tatsächlichen Weg eines Bounces zeigt der Ende-zu-Ende-Test in der Übersicht.',
    'warn_route_not_mail'          => 'Die Nachricht „%1$s“ geht über das Gateway „%2$s“, das keine E-Mails verschickt – für sie gibt es keine Bounce-Erkennung.',
    'warn_route_from_is_bounce'    => 'Der Absender %s ist das Bounce-Postfach. Antworten von Teilnehmern landen dann in einem Postfach, das nur das Bundle liest. Bitte einen Absender wählen, den ein Mensch liest.',
    'warn_route_replyto_is_bounce' => '„Antwort an“ %s ist das Bounce-Postfach. Antworten von Teilnehmern landen dann in einem Postfach, das nur das Bundle liest. Bitte eine Adresse wählen, die ein Mensch liest.',
    'warn_route_no_mailbox'        => 'Es ist kein Bounce-Postfach eingerichtet: Bounces gehen an den Absender und werden nicht erkannt. Einrichten in der Workflow-Übersicht unter „Bounce-Postfach“ (nur Administratoren).',
    'warn_route_mailbox_failing'   => 'Das Bounce-Postfach war bei der letzten Prüfung nicht erreichbar – Bounces werden derzeit nicht erkannt. Details in der Workflow-Übersicht.',
];

// WorkflowIntegrityListener::flagStaleSource() – Hinweis in der Bearbeitungsmaske, wenn die
// gespeicherten Daten nicht mehr zur Quelldatei passen. Zwei Fälle, eine Regel
// (WorkflowValidator::isSourceDirty), aber zwei Wortlaute: „noch nie importiert“ liest sich
// anders als „die Datei hat sich geändert“.
$GLOBALS['TL_LANG']['workflow_reimport'] = [
    'edit_hint'         => 'Die Quelldatei wurde geändert, aber noch nicht importiert. Vorschau-Formular und Vorschau-PDF zeigen bis dahin die alten Daten und Zahlenformate. Bitte den Import ausführen, um die aktuellen Daten und Feldformatierungen zu laden.',
    'first_import_hint' => 'Für diesen Workflow wurde noch kein Import ausgeführt – es liegen keine Teilnehmerdaten vor. Vorschau-Formular und Vorschau-PDF zeigen bis dahin nur Beispieldaten, und es können keine E-Mails versendet werden.',
    'import_button'     => 'Jetzt importieren',
];
