# Changelog

Alle nennenswerten Änderungen an diesem Bundle. Format angelehnt an
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/); Versionierung nach
[SemVer](https://semver.org/lang/de/).

## [2.4.0] – 2026-06-12

### Hinzugefügt
- **Formular/PDF-Parität über Dokument-Texte (Textbausteine).** Formular und PDF nutzen
  dieselben Texte: Auswahl-Optionen tragen einen optionalen **Dokument-Text** (leer = der
  sichtbare Options-Text gilt wörtlich), Wert-Felder (Freitext, Zahl, Datum, Aktuelle Zeit)
  ein Satz-Template mit `##value##` (leer = „Beschriftung: Wert"). Das Formular zeigt den
  Text live unter dem Feld („So erscheint dies im Dokument"). Neue Platzhalter
  `##stmt_<speicherfeld>##` und `##stmt_all##` (alle Felder in Formular-Reihenfolge; Felder
  mit eigenem Dokument-Text beginnen als eigener Absatz) – identisch in PDF-Texten,
  Notification-Center-Mails und Body-Vorlagen (`$this->statements`). Der Dokument-Body wird
  zentral im neuen `DocumentBodyComposer` gerendert.
- **Überschrift & Einleitungstext für Formular und PDF.** Die Überschrift (bisher nur PDF)
  erscheint jetzt auch oben im Formular; dazu ein optionaler **Einleitungstext** nach der
  Überschrift in beiden. Beide stehen im neuen Workflow-Abschnitt **„Inhalt (Formular & PDF)"**;
  Body-Vorlagen erhalten sie als `$this->heading`/`$this->intro`.
- **Antwortfeld-Optionen „Mit Wert aus den Daten vorbelegen" und „Schreibgeschützt".**
  Vorbelegen füllt das editierbare Feld mit dem gespeicherten Wert (Outputfeld = Inputfeld;
  unpassende Werte bei Auswahlfeldern bleiben leer, das Backend warnt). Schreibgeschützt
  zeigt den Wert nur an (jeder Typ; ersetzt die bisherigen Workflow-„Anzeige-Felder" –
  eine Migration wandelt sie automatisch in schreibgeschützte Antwortfelder um).
- **Neuer Antwortfeld-Typ „Zahl"** (Zahleneingabe inkl. Dezimalwerte, automatische
  Komma-Konvertierung).
- **Antwortfelder per Drag & Drop sortieren** – direkt in der eingebetteten Liste der
  Bearbeitungsmaske (Griff links, sofort gespeichert).
- Neuer CLI-Befehl `workflow:demo:restore` (entspricht dem Wiederherstellen-Button).

### Geändert
- Formular-Validierung läuft über Contaos Form-Widgets (Pflichtfelder, Options-Whitelist,
  lokalisierte Fehlermeldungen).
- Einheitliches Formular-Markup: alle Felder als `.tw-field.tw-field--<art>` mit
  Label/Legende über dem Feld, randlos (die bisherige Fieldset-Box-Optik entfällt).
- Konfigurationsformat **v3** (Dokument-Texte, Vorbelegen/Schreibgeschützt, Zahl,
  Einleitungstext); ältere v1/v2-Dateien bleiben importierbar (`inputFields` bzw. der
  kurzlebige Typ „Anzeige" werden beim Import umgewandelt).
- Demo-Workflow zeigt die neuen Funktionen end-to-end (schreibgeschützte Felder,
  vorbelegtes Feld, Options-Dokument-Texte, Brieftexte aus `##stmt_all##`).

### Entfernt
- Workflow-Einstellung **„Anzeige-Felder (Input)"** (`tl_workflow.inputFields`) – ersetzt
  durch schreibgeschützte Antwortfelder (automatische Migration).

## [2.3.11] – 2026-06-06

### Geändert
- **„Konfiguration herunterladen" jetzt in der Workflow-Liste.** Der Konfigurations-Export ist als
  eigene Operation (Symbol) in der Liste unter „Workflows" (workflow_manage) verfügbar – neben
  „Bearbeiten", „Einträge", „Kopieren" usw. – und wurde dafür aus der Übersicht entfernt.
- **„Bearbeiten"-Button in der Workflow-Übersicht.** Jeder Workflow in der Übersicht hat jetzt einen
  „Bearbeiten"-Button, der direkt in die Bearbeiten-Ansicht (workflow_manage) dieses Workflows führt.
- Die Zugriffsprüfung der Workflow-Aktionsrouten akzeptiert nun das Übersichts- **oder** das
  Verwalten-Modul (damit der Export auch aus der Liste heraus funktioniert).

## [2.3.10] – 2026-06-05

### Behoben
- **Status-Aktualisierung nach Mailversand funktionierte bei asynchronem Versand nicht.** Der in
  2.3.6 eingeführte Ansatz stempelte einen Korrelations-Header über das `MessageEvent` auf die
  Mail – Symfony verwirft beim Einreihen in die Queue aber genau diese Änderungen (es stellt
  bewusst die *originale* Nachricht zu), sodass der Header beim echten Versand im Worker fehlte
  und der Teilnehmer-Status auf „0" stehen blieb, obwohl die Mail erfolgreich versendet wurde.
  Die Zuordnung läuft jetzt über die **Parcel-ID des Notification Centers** und dessen
  `AsynchronousReceiptEvent`: Beim Versand wird die Parcel-ID am Eintrag vermerkt und beim
  tatsächlichen (auch asynchronen) Zustellergebnis wieder aufgelöst. Erfolg → Einladung wechselt
  auf „eingeladen"; Fehler → Status bleibt unverändert und wird im Dashboard als „Versandfehler"
  angezeigt. Gilt für Einladung, Erinnerung und Ergebnis-Mail. (Neue Spalten
  `tl_workflow_entry.sendParcelId` / `sendKind` – Datenbank-Migration erforderlich.)

## [2.3.9] – 2026-06-05

### Geändert
- **Formularseite „Workflow-Formular" ist auf `noindex,nofollow` gesetzt.** Die Seite wird nur
  über individuelle Token-Links erreicht und soll nicht von Suchmaschinen indexiert werden. Das
  Robots-Tag wird beim Anlegen/Heilen der Seite gesetzt; eine Migration setzt es zudem auf einer
  bereits vorhandenen Formularseite (neuer und alter Alias).

## [2.3.8] – 2026-06-05

### Behoben
- **„Ausstehende Antworten" zeigte keinen Nachnamen.** Die Übersicht suchte die Namensspalte fest
  unter „Name" – Quelldaten mit „Nachname" (u. a. der Demo) blieben so ohne Namensspalte. Vor-/
  Nachname (und als Fallback die E-Mail) werden jetzt **automatisch anhand gängiger Feldnamen
  erkannt**, normalisiert (Groß-/Kleinschreibung, Leer-/Sonderzeichen) – z. B. Vorname/Rufname/
  First Name/Given Name, Nachname/Familienname/Surname/Last Name/Family Name (generisches „Name"
  als Fallback) sowie E-Mail/Mail/E-Mail-Adresse. Die Spalte „Status" bleibt unverändert (vom
  Plugin vorgegeben).

## [2.3.7] – 2026-06-05

### Geändert
- **Formularseite ist jetzt allgemein nutzbar statt demo-spezifisch.** Die bei der Installation
  angelegte Seite heißt nun „Workflow-Formular" mit Alias **`/workflow-formular`** (vorher
  „… (Demo)" / `workflow-formular-demo`). Da das Formularmodul Eintrag *und* Workflow allein aus
  dem Token in der URL auflöst, kann **eine einzige Seite alle Workflows bedienen** – neue
  Workflows verweisen einfach mit ihrer „Formularseite" darauf. Theme/Modul entsprechend generisch
  benannt. Eine Migration benennt eine vorhandene Demo-Seite (inkl. Artikel/Modul/Theme) in place
  um – die Seiten-ID bleibt erhalten, nur die URL wird zu `/workflow-formular/<token>` (bereits
  versendete Demo-Links auf die alte Adresse verlieren dadurch ihre Gültigkeit).

## [2.3.6] – 2026-06-05

### Geändert
- **Workflow-Schritt wird erst nach dem tatsächlichen Mail-Versand weitergesetzt.** Bisher
  wurde der Status sofort beim Klick hochgezählt – auch wenn die Mail (oft asynchron über die
  Queue) danach am SMTP-Server scheiterte; die grüne Meldung bestätigte fälschlich „versendet".
  Jetzt wird der Status ereignisgesteuert aus dem echten Sendeergebnis aktualisiert
  (`SentMessageEvent`/`FailedMessageEvent`): eine Einladung wechselt erst nach erfolgreicher
  Zustellung auf „eingeladen", ein **Fehlversand lässt den Schritt unverändert**. Gilt für
  Einladung, Erinnerung und Ergebnis-Mail. Die Bestätigungsmeldung lautet entsprechend
  „… zum Versand eingereiht".

### Hinzugefügt
- **Versandfehler im Dashboard.** Fehlgeschlagene Zustellungen werden pro Workflow in einer
  eigenen „Versandfehler"-Box (Empfänger + Fehlertext) sowie als Markierung an der betroffenen
  Zeile angezeigt. Ein späterer erfolgreicher Versand räumt die Markierung automatisch wieder ab.
  (Korrelation Mail↔Zeile über gestempelte Mail-Header; neue Spalten `tl_workflow_entry.sendError`
  / `sendErrorAt` – Datenbank-Migration erforderlich.)

## [2.3.5] – 2026-06-05

### Behoben
- **Hinweis auf übersprungene Elemente wurde nicht angezeigt.** Die Import-Meldungen (Erfolg sowie
  „… wegen Namenskonflikt übersprungen") wurden zwar gesetzt, aber in der Workflow-Übersicht nicht
  ausgegeben – ein eigenes Backend-Modul wird (anders als DC-Listen/Masken) nicht automatisch mit
  der Meldungsausgabe umrahmt. Das Dashboard rendert die Flash-Meldungen jetzt selbst.

## [2.3.4] – 2026-06-05

### Behoben
- **Import legte Duplikate mit gleichem Namen an.** Beim „Workflow-Konfiguration importieren"
  wurden Briefpapier und E-Mail-Vorlagen auch dann (doppelt) angelegt, wenn bereits gleichnamige
  Elemente existierten. Jetzt wird **nichts überschrieben und nichts unter einem bereits
  vergebenen Namen angelegt**: Ein belegter **Workflow-Titel** bricht den gesamten Import ab
  (keine verwaisten Elemente), ein belegtes **Briefpapier** bzw. eine belegte **E-Mail-Vorlage**
  wird einzeln übersprungen. Übersprungene Elemente werden nach dem Import **namentlich gemeldet**
  (vorhandenes umbenennen oder Namen in der JSON ändern und erneut importieren).

### Geändert
- **Eindeutige Namen erzwungen (Anlegen/Bearbeiten/Duplizieren).** Workflow- und Briefpapier-Titel
  müssen jetzt eindeutig sein (`eval.unique`): beim Duplizieren wird der Titel geleert und im
  Bearbeiten-Formular ein freier Name verlangt; ein bereits vergebener Name wird beim Speichern mit
  einer Warnung abgelehnt.

## [2.3.3] – 2026-06-05

### Behoben
- **Demo-Formularseite: „Unterseitenlayout" zeigte „Unbekannte Option".** Beim Anlegen und beim
  Heilen der Seite wird `subpageLayout` jetzt explizit auf **0 (= Seitenlayout vererben)** gesetzt
  – vorher blieb dort ein ungültiger Wert (Verweis auf das entfernte dedizierte Demo-Layout).

## [2.3.2] – 2026-06-05

### Behoben
- **Demo-Formularseite erschien in der Navigation und brachte ein eigenes, nacktes Layout mit.**
  Sie wird jetzt **aus dem Menü versteckt** (`hide`), **erbt ein vorhandenes Site-Layout** (statt
  eines eigenen) und bindet das „Workflow-Formular"-Modul über **Artikel + Inhaltselement** ein –
  ohne bestehende Seiten/Layouts zu verändern. Das frühere dedizierte Demo-Layout wird entfernt;
  eine bereits angelegte Demo-Seite wird beim Wiederherstellen entsprechend korrigiert.

### Doku
- ANLEITUNG Abschnitt 1: genaue Anleitung, wie die Formularseite das **Website-Layout übernimmt**,
  **aus dem Menü** genommen wird und das Modul per Inhaltselement erhält – ohne andere Teile der
  Website zu verändern oder Fehler zu verursachen.

## [2.3.1] – 2026-06-05

### Behoben
- **Sonderzeichen wurden beim Speichern kodiert.** Text-/Textarea-Felder ohne `decodeEntities`
  ließen Contao `( ) # < > = \` als HTML-Entities speichern – z. B. wurde ein Titel
  „… (synthetische Daten)" beim erneuten Speichern zu „… &#40;synthetische Daten&#41;", und
  `##platzhalter##` wären über `#` → `&#35;` zerstört worden. `decodeEntities => true` an allen
  Inhalts-Textfeldern ergänzt (Titel/Label, `pdfTitle`, `pdfFileName`, `pdfBody`, Antwort-Optionen,
  PDF-Variablen, Bedingungswerte). Bereits verfälschte Werte heilen beim nächsten Speichern bzw.
  beim Wiederherstellen des Demos.

## [2.3.0] – 2026-06-05

### Hinzugefügt
- **Der Demo bringt eine Formularseite mit** und ist damit end-to-end versendbar. Beim Anlegen/
  Wiederherstellen wird (idempotent) eine funktionierende Formularseite erzeugt: Theme + Layout +
  „Workflow-Formular"-Modul + eine reguläre Seite unter einer vorhandenen **veröffentlichten
  Root-Seite**; danach wird sie am Demo-Workflow als *Formularseite* gesetzt. Vorhandene Records
  werden per Marker-Name **wiederverwendet** (kein Duplikat), **keine Datei wird überschrieben**.
  Ohne veröffentlichte Root-Seite entfällt nur die Formularseite.
- **Echter Formular-Link im Backend-Eintrag.** Beim Token wird jetzt der **tatsächliche** Link
  (`<URL der Formularseite>/<Token>`) angezeigt statt des statischen „…/workflow/…".

### Geändert
- Klargestellt (ANLEITUNG/Eintrag): Die Formular-URL ergibt sich aus dem **Alias der
  Formularseite** + Token (nicht fix `/workflow/…`); häufige 404-Ursache (falscher Alias /
  abschließender Slash) dokumentiert.

## [2.2.3] – 2026-06-05

### Behoben
- **Versand-Versuch ohne Formularseite scheiterte ohne sichtbare Rückmeldung.** Ein Workflow ohne
  (gültige) Formularseite oder ohne zugeordnete E-Mail-Benachrichtigung kann keine Einladungen
  versenden (der `##link##` braucht die Formularseite) – der Versand brach erst beim Klick mit
  einer leicht zu übersehenden Meldung ab. Die Übersicht zeigt das jetzt **vorab** als deutliche
  Warnung („Versand nicht möglich: …") und **deaktiviert** den Senden-Button. Betrifft u. a. den
  nicht-invasiven Demo-Workflow (keine Formularseite).

## [2.2.2] – 2026-06-05

### Geändert
- Redundante Beispiel-CSV `docs/sample-trainers.csv` entfernt. Als Beispiel-Quelldatei dient
  jetzt die (synthetische) Demo-Quelle `src/Resources/demo/demo-teilnehmer.csv` – es gibt nur
  noch **eine** Demo-CSV.

## [2.2.1] – 2026-06-05

### Geändert
- **Keine vorgefertigten Workflow-Vorlagen mehr im Paket** (außer dem synthetischen Demo). Der
  Konfigurations-Import erfolgt jetzt **nur per Datei-Upload** (JSON-Export); die Auswahl
  mitgelieferter Presets entfällt. Vereinsspezifische Vorlagen werden als externe Dateien
  bereitgestellt, nicht im Paket/Repo.
- **Vereinsspezifische Inhalte entfernt.** Die mitgelieferten Templates (`pdf_master`,
  `pdf_body_verzicht`), die Hilfetexte und die Doku verwenden jetzt durchgängig neutrale
  Platzhalter („Musterverein e.V."). `pdf_master` ist damit ein **neutraler Beispiel-Briefkopf**.

### Hinzugefügt
- Der **Demo-Workflow** legt jetzt zusätzlich passende **E-Mail-Vorlagen** an (Notification
  Center, jeweils mit „(Demo)" im Namen) und verknüpft sie; beim Wiederherstellen werden sie
  ersetzt. Das gemeinsame E-Mail-Gateway bleibt unangetastet.

## [2.2.0] – 2026-06-05

### Hinzugefügt
- **Workflow-Konfigurationen importieren/exportieren.** In der Workflow-Übersicht lässt sich
  jede Workflow-Konfiguration als portable **JSON-Datei exportieren** und eine solche Datei
  wieder **importieren** (Datei-Upload). Beim Import optional auch die
  **Briefpapier-Konfiguration** und die **E-Mail-Vorlagen** (Notification Center:
  Einladung/Erinnerung/Ergebnis) mit anlegen (vorhandenes E-Mail-Gateway wird wiederverwendet).
  Der importierte Workflow hat bewusst **keine Quelldatei** → nach der bestehenden Prüfung
  „nicht ausführbar", bis eine passende Quelle zugeordnet wird.
- Export/Import lassen Logo, Quelldatei-UUID und Formularseite (site-spezifisch) bewusst aus.
  Der Demo-Seeder nutzt jetzt denselben Materializer (`WorkflowConfigImporter`).

## [2.1.0] – 2026-06-05

### Hinzugefügt
- **Synthetischer Demo-Workflow.** Bei der Erstinstallation wird einmalig ein komplett
  synthetischer Demo-Workflow („Musterverein", `@example.org`) angelegt: Briefkopf
  (`pdf_master_generic`), Antwortfelder (Radio + „Aktuelle Zeit"), PDF-Regeln und fünf
  importierte Beispiel-Teilnehmer. Updates legen ihn **nicht** erneut an (Marker-Datei
  `var/workflow_demo_installed`). In der Workflow-Übersicht gibt es den Button **„Demo-Workflow
  wiederherstellen"**, der den Demo idempotent neu anlegt (vorhandener gleichen Namens wird
  ersetzt). Nicht-invasiv: legt **keine** Seiten/Module/Notification-Center-Datensätze an –
  das Live-Formular braucht weiterhin die dokumentierte Formularseite (siehe ANLEITUNG.md).

## [2.0.2] – 2026-06-05

### Behoben
- Bearbeiten eines Workflows warf im **Produktiv**-Container eine
  `ServiceNotFoundException` für `AnswerConfigListener` („removed or inlined"), weil der
  per `System::importStatic()` aufgelöste DCA-Callback-Service privat war. Jetzt `public`
  (wie die übrigen container-aufgelösten Helfer). Nur in Prod sichtbar – der dev-Container
  inlinet private Services nicht.

## [2.0.1] – 2026-06-05

### Behoben
- Anlegen/Bearbeiten eines Workflows schlug auf einer frischen Installation mit einem
  SQL-Syntaxfehler fehl (`… WHERE  ORDER BY id …`). Ursache war ein `findBy([], …)` im
  Master-Vorauswahl-Callback (`WorkflowOptionsListener::preselectMaster`); ersetzt durch
  `findAll(['order' => 'id', 'limit' => 1])`. Trat auf, sobald das Feld „Briefkopf-Vorlage"
  leer war (also wenn noch kein Master angelegt ist).

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

### Added – Beispiel-Briefkopf (Master-Vorlage)
- `pdf_master.html5` neu als neutraler Beispiel-Briefkopf: blaue **Kopfzeile** „Musterverein e.V. • Musterstraße 1 • …"
  oben links, Logo oben rechts, blaue Linie darunter; **4-spaltige blaue Fußzeile** (Anschrift,
  Vorstände, Kontakt, Bankverbindungen) – umgesetzt als echte **mPDF-Lauf-Kopf/Fußzeile**
  (`<htmlpageheader>/<htmlpagefooter>`, dazu Seitenränder in `PdfGenerator::renderPdf`).
  **Signaturzeile gespiegelt**: links „<Ort>, <Datum>" über der Linie + Label „Ort, Datum",
  rechts Unterschriftsbild über der Linie + „Unterschrift <Name>".

### Added – Demo
- Zwei zusätzliche Demo-Workflows als weitere Vorlagen: **„EStG Übungsleiter"** (§ 3 Nr. 26 EStG)
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
