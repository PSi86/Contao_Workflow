# Implementierungsplan: Bedingte Formularfelder

> **Status: umgesetzt in 3.4.0 (2026-08-16).** AP1–AP7 sind vollständig implementiert und
> verifiziert (Unit-Tests, End-to-End im DDEV-Stack: Formular, Absenden mit verworfenem Wert,
> Dokument-Tokens, Backend-Maske, Feldliste, Reorder-Guard). Das Dokument bleibt als Begründung
> der Entwurfsentscheidungen E1–E9 stehen – wer das Feature erweitert, liest zuerst Abschnitt 1
> und 9. Benutzerdokumentation: `docs/ANLEITUNG.md`, Abschnitt 3 b‑1.

**Repo:** `psimandl/contao-workflow` (Contao 5.3, PHP 8.1+)
**Ziel:** Ein Formularfeld erscheint nur, wenn die Antwort auf ein vorangehendes Feld eine
Bedingung erfüllt. Leitbeispiel: Dropdown „Haben Sie Kinder?" (`nein`/`ja`) → bei `ja`
erscheint das Zahlenfeld „Wie viele Kinder haben Sie?".

---

## 0. Ausgangslage (verifiziert, nicht neu ermitteln)

Das Feature steht auf vorhandener Mechanik. Diese Stellen sind gelesen und bilden die Basis:

| Baustein | Ort | Was davon wiederverwendet wird |
|---|---|---|
| Bedingungen auf Speicherspalten | `contao/dca/tl_workflow_rule.php` (`conditions`, MCW) | Spaltenmodell, MCW-Spaltenlayout, Operator-Übersetzungen |
| Operator-Auswertung | `src/Service/RuleEvaluator.php::evaluate()` (privat) | wird zu `ConditionMatcher` extrahiert |
| Backend-Ein-/Ausblenden ohne Zwischenspeichern | `public/workflow-field-toggle.js`, `data-wf-toggle` | steuert die neue Bedingungssektion in der Maske |
| Formular-Renderdaten (Frontend **und** Backend-Vorschau) | `src/Service/WorkflowFormView.php` | eine Quelle für beide Ansichten |
| Live-Textbaustein-Vorschau | `public/workflow-form.js` | Vorbild für das neue Frontend-Skript |
| Dokumenttexte | `src/Service/DocumentBodyComposer.php::statementTokens()` | Filterpunkt für unsichtbare Felder |
| Feldliste mit Drag&Drop | `AnswerConfigListener::renderQuestionsList()`, `public/workflow-question-sort.js` | Marker-Spalte, Reihenfolge-Prüfung |
| Reihenfolge-Persistenz | `AnswerConfigListener::saveQuestionOrder()` / `renumberQuestions()` | Reorder-Guard |
| Sperre bei vorhandenen Antworten | `src/EventListener/DataContainer/WorkflowLockListener.php` | Bedingungen werden **nicht** gesperrt |
| Konfig-Portabilität | `WorkflowConfigExporter` / `WorkflowConfigImporter` (`VERSION = 7`) | drei neue Keys, Version 8 |

Ebenfalls verifiziert: Die Backend-Vorschau (`WorkflowActionController`, ~Z. 330/373) rendert
dasselbe Template `mod_workflow_form.html5` und lädt dieselben Frontend-Assets — neue Skripte
müssen an **beiden** Stellen eingebunden werden.

---

## 1. Getroffene Entscheidungen (nicht neu verhandeln)

