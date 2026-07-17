# Implementierungsplan: Zuverlässige Versanderkennung inkl. Bounce-Handling

**Repo:** `psimandl/contao-workflow` (Contao 5.3, NC 2.x, PHP 8.1+)
**Ziel:** Pro Einzelmail zuverlässig erkennen, ob sie zugestellt wurde — inkl. asynchroner
Bounces (DSN), die derzeit komplett unbemerkt bleiben.

---

## 0. Ground Truth (verifiziert, nicht neu ermitteln)

Diese Punkte sind auf dem Produktivsystem (All-Inkl, `dd36332.kasserver.com`) an echten
Mails nachgewiesen. **Nicht in Frage stellen, darauf aufbauen.**

### 0.1 Was `wasDelivered()` bedeutet

```
MailerGateway::doSendParcel() → $mailer->send()   // async: nur enqueue, wirft nie
  └─ Worker: EsmtpTransport::send()
     ├─ Erfolg → SentMessageEvent   ─┐
     └─ Fehler → FailedMessageEvent ─┤
                                     ↓
     NC\MailerAsynchronousReceiptUpdateListener::handleEmail()
       └─ AsynchronousReceiptEvent → WorkflowMailResultListener
```

`$receipt->wasDelivered() === true` heißt ausschließlich: **der Submission-Server hat mit
`250 OK` angenommen.** Der `550` des Empfänger-MTA kommt Minuten später als DSN an den
Return-Path und erzeugt **kein Event**. Das ist die Lücke, die dieser Plan schließt.

### 0.2 Envelope & Korrelation (bewiesen)

- NC setzt weder `Sender:` noch `Return-Path:` → Symfony leitet den Envelope-Sender aus
  `From` ab. `ContaoMailer::setFrom()` greift nicht (kein Transport mit `from` konfiguriert).
- Zugestellte Mail trägt `Return-Path: <noreply@tsvkorntal.com>` = `From`. Kein Rewrite
  durch kasserver.
- **`Notification-Center-Parcel-ID: <64 hex>` ist im zugestellten Original vorhanden**
  (NC setzt ihn in `MailerGateway::createEmail()` vor `$mailer->send()`, entfernt ihn erst
  im `SentMessageEvent` — also nach dem Transport).
- **Der Header ist im `message/rfc822`-Teil des Bounces enthalten.**
  → **Kein VERP, kein Catch-All, keine Plus-Adressierung nötig.** Korrelation läuft über
  die Parcel-ID.
- Gmail: `spf=pass`, `dkim=pass`, `dmarc=pass`. Zustellbarkeit ist kein Thema.

### 0.3 Struktur eines echten Bounces (Fixture)

```
Return-Path: <>
From: Mail Delivery System <MAILER-DAEMON@dd36332.kasserver.com>
To: noreply@tsvkorntal.com
Auto-Submitted: auto-replied
Content-Type: multipart/report; report-type=delivery-status; boundary="..."

├─ text/plain              (menschenlesbarer Text)
├─ message/delivery-status
│    Block 0 (per-message):   Reporting-MTA, X-Postfix-Queue-ID, X-Postfix-Sender, Arrival-Date
│    Block 1 (per-recipient): Final-Recipient, Original-Recipient, Action, Status,
│                             Remote-MTA, Diagnostic-Code
└─ message/rfc822          (Original inkl. Notification-Center-Parcel-ID)
```

### 0.4 ⚠️ Fallstrick, der im Fixture belegt ist

```
Action: failed
Status: 5.0.0                                   ← generisch!
Diagnostic-Code: smtp; 550-5.1.1 <...>: Recipient address rejected: 550 User unknown
```

Postfix schreibt **`Status: 5.0.0`**, obwohl der Remote-MTA `5.1.1` gemeldet hat.
→ **Nur die erste Ziffer von `Status:` auswerten** (5 = permanent, 4 = temporär).
Ein Parser, der auf `5.1.1` matcht, findet nichts. Der `Diagnostic-Code` ist reiner
Anzeigetext, keine Entscheidungsgrundlage.

---

## 1. Rahmenbedingungen

