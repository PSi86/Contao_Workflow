# PDF-Vorlagen: Syntax & Variablen

Wie man **Master-** (Briefpapier) und **Body-Vorlagen** für die PDF-Ausgabe **manuell**
erstellt: Vorlagen-Syntax, verfügbare Variablen und mPDF-Regeln.

Architektur (Details: [ANLEITUNG.md](ANLEITUNG.md) Abschnitt 2b/8):
ein **Master** liefert Briefpapier (Kopf/Fuß, Logo, Unterschrift, Footer) + PDF-Variablen, ein
**Body** liefert den Brieftext. PDF = Master umschließt Body.

---

## 1. Vorlagen-Syntax

PDF-Vorlagen sind hier **Contao-Legacy-Templates** (`.html5`): normales HTML mit
eingebettetem PHP (`<?= … ?>`). Auf Werte wird über `$this->…` zugegriffen; oben
definiert man kleine Helfer:

```php
<?php
$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$d = fn (string $k): string => (string) ($this->data[$k] ?? '');          // Quellspalte
$x = fn (string $k, string $def = ''): string => '' !== (string) ($this->extra[$k] ?? '') ? (string) $this->extra[$k] : $def; // Master-Variable
?>
<p>Name: <?= $esc($d('Vorname')) ?> <?= $esc($d('Name')) ?> — <?= $esc($x('Verein')) ?></p>
```

