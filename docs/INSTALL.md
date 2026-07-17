# Installation auf einer echten Contao-Installation

Diese Anleitung beschreibt die Installation des Bundles
`psimandl/contao-workflow` auf einer bestehenden **Contao 5.3+ Managed
Edition** – einmal **mit CLI** (SSH/Composer) und einmal **ohne CLI** (nur
Contao-Manager im Browser + FTP, z. B. auf all-inkl Shared Hosting).

Nach der reinen Installation folgt die fachliche Einrichtung (Notification Center,
Formularseite, erster Workflow) – die steht in [ANLEITUNG.md](ANLEITUNG.md), der
Mail-/Cron-Betrieb in [DEPLOYMENT.md](DEPLOYMENT.md).

---

## 0. Voraussetzungen

- **Contao 5.3+** (Managed Edition), **PHP 8.1+** (8.2/8.3 empfohlen), MySQL/MariaDB.
- **PHP-Erweiterungen:** `gd` (mPDF/Unterschrift – „PNG Support: ja" nötig), `zip`,
  `intl`, `mbstring`, `dom`, `fileinfo`, `curl`, `pdo_mysql`.
  - **`gd` prüfen ohne CLI:** Datei `info.php` mit `<?php phpinfo();` hochladen, im
    Browser öffnen, Abschnitt „gd" suchen, danach **wieder löschen**.
  - **mit CLI:** `php -r "var_dump(extension_loaded('gd'));"` bzw. `php -m | grep -i gd`.
  - `gd` fehlt? VPS/root: `apt install php8.x-gd` (+ FPM neu starten). Shared Hosting
    (all-inkl): meist vorhanden – sonst andere PHP-Version in KAS wählen oder Support
    fragen. Composer bricht ohne `gd` ohnehin mit „requires ext-gd … missing" ab.
- Das Bundle **zieht seine Abhängigkeiten automatisch mit**:
  `terminal42/notification_center`, `menatwork/contao-multicolumnwizard-bundle`,
  `mpdf/mpdf`, `phpoffice/phpspreadsheet`.
- Das Bundle **registriert sich selbst** (Contao-Manager-Plugin) – kein manuelles
  Bearbeiten von `config/bundles.php` o. Ä. nötig.

---

## 1. Bundle bereitstellen (Distribution)

Das Bundle liegt **nicht auf Packagist**. Wähle eine Verteilform:

| Methode | Wofür | Aufwand |
|---|---|---|
| **A) Artefakt-ZIP** | ohne CLI / Shared Hosting | kein Git nötig |
| **B) Git-Repository (VCS)** | mit CLI, saubere Updates | Git nötig |
| **C) Path-Repository** | nur lokale Entwicklung (DDEV) | — |

### Artefakt-ZIP bauen (für A)
Ein gültiges Composer-Artefakt ist ein ZIP des Bundles **mit `composer.json` im
ZIP-Wurzelverzeichnis**. Am saubersten mit Composer selbst (nutzt die feste `"version"`
aus der `composer.json` und legt `composer.json` an die Wurzel):

```bash
# im Bundle-Ordner (dort liegt die composer.json)
composer archive --format=zip --dir=../dist --file=psimandl-contao-workflow-2.0.0
# -> ../dist/psimandl-contao-workflow-2.0.0.zip
```

Ohne Composer zur Hand tut es auch ein einfaches ZIP des Ordnerinhalts (composer.json
muss an der ZIP-Wurzel liegen, `vendor/` weglassen):

```powershell
Compress-Archive -Path contao-workflow\* -DestinationPath psimandl-contao-workflow-2.12.0.zip -Force
```

Das ZIP enthält dank der festen `"version"` in der `composer.json` eine auflösbare Version –
der Dateiname muss zu ihr passen (hier `2.12.0`). Bequemer und weniger fehleranfällig ist
`scripts/build-bundle.ps1`: das Skript liest die Version aus der `composer.json` und schließt
`vendor/`, `tests/` usw. automatisch aus.

---

## 2. Variante MIT CLI (SSH / Composer)

Im Projekt-Root der Contao-Installation (dort liegt die `composer.json`):

**Schritt 1 – Repository eintragen** (eine der Varianten):

```bash
# A) Artefakt: ZIP zuvor nach <projekt>/packages/ hochladen
composer config repositories.workflow artifact ./packages

# B) Git/VCS
composer config repositories.workflow vcs https://github.com/<user>/contao-workflow.git
```

**Schritt 2 – Paket installieren** (zieht NC, mPDF, PhpSpreadsheet mit):

```bash
composer require psimandl/contao-workflow:^2.0 terminal42/notification_center:^2.0
```

**Schritt 3 – Datenbank + Assets:**

```bash
vendor/bin/contao-console contao:migrate --no-interaction   # legt tl_workflow_* und tl_nc_* an
vendor/bin/contao-console contao:setup                      # Bundle-Assets (Signatur-JS/CSS) veröffentlichen
```

**Schritt 4 – Mail-Worker/Cron** einrichten (Mails sind asynchron) –
siehe [DEPLOYMENT.md](DEPLOYMENT.md), Abschnitt „Worker/Cron".

---

## 3. Variante OHNE CLI (Contao-Manager + FTP)

Geeignet für Shared Hosting (all-inkl) ohne SSH. Der Contao-Manager führt die
Composer-Schritte **im Browser** aus.