| Punkt | Vorgabe |
|---|---|
| Contao | 5.3, PHP ^8.1 |
| IMAP | **`webklex/php-imap`** (reines PHP). **Nicht** `ext-imap` (seit PHP 8.4 nicht mehr im Core), **nicht** `ddeboer/imap` (braucht ext-imap) |
| Bounce-Parsing | Eigener Parser auf `symfony/mime`. Keine Fremd-Bibliothek |
| Fremdcode | Keine Änderungen an NC oder Contao-Core. Nur Erweiterungspunkte |
| Migrationen | `Contao\CoreBundle\Migration\AbstractMigration` (Stil der vorhandenen 9 Migrationen) |
| Code-Stil | `declare(strict_types=1)`, readonly promoted properties, DBAL `Connection` für Schreibzugriffe, Models für Lesezugriffe — wie bisher |
| Sprache | Code/Docblocks Englisch, Backend-Labels & CHANGELOG Deutsch |
| Tests | Unit-Tests für Parser + Statusmaschine, **ohne** IMAP und ohne DB |

---

## AP1 — Bugfix: Korrelation nicht bei Fehler konsumieren

**Priorität: sofort.** Kleinster Fix, größter Schaden, wenn er fehlt.

**Datei:** `src/EventListener/Mailer/WorkflowMailResultListener.php`

Am Ende von `onAsynchronousReceipt()` steht bedingungslos:

```php
// Correlation consumed.
$this->connection->executeStatement(
    "UPDATE tl_workflow_entry SET sendParcelId = '', sendKind = '' WHERE id = ?", [$entryId],
);
```

**Fehlerfall:** Contao setzt keine `retry_strategy` → Symfony-Defaults (`max_retries: 3`).
Versuch 1 scheitert (All-Inkl: max. 3 SMTP-Verbindungen) → `FailedMessageEvent` → Korrelation
gelöscht. Retry gelingt → `SentMessageEvent` mit derselben Parcel-ID → Lookup findet nichts,
`$this->context` ist im Worker leer → `return;`. **Mail ist raus, Eintrag bleibt auf Status 0
mit Versandfehler.** Nächster Lauf schickt eine zweite Einladung.

**Fix:** Korrelation nur im `onDelivered()`-Zweig abräumen — bei Fehler stehen lassen.
Mit AP2 entfällt das Abräumen ohnehin.

**Akzeptanz:** Test, der zweimal `AsynchronousReceiptEvent` mit derselben Identifier feuert
(erst failed, dann delivered) und prüft, dass der Eintrag danach auf „eingeladen" steht und
`sendError` leer ist.

---

## AP2 — Korrelationstabelle `tl_workflow_send`

Ersetzt `tl_workflow_entry.sendParcelId` / `.sendKind`. Löst drei Probleme auf einmal:

1. **Nur ein Parcel-Slot pro Eintrag** — `sendResult()` überschreibt die noch offene
   Einladungs-ID, deren Receipt danach ins Leere läuft (stiller Verlust).
2. **Verwaiste IDs im Sync-Pfad** — `rememberParcel()` läuft *nach* `sendNotification()`;
   synchron hat der Listener da schon über den Context-Fallback gearbeitet und die Spalten
   geleert, danach schreibt `rememberParcel()` die ID doch noch rein. Sie wird nie aufgeräumt.
3. **Bounce-Matching braucht die ID dauerhaft** — ein Bounce kommt Minuten bis Stunden nach
   dem Receipt. Aktuell wird die ID exakt dann gelöscht, wenn sie nützlich wird.

**Schema:**

```sql
CREATE TABLE tl_workflow_send (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tstamp      INT UNSIGNED NOT NULL DEFAULT 0,
    parcelId    VARCHAR(64)  NOT NULL DEFAULT '',
    entryId     INT UNSIGNED NOT NULL DEFAULT 0,
    workflowId  INT UNSIGNED NOT NULL DEFAULT 0,
    kind        VARCHAR(16)  NOT NULL DEFAULT '',   -- invite|reminder|result
    recipient   VARCHAR(255) NOT NULL DEFAULT '',
    state       VARCHAR(16)  NOT NULL DEFAULT '',   -- queued|sent|failed|bounced
    queuedAt    INT UNSIGNED NOT NULL DEFAULT 0,
    sentAt      INT UNSIGNED NOT NULL DEFAULT 0,
    bouncedAt   INT UNSIGNED NOT NULL DEFAULT 0,
    error       TEXT NULL,                           -- Transportfehler
    bounceCode  VARCHAR(255) NOT NULL DEFAULT '',    -- Diagnostic-Code, gekürzt
    PRIMARY KEY (id),
    UNIQUE KEY parcelId (parcelId),
    KEY entryId (entryId),
    KEY recipient_state (recipient, state)
) ENGINE=InnoDB;
```

**Zustandsmaschine:**

