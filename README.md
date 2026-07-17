# Contao Workflow Bundle

Token-basierter Abfrage-Workflow für Contao 5.3+: CSV/XLSX-Import, individuelle
vorausgefüllte Formulare mit Unterschrift, PDF-Erzeugung nach Vorlage, sichere
Ablage, Versand über das Notification Center und eine Admin-Übersicht mit Export.

> Das Bundle ist workflow-zentriert aufgebaut (alles hängt an einem Workflow).
> **Mehrere parallele Workflows** werden voll unterstützt (weitere `tl_workflow`-
> Datensätze; die Demo liefert drei). Jeder Workflow hat eine eigene Menge von
> Datenfeldern, und **dieselben Platzhalter** gelten überall (DB/Export, PDF, E-Mail).
>
> Alle Änderungen sind im **[CHANGELOG.md](CHANGELOG.md)** dokumentiert (zuletzt
> 2.0.0 – Umbenennung Trainer→Workflow inkl. Paket/Namespace/DB-Tabellen).

## Anforderungen
- Contao 5.3+ / PHP 8.1+
- `terminal42/notification_center` ^2.0
- `menatwork/contao-multicolumnwizard-bundle` ^3.6 (Inline-Editor für Antwort­optionen & Regel-Bedingungen)
- `phpoffice/phpspreadsheet`, `mpdf/mpdf` (werden als Abhängigkeiten installiert)

> **Installation auf einer echten Contao-Installation** (mit *und* ohne CLI /
> Contao-Manager): siehe [docs/INSTALL.md](docs/INSTALL.md). Der folgende Abschnitt
> beschreibt nur das lokale Entwicklungs-Setup als Path-Repo.

## Installation in einer Contao Managed Edition (lokal, als Path-Repo)

```bash
# 1) Managed Edition anlegen (eine Ebene über diesem Bundle)
composer create-project contao/managed-edition contao-app "5.3.*"
cd contao-app

# 2) Path-Repository auf das Bundle eintragen
composer config repositories.workflow path ../contao-workflow

# 3) Bundle + Abhängigkeiten installieren
composer require psimandl/contao-workflow:@dev terminal42/notification_center:^2.0

# 4) Datenbank/ENV einrichten (.env.local: DATABASE_URL, MAILER_DSN ...)
#    Mailpit o. Ä. zum Abfangen der Mails empfehlenswert.

# 5) Schema anlegen und Assets veröffentlichen
vendor/bin/contao-console contao:migrate
vendor/bin/contao-console contao:user:create     # Backend-Benutzer
php -S 127.0.0.1:8000 -t public                  # oder symfony server:start / DDEV
```

`contao:migrate` legt die Tabellen `tl_workflow`, `tl_workflow_question`
(Formularfelder), `tl_workflow_rule` (Dokument-Texte) und `tl_workflow_entry` aus den
DCA-Definitionen an. Bundle-Assets unter `public/` werden beim Install nach
`public/bundles/contaoworkflow/` veröffentlicht.

## Einrichtung im Backend
1. **Notification Center**: drei Notifications anlegen (Einladung, Erinnerung,
   Ergebnis). Verfügbare Tokens:
   - `##email##` (als „Versenden an“ verwenden), `##link##`, `##workflow_title##`
   - `##data_<slug>##` für jede importierte Spalte (inkl. der gespeicherten
     Antwortwerte). `<slug>` = kleingeschrieben, Umlaute transliteriert (ä→ae, ß→ss …);
     z. B. `##data_verzicht##`, „davon Spende“ → `##data_davon_spende##`.
   - `##letterhead_<slug>##` für jede Briefpapier-Variable (z. B. `##letterhead_verein##`, `##letterhead_ort##`).
   - `##system_year##`, `##system_month##`, `##system_today##`, `##system_time##`,
     `##system_datetime##` – eingebaute Datums-/Zeit-Platzhalter (aktuelles Jahr/Datum/Uhrzeit),
     ohne Konfiguration überall verfügbar.
   - `##text_<speicherfeld>##` / `##text_all##` für die **Dokument-Texte (Textbausteine)**
     der Formularfelder (z. B. um die Auswahl in der Ergebnis-Mail wörtlich zu zitieren).
     Dieselben Tokens gelten **identisch** im PDF.
   - **Contao-Insert-Tags** `{{…}}` (z. B. `{{date::d.m.Y}}`) werden in allen Textfeldern
     eines Workflows aufgelöst (Überschrift, Einleitung, Dokument-Text, Textbaustein,
     PDF-Dateiname). Sie werden nur in den (im Backend gepflegten) Vorlagen ausgewertet,
     nie in eingegebenen Antwortdaten.
   - Ergebnis-Mail: Anhang über **„Anhänge über Tokens“** mit `##attachment##`
     (das erzeugte PDF).
   - **Hinweis zur `##`-Vorschlagsliste des Notification Center:** Vorgeschlagen
     (auto-suggest) werden nur `##data_*##`, `##email##`, `##link##`,
     `##workflow_title##` und `##attachment##`. Die übrigen Workflow-Platzhalter
     (`##letterhead_*##`, `##system_*##`, `##text_*##` / `##text_all##`) werden
     **nicht vorgeschlagen, funktionieren beim Versand aber trotzdem** – einfach
     ausschreiben. (Fehlt zu einem `##data_*##`-Token die Spalte im Eintrag, bleibt
     der Platzhalter unersetzt im Text stehen.)