> **Hinweis:** `.html5`-PHP-Templates sind in Contao 5 *Legacy* (funktionieren in
> 5.x). Contao bewegt sich zu **Twig** (`.html.twig`); PHP-Templates entfallen in
> Contao 6. Eine spätere Twig-Migration ist möglich, ändert aber nur die Schreibweise,
> nicht die hier beschriebenen Variablen. Contao-Doku:
> [Templates](https://docs.contao.org/5.x/dev/framework/templates/) ·
> [Legacy templates](https://docs.contao.org/4.x/dev/framework/templates/legacy/).

---

## 2. Verfügbare Variablen

### Master-Vorlage (`pdf_master*`)
| Variable | Inhalt |
|---|---|
| `$this->bodyHtml` | fertig gerendertes Body-HTML (roh ausgeben: `<?= $this->bodyHtml ?>`) |
| `$this->logoSrc` | absoluter Dateipfad des Logos (oder `''`) |
| `$this->signatureSrc` | absoluter Dateipfad der Unterschrift (oder `''`) |
| `$this->signerName` | Name für die Unterschriftszeile |
| `$this->ort` | Ort der Unterschriftszeile (aus dem Workflow-Feld *Ort für Unterschriftszeile*, z. B. `Wohnort`) |
| `$this->datum` | Datum der Unterschriftszeile (aus dem Workflow-Feld *Datum für Unterschriftszeile*) |
| `$this->footer` | optionale Fußzeilen-Variable `Footer`; das mitgelieferte `pdf_master` (Beispiel-Briefpapier) nutzt stattdessen eine feste 4-spaltige Fußzeile |
| `$this->extra` | **alle** PDF-Variablen des Briefpapiers als Array (`$this->extra['Verein']` …); damit kann ein Master Kopf-/Fußzeile komplett aus den Variablen aufbauen |

> Das mitgelieferte **`pdf_master`** ist ein neutraler Beispiel-Briefkopf (Musterverein): blaue Kopfzeile + Logo + Linie und
> eine 4-spaltige Fußzeile als **mPDF-Lauf-Kopf/Fußzeile** (`<htmlpageheader>`/`<htmlpagefooter>` +
> `<sethtmlpageheader>/<sethtmlpagefooter>`). Kopf-/Fußzeilentext ist hier **fest** im Template.
> Eigene Master mit Lauf-Kopf/Fußzeile brauchen passende Seitenränder; diese setzt
> `PdfGenerator::renderPdf` (`margin_top/bottom/left/right/header/footer`) – standardmäßig aus
> eingebauten Defaults, oder **pro Briefpapier** aus Layout-Variablen, sofern das Template
> solche deklariert (siehe `pdf_master_generic` unten). Die Unterschriftszeile ist
> gespiegelt (Wert über der Linie, Label darunter).

> **Generischer Master `pdf_master_generic`** (organisationsneutral): identisches Layout, aber
> **aller** Kopf-/Fußzeilentext kommt aus den PDF-Variablen des Briefpapiers, nichts ist fest
> verdrahtet. **Inhalts-Variablen:**
> - `HeaderLine` – Absenderzeile über der Linie; **mehrzeilig**
> - `Footer1`…`Footer4` – die vier Fußzeilen-Spalten; **mehrzeilig**
> - `Verein`, `Ort` – für die Brieftexte (`##letterhead_verein##` …); das aktuelle
>   Jahr/Datum liefern die eingebauten `##system_year##` / `##system_today##`
>
> Mehrzeilig heißt: eine Zeile je Eingabezeile (Enter im Feld) **oder** je `|`. Kopf- und
> Fußzeile brechen **nicht automatisch um** – jede Zeile folgt strikt der Eingabe. Zu lange
> Zeilen laufen über; dann selbst umbrechen oder Schriftgröße/Ränder (siehe unten) anpassen.
>
> **Layout-Variablen** (Editor-Gruppe „Layout & Maße", pro Briefpapier einstellbar; Defaults =
> die eingebauten Werte, ein unverändertes Briefpapier rendert also wie zuvor):
> `MarginTop/Bottom/Left/Right` und `MarginHeader/MarginFooter` (mm), `FontSizeHeader/Body/Footer`
> (pt), `FooterColSpacing` (px). `PdfGenerator` liest+prüft die Ränder daraus (numerisch,
> begrenzt), die Vorlage die Schriftgrößen/Abstände.
>
> Nach Wahl der Layout-Vorlage werden alle diese Variablen im Briefpapier-Editor **sofort** als
> Felder vorgeschlagen (aus `$GLOBALS['TL_WORKFLOW_PDF_VARS']['pdf_master_generic']`).

### Body-Vorlage (`pdf_body_*`)
| Variable | Inhalt |
|---|---|
| `$this->data` | **alle** importierten Spalten **inkl. der gespeicherten Antwortwerte** (assoz.), Zugriff per `$d('Spaltenname')` |
| `$this->extra` | Master-PDF-Variablen, Zugriff per `$x('Verein')`, `$x('Ort')` … |
| `$this->statements` | die gerenderten **Dokument-Texte (Textbausteine)** der Antwortfelder: `text_<speicherfeld-slug>` je Feld + `text_all` (alle, in Formular-Reihenfolge), Klartext |
| `$this->heading` | die Workflow-**Überschrift** (Platzhalter bereits aufgelöst; im Formular identisch sichtbar) |
| `$this->intro` | der optionale **Einleitungstext** (aufgelöst; im Formular identisch sichtbar) |

> Eine Body-Vorlage **enthält ihre gesamte Verzweigung selbst** – sie bekommt alle Antwortwerte
> in `$this->data` und entscheidet im Code (z. B. `$accept = 'ja' === $d('Verzicht');`). Bei
> „Spezielle Vorlage" gibt es deshalb **keine** PDF-Regeln. Vorlagen sind für komplexe/pixelgenaue
> Fälle gedacht. Für die Formular/PDF-Übereinstimmung empfiehlt sich, wo möglich
> `$this->statements` zu nutzen – das sind exakt die Texte, die der Teilnehmer im Formular
> gesehen hat.

### Einfacher Brief (Letter-Modus, ganz ohne Datei)
Bei „Einfacher Brief" kommen **Überschrift** und **Einleitungstext** aus dem Workflow-Abschnitt
*Inhalt (Formular & PDF)* (sie erscheinen identisch im Formular); die **Brieftexte** werden als
**PDF-Regeln** gepflegt (je nach Antwort). Platzhalter (überall identisch – PDF,
E-Mail, Export): **`##data_<slug>##`** für jede Quellspalte inkl. Antwortfelder (Slug =
kleingeschrieben, Umlaute transliteriert, z. B. `##data_vorname##`, „davon Spende" →
`##data_davon_spende##`), **`##letterhead_<slug>##`** für Master-Variablen (`##letterhead_verein##`,
`##letterhead_ort##`), **`##system_year##`/`##system_month##`/`##system_today##`/`##system_time##`/`##system_datetime##`**
(eingebaute Datums-/Zeit-Platzhalter), **`##text_<speicherfeld>##`** / **`##text_all##`** für die
**Dokument-Texte der Antwortfelder** (die Texte, die der Teilnehmer im Formular sieht – in
`##text_all##` stehen Felder ohne eigenen Dokument-Text zeilenweise als „Beschriftung: Wert",
Felder mit eigenem Dokument-Text beginnen als eigener Absatz), dazu `##email##`. (In
Überschrift/Einleitung/Dateiname sind `##text_*##` nicht verfügbar.)

> So entscheidet sich der Text: Verbindungsglied ist das **Speicherfeld** eines Antwortfelds.
> Die Regel-Engine prüft die Regeln der Reihe nach gegen die gespeicherten Werte; die erste
> passende liefert den Brieftext, eine Regel **ohne Bedingung** gilt immer (Sonst-Fall).
> Empfohlenes Muster: der Brieftext besteht aus `##text_all##` (alle Antworten, wörtlich wie im
> Formular) plus Rahmen-Sätzen je Regel – so kann kein Antwortfeld im PDF vergessen werden.

---

## 3. mPDF-Regeln (unbedingt beachten)

Gerendert wird mit **mPDF**. Es versteht UTF-8 und viel HTML4/5, aber nur eine
**Teilmenge von CSS**:

- **Kein Flexbox/Grid.** Mehrspaltige Layouts über `<table>` lösen (so macht es der
  Master für die Unterschriftszeile).
- **Block-Elemente (div/p) in Tabellenzellen**: viele CSS-Eigenschaften werden dort
  ignoriert → Tabellenzellen schlicht halten.
- **Floats** nur eingeschränkt (nur Block-Elemente mit fester Breite).
- **Bilder** als **lokalen Dateipfad** einbinden (`logoSrc`/`signatureSrc` sind Pfade).
  Data-URIs (`data:image/png;base64,…`) werden von mPDF **nicht** zuverlässig
  gerendert – deshalb schreibt der Generator Logo/Unterschrift als Dateien.
- Seitenformat A4, kein JavaScript.

Referenz: [Supported CSS](https://mpdf.github.io/css-stylesheets/supported-css.html) ·
[Limitations](https://mpdf.github.io/about-mpdf/limitations.html) ·
[Features](https://mpdf.github.io/about-mpdf/features.html).

---

## 4. Manuell eine Vorlage erstellen

**Body-Vorlage:**
1. Datei `pdf_body_xyz.html5` anlegen (Helfer-Kopf wie oben, dann der Inhalt – **kein**
   Logo/Unterschrift, das liefert der Master). Vorlage zum Abschauen:
   [`../contao/templates/pdf_body_verzicht.html5`](../contao/templates/pdf_body_verzicht.html5).
2. In den **`templates/`**-Ordner des Projekts legen (produktiv per FTP). Name **muss
   mit `pdf_body_`** beginnen → erscheint automatisch in der Auswahl.

**Master-Vorlage (selten nötig):**
1. Datei `pdf_master_xyz.html5` anlegen (nutzt `bodyHtml`, `logoSrc`, `signatureSrc`,
   `signerName`, `ort`, `datum`, `footer`). Vorlage:
   [`../contao/templates/pdf_master.html5`](../contao/templates/pdf_master.html5).
2. Nach `templates/` legen (Name beginnt mit `pdf_master`).
3. Bietet das Layout feste Variablen, in `contao/config/config.php` unter
   `$GLOBALS['TL_WORKFLOW_PDF_VARS']` einen Eintrag
   `'pdf_master_xyz' => ['Verein' => '', …]` ergänzen → werden im Briefpapier
   vorgeschlagen. Je Variable ist der Wert entweder ein **einfacher Default**
   (Inhalts-Variable) **oder** ein **Array** `['default'=>…, 'label'=>…, 'group'=>'layout']`
   für Layout-Maße (eigene Editor-Gruppe, nicht als `##letterhead_*##`-Token, von
   `PdfGenerator`/Template gelesen).

> **Eine Mailmerge-Vorlage (`.docm`) als Ausgangspunkt?** Ein lokaler Konverter kann aus
> einer Word-`.docm` ein Body-Gerüst erzeugen – das ist ein **Entwickler-Werkzeug** der
> lokalen Entwicklungsumgebung und nicht Teil des Bundles.

---

## 5. Namens- & Registry-Konventionen
- Body-Vorlagen: Dateiname `pdf_body_*.html5`.
- Master-Vorlagen: Dateiname `pdf_master*.html5`.
- PDF-Variablen je Master-Layout: `$GLOBALS['TL_WORKFLOW_PDF_VARS']` in
  `contao/config/config.php` (`'<master-template>' => ['Var' => 'Default', …]`);
  Layout-Maße als `['default'=>…, 'label'=>…, 'group'=>'layout']`.
