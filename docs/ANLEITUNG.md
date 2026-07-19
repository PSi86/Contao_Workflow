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
4. Am Workflow diese Seite als **Formularseite** wählen (Abschnitt 3 b, Punkt 7).

Die **Formular-URL** ergibt sich aus dem **Alias dieser Seite** + Token (z. B. `/formular/<token>`),
**nicht** zwangsläufig `/workflow/…`. Den exakten Link zeigt jeder **Eintrag** beim Feld *Token*.

> Der mitgelieferte **Demo-Workflow** legt genau so eine Seite automatisch an: **„Workflow-Formular"**
> (Alias **`/workflow-formular`**), im Menü versteckt, ein vorhandenes Site-Layout erbend, Modul per
> Inhaltselement und auf **„noindex,nofollow"** gesetzt – **ohne** bestehende Seiten/Layouts zu
> verändern. Diese Seite ist **generisch**: Da das Modul Eintrag und Workflow allein am **Token**
> erkennt, kann **jeder** Workflow sie als Formularseite verwenden – du brauchst i. d. R. keine
> zweite anzulegen.

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
     gespeicherten Antwortwerte, z. B. `##data_verzicht##`),
     `##letterhead_<Variable>##` (Briefkopf-Variablen, z. B. `##letterhead_verein##`),
     `##system_year##` / `##system_today##` … (aktuelles Datum/Uhrzeit, ohne
     Konfiguration) sowie `##text_<Speicherfeld>##` / `##text_all##` (die
     **Dokument-Texte** der Formularfelder, z. B. um in der Ergebnis-Mail die Auswahl
     wörtlich zu zitieren).
   - **Achtung – Vorschlagsliste ist unvollständig:** Die `##`-Auto-Vorschläge des
     Notification Center zeigen nur `##data_*##`, `##email##`, `##link##`,
     `##workflow_title##` und `##attachment##` (und je Feld gefiltert nach Kontext).
     Die übrigen (`##letterhead_*##`, `##system_*##`, `##text_*##`) werden **nicht
     vorgeschlagen, beim Versand aber trotzdem ersetzt** – einfach ausschreiben.
     Fehlt zu einem `##data_*##`-Token die passende Spalte im Eintrag, bleibt der
     Platzhalter unersetzt stehen (er wird nicht durch Leerstring ersetzt).

---

## 2b. Einmalig: Briefkopf-Vorlage (Master)

Der **Briefkopf (Master)** bündelt **Layout-Vorlage + Logo + PDF-Variablen** und wird
von Workflows wiederverwendet. **Logo und PDF-Variablen werden nur hier gepflegt**,
nicht mehr im Workflow.

1. **Workflow → Briefkopf-Vorlagen → Neu.**
2. **Titel** (z. B. „Musterverein Briefkopf").
3. **Layout-Vorlage** wählen (z. B. `pdf_master` – Kopf/Fuß/Unterschrift).
4. **PDF-Logo** auswählen (Bilddatei aus der Dateiverwaltung).
5. **PDF-Variablen**: Nach Wahl der Layout-Vorlage erscheinen die passenden Variablen
   **sofort** als beschriftete Felder – ohne Zwischenspeichern. Bei `pdf_master_generic`
   sind sie in **Inhalt** (Kopf-/Fußzeilentexte) und **Layout & Maße** (Seitenränder,
   Schriftgrößen, Spaltenabstand der Fußzeile) gruppiert; darunter lassen sich eigene
   Variablen ergänzen. Nur die Werte eintragen und speichern.
6. Speichern. Diesen Briefkopf können beliebig viele Workflows nutzen.

---

## 3. Pro Workflow: Admin-Einrichtung

> **Abkürzung – Konfiguration importieren:** Statt alles von Hand anzulegen, kannst du in der
> **Übersicht** unter *„Workflow-Konfiguration importieren"* eine exportierte/bereitgestellte
> **`.json`-Datei** hochladen und optional **Briefpapier** + **E-Mail-Vorlagen** mit anlegen lassen. Der so erzeugte Workflow ist noch **ohne Quelldatei** und damit „nicht
> ausführbar" – es bleibt also nur Schritt 3 a (Quelldatei hochladen) + die Spalten-Zuordnung
> (3 b, Punkte 3–4). **Es wird nichts überschrieben:** Briefpapier oder E-Mail-Vorlagen mit
> **bereits vergebenem Namen** werden **übersprungen** und nach dem Import namentlich gemeldet
> (vorhandenes umbenennen oder Namen in der JSON ändern und erneut importieren); ein bereits
> vergebener **Workflow-Titel** bricht den Import ab. Umgekehrt lädt *„Konfiguration
> herunterladen"* in der **Workflow-Liste** (Symbol je Zeile) die Einstellungen eines Workflows
> als portable Datei herunter (ohne Logo/Quelldatei/Formularseite).

### 3 a. Quelldatei hochladen
**Dateiverwaltung → Ordner `files`** → CSV/XLSX hochladen
(z. B. `files/analyse/basistabelle-2026.xlsx`).

### 3 b. Workflow anlegen & konfigurieren
**Workflow → Workflows → Neu.** Die **gesamte** Konfiguration liegt in
**„Bearbeiten"** (in Abschnitte gegliedert: *Allgemein · Quelldaten ·
Inhalt (Formular & Dokument) · Formular & Formularfelder · Dokument-Einstellungen · Dokument-Texte ·
Benachrichtigungen · Zurücksetzen*). In der Workflow-Liste
gibt es pro Zeile **Bearbeiten** (Konfiguration), **Einträge** (Antworten/Daten) und
**Konfiguration herunterladen** (JSON-Export).
Felder in **dieser Reihenfolge** (einige Listen befüllen sich erst aus der Datei):

