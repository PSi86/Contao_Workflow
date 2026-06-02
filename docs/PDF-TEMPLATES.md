# PDF-Vorlagen: Syntax, Variablen & `.docm`-Import

Wie man **Master-** (Briefkopf) und **Body-Vorlagen** für die PDF-Ausgabe erstellt –
**manuell** (Syntax + Variablen + externe Links) und **reproduzierbar aus einem
Word-`.docm`** über einen lokalen Konverter.

Architektur (Details: [../../ANLEITUNG.md](../../ANLEITUNG.md) Abschnitt 2b/8):
ein **Master** liefert Briefkopf/Logo/Unterschrift/Footer + PDF-Variablen, ein
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
| `$this->ort` | Ort (aus PDF-Variable `Ort`) |
| `$this->datum` | Antwortdatum `TT.MM.JJJJ` |
| `$this->footer` | Fußzeile (aus PDF-Variable `Footer`) |

### Body-Vorlage (`pdf_body_*`)
| Variable | Inhalt |
|---|---|
| `$this->data` | **alle** importierten Spalten **inkl. der gespeicherten Antwortwerte** (assoz.), Zugriff per `$d('Spaltenname')` |
| `$this->extra` | Master-PDF-Variablen, Zugriff per `$x('Jahr')`, `$x('Verein')` … |

> Die frühere feste Entscheidung gibt es nicht mehr. Branche im Template auf einen
> gespeicherten Antwortwert (z. B. `$accept = 'ja' === $d('Verzicht');`) **oder** wähle
> die passende Body-Vorlage über **PDF-Regeln** am Workflow.

### Einfacher Brief (Letter-Modus, ganz ohne Datei)
Im Backend (Überschrift/Brieftext) mit `##Platzhaltern##`:
`##Spaltenname##` (jede Quellspalte inkl. Antwortfelder), `##Jahr##` / `##Verein##` …
(Master-Variablen), `##datum##`, `##email##`.

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
2. Nach `contao-app/templates/` (lokal) bzw. produktiv per FTP in `templates/` legen.
   Name **muss mit `pdf_body_`** beginnen → erscheint automatisch in der Auswahl.

**Master-Vorlage (selten nötig):**
1. Datei `pdf_master_xyz.html5` anlegen (nutzt `bodyHtml`, `logoSrc`, `signatureSrc`,
   `signerName`, `ort`, `datum`, `footer`). Vorlage:
   [`../contao/templates/pdf_master.html5`](../contao/templates/pdf_master.html5).
2. Nach `templates/` legen (Name beginnt mit `pdf_master`).
3. Bietet das Layout feste Variablen, in `contao/config/config.php` unter
   `$GLOBALS['TL_TRAINER_PDF_VARS']` einen Eintrag
   `'pdf_master_xyz' => ['Jahr' => date('Y'), 'Verein' => '', …]` ergänzen → werden im
   Briefkopf automatisch vorgeschlagen.

---

## 5. `.docm` → Body-Vorlage (lokaler, reproduzierbarer Workflow)

Eine `.docx/.docm`-Mailmerge-Vorlage lässt sich **nicht** direkt zu PDF rendern
(bräuchte LibreOffice/Word, steht auf dem Server nicht zur Verfügung). Stattdessen
erzeugt ein **lokaler Konverter** ein **Body-Template-Gerüst** + extrahiert die Bilder.
Das Gerüst ist ein **Startpunkt** (Text + Felder + Bilder), kein pixelgenaues Ergebnis.

**Ausführen (in DDEV, im Projekt-Root):**
```powershell
ddev exec php scripts/docm-to-template.php <pfad-zur.docm> pdf_body_xyz
```
(z. B. eine `.docm` zuvor nach `contao-app/files/…` legen und diesen Pfad angeben.)

**Ergebnis:**
- `scripts/generated/pdf_body_xyz.html5` – das Gerüst (Überschrift → `<h1>`, Absätze →
  `<p>`, jedes `MERGEFIELD` → `<?= $esc($d('Spalte')) ?>`, Unterstriche im Feldnamen
  werden zu Leerzeichen).
- `scripts/generated/pdf_body_xyz-media/` – die eingebetteten Bilder (Logo …).
- eine Liste der erkannten Felder.

**Nacharbeiten (Pflicht):**
1. Briefkopf-/Unterschrift-/„Ort, Datum"-Zeilen entfernen (liefert der Master).
2. Datumsspalten mit `$fmtDate(...)` umschließen, z. B.
   `<?= $esc($fmtDate($d('Geburtsdatum'))) ?>`.
3. Feste Werte (Verein, Jahr) durch `$x('Verein')` / `$x('Jahr')` ersetzen und als
   PDF-Variablen am **Master** pflegen.
4. Prüfen, dass jeder `$d('…')`-Spaltenname **exakt** einer Quelldatei-Spalte entspricht.
5. Datei nach `templates/` kopieren (Name `pdf_body_*`), Logo in die Dateiverwaltung
   laden und im **Briefkopf (Master)** als PDF-Logo setzen.
6. Im Workflow: **PDF-Inhalt = Spezielle Vorlage** → diese Body-Vorlage wählen.

**Beispiel-Output** (aus der TSV-Verzicht-`.docm`):
```html
<h1>Verzichtserklärung für Trainer / Übungsleiter / Betreuer …</h1>
<p>Name <?= $esc($d('Vorname')) ?> <?= $esc($d('Name')) ?> geb. am <?= $esc($d('Geburtsdatum')) ?></p>
<p>Adresse: <?= $esc($d('Straße')) ?>, <?= $esc($d('PLZ')) ?> <?= $esc($d('Wohnort')) ?></p>
…
```
Die fertig überarbeitete Fassung davon ist `pdf_body_verzicht.html5`.

---

## 6. Namens- & Registry-Konventionen
- Body-Vorlagen: Dateiname `pdf_body_*.html5`.
- Master-Vorlagen: Dateiname `pdf_master*.html5`.
- PDF-Variablen je Master-Layout: `$GLOBALS['TL_TRAINER_PDF_VARS']` in
  `contao/config/config.php` (`'<master-template>' => ['Var' => 'Default', …]`).
