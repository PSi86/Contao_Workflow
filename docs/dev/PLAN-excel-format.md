# Implementierungsplan: Zentrales Excel-Format-Modul

**Repo:** `psimandl/contao-workflow` (Contao 5.3, PhpSpreadsheet ^2|^3, mPDF, PHP 8.1+)
**Ziel:** Excel-Formatierung an genau einer Stelle behandeln, statt verstreuter Ad-hoc-Adaptionen.
Behebt die Wertverfälschung im Zahlenfeld und vereinheitlicht Import → Formular → Vorschau →
PDF → Export.

---

## 0. Ground Truth (verifiziert am Code, nicht neu ermitteln)

### 0.1 Die gemeinsame Wurzel

Formatierung passiert heute **ausschließlich beim Import**. `SpreadsheetImporter::localizeNumber()`
macht aus der Zelle einen **deutschen Anzeige-String** (`"3.000,00 €"`); das Excel-Format selbst
wird verworfen. Jeder Pfad danach kennt das Format nicht mehr und rät:

```
Excel-Zelle ──localizeNumber()──> "3.000,00 €" ──> data-Blob (serialize)
                                                      │
   ┌──────────────────────────────────────────────────┼───────────────┬────────────┐
   ▼                        ▼                         ▼               ▼            ▼
Formular              Live-Vorschau                 PDF            Export       Regeln
(input type=number)   (input.value)          (##data_*##)      (TYPE_STRING)  (strcmp!)
   │                        │                         │
   └── liest Punkt als      └── immer Punkt-          └── Antwort-Pfad liefert
       Dezimaltrenner           kanonisch                 "1234.5" (rgxp digit)
       → WERT VERFÄLSCHT
```

### 0.2 Belegte Einzelursachen

| # | Symptom | Ursache | Fundstelle |
|---|---------|---------|------------|
| 1 | Exportreihenfolge ≠ Importreihenfolge | keine Spalte für die Quellzeile; Identität ist die E-Mail | `tl_workflow_entry` DCA (kein `sorting`), `SpreadsheetExporter.php:36` `['order' => 'email']` |
| 2 | Beschreibung anders formatiert als Body | **Absicht:** als „Hinweis" gestylt | `workflow-form.css:40` `color:#555; font-size:.9em`; Erklärung `:43` nur `color:#222`, keine Größe |
| 3 | Zahlenfeld verfälscht `1.234` → `1,234` | `<input type="number" value="1.234">` — das HTML-`value` **muss** eine „valid floating-point number" sein, der Browser liest den Punkt als **Dezimaltrenner** und zeigt de-Locale `1,234` | `mod_workflow_form.html5:98` |
| 3b | Freitext funktioniert | `type="text"` zeigt den String unverändert | `mod_workflow_form.html5:98` |
| 4 | Formate nicht prüfbar | Inspector liest mit `setReadDataOnly(true)` → **Formate werden gar nicht geladen** | `SpreadsheetInspector.php:38,61` |
| 5a | Vorschau zeigt Punkt | `input.value` ist bei `type=number` immer punkt-kanonisch; `formatDate()` existiert, `formatNumber()` nicht | `workflow-form.js:86`, `:31` |
| 5b | PDF zeigt Punkt | `$widget->value` wird **nach** `validate()` gelesen; Contaos rgxp `digit` hat da schon `,`→`.` konvertiert | `WorkflowFormController.php:179`, `QuestionWidgetFactory.php:79` |

### 0.3 Nebenbefunde (gleiche Wurzel, mit beheben)

- **`RuleEvaluator.php:72`** — `is_numeric("3.000,00 €")` ist `false`, also vergleicht `gt`/`lt`
  per `strcmp` statt numerisch. Nur „General"-Zellen (`"3000"`) vergleichen korrekt.
- **Zwei Schreibweisen pro Spalte** — importierte Zeilen `"3.000,00 €"`, vom Teilnehmer
  beantwortete `"3000.5"`. Derselbe `data`-Blob, dieselbe Spalte.
- **Keine Tests** für den Importpfad; `cellValue()`/`localizeNumber()`/`currencySymbol()` sind
  private und untestbar.

### 0.4 Warum Deutsch eindeutig parsebar ist

Im Deutschen ist der Punkt **nie** Dezimaltrenner. Jeder Punkt in einem von uns erzeugten String
ist also Tausendertrennung. Zusammen mit der Regel aus Punkt 4 (nur 0 oder 2 Nachkommastellen)
ist `"1.234"` eindeutig `1234` — nicht `1.234`. **Das ist die Voraussetzung, auf der das
Speichermodell (§1) ruht.**

---

## 1. Entscheidungen (mit dem Auftraggeber abgestimmt, 2026-07-17)

| Thema | Entscheidung |
|---|---|
| **Speichermodell** | Der **deutsche Anzeige-String bleibt** der gespeicherte Wert. Keine Migration der `data`-Blobs. Das Modul parst/formatiert zentral; der Antwort-Pfad wird an den Import-Pfad angeglichen. |
| **Zahlen-Eingabefeld** | `<input type="text" inputmode="decimal">` mit deutschem Wert — zeigt exakt das, was im PDF steht, inkl. Tausendertrennung. Tolerantes Parsen (`1234`, `1234,5`, `1.234,50`). |
| **Währungssymbol** | Nur bei **Eingabe/Prüfung** ignorieren. Die Ausgabe (PDF, Export, Platzhalter) behält das Spaltenformat inkl. `€`, damit die Spalte einheitlich bleibt. |
| **Beschreibungstext** | Beschreibung **und** Erklärung erben Schrift/Größe/Farbe vom Body (Overrides raus). |