**E1 — Eine Bedingung referenziert die Speicherspalte, nicht die Feld-ID.**
Wie bei den PDF-Regeln. Kein ID-Remapping beim Kopieren eines Workflows (`act=copy`), keine
Sonderlogik im Konfig-Import, und die Sichtbarkeit bleibt **nachträglich aus den gespeicherten
Daten reproduzierbar** — entscheidend, wenn Monate später ein PDF neu erzeugt wird.
Preis: Ein Feld ohne Speicherfeld (Typ „Erklärung") kann nicht Auslöser sein.

**E2 — Eine Bedingung darf sich nur auf ein *vorangehendes* Feld beziehen.**
Zyklen sind damit ausgeschlossen, die Auswertung ist ein einziger Durchlauf in
Sortierreihenfolge, das Auswahl-Dropdown bleibt kurz. Wird beim Speichern und beim
Umsortieren erzwungen (AP5).

**E3 — Der Server entscheidet, das JavaScript zeigt es nur an.**
Beim Absenden wird die Sichtbarkeit erneut berechnet. Ohne das würde ein Feld, das der
Teilnehmer ausgefüllt und dann durch Umschalten des Auslösers versteckt hat, trotzdem
gespeichert; ein verstecktes Pflichtfeld würde das Absenden blockieren.

**E4 — Ein `ConditionMatcher` für Formularbedingungen und PDF-Regeln.**
Sonst driften „ist gleich" im Formular und im Dokument auseinander.

**E5 — Operator-Set v1 (risikominimiert):** `eq`, `neq`, `contains`, `empty`, `notempty`.
Der `ConditionMatcher` bleibt vollständig (die PDF-Regeln brauchen `lt/lte/gt/gte` weiter);
eingeschränkt wird nur die Auswahlliste in der Formularfeld-Maske. Damit vergleicht die
Formularlogik ausschließlich Strings — der JS-Zwilling braucht **weder Zahlen- noch
Datums-Parser**, und genau das war das Paritätsrisiko.

**E6 — Antwortspalten unsichtbarer Felder werden geleert.**
Beim Absenden schreibt ein unsichtbares Feld `''` in seine Spalte, sofern es nicht
schreibgeschützt ist und **kein sichtbares Feld dieselbe Spalte beschreibt**. Damit sind
Formular, Dokument und Excel-Export deckungsgleich. Folge, die dokumentiert werden muss:
Die Spalte eines bedingten Feldes ist eine Antwortspalte — trägt sie zusätzlich importierte
Quelldaten, werden die beim Absenden überschrieben.

**E7 — Feldliste: Verknüpfungsmarker mit Hover-Hervorhebung, Klartext im Tooltip.**
Keine Einrückung/Baumdarstellung: Die Reihenfolge ist frei (ein abhängiges Feld muss nicht
direkt hinter seinem Auslöser stehen) und ein Feld kann mehrere Auslöser haben — beides kann
ein Baum nicht ehrlich abbilden, und eine serverseitig gerenderte Einrückung wäre nach einem
Drag&Drop sofort veraltet.

**E8 — Bedingungen werden bei vorhandenen Antworten *nicht* gesperrt.**
Konsistent mit Optionen und Texten: Sie verändern nicht die Bedeutung bereits gespeicherter
Daten. Gesperrt bleiben nur die Speicherspalten (`WorkflowLockListener`).

**E9 — Kalibrierungen ohne Rückfrage:**
(a) Der Demo-Workflow bekommt ein bedingtes Feldpaar, damit das Feature nach der Installation
sofort sichtbar ist. (b) Die Marker-Nummern werden serverseitig in Listenreihenfolge vergeben
und nach einem Drop **nicht** neu durchgezählt (nur die Verletzungsprüfung läuft neu) — sonst
läge die Nummerierungslogik doppelt vor.

---

## 2. Datenmodell

Drei Spalten auf `tl_workflow_question`. **Kein Migrations-Skript** — Contao legt die Spalten
aus dem DCA an (`contao:migrate`), und die Defaults bilden exakt das heutige Verhalten ab.

| Feld | SQL | Bedeutung |
|---|---|---|
| `conditionMode` | `varchar(8) NOT NULL default ''` | `''` = immer sichtbar · `show` = nur anzeigen wenn · `hide` = ausblenden wenn |
| `conditionLogic` | `varchar(3) NOT NULL default 'and'` | `and` = alle Bedingungen · `or` = eine genügt |
| `conditions` | `blob NULL` | MCW-Zeilen `['field' => <spalte>, 'operator' => …, 'value' => …]` |

`QuestionModel` bekommt:

```php
public function getConditionMode(): string        // '', 'show', 'hide'
public function isConditional(): bool             // '' !== mode && [] !== conditions
public function getConditionLogic(): string       // 'and' | 'or'
public function getConditions(): array            // vollständige Zeilen, analog RuleModel
```

---

## 3. Architektur

### 3.1 Zwei neue Services

```php
// src/Service/ConditionMatcher.php  – aus RuleEvaluator::evaluate() extrahiert
final class ConditionMatcher
{
    public function __construct(private readonly ValueParser $valueParser) {}

    /** Vollständiges Operator-Set (Formular nutzt nur die Teilmenge aus E5). */
    public function matches(string $actual, string $operator, string $expected): bool;
}

// src/Service/FieldVisibility.php
final class FieldVisibility
{
    public function __construct(private readonly ConditionMatcher $matcher) {}

    /** Ein Feld gegen einen Datenstand (Submit-Schleife). */
    public function isVisible(QuestionModel $question, array $data): bool;

    /**
     * Alle Felder mit Kaskade: Der Wert eines unsichtbaren Feldes zählt für die
     * nachfolgenden Bedingungen als leer.
     *
     * @return array<int, bool> Frage-ID => sichtbar
     */
    public function resolve(array $questions, array $data): array;
}
```

`RuleEvaluator` wird auf den `ConditionMatcher` umgestellt (Verhalten unverändert).
Autowiring greift, beide Services liegen unter `src/Service/` (siehe `config/services.yaml`).

**Laufzeitregeln (fail-open):**

- `conditionMode === ''` → sichtbar.
- Keine vollständige Bedingung vorhanden → **sichtbar** (defekte Konfiguration lässt ein Feld
  nie lautlos verschwinden). Beim Speichern wird dieser Zustand ohnehin abgelehnt (AP5).
- Unbekannter Operator → die einzelne Bedingung gilt als nicht erfüllt.
- `hide`-Modus = logische Negation des Treffers.
- Kaskade in `resolve()`: Für ein unsichtbares Feld wird die eigene Speicherspalte im
  Arbeitsdatensatz auf `''` gesetzt. **Merksatz für die Anleitung: Ein ausgeblendetes Feld
  zählt für nachfolgende Bedingungen als leer.**

### 3.2 Vier Konsumenten plus JS-Zwilling

```
                       ┌──────────────────┐
                       │ FieldVisibility  │──uses──▶ ConditionMatcher ──▶ ValueParser
                       └────────┬─────────┘                  ▲
        ┌───────────────┬───────┴────────┬───────────────┐   └── RuleEvaluator (PDF-Regeln)
        ▼               ▼                ▼               ▼
 WorkflowFormView  FormController   DocumentBody     Backend-
 (Initialzustand)  (Absenden:       Composer         Vorschau
        │           Prüfung+Leeren) (##text_*##)     (gleiche View)
        ▼
 mod_workflow_form.html5 ──data-wf-cond──▶ public/workflow-conditions.js
                                            (nur String-Vergleiche, E5)
```

---

## 4. Backend-UI

### 4.1 Feldmaske (`tl_workflow_question`)

```
┌─ Formularfeld ─────────────────────────────────────────────────┐
│ Überschrift [Wie viele Kinder haben Sie?]  Typ [Zahl        ▾] │
│ Speicherfeld [Anzahl_Kinder ▾]             Nachkommast. [0  ▾] │
│ ☑ Pflichtfeld   ☐ Schreibgeschützt   ☐ Vorbelegen              │
└────────────────────────────────────────────────────────────────┘
┌─ Sichtbarkeit ─────────────────────────────────────────────────┐
│ Anzeigen  [nur wenn …                        ▾]                │
│ Verknüpfung [alle Bedingungen ▾]                               │
│                                                                │
│ Feld                       Operator       Wert                 │
│ [Haben Sie Kinder? (Kinder)▾] [ist gleich ▾] [ja         ] ⊞⊟ │
└────────────────────────────────────────────────────────────────┘
```

Palette:

```php
'default' => '{question_legend},label,type,storageField,numberDecimals,mandatory,readOnly,'
    .'prefill,description,options,pdfStatement,showStatementInForm,hideInForm;'
    .'{condition_legend},conditionMode,conditionLogic,conditions',
```

Toggle-Verdrahtung (das vorhandene `workflow-field-toggle.js` kombiniert mehrere Selektoren
per UND, siehe dessen `recompute()`):

- `type`: `conditionMode`, `conditionLogic`, `conditions` werden **jedem** Typ-Zweig der
  bestehenden `data-wf-toggle`-Map hinzugefügt (Bedingungen gibt es für jeden Feldtyp,
  ausdrücklich auch für „Erklärung").
- `conditionMode` bekommt eine eigene Map:
  `{"mode":"select","map":{"":[],"show":["conditionLogic","conditions"],"hide":["conditionLogic","conditions"]}}`
- `conditionMode` ist ein `select` mit `includeBlankOption` + `blankOptionLabel` =
  „immer anzeigen" (Muster: `numberDecimals`), damit der leere Wert eine echte, beschriftete
  Option ist.

**Optionen der Feld-Spalte** — neu `AnswerConfigListener::getConditionSourceOptions()`:

- nur Felder **desselben Workflows** mit nicht-leerem `storageField`,
- nur Felder mit **kleinerer `sorting`** als das gerade bearbeitete (E2); bei `act=create`
  alle vorhandenen (der neue Datensatz wird hinten angehängt),
- **nicht** das Feld selbst, **nicht** Felder ohne Formulardarstellung
  (`currentTime` + `hideInForm`) — deren Wert existiert im Browser nicht,
- Beschriftung `Überschrift (Speicherspalte)`, Wert = Speicherspalte,
- ein gespeicherter, nicht mehr auflösbarer Wert bleibt als „Unbekannte Option: …" sichtbar
  (Muster: `getStorageFieldOptions()`).

Schreibgeschützte Felder **dürfen** Auslöser sein (ihr Wert steht im Datensatz und im
`disabled` Input) — typischer Fall „wenn Tarif = Premium, dann Zusatzfrage".

**Operator-Spalte:** eigene Referenzliste mit den fünf Operatoren aus E5, Beschriftungen aus
`tl_workflow_rule.operatorOptions` übernommen. Hinweis im Tooltip: Bei Checkbox-Auslösern
(Mehrfachauswahl, gespeichert als `", "`-Liste) ist `enthält` der „diese Option ist
angehakt"-Operator.

**Wert-Spalte:** ein Eingabefeld, das zum **Dropdown der Optionen des gewählten Auslösefelds**
wird, sobald dieses feste Antworten hat (Dropdown, Radio, Checkboxen). Verglichen wird der
**gespeicherte Wert** (`ja`), nicht der Options-Text (`Einverstanden`) – von Hand ist das leicht
falsch und fällt niemandem auf, weil ein Wert ohne Entsprechung eine zulässige Eingabe ist. Das
Dropdown zeigt deshalb beides: `Einverstanden (ja)`.

Warum clientseitig (`public/workflow-condition-value.js`) und nicht per `options_callback`:
Welche Optionen in eine Zeile gehören, folgt aus dem **in dieser Zeile** gewählten Feld – der
MultiColumnWizard konfiguriert seine Spalten aber einmalig für alle Zeilen. Der Server liefert
darum nur die Landkarte (Spalte → Optionen) als JSON
(`AnswerConfigListener::loadConditionValueOptions()`, ausgeliefert über `TL_MOOTOOLS`; `TL_HEAD`
rendert das Backend nicht).

**Der Wechsel Liste ↔ Freitext gehört neben das Feld, nicht in die Liste.** Ein Listeneintrag
(„andere Eingabe …") kann nur in eine Richtung wirken: Sobald er gewählt ist, ist die Liste
weg, die den Rückweg tragen müsste – eine Einbahnstraße, die nur über einen Feldwechsel oder
das Verwerfen des Dialogs zu verlassen war. Stattdessen steht ein Symbol-Schalter (✎ / ☰) in
der Zelle, der in beide Richtungen wechselt und den Wert jeweils mitnimmt. Der Modus wird
**nicht gespeichert**: Beim Öffnen startet die Zeile mit der Liste, ein listenfremder Wert
steht darin als markierter Eintrag – einen Klick von der freien Bearbeitung entfernt. Freitext
bleibt nötig für Auslösefelder, deren Spalte importierte Daten trägt (vorbelegt oder
schreibgeschützt), und für Teiltexte bei `enthält`.

**Operatoren ohne Vergleichswert** (`ist leer`, `ist nicht leer`) grauen das Wertfeld aus und
sperren es (`readonly`, nicht `disabled` – ein deaktiviertes Feld würde nicht gepostet und der
Wert bei einem Operatorwechsel fehlen).

Das Skript wird auch dann ausgeliefert, wenn kein Vorgänger ein Auswahlfeld ist: Das Ausgrauen
gilt unabhängig davon, und die Landkarte ist dann eben leer.

### 4.2 Feldliste — Marker + Hover + Tooltip (E7)

```
☰  ●    Name                     Freitext   Name             ✓   ✎ 🗑
☰  ①    Haben Sie Kinder?        Dropdown   Kinder           ✓   ✎ 🗑   ◀ Hover
☰  ⤷①   Wie viele Kinder?        Zahl       Anzahl_Kinder    ✓   ✎ 🗑   ◀ hervorgehoben
☰  ⤷①   Hinweis Kinderfreibetrag Erklärung  –                –   ✎ 🗑   ◀ hervorgehoben
☰  ⤷①②  Zusatzangaben            Freitext   Zusatz           –   ✎ 🗑   ◀ hervorgehoben
```

Serverseitig (`renderQuestionsList()`):

- Vor dem Rendern einmal ermitteln: welche Spalten von mindestens einem anderen Feld
  referenziert werden → laufende Nummer je Auslöserspalte in Listenreihenfolge (E9b).
- Neue schmale Spalte direkt hinter dem Ziehgriff:
  - **Auslöserzeile:** `<span class="tw-dep tw-dep-trigger" title="Steuert: „Wie viele Kinder?", „Hinweis Kinderfreibetrag"">①</span>`
  - **Abhängige Zeile:** `<span class="tw-dep tw-dep-child" title="wenn „Haben Sie Kinder?" = ja">⤷①</span>`
  - Der Tooltip-Klartext kommt aus einer gemeinsamen `formatConditions()`-Variante, die
    Feldüberschriften statt Spaltennamen einsetzt; „ausblenden wenn …" wird ausgeschrieben,
    damit die Negation nicht untergeht. Mehrere Bedingungen werden mit „und"/„oder" verbunden.
- Zeilenattribute für das Skript: `data-wf-col="<eigene spalte>"` und
  `data-wf-depends="<spalte1,spalte2>"`.

Clientseitig, neu `public/workflow-question-deps.js`:

- Hover/Fokus auf einer Zeile hebt die verbundenen Zeilen hervor (farbige linke Leiste),
  in beide Richtungen (Auslöser → Abhängige, Abhängige → Auslöser).
- Nach jedem Drop und nach jedem dcaWizard-Refresh: prüfen, ob eine abhängige Zeile **vor**
  einer ihrer Auslöserzeilen steht → `tw-dep-error` (rote Leiste + rotes Marker-Symbol +
  `title` „steht vor seinem Auslösefeld"). Das ist die Frühwarnung; abgelehnt wird die
  Reihenfolge serverseitig (AP5).
- `workflow-question-sort.js` bekommt dafür genau eine Zeile: in `sync()` ein
  `document.dispatchEvent(new CustomEvent('wf-question-order-changed'))`. Das Sortierskript
  bleibt sonst unverändert; das Deps-Skript arbeitet mit Event-Delegation und einem
  `MutationObserver` (Muster ist dort bereits vorhanden).

---

## 5. Frontend

### 5.1 Markup

```html
<!-- jedes Feld mit Speicherspalte = möglicher Auslöser -->
<div class="tw-field tw-field--select" data-wf-field="Kinder" …>

<!-- bedingtes Feld: Regeln als JSON, Initialzustand serverseitig -->
<div class="tw-field tw-field--number"
     data-wf-field="Anzahl_Kinder"
     data-wf-cond='{"mode":"show","logic":"and","rules":[{"f":"Kinder","o":"eq","v":"ja"}]}'
     hidden> … </div>
```

Der Initialzustand (`hidden`) wird serverseitig gesetzt, damit nichts aufblitzt.
**`disabled` setzt ausschließlich das Skript** — siehe 5.3.

### 5.2 `public/workflow-conditions.js`

- Wertermittlung je Auslösercontainer, spiegelbildlich zur Speicherung:
  `select` → gewählter Wert · `radio` → angehakter Wert oder `''` ·
  `checkbox` → angehakte Werte mit `", "` verbunden · sonst `input.value.trim()`.
- Operatoren (E5): `eq` = strikt nach `trim()`, `neq`, `contains` = case-insensitiv,
  `empty`/`notempty`. Keine Zahlen-/Datumsnormalisierung.
- Durchlauf in DOM-Reihenfolge, Kaskade wie serverseitig (versteckt ⇒ Wert zählt als leer).
- Ein delegierter `input`/`change`-Listener auf dem Formular; einmal beim Laden.
- Beim Verstecken: `hidden = true` und Bedienelemente `disabled`. **Wichtig:** Der
  ursprüngliche `disabled`-Zustand wird gemerkt (`data-wf-was-disabled`) und beim Einblenden
  wiederhergestellt — schreibgeschützte Auswahlfelder sind serverseitig `disabled` und dürfen
  nicht versehentlich freigeschaltet werden (gleiche Falle wie `tw-locked` im Backend-Toggle).

### 5.3 Ohne JavaScript

```html
<noscript><style>.tw-form [data-wf-cond]{display:block !important}</style></noscript>
```

Der `hidden`-Zustand ist nur eine UA-CSS-Regel und wird davon überschrieben; `disabled` setzt
niemand, weil das Skript nicht läuft. Ergebnis: **alle** Felder sichtbar und absendbar, der
Server verwirft die nicht zutreffenden. Keine Datenlücke, keine unerreichbare Rückfrage.

**Bekannte, harmlose Abweichung:** Nach einer serverseitig abgelehnten Eingabe werden die
Ansichtsdaten aus dem gespeicherten Datenstand aufgebaut, die Eingaben aber aus dem Request
wiederhergestellt. Der Initialzustand kann dann von den eingegebenen Werten abweichen; das
Skript korrigiert das beim Laden, ohne Skript sind ohnehin alle Felder sichtbar.

---

## 6. Arbeitspakete

### AP1 — Datenmodell und Feldmaske

**Dateien:** `contao/dca/tl_workflow_question.php` · `contao/languages/{de,en}/tl_workflow_question.php` ·
`src/Model/QuestionModel.php` · `src/EventListener/DataContainer/AnswerConfigListener.php`

Drei Felder, `{condition_legend}`, Toggle-Verdrahtung (4.1), `getConditionSourceOptions()`,
Operator-Referenzliste, Modell-Methoden.

**DoD:** `contao:migrate` legt die Spalten an; die Sektion erscheint erst bei `show`/`hide`;
das Feld-Dropdown zeigt ausschließlich zulässige Auslöser; Bestandsfelder verhalten sich
unverändert.

### AP2 — Auswertung

**Dateien:** neu `src/Service/ConditionMatcher.php`, neu `src/Service/FieldVisibility.php` ·
`src/Service/RuleEvaluator.php` · neu `tests/Service/ConditionMatcherTest.php`,
`tests/Service/FieldVisibilityTest.php`

**DoD:** PDF-Regeln verhalten sich unverändert (bestehende Tests grün); die neuen Tests decken
`and`/`or`, `show`/`hide`, Kaskade, Fail-open und die Checkbox-`contains`-Semantik ab.

### AP3 — Formular und Vorschau

**Dateien:** `src/Service/WorkflowFormView.php` (`visible`, `condition` — auch im
„Erklärung"-Zweig) · `contao/templates/mod_workflow_form.html5` · neu
`public/workflow-conditions.js` · `public/workflow-form.css` (`noscript`-Regel bzw. Übergang) ·
`src/Controller/FrontendModule/WorkflowFormController.php` **und**
`src/Controller/Backend/WorkflowActionController.php` (Skript einbinden)

**DoD:** Im Leitbeispiel erscheint das Zahlenfeld bei `ja` und verschwindet bei `nein`; die
Backend-Vorschau verhält sich identisch; mit deaktiviertem JavaScript sind alle Felder
sichtbar; ein schreibgeschütztes Feld bleibt nach Aus-/Einblenden schreibgeschützt.

### AP4 — Absenden und Dokument

**Dateien:** `src/Controller/FrontendModule/WorkflowFormController.php::handleSubmission()` ·
`src/Service/DocumentBodyComposer.php::statementTokens()`

```php
$effective = $entry->getData();
$hidden = $written = [];

foreach ($questions as $question) {
    $storage = trim((string) $question->storageField);

    if (!$this->visibility->isVisible($question, $effective)) {
        if ('' !== $storage) {
            $effective[$storage] = '';                 // Kaskade
            if (!$question->isReadOnly()) {
                $hidden[$storage] = true;              // E6: Kandidat zum Leeren
            }
        }
        continue;                                      // keine Pflichtprüfung, kein Widget
    }

    /* … unveränderte Prüfung/Normalisierung … */
    if ('' !== $storage) {
        $answers[$storage] = $value;
        $effective[$storage] = $value;
        $written[$storage] = true;
    }
}

foreach (array_keys($hidden) as $column) {
    if (!isset($written[$column])) {
        $answers[$column] = '';                        // E6
    }
}
```

`statementTokens()` holt einmal `resolve()` und überspringt unsichtbare Felder — sowohl für
`##text_<spalte>##` (bleibt leer) als auch für `##text_all##`. Das ist **nicht** redundant zu
E6: Eine bedingte „Erklärung" hat keinen Wert, über den sie sich selbst aus dem Dokument nähme.

**DoD:** „ja" → 3 eintragen → zurück auf „nein" → absenden: Spalte leer, kein Textbaustein im
PDF, kein Pflichtfeldfehler. Eine bedingte „Erklärung" fehlt im PDF, wenn ihre Bedingung nicht
zutrifft. Ein erneut erzeugtes PDF (Backend-Aktion) liefert dasselbe Ergebnis.

### AP5 — Konfigurationsschutz

**Dateien:** `AnswerConfigListener` (neu `validateConditions()` als `save_callback`,
Reorder-Guard in `saveQuestionOrder()`/`renumberQuestions()`) ·
`src/Service/WorkflowValidator.php` · `contao/languages/{de,en}/workflow_messages.php`

- `validateConditions()`: unvollständige Zeilen verwerfen · bei `conditionMode === ''` die
  Bedingungen leeren (Muster: `clearConditionsForDefaultRule`) · Speichern **ablehnen** bei
  Selbstbezug, bei Verweis auf ein nachfolgendes Feld und bei `show`/`hide` ohne eine einzige
  vollständige Bedingung · **Hinweis** (nicht blockierend, Muster `warnOnTypeMismatch`), wenn
  der Wert zu keiner Option des Auslösers passt oder wenn `eq`/`neq`/`contains` auf einem
  Auslöser vom Typ Zahl/Datum verwendet wird (dort ist erst `ist (nicht) leer` belastbar).
- **Reorder-Guard:** Nach dem Umnummerieren prüfen, ob jeder Auslöser vor seinen abhängigen
  Feldern liegt. Verletzung → `Message::addError()` mit Feldnamen und die **alte** Reihenfolge
  zurückgeben (die Umsortierung wird verworfen).
- `WorkflowValidator`: neue Meldungen `condition_unknown_field` (Spalte fehlt in der
  Quelldatei) und `condition_forward_ref`; `orphanedFields()` markiert dann das Feld
  `questions` rot.

**DoD:** Auslöser hinter sein abhängiges Feld ziehen und speichern → verständliche Fehlermeldung,
Reihenfolge unverändert. Quellspalte in der Datei umbenennen → Workflow ist „nicht ausführbar"
mit benannter Ursache.

### AP6 — Feldliste (E7)

**Dateien:** `AnswerConfigListener::renderQuestionsList()` · neu
`public/workflow-question-deps.js` · `public/workflow-question-sort.js` (ein Event) ·
`public/workflow-backend.css`

**DoD:** Marker mit Klartext-Tooltip in beiden Richtungen; Hover hebt die verbundenen Zeilen
hervor; eine per Drag&Drop erzeugte Reihenfolgeverletzung wird sofort rot markiert; nach dem
Schließen eines Feld-Dialogs (dcaWizard-Refresh) funktioniert alles weiter.

### AP7 — Portabilität, Demo, Doku

**Dateien:** `src/Service/WorkflowConfigExporter.php` · `src/Service/WorkflowConfigImporter.php`
(`VERSION 7 → 8`, Kommentarblock v8, INSERT um drei Spalten erweitern) ·
`src/Service/DemoWorkflowSeeder.php` · `docs/ANLEITUNG.md` · `CHANGELOG.md` ·
`composer.json` (3.3.0 → 3.4.0)

Import älterer Dateien (v ≤ 7): die drei Keys fehlen → Defaults → unverändertes Verhalten.
Demo-Workflow (E9a): ein Auswahlfeld plus ein davon abhängiges Feld.

**DoD:** Export → Import auf derselben Installation reproduziert die Bedingungen; eine v7-Datei
importiert weiterhin fehlerfrei; die Anleitung erklärt das Leitbeispiel, den Merksatz aus 3.1
und die Leer-Regel E6.

**Reihenfolge:** AP1 → AP2 → (AP3 ∥ AP6) → AP4 → AP5 → AP7.

---

## 7. Fallstricke

| Fallstrick | Behandlung |
|---|---|
| Drag&Drop verschiebt den Auslöser hinter sein abhängiges Feld | Reorder-Guard (AP5) + rote Markierung (AP6) |
| Neuer Datensatz hat noch keine `sorting` | Bei `act=create` alle vorhandenen Felder als Auslöser anbieten (wird hinten angehängt) |
| Zwei Felder auf derselben Spalte | Bedingung prüft den **Spaltenwert**; beim Leeren gewinnt ein sichtbares Feld (E6) |
| Schreibgeschützte Felder rendern `disabled` | JS merkt sich den Ursprungszustand (`data-wf-was-disabled`) |
| Auto-Feld „Aktuelle Zeit" mit `hideInForm` | Nicht als Auslöser anbieten (kein DOM-Element); als *Ziel* zulässig — unsichtbar ⇒ kein Datum, Spalte wird geleert |
| Bedingtes Pflichtfeld | Pflicht gilt nur, solange sichtbar — im Tooltip dokumentieren |
| Sperre bei vorhandenen Antworten | Bedingungen bleiben editierbar (E8) |
| Workflow kopieren | Funktioniert ohne Zutun (Spaltennamen bleiben gleich, E1) |
| Versionierung | Die drei Spalten sind echte Felder → Contao-Diff zeigt Änderungen |

---

## 8. Tests

**Unit (PHPUnit, `tests/Service/`):**

- `ConditionMatcherTest`: alle Operatoren, Zahlenvergleich über `ValueParser` (für die
  PDF-Regeln), Case-Sensitivität von `eq` vs. `contains`.
- `FieldVisibilityTest`: `show`/`hide`, `and`/`or`, Kaskade über zwei Ebenen, Fail-open bei
  leerer/defekter Bedingung, Checkbox-Mehrfachwerte.

**Manuell (DDEV, `https://workflow.ddev.site`):** Leitbeispiel im Frontend und in der
Backend-Vorschau · Absenden mit umgeschaltetem Auslöser (E6 prüfen: Spalte leer, PDF ohne
Baustein) · Formular mit deaktiviertem JavaScript · Feldliste: Tooltips, Hover, Drop-Verletzung
· Export/Import · Import einer v7-Datei.

> Vor Testläufen im Container `ddev mutagen sync` ausführen — der Host→Container-Abgleich ist
> asynchron, sonst laufen die Tests gegen den alten Stand.

---

## 9. Ausdrücklich nicht in v1

- Operatoren `größer`/`kleiner` im Formular (E5) — nachrüstbar über `WorkflowNumber` plus die
  vorhandene JS-vs-PHP-Parity-Harness in `scripts/`.
- Feldtyp „Gruppe/Abschnitt": Mehrere Felder mit identischer Bedingung ergeben bereits
  faktisch eine Gruppe; ein echter Container ist additiv nachrüstbar.
- Bedingungen über den Auslöser konfigurieren („diese Option zeigt Feld X") — die Regeln eines
  Feldes sollen an einer Stelle stehen.
- Animierte Ein-/Ausblendung.