```
queued ──SentMessageEvent──→ sent ──DSN 5.x.x──→ bounced
   │                          │
   │                          └──DSN 4.x.x / Action: delayed──→ sent (nur loggen)
   └──FailedMessageEvent──→ failed ──Retry erfolgreich──→ sent
```

**Anpassungen:**

- `NotificationDispatcher::rememberParcel()` → `INSERT INTO tl_workflow_send`
  (state=`queued`, queuedAt=time()). Der Sync-Pfad-Konflikt aus (2) verschwindet, weil
  `INSERT … ON DUPLICATE KEY UPDATE` idempotent ist.
- `WorkflowMailResultListener` → `UPDATE tl_workflow_send SET state=… WHERE parcelId=?`.
  **Zeile niemals löschen.** Der Context-Fallback bleibt für den Sync-Pfad erhalten.
- `tl_workflow_entry.sendError` / `.sendErrorAt` **bleiben** als denormalisierte
  Anzeigefelder für das Dashboard, gespeist aus `tl_workflow_send`.
- Migration: `sendParcelId`/`sendKind` in die neue Tabelle übernehmen, Spalten anschließend
  droppen (Muster: `DropLegacyColumnsMigration`).

**Fallstrick für den Context-Fallback:** `WorkflowMailContext` ist ein Singleton ohne
Request-Scope. Feuert während der Sende-Schleife eine *fremde* NC-Notification synchron,
hat sie eine Parcel-ID, der DB-Lookup schlägt fehl und der aktive Context ordnet sie dem
falschen Eintrag zu. Guard: Notification-ID im Context mitführen und im Fallback prüfen.

---

## AP3 — Dashboard: „hängt in der Queue"

Der häufigste Produktivfehler (KAS-Cron läuft nicht, PHP-Prozess getötet) ist aktuell
**unsichtbar**: Eintrag Status 0, `sendError` leer, Dashboard zeigt „ausstehend" —
ununterscheidbar von „läuft gerade".

- Query: `tl_workflow_send WHERE state='queued' AND queuedAt < (time() - 900)`
- Dashboard-Box: „**N Mails seit über 15 Minuten eingereiht, kein Ergebnis** — läuft der
  Cron/Worker?" mit Link auf `docs/DEPLOYMENT.md` §2a.

**Datei:** `src/Controller/Backend/DashboardModule.php`, Template `be_workflow_dashboard.html5`

---

## AP4 — `BounceParser` (pure, ohne IMAP, unit-testbar)

**Datei:** `src/Service/Bounce/BounceParser.php`
**DTO:** `src/Service/Bounce/BounceReport.php`

```php
final class BounceReport
{
    public function __construct(
        public readonly ?string $parcelId,      // aus message/rfc822
        public readonly string $recipient,      // Final-Recipient, ohne "rfc822;"
        public readonly string $action,         // failed|delayed|delivered|relayed|expanded
        public readonly int $statusClass,       // 2|4|5  ← erste Ziffer von Status:
        public readonly string $status,         // roh, z. B. "5.0.0"
        public readonly string $diagnosticCode, // Anzeigetext
    ) {}

    public function isHardBounce(): bool { return 'failed' === $this->action && 5 === $this->statusClass; }
    public function isSoftBounce(): bool { return 4 === $this->statusClass || 'delayed' === $this->action; }
}
```

**Parser-Regeln (aus dem Fixture abgeleitet):**

1. **Erkennung:** top-level `Content-Type: multipart/report; report-type=delivery-status`.
   Alles andere → `null` zurückgeben, nicht raten. (`Return-Path: <>` und
   `Auto-Submitted: auto-replied` als sekundäre Signale.)
2. **`message/delivery-status`:** **nicht** über `walk()` iterieren — der Part enthält eine
   *Liste* von Header-Blöcken. Block 0 = per-message, Blöcke 1..n = **je ein Empfänger**.
   Ein Bounce kann mehrere Empfänger-Blöcke enthalten → `BounceReport[]` zurückgeben, nicht
   einen einzelnen.
3. **Entscheidung** ausschließlich über `Action:` + **erste Ziffer** von `Status:`.
   Siehe Ground Truth 0.4 — `Status: 5.0.0` bei einem echten `5.1.1`.
4. **Parcel-ID:** Header `Notification-Center-Parcel-ID` im `message/rfc822`-Teil.
   Fallback `text/rfc822-headers` (greift, wenn Postfix' `bounce_size_limit`, Default
   50000 Bytes, das Original abschneidet — bei der Ergebnis-Mail mit PDF-Anhang realistisch;
   Header stehen am Anfang, überleben also, aber der Content-Type kann wechseln).