2. **Seite + Modul**: Frontend-Modul „Workflow-Formular“ anlegen und auf einer
   Seite einfügen, die `auto_item` nutzt. Diese Seite unter *Formularseite*
   am Workflow auswählen.
3. **Workflow** (Backend → Workflow → Workflows → **Bearbeiten**).
   Die *gesamte* Konfiguration liegt auf einer Seite, in Abschnitte gegliedert
   (in der Liste gibt es pro Zeile nur *Bearbeiten* = Konfiguration und *Einträge* = Antworten):
   - **Allgemein:** Titel, *Veröffentlicht*; **Schritte** z. B. `Importiert`, `Eingeladen`, `Beantwortet`
   - **Quelldaten:** Quelldatei, Tabellenblatt, Kopfzeile, E-Mail-Spalte. Beim Import werden
     Datums- sowie Währungs-/Zahlenzellen **deutsch lokalisiert** übernommen (z. B. `17.12.1955`,
     eine „Währung“-Zelle `3000` → `3.000,00 €`). Eine Zelle im Format „Standard“ behält ihre
     eigenen Stellen – `3000` bleibt `3000`, `3000.5` wird zu `3000,5` (so zeigt deutsches Excel
     sie auch). Formatiert wird **genau einmal**, beim Import bzw. beim Speichern einer Antwort;
     Formular, Live-Vorschau, PDF und Export zeigen danach denselben Wert.
   - **Inhalt (Formular & Dokument):** **Überschrift** und optionaler **Einleitungstext** –
     erscheinen **identisch** oben im Formular und im PDF (Platzhalter und `{{Insert-Tags}}` erlaubt;
     der Einleitungstext zusätzlich mit **Textauszeichnung** `[b]`/`[i]`/`[u]`, die Überschrift ohne).
   - **Formular & Formularfelder:** *Unterschrift benötigt* (mit Auswahl der
     Datenfelder für **Datum** und **Ort** der Unterschriftszeile), Formularseite und die
     eingebetteten **Formularfelder** (Reihenfolge per **Drag & Drop** direkt in der Liste) –
     pro Feld **Überschrift**, Typ (Freitext, **Zahl**, Datum, Dropdown, Radio, Checkboxen,
     **Aktuelle Zeit**, **Erklärung**), Speicherfeld (Quellspalte), *Pflichtfeld*,
     *Mit Wert aus den Daten vorbelegen* (editierbar vorausgefüllt) und *Schreibgeschützt*
     (reines Anzeige-Feld). Optional eine **Beschreibung** (erscheint **nur im Formular**
     unter der Überschrift, nie im Dokument; Platzhalter, `{{Insert-Tags}}` und **Textauszeichnung**
     `[b]`/`[i]`/`[u]` werden aufgelöst). Dazu der **Dokument-Text (Textbaustein)**:
     bei Wert-Typen ein Satz mit `##answer##`, bei Optionstypen je Option (Wert +
     Options-Text + Dokument-Text; leer = Options-Text gilt wörtlich). Das Formular zeigt
     den Dokument-Text live unter dem Feld („So erscheint dies im Dokument"); mit
     *Textbaustein im Formular anzeigen* lässt sich diese Vorschau je Feld ausblenden –
     **Formular und PDF nutzen dieselben Texte**. **„Aktuelle Zeit"** wird beim Absenden
     automatisch mit dem Datum gefüllt und kann im Formular ausgeblendet werden.
     **„Erklärung"** ist ein reiner Textabsatz (kein Eingabefeld): der Text steht im
     *Dokument-Text* und erscheint als Fließtext im Formular **und** im Dokument.
     Ja/Nein = Radio mit zwei Optionen (z. B. „Akzeptieren“→`ja`, „Ablehnen“→`nein`).
   - **Dokument-Einstellungen:** Briefpapier, **PDF-Dateiname** (Muster mit Platzhaltern und
     `{{Insert-Tags}}`, z. B. `Verzicht_##data_name##_##data_vorname##` oder
     `Verzicht_##data_name##_{{date::Y}}`; die Eingabe von `##` bzw. `{` blendet eine
     Vorschlagsliste ein) + **Dokument-Inhalt**. **Einfacher Brief**
     → die Texte stehen in den **Dokument-Texten**. **Spezielle Vorlage** → eine Datei
     `pdf_body_*`, die ihre Logik selbst enthält (dann **keine** Dokument-Texte).
   - **Dokument-Texte** (nur bei *Einfacher Brief*): die Texte als Liste. Jede Regel =
     Bedingungen `Feld / Operator / Wert` (UND) + Dokument-Text; erste passende gewinnt, eine Regel
     **ohne Bedingung** gilt immer (Sonst-Fall, ans Ende).
     - **`##text_all##` einbinden – wichtig:** Die Textbausteine der Formularfelder und die
       **Erklärungen** erscheinen im Dokument **nur dort, wo ein `##text_*##`-Platzhalter im
       Dokument-Text steht.** `##text_<speicherfeld>##` fügt den Baustein *eines* Feldes ein,
       `##text_all##` **alle** in Formularfeld-Reihenfolge (so kann keiner vergessen werden;
       Felder/Erklärungen mit eigenem Text beginnen darin als eigener Absatz). Enthält ein
       Dokument-Text kein `##text_*##`, tauchen die Textbausteine/Erklärungen dort **nicht**
       auf – der Text wird dann komplett von Hand geschrieben (z. B. mit einzelnen
       `##data_*##`-Platzhaltern). Verbindung Antwort↔Text = das **Speicherfeld**.
     - **Textauszeichnung:** In den **Dokument-Texten** (Regel, je Feld, je Option), im
       Einleitungstext und in der Formularfeld-Beschreibung formatieren `[b]fett[/b]`,
       `[i]kursiv[/i]` und `[u]unterstrichen[/u]` den Text – im **PDF** und in der
       **Formular-Vorschau**. In E-Mails werden die Marker zu reinem Text entfernt; die
       Überschrift bleibt ohne Auszeichnung. Nur Vorlagentexte werden formatiert,
       importierte Daten nicht.
   - **Benachrichtigungen:** die drei Notifications zuordnen.

## Verifikation (End-to-End)
1. `contao:migrate` läuft fehlerfrei; Backend zeigt „Übersicht“ und „Workflows“.
2. In der Übersicht **Import ausführen** → Einträge mit Status 0, Token, E-Mail, Daten.
3. **E-Mails senden → Automatisch → Einladungen senden** (Empfänger bestätigen) → Mail
   (Mailpit) mit `##link##`; Status → 1, `sentAt` gesetzt.
4. Link öffnen → Formular vorausgefüllt; Antwortfelder ausfüllen + (falls aktiv)
   unterschreiben → absenden.
5. Eintrag: Status 2, Antwortwerte (in den Speicherfeldern) und ggf. `signature`
   gesetzt; PDF unter `var/workflow_pdfs/<id>/<dateiname>.pdf` (Name aus `pdfFileName`);
   Ergebnis-Mail mit PDF-Anhang.
6. Übersicht: Zähler (eingegangen/offen) + die sortierbare, auswählbare Liste der
   ausstehenden Antworten.
7. **Export (XLSX/CSV)** lädt herunter; **PDFs herunterladen** liefert ZIP.
8. **E-Mails senden → Erinnerungen senden** → nur Einträge mit Status 1 erhalten eine Erinnerung.

## Hinweise
- **PDF-Vorlagen (Master/Body) erstellen** – Syntax, Variablen und mPDF-Regeln:
  siehe [docs/PDF-TEMPLATES.md](docs/PDF-TEMPLATES.md).
- **Produktiv-Betrieb & Mailversand:** siehe [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)
  (Worker/Cron, SMTP, SPF/DKIM/DMARC, Skalierung 100–300, all-inkl-Hosting).
- **E-Mails werden asynchron** über Symfony Messenger versendet (Queue
  `tl_message_queue`). Der Klick reiht die Mail nur **ein**; der Teilnehmer-Status wechselt
  **erst nach dem tatsächlichen Versand** auf „eingeladen", ein Fehlversand wird als
  **„Versandfehler"** angezeigt. Ohne laufenden Worker/Cron (`contao:cron` bzw.
  `messenger:consume`) wird nichts zugestellt – und der Status bleibt dann auf **0**.
