# Contao Trainer-Workflow Bundle

Token-basierter Abfrage-Workflow für Contao 5.3+: CSV/XLSX-Import, individuelle
vorausgefüllte Formulare mit Unterschrift, PDF-Erzeugung nach Vorlage, sichere
Ablage, Versand über das Notification Center und eine Admin-Übersicht mit Export.

> Das Bundle ist workflow-zentriert aufgebaut (alles hängt an einem Workflow).
> Aktuell wird komfortabel **ein** Workflow bedient; mehrere parallele Workflows
> sind ohne Code-Änderung möglich (weitere `tl_trainer_workflow`-Datensätze).

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
composer config repositories.trainer path ../contao-trainer-workflow

# 3) Bundle + Abhängigkeiten installieren
composer require psimandl/contao-trainer-workflow:@dev terminal42/notification_center:^2.0

# 4) Datenbank/ENV einrichten (.env.local: DATABASE_URL, MAILER_DSN ...)
#    Mailpit o. Ä. zum Abfangen der Mails empfehlenswert.

# 5) Schema anlegen und Assets veröffentlichen
vendor/bin/contao-console contao:migrate
vendor/bin/contao-console contao:user:create     # Backend-Benutzer
php -S 127.0.0.1:8000 -t public                  # oder symfony server:start / DDEV
```

`contao:migrate` legt die Tabellen `tl_trainer_workflow`, `tl_trainer_question`
(Antwortfelder), `tl_trainer_rule` (PDF-Regeln) und `tl_trainer_entry` aus den
DCA-Definitionen an. Bundle-Assets unter `public/` werden beim Install nach
`public/bundles/contaotrainerworkflow/` veröffentlicht.

## Einrichtung im Backend
1. **Notification Center**: drei Notifications anlegen (Einladung, Erinnerung,
   Ergebnis). Verfügbare Tokens:
   - `##email##` (als „Versenden an“ verwenden), `##link##`, `##workflow_title##`
   - `##data_<spalte>##` für jede importierte Spalte (inkl. der gespeicherten
     Antwortwerte, z. B. `##data_Verzicht##`)
   - Ergebnis-Mail: Anhang über **„Anhänge über Tokens“** mit `##attachment##`
     (Pfad des erzeugten PDFs).
2. **Seite + Modul**: Frontend-Modul „Trainer-Formular“ anlegen und auf einer
   Seite einfügen, die `auto_item` nutzt. Diese Seite unter *Formularseite*
   am Workflow auswählen.
3. **Workflow** (Backend → Trainer-Workflow → Trainer-Workflows → Neu):
   - Titel, *Veröffentlicht* aktivieren
   - *Schritte* z. B. `Importiert`, `Eingeladen`, `Beantwortet`
   - *Quelldatei* hochladen/auswählen, *Feld-Zuordnung* `email` → Spaltentitel
   - *Formularseite*, optional *Anzeige-Felder*, *Unterschrift verlangen*
   - *Briefkopf-Vorlage* und Standard-*PDF-Inhalt* (Brief oder Body-Vorlage)
   - die drei Notifications zuordnen
   - **Antwortfelder** (Operation am Workflow): pro Feld Typ (Dropdown, Radio,
     Checkboxen, Freitext, Datum), Speicherfeld (Quellspalte, Pflicht), bei
     Options­typen die Optionen (Wert + Options-Text). Die Ja/Nein-Abfrage =
     Radio mit zwei Optionen (z. B. „Akzeptieren“→`ja`, „Ablehnen“→`nein`).
   - **PDF-Regeln** (optional, Operation am Workflow): wählen je nach Antworten
     eine andere Body-Vorlage. Bedingungen `Feld / Operator / Wert` (UND-verknüpft);
     die erste passende Regel gewinnt, sonst gilt der Standard-PDF-Inhalt.

## Verifikation (End-to-End)
1. `contao:migrate` läuft fehlerfrei; Backend zeigt „Übersicht“ und „Trainer-Workflows“.
2. In der Übersicht **Import ausführen** → Einträge mit Status 0, Token, E-Mail, Daten.
3. **Einladungen senden** → Mail (Mailpit) mit `##link##`; Status → 1, `sentAt` gesetzt.
4. Link öffnen → Formular vorausgefüllt; Antwortfelder ausfüllen + (falls aktiv)
   unterschreiben → absenden.
5. Eintrag: Status 2, Antwortwerte (in den Speicherfeldern) und ggf. `signature`
   gesetzt; PDF unter `var/trainer_pdfs/<id>/<token>.pdf`; Ergebnis-Mail mit PDF-Anhang.
6. Übersicht: Zähler (eingegangen/offen) + Liste der ausstehenden Antworten.
7. **Export (XLSX/CSV)** lädt herunter; **PDFs herunterladen** liefert ZIP.
8. **Erinnerung senden** → nur Einträge mit Status 1 erhalten eine Erinnerung.

## Hinweise
- **PDF-Vorlagen (Master/Body) erstellen** – Syntax, Variablen, mPDF-Regeln und der
  `.docm`-Konverter: siehe [docs/PDF-TEMPLATES.md](docs/PDF-TEMPLATES.md).
- **Produktiv-Betrieb & Mailversand:** siehe [../DEPLOYMENT.md](../DEPLOYMENT.md)
  (Worker/Cron, SMTP, SPF/DKIM/DMARC, Skalierung 100–300, all-inkl-Hosting).
- **E-Mails werden asynchron** über Symfony Messenger versendet (Queue
  `tl_message_queue`). Ohne laufenden Worker/Cron (`contao:cron` bzw.
  `messenger:consume`) wird nichts zugestellt, obwohl der Versand als erfolgreich gilt.
- Generierte PDFs liegen unter `%kernel.project_dir%/var/trainer_pdfs/` (nicht
  öffentlich) und werden nur über die authentifizierten Backend-Routen gestreamt.
- PDF-Anhang in der Ergebnis-Mail: setzt voraus, dass das Notification Center den
  über `##attachment##` übergebenen Dateipfad als Anhang verarbeitet. Falls die
  installierte NC-Version das nicht abdeckt, `do-while/contao-pdf-nc-attachment-bundle`
  ergänzen oder die Ergebnis-Mail auf Symfony-Mailer mit direktem Anhang umstellen
  (`NotificationDispatcher::sendResult`).
- Eine Beispiel-Quelldatei liegt unter `docs/sample-trainers.csv`.