5. **Letzter Fallback ohne Parcel-ID:** jüngste `tl_workflow_send`-Zeile mit
   `recipient = Final-Recipient AND state = 'sent'`. Protokollieren, dass geraten wurde.

**Tests:** Beide echten `.eml` nach `tests/Fixtures/` kopieren:
- `bounce-hard-550.eml` → erwartet: `parcelId=ab0ad4b1…`, `action=failed`, `statusClass=5`,
  `recipient=sdfdfsdfsd@wherever-we-are.com`
- `delivered-reminder.eml` → erwartet: `null` (kein DSN, darf nicht als Bounce durchgehen)
- Zusätzlich synthetisch: `Action: delayed` / `Status: 4.4.1`, Bounce mit zwei
  Empfänger-Blöcken, Bounce ohne `message/rfc822`-Teil.

---

## AP5 — `BounceCollector` (IMAP + Cron)

**Datei:** `src/Service/Bounce/BounceCollector.php`

```php
#[AsCronJob('*/15 * * * *')]
public function __invoke(): void
```

- **Konfiguration** über `.env.local`, nicht im Backend:
  `WORKFLOW_BOUNCE_IMAP_DSN=imap://noreply%40tsvkorntal.com:PASS@wXXXXXXX.kasserver.com:993?ssl=true`
  Leer/nicht gesetzt → Collector no-op, keine Exception.
- **Idempotenz:** verarbeitete Mails in Unterordner `INBOX/Processed` verschieben.
  Robuster als `\Seen`, weil ein Mensch im Webmail nichts kaputt macht.
- **Fehlertoleranz:** IMAP nicht erreichbar → `LoggerInterface::warning` + `return`.
  Der Cron darf niemals eine Exception werfen (sonst reißt er die restlichen Contao-Cronjobs mit).
- **Batch-Limit:** max. 100 Mails pro Lauf (Shared Hosting, PHP-Zeitlimit).
- **Mapping:**
  - `isHardBounce()` → `tl_workflow_send.state='bounced'`, `bounceCode`, `bouncedAt`;
    am Eintrag `sendError` für die Dashboard-Anzeige setzen
  - `isSoftBounce()` → nur loggen, **Status unangetastet**. (Ein `Action: delayed` nach
    ~4 h ist kein Fehler — der finale Bounce kommt ggf. Tage später.)
  - Nicht-DSN-Mail im Bounce-Postfach → in `Processed` verschieben, ignorieren

**Wichtige Designentscheidung:** Ein Hard Bounce setzt den Eintrag **nicht** auf Status 0
zurück. Sonst schickt der nächste „Einladungen senden"-Lauf erneut an die tote Adresse →
Bounce-Schleife → Reputationsschaden. Stattdessen AP6.

---

## AP6 — Suppression + Dashboard-Trennung

- **Neue Spalte** `tl_workflow_entry.bounceHard` (`char(1)`) + `bounceInfo` (`varchar(255)`).
- `EntryModel::findByWorkflowAndStatus()` → Einträge mit `bounceHard='1'` aus Einladungs-
  und Erinnerungsläufen **ausschließen**.
- **Dashboard:** eigene Box „**Ungültige Adressen**" (Empfänger + Diagnostic-Code),
  **getrennt** von „Versandfehler". Semantisch verschieden:
  - *Versandfehler* = Transportproblem, wiederholbar, löst sich ggf. von selbst
  - *Ungültige Adresse* = Adresse existiert nicht, braucht einen Menschen
- **DCA `tl_workflow_entry`:** `onsave_callback` auf `email` → bei Änderung der Adresse
  `bounceHard=''` und `bounceInfo=''` zurücksetzen. Sonst bleibt der korrigierte Eintrag
  für immer gesperrt.

---

## AP7 — `WorkflowValidator`: Absenderdomain prüfen

**Das war die Ursache des ganzen Problems.** Der Seed setzt `noreply@example.com`
(`DemoWorkflowSeeder.php:274,282,290`, `WorkflowConfigImporter.php:284`). Steht im NC eine
Absenderdomain ohne passenden MX — `example.com`, oder `.de` statt `.com` — wird der
Envelope-Sender unzustellbar und **jeder Bounce verschwindet lautlos**. Der Versand selbst
sieht dabei völlig gesund aus.

- Check in `src/Service/WorkflowValidator.php`: NC-Absenderdomain der drei referenzierten
  Notifications == Domain des Website-Roots? Sonst **Warnung** im Backend.