1. **Titel**, **Veröffentlicht** ankreuzen. *Veröffentlicht* ist Voraussetzung für den Versand:
   Solange der Haken fehlt, weist die Übersicht den Versand von Einladungen und Erinnerungen ab
   – die Links würden den Empfängern sonst als „ungültig" angezeigt.
2. **Quelldatei** auswählen → **Speichern** (jetzt liest das System die Datei ein).
3. **Tabellenblatt** wählen (z. B. `Übungsleiter`) und **Kopfzeile** (i. d. R. `1`).
   Beide lösen ein automatisches Neuladen aus.
4. **E-Mail-Spalte** wählen (z. B. `eMail`).
5. **Inhalt (Formular & Dokument):** die **Überschrift** und der optionale
   **Einleitungstext** erscheinen **identisch im Formular und im PDF** (oben, vor den
   Feldern bzw. dem Dokument-Text). Platzhalter wie `##data_vorname##` oder `##letterhead_verein##`
   und `{{Insert-Tags}}` sind erlaubt; der Einleitungstext unterstützt zusätzlich die
   **Textauszeichnung** `[b]fett[/b]` / `[i]kursiv[/i]` / `[u]unterstrichen[/u]` (die Überschrift nicht).
   So sieht der Trainer im Formular dieselbe Kopfzeile wie später im Dokument.
