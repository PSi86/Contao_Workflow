# Changelog

Alle nennenswerten Änderungen an diesem Bundle. Format angelehnt an
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/); Versionierung nach
[SemVer](https://semver.org/lang/de/).

## [2.0.0] – 2026-06-04

Großer Umbau: Umbenennung **Trainer → Workflow** auf allen Ebenen (Code, DB, UI)
plus zahlreiche neue Funktionen und Korrekturen. **Breaking** durch die
Umbenennung von Paket, Namespace und DB-Tabellen.

### Breaking – Umbenennung Trainer → Workflow
- Paket `psimandl/contao-trainer-workflow` → **`psimandl/contao-workflow`**;
  Verzeichnis `contao-trainer-workflow/` → **`contao-workflow/`**.
- Namespace `Psimandl\TrainerWorkflowBundle` → **`Psimandl\WorkflowBundle`**;
  Bundle-Klasse `ContaoTrainerWorkflowBundle` → **`ContaoWorkflowBundle`**.
- DB-Tabellen `tl_trainer_workflow/_entry/_question/_rule/_master` →
  **`tl_workflow`, `tl_workflow_entry`, `tl_workflow_question`, `tl_workflow_rule`, `tl_workflow_master`**.
- CLI-Befehle `trainer:import|send|export` → **`workflow:import|send|export`**.
- Backend-Routen `/contao/trainer` + `trainer_*` → **`/contao/workflow`** + `workflow_*`.
- BE-Module `trainer/_overview/_workflow/_master` → **`workflow/workflow_overview/workflow_manage/workflow_master`**.
- FE-Modul-Typ `trainer_form` → **`workflow_form`**; Templates `be_trainer_dashboard`/`mod_trainer_form`
  → **`be_workflow_dashboard`/`mod_workflow_form`**; Assets `trainer-*.{css,js}` → **`workflow-*`**;
  Asset-Bundle `bundles/contaotrainerworkflow` → **`bundles/contaoworkflow`**.
- Notification-Center-Typ `trainer_workflow` → **`workflow`**.
- PDF-Speicherpfad `var/trainer_pdfs/` → **`var/workflow_pdfs/`**.
- **Migration** `RenameTrainerToWorkflowMigration` benennt die fünf Tabellen um, aktualisiert
  `tl_nc_notification.type` und `tl_module.type`, schreibt `tl_workflow_entry.pdfPath` um und
  verschiebt das PDF-Verzeichnis. Läuft im ersten Migrations-Pass vor dem Schema-Diff.
- DDEV-Projekt `trainer-workflow` → **`workflow`** (URL `https://workflow.ddev.site`),
  Demo-Seiten-Alias `trainer` → `workflow` (Formular-Link `/workflow/<token>`).

### Added – einheitliche Platzhalter
- **`Service\PlaceholderResolver`** als einzige Token-Quelle für PDF, E-Mail und Export:
  kanonische, überall identische Platzhalter **`##data_<slug>##`** (Quellspalten inkl.
  gespeicherter Antwortwerte) und **`##var_<slug>##`** (Briefkopf-Variablen). `<slug>` =
  kleingeschrieben, deutsche Umlaute transliteriert (ä→ae, ö→oe, ü→ue, ß→ss), Rest → `_`
  (z. B. „davon Spende" → `##data_davon_spende##`). Im PDF gilt zusätzlich der Rohspaltenname
  (`##Spalte##`) als Alias; in Mails nur die kanonische Form (NC-Token ohne Leerzeichen).

### Added – Antwortfelder & PDF
- Neuer Antwortfeld-Typ **„Aktuelle Zeit" (`currentTime`)**: wird beim Absenden serverseitig
  automatisch mit dem aktuellen Datum gefüllt (ignoriert das Formular). Zusatzoption
  **„Feld im Formular ausblenden" (`hideInForm`)**; bei diesem Typ entfällt „Pflichtfeld".
- Workflow-Felder **`pdfSignatureDate`** (Datum) und **`pdfSignatureLocation`** (Ort, z. B. Wohnort
  der Person) speisen die Unterschriftszeile aus echten Datenfeldern. Beide liegen in der
  Subpalette von **„Unterschrift verlangen"** und sind nur sichtbar, wenn diese aktiv ist.
- **Konfigurierbarer PDF-Dateiname (`pdfFileName`)** mit Platzhaltern
  (z. B. `Verzicht_##data_name##_##data_vorname##`); zu einem sicheren Dateinamen bereinigt,
  bei Namensgleichheit wird ein kurzer Token angehängt; erneute Erzeugung überschreibt die
  eigene Datei. Leer = Eintrags-Token.

### Added – Validierung & Kopieren
- **`Service\WorkflowValidator`** + **`WorkflowIntegrityListener`**: ein Workflow ohne (lesbare)
  Quelldatei bzw. mit Spalten, die nicht zur Quelle passen (E-Mail-Spalte, Speicherfelder der
  Antwortfelder, Bedingungsfelder der PDF-Regeln), ist **nicht ausführbar**. Im Bearbeiten-Dialog:
  Info-Box + **rote Umrandung** der betroffenen Felder (inkl. Antwortfelder- und PDF-Regeln-Liste),
  Warnung beim Speichern. Import/Versand werden gesperrt; in der Übersicht erscheinen Badge +
  deaktivierte Aktionen. Im Regel-Dialog zeigt das „Antwortfeld"-Dropdown unbekannte Werte als
  **„Unbekannte Option: …"**.
- **Workflow kopieren** übernimmt jetzt Antwortfelder **und** PDF-Regeln, **nicht** aber Quelldatei
  und importierte Einträge; die Kopie startet **unveröffentlicht** (über Contao-Bordmittel:
  `ctable` + `doNotCopyRecords` + `eval.doNotCopy`). Eine Kopie greift nie auf die PDFs des
  Originals zu (eigene ID → eigenes Verzeichnis).

### Added – Dashboard / Übersicht
- **Ausstehende-Antworten-Liste**: zeigt zusätzlich **Name/Vorname** (falls vorhanden), ist je
  Spalte **sortierbar**, hat eine **Checkbox je Zeile**, Massenauswahl **„Alle"/„Alle aufheben"**
  und je Workflow-Schritt (außer dem letzten) einen **Auswahl-Button**, der alle Einträge dieses
  Status selektiert.
- **Ein** Button **„E-Mails senden"** statt zwei: öffnet einen Dialog mit **„Automatisch"/„Manuelle
  Auswahl"** und den Schaltflächen **„Einladungen senden"/„Erinnerungen senden"** samt Live-Anzahl;
  danach **Bestätigungsschritt** mit der konkreten Empfängerliste. Serverseitig eine
  `workflow_send`-**POST**-Route (`type=invite|reminder`, optional `ids[]`).
- **Warnung „kein Import ausgeführt"** für einen konfigurierten, lauffähigen Workflow ohne Einträge.
- Jeder Workflow wieder als **eigene Karte** (`.wf-box`), mit Contao-Theme-Variablen → **Dark-Mode-fest**.
- Workflow-Liste **neueste zuerst** als flache Liste (mode 1, `tstamp DESC`, `disableGrouping`).

### Added – TSV-Briefkopf (Master-Vorlage)
- `pdf_master.html5` neu als TSV-Briefkopf: blaue **Kopfzeile** „TSV Korntal e.V. • Jahnstraße 1 • …"
  oben links, Logo oben rechts, blaue Linie darunter; **4-spaltige blaue Fußzeile** (Anschrift,
  Vorstände, Kontakt, Bankverbindungen) – umgesetzt als echte **mPDF-Lauf-Kopf/Fußzeile**
  (`<htmlpageheader>/<htmlpagefooter>`, dazu Seitenränder in `PdfGenerator::renderPdf`).
  **Signaturzeile gespiegelt**: links „<Ort>, <Datum>" über der Linie + Label „Ort, Datum",
  rechts Unterschriftsbild über der Linie + „Unterschrift <Name>".

### Added – Demo
- Zwei zusätzliche Demo-Workflows aus den TSV-Vorlagen: **„EStG Übungsleiter"** (§ 3 Nr. 26 EStG)
  und **„Verzicht Ehrenamtspauschale"** (je eine `isDefault`-Regel, Signatur-Formular,
  verstecktes „Aktuelle Zeit"-Datumsfeld). `scripts/configure-demo-basistabelle.php` legt nun drei
  Workflows idempotent (nach Titel) an.

### Changed
- PDF-Brieftexte/Überschriften nutzen die kanonischen `##data_*##`/`##var_*##`-Tokens; der implizite
  Token **`##datum##`** und das automatische „aktuelle Datum" im PDF wurden **entfernt** – das
  gedruckte Datum kommt aus `pdfSignatureDate`, der Ort aus `pdfSignatureLocation` (PDF == DB == Export).
- `NotificationDispatcher` liefert E-Mail-Tokens über den `PlaceholderResolver` (zusätzlich `##var_*##`).

### Removed
- Legacy-Spalten der alten fixen Ja/Nein-Logik (`labelAccept`, `labelReject`, `decisionField`,
  `dateField`, `pdfBody`, `pdfBodyReject` an `tl_workflow`; `decision` an `tl_workflow_entry`) per
  **`DropLegacyColumnsMigration`** entfernt → die „Workflow-Details" (`act=show`) zeigen keine
  Altfelder mehr. `ConfigurableAnswersMigration` entfällt.

### Fixed
- **500-Fehler** „Call to undefined method `Contao\Message::addWarning()`" beim Speichern eines
  nicht-lauffähigen Workflows (u. a. `Kopfzeile` per submitOnChange) → `addInfo`.
- Demo-PDF: nicht aufgelöste Platzhalter (Tokens auf reale Spalten + kanonisches Schema umgestellt).
- PDF nutzte das **aktuelle Datum** statt des gespeicherten Antwortdatums → jetzt aus dem Datenfeld.
- Kopie verlor die **Antwortfelder** bzw. schleppte Quelldatei/Einträge mit.
- Sortier-Liste der Übersicht **sprang** beim Sortieren (Sortierpfeil in festem Slot hinter der
  Überschrift).
- **Dark Mode**: weiße Boxen mit unleserlichem Text in der Übersicht (Theme-Variablen statt
  fester Farben).
- Vertikale Ausrichtung/Beschriftung der Aktions-Buttons in der Übersicht.

[2.0.0]: #
