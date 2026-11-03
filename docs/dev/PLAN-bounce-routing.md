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
| E9 | Zielbild tsvkorntal.de: Empfänger sehen `From: verwaltung@tsvkorntal.de`, Antworten gehen dorthin (kein abweichendes `Reply-To` nötig). Bounce-Postfach bleibt `workflow@tsvkorntal.de` und ist zugleich das SMTP-Anmeldekonto; AP-B1 setzt `MAIL FROM` = `workflow@`. Alle Adressen in `tsvkorntal.de` (SPF/DKIM-Ausrichtung für DMARC). Machbarkeit hängt am Test zu F1. | Nutzer, 2026-11-03 |
| E10 | Passwort des Bounce-Postfachs darf verschlüsselt in der Datenbank liegen (F3). | Nutzer, 2026-11-03 |
| E11 | Bounce-Postfach einrichten dürfen nur Contao-Administratoren (F4). | Nutzer, 2026-11-03 |
| E12 | Zuverlässiger Versand hat Vorrang vor frei wählbarem Absender (F5). | Nutzer, 2026-11-03 |
| E13 | Erzwungenes `from` am Transport `workflow` bleibt, Wert wird `verwaltung@tsvkorntal.de` – **erst nach** dem Update von tsvkorntal.de auf 3.6.0 (sonst gingen Bounces an `verwaltung@`, siehe Probe D). | Nutzer, 2026-11-03 |

## 2. Offene Fragen – vor bzw. während der Umsetzung klären

- [x] **F1 (All-Inkl): Einrichtung 1 oder 2?** → **Beide gehen; Zielbild E9 bestätigt** (Details
      unten, Gmail-Kopfzeilen von A und D vom Nutzer, 2026-11-03).
      Einrichtung 1 = Versand über das Bounce-Konto (`bounces@` als SMTP-Anmeldung, `From:
      workflow@`); offen, ob kasserver eine `From`-Kopfzeile ≠ Anmeldekonto zulässt.
      Einrichtung 2 = Versand über `workflow@`, Bundle setzt `MAIL FROM: bounces@`; offen, ob
      kasserver `MAIL FROM` ≠ Anmeldekonto zulässt (sonst `553 5.7.1 … Sender address rejected`).
      Klärung: AP-B3 (Ende-zu-Ende-Test) auf tsvkorntal.com. Der Fehler landet dank 3.5.0 als
      „Versandfehler" mit Text am Eintrag bzw. in der Testanzeige.
      **Stand 2026-11-03:** Zielbild E9 = Einrichtung 1 mit `From: verwaltung@`. Vor dem Bau von
      AP-B3 vorgezogen: `scripts/probe-smtp-routing.php` (im DDEV gegen Mailpit geprüft) schickt
      über das echte Konto `workflow@` vier Mails: A `From: verwaltung@` / `MAIL FROM: workflow@`
      an eine eigene Adresse; B, C dasselbe an unzustellbare Empfänger (Bounce-Weg); D Gegenprobe
      `MAIL FROM: verwaltung@` ≠ Anmeldung. Läuft mit dem eigenen Bounce-Postfach des Testsystems
      (E7) `workflow-test@tsvkorntal.de`, nicht mit dem Produktionskonto.
      **Gemessen 2026-11-03 14:28** (Probe `20261103-132813-56d3f1`, `w020341b.kasserver.com:587`,
      Anmeldung `workflow-test@tsvkorntal.de`): kasserver nimmt **A, B, C und D** an (`250 2.0.0 Ok:
      queued as …`). Also: `From` ≠ Anmeldekonto **und** `MAIL FROM` ≠ Anmeldekonto (D, gleiche
      Domain) werden beim Einliefern akzeptiert. Die Bounces zu B und C kamen im Postfach
      `workflow-test@` an (IMAP, `scripts/probe-imap-inbox.php`): `From: MAILER-DAEMON@dd36332.kasserver.com`,
      `Return-Path: <>`, `multipart/report; report-type=delivery-status`. Damit belegt: der Bounce
      geht an `MAIL FROM`, nicht an `From`. Gmail (Kopfzeilen vom Nutzer): A und D im Posteingang,
      je `spf=pass`, `dkim=pass header.i=@tsvkorntal.de s=kas202610150132`, `dmarc=pass (p=NONE)
      header.from=tsvkorntal.de`; A mit `Return-Path: <workflow-test@tsvkorntal.de>`, D mit
      `Return-Path: <verwaltung@tsvkorntal.de>`; kein `Reply-To` → Antworten gehen an `From`.
      Im Postfach `verwaltung@` kam nichts an. D zeigt zugleich, was ohne AP-B1 passiert, wenn das
      erzwungene `from` auf `verwaltung@` steht: die Bounces gingen an `verwaltung@`.
      **Folge:** Auf tsvkorntal.de das `from` erst nach dem Update auf 3.6.0 umstellen.