6. **Unterschrift benötigt:** ankreuzen, wenn der Trainer im Formular unterschreiben
   muss (die Unterschrift wird ins PDF eingebettet). Bei aktiver Option erscheinen
   **darunter** zusätzlich **Datum** und **Ort für die Unterschriftszeile** – je ein **Datenfeld** (z. B. ein
   „Aktuelle Zeit"-Formularfeld als Datum, die Spalte `Wohnort` als Ort). So steht im PDF
   genau der gespeicherte Wert (PDF == DB == Export). Sonst entfällt das Unterschriftsfeld.
7. **Formularseite** = die Seite aus Abschnitt 1.
8. **Formularfelder** anlegen (Abschnitt 3 b‑1) – auch schreibgeschützte Anzeige-Felder
   (z. B. Name, Vorname, Abteilung zur Kontrolle) sind jetzt normale Formularfelder mit der
   Option **„Schreibgeschützt"**.
9. **Dokument-Einstellungen:**
    - **Briefkopf-Vorlage** (Master) auswählen – sie bringt **Logo + Variablen + Layout**
      mit (vorausgewählt, falls nur einer existiert). Logo/Variablen werden **nicht**
      hier, sondern unter „Briefkopf-Vorlagen" gepflegt (Abschnitt 2b).
    - **PDF-Dateiname:** Muster mit Platzhaltern und `{{Insert-Tags}}` (z. B.
      `Verzicht_##data_name##_##data_vorname##` oder `Verzicht_##data_name##_{{date::Y}}`); die
      Eingabe von `##` bzw. `{` blendet eine Vorschlagsliste ein. Wird zu einem sicheren
      Dateinamen bereinigt, bei Namensgleichheit folgt ein kurzer Token. Leer = Eintrags-Token.
    - **Dokument-Inhalt** wählen:
      - **Einfacher Brief** (online, ohne Datei): die **Dokument-Texte** stammen aus dem gleichnamigen Abschnitt
        **Dokument-Texte** (Abschnitt 3 b‑2) — so können sie je nach Antwort variieren.
        Platzhalter (überall identisch – PDF, E-Mail, Export): `##data_<slug>##` für jede
        Quellspalte inkl. Formularfelder (z. B. `##data_vorname##`, `##data_verzicht##`),
        `##letterhead_<slug>##` für Briefkopf-Variablen (z. B. `##letterhead_verein##`, `##letterhead_ort##`),
        `##system_year##` / `##system_month##` / `##system_today##` / `##system_time##` /
        `##system_datetime##` (eingebaute Datums-/Zeit-Platzhalter – aktuelles Jahr/Datum/Uhrzeit,
        ohne Konfiguration überall verfügbar),
        `##text_<speicherfeld>##` / `##text_all##` für die **Dokument-Texte der
        Formularfelder** (Abschnitt 3 b‑3), dazu `##email##`. Zusätzlich funktionieren in allen Textfeldern des Workflows
        (Überschrift, Einleitung, Dokument-Text/Textbaustein, Dateiname) auch
        **Contao-Insert-Tags** `{{…}}` (z. B. `{{date::d.m.Y}}`). In den Dokument-Texten
        (Regel, je Feld, je Option), im Einleitungstext und in der Feld-Beschreibung
        formatieren zudem `[b]fett[/b]`, `[i]kursiv[/i]` und `[u]unterstrichen[/u]` den Text
        (im PDF und in der Formular-Vorschau; in E-Mails werden die Marker entfernt).
      - **Spezielle Vorlage** (detailliertes Layout): **Body-Vorlage** aus der **Auswahlliste**
        wählen (alle `pdf_body_*`-Vorlagen erscheinen automatisch). Die Vorlage **enthält ihre
        eigene Logik** → **die Dokument-Texte entfallen** (werden ausgeblendet). Siehe Abschnitt 8.
    - Header (Logo), Unterschrift und Footer kommen aus der gewählten **Briefkopf-Vorlage**;
      die Briefkopf-Variablen (`##letterhead_verein##`, `##letterhead_ort##`, …) stammen ebenfalls von dort.
10. **Benachrichtigungen:** Einladung / Erinnerung / Ergebnis zuordnen.
11. **Speichern.**

> ### Gesperrte Einstellungen, sobald Antworten vorliegen
>
> Antwortet der erste Teilnehmer, werden die Einstellungen gesperrt, die festlegen **was die
> gespeicherten Daten bedeuten**: **Tabellenblatt**, **Kopfzeile**, **E-Mail-Spalte** und das
> **Speicherfeld** der Formularfelder; außerdem lassen sich **keine Formularfelder mehr anlegen
> oder löschen**. Eine Änderung würde die erfassten Antworten überschreiben oder von ihrer Frage
> trennen – obwohl auf ihrer Grundlage bereits Dokumente ausgestellt und versendet wurden. Die
> betroffenen Felder sind ausgegraut und mit einem Schloss markiert, oben erscheint ein Hinweis
> mit der Anzahl der Antworten.
>
> **Frei änderbar bleiben** Wortlaut, Überschrift, Einleitungs- und Dokument-Texte, Optionen,
> Briefpapier, Dateiname und Benachrichtigungen – sie ändern nicht, was die Daten bedeuten.
>
> **Die Quelldatei bleibt austauschbar**, aber nur gegen eine mit **exakt denselben Spalten**.
> Genau so werden Teilnehmer mitten im Durchlauf nachgemeldet oder Daten korrigiert: den
> aktuellen Export hochladen und **Import ausführen**. Weichen die Spalten ab, wird das
> Speichern mit Nennung der Unterschiede abgelehnt.
>
> **Zwei Wege, eine gesperrte Einstellung doch zu ändern:**
> 1. Für den **nächsten Durchlauf** eine **Kopie** des Workflows anlegen (Workflow-Liste →
>    Kopieren) und dort anpassen. Die Kopie startet unveröffentlicht, ohne Quelldatei und ohne
>    Teilnehmer – die Formularfelder und Dokument-Texte kommen mit.
> 2. **Noch im laufenden Durchlauf:** Abschnitt **„Zurücksetzen"** ganz unten in der
>    Bearbeitungsmaske aufklappen → **„Alle N Teilnehmer zurücksetzen"**. Das verwirft
>    **sämtliche erfassten Antworten** und macht die bereits ausgestellten Dokumente ungültig;
>    danach sind die Einstellungen wieder frei. Erhalten bleiben die importierten Daten, die
>    Links der Teilnehmer und die bisherigen PDF-Dateien (sie werden beim erneuten Absenden
>    überschrieben). Anschließend **Import erneut ausführen**, um die Originaldaten zu laden.

### 3 b‑1. Formularfelder  *(Abschnitt „Formular & Formularfelder" in „Bearbeiten")*

Im Abschnitt **Formularfelder** mit **„Neu"** ein Feld anlegen, mit **„Bearbeiten"** im Dialog
öffnen. Die **Reihenfolge** wird **direkt in der Liste per Drag & Drop** geändert (Griff ☰
links in jeder Zeile ziehen – die neue Reihenfolge wird beim **Speichern des Workflows**
übernommen und gilt für Formular **und** `##text_all##` im PDF). Pro Feld:
- **Überschrift** (die Frage im Formular; die Feld-Überschrift, vormals „Beschriftung"),
- **Beschreibung** (optional): ein **nur im Formular** angezeigter Hinweis unterhalb der
  Überschrift (erscheint nur, wenn ausgefüllt); im **Dokument** taucht er **nie** auf –
  Platzhalter, `{{Insert-Tags}}` und die Textauszeichnung `[b]`/`[i]`/`[u]` werden darin aufgelöst,
- **Typ:** Freitext (ein-/mehrzeilig), **Zahl**, Datum, Dropdown, Radio-Buttons,
  Checkboxen (Mehrfachauswahl), **Aktuelle Zeit** oder **Erklärung** (statischer Text ohne Eingabefeld).
  Zum Typ **Zahl** siehe den Kasten weiter unten – er stellt Anforderungen an die Formatierung
  der Quellspalte,
- **Speicherfeld:** die **Quellspalte**, in die der Wert geschrieben wird –
  fließt in Export und PDF-Tokens. Bei Eingabefeldern sinnvollerweise gesetzt (ohne
  Speicherfeld wird die Antwort nicht gespeichert); bei **„Erklärung"** entfällt es.
  **Sobald Antworten vorliegen, ist es gesperrt** – ebenso das Anlegen und Löschen von
  Formularfeldern (siehe „Gesperrte Einstellungen" in 3 b),
- **Pflichtfeld:** muss im Formular ausgefüllt werden,
- **Mit Wert aus den Daten vorbelegen:** das Feld startet mit dem gespeicherten Wert
  (aus der Quelldatei bzw. einer früheren Antwort) und bleibt **editierbar** –
  „Outputfeld = Inputfeld", z. B. zum Prüfen/Korrigieren importierter Angaben.
  Passt der Wert bei Auswahlfeldern zu keiner Option, bleibt das Feld leer
  (das Backend warnt beim Bearbeiten des Workflows).
- **Schreibgeschützt:** das Feld zeigt den gespeicherten Wert **nur an** (jeder Typ
  möglich; Auswahlfelder erscheinen deaktiviert mit markierter Auswahl). Es wird beim
  Absenden weder geprüft noch gespeichert. So baut man Kontroll-/Anzeige-Felder
  (z. B. Name, Vorname) an beliebiger Position zwischen den Eingabefeldern.
- **Dokument-Text (Textbaustein):** der Satz, der für dieses Feld **im PDF** erscheint
  (und im Formular – sofern **„Textbaustein im Formular anzeigen"** aktiv ist – als Hinweis
  „So erscheint dies im Dokument" live angezeigt wird) –
  siehe Abschnitt 3 b‑3:
  - **Wert-Typen** (Freitext, Zahl, Datum, Aktuelle Zeit) haben **ein** Feld
    „Dokument-Text"; `##answer##` steht für den eingegebenen Wert
    (z. B. `Ich spende ##answer## € an den ##letterhead_verein##.`).
    Leer = „Überschrift: Wert".
  - **Auswahl-Typen** (Dropdown, Radio, Checkboxen) pflegen den Dokument-Text
    **je Option** (dritte Spalte der Optionen-Tabelle). Leer = der sichtbare
    **Options-Text gilt wörtlich** als Dokument-Text.
  - **Textauszeichnung:** `[b]fett[/b]`, `[i]kursiv[/i]` und `[u]unterstrichen[/u]` formatieren
    den Dokument-Text – im PDF und in der Live-Vorschau im Formular.
- **Textbaustein im Formular anzeigen** (Standard: **an**): schaltet die Live-Vorschau
  „So erscheint dies im Dokument" des Dokument-Texts im Formular ein oder aus.
- **Erklärung**: ein **statischer Text-Absatz** – **kein** Eingabefeld und **ohne**
  Speicherfeld. Der Text wird im **Dokument-Text** des Felds eingegeben, erscheint im
  Formular als **Fließtext** und wird ins Dokument übernommen – dort, wo `##text_all##`
  bzw. `##text_*##` steht (siehe Abschnitt 3 b‑3).
- **Aktuelle Zeit**: wird beim Absenden **automatisch** mit dem aktuellen Datum gefüllt
  (kein Eingabefeld). Mit **„Feld im Formular ausblenden"** erscheint es gar nicht im
  Formular; „Pflichtfeld" entfällt. Ideal als Datums­quelle für die Unterschriftszeile.
- bei Options­typen die **Optionen**: je Zeile **Wert** (wird gespeichert) +
  **Options-Text** (wird angezeigt) + **Dokument-Text** (erscheint im PDF; leer =
  Options-Text). Mit den **+/–-Buttons** Zeilen hinzufügen/entfernen.

> **Feldtyp „Zahl": Anforderungen an die Quellspalte**
>
> Ein Zahlenfeld zeigt den gespeicherten Wert, lässt ihn bearbeiten und schreibt ihn zurück.
> Das funktioniert nur, wenn sich die Formatierung der Spalte **reproduzieren** lässt – sonst
> würde der Wert beim Zurückschreiben still verändert. Beim **Speichern des Feldes** wird die
> gewählte Spalte deshalb über alle importierten Zeilen geprüft:
>
> - **Erlaubt:** keine oder genau **zwei** Nachkommastellen. Tausendertrennung ist egal, das
>   **Währungssymbol** ebenfalls (es wird bei der Eingabe ignoriert und bleibt auf dem
>   gespeicherten und gedruckten Wert erhalten).
> - **Abgelehnt:** Prozent-, Datums-, Bruch- und wissenschaftliche Formate, Text in einer
>   Zahlenspalte sowie **gemischte** Nachkommastellen. Die Meldung nennt Zeile, Wert und
>   Excel-Format.
> - **Summenzeilen** (Zeilen ohne E-Mail) bleiben außen vor – sie werden auch nicht importiert.
>
> Passt die Spalte nicht, ist **„Freitext"** die Alternative: dort wird das Format nicht
> geprüft und der Wert unverändert übernommen (z. B. `1.234,56 €`) – er lässt sich dann aber
> auch nicht rechnerisch weiterverwenden.
>
> Die Prüfung meldet sich außerdem beim Bearbeiten des Workflows, falls die Quelldatei später
> gegen eine mit anderer Formatierung getauscht wird. Liegen bereits Antworten vor, wird ein
> Tausch gegen eine Datei mit **anderen Spalten** nicht nur gemeldet, sondern **abgelehnt** –
> mit Nennung der Unterschiede (siehe „Gesperrte Einstellungen" in 3 b).
>
> **Anzeige:** Zahlen erscheinen im Formular, in der Live-Vorschau, im PDF und im Export in
> **deutscher Schreibweise** und identisch – so, wie sie in der Quelldatei formatiert sind
> (`1.234,50 €`). Eingeben darf man tolerant: `1234`, `1234,5`, `1.234,50` und auch `1234.5`
> werden verstanden und beim Speichern ins Format der Spalte gebracht.

Beispiel (Demo): Typ **Radio**, Speicherfeld `Entscheidung`, zwei Optionen
„Einverstanden"→`ja` und „Nicht einverstanden"→`nein`, jeweils mit einem vollständigen
Satz als **Dokument-Text**; dazu schreibgeschützte Felder `Vorname`/`Nachname`/`Abteilung`,
ein **vorbelegtes** Feld `Funktion` und ein **Aktuelle Zeit**-Feld (im Formular
ausgeblendet) mit Speicherfeld `Unterschriftsdatum`, das als Unterschriftsdatum dient.

### 3 b‑2. Dokument-Texte = die Texte des Briefs  *(Abschnitt „Dokument-Texte" in „Bearbeiten")*

Bei **Dokument-Inhalt = Einfacher Brief** stehen **alle Dokument-Texte** als Regeln in **einer Liste**
(bei *Spezielle Vorlage* ist dieser Abschnitt ausgeblendet — dann steckt die Logik im Template).
**„Neu"** legt einen Text an, **„Bearbeiten"** öffnet ihn im Dialog. Pro Regel:
- **Bezeichnung** (z. B. „Zustimmung", „Ablehnung"),
- **Standardtext** (Checkbox): aktivieren für den „Sonst"-Fall — die Regel gilt dann **immer**,
  und die **Bedingungen werden ausgeblendet**. In der Liste erscheint automatisch **„(Standardtext)"**.
  Es darf **nur eine** Standardtext-Regel geben; gibt es mehrere bzw. keine, zeigt die Regel-Liste
  einen entsprechenden **Hinweis/Fehler**. Standardtext-Regel ans Ende stellen.
- **Bedingungen** (nur ohne Standardtext sichtbar; je Zeile **Formularfeld / Operator / Wert**,
  alle UND-verknüpft; Operatoren u. a. =, ≠, <, ≤, >, ≥, enthält, ist leer).
- **Dokument-Text** mit `##Platzhaltern##` (Überschrift/Einleitung/Logo/Unterschrift/Footer
  kommen aus Workflow bzw. Briefkopf). Empfohlen: den Brief aus den **Dokument-Texten der
  Formularfelder** zusammensetzen – `##text_<speicherfeld>##` für ein einzelnes Feld,
  `##text_all##` für **alle** Felder (Abschnitt 3 b‑3).

Die Liste zeigt je Regel **Bezeichnung und Bedingung** (bzw. „(Standardtext)"). Geprüft wird
**von oben nach unten**; die **erste passende** Regel liefert den Text.

### 3 b‑3. Wie kommt der Text ins PDF? (Dokument-Texte + Regeln)

Das ist der **zentrale Zusammenhang**. Grundprinzip: **Was der Trainer im Formular sieht
und auswählt, steht wörtlich im PDF** – das Dokument enthält keine Überraschungen.

**Die Dokument-Texte (Textbausteine)** sind die gemeinsame Textquelle für Formular und PDF:
- Jedes Formularfeld hat einen Dokument-Text (Abschnitt 3 b‑1): bei Auswahlfeldern je Option
  (leer = der sichtbare Options-Text gilt), bei Wert-Feldern ein Satz mit `##answer##`
  (leer = „Überschrift: Wert").
- Im **Formular** wird der Dokument-Text unter dem Feld live angezeigt
  („So erscheint dies im Dokument: …"), sobald ein eigener Text gepflegt ist.
- Sie erscheinen im Dokument (und in Mails) **nur dort, wo im Dokument-Text ein
  `##text_*##`-Platzhalter steht**:
  - `##text_<speicherfeld>##` – fügt den Textbaustein **eines** Felds ein
    (z. B. `##text_entscheidung##`),
  - `##text_all##` – fügt **alle** Bausteine (Formularfelder **und** „Erklärung"-Absätze)
    in Formular-Reihenfolge ein. So kann **kein konfiguriertes Feld im Dokument vergessen
    werden**. Formatierung: Felder ohne eigenen Dokument-Text („Überschrift: Wert") stehen
    zeilenweise untereinander; vor jedem Feld bzw. jeder Erklärung **mit** eigenem Text
    beginnt ein **neuer Absatz** (Leerzeile).
  - Enthält ein Dokument-Text **kein** `##text_*##`, erscheinen die Textbausteine/Erklärungen
    dort **nicht** – der Text wird dann vollständig von Hand geschrieben (z. B. mit einzelnen
    `##data_*##`-Platzhaltern).

**Die Dokument-Texte** (als Regeln) wählen den **Rahmen** um diese Bausteine (z. B. ein zusätzlicher
Dankes-Satz nur bei Zustimmung). Verbindungsglied ist das **Speicherfeld**:

1. Der Trainer wählt im Formular eine Option (z. B. „Einverstanden").
2. Deren **Wert** (z. B. `ja`) wird in das **Speicherfeld** geschrieben (z. B. `Entscheidung`).
3. Beim PDF-Bauen prüft die **Regel-Engine** die Regeln der Reihe nach gegen die gespeicherten
   Werte. Die **erste passende Regel** liefert den Dokument-Text (die als **Standardtext** markierte
   Regel trifft immer). Die `##text_*##`-Platzhalter darin werden mit den Dokument-Texten
   der tatsächlich gegebenen Antworten gefüllt.

**Beispiel-Einrichtung (komplett im Backend, ohne Vorlagen-Datei – so ist die Demo gebaut):**
- **Formularfeld** „Ihre Entscheidung" (Radio, Speicherfeld **`Entscheidung`**) mit zwei
  Optionen, deren **Dokument-Texte** vollständige Sätze sind:
  - „Einverstanden"→`ja`, Dokument-Text *„Hiermit erkläre ich mein Einverständnis gegenüber
    dem ##letterhead_verein## für das Jahr ##system_year##."*
  - „Nicht einverstanden"→`nein`, Dokument-Text *„Für das Jahr ##system_year## erteile ich …
    kein Einverständnis."*
- **Dokument-Texte:**
  1. „Einverständnis erteilt" — Bedingung **`Entscheidung` ist gleich `ja`** →
     Dokument-Text `##text_all##` + „Vielen Dank für Ihre Unterstützung!"
  2. „Standardtext" — **Standardtext** aktiviert (gilt immer) → Dokument-Text `##text_all##`.

→ Der gewählte Satz landet **wörtlich** im PDF (über `##text_all##`), die Regel steuert nur
den Rahmen. **Kein `.html5`-Code nötig.**

**Alternative für komplexe Formulare – Verzweigung in einer Vorlage:** Wenn die Logik zu komplex
für Regeln wird oder ein pixelgenaues Layout nötig ist, **Dokument-Inhalt = Spezielle Vorlage** wählen.
Dann steckt die gesamte Entscheidung **im Template** (Beispiel `pdf_body_verzicht.html5`:
`$accept = 'ja' === $d('Verzicht')`), die Dokument-Texte entfallen. Verknüpfung ist hier der
**Spaltenname** im Vorlagen-Code — das ist Datei-Arbeit (siehe Abschnitt 8). Beide Wege sind
gleichwertig unterstützt; die Regel-Variante ist für einfache Fälle transparenter.

### 3 c. Import
**Workflow → Übersicht** → beim Workflow **„Import ausführen"**.
Kontrolle: in **Workflows → (Workflow) → Einträge** stehen die Personen im Schritt
**„Importiert"**.

> **Der Import läuft immer** – auch dann, wenn die Quelldatei unverändert ist. Genau das ist
> der Weg, die ursprünglichen Quelldaten nach einem Zurücksetzen wieder einzulesen. Die
> Prüfsumme der Datei wird weiterhin gepflegt und treibt unverändert den Hinweis
> „Quelldatei geändert – Import nötig".
>
> **Bereits beantwortete Teilnehmer bleiben vollständig unangetastet** (nur ihre Zeilennummer
> für die Export-Sortierung wird nachgeführt) – ihre Daten sind die Grundlage eines bereits
> ausgestellten Dokuments. Die Meldung nach dem Import weist sie eigens aus, z. B.
> „Import: 3 neu hinzugefügt, 12 aktualisiert, 5 unverändert (bereits beantwortet)".
> Ein zuvor zurückgesetzter Teilnehmer zählt nicht dazu und wird frisch eingelesen.

### 3 d. Einladungen senden
**Übersicht → „Einladungen senden"** → pro Person wird eine Einladungsmail mit individuellem
Link **zum Versand eingereiht**. Der Schritt wechselt **erst nach dem tatsächlichen Versand**
auf **„Eingeladen"**; ein **fehlgeschlagener** Versand lässt ihn auf **„Importiert"** und wird
in der Übersicht als **„Versandfehler"** angezeigt.
*(Es muss also ein Worker/Cron laufen, damit die Mail rausgeht **und** der Status umspringt – Abschnitt 6.)*

---

## 4. Trainer-Bedienung (Frontend)

### 4 a. Üblicher Weg: Link aus der E-Mail
Der Trainer öffnet den Link aus der Einladungsmail (`…/workflow-formular/<token>`) und:
1. sieht oben die **Überschrift** und den **Einleitungstext** des Workflows – exakt wie
   später im PDF – sowie seine **schreibgeschützten Daten** (zur Kontrolle),
2. füllt die **Formularfelder** aus (je nach Konfiguration Auswahl, Freitext, Zahl,
   Datum …; **vorbelegte** Felder zeigen den importierten Wert und können korrigiert
   werden). Hat ein Feld einen eigenen **Dokument-Text**, erscheint darunter live der
   Hinweis **„So erscheint dies im Dokument: …"** mit dem Satz, der ins PDF übernommen
   wird – der Inhalt des PDFs ist damit keine Überraschung,
3. **unterschreibt** im Unterschriftenfeld (Maus/Finger/Stift) – nur wenn am Workflow
   *Unterschrift benötigt* aktiv ist; „Unterschrift löschen" korrigiert,
4. klickt **„Absenden"** → Bestätigungsseite „Vielen Dank…".

Folge: Schritt → **„Beantwortet"**; das **PDF** wird erzeugt und sicher gespeichert; eine
**Ergebnis-Mail mit PDF-Anhang** wird eingereiht. Ein bereits beantworteter Link
zeigt „bereits übermittelt" – bis der Schritt des Teilnehmers zurückgesetzt wird
(siehe 5 a), dann ist derselbe Link wieder gültig.

### 4 b. Ohne Mail an den Link kommen (zum Testen)
**Workflows → (Workflow) → Einträge → (Eintrag öffnen):** unter dem Feld **„Token"** steht der
**fertige Formular-Link**. Ein **Klick darauf markiert ihn vollständig und kopiert ihn** in die
Zwischenablage (kurze Bestätigung „✓ kopiert"). Die URL ist immer
`<URL deiner Formularseite>/<Token>` – also der **Alias deiner Formularseite** (nicht
zwangsläufig `/workflow/…`) plus Token, **ohne** abschließenden Slash. Einfach den
angezeigten Link öffnen.

> **Häufige 404-Ursache:** eine selbst getippte URL wie `/workflow/<Token>` trifft nur, wenn
> deine Formularseite genau diesen Alias hat – die **mitgelieferte** Seite hat den Alias
> `workflow-formular` (Link also `/workflow-formular/<Token>`). Maßgeblich ist immer der Alias der
> Seite, die am Workflow als *Formularseite* gewählt ist – nutze den im Eintrag angezeigten Link.

---

## 5. Auswertung & Nachfass (Admin)

**Workflow → Übersicht** zeigt pro Workflow eine eigene Karte mit:
- Zähler **eingegangen / offen / gesamt**. Ist die Quelldatei geladen, aber **noch nicht
  importiert** (0 Antworten), erscheint ein Hinweis. Nicht ausführbare Workflows (fehlende
  oder unpassende Quelldatei) sind mit Badge markiert und ihre Aktionen gesperrt.
- die Liste **„Offene Vorgänge"** – mit Name/Vorname (falls vorhanden), **je Spalte
  sortierbar**, **Checkbox je Zeile**, „Alle"/„Alle aufheben" und je Schritt einem Auswahl-Button.
  Sie zeigt **alle Teilnehmer, die den letzten Schritt nicht fehlerfrei erreicht haben** – also
  auch solche mit Zustellproblem und solche, deren Schritt zurückgesetzt wurde. Damit deckt sie
  sich mit dem Zähler „offen".
- **„E-Mails senden"** öffnet einen Dialog: **Automatisch** (Adressaten nach Status) oder
  **Manuelle Auswahl** (die markierten Personen), darunter **„Einladungen senden"** bzw.
  **„Erinnerungen senden"** (mit Anzahl) und einem **Bestätigungsschritt** mit der konkreten
  Empfängerliste. Einladungen gehen an Teilnehmer im Schritt „Importiert", Erinnerungen an „Eingeladen".
- **„Export (XLSX)" / „Export (CSV)"** → die **Quellspalten in Originalreihenfolge**,
  gefüllt mit den aktuellen Daten (inkl. der gespeicherten Antwortwerte). Auch die **Zeilen**
  stehen in der Reihenfolge der Quelldatei, der Export lässt sich also direkt dagegen
  vergleichen. Dateiname: `<Workflow>_<Datum>_<Uhrzeit>.xlsx`.
- **„PDFs herunterladen"** → ZIP der erzeugten PDFs (nur die dieses Workflows). Dateiname:
  `<Workflow>_<Datum>_<Uhrzeit>_<Anzahl>-PDFs.zip`, z. B.
  `EStG_Uebungsleiter_20260717_131534_3-PDFs.zip`.
- **„Bearbeiten"** → springt direkt in die Konfiguration dieses Workflows (Modul „Workflows").
- **„Versandfehler"** (nur bei Bedarf) → schlägt der Versand einer Mail tatsächlich fehl, wird die
  betroffene Person hier mit Fehlertext gelistet und der Schritt **bleibt unverändert**; ein
  späterer erfolgreicher Versand räumt die Markierung automatisch wieder ab.

### 5 a. Einen einzelnen Teilnehmer erneut antworten lassen

Kommt jemand nach dem Absenden und meldet einen Fehler, muss der Vorgang nicht neu aufgesetzt
werden: **Workflows → (Workflow) → Einträge → (Eintrag bearbeiten)** → Feld **„Schritt"**
(Auswahlfeld) auf **„Importiert"** oder **„Eingeladen"** zurückstellen → **Speichern**.

Was dabei passiert:

- Antwortzeitpunkt und Bestätigungsstatus werden mitgeleert. Ohne das würde der Eintrag intern
  weiterhin als beantwortet gelten – die automatische Nachbearbeitung bzw. „Bestätigung neu
  senden" würde die **alte** Bestätigung erneut verschicken.
- Der Teilnehmer erreicht sein Formular über **seinen unveränderten Link** wieder; eine neue
  Einladung ist nicht nötig (schadet aber auch nicht).
- Die **erfassten Daten bleiben erhalten** und füllen das Formular vor. Werden stattdessen die
  **Originalwerte aus der Quelldatei** gebraucht, danach **Import ausführen** – zurückgesetzte
  Teilnehmer werden dabei wieder frisch eingelesen.
- Das bisherige PDF bleibt zunächst liegen und wird beim erneuten Absenden überschrieben.
- Der Teilnehmer taucht sofort wieder in **„Offene Vorgänge"** auf.

> Sollen **alle** Teilnehmer zurückgesetzt werden – etwa um eine gesperrte Quell-Einstellung zu
> ändern –, führt der Weg über den Abschnitt **„Zurücksetzen"** in der Bearbeitungsmaske des
> Workflows (siehe 3 b), nicht über die Einträge einzeln.

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
3. [ ] **Import ausführen** → Einträge im Schritt „Importiert" (3 c)
4. [ ] **Einladungen senden** → Schritt „Eingeladen" (3 d)
5. [ ] Worker/Cron → **Posteingang**: Einladung mit Link (6)
6. [ ] Link öffnen → Daten prüfen → Option wählen → unterschreiben → **Absenden** (4)
7. [ ] Eintrag steht im Schritt „Beantwortet"; PDF unter `var/workflow_pdfs/…`
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
Im Workflow „Dokument-Inhalt = Einfacher Brief" wählen. **Überschrift** und **Einleitungstext**
stehen im Abschnitt *Inhalt (Formular & Dokument)* und erscheinen identisch im Formular und im
PDF. Die **Dokument-Texte** werden im Abschnitt **Dokument-Texte** gepflegt – idealerweise aus den
**Dokument-Texten der Formularfelder** zusammengesetzt (`##text_all##` /
`##text_<speicherfeld>##`, siehe 3 b‑2 / 3 b‑3), ergänzt um `##data_*##`/`##letterhead_*##`.
Ideal, wenn schnell ein neuer Brief gebraucht wird – **kein Entwickler, kein
Deployment** nötig.

**b) Spezielle Vorlage – für detaillierte Layouts (Datei)**
Für pixelgenaue/komplexe Layouts eine eigene **Body-Vorlage** als Datei anlegen:
1. Datei `pdf_body_xyz.html5` erstellen (nur der Body – **kein** Logo/Unterschrift,
   die liefert der Master). Verfügbare Variablen im Template: `$this->data`
   (alle Spalten **inkl. der gespeicherten Antwortwerte**), `$this->extra`
   (PDF-Variablen), `$this->statements` (die gerenderten **Dokument-Texte** je
   Speicherfeld-Slug + `text_all`) sowie `$this->heading`/`$this->intro`
   (Überschrift/Einleitungstext, bereits aufgelöst). Die gesamte Verzweigung passiert
   **im Template** auf Basis der Antwortwerte
   (z. B. `$accept = 'ja' === ($this->data['Verzicht'] ?? '')`) — bei
   „Spezielle Vorlage" gibt es **keine** Dokument-Texte. Vorlage zum Abschauen:
   `pdf_body_verzicht.html5`.
2. Datei in den **`templates/`**-Ordner des Projekts legen (lokal) bzw. produktiv per FTP/KAS
   in den `templates/`-Ordner hochladen. *(Contao 5 hat keinen Online-Editor für
   Template-Dateien – dieser Schritt ist Datei-Arbeit/Deployment.)*
   Wichtig: Der Name muss mit **`pdf_body_`** beginnen, damit die Vorlage
   automatisch in der Auswahlliste erscheint.
3. Im Workflow „Dokument-Inhalt = Spezielle Vorlage" und unter **Body-Vorlage** die
   Vorlage aus der **Auswahlliste** wählen.

**Eigenes Briefkopf-Layout (Master):** analog eine Datei `pdf_master_xyz.html5`
anlegen (Logo/Header/Unterschrift/Footer; bekommt `$this->bodyHtml`, `$this->logoSrc`,
`$this->signatureSrc`, `$this->signerName`, `$this->ort`, `$this->datum`, `$this->footer`),
nach `templates/` legen → erscheint in „Briefkopf-Vorlagen → Layout-Vorlage".
`$this->ort`/`$this->datum` stammen aus den Workflow-Feldern *Ort/Datum für Unterschriftszeile*
(nicht mehr automatisch). Die mitgelieferte `pdf_master` ist ein **neutraler Beispiel-Briefkopf** (Musterverein) mit
Lauf-Kopf-/Fußzeile (siehe [PDF-TEMPLATES.md](PDF-TEMPLATES.md)).
Feste Variablen, die ein Master-Layout anbietet, in
`contao/config/config.php` unter `$GLOBALS['TL_WORKFLOW_PDF_VARS']` ergänzen – sie
werden dann im Briefkopf vorgeschlagen. Ein Eintrag ist entweder ein einfacher
Default (Inhalts-Variable), z. B. `'Verein' => ''`, **oder** – für Layout-Maße – ein
Array `['default' => '20', 'label' => 'Rand links (mm)', 'group' => 'layout']`.
„layout"-Variablen erscheinen im Editor in der Gruppe **„Layout & Maße"**, werden von
`PdfGenerator` (Seitenränder) bzw. der Vorlage (Schriftgrößen, Abstände) gelesen und
**nicht** als `##letterhead_*##`-Token angeboten.

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
