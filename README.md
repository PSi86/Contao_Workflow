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
> Contao-Manager): siehe [../INSTALL.md](../INSTALL.md). Der folgende Abschnitt
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
(Antwortfelder), `tl_workflow_rule` (PDF-Regeln) und `tl_workflow_entry` aus den
DCA-Definitionen an. Bundle-Assets unter `public/` werden beim Install nach
`public/bundles/contaoworkflow/` veröffentlicht.

## Einrichtung im Backend
1. **Notification Center**: drei Notifications anlegen (Einladung, Erinnerung,
   Ergebnis). Verfügbare Tokens:
   - `##email##` (als „Versenden an“ verwenden), `##link##`, `##workflow_title##`
   - `##data_<slug>##` für jede importierte Spalte (inkl. der gespeicherten
     Antwortwerte). `<slug>` = kleingeschrieben, Umlaute transliteriert (ä→ae, ß→ss …);
     z. B. `##data_verzicht##`, „davon Spende“ → `##data_davon_spende##`.
   - `##var_<slug>##` für jede Briefpapier-Variable (z. B. `##var_jahr##`, `##var_verein##`).
     Dieselben `##data_*##`/`##var_*##`-Tokens gelten **identisch** im PDF.
   - Ergebnis-Mail: Anhang über **„Anhänge über Tokens“** mit `##attachment##`
     (das erzeugte PDF).
2. **Seite + Modul**: Frontend-Modul „Workflow-Formular“ anlegen und auf einer
   Seite einfügen, die `auto_item` nutzt. Diese Seite unter *Formularseite*
   am Workflow auswählen.
3. **Workflow** (Backend → Workflow → Workflows → **Bearbeiten**).
   Die *gesamte* Konfiguration liegt auf einer Seite, in Abschnitte gegliedert
   (in der Liste gibt es pro Zeile nur *Bearbeiten* = Konfiguration und *Einträge* = Antworten):
   - **Allgemein:** Titel, *Veröffentlicht*; **Schritte** z. B. `Importiert`, `Eingeladen`, `Beantwortet`
   - **Quelldaten:** Quelldatei, Tabellenblatt, Kopfzeile, E-Mail-Spalte
   - **Formular & Antwortfelder:** Anzeige-Felder, *Unterschrift verlangen* (mit Auswahl der
     Datenfelder für **Datum** und **Ort** der Unterschriftszeile), Formularseite und die
     eingebetteten **Antwortfelder** – pro Feld Typ (Dropdown, Radio, Checkboxen, Freitext, Datum,
     **Aktuelle Zeit**), Speicherfeld (Quellspalte, Pflicht), bei Optionstypen die Optionen
     (Wert + Options-Text). **„Aktuelle Zeit"** wird beim Absenden automatisch mit dem Datum gefüllt
     und kann im Formular ausgeblendet werden. Ja/Nein = Radio mit zwei Optionen (z. B.
     „Akzeptieren“→`ja`, „Ablehnen“→`nein`).
   - **PDF-Inhalt:** Briefpapier, **PDF-Dateiname** (Muster mit Platzhaltern,
     z. B. `Verzicht_##data_name##_##data_vorname##`) + Typ. **Einfacher Brief** → nur die
     gemeinsame *Überschrift* hier; die Brieftexte stehen in den **PDF-Regeln**. **Spezielle
     Vorlage** → eine Datei `pdf_body_*`, die ihre Logik selbst enthält (dann **keine** PDF-Regeln).
   - **PDF-Regeln** (nur bei *Einfacher Brief*): die **Brieftexte** als Liste. Jede Regel =
     Bedingungen `Feld / Operator / Wert` (UND) + Brieftext; erste passende gewinnt, eine Regel
     **ohne Bedingung** gilt immer (Sonst-Fall, ans Ende). Verbindung Antwort↔Text = das **Speicherfeld**.
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
- **PDF-Vorlagen (Master/Body) erstellen** – Syntax, Variablen, mPDF-Regeln und der
  `.docm`-Konverter: siehe [docs/PDF-TEMPLATES.md](docs/PDF-TEMPLATES.md).
- **Produktiv-Betrieb & Mailversand:** siehe [../DEPLOYMENT.md](../DEPLOYMENT.md)
  (Worker/Cron, SMTP, SPF/DKIM/DMARC, Skalierung 100–300, all-inkl-Hosting).
- **E-Mails werden asynchron** über Symfony Messenger versendet (Queue
  `tl_message_queue`). Ohne laufenden Worker/Cron (`contao:cron` bzw.
  `messenger:consume`) wird nichts zugestellt, obwohl der Versand als erfolgreich gilt.
- Generierte PDFs liegen unter `%kernel.project_dir%/var/workflow_pdfs/` (nicht
  öffentlich) und werden nur über die authentifizierten Backend-Routen gestreamt.
- PDF-Anhang in der Ergebnis-Mail: setzt voraus, dass das Notification Center den
  über `##attachment##` übergebenen Dateipfad als Anhang verarbeitet. Falls die
  installierte NC-Version das nicht abdeckt, `do-while/contao-pdf-nc-attachment-bundle`
  ergänzen oder die Ergebnis-Mail auf Symfony-Mailer mit direktem Anhang umstellen
  (`NotificationDispatcher::sendResult`).
- Eine Beispiel-Quelldatei liegt unter `docs/sample-trainers.csv`.