- Zusatzcheck: hat die Absenderdomain einen MX-Record? (`dns_get_record($domain, DNS_MX)`)
- Seed-Default nicht mehr hart auf `noreply@example.com`, sondern aus dem Website-Root
  ableiten (`tl_page.dns`), Fallback leer → NC greift dann auf `##admin_email##` zurück.

---

## AP8 — `autoscale` deckeln + Doku

Contaos Default (`manager-bundle/skeleton/config/config.yaml`, 5.3):

```yaml
contao:
  messenger:
    workers:
      - transports: [contao_prio_high]
        autoscale: { desired_size: 5, max: 10 }
```

`desired_size: 5` = ein Worker je 5 wartende Nachrichten. Bei 300 eingereihten Einladungen
→ 60 gewünscht → auf **10 parallele `messenger:consume`-Prozesse** gedeckelt. All-Inkl
erlaubt **3 gleichzeitige SMTP-Verbindungen**. `DEPLOYMENT.md` §3a sagt „nicht mehrere
Worker parallel betreiben" — die Default-Konfiguration tut aber genau das, sobald der
KAS-Cron `/_contao/cron` anstößt. Und genau dort feuert AP1.

- Empfehlung in `docs/DEPLOYMENT.md` §2a ergänzen:
  ```yaml
  # config/config.yaml — All-Inkl: max. 3 gleichzeitige SMTP-Verbindungen
  contao:
    messenger:
      workers:
        - transports: [contao_prio_high]
          options: ['--time-limit=55', '--sleep=5']
          autoscale: { enabled: false }
  ```
- Neuer Abschnitt „Bounce-Postfach" in `DEPLOYMENT.md`: Postfach anlegen, `WORKFLOW_BOUNCE_IMAP_DSN`
  setzen, **Spamfilter für dieses Postfach deaktivieren** (Bounces haben `MAIL FROM:<>`,
  manche Filter stufen genau das als verdächtig ein), Unterordner `Processed` anlegen.
- Neuer Abschnitt in `ANLEITUNG.md`: die drei Zustände im Dashboard und was sie bedeuten.

---

## Reihenfolge

| # | Paket | Aufwand | Warum zuerst |
|---|---|---|---|
| 1 | **AP1** Bugfix Korrelation | Minuten | Betrifft dich jetzt schon produktiv |
| 2 | **AP8** autoscale deckeln + Doku | Minuten | Verhindert, dass AP1 im Lasttest massenhaft feuert |
| 3 | **AP2** `tl_workflow_send` | ~½ Tag | Fundament für alles Weitere |
| 4 | **AP3** Queue-Warnung | ~1 h | Macht den häufigsten Fehler sichtbar |
| 5 | **AP4** BounceParser + Tests | ~½ Tag | Rein, testbar, kein Hosting nötig |
| 6 | **AP5** BounceCollector | ~½ Tag | Braucht AP2 + AP4 |
| 7 | **AP6** Suppression + Dashboard | ~½ Tag | Braucht AP5 |
| 8 | **AP7** Validator | ~2 h | Verhindert Wiederholung der Ursache |

AP4 lässt sich vollständig lokal gegen die Fixtures entwickeln — kein Hosting, keine DB.

---

## Was dieser Plan bewusst *nicht* löst

- **Silent Discard** — Empfänger nimmt an und verwirft. Kein Bounce, kein Signal. Prinzipiell
  nicht erkennbar.
- **Spam-Ordner-Zustellung** — zählt als zugestellt. SPF/DKIM/DMARC stehen (verifiziert),
  das Restrisiko ist Empfänger-Policy.
- **Positive Zustellbestätigung** — RFC 3461 `NOTIFY=SUCCESS` wird von Symfony Mailer nicht
  sauber unterstützt und von den meisten MTAs ignoriert. „Zugestellt" bleibt „kein Bounce
  innerhalb von N Tagen".

Deshalb sollte das Dashboard `sent` ehrlich als „**versendet, keine Fehlermeldung**"
beschriften — nicht als „zugestellt".

---

## Offene Punkte, bewusst vertagt

- **N1** Bounce der Ergebnis-Mail mit PDF-Anhang (`bounce_size_limit` → `text/rfc822-headers`
  statt `message/rfc822`). AP4 Regel 4 deckt beide Fälle ab, ein Realtest steht noch aus.
- **N2** Soft-Bounce/`Action: delayed` an echtem Material. AP4 behandelt es korrekt,
  der Fixture-Test ist synthetisch.
