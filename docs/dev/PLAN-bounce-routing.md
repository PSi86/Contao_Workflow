# Implementierungsplan 3.6.0: Bounce-Zustellung unabhängig von Absender und „Antwort an"

**Stand:** 2026-11-03, nach Release 3.5.0. **Zweig:** `feature/3.6.0-bounce-routing`.
**Konzept mit Begründung und Zeichnung:** Claude Doc
<https://claude.ai/artifact/Nn4nxR4Nz3KVmtLtC718wF> (dort auch die Varianten, die verworfen wurden).
**Version:** 3.6.0. Das Contao-5.7-Release (Zweig `feature/3.5.0-contao-5.7`, PR #30) wird **3.7.0**.

**Ziel:** Bounces jeder Workflow-Mail landen in einem eigenen Postfach, das nur das Bundle liest –
unabhängig davon, was der Workflow-Admin im Notification Center als Absender oder „Antwort an"
einträgt. Antworten von Menschen landen in einem anderen Postfach, das das Bundle nie anfasst.

---

## 0. Ausgangslage (verifiziert, nicht neu ermitteln)

### 0.1 Wohin Bounces gehen

- Ein Bounce (DSN) geht an den **Envelope-Sender** (`MAIL FROM`, beim Empfänger `Return-Path`),
  nicht an `Reply-To`, nicht an `From` (RFC 5321).
- Symfony Mailer 6.4 / 7.x bestimmt den Envelope-Sender in `DelayedEnvelope::getSenderFromHeaders()`:
  Kopfzeile `Sender`, sonst `Return-Path`, sonst erste `From`-Adresse. `Reply-To` spielt keine Rolle.
- `ContaoMailer::setFrom()` überschreibt bei einem Transport mit `from` die From-Adresse (und
  `Return-Path`/`Sender`, falls gesetzt) – damit auch den Envelope-Sender.
- Das Notification Center 2.7 setzt weder `Sender` noch `Return-Path`.
- **Gemessen im DDEV 2026-11-03** (`scripts/measure-envelope-sender.sh`, Mailpit-API): Einladung
  mit `From: noreply@example.com` und `Reply-To: antwort-an@example.org` → `Return-Path:
  <noreply@example.com>`.
- **Auf Produktion im Juli 2026 belegt** (`PLAN-bounce-handling.md` 0.2): kasserver schreibt den
  Envelope-Sender nicht um (`Return-Path` = `From`).
- Die Beobachtung des Nutzers „Bounces gehen meist an die Reply-To-Adresse" ist damit **nicht
  bestätigt**. Vermutete Ursachen: Abwesenheitsnotizen / Backscatter (keine DSN). An einem echten
  Beispiel prüfen (Kopfzeilen `Return-Path`, `Content-Type`), bevor man darauf baut.

### 0.2 Wo das Bundle den Envelope-Sender setzen kann

- `Mailer::send()` mit Messenger-Bus löst beim **Einreihen** einen `MessageEvent` (`isQueued()
  === true`) aus – auf einer **Kopie** von Nachricht und Envelope. Änderungen dort werden
  verworfen.
- `AbstractTransport::send()` löst beim **eigentlichen Senden** (im Worker bzw. synchron) einen
  `MessageEvent` (`isQueued() === false`) aus und verwendet danach `$event->getEnvelope()` für
  `doSend()`. **Hier** greift `$event->getEnvelope()->setSender(new Address(...))`.
- Die Kopfzeile `Notification-Center-Parcel-ID` ist zu diesem Zeitpunkt noch vorhanden (NC
  entfernt sie erst in `MailerAsynchronousReceiptUpdateListener` nach dem Senden). Damit lassen
  sich Workflow-Mails von anderen Mails der Website unterscheiden: Parcel-ID steht in
  `tl_workflow_send`.

### 0.3 IMAP (webklex/php-imap 6.2)

- Standard-Sequenz ist `IMAP::ST_UID` (Vendor-Config) → Verschieben arbeitet über UIDs.
- `Query::getByUidGreater(int $uid)` und `whereUid*()` vorhanden; `UIDVALIDITY` liefern
  `examineFolder()` / `folderStatus()` (`ImapProtocol`, Schlüssel `uidvalidity`).
- Keyword-Flags (`whereKeyword`, `setFlag`) gibt es auch, sind aber von der Server-Unterstützung
  abhängig → **Wasserstandsmarke (UIDVALIDITY + höchste UID) bevorzugen.**
- Heute (3.5.0) verschiebt `BounceCollector::collect()` **jede** Nachricht nach `INBOX/Processed`,
  auch Nicht-DSNs und fremde Bounces.

### 0.4 Was 3.5.0 schon mitbringt (darauf aufbauen)

- `CronHealth` (letzter Aufruf `/_contao/cron` / `contao:cron`), Statuszeile in der Übersicht
  (`DashboardModule::cronStatus()`), `shouldDefer()` für Seitenaufrufe.
- `BounceSchedule` (Takt 2 / 15 / 120 Min.), `BounceCollector::checkNow()` mit `LockFactory`,
  Route `workflow_bounce_check` („Jetzt prüfen").
- `DeliveryRecorder::blockAddress()` sperrt nur, wenn die Adresse des Eintrags noch die der
  Versandzeile ist; `SendFailureClassifier`; `SendLogReconciler`.
- `RequestTimingListener` + `BackgroundActivity` (System-Log bei > 10 s).
- Konfiguration des Postfachs: `WORKFLOW_BOUNCE_IMAP_DSN` in `.env.local`
  (`config/services.yaml`, bind `$bounceImapDsn`).

### 0.5 Produktion (tsvkorntal.de, All-Inkl, keine Konsole)

- PHP läuft als `fpm-fcgi`, `fastcgi_finish_request` vorhanden (2026-11-03 geprüft).
- KAS-Cronjob auf `/_contao/cron` läuft sehr wahrscheinlich (alle `tl_cron_job.lastRun` auf
  Sekunde :01). Nach dem Update auf 3.5.0 zeigt es die Statuszeile sicher.
- Mail-Setup (Stand 2026-10-12, siehe Memory `production-rollout-tsvkorntal-de`): Transport
  `workflow` mit `WORKFLOW_MAILER_DSN` (Konto `workflow@tsvkorntal.de`) und erzwungenem `from`;
  eigenes NC-Gateway „E-Mail (Workflow)". Bounce-Postfach laut damaliger Planung ebenfalls
  `workflow@` – **auf Produktion nicht verifiziert**.

---

## 1. Getroffene Entscheidungen (nicht neu verhandeln)

| # | Entscheidung | Datum / von |
|---|---|---|
| E1 | Zwei Postfächer: **Bounce-Postfach** (z. B. `bounces@`) liest nur das Bundle; **Antwort-Postfach** (`Reply-To`, sonst `From`) liest nur der Mensch. | Nutzer, 2026-11-03 |
| E2 | Das Bundle setzt bei jeder Workflow-Mail den Envelope-Sender auf die Bounce-Adresse (beim Senden, nicht beim Einreihen). `From` und `Reply-To` bleiben unverändert. | Konzept, 2026-11-03 |
| E3 | Der Bounce-Abruf verschiebt nur eigene Bounces nach `INBOX/Processed`; alles andere bleibt im Posteingang und wird gezählt. Jede Nachricht wird genau einmal angesehen (Wasserstandsmarke). | Konzept |
| E4 | Ende-zu-Ende-Test per Knopf, über den echten Versandweg eines gewählten Workflows. | Konzept |
| E5 | Bounce-Postfach im Back-End einrichtbar; `WORKFLOW_BOUNCE_IMAP_DSN` in `.env.local` hat Vorrang und bleibt gültig. | Konzept |
| E6 | Bearbeitungsmaske warnt, wenn `From` oder `Reply-To` einer Benachrichtigung die Bounce-Adresse ist. | Nutzer, 2026-11-03 |
| E7 | Test- und Produktionssystem bekommen je ein eigenes Bounce-Postfach. | Konzept |
| E8 | Version 3.6.0; Contao 5.7 wird 3.7.0. | Nutzer, 2026-11-03 |

## 2. Offene Fragen – vor bzw. während der Umsetzung klären

- [ ] **F1 (All-Inkl): Einrichtung 1 oder 2?**
      Einrichtung 1 = Versand über das Bounce-Konto (`bounces@` als SMTP-Anmeldung, `From:
      workflow@`); offen, ob kasserver eine `From`-Kopfzeile ≠ Anmeldekonto zulässt.
      Einrichtung 2 = Versand über `workflow@`, Bundle setzt `MAIL FROM: bounces@`; offen, ob
      kasserver `MAIL FROM` ≠ Anmeldekonto zulässt (sonst `553 5.7.1 … Sender address rejected`).
      Klärung: AP-B3 (Ende-zu-Ende-Test) auf tsvkorntal.com. Der Fehler landet dank 3.5.0 als
      „Versandfehler" mit Text am Eintrag bzw. in der Testanzeige.
- [ ] **F2 (All-Inkl): Welche Testadresse erzeugt einen echten Bounce statt einer sofortigen
      Ablehnung?** Kandidaten: `bounce-test-<zufall>@bounce-test.invalid` (reservierte TLD) oder
      ein unbekanntes Postfach der eigenen Domain. Beides auf tsvkorntal.com ausprobieren.
- [ ] **F3 (Nutzer): Passwort des Bounce-Postfachs in der Datenbank** (libsodium, Schlüssel aus
      `APP_SECRET`) akzeptabel – oder bleibt es in `.env.local`? Davon hängt AP-B4 ab.
- [ ] **F4 (Nutzer): Wer darf das Postfach einrichten?** Vorschlag: nur Contao-Administratoren.
- [ ] **F5 (Nutzer): Erzwungenes `from` am Transport `workflow`** – mit E2 für Bounces nicht mehr
      nötig. Soll der Absender wieder frei wählbar werden (DKIM/DMARC beachten)?
- [ ] **F6 (Nutzer, optional): Beispiel-Mail zu „Bounce kam bei Reply-To an"** mit Kopfzeilen,
      um 0.1 endgültig abzuhaken.
- [ ] **F7 (Technik): ext-sodium auf All-Inkl vorhanden?** (`<?php var_dump(extension_loaded('sodium'));`
      in einer Wegwerf-Datei, danach löschen) – Voraussetzung für AP-B4.

## 3. Architektur

### 3.1 Bounce-Adresse

Eine Stelle liefert sie: neuer Dienst `BounceMailbox` (Name offen) mit
`address(): ?string`, `imapConfig(): ?array`, `source(): 'env'|'backend'|null`.
- Aus `WORKFLOW_BOUNCE_IMAP_DSN`: Benutzername, wenn er eine E-Mail-Adresse ist; sonst optional
  `WORKFLOW_BOUNCE_ADDRESS`.
- Aus dem Back-End-Datensatz (AP-B4).
- `BounceCollector`, `BounceHealth::isConfigured()`, Envelope-Listener, Maskenprüfung und
  Statuszeile lesen nur noch diesen Dienst. Heute lesen `BounceCollector`, `BounceHealth` und
  `BounceCollectCommand` den gebundenen `$bounceImapDsn` je selbst.

### 3.2 Datenmodell (nur hinzufügen)

- `tl_workflow_send.envelopeSender` varchar(255) – welche Adresse tatsächlich als `MAIL FROM` ging.
- `tl_workflow_bounce_health`: `uidValidity` int, `lastUid` int (Wasserstandsmarke),
  `foreignCount` int (Nachrichten im Postfach, die keine eigenen Bounces sind, beim letzten Lauf).
- `tl_workflow_send.kind = 'test'` für Testmails (Spalte existiert; `entryId = 0`).
- AP-B4: Einstellungstabelle (z. B. `tl_workflow_settings`, ein Datensatz) mit `bounceAddress`,
  `imapHost`, `imapPort`, `imapEncryption`, `imapUser`, `imapPasswordEnc` (blob).

**Dev-DB-Falle:** auf den DDEV-Datenbanken nie ein volles `contao:migrate` von einem Zweig ohne
`ruleOrder` ausführen (das löscht die Spalte des 5.7-Zweigs). Neue Spalten gezielt anlegen, Muster:
`scripts/create-cron-health-table.sh`.

## 4. Arbeitspakete

Reihenfolge B1 → B2 → B3 → (Test auf tsvkorntal.com, F1/F2) → B4 → B5 → B6.
Ein Commit pro Paket, deutsche Commit-Texte ohne Umlaute im Betreff (siehe Git-Log).

### AP-B1 — Envelope-Sender setzen
- Neuer Listener auf `Symfony\Component\Mailer\Event\MessageEvent`; nur `!$event->isQueued()`.
- Parcel-ID aus Kopfzeile `Notification-Center-Parcel-ID` (`MailerGateway::MESSAGE_IDENTIFIER_HEADER`);
  nur wenn sie in `tl_workflow_send` steht.
- `$event->getEnvelope()->setSender(new Address($bounceAddress))`; ohne Bounce-Adresse nichts tun.
- `tl_workflow_send.envelopeSender` schreiben.
- Tests: Unit-Test mit echtem `MessageEvent` (queued/nicht queued, fremde Mail, ohne Adresse).
  DDEV: `scripts/measure-envelope-sender.sh` erweitern → `Return-Path` = Bounce-Adresse, `From`/
  `Reply-To` unverändert.
- Abnahme: Mailpit zeigt `Return-Path: <bounces@…>` bei unverändertem `From` und `Reply-To`.

### AP-B2 — Bounce-Abruf rührt nur eigene Bounces an
- Wasserstandsmarke: `UIDVALIDITY` + höchste geprüfte UID; nur `getByUidGreater($lastUid)`.
  Bei geänderter `UIDVALIDITY` Marke zurücksetzen (ein voller Durchgang).
- Eigener Bounce (Parcel-ID bekannt oder Empfänger-Fallback „Regel 5") → wie heute auswerten und
  nach `INBOX/Processed`. Alles andere: liegen lassen, `foreignCount` hochzählen.
- `handleRaw()` bleibt IMAP-frei testbar; die Entscheidung „verschieben ja/nein" als reine Funktion
  herausziehen, damit sie ohne IMAP getestet werden kann.
- Statuszeile: „N Nachrichten im Bounce-Postfach sind keine Bounces – zeigt eine ‚Antwort an'
  dorthin?" (nur wenn N > 0).
- Verhaltensänderung für bestehende Installationen: Nicht-DSNs bleiben künftig im Posteingang
  (bisher nach `Processed`). Im CHANGELOG nennen.
- Testmöglichkeit für echtes IMAP im DDEV erwägen (z. B. GreenMail- oder Dovecot-Container als
  zusätzlicher DDEV-Dienst) – Mailpit ist kein IMAP-Server.

### AP-B3 — Ende-zu-Ende-Test
- Knopf in der Statuszeile/im Dialog „Bounce-Postfach": Workflow wählen → Testmail über dessen
  Einladungs-Benachrichtigung (echtes Gateway, Transport, From, Reply-To) an die Testadresse (F2).
- Eigene `tl_workflow_send`-Zeile (`kind = 'test'`, `entryId = 0`); kein Eintrag wird verändert
  (`DeliveryRecorder` bei `entryId = 0` überspringen).
- Während eines laufenden Tests: `BounceSchedule` liefert eine Stufe „test" (jede Minute), bis zu
  30 Minuten.
- Ergebnis in der Statuszeile: „Bounce nach 48 s angekommen und zugeordnet (Envelope-Sender …)",
  „Kein Bounce nach 30 Minuten" oder „Server hat den Versand abgelehnt: …" (Text aus
  `tl_workflow_send.error`).
- Abnahme auf tsvkorntal.com: beantwortet F1 und F2.

### AP-B4 — Bounce-Postfach im Back-End (hängt an F3, F4, F7)
- Dialog „Bounce-Postfach" (Übersicht): Adresse, IMAP-Server, Port, Verschlüsselung, Benutzer
  (Standard = Adresse), Passwort (nur schreiben; Anzeige „gesetzt").
- Verschlüsselung: `sodium_crypto_secretbox`, Schlüssel per `sodium_crypto_generichash` aus
  `kernel.secret`; Entschlüsselung scheitert → Status „Passwort neu eingeben".
- `WORKFLOW_BOUNCE_IMAP_DSN` gesetzt → Werte nur anzeigen, Hinweis „durch .env.local festgelegt".
- Knöpfe „Verbindung testen" (Anmeldung, `INBOX`, `INBOX/Processed`) und „Ende-zu-Ende-Test" (B3).
- Hinweise im Dialog: Adresse nur für Bounces, Spamfilter für dieses Postfach aus, je Installation
  ein eigenes Postfach.

### AP-B5 — Prüfungen in der Bearbeitungsmaske
- Je Benachrichtigung (Einladung, Erinnerung, Ergebnis): Zeile „Bounces gehen an …" und
  „Antworten gehen an …" (`Reply-To`, sonst `From`). `WorkflowValidator::senderWarnings()` liest
  bisher nur `tl_nc_language.email_sender_address` (über `tl_nc_notification` → `tl_nc_message`);
  `email_replyTo` kommt dazu.
- Warnungen: kein Bounce-Postfach; letzte Prüfung gescheitert; Gateway kein Mail-Gateway;
  `From` oder `Reply-To` = Bounce-Adresse (E6).
- Bestehende Absenderdomain-Prüfungen (AP7 aus 2.x) bleiben.

### AP-B6 — Doku, Changelog, Release
- DEPLOYMENT.md §3c neu: zwei Postfächer, Einrichtung 1/2, Schritt-für-Schritt All-Inkl,
  Ende-zu-Ende-Test. ANLEITUNG.md: Dialog „Bounce-Postfach", Maskenzeilen.
- CHANGELOG `[3.6.0]`; Verhaltensänderung aus B2 und neue Spalten unter „Beim Update".
- Danach 3.7.0 (Contao 5.7): Zweig `feature/3.5.0-contao-5.7` auf den neuen `main` bringen,
  Titel von PR #30 und dessen CHANGELOG-Block auf 3.7.0.

## 5. Testen im DDEV

- Tests: `ddev exec bash /var/www/html/scripts/run-phpunit.sh 57` bzw. `… 53` (je eine Klasse pro
  `--filter`; `|` und `(` im Filter zerlegt `ddev exec`).
- Vor jedem Containerlauf `ddev mutagen sync`.
- Nach dem Entfernen/Umbenennen von Klassen: `rm -rf var/cache/prod && cache:warmup` statt
  `cache:clear` (der startet den alten Container).
- Bounce-Postfach-Attrappen in `contao-app/.env.local` (vorher sichern, danach zurück):
  `127.0.0.1:9993` = sofort abgelehnt, `10.255.255.1:993` = 20 s Zeitlimit.
- Ende-zu-Ende-Skripte aus 3.5.0: `scripts/e2e-350.sh` (+ `-cleanup`), `e2e-350-bounce-line.sh [57]`,
  `e2e-350-import-and-timing.sh`, `repro-prod-orphan.sh`, `measure-envelope-sender.sh`,
  `smoke-test.sh [57]` (zwei bekannte Abweichungen auf `main`-Basis: Funktionen des 5.7-Zweigs).

## 6. Offene Punkte auf Produktion (nicht Teil von 3.6.0, aber vorher erledigen)

- [ ] tsvkorntal.de auf 3.5.0 aktualisieren, „Datenbank aktualisieren", Cache leeren.
- [ ] `contao.cron.web_listener: false` in `config/config.yaml`, sobald die Statuszeile den
      Cronjob grün zeigt.
- [ ] Eintrag 43 (`kahnttlehrgang@hotmail`): richtige Adresse erfragen, am Eintrag **und** in der
      Excel-Quelldatei korrigieren.
- [ ] System-Log in den Tagen nach dem Update auf „Langsamer Seitenaufbau" / „Hintergrundarbeit
      nach …" ansehen (Frage der 30-s-Ladezeiten).