- Generierte PDFs liegen unter `%kernel.project_dir%/var/workflow_pdfs/` (nicht
  öffentlich) und werden nur über die authentifizierten Backend-Routen gestreamt.
- **Quelldateien & Datenschutz:** Die hochgeladene Quelltabelle enthält personenbezogene
  Daten. Contao-Dateiordner sind standardmäßig **geschützt** (nicht ohne Login abrufbar) –
  Quelldateien daher in einem geschützten Ordner belassen und den Ordner **nicht freigeben**.
  Liegt die Quelldatei doch in einem öffentlichen Ordner, warnt das Backend beim Bearbeiten
  des Workflows mit einem deutlichen Datenschutz-Hinweis.
- PDF-Anhang in der Ergebnis-Mail: setzt voraus, dass das Notification Center den
  über `##attachment##` übergebenen Dateipfad als Anhang verarbeitet. Falls die
  installierte NC-Version das nicht abdeckt, `do-while/contao-pdf-nc-attachment-bundle`
  ergänzen oder die Ergebnis-Mail auf Symfony-Mailer mit direktem Anhang umstellen
  (`NotificationDispatcher::sendResult`).
- **Konfigurationen importieren/exportieren:** In der **Workflow-Liste** lädt *„Konfiguration
  herunterladen"* (Symbol je Zeile) jede Workflow-Konfiguration als portable **JSON-Datei**
  herunter; in der **Übersicht** lässt sich eine solche Datei wieder **importieren** (Upload),
  optional inkl. **Briefpapier** und **E-Mail-Vorlagen**. Der Import **überschreibt nichts**:
  gleichnamiges Briefpapier/Vorlagen werden übersprungen und gemeldet, ein bereits vergebener
  Workflow-Titel bricht ab. Der Import erzeugt einen Workflow **ohne Quelldatei** (zunächst
  „nicht ausführbar", bis eine Quelle zugeordnet wird). Logo/Quelldatei/Formularseite sind
  site-spezifisch und nicht Teil des Exports. Das Bundle bringt **keine** vorgefertigten
  Workflows mit (außer dem Demo).
- **Demo-Workflow:** Bei der Erstinstallation wird einmalig ein **synthetischer**
  Demo-Workflow („Musterverein") mit fünf Beispiel-Teilnehmern angelegt (Updates nicht
  erneut, Marker `var/workflow_demo_installed`). In der Übersicht per **„Demo-Workflow
  wiederherstellen"** neu erzeugbar (idempotent). Er bringt **alles zum Ausprobieren** mit:
  E-Mail-Vorlagen (Notification Center, „(Demo)") und eine **Formularseite** „Workflow-Formular"
  (Alias `/workflow-formular`, auf **noindex,nofollow** gesetzt), die **im Menü versteckt** ist,
  ein **vorhandenes Site-Layout erbt** und das Modul per Inhaltselement einbindet – **von jedem
  Workflow nutzbar** (die Zuordnung läuft über den Token). Idempotent und **ohne** bestehende
  Seiten/Layouts/Dateien zu verändern (ohne veröffentlichte Root-Seite bzw. ohne nutzbares
  Site-Layout entfällt nur die Formularseite).
- Eine Beispiel-Quelldatei (die Quelle des mitgelieferten Demos) liegt unter
  `src/Resources/demo/demo-teilnehmer.csv`.