**Schritt 1 – Dateien per FTP hochladen**
- Artefakt-ZIP nach `<projekt>/packages/` hochladen (Ordner `packages` ggf. anlegen).

**Schritt 2 – `composer.json` per FTP ergänzen**
Der Contao-Manager verwaltet nur Packagist-Pakete; eine **eigene Repository-Quelle**
muss in der `composer.json` des Projekts stehen. Datei herunterladen, ergänzen,
hochladen:

```jsonc
{
    "repositories": {
        "workflow": { "type": "artifact", "url": "packages" }
    },
    "require": {
        "psimandl/contao-workflow": "^2.0",
        "terminal42/notification_center": "^2.0"
    }
}
```
*(Die beiden `require`-Zeilen in den bestehenden `require`-Block einfügen, nicht
doppelt anlegen. Auf gültiges JSON achten – Kommas!)*

**Schritt 3 – Contao-Manager öffnen**
`https://deine-domain.de/contao-manager.phar.php` → einloggen.
- **System → „Pakete aktualisieren" / „Update"** ausführen. Der Manager lädt das
  Bundle + Abhängigkeiten und veröffentlicht die Assets automatisch
  (Composer-Scripts laufen mit).

**Schritt 4 – Datenbank aktualisieren (ohne CLI)**
- Entweder direkt im **Contao-Manager** den angebotenen Schritt
  **„Datenbank-Migrationen ausführen"** bestätigen,
- **oder** das **Install-Tool** öffnen: `https://deine-domain.de/contao/install`
  → einloggen → **„Datenbank aktualisieren"** ausführen.
  Das legt `tl_workflow`, `tl_workflow_question`, `tl_workflow_rule`,
  `tl_workflow_entry` und die `tl_nc_*`-Tabellen an.

**Schritt 5 – Mail-Worker/Cron** ohne CLI: KAS-Cronjob auf
`https://deine-domain.de/_contao/cron` (jede Minute) – siehe [DEPLOYMENT.md](DEPLOYMENT.md).

> **Hinweis:** Würde man das Bundle auf (öffentliches) **Packagist** veröffentlichen,
> entfiele Schritt 1–2: dann ließe es sich im Contao-Manager direkt über
> „Paket hinzufügen" per Name installieren.

---

## 4. Nach der Installation (beide Varianten)

Installiert ist erst die Software. Es fehlt die fachliche Einrichtung:
1. **Notification Center:** E-Mail-Gateway + Notifications anlegen ([ANLEITUNG.md](ANLEITUNG.md) Abschnitt 2).
2. **Formularseite + Modul** „Workflow-Formular" ([ANLEITUNG.md](ANLEITUNG.md) Abschnitt 1).
3. **Mail-Worker/Cron** aktiv ([DEPLOYMENT.md](DEPLOYMENT.md)).
4. **Ersten Workflow** anlegen ([ANLEITUNG.md](ANLEITUNG.md) Abschnitt 3).

Prüfen: Im Backend erscheint links die Modulgruppe **„Workflow"** mit
„Übersicht" und „Workflows".

**Demo-Workflow:** Bei der Erstinstallation wird automatisch ein **synthetischer**
Demo-Workflow („Musterverein", `@example.org`) inkl. fünf Beispiel-Teilnehmern angelegt – zum
Erkunden **und direkt Ausprobieren**: er bringt E-Mail-Vorlagen (Notification Center) und eine
**Formularseite** gleich mit – **im Menü versteckt**, ein **vorhandenes Site-Layout erbend**,
Modul per Inhaltselement. Updates legen ihn **nicht** erneut an; in der **Übersicht** kann er per
Button **„Demo-Workflow wiederherstellen"** neu erzeugt werden. Idempotent und **ohne** bestehende
Seiten/Layouts/Dateien zu verändern.

---

## 5. Updates

**Mit CLI:**
```bash
composer update psimandl/contao-workflow
vendor/bin/contao-console contao:migrate --no-interaction
```
**Ohne CLI:** neues Artefakt-ZIP (höhere Version, z. B. `…-2.1.0.zip`) nach
`packages/` hochladen, Versionsconstraint in `composer.json` ggf. anpassen, im
Contao-Manager „Update" ausführen, danach Install-Tool → „Datenbank aktualisieren".

*(Bei VCS: Git-Tag erhöhen, dann `composer update` bzw. Manager-Update.)*

---

## 6. Troubleshooting

| Symptom | Ursache / Lösung |
|---|---|
| Manager/Composer findet das Paket nicht | `repositories`-Eintrag in `composer.json` prüfen; ZIP wirklich in `packages/`? Dateiname/Version korrekt? |
| „Could not find a version …" | feste `"version"` im Bundle vorhanden? Constraint `^2.0` passend? |
| Backend-Module fehlen nach Install | Migration nicht gelaufen → Install-Tool „Datenbank aktualisieren" bzw. `contao:migrate`. |
| Formular ohne Stil / Signaturfeld lädt nicht | Assets nicht veröffentlicht → `contao:setup` bzw. Manager-Update erneut; Ordner `public/bundles/contaoworkflow/` vorhanden? |
| PDF-Fehler (mPDF) | PHP-Erweiterung **`gd`** aktivieren (all-inkl: passende PHP-Version wählen). |
| Mails kommen nicht an | Worker/Cron nicht aktiv → [DEPLOYMENT.md](DEPLOYMENT.md). |
