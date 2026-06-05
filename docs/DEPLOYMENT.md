# Produktiv-Deployment & Mailversand (Workflow)

Leitfaden für den produktiven Betrieb des Workflow-Bundles, mit Fokus auf
den E-Mail-Versand (100–300 Empfänger pro Lauf) und das Hosting bei
**all-inkl.com (KAS)**. Installation siehe [INSTALL.md](INSTALL.md), Bedienung
[ANLEITUNG.md](ANLEITUNG.md).

> **Die zwei Dinge, die produktiv zwingend stimmen müssen:**
> 1. Ein **Worker/Cron** muss laufen, sonst werden Mails nur eingereiht, aber nie versendet.
> 2. **SPF + DKIM + DMARC** müssen für die Absender-Domain gesetzt sein, sonst landet
>    ein 300er-Schwung im Spam.

---

## 1. Wie der Mailversand funktioniert (wichtig zu verstehen)

Contao versendet E-Mails **asynchron über Symfony Messenger**. Beim Klick auf
„Einladungen senden" (bzw. `workflow:send`) passiert nur:

1. pro Empfänger wird eine Nachricht in die Queue-Tabelle **`tl_message_queue`**
   (Transport `contao_prio_high`) geschrieben,
2. der Eintrag wird auf Status `1` gesetzt.

Der **eigentliche SMTP-Versand** passiert erst, wenn ein **Worker** die Queue
abarbeitet. Ohne laufenden Worker bleibt alles in der Queue liegen — die Aktion
meldet trotzdem „versendet" (= erfolgreich **eingereiht**).

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
so nach wenigen Minuten draußen.
([all-inkl Cronjob-Anleitung](https://all-inkl.com/en/support/tutorials/kas/tools/cronjobs/setup_479.html))

> Verlass dich **nicht** auf den „Poor-Man's-Cron" (läuft nur bei zufälligen
> Seitenaufrufen) — für planbare Bursts ist der KAS-Cronjob zuverlässiger.

### 2b. VPS / eigener Server (Alternative)
Dauerhafter Worker via systemd/supervisor (wird vom Prozessmanager neu gestartet):

```
contao-console messenger:consume contao_prio_high contao_prio_normal contao_prio_low \
  --time-limit=3600 --memory-limit=256M
```

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
- Status `0→1` wird beim **Einreihen** gesetzt, **nicht** bei bestätigter Zustellung.
- Dauerhaft fehlgeschlagene Mails wandern nach Retries in den Transport
  **`contao_failure`**. Diese Personen stehen auf „eingeladen", haben aber nichts
  erhalten. Regelmäßig prüfen:
  ```
  contao-console messenger:stats
  contao-console messenger:consume contao_failure --limit=10   # erneut zustellen
  ```

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
| „Versendet", aber keine Mail kommt an | Kein Worker/Cron aktiv → KAS-Cronjob auf `/_contao/cron` (Abschnitt 2). |
| Mails landen im Spam | SPF/DKIM/DMARC fehlen oder Absender-Domain ≠ signierte Domain (Abschnitt 3a/4). |
| Versand bricht ab / Verbindungsfehler | all-inkl 3-Verbindungen-Limit → nur **einen** Worker betreiben. |
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