- [x] **F2:** Empfänger auf demselben Server (z. B. `non.existent@tsvkorntal.de`) werden sofort
      mit 5xx abgelehnt, kein DSN (Nutzer). Externe Empfänger liefern einen DSN (Probe zu F1):
      `…@bounce-test.invalid` nach ~1 s (`5.4.4`, „Host or domain name not found"), unbekannter
      Gmail-Empfänger nach ~10 s (`5.1.1`). **Testadresse für AP-B3: `wf-bouncetest-<zufall>@bounce-test.invalid`**
      – schnell, kein fremder Server, kein Risiko, eine echte Person zu treffen, keine Last für den
      Ruf bei Gmail.
- [x] **F3:** Passwort darf verschlüsselt in der Datenbank liegen (E10).
- [x] **F4:** nur Contao-Administratoren (E11).
- [x] **F5:** entschieden als E13 (erzwungenes `from` bleibt, Wert `verwaltung@tsvkorntal.de`, erst
      nach dem Update auf 3.6.0). Damit liegt `From` immer in der eigenen Domain, egal was im NC
      steht.
- [x] **Testsystem tsvkorntal.com** (Nutzer, 2026-11-03): ruft heute `noreply@tsvkorntal.com` als
      Bounce-Postfach ab – also nicht das Postfach der Produktion. Nach dem Update auf 3.6.0 wird
      dessen DSN-Benutzer automatisch die Bounce-Adresse. Für die Abnahme kann es bleiben oder im
      Dialog auf `workflow-test@tsvkorntal.de` wechseln (dazu `WORKFLOW_BOUNCE_IMAP_DSN` dort
      entfernen, sonst hat sie Vorrang).
- [x] **F6:** Kein Beispiel vorhanden. 0.1 bleibt „nicht bestätigt"; der Zähler aus AP-B2 macht
      Nicht-Bounces im Bounce-Postfach künftig sichtbar.
- [x] **F7:** ext-sodium ist auf tsvkorntal.de vorhanden. Prüfdatei im Web-Root, 2026-11-03:
      `PHP 8.3.36-nmm1 (fpm-fcgi)`, `ext-sodium geladen: ja`, `sodium_crypto_secretbox vorhanden: ja`.
      AP-B4 prüft trotzdem zur Laufzeit und zeigt einen Hinweis statt abzustürzen.

## 3. Architektur

### 3.1 Bounce-Adresse

Eine Stelle liefert sie: Dienst `Service\Bounce\BounceMailbox` (seit AP-B1) mit `address(): ?string`
und `imapDsn(): string`; `source()` und die Back-End-Quelle kommen mit AP-B4.
- `WORKFLOW_BOUNCE_ADDRESS`, wenn gesetzt (ausdrücklich gewinnt; ungültig → keine Adresse, kein
  stilles Ausweichen); sonst der Benutzername aus `WORKFLOW_BOUNCE_IMAP_DSN`, wenn er eine
  E-Mail-Adresse ist (`rawurldecode`, ein `+` bleibt ein `+`).
- Gefunden beim Bau von B1: `BounceCollector::configFromDsn()` dekodiert den Benutzer mit
  `urldecode()` – aus `bounces%2Bwf%40…` wird „bounces wf@…“, die Anmeldung scheitert. In AP-B4
  beim Umstellen auf `BounceMailbox` mit beheben (Test zuerst rot sehen).
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

### AP-B1 — Envelope-Sender setzen — **umgesetzt 2026-11-03, Commit e1389f9**
- Umsetzung: `EventListener\Mailer\EnvelopeSenderListener` (Priorität −256, nach Symfonys
  `EnvelopeListener` −255), `Service\Bounce\BounceMailbox`, Bind `$bounceAddress`
  (`WORKFLOW_BOUNCE_ADDRESS`), Spalte `tl_workflow_send.envelopeSender`. Synchroner Weg: Listener
  legt die Versandzeile aus dem `WorkflowMailContext` an (einfaches INSERT, portabel). DB-Fehler
  beim Schreiben (z. B. Spalte fehlt, weil „Datenbank aktualisieren“ noch nicht lief) → Warnung
  im System-Log, die Mail geht trotzdem mit der Bounce-Adresse raus.
- Tests: `EnvelopeSenderListenerTest` (7), `BounceMailboxTest` (10); ohne `setSender()` scheitern
  3 der 7 (gesehen). Ganze Suite 326 Tests grün gegen 5.7 und 5.3.
- DDEV gemessen (`measure-envelope-sender.sh`, über NC + Messenger-Worker): mit
  `WORKFLOW_BOUNCE_ADDRESS=bounces@example.org` → `Return-Path: <bounces@example.org>`, `From`
  und `Reply-To` unverändert, Zeile `sent, envelopeSender=bounces@example.org`; ohne →
  `Return-Path: <noreply@example.com>` wie bisher, `envelopeSender` leer.
- Bekannte Lücke: läuft ein CLI-Worker parallel und holt die Mail in den Millisekunden zwischen
  NC-Versand und `rememberParcel()`, ist die Zeile noch nicht da und der Kontext in seinem
  Prozess nicht aktiv → Envelope bleibt beim `From` (gleiche Lücke wie beim Ergebnis-Listener).
  Auf All-Inkl gibt es keinen CLI-Worker.
- Verhaltensänderung beim Update (CHANGELOG „Beim Update“): Ist `WORKFLOW_BOUNCE_IMAP_DSN` mit
  einer Adresse als Benutzer gesetzt, gehen Workflow-Mails ab 3.6.0 automatisch mit dieser
  Adresse als `MAIL FROM` raus. Ein Anbieter, der `MAIL FROM` ≠ Anmeldekonto ablehnt, würde den
  Versand dann scheitern lassen (All-Inkl nimmt es an, Probe D).
- Ursprüngliche Planung:
- Neuer Listener auf `Symfony\Component\Mailer\Event\MessageEvent`; nur `!$event->isQueued()`.
- Parcel-ID aus Kopfzeile `Notification-Center-Parcel-ID` (`MailerGateway::MESSAGE_IDENTIFIER_HEADER`);
  nur wenn sie in `tl_workflow_send` steht.
- `$event->getEnvelope()->setSender(new Address($bounceAddress))`; ohne Bounce-Adresse nichts tun.
- `tl_workflow_send.envelopeSender` schreiben.
- Tests: Unit-Test mit echtem `MessageEvent` (queued/nicht queued, fremde Mail, ohne Adresse).
  DDEV: `scripts/measure-envelope-sender.sh` erweitern → `Return-Path` = Bounce-Adresse, `From`/
  `Reply-To` unverändert.
- Abnahme: Mailpit zeigt `Return-Path: <bounces@…>` bei unverändertem `From` und `Reply-To`.

### AP-B2 — Bounce-Abruf rührt nur eigene Bounces an — **umgesetzt 2026-11-03**
- Umsetzung: `handleRaw($raw, $dryRun, $note)` liefert `['own' => bool, 'outcomes' => …]`; „eigen“
  = mindestens ein Report hat eine Versandzeile (Parcel-ID bekannt oder Regel 5). Nur eigene
  Nachrichten → `INBOX/Processed`. Wasserstandsmarke in `tl_workflow_bounce_health`
  (`scanMailbox` = `imap://user@host:port` ohne Passwort, `uidValidity`, `lastUid`,
  `foreignCount`), nach jeder Nachricht fortgeschrieben; anderes Postfach oder neue UIDVALIDITY →
  voller Durchgang. `STATUS` vor dem `SELECT` (Anzahl, UIDVALIDITY, UIDNEXT); nichts über der
  Marke → kein Abruf (auch kein UID-Abruf bei leerem Posteingang). DB-Fehler → Lauf stoppt, die
  Nachricht kommt beim nächsten Lauf wieder; unlesbare Nachricht → bleibt liegen, wird
  übersprungen; Verschieben scheitert → bleibt liegen (schon ausgewertet). `foreignCount` aus der
  STATUS-Anzahl berechnet (`leftInInbox()`), sinkt also, wenn jemand im Webmail löscht.
  `--dsn` am Befehl: ganzes Postfach, Marke des konfigurierten Postfachs bleibt unberührt.
- Anzeige: Statuszeile der Übersicht und „Jetzt prüfen“ zeigen `bounce_foreign` (DE/EN), nur
  wenn > 0 und der letzte Lauf ok war.
- Tests: `BounceCollectorTest` 19 (Urteil eigen/fremd, Testlauf schreibt nichts,
  `resumeAfter()`, `leftInInbox()`), `BounceHealthTest` 10 (Marke, fehlende Spalten, erster
  Befund nach von `recordScan()` angelegter Zeile). Gegenproben gesehen: `$own = true` → 2 rot;
  `record()` ohne ''→null → 1 rot. Suite 338 grün gegen 5.7 und 5.3.
- Echtes IMAP (All-Inkl-Dovecot, `workflow-test@`): `--dry-run --dsn=…` liest STATUS, holt 2 neue
  Nachrichten (UID > 0), erkennt beide Probe-Bounces als fremd, verändert nichts.
- Echter Lauf mit Schreiben (Nutzer-OK 2026-11-03, `scripts/e2e-360-b2.php`): künstlicher DSN per
  IMAP APPEND (keine Mail verschickt) zu einer DDEV-Versandzeile. Lauf 1: 3 neu, der eigene →
  `Processed`, Eintrag gesperrt, Zeile `bounced`, die 2 Probe-Bounces bleiben; gespeichert
  `lastUid 3`, `foreignCount 2`. Lauf 2: „0 neu (UID > 3)“, kein Abruf. Danach aufgeräumt
  (DSN gelöscht, DDEV-Zeilen und `.env.local` zurück).
- Dabei gefunden und behoben (seit 3.5.0): `createFolder()` von webklex schickt danach
  `EXPUNGE` ohne geöffneten Ordner → „BAD No mailbox selected“ als Warnung, obwohl der Ordner
  angelegt war (UIDVALIDITY des Ordners belegt das). Jetzt `createFolder(…, false)`; nach Löschen
  von `Processed` legte Lauf 3 ihn ohne Warnung neu an.
- Ursprüngliche Planung:
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

### AP-B3 — Ende-zu-Ende-Test — **umgesetzt 2026-11-03**
- Umsetzung: `Service\Bounce\BounceProbe` (`start()`, `latest()`, reine `describe()`),
  `NotificationDispatcher::sendTest()` (Einladungs-Benachrichtigung, Tokens eines leeren
  Antwortsatzes, nicht gespeichertes `EntryModel`), `WorkflowMailContext::KIND_TEST`. Route
  `workflow_bounce_test` (POST + REQUEST_TOKEN, Modulzugriff wie „Jetzt prüfen“). Formular in der
  Statuszeile (nur mit Bounce-Postfach; Auswahl der Workflows mit Einladung). Testadresse
  `wf-bouncetest-<12 hex>@bounce-test.invalid`. `BounceSchedule::TIER_TEST` (60 s) solange eine
  Testzeile `queued`/`sent` und ≤ 30 min alt ist – eigene Abfrage, kann die anderen Stufen nicht
  mitreißen. Collector: Bounce einer Testzeile → Zeile `bounced`, System-Log, Ergebnis `test`,
  kein Eintrag. `DeliveryRecorder` überspringt `entryId <= 0`. Statuszeile: eingereiht / warte
  bis HH:MM / ✔ nach N s / ⚠ Versand fehlgeschlagen – Fehlertext / ⚠ kein Bounce nach 30 min;
  Anzeige 7 Tage.
- Tests: `BounceProbeTest` (6), Collector + Schedule erweitert; Gegenproben gesehen (Test-Zweig
  im Collector aus → 1 rot, Test-Stufe aus → 1 rot). Suite 347 grün gegen 5.7 und 5.3.
- DDEV (`scripts/e2e-360-b3.sh`, `e2e-360-b3-states.sh`): Formular per POST mit Token →
  Bestätigung; Zeile `test`/`entryId 0`; Worker versendet; Mailpit `Return-Path:
  <bounces@example.org>` an die `.invalid`-Adresse; alle vier Statuszeilen und die Takt-Zeile
  gerendert; aufgeräumt.
- **Abnahme offen:** echter Bounce auf tsvkorntal.com nach dem Update (Bounce-Postfach
  `workflow-test@tsvkorntal.de`).
- Ursprüngliche Planung:
- Knopf in der Statuszeile/im Dialog „Bounce-Postfach“: Workflow wählen → Testmail über dessen
  Einladungs-Benachrichtigung (echtes Gateway, Transport, From, Reply-To) an die Testadresse (F2;
  muss extern sein, Empfänger auf demselben Server lehnt kasserver sofort ab).
- Eigene `tl_workflow_send`-Zeile (`kind = 'test'`, `entryId = 0`); kein Eintrag wird verändert
  (`DeliveryRecorder` bei `entryId = 0` überspringen).
- Während eines laufenden Tests: `BounceSchedule` liefert eine Stufe „test" (jede Minute), bis zu
  30 Minuten.
- Ergebnis in der Statuszeile: „Bounce nach 48 s angekommen und zugeordnet (Envelope-Sender …)",
  „Kein Bounce nach 30 Minuten" oder „Server hat den Versand abgelehnt: …" (Text aus
  `tl_workflow_send.error`).
- Abnahme auf tsvkorntal.com: beantwortet F1 und F2.

### AP-B4 — Bounce-Postfach im Back-End — **umgesetzt 2026-11-03**
- Umsetzung: `PasswordCipher` (secretbox, Schlüssel = generichash(Kontext | `kernel.secret`),
  Bind `$appSecret`), `BounceSettings` (Tabelle `tl_workflow_settings`, id 1; `validate()` liefert
  Sprachschlüssel), `BounceMailbox` jetzt einzige Quelle: `source()` env|backend|null,
  `imapDsn()` (env, sonst aus den Einstellungen gebaut, `rawurlencode`), `address()`
  (WORKFLOW_BOUNCE_ADDRESS > Adresse der Quelle), `passwordUnreadable()`, `envSummary()`.
  `BounceCollector`, `BounceHealth::isConfigured()`, `BounceCollectCommand` lesen nur noch sie.
  Route `workflow_bounce_mailbox` (POST + Token, `ROLE_ADMIN`; Aktionen check/save/clear;
  bei env → abgelehnt). Dialog `<details>` in der Übersicht nur für Admins (env → nur Anzeige;
  Passwort nie ausgegeben, Platzhalter „gespeichert“). „Speichern und Postfach prüfen“ =
  speichern + `checkNow()` (echter Abruf, Banner sofort aktuell). Banner bei unlesbarem Passwort.
- `+`-Fehler behoben: `BounceCollector::imapConfig()` (vorher privat `configFromDsn`) mit
  `rawurldecode`; Test zuerst rot gesehen („bounces wf@…“), dann grün inkl. Rundweg mit
  Sonderzeichen über `buildDsn()`. **Verhaltensänderung:** ein von Hand als `+` geschriebenes
  Leerzeichen in DSN-Benutzer/-Passwort wird nicht mehr zum Leerzeichen (kein Werkzeug erzeugt
  das; `%20` gilt weiter).
- Beim Bau ergänzt: `watermarkAfterRun()` hebt die Marke nach einem vollständigen Durchgang auf
  UIDNEXT − 1, damit gelöschte UIDs nicht bei jedem Lauf eine UID-Abfrage auslösen.
- Tests: `PasswordCipherTest` (4), `BounceSettingsTest` (10), Collector/Health angepasst;
  Gegenproben gesehen (env-Vorrang umgedreht → 2 rot; Schlüssel ohne APP_SECRET → 1 rot).
  Suite 363 grün gegen 5.7 und 5.3.
- DDEV über die Oberfläche (`scripts/e2e-360-b4.sh`, `e2e-360-b4-nonadmin.sh`): leer → Hinweis;
  Attrappe speichern + prüfen → „nicht erreichbar“, Passwort verschlüsselt (68 Zeichen);
  Einladung trägt `Return-Path` aus den Back-End-Einstellungen; **echtes Postfach
  `workflow-test@` gespeichert + geprüft → Anmeldung ok, 2 fremde liegen gelassen**; Befehl
  liest „aus den Einstellungen der Workflow-Übersicht“, Lauf 1 „UID > 2“, Lauf 2 „UID > 3“;
  env gesetzt → Dialog nur Anzeige, Speichern abgelehnt; Passwort beschädigt → Banner + Pflichtfeld;
  Entfernen → Hinweis; Nicht-Admin → kein Dialog, POST 403. Danach aufgeräumt.
- Ursprüngliche Planung:
- Dialog „Bounce-Postfach" (Übersicht): Adresse, IMAP-Server, Port, Verschlüsselung, Benutzer
  (Standard = Adresse), Passwort (nur schreiben; Anzeige „gesetzt").
- Verschlüsselung: `sodium_crypto_secretbox`, Schlüssel per `sodium_crypto_generichash` aus
  `kernel.secret`; Entschlüsselung scheitert → Status „Passwort neu eingeben".
- `WORKFLOW_BOUNCE_IMAP_DSN` gesetzt → Werte nur anzeigen, Hinweis „durch .env.local festgelegt".
- Knöpfe „Verbindung testen" (Anmeldung, `INBOX`, `INBOX/Processed`) und „Ende-zu-Ende-Test" (B3).
- Hinweise im Dialog: Adresse nur für Bounces, Spamfilter für dieses Postfach aus, je Installation
  ein eigenes Postfach.

### AP-B5 — Prüfungen in der Bearbeitungsmaske — **umgesetzt 2026-11-03**
- Umsetzung: `Service\Bounce\MailRoute::forNotification()` je veröffentlichter NC-Nachricht:
  Absender (erzwungenes `from` des Gateway-Transports via `AvailableTransports`, nur die Adresse
  aus „Name <…>“; sonst `email_sender_address`; leer → Admin-Adresse), `email_replyTo`,
  Antworten (Reply-To, sonst Absender), Bounces (`BounceMailbox::address()`, sonst Absender).
  Reine `warnings()`: kein Mail-Gateway; Absender = Bounce-Adresse; Reply-To = Bounce-Adresse
  (E6; ohne Groß/klein, nie bei `##token##`); kein Postfach; letzte Prüfung gescheitert.
  Pseudo-Feld `mailRoute` (`MailRouteListener`, public) unter `ncResult`: Tabelle „Versandweg“
  Einladung/Erinnerung/Ergebnis × Absender/Antworten/Bounces, Warnungen, Hinweis „aus den
  gespeicherten Einstellungen“. Bestehende Absenderdomain-Prüfungen (onload) unverändert.
- Tests: `MailRouteTest` (6); Gegenproben gesehen (E6-Vergleich aus → 1 rot, erzwungener
  Absender ignoriert → 1 rot). Suite 369 grün gegen 5.7 und 5.3.
- DDEV (`scripts/fetch-edit-route.sh`): ohne Postfach → Bounces „= Absender“ + Warnung; mit
  Postfach = Absender und Reply-To `NoReply@…` → beide E6-Warnungen. Aufgeräumt.
- Ursprüngliche Planung:
- Je Benachrichtigung (Einladung, Erinnerung, Ergebnis): Zeile „Bounces gehen an …" und
  „Antworten gehen an …" (`Reply-To`, sonst `From`). Hat der Transport ein erzwungenes `from`,
  ist das der tatsächliche `From` (`ContaoMailer::setFrom()`), nicht der Eintrag im NC.
  `WorkflowValidator::senderWarnings()` liest
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