### Leitinvariante

> **Ein Wert wird genau einmal formatiert — beim Import bzw. beim Speichern einer Antwort —
> und danach überall unverändert angezeigt. Geparst wird nur, wenn gerechnet oder verglichen wird.**

---

## 2. Das Modul: `src/Excel/`

| Klasse | Aufgabe | rein? |
|---|---|---|
| `NumberFormat` | VO: `kind`, `decimals`, `grouping`, `currency` | ✅ |
| `FormatCodeParser` | Excel-Maske (`#,##0.00 [$€-407]`) → `NumberFormat` | ✅ |
| `ValueFormatter` | `float` + `NumberFormat` → deutscher String | ✅ |
| `ValueParser` | deutscher/loser String → `?float` | ✅ |
| `CellReader` | `Cell` → String (Datum/Zahl/Text) — ersetzt `cellValue()` | nein (PhpSpreadsheet) |
| `ColumnFormatAnalyzer` | Spalte → `NumberFormat[]` (distinct, **ohne** `readDataOnly`) | nein |
| `ColumnCompatibility` | `NumberFormat[]` + Fragetyp → Problemliste | ✅ |

Die vier reinen Klassen sind vollständig unit-testbar — das ist der Hauptgewinn gegenüber den
heutigen privaten Methoden im Importer.

**JS-Spiegel:** `public/workflow-number.js` (`formatNumber`/`parseNumber`), genutzt von
`workflow-form.js`. Parameter kommen per `data`-Attribut aus demselben `NumberFormat`-VO —
die JS-Seite erfindet nichts eigenes.

### Format-Snapshot

Die Kompatibilitätsprüfung (Punkt 4) läuft beim Speichern der Frage ohnehin. Sie **schreibt ihr
Ergebnis als Snapshot** nach `tl_workflow_question.numberFormat`. Damit haben Formular, Vorschau,
PDF und Export das Format zur Hand, **ohne die Quelldatei erneut zu lesen** (teuer: ohne
`readDataOnly` muss der Style-Layer geladen werden).

---

## 3. Arbeitspakete

### AP1 — Modulkern + Tests
`NumberFormat`, `FormatCodeParser`, `ValueFormatter`, `ValueParser` inkl. Unit-Tests.
Logik aus `localizeNumber()`/`currencySymbol()` wird extrahiert, nicht neu erfunden.

### AP2 — `CellReader` + Importer-Refactor
`cellValue()`, `localizeNumber()`, `currencySymbol()` verschwinden aus `SpreadsheetImporter`.
Verhalten bleibt identisch (Regressionsschutz durch AP1-Tests).

### AP3 — Punkt 1: Zeilenreihenfolge
- Neue Spalte `tl_workflow_entry.sourceRow` (int).
- Migration: Spalte anlegen, Backfill `sourceRow` nach `id` ASC pro `pid` — die Einträge wurden
  seinerzeit **in Zeilenreihenfolge** eingefügt, `id` ist also der korrekte Proxy.
- Importer setzt `sourceRow = $r` (echte Zeilennummer im Blatt → taugt auch für Fehlermeldungen).
- `SpreadsheetExporter`: `['order' => 'sourceRow']` statt `'email'`.

### AP4 — Punkt 4: Kompatibilitätsprüfung
- `ColumnFormatAnalyzer` liest die Spalte **ohne** `readDataOnly`.
- Akzeptiert: 0 **oder** 2 Nachkommastellen, Tausendertrennung optional, Währungssymbol egal.
- Abgelehnt: Prozent, wissenschaftlich, Bruch, Datum/Zeit, gemischte Nachkommastellen,
  nicht-numerische Zellen, 1/3/4+ Nachkommastellen.
- Fehlermeldung nennt **Zeile, Wert, Maske, Grund** und die Alternative „Freitext".
- Hook: `save_callback` auf `storageField` (läuft nach `type` in der Palette → `Input::post('type')`
  ist verfügbar) → wirft bei Inkompatibilität.
- Zusätzlich in `WorkflowValidator::getProblems()`, damit ein **späterer Dateitausch** dasselbe
  Problem meldet.

### AP5 — Punkt 3+5: Zahlenfeld
- Template: `type="text" inputmode="decimal"` + `data-wf-decimals`/`data-wf-grouping`.
- `QuestionWidgetFactory`: rgxp `digit` raus (macht die ungewollte `,`→`.`-Konvertierung).
- `WorkflowFormController`: `normalizeNumber()` neben dem bestehenden `normalizeDate()`.
- `workflow-number.js` + Einbindung in `workflow-form.js`.

### AP6 — Punkt 2: CSS
`.tw-field-desc`: `color`/`font-size` raus. `.tw-explanation-text`: `color:#222` raus.

### AP7 — Bonus: `RuleEvaluator` auf `ValueParser`
Numerischer Vergleich funktioniert dann auch für formatierte Werte.

---

## 4. Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| `ColumnFormatAnalyzer` ohne `readDataOnly` ist langsam bei großen Dateien | Nur beim Speichern einer **Zahlen**frage; Ergebnis als Snapshot cachen |
| Bestehende Zahlenfelder haben keinen Snapshot | Fallback: aus dem gespeicherten String ableiten (deutsch ist eindeutig, §0.4) |
| rgxp-`digit`-Entfernung schwächt Validierung | `normalizeNumber()` validiert selbst und meldet konkret |
| `type=text` verliert den nativen Spinner | Bewusst akzeptiert — `inputmode="decimal"` hält die numerische Tastatur |
