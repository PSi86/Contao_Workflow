# Anleitung: Workflow einrichten & durchführen

Diese Anleitung beschreibt **alle manuellen Schritte** – Admin-Bedienung **und**
Trainer-Bedienung – um einen Workflow von Grund auf einzurichten und end-to-end
durchzuspielen. Sie gilt für jede Contao-Installation; die Beispielwerte (Verein,
Spalten, „Verzicht" …) stammen aus der mitgelieferten Demo.

Backend-Login: `https://<deine-domain>/contao` mit deinem Contao-Admin-Konto.

> **Wichtig vorab:** E-Mails werden **asynchron** verschickt. Eine Mail kommt erst
> an, wenn ein **Worker/Cron** die Queue abarbeitet (Abschnitt 6, produktiv
> [DEPLOYMENT.md](DEPLOYMENT.md)). Den Formular-Link kannst du auch **ohne Mail**
> abgreifen (Abschnitt 4 b).

---

## 0. Was ist einmalig, was pro Workflow?

| Einmalig (für alle Workflows) | Pro neuem Workflow |
|---|---|
| Formularseite + „Workflow-Formular"-Modul (Abschnitt 1) | Quelldatei hochladen (3 a) |
| Notification Center: Gateway + 3 Notifications (Abschnitt 2) | Workflow anlegen & konfigurieren (3 b) |
| **Briefkopf-Vorlage (Master): Logo + PDF-Variablen (Abschnitt 2b)** | Import, Einladungen, Auswertung (3 c–5) |
| Mail-Worker/Cron (Abschnitt 6) | |

Die Notifications und die Formularseite sind **generisch** und werden von jedem
Workflow wiederverwendet (der Token im Link bestimmt, zu welchem Workflow eine
Antwort gehört). Für einen **weiteren** Workflow brauchst du also nur die rechte
Spalte.

---

## 1. Einmalig: Formularseite + Modul

So wird die Formularseite **sauber** in eine bestehende Website eingebaut – sie übernimmt das
**Layout der Website**, taucht **nicht** in der Navigation auf und verändert **keine** anderen
Seiten:

1. **Modul anlegen:** *Layout → Themes → (dein Theme) → Module → Neu* → Typ **„Workflow-Formular"**
   (Kategorie *Anwendungen*), Name z. B. „Workflow-Formular". Speichern.
2. **Seite anlegen:** *Seitenstruktur → Neue Seite* (Typ **Reguläre Seite**), z. B. „Formular",
   **veröffentlicht**. Dabei:
   - **Layout der Website übernehmen:** das Häkchen **„Eigenes Seitenlayout"** *nicht* setzen –
     dann **erbt** die Seite das Layout der übergeordneten Seite und sieht aus wie der Rest der
     Site. *(Alternativ ein bereits vorhandenes Layout explizit zuweisen – aber **kein** neues,
     leeres Layout anlegen, sonst erscheint die Seite ohne Kopf/Fuß.)*
   - **Aus dem Menü nehmen:** unter **Experteneinstellungen** die Option **„Seite im Menü
     verbergen"** aktivieren (sonst erscheint die Seite in der Navigation). Optional zusätzlich
     „Diese Seite nicht durchsuchen".
3. **Modul auf die Seite:** in dieser Seite einen **Artikel** in der **Hauptspalte** anlegen →
   darin ein **Inhaltselement „Modul"** einfügen und das „Workflow-Formular"-Modul auswählen.
   Speichern.
4. Am Workflow diese Seite als **Formularseite** wählen (Abschnitt 3 b, Punkt 8).

Die **Formular-URL** ergibt sich aus dem **Alias dieser Seite** + Token (z. B. `/formular/<token>`),
**nicht** zwangsläufig `/workflow/…`. Den exakten Link zeigt jeder **Eintrag** beim Feld *Token*.

> Der mitgelieferte **Demo-Workflow** legt genau so eine Seite automatisch an – im Menü
> versteckt, ein vorhandenes Site-Layout erbend, Modul per Inhaltselement – **ohne** bestehende
> Seiten/Layouts zu verändern. Du kannst sie als Vorlage ansehen.

---

## 2. Einmalig: Notification Center

1. **Notification Center → Gateways → Neu** → Typ **E-Mail** (Mailer). Speichern.
2. **Notification Center → Notifications** → drei Stück anlegen, jeweils Typ
   **„Workflow"**:
   - **Einladung**, **Erinnerung**, **Ergebnis**.
   - Pro Notification eine **Nachricht** (Gateway = E-Mail, *veröffentlicht*) und
     darunter eine **Sprache** „de" (*Fallback*) mit:
     - **Empfänger:** `##email##`
     - **Absender:** z. B. `noreply@deine-domain.de`
     - **Betreff/Text** frei; bei **Einladung/Erinnerung** den Link einbauen: `##link##`
     - bei **Ergebnis**: Feld **„Anhänge über Tokens" = `##attachment##`** (hängt das PDF an)
   - Verfügbare Tokens: `##email##`, `##link##`, `##workflow_title##`,
     `##attachment##`, `##data_<Spalte>##` (jede importierte Spalte – inkl. der
     gespeicherten Antwortwerte, z. B. `##data_verzicht##`).

---

## 2b. Einmalig: Briefkopf-Vorlage (Master)

Der **Briefkopf (Master)** bündelt **Layout-Vorlage + Logo + PDF-Variablen** und wird
von Workflows wiederverwendet. **Logo und PDF-Variablen werden nur hier gepflegt**,
nicht mehr im Workflow.

1. **Workflow → Briefkopf-Vorlagen → Neu.**
2. **Titel** (z. B. „Musterverein Briefkopf").
3. **Layout-Vorlage** wählen (z. B. `pdf_master` – Kopf/Fuß/Unterschrift).
4. **PDF-Logo** auswählen (Bilddatei aus der Dateiverwaltung).
5. **PDF-Variablen**: werden nach Wahl der Layout-Vorlage automatisch vorgeschlagen
   (z. B. `Jahr`, `Verein`, `Ort`, `Footer`) – nur noch Werte eintragen.
6. Speichern. Diesen Briefkopf können beliebig viele Workflows nutzen.

---

## 3. Pro Workflow: Admin-Einrichtung

> **Abkürzung – Konfiguration importieren:** Statt alles von Hand anzulegen, kannst du in der
> **Übersicht** unter *„Workflow-Konfiguration importieren"* eine exportierte/bereitgestellte
> **`.json`-Datei** hochladen und optional **Briefpapier** + **E-Mail-Vorlagen** mit anlegen lassen. Der so erzeugte Workflow ist noch **ohne Quelldatei** und damit „nicht
> ausführbar" – es bleibt also nur Schritt 3 a (Quelldatei hochladen) + die Spalten-Zuordnung
> (3 b, Punkte 3–6). Umgekehrt exportiert *„Konfiguration exportieren"* an jedem Workflow seine
> Einstellungen als portable Datei (ohne Logo/Quelldatei/Formularseite).

### 3 a. Quelldatei hochladen
**Dateiverwaltung → Ordner `files`** → CSV/XLSX hochladen
(z. B. `files/analyse/basistabelle-2026.xlsx`).

### 3 b. Workflow anlegen & konfigurieren
**Workflow → Workflows → Neu.** Die **gesamte** Konfiguration liegt in
**„Bearbeiten"** (in Abschnitte gegliedert: *Allgemein · Schritte · Quelldaten · Formular &
Antwortfelder · PDF – Standard-Inhalt · PDF-Regeln · Benachrichtigungen*). In der Workflow-Liste
gibt es pro Zeile nur **Bearbeiten** (Konfiguration) und **Einträge** (Antworten/Daten).
Felder in **dieser Reihenfolge** (einige Listen befüllen sich erst aus der Datei):

1. **Titel**, **Veröffentlicht** ankreuzen.
2. **Schritte:** z. B. `Importiert`, `Eingeladen`, `Beantwortet`.
3. **Quelldatei** auswählen → **Speichern** (jetzt liest das System die Datei ein).
4. **Tabellenblatt** wählen (z. B. `Übungsleiter`) und **Kopfzeile** (i. d. R. `1`).
   Beide lösen ein automatisches Neuladen aus.
5. **E-Mail-Spalte** wählen (z. B. `eMail`).
6. **Anzeige-Felder (Input):** die Spalten ankreuzen, die im Formular vorausgefüllt
   und schreibgeschützt erscheinen sollen (z. B. Name, Vorname, Geburtsdatum,
   Tätigkeit in Abteilung, Tätigkeit, Abteilung, Höhe der ÜLP).
7. **Unterschrift verlangen:** ankreuzen, wenn der Trainer im Formular unterschreiben
   muss (die Unterschrift wird ins PDF eingebettet). Bei aktiver Option zusätzlich **Datum**
   und **Ort für die Unterschriftszeile** wählen – je ein **Datenfeld** (z. B. ein
   „Aktuelle Zeit"-Antwortfeld als Datum, die Spalte `Wohnort` als Ort). So steht im PDF
   genau der gespeicherte Wert (PDF == DB == Export). Sonst entfällt das Unterschriftsfeld.
8. **Formularseite** = die Seite aus Abschnitt 1.
9. **PDF-Inhalt:**
   - **Briefkopf-Vorlage** (Master) auswählen – sie bringt **Logo + Variablen + Layout**
     mit (vorausgewählt, falls nur einer existiert). Logo/Variablen werden **nicht**
     hier, sondern unter „Briefkopf-Vorlagen" gepflegt (Abschnitt 2b).
   - **PDF-Dateiname:** Muster mit Platzhaltern (z. B. `Verzicht_##data_name##_##data_vorname##`);
     wird zu einem sicheren Dateinamen bereinigt, bei Namensgleichheit folgt ein kurzer Token.
     Leer = Eintrags-Token.
   - **PDF-Inhalt** wählen:
     - **Einfacher Brief** (online, ohne Datei): hier nur die gemeinsame **Überschrift**
       eintragen. Die eigentlichen **Brieftexte** kommen aus den **PDF-Regeln** (Abschnitt 3 b‑2) —
       so können sie je nach Antwort variieren. Platzhalter (überall identisch – PDF, E-Mail,
       Export): `##data_<slug>##` für jede Quellspalte inkl. Antwortfelder
       (z. B. `##data_vorname##`, `##data_verzicht##`), `##var_<slug>##` für Briefkopf-Variablen
       (z. B. `##var_jahr##`, `##var_verein##`), dazu `##email##`.
     - **Spezielle Vorlage** (detailliertes Layout): **Body-Vorlage** aus der **Auswahlliste**
       wählen (alle `pdf_body_*`-Vorlagen erscheinen automatisch). Die Vorlage **enthält ihre
       eigene Logik** → **PDF-Regeln entfallen** (werden ausgeblendet). Siehe Abschnitt 8.
   - Header (Logo), Unterschrift und Footer kommen aus der gewählten **Briefkopf-Vorlage**;
     die Briefkopf-Variablen (`##var_jahr##`, `##var_verein##`, …) stammen ebenfalls von dort.
10. **Benachrichtigungen:** Einladung / Erinnerung / Ergebnis zuordnen.
11. **Speichern.**

### 3 b‑1. Antwortfelder  *(Abschnitt „Formular & Antwortfelder" in „Bearbeiten")*

Im Abschnitt **Antwortfelder** mit **„Neu"** ein Feld anlegen, mit **„Bearbeiten"** im Dialog
öffnen (Sortierung per Drag&Drop). Pro Feld:
- **Beschriftung** (die Frage im Formular),
- **Typ:** Freitext (ein-/mehrzeilig), Dropdown, Radio-Buttons, Checkboxen
  (Mehrfachauswahl), Datum oder **Aktuelle Zeit**,
- **Speicherfeld** (Pflicht): die **Quellspalte**, in die der Wert geschrieben wird –
  fließt in Export und PDF-Tokens,
- **Pflichtfeld:** muss im Formular ausgefüllt werden,
- **Aktuelle Zeit**: wird beim Absenden **automatisch** mit dem aktuellen Datum gefüllt
  (kein Eingabefeld). Mit **„Feld im Formular ausblenden"** erscheint es gar nicht im
  Formular; „Pflichtfeld" entfällt. Ideal als Datums­quelle für die Unterschriftszeile.
- bei Options­typen die **Optionen**: je Zeile **Wert** (wird gespeichert) +
  **Options-Text** (wird angezeigt). Mit den **+/–-Buttons** Zeilen hinzufügen/entfernen.

Beispiel (Demo): Typ **Radio**, Speicherfeld `Verzicht`, zwei Optionen
„Akzeptieren"→`ja` und „Ablehnen"→`nein`; dazu ein **Aktuelle Zeit**-Feld (im Formular
ausgeblendet) mit Speicherfeld `Datum Verzicht`, das als Unterschriftsdatum dient.

### 3 b‑2. PDF-Regeln = die Brieftexte  *(Abschnitt „PDF-Regeln" in „Bearbeiten")*

Bei **PDF-Inhalt = Einfacher Brief** stehen **alle Brieftexte** als Regeln in **einer Liste**
(bei *Spezielle Vorlage* ist dieser Abschnitt ausgeblendet — dann steckt die Logik im Template).
**„Neu"** legt einen Text an, **„Bearbeiten"** öffnet ihn im Dialog. Pro Regel:
- **Bezeichnung** (z. B. „Zustimmung", „Ablehnung"),
- **Standardtext** (Checkbox): aktivieren für den „Sonst"-Fall — die Regel gilt dann **immer**,
  und die **Bedingungen werden ausgeblendet**. In der Liste erscheint automatisch **„(Standardtext)"**.
  Es darf **nur eine** Standardtext-Regel geben; gibt es mehrere bzw. keine, zeigt die Regel-Liste
  einen entsprechenden **Hinweis/Fehler**. Standardtext-Regel ans Ende stellen.
- **Bedingungen** (nur ohne Standardtext sichtbar; je Zeile **Antwortfeld / Operator / Wert**,
  alle UND-verknüpft; Operatoren u. a. =, ≠, <, ≤, >, ≥, enthält, ist leer).
- **Brieftext** mit `##Platzhaltern##` (Überschrift/Logo/Unterschrift/Footer kommen aus Workflow
  bzw. Briefkopf).

Die Liste zeigt je Regel **Bezeichnung und Bedingung** (bzw. „(Standardtext)"). Geprüft wird
**von oben nach unten**; die **erste passende** Regel liefert den Text.

### 3 b‑3. Wie wird entschieden, welcher Text ins PDF kommt?

Das ist der **zentrale Zusammenhang**. Verbindungsglied zwischen Antwort und PDF ist immer das
**Speicherfeld** (die Spalte) eines Antwortfelds:

1. Der Trainer wählt im Formular eine Option (z. B. „Akzeptieren").
2. Deren **Wert** (z. B. `ja`) wird in das **Speicherfeld** geschrieben (z. B. Spalte `Verzicht`).
3. Beim PDF-Bauen prüft die **Regel-Engine** die Regeln der Reihe nach gegen die gespeicherten
   Werte. Die **erste passende Regel** liefert den Brieftext (die als **Standardtext** markierte
   Regel trifft immer).

**Beispiel-Einrichtung (komplett im Backend, ohne Vorlagen-Datei):**
- **Antwortfeld** „Ihre Entscheidung" (Radio): `Akzeptieren`→`ja`, `Ablehnen`→`nein`, Speicherfeld **`Verzicht`**.
- **PDF-Inhalt** = *Einfacher Brief*, **Überschrift** „Verzichtserklärung …".
- **PDF-Regeln** (beide Texte beieinander):
  1. „Verzicht: Zustimmung" — Bedingung **`Verzicht` ist gleich `ja`** → Zustimmungstext.
  2. „Verzicht: Ablehnung" — **Standardtext** aktiviert (gilt immer) → Ablehnungstext.

→ `ja` trifft Regel 1; `nein` fällt auf den Standardtext (Regel 2). Beide Texte stehen
gleichberechtigt in den Regeln; der Zusammenhang ist über das Dropdown „Antwortfeld = `Verzicht`" sichtbar.
**Kein `.html5`-Code nötig.**

**Alternative für komplexe Formulare – Verzweigung in einer Vorlage:** Wenn die Logik zu komplex
für Regeln wird oder ein pixelgenaues Layout nötig ist, **PDF-Inhalt = Spezielle Vorlage** wählen.
Dann steckt die gesamte Entscheidung **im Template** (Beispiel `pdf_body_verzicht.html5`:
`$accept = 'ja' === $d('Verzicht')`), die PDF-Regeln entfallen. Verknüpfung ist hier der
**Spaltenname** im Vorlagen-Code — das ist Datei-Arbeit (siehe Abschnitt 8). Beide Wege sind
gleichwertig unterstützt; die Regel-Variante ist für einfache Fälle transparenter.

### 3 c. Import
**Workflow → Übersicht** → beim Workflow **„Import ausführen"**.
Kontrolle: in **Workflows → (Workflow) → Einträge** stehen die Personen mit **Status 0**.

### 3 d. Einladungen senden
**Übersicht → „Einladungen senden"** → Status wird auf **1** gesetzt, pro Person
wird eine Einladungsmail mit individuellem Link eingereiht.
*(Jetzt muss ein Worker/Cron laufen, damit die Mail wirklich rausgeht – Abschnitt 6.)*

---

## 4. Trainer-Bedienung (Frontend)

### 4 a. Üblicher Weg: Link aus der E-Mail
Der Trainer öffnet den Link aus der Einladungsmail (`…/workflow/<token>`) und:
1. sieht oben seine **vorausgefüllten, schreibgeschützten Daten** (zur Kontrolle),
2. füllt die **Antwortfelder** aus (je nach Konfiguration Auswahl, Freitext, Datum …),
3. **unterschreibt** im Unterschriftenfeld (Maus/Finger/Stift) – nur wenn am Workflow
   *Unterschrift verlangen* aktiv ist; „Unterschrift löschen" korrigiert,
4. klickt **„Absenden"** → Bestätigungsseite „Vielen Dank…".

Folge: Status → **2**; das **PDF** wird erzeugt und sicher gespeichert; eine
**Ergebnis-Mail mit PDF-Anhang** wird eingereiht. Ein bereits beantworteter Link
zeigt „bereits übermittelt".

### 4 b. Ohne Mail an den Link kommen (zum Testen)
**Workflows → (Workflow) → Einträge → (Eintrag öffnen):** beim Feld **„Token"** wird direkt
der **fertige Formular-Link** angezeigt (zum Kopieren). Die URL ist immer
`<URL deiner Formularseite>/<Token>` – also der **Alias deiner Formularseite** (nicht
zwangsläufig `/workflow/…`) plus Token, **ohne** abschließenden Slash. Einfach den
angezeigten Link öffnen.

> **Häufige 404-Ursache:** eine selbst getippte URL wie `/workflow/<Token>` trifft nur, wenn
> deine Formularseite tatsächlich den Alias `workflow` hat. Maßgeblich ist immer der Alias der
> Seite, die am Workflow als *Formularseite* gewählt ist – nutze den im Eintrag angezeigten Link.

---

## 5. Auswertung & Nachfass (Admin)

**Workflow → Übersicht** zeigt pro Workflow eine eigene Karte mit:
- Zähler **eingegangen / offen / gesamt**. Ist die Quelldatei geladen, aber **noch nicht
  importiert** (0 Antworten), erscheint ein Hinweis. Nicht ausführbare Workflows (fehlende
  oder unpassende Quelldatei) sind mit Badge markiert und ihre Aktionen gesperrt.
- die **Liste der ausstehenden Personen** – mit Name/Vorname (falls vorhanden), **je Spalte
  sortierbar**, **Checkbox je Zeile**, „Alle"/„Alle aufheben" und je Schritt einem Auswahl-Button.
- **„E-Mails senden"** öffnet einen Dialog: **Automatisch** (Adressaten nach Status) oder
  **Manuelle Auswahl** (die markierten Personen), darunter **„Einladungen senden"** bzw.
  **„Erinnerungen senden"** (mit Anzahl) und einem **Bestätigungsschritt** mit der konkreten
  Empfängerliste. Einladungen gehen an Status 0, Erinnerungen an Status 1.
- **„Export (XLSX)" / „Export (CSV)"** → die **Quellspalten in Originalreihenfolge**,
  gefüllt mit den aktuellen Daten (inkl. der gespeicherten Antwortwerte).
- **„PDFs herunterladen"** → ZIP der erzeugten PDFs (nur die dieses Workflows).

---

## 6. End-to-End-Testen: E-Mails zustellen

E-Mails werden **asynchron** über Symfony Messenger verschickt. „Einladungen/
Erinnerungen senden" und das Formular-Absenden (Ergebnis-Mail) reihen die Mail nur in
die Queue ein; **zugestellt** wird sie erst, wenn ein **Worker/Cron** die Queue
abarbeitet – produktiv per Cron, zum Testen manuell:

```bash
vendor/bin/contao-console messenger:consume contao_prio_high contao_prio_normal contao_prio_low --time-limit=20
```

Danach die Mails im **Posteingang** (lokal: im Mailcatcher) prüfen: Einladung/
Erinnerung mit Link und die Ergebnis-Mail mit PDF-Anhang. Produktiv-Setup (Cron,
SMTP, SPF/DKIM/DMARC): siehe [DEPLOYMENT.md](DEPLOYMENT.md).

**CLI-Alternativen** zu den Übersicht-Buttons (`<id>` = Workflow-ID):
```bash
vendor/bin/contao-console workflow:import <id>
vendor/bin/contao-console workflow:send <id>             # Einladungen
vendor/bin/contao-console workflow:send <id> --reminder  # Erinnerungen
vendor/bin/contao-console workflow:export <id> --out=export.xlsx
```

**Erzeugte PDFs:** `var/workflow_pdfs/<WorkflowId>/<Token>.pdf` (außerhalb des
Web-Roots, nur über die Backend-Routen abrufbar).

---

## 7. Kompakter End-to-End-Durchlauf (zum Abhaken)

1. [ ] Quelldatei hochladen (3 a)
2. [ ] Workflow anlegen & konfigurieren (3 b) → **Speichern**
3. [ ] **Import ausführen** → Einträge mit Status 0 (3 c)
4. [ ] **Einladungen senden** → Status 1 (3 d)
5. [ ] Worker/Cron → **Posteingang**: Einladung mit Link (6)
6. [ ] Link öffnen → Daten prüfen → Option wählen → unterschreiben → **Absenden** (4)
7. [ ] Eintrag steht auf Status 2; PDF unter `var/workflow_pdfs/…`
8. [ ] Worker/Cron → **Posteingang**: Ergebnis-Mail **mit PDF-Anhang**
9. [ ] **Übersicht**: Zähler stimmen; **Export** + **PDFs-ZIP** herunterladen
10. [ ] **Erinnerung senden** → nur offene Personen erhalten eine Mail

---

## 8. PDF-Vorlagen: Briefkopf (Master) + Body

Jedes PDF besteht aus zwei Teilen:
- dem **Briefkopf (Master)** – eine eigene Backend-Einheit unter „Briefkopf-Vorlagen"
  (Abschnitt 2b), die **Layout-Vorlage (`pdf_master*`) + Logo + PDF-Variablen** bündelt.
  Mehrere Briefköpfe möglich (z. B. verschiedene Vereine/Layouts); ein Workflow wählt einen.
- dem **workflow-individuellen Body**. Für den Body gibt es zwei Wege:

**a) Einfacher Brief – komplett online, ohne Datei (Standard)**
Im Workflow „PDF-Inhalt = Einfacher Brief" wählen, die gemeinsame **Überschrift** eintragen und
die **Brieftexte** als **PDF-Regeln** pflegen (je nach Antwort, mit `##Platzhaltern##`; siehe
3 b‑2 / 3 b‑3). Ideal, wenn schnell ein neuer Brief gebraucht wird – **kein Entwickler, kein
Deployment** nötig.

**b) Spezielle Vorlage – für detaillierte Layouts (Datei)**
Für pixelgenaue/komplexe Layouts eine eigene **Body-Vorlage** als Datei anlegen:
1. Datei `pdf_body_xyz.html5` erstellen (nur der Body – **kein** Logo/Unterschrift,
   die liefert der Master). Verfügbare Variablen im Template: `$this->data`
   (alle Spalten **inkl. der gespeicherten Antwortwerte**) und `$this->extra`
   (PDF-Variablen). Die gesamte Verzweigung passiert **im Template** auf Basis der
   Antwortwerte (z. B. `$accept = 'ja' === ($this->data['Verzicht'] ?? '')`) — bei
   „Spezielle Vorlage" gibt es **keine** PDF-Regeln. Vorlage zum Abschauen:
   `pdf_body_verzicht.html5`.
2. Datei in den **`templates/`**-Ordner des Projekts legen (lokal) bzw. produktiv per FTP/KAS
   in den `templates/`-Ordner hochladen. *(Contao 5 hat keinen Online-Editor für
   Template-Dateien – dieser Schritt ist Datei-Arbeit/Deployment.)*
   Wichtig: Der Name muss mit **`pdf_body_`** beginnen, damit die Vorlage
   automatisch in der Auswahlliste erscheint.
3. Im Workflow „PDF-Inhalt = Spezielle Vorlage" und unter **Body-Vorlage** die
   Vorlage aus der **Auswahlliste** wählen.

**Eigenes Briefkopf-Layout (Master):** analog eine Datei `pdf_master_xyz.html5`
anlegen (Logo/Header/Unterschrift/Footer; bekommt `$this->bodyHtml`, `$this->logoSrc`,
`$this->signatureSrc`, `$this->signerName`, `$this->ort`, `$this->datum`, `$this->footer`),
nach `templates/` legen → erscheint in „Briefkopf-Vorlagen → Layout-Vorlage".
`$this->ort`/`$this->datum` stammen aus den Workflow-Feldern *Ort/Datum für Unterschriftszeile*
(nicht mehr automatisch). Die mitgelieferte `pdf_master` ist ein **neutraler Beispiel-Briefkopf** (Musterverein) mit
Lauf-Kopf-/Fußzeile (siehe [PDF-TEMPLATES.md](PDF-TEMPLATES.md)).
Feste Variablen, die ein Master-Layout anbietet, in
`contao/config/config.php` unter `$GLOBALS['TL_WORKFLOW_PDF_VARS']` als
`'pdf_master_xyz' => ['Jahr' => date('Y'), 'Verein' => '', …]` ergänzen – sie werden
dann im Briefkopf automatisch vorgeschlagen.

> **Kurz:** Auswahl/Texte/Logo/Variablen = **online**. Eine neue **Datei-Vorlage**
> (Body `pdf_body_*` oder Master `pdf_master_*`) wird wie Code behandelt und auf den
> Server übertragen. Die Word-/`.docm`-Umwandlung in eine Vorlage ist
> Entwickler-Handarbeit (einmalig).

**Template-Syntax, alle verfügbaren Variablen und mPDF-Regeln** sind ausführlich
dokumentiert in [PDF-TEMPLATES.md](PDF-TEMPLATES.md).

---

Installation: [INSTALL.md](INSTALL.md). Produktivbetrieb (Mail-Worker per Cron,
SPF/DKIM/DMARC, all-inkl): [DEPLOYMENT.md](DEPLOYMENT.md). Bundle-Details:
[README.md](../README.md).
