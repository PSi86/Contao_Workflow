# Produktiv-Deployment & Mailversand (Workflow)

Leitfaden für den produktiven Betrieb des Workflow-Bundles, mit Fokus auf
den E-Mail-Versand (100–300 Empfänger pro Lauf) und das Hosting bei
**all-inkl.com (KAS)**. Installation siehe [INSTALL.md](INSTALL.md), Bedienung
[ANLEITUNG.md](ANLEITUNG.md).

> **Die drei Dinge, die produktiv zwingend stimmen müssen:**
> 1. Ein **Worker/Cron** muss laufen, sonst werden Mails nur eingereiht, aber nie versendet.
> 2. **SPF + DKIM + DMARC** müssen für die Absender-Domain gesetzt sein, sonst landet
>    ein 300er-Schwung im Spam.
> 3. Der Workflow muss **veröffentlicht** sein – sonst weist die Übersicht den Versand ab
>    (die Links würden allen Empfängern als „ungültig" angezeigt).

---

## 1. Wie der Mailversand funktioniert (wichtig zu verstehen)

Contao versendet E-Mails **asynchron über Symfony Messenger**. Beim Klick auf
„Einladungen senden" (bzw. `workflow:send`) passiert nur:

1. pro Empfänger wird eine Nachricht in die Queue-Tabelle **`tl_message_queue`**
   (Transport `contao_prio_high`) geschrieben — die Aktion meldet „**zum Versand eingereiht**".

Der **eigentliche SMTP-Versand** passiert erst, wenn ein **Worker** die Queue
abarbeitet. **Erst dann** wechselt der Eintrag auf den Schritt **„Eingeladen"**; ein
**fehlgeschlagener** Versand lässt den Schritt unverändert und erscheint in der Übersicht
als **„Versandfehler"**. Ohne laufenden Worker bleibt alles in der Queue liegen — die Mail
geht nicht raus **und** der Status springt nicht um.

Gemessen (Dev-Container, Referenzwerte):
- **Einreihen von 300 Einladungen: ~1,5 s** (reiner DB-Schreibvorgang, kein Timeout-Risiko, auch im Backend-Klick).
- **Zustellung: ~17 Mails/s** gegen lokalen Mailserver → 300 lokal in ~17 s.
  In Produktion bestimmt die Latenz/Limitierung des echten Mailservers das Tempo
  (sequenziell): realistisch **300 Mails in ~1–2 Minuten**.

---

## 2. Worker / Cron in Produktion einrichten

### 2a. all-inkl (Shared Hosting) — empfohlener Weg
Auf Shared Hosting läuft **kein** Dauer-Worker. Stattdessen Contaos Web-Cron per
**KAS-Cronjob** jede Minute anstoßen:

- KAS → **Tools → Cronjobs → „Cronjob hinzufügen"**
- URL: `https://deine-domain.de/_contao/cron`
- Intervall: **jede Minute**

Contaos Cron arbeitet dabei die Mail-Queue (`tl_message_queue`) ab. 300 Mails sind
so nach wenigen Minuten draußen – vorausgesetzt, PHP läuft als **PHP-FPM** (bei all-inkl der
Fall; prüfbar mit einer Datei `<?php echo PHP_SAPI;`, die `fpm-fcgi` ausgeben muss). Dann
arbeitet Contao die Queue nach Auslieferung der Seite bis zu 30 Sekunden je Aufruf ab; ohne FPM
nur 1 Sekunde, und der Aufrufer wartet so lange. Ein Intervall von **3 Minuten** geht auch; dann prüft der
Bounce-Abruf in seiner schnellsten Stufe eben alle 3 statt alle 2 Minuten (Abschnitt 3c).
([all-inkl Cronjob-Anleitung](https://all-inkl.com/en/support/tutorials/kas/tools/cronjobs/setup_479.html))

> Verlass dich **nicht** auf den „Poor-Man's-Cron" (läuft nur bei zufälligen
> Seitenaufrufen) — für planbare Bursts ist der KAS-Cronjob zuverlässiger. Ohne Cronjob führt
> Contao Mail-Queue und Cronjobs nur am **Ende eines beliebigen Seitenaufrufs** aus, also
> **nachdem** die Seite ausgeliefert wurde: Die gerade angezeigte Seite zeigt immer den Stand
> davor, erst der nächste Aufruf das Ergebnis.

**Läuft der Cronjob? – Statuszeile in der Workflow-Übersicht.** Ganz oben steht
„Cronjob: zuletzt aufgerufen am … (über /_contao/cron)". Gezählt werden nur echte Aufrufe von
`/_contao/cron` bzw. `contao:cron`, keine Seitenaufrufe. Kommt länger als **10 Minuten** keiner,
wird die Zeile rot. Ins **System-Log** (System → System-Log) schreibt das Bundle nur bei einem
Wechsel: „Cronjob erkannt: erster Aufruf über …" und „Cronjob wieder aktiv … nach einer Pause
von N Minuten". Jede Minute einen Eintrag gibt es bewusst nicht.

**Danach: Cronjobs nicht mehr bei Seitenaufrufen ausführen.** Contao führt seine Cronjobs
zusätzlich am Ende jedes Seitenaufrufs aus und schaltet das nur bei einem **CLI**-Cron
(`contao:cron`) selbst ab, beim URL-Cron nicht. Läuft der KAS-Cronjob (Statuszeile grün), in
`<projekt>/config/config.yaml` ergänzen und danach den Cache leeren (Contao-Manager →
Systemwartung → Prod.-Cache neu erzeugen):

```yaml
contao:
    cron:
        web_listener: false
```

Das gilt für **alle** Contao-Cronjobs. Die beiden aufwendigen Jobs des Bundles (Bounce-Abruf,
Nachversand der Bestätigungen mit PDF-Erzeugung) lassen Seitenaufrufe ohnehin aus, solange ein
Cronjob aktiv ist; fällt er aus, springen die Seitenaufrufe wieder ein. Die Mail-Queue selbst
wird weiterhin am Ende jedes Aufrufs geleert (Contaos Web-Worker, unabhängig von dieser
Einstellung) – Mails gehen also direkt nach „E-Mails senden" raus; eine leere Queue kostet
eine einzige Abfrage.

### 2b. VPS / eigener Server (Alternative)
Dauerhafter Worker via systemd/supervisor (wird vom Prozessmanager neu gestartet):

```
contao-console messenger:consume contao_prio_high contao_prio_normal contao_prio_low \
  --time-limit=3600 --memory-limit=256M
```

### 2c. Nur einen Worker gleichzeitig — autoscale deckeln (wichtig bei all-inkl)
all-inkl erlaubt **max. 3 gleichzeitige SMTP-Verbindungen** (Abschnitt 3a). Wie viele
Versand-Prozesse parallel laufen, hängt davon ab, **wie** die Queue abgearbeitet wird:

- **URL-Cron auf `/_contao/cron` (Abschnitt 2a, empfohlen auf Shared Hosting):**
  Hier springt Contaos **Web-Worker** ein — pro Web-Request **ein** sequenzieller
  `messenger:consume` von wenigen Sekunden. Der autoscale-Supervisor läuft in diesem
  Pfad **nicht** (er ist an den CLI-Scope gebunden). Zu beachten: der Web-Worker kann
  bei vielen *gleichzeitigen* Seitenaufrufen mehrfach parallel anspringen.
- **CLI-Cron / VPS-Supervisor (`contao:cron` bzw. `contao:supervise-workers`):**
  Hier greift die **autoscale-Konfiguration** der Worker. Contaos Default skaliert
  **einen Worker je 5 wartende Nachrichten, bis zu 10 parallel** — bei einem 300er-Schwung
  also bis zu **10 gleichzeitige `messenger:consume`-Prozesse** und damit deutlich mehr,
  als all-inkl an SMTP-Verbindungen zulässt.

**Empfehlung** (greift für den CLI-/Supervisor-Pfad; auf reinem Shared Hosting schadet sie
nie): autoscale abschalten, sodass **genau ein** Worker läuft. In
`<projekt>/config/config.yaml`:

```yaml
# config/config.yaml — all-inkl: max. 3 gleichzeitige SMTP-Verbindungen
contao:
    messenger:
        workers:
            -
                transports: [contao_prio_high]
                options: ['--time-limit=55', '--sleep=5']
                autoscale: { enabled: false }
```

Bei abgeschaltetem autoscale startet der Supervisor **immer genau einen** Worker
(`contao:supervise-workers`, „Always start one worker"). Betreibe auf all-inkl außerdem
**keinen** zusätzlichen Dauer-Worker parallel zum Cron.

---

## 3. SMTP konfigurieren

### 3a. all-inkl-eigener SMTP (für 100–300 Mails ausreichend, kein Extra-Anbieter nötig)
all-inkl erlaubt **max. 3 gleichzeitige SMTP-Verbindungen** und empfiehlt
gestaffelten Versand (~1000 Mails/10 min). Unser **einzelner, sequenzieller
Worker** passt dort hinein — 300 sind unkritisch. **Wichtig: nicht mehrere Worker
parallel betreiben.**

1. **Absender-Postfach** anlegen: KAS → „E-Mail", z. B. `noreply@deine-domain.de`.
   ([Anleitung](https://all-inkl.com/en/support/tutorials/kas/email/email-address-autoresponder-forwarding/how-to-create-an-email-account_98.html))
2. **`MAILER_DSN`** in `<projekt>/.env.local` (nicht im Web-Root sichtbar):
   ```
   MAILER_DSN=smtp://noreply%40deine-domain.de:PASSWORT@wXXXXXXX.kasserver.com:587
   ```
   - Server = `<KAS-Login>.kasserver.com`, Port **587 (STARTTLS)** oder 465 (SSL).
   - `@` im Benutzernamen als `%40` kodieren; Sonderzeichen im Passwort ebenfalls URL-kodieren.
   - **Tipp:** Contaos Handbuch enthält einen **Mailer-DSN-Generator**, der die DSN inklusive
     korrekter URL-Kodierung (`@` → `%40`, Sonderzeichen im Passwort) für dich zusammenbaut –
     das erspart Tippfehler beim Kodieren:
     <https://docs.contao.org/5.x/manual/de/system/einstellungen/#mailer-dsn>
3. **Absenderadresse im Notification Center** auf `noreply@deine-domain.de` setzen
   (bei allen drei Notifications, Feld „Absender") — **nicht** die Demo-Adresse
   `example.com`. Sonst stimmt die DKIM/SPF-Ausrichtung nicht und die Mail gilt als unzulässig.

### 3b. Externer Transaktions-Anbieter (optional, erst bei Bedarf)
Sinnvoll bei deutlich höherem Volumen, schlechter Zustellbarkeit oder Bedarf an
Bounce-/Logging-Webhooks (z. B. **Amazon SES, Postmark, Mailgun, Brevo**):
- `MAILER_DSN` auf den SMTP/API-Endpunkt des Anbieters umstellen.
- **Dann** SPF/DKIM/DMARC für die Domain auf **den Anbieter** ausrichten (dessen
  DKIM-Schlüssel + SPF-Include), nicht auf all-inkl.
- Anbieter-Limits/Warmup beachten (z. B. SES-Sandbox: 1 Mail/s, nur verifizierte
  Empfänger, bis zur Freischaltung).

### 3c. Bounce-Postfach einrichten (unzustellbare Adressen erkennen)
Ein „**250 OK**" beim Absenden heißt nur „angenommen", **nicht** „zugestellt". Lehnt der
Empfänger-Server die Mail später ab (z. B. `550 User unknown`), schickt er eine
**Unzustellbarkeitsmeldung (Bounce/DSN)** als eigene E-Mail an die Absenderadresse zurück.
Diese Meldung erzeugt in Contao **kein** Ereignis – sie liegt nur im Postfach. Das Bundle
holt sie per **IMAP** ab, erkennt harte Bounces und sperrt die betroffene Adresse
(„Unzustellbar" in der Übersicht). Wie oft, richtet sich danach, was im Postfach sein kann:
**alle 2 Minuten** in der halben Stunde nach einem Versand, **alle 15 Minuten**, solange
Einladungen unbeantwortet sind, sonst **alle 2 Stunden** (holt auch einen verspäteten Bounce
noch ab). Die Statuszeile der Übersicht zeigt letzte und nächste Prüfung samt Grund;
**„Jetzt prüfen"** ruft das Postfach sofort ab – auch ohne Konsole.

1. **Postfach = Absenderadresse.** Die Bounces landen bei der Absenderadresse aus dem
   Notification Center (z. B. `noreply@deine-domain.de`). Lege für sie in KAS ein echtes
   **Postfach** an (kein reiner Weiterleitungs-Alias), damit die Bounces abholbar sind.
2. **`WORKFLOW_BOUNCE_IMAP_DSN`** in `<projekt>/.env.local` setzen:
   ```
   WORKFLOW_BOUNCE_IMAP_DSN=imap://noreply%40deine-domain.de:PASSWORT@wXXXXXXX.kasserver.com:993?ssl=true
   ```
   - Server = `<KAS-Login>.kasserver.com`, IMAP-Port **993** (SSL).
   - `@` im Benutzernamen als `%40` kodieren; Sonderzeichen im Passwort URL-kodieren.
   - **Leer/nicht gesetzt = Funktion aus** (kein Fehler, es passiert einfach nichts).
3. **Spamfilter für dieses Postfach deaktivieren.** Bounces haben `MAIL FROM:<>` (leerer
   Absender); manche Filter stufen genau das als verdächtig ein und würden die Bounce-Mails
   aussortieren, bevor das Bundle sie sieht.
4. **Unterordner `Processed`.** Verarbeitete Mails werden nach `INBOX/Processed` verschoben
   (Idempotenz – nichts wird doppelt ausgewertet). Der Ordner wird bei Bedarf automatisch
   angelegt; du kannst ihn auch selbst im Webmail erstellen.

> Der Abruf läuft über Contaos regulären Cron (Abschnitt 2) – kein zusätzlicher Cronjob
> nötig. Ist kein Bounce-Postfach konfiguriert, bleibt alles beim Alten; ein Versand gilt
> dann weiterhin als „versendet, keine Fehlermeldung".

> **⚠ Managed Edition: `.env.local` wird evtl. nicht direkt gelesen.** Existiert eine
> kompilierte `<projekt>/.env.local.php` (der Contao-Manager legt sie an), hat sie **Vorrang**,
> und Änderungen in `.env.local` werden ignoriert. Nach dem Eintragen von
> `WORKFLOW_BOUNCE_IMAP_DSN` daher **eine** der Varianten:
> - die Variable über die **Contao-Manager-Oberfläche** setzen (schreibt beides), **oder**
> - neu kompilieren: `vendor/bin/contao-console dotenv:dump prod`, **oder**
> - die Datei `.env.local.php` löschen (dann liest Contao `.env` + `.env.local` direkt).
>
> Prüfen, ob die Variable ankommt: `vendor/bin/contao-console debug:dotenv` (listet alle
> Werte). Danach den **prod-Cache neu bauen**.

**Konfiguration prüfen / Abruf sofort testen:** ohne Konsole über **„Jetzt prüfen"** in der
Übersicht; mit Konsole Schritt für Schritt:
```
vendor/bin/contao-console workflow:bounce:collect --dry-run
```
Das zeigt Schritt für Schritt: ob die DSN erkannt wird, ob die IMAP-Verbindung steht, wie
viele Nachrichten im Postfach liegen und ob ein Bounce einem Eintrag zugeordnet werden kann –
**ohne** etwas zu verändern. Ohne `--dry-run` verarbeitet es die Bounces sofort (verschiebt
sie nach `INBOX/Processed`, markiert die Einträge). Mit `--dsn=imap://…` lässt sich die
IMAP-Verbindung unabhängig von `.env.local` testen.

> **Diagnose in der Workflow-Übersicht.** Der Zustand der Bounce-Erkennung wird oben in der
> Übersicht angezeigt, ohne dass die Seite selbst eine IMAP-Verbindung öffnet (sie liest den
> letzten Cron-Befund):
> - **Kein Banner** – ein Postfach ist konfiguriert und beim letzten Lauf erreichbar.
> - **Hinweis (blau)** – es ist **kein** Postfach konfiguriert (oder die `.env`-Variable wurde
>   nicht geladen, siehe den Managed-Edition-Hinweis oben). Zustellfehler werden dann nicht
>   erkannt. Im System-Log steht dazu eine **Warnung**, kein Fehler.
> - **Fehler (rot)** – ein Postfach ist konfiguriert, war beim letzten Lauf aber **nicht
>   erreichbar** (falscher Host/Port, Zugangsdaten, Passwort-Format …). Der Grund steht im
>   Banner und als **Fehler** im System-Log. Nach der Korrektur **„Jetzt prüfen"** klicken (oder
>   `workflow:bounce:collect` ohne `--dsn` ausführen), um das Banner sofort zu aktualisieren –
>   sonst räumt es die nächste planmäßige Prüfung ab. Die Log-Meldungen erscheinen nur bei einem
>   **Zustandswechsel**, nicht bei jedem Lauf.

---

## 4. SPF / DKIM / DMARC bei all-inkl (Schritt für Schritt)

Zweck: Empfänger-Server (Gmail/Outlook/GMX …) sollen den 300er-Schwung als echt
einstufen. **Reihenfolge: erst SPF, dann DKIM, zuletzt DMARC** (nach SPF/DKIM ~48 h
warten, bevor DMARC scharf gestellt wird).

### 4a. DKIM (am einfachsten bei all-inkl)
KAS → **„Domain" → Domain „bearbeiten" → DKIM-Signierung „aktiviert"**.
Liegt die DNS bei all-inkl, wird der DKIM-DNS-Eintrag **automatisch** angelegt und
ausgehende Mails über kasserver werden signiert.
([DKIM-Doku](https://all-inkl.com/en/support/tutorials/kas/tools/dns-tools/how-to-add-a-dkim-record-in-case-of-using-an-external-email-server_444.html))

### 4b. SPF
KAS → **„Tools" → „DNS-Einstellungen"** → Domain bearbeiten. Es darf **genau einen**
SPF-Record (TXT) pro Domain geben. Verwende den von all-inkl angegebenen Wert für
Versand über deren Mailserver (endet auf `~all`), z. B. in der Form:
```
v=spf1 a mx ~all
```
> Den **exakten** für dein Konto gültigen Wert (ggf. mit `include:`) gibt all-inkl in
> der [SPF-Anleitung](https://all-inkl.com/en/support/tutorials/kas/tools/dns-tools/how-to-add-an-spf-record_482.html)
> vor — diesen übernehmen, nicht raten.

### 4c. DMARC
KAS → „Tools" → „DNS-Einstellungen" → TXT-Record, Name `_dmarc`, zunächst nur
beobachten:
```
v=DMARC1; p=none; rua=mailto:dmarc@deine-domain.de; fo=1
```
Wenn die Reports über einige Tage sauber sind (SPF/DKIM bestehen), schrittweise auf
`p=quarantine` und später `p=reject` verschärfen.
([DMARC-Anleitung](https://all-inkl.com/en/support/tutorials/kas/tools/dns-tools/how-to-add-a-dmarc-record_562.html))

---

## 5. Skalierung 100–300 Personen
- **Mengenmäßig unkritisch.** Einreihen <2 s, Zustellung ~1–2 min (mailserver-abhängig).
- all-inkl-Limit „3 Verbindungen / gestaffelt" wird durch den einzelnen Worker eingehalten.
- **Lastverteilung ist günstig:** Die rechenintensive **PDF-Erzeugung (mPDF)** läuft
  in **Stufe 2**, also einzeln pro Rücklauf über Tage verteilt — nicht im Stufe-1-Burst.
  Stufe 1 sind reine Text-Mails (leicht).
- Bei künftig deutlich größeren Mengen: externen Anbieter (Abschnitt 3b) bzw.
  Symfony-Messenger-Rate-Limiter erwägen.

---

## 6. Statussemantik & Fehlerüberwachung
Die drei Schritte heißen **Importiert → Eingeladen → Beantwortet** und sind fest; die
Bezeichnungen lassen sich nicht mehr pro Workflow ändern.

- Der Wechsel **Importiert → Eingeladen** erfolgt **erst nach dem tatsächlichen Versand**,
  nicht beim Einreihen (siehe Abschnitt 1). Ein Fehlversand lässt den Schritt **unverändert**
  und erscheint in der Übersicht als **„Versandfehler"**.
- **Gesperrte Adressen („Unzustellbar").** Eine Adresse wird gesperrt, wenn ein harter Bounce
  eintrifft, der Mailserver den Empfänger beim Versand endgültig ablehnt (SMTP 5xx mit
  Adress-Status 5.1.x beim `RCPT TO`) oder die Adresse ungültig geschrieben ist. Gesperrte
  Adressen bekommen keine Einladungen/Erinnerungen mehr – auch nicht nach „Teilnehmer
  zurücksetzen" –, bis die Adresse am Eintrag geändert wird. Infrastrukturfehler (Server nicht
  erreichbar, Anmeldung, „zu viele Verbindungen", 4xx, ungültiger Absender) sperren **nicht**;
  sie bleiben „Versandfehler" und werden beim nächsten Lauf erneut versucht.
- **Scheitert eine Mail schon beim Einreihen** (z. B. die Absenderadresse der Vorlage löst sich
  nicht auf), steht der Fehler sofort am Eintrag. Bis 3.4.1 wurde so eine Mail als „eingereiht"
  verbucht und löste die Warnung „… seit über 15 Minuten eingereiht" aus, obwohl der Cron lief.
  Solche Altlasten löst ein Cronjob (alle 15 Minuten) auf, sobald sie älter als eine Stunde
  sind: Ist die Empfängeradresse ungültig geschrieben (z. B. `name@hotmail` ohne Endung), wird
  die Zeile sofort als fehlgeschlagen markiert und die Adresse gesperrt. Alle anderen werden als
  fehlgeschlagen markiert, sobald die Mail-Queue leer ist – dann kann keine Mail mehr unterwegs
  sein –, und der Eintrag zeigt den Fehler. Das System-Log vermerkt beides.
- Dauerhaft fehlgeschlagene Mails wandern nach Retries in den Transport
  **`contao_failure`**. Diese Personen stehen weiterhin auf **„Importiert"** und haben nichts
  erhalten. Regelmäßig prüfen:
  ```
  contao-console messenger:stats
  contao-console messenger:consume contao_failure --limit=10   # erneut zustellen
  ```
- Die Liste **„Offene Vorgänge"** in der Übersicht ist die operative Kontrollansicht: Sie
  zeigt jeden Teilnehmer, der den letzten Schritt **nicht fehlerfrei** erreicht hat – noch
  nicht beantwortet, Zustellproblem, offene Bestätigung oder manuell zurückgesetzt. Ist sie
  leer, ist der Durchlauf sauber abgeschlossen.

---

## 7. Sicherheit & Datenhaltung
- Generierte PDFs liegen unter `%kernel.project_dir%/var/workflow_pdfs/<workflowId>/`
  — **außerhalb** des Web-Roots, nur über authentifizierte Backend-Routen abrufbar.
- Der PDF-Mailanhang wird als **Notification-Center-„Bulky Item"** zwischengespeichert
  (eigener, nicht-öffentlicher Speicher mit signierten URLs) und nach der Retention
  (Standard 7 Tage) automatisch bereinigt.

---

## 8. Validierung vor dem Echtbetrieb (Checkliste)
- [ ] KAS-Cronjob auf `/_contao/cron` aktiv (Worker läuft).
- [ ] `MAILER_DSN` in `.env.local` gesetzt; NC-Absender = echte Domain.
- [ ] DKIM in KAS aktiviert; SPF (genau einer) und DMARC als DNS-Records gesetzt.
- [ ] Testmail an **mail-tester.com** → SPF/DKIM/DMARC = pass, niedriger Spam-Score.
- [ ] Empfangene Mail-Header zeigen `dkim=pass`, `spf=pass`, `dmarc=pass`.
- [ ] Test-Workflow mit wenigen echten Adressen: Einladung → Link → Formular →
      PDF/Ergebnis-Mail → `messenger:stats` zeigt `contao_failure = 0`.

---

## 9. Troubleshooting
| Symptom | Ursache / Lösung |
|---|---|
| „Eingereiht", aber keine Mail / Schritt bleibt „Importiert" | Kein Worker/Cron aktiv → KAS-Cronjob auf `/_contao/cron` (Abschnitt 2). Der Schritt wechselt erst nach echtem Versand. Die Statuszeile der Übersicht zeigt, wann der Cronjob zuletzt aufgerufen wurde. |
| Back-End-Seite lädt gelegentlich sehr lange (z. B. 30 s) | System-Log zum Zeitpunkt ansehen. „Langsamer Seitenaufbau …" = die Seite selbst war langsam. „Hintergrundarbeit nach … – darin: …" = ein vorheriger Aufruf hat den PHP-Prozess belegt (meist Cronjob bei Seitenaufrufen → `web_listener: false`, Abschnitt 2a). Kein Eintrag (Schwelle 10 s) = die Zeit verging außerhalb von PHP, also beim Hosting. |
| Bounces werden mal verarbeitet, mal nicht; erst der nächste Klick zeigt den neuen Stand | Kein Cronjob: Contao arbeitet dann nur am Ende von Seitenaufrufen, **nach** deren Auslieferung (Abschnitt 2a). KAS-Cronjob einrichten; zum Testen „Jetzt prüfen". |
| Warnung „… seit über 15 Minuten eingereiht", obwohl der Cronjob läuft | Mail ist beim Einreihen gescheitert (Altlast bis 3.4.1), meist wegen einer ungültig geschriebenen Adresse; im System-Log steht dann „An email must have a "To", "Cc", or "Bcc" header". Löst sich nach einer Stunde von selbst (Abschnitt 6). |
| Versand wird gar nicht erst angeboten / abgelehnt | Workflow nicht **veröffentlicht**, keine gültige Formularseite oder keine gültige Benachrichtigung zugeordnet. |
| Mails landen im Spam | SPF/DKIM/DMARC fehlen oder Absender-Domain ≠ signierte Domain (Abschnitt 3a/4). |
| Versand bricht ab / Verbindungsfehler | all-inkl 3-Verbindungen-Limit → nur **einen** Worker betreiben; autoscale deckeln (Abschnitt 2c). |
| Einzelne Personen ohne Mail | Transport `contao_failure` prüfen (Abschnitt 6). |
| PDF fehlt im Anhang | NC-Notification „Ergebnis": unter „Anhänge über Tokens" muss `##attachment##` stehen. |

---

### Quellen (all-inkl)
- [Cronjobs einrichten](https://all-inkl.com/en/support/tutorials/kas/tools/cronjobs/setup_479.html)
- [E-Mail-Konto anlegen](https://all-inkl.com/en/support/tutorials/kas/email/email-address-autoresponder-forwarding/how-to-create-an-email-account_98.html)
- [SMTP-Authentifizierung](https://all-inkl.com/wichtig/anleitungen/programme/e-mail/thunderbird/smtp-authentifizierung-aktivieren_449.html)
- [SPF-Record](https://all-inkl.com/en/support/tutorials/kas/tools/dns-tools/how-to-add-an-spf-record_482.html)
- [DKIM-Record](https://all-inkl.com/en/support/tutorials/kas/tools/dns-tools/how-to-add-a-dkim-record-in-case-of-using-an-external-email-server_444.html)
- [DMARC-Record](https://all-inkl.com/en/support/tutorials/kas/tools/dns-tools/how-to-add-a-dmarc-record_562.html)
