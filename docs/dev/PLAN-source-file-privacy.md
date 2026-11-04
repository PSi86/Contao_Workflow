# Implementierungsplan 3.6.2: Quelldateien bleiben geschützt

**Stand:** 2026-11-04. **Zweig:** `feature/3.6.2-quelldatei-datenschutz`, abgezweigt von
`feature/3.6.1-versandweg-testbereich` (b4f2bdf).
**Version:** 3.6.2. 3.6.1 ist auf tsvkorntal.com installiert und wird deshalb nicht neu gebaut,
auch wenn sie nie veröffentlicht wird. Contao 5.7 bleibt 3.7.0 (PR #30).

**Anlass:** Auf tsvkorntal.de lag die Quelldatei eines Workflows in einem öffentlichen Ordner der
Dateiverwaltung. Sie war ohne Login herunterladbar, mit allen personenbezogenen Daten. Die
vorhandene Warnung in der Bearbeitungsmaske hat das gemeldet, aber nichts verhindert.

**Ziel:** Ein Nutzer kann eine Quelldatei hochladen und importieren, ohne einen Speicherort wählen
zu müssen, und sie landet immer geschützt. Die übrigen Wege, auf denen eine Quelldatei im
Contao-Back-End öffentlich werden kann, sind gesperrt. Was trotzdem öffentlich liegt, wird überall
gemeldet und lässt sich mit einem Klick beheben.

---

## 0. Ausgangslage (verifiziert, nicht neu ermitteln)

Gelesen im Code von Contao 5.3.46 (`contao-app/vendor/contao/core-bundle`), Abweichungen in 5.7 in
0.6.

### 0.1 Öffentliche Ordner in Contao

- Ein Ordner ist öffentlich, wenn er oder ein übergeordneter Ordner eine Datei `.public` enthält
  (`Folder::isUnprotected()`, `contao/library/Contao/Folder.php:354-370`). Die Prüfung des Bundles
  (`WorkflowIntegrityListener::sourceFileIsPublic()`) macht genau dasselbe.
- Den Haken „Öffentlich“ im Bearbeiten-Dialog eines Ordners verarbeitet Contao **in der
  Anzeige-Funktion des Feldes**, nicht beim Speichern: `tl_files::protectFolder()`
  (`contao/dca/tl_files.php:866-905`, eingebunden als `input_field_callback` des Feldes
  `protected`, Zeile 217). Sie liest den POST, legt `.public` an oder löscht sie und erzeugt sofort
  die Symlinks neu. Ein `save_callback` greift hier nicht.
- Bei Unterordnern eines öffentlichen Ordners ist der Haken ausgegraut (gleiche Funktion).
- Die vorhandene Warnung im Bundle verweist auf ein „Kontextmenü ‚Schützen‘“, das es in 5.3 nicht
  gibt. **Text ist falsch.**

### 0.2 Hochladen und Versionen

- Contao führt **keine Versionen hochgeladener Dateien**. `FileUpload::uploadTo()` verschiebt die
  Datei ohne Prüfung auf den Zielnamen (`contao/classes/FileUpload.php:186-189`). Eine Datei mit
  gleichem Namen wird also ohne Rückfrage überschrieben.
- Danach ruft `DC_Folder::move()` `Dbafs::addResource()` auf (`DC_Folder.php:1226-1229`). Bei
  vorhandenem Pfad aktualisiert das nur den Hash, der Eintrag und seine UUID bleiben. Der Workflow
  zeigt also weiter auf die Datei.
- Versionen gibt es nur für die Angaben im Bearbeiten-Dialog einer Datei (`DC_Folder::edit()`,
  Zeile 1402) und für Textdateien im eingebauten Quelltext-Editor (`DC_Folder::source()`,
  Zeile 2040). `csv` gehört standardmäßig zu den bearbeitbaren Typen
  (`src/DependencyInjection/Configuration.php:99-101`), `xlsx`/`xls` nicht.
- In Contaos Dateiauswahl (Picker) lässt sich in jeden Ordner hochladen. Der Knopf erscheint auch im
  Picker (`DC_Folder.php:500-502`, Ordner-Symbol Zeile 2786). Dieser Weg bleibt bestehen.

### 0.3 Dateiauswahl im Workflow (`fileTree`)

- `FileTree::checkValue()` prüft bei **jedem** Speichern: Dateityp, `filesOnly` und einen
  gesetzten `eval.path` (`contao/widgets/FileTree.php:100-160`). Ein `path` würde bestehende
  Workflows mit Dateien anderswo unspeicherbar machen. **Kein `path` setzen.**
- Dateifreigaben (Filemounts) prüft `checkValue()` nicht. Eine Datei, die das Bundle in einem
  Ordner ablegt, für den der Nutzer keine Freigabe hat, bleibt also gültig.
- `sourceFile` hat `submitOnChange`. Die Auswahl einer Datei speichert die Maske schon heute.

### 0.4 Hochladen in der Bearbeitungsmaske

- Ist ein Widget `UploadableWidgetInterface`, schaltet Contao das Formular auf
  `multipart/form-data` (`contao/classes/DataContainer.php:669-672`, Formular
  `DC_Table.php:2457`).
- `DC_Table::save()` ruft die `save_callback`s **vor** der Änderungsprüfung auf (Zeilen 3352ff.
  vor 3375). Der `save_callback` von `sourceFile` kann also die hochgeladene Datei eintragen.
- Die Werte sammelt `save()` in `arrSubmit` und schreibt sie gesammelt in `submit()` (Zeilen 3383,
  3393). Ein anderes Feld kann `sourceFile` deshalb nicht nachträglich per SQL setzen, es würde
  überschrieben. **Die Übernahme gehört in den `save_callback` von `sourceFile`.**
- Vorbild für einen Upload-Dialog in der Übersicht: `WorkflowActionController::importConfig()`
  (Route `/import-config`, `UploadedFile`).

### 0.5 Verschieben in der Dateiverwaltung

- `DC_Folder::cut()` fragt **vor** dem Verschieben die Rechteprüfung:
  `denyAccessUnlessGranted(DC_PREFIX.'tl_files', new UpdateAction(..., ['pid' => <Ziel>]))`
  (`DC_Folder.php:735-738`). Ein Voter des Bundles kann das verweigern.
- `oncut_callback` läuft erst **nach** dem Verschieben (`DC_Folder.php:769`).
- Ziehen mit der Maus läuft über denselben Weg: `act=cut` per Request (`assets/scripts/core.js:841`).
- Mehrfachauswahl (`cutAll()`) ruft `cut()` je Eintrag und **überspringt verweigerte Einträge ohne
  Meldung** (`DC_Folder.php:826-833`).
- Eine Verweigerung außerhalb von `cutAll()` zeigt die Fehlerseite des Back-Ends mit
  „Forbidden“ und der englischen Meldung „Not enough permissions to update …“
  (`PrettyErrorScreenListener`, `templates/Error/backend.html.twig`). Deshalb müssen die
  Bedienelemente vorher ausgegraut sein.
- Bedienelemente: Operation `cut` mit `button_callback` `tl_files::cutFile` (`tl_files.php:106-112`,
  Funktion Zeile 718), Operation `drag` mit `tl_files::dragFile` (Zeile 138-143).
- Die Einfüge-Symbole prüfen `CreateAction` mit dem Zielordner, nicht mit dem, was verschoben wird
  (`DC_Folder.php:2774`).
- `Dbafs::moveResource()` behält Eintrag und UUID. Ein Verschieben durch das Bundle lässt den
  Verweis des Workflows intakt.

### 0.6 Contao 5.7 (für 3.7.0, `contao-app-57`)

- Der Haken „Öffentlich“ ist gleich aufgebaut (`input_field_callback` `protectFolder`,
  `tl_files.php:221-225`).
- `oncut_callback` ebenfalls erst nach dem Verschieben (`DC_Folder.php:892`). `cutAll()`
  überspringt verweigerte Einträge ebenso (Zeile 948).
- **Abweichend:** Die Operation `cut` nutzt `tl_files::canRenameFile`
  (`tl_files.php:120-126`). Eine Operation `drag` wurde nicht gefunden. Die Zwischenablage wird
  schon beim Hineinlegen geprüft (`DC_Folder.php:318`). Für 3.7.0 anpassen.

### 0.7 Was das Bundle heute tut

- `WorkflowIntegrityListener::warnPublicSourceFile()` (`config.onload`) zeigt nur beim Öffnen der
  Bearbeitungsmaske eine Fehlermeldung. Speichern, Import und Versand laufen weiter. Die
  Übersicht zeigt nichts.
- `SourceFileGuardListener::assertSameColumns()` (`fields.sourceFile.save`) erzwingt bei
  gesperrtem Workflow (es liegen Antworten vor) identische Spalten.
- Die Demo legt ihre Datei unter `files/workflow-demo/` ab, ohne `.public`, also geschützt.

### 0.8 Gefunden bei der Umsetzung (2026-11-04)

Im Code nachgelesen (5.3, wo angegeben auch 5.7) bzw. im DDEV gemessen:

- **Neue Ordner sind öffentlich vorbelegt:** `DC_Folder::create()` legt `<ordner>/__new__` an und
  öffnet den Bearbeiten-Dialog (`DC_Folder.php:664-681`); dort ist der Haken „Öffentlich“ für
  `__new__` vorab gesetzt (`tl_files.php:917`, in 5.7 gleich). Wer einen Ordner anlegt und nur
  den Namen einträgt, veröffentlicht ihn. Ein naheliegender Weg, wie eine Quelldatei öffentlich
  wird – und ein Grund mehr für „Datei hochladen“.
- **Meldung des Hakens „Öffentlich“ geht beim Speichern verloren:** `DC_Folder::edit()` baut die
  Felder (also auch das umhüllte `protectFolder`), ruft danach `Message::generate()` für die Seite
  auf (`DC_Folder.php:1594`) und lädt erst dann neu (Zeile 1611ff.). Die erzeugte Seite wird
  verworfen und mit ihr die Meldung. Gelöst in `FileManagerGuardListener::keepRefusalsAcrossReload()`
  (bei einer Weiterleitung die Meldung erneut setzen).
- **Felder ohne `exclude` sind für Nicht-Admins ausgeschlossen:** `DataContainer::isFieldExcluded()`
  wertet ein Feld mit `inputType` ohne `exclude`-Angabe als ausgeschlossen (`DataContainer.php:1588`).
  Das Upload-Feld setzt deshalb `exclude => false`; wer es sieht, regelt das Recht an `sourceFile`.
  Gefunden durch `scripts/e2e-362-nonadmin.php` (N2 sah kein Upload-Feld).
- **Rechteprüfung:** Im Back-End entscheidet `PriorityStrategy` (`ContaoStrategy`); der Contao-Voter,
  der alles erlaubt, läuft mit Priorität -1024 zuletzt (`config/services.yaml` des Core). Ein
  „verweigert“ des Bundles (Priorität 0) gewinnt also.
- **Feldrechte nur an Gruppen:** `tl_user` hat in 5.3 keine Spalte `alexf`; Feldrechte stehen an
  `tl_user_group`.
- **Abweichung vom Plan:** Die Route „In geschützten Ordner verschieben“ ist **GET mit Request-Token**
  (wie Import und Zurücksetzen), nicht POST: Der Knopf steht in Meldungen über dem DCA-Formular,
  wo kein eigenes Formular geht; dazu Rechteprüfung und Rückfrage im Knopf.

---

## 1. Getroffene Entscheidungen (nicht neu verhandeln)

| # | Entscheidung | Datum / von |
|---|---|---|
| E1 | Import ist gesperrt, solange die Quelldatei öffentlich liegt. Zum Konzept gehört ein Weg, eine Quelldatei ohne Datenschutzproblem hochzuladen und zu importieren; nur sperren wäre zu kurz gedacht. | Nutzer, 2026-11-04 |
| E2 | Der Versand wird **nicht** gesperrt. | Nutzer, 2026-11-04 |
| E3 | „In geschützten Ordner verschieben“ darf jeder, der den Workflow bearbeiten darf. Das Bundle verschiebt selbst, ohne Dateifreigaben des Nutzers zu prüfen. | Nutzer, 2026-11-04 |
| E4 | Version 3.6.2 (3.6.1 ist auf tsvkorntal.com installiert). | Nutzer, 2026-11-04 |
| E5 | Das Bundle **löscht keine Dateien**. Eine vorherige Quelldatei bleibt nach dem Hochladen einer neuen erhalten: transparentes Verhalten, niemand soll sich über verschwundene Dateien wundern. | Nutzer, 2026-11-04 |
| E6 | **Ein zentraler Ordner** `files/workflow-quellen/` für alle hochgeladenen Quelldateien, keine Unterordner je Workflow. Das Modell „gemeinsame und eigene Dateien“ ist als Option für später festgehalten (Abschnitt 6). | Nutzer, 2026-11-04 |
| E7 | Upload an zwei Stellen: in der Bearbeitungsmaske (neuer Workflow) und in der Übersicht als „Quelldatei aktualisieren“ mit „Danach importieren“ (regelmäßiges Aktualisieren). | Nutzer, 2026-11-04 |
| E8 | Quelldateien und Ordner, die eine enthalten, lassen sich im Contao-Back-End nicht verschieben. Die Bedienelemente sind ausgegraut, mit Hinweis. Der einzige Weg ist der Knopf des Workflows. | Nutzer, 2026-11-04 |
| E9 | Ein Ordner mit einer Quelldatei lässt sich nicht veröffentlichen; `files/workflow-quellen` nie. | Konzept, 2026-11-04 |
| E10 | Eine **neue** Auswahl aus einem öffentlichen Ordner wird beim Speichern abgelehnt. Ein bestehender Workflow mit öffentlicher Quelldatei bleibt speicherbar (er bekommt Warnung und Knopf). | Konzept, 2026-11-04 |
| E11 | Keine Datenbankänderung, keine Migration. `sourceFile` bleibt ein Verweis (UUID) in die Dateiverwaltung. Der Ordner entsteht erst beim ersten Hochladen oder Verschieben. | Konzept, 2026-11-04 |
| E12 | Liegt im zentralen Ordner schon eine Datei mit dem Namen der hochgeladenen (oder verschobenen), wird **nie überschrieben**: Die neue Datei bekommt einen Zusatz (`Mitglieder-2.xlsx`), die Meldung sagt das. Folgt aus E5; Nebeneffekt: Ein Hochladen ändert nie die Daten eines anderen Workflows, der dieselbe Datei nutzt. (F1) | Nutzer, 2026-11-04 |
| E13 | Löschen einer Datei, die ein Workflow nutzt, und eines Ordners, der eine solche enthält, ist im Back-End ebenfalls gesperrt: `DeleteAction` (`DC_Folder.php:1063`), Lösch-Symbol ausgegraut mit Hinweis. Alles, was sich löschen lässt, nutzt kein Workflow. (F2) | Nutzer, 2026-11-04 |

## 2. Offene Fragen

Keine (F1 → E12, F2 → E13, beide 2026-11-04).

## 3. Ablauf für den Nutzer

**Neuer Workflow (Maske):** Abschnitt „Quelldatei“ bekommt unter der Dateiauswahl das Feld „Datei
hochladen“. Datei wählen → die Maske speichert (wie heute bei der Dateiauswahl), das Bundle legt
die Datei in `files/workflow-quellen/` ab und trägt sie als Quelldatei ein → Blatt, Kopfzeile,
E-Mail-Spalte wählen → Import über den vorhandenen Import-Hinweis der Maske.

**Laufender Workflow (Übersicht):** „Quelldatei aktualisieren“ → Dialog mit Dateiauswahl und dem
vorausgewählten Haken „Danach importieren“ → Spaltenprüfung (bei gesperrtem Workflow wie heute:
identische Spalten), sonst ändert sich nichts → Datei ablegen, eintragen, importieren → Ergebnis wie
beim heutigen Import. Die bisherige Datei bleibt liegen (E5). Nutzt ein anderer Workflow sie, sagt
die Meldung, dass er weiter die bisherige Datei liest.

**Vorhandene Datei für einen weiteren Workflow:** über Contaos Dateiauswahl wie bisher. Dazu
braucht ein Nicht-Admin eine Dateifreigabe für `files/workflow-quellen` (in die Doku).

**Quelldatei liegt öffentlich** (aus der Zeit vor 3.6.2 oder von außerhalb Contao): rote Meldung in
der Übersicht (Karte des Workflows), in der Maske (oben) und bei verweigertem Import,
jeweils mit „In geschützten Ordner verschieben“. Verschiebt nach `files/workflow-quellen/` (bei
Namensgleichheit Zusatz wie E12), UUID bleibt, alle Workflows mit dieser Datei laufen weiter.

## 4. Architektur

- **`SourceFilePrivacy`** (Service): `publicFolderOf(path): ?string` (der Ordner mit `.public`,
  sonst null), `isPublic(path)`, `workflowsUsing(pathOrFolder): list<WorkflowModel>` (über
  `tl_files.uuid = tl_workflow.sourceFile`, Ordner mit Präfix `path/`). Ersetzt
  `sourceFileIsPublic()`; genutzt von Warnung, Auswahlprüfung, Import, Übersicht, Dateiverwaltung.
- **`SourceFileStore`** (Service): `store(UploadedFile): FilesModel` (Typ `csv,xlsx,xls`, Größe wie
  Contaos `maxFileSize`, Dateiname bereinigen, lesbar per `SpreadsheetInspector::sheetNamesOf()`,
  Zusatz bei Namensgleichheit, `Dbafs::addResource()`); `moveToProtected(FilesModel)`
  (`Files::rename()` + `Dbafs::moveResource()`); legt den Ordner bei Bedarf an und verweigert, wenn
  er oder ein übergeordneter Ordner öffentlich ist.
- **Spaltenprüfung** aus `SourceFileGuardListener` in einen Service ziehen, mit einer Variante für
  einen Pfad (die hochgeladene Datei ist noch keinem Workflow zugeordnet).
- **Kein neues Feld in der Datenbank.** Das Upload-Feld der Maske ist ein Pseudofeld (eigenes Widget,
  `UploadableWidgetInterface`, kein `sql`, nie gespeichert); die Übernahme macht der
  `save_callback` von `sourceFile` (0.4).

## 5. Arbeitspakete

**Stand 2026-11-04: AP-Q1 bis AP-Q4 umgesetzt** (Version 3.6.2 geschnitten), lokal auf dem Zweig.
Geprüft:
- PHPUnit: 417 Tests grün unter 5.3 und 5.7 (`scripts/run-phpunit.sh 53|57`), neu:
  `SourceFilePrivacyTest`, `SourceFileStoreTest`, `FileManagerGuardTest`, `SourceFileVoterTest`,
  `SourceColumnCheckTest`. 8 Mutationen (`scripts/mutate-362.sh`) alle erkannt.
- DDEV 5.3 über HTTP: `scripts/e2e-362.php` (A1–A14, C1–C5, D1–D2, E1–E7) und
  `scripts/e2e-362-nonadmin.php` (N1–N3) grün; 4 Mutationen der Sperren
  (`scripts/mutate-362-e2e.sh`: Import, Auswahl, Dateiverwaltung, Voter) jeweils von genau ihren
  Prüfungen erkannt.
- Container 5.3 baut, keine Schemaänderung (`scripts/check-362-container.sh`).

Nicht geprüft: ein echter Browser (Dialog „Quelldatei aktualisieren“ öffnen/schließen, Absenden
der Maske beim Wählen einer Datei per `Backend.autoSubmit`), Contao 5.7 im Betrieb (3.7.0).

### AP-Q1 — Erkennen, melden, Import sperren, verschieben

- `SourceFilePrivacy`; Warnung der Maske mit korrigiertem Text (nennt den öffentlichen Ordner und die
  öffentliche Adresse der Datei).
- `fields.sourceFile.save`: neue Auswahl aus öffentlichem Ordner ablehnen (E10), Meldung verweist
  auf „Datei hochladen“.
- Import verweigern bei öffentlicher Quelldatei: Route `workflow_import`, `ImportCommand`; Meldung
  mit Knopf.
- Übersicht: rote Zeile je betroffenem Workflow mit Knopf.
- Route „In geschützten Ordner verschieben“ (GET mit Request-Token, siehe 0.8; Bearbeitungsrecht
  an der Quelldatei, E3).
- Tests: Unit für `SourceFilePrivacy` und Store; Mutationen; DDEV: öffentliche Adresse vorher
  erreichbar, nachher 404.

### AP-Q2 — Hochladen

- Upload-Widget in der Maske, Übernahme im `save_callback` von `sourceFile`, Spaltenprüfung.
- Dialog „Quelldatei aktualisieren“ in der Übersicht (multipart, „Danach importieren“).
- Namensgleichheit nach E12.
- Tests: Unit für Store; DDEV-E2E für beide Wege, auch gesperrter Workflow mit abweichenden Spalten.

### AP-Q3 — Dateiverwaltung absichern

- Haken „Öffentlich“: `input_field_callback` von `tl_files.protected` umhüllen. Will der POST
  veröffentlichen und enthält der Ordner (in beliebiger Tiefe) eine Quelldatei oder ist es
  `files/workflow-quellen` → POST-Wert leeren, Meldung mit den Workflows, dann an
  `tl_files::protectFolder()` weitergeben.
- Verschieben: Voter auf `contao_dc.tl_files` verweigert `UpdateAction` mit geändertem `pid` für
  Quelldateien, Ordner mit Quelldateien und `files/workflow-quellen`. `cut` und `drag`
  ausgegraut bzw. ausgeblendet (umhüllte `button_callback`s), Hinweis nennt die Workflows. Nach
  einer Mehrfachauswahl melden, welche Quelldateien liegen geblieben sind.
- Löschen (E13): dasselbe für `DeleteAction` und das Lösch-Symbol.
- Tests: Unit für Voter; DDEV: Haken, Einzel-/Mehrfach-Verschieben, Ziehen (Request mit
  `act=cut`).

### AP-Q4 — Doku, Changelog, Release

- `docs/ANLEITUNG.md` (Hochladen, Aktualisieren, Verschieben-Knopf), `README.md` Datenschutz-Abschnitt,
  Hinweis Dateifreigabe für Nicht-Admins.
- `CHANGELOG.md` `[3.6.2]`: „Beim Update: nur den Cache leeren; keine Datenbankänderung.“
- Version, ZIP (`scripts/build-bundle.ps1`), Test auf tsvkorntal.com, Release nur auf Freigabe.

## 6. Option für später: gemeinsame und eigene Quelldateien

Zurückgestellt am 2026-11-04 (E6), weil der Aufwand für den Augenblick zu groß ist. So war es
gedacht:

- `files/workflow-quellen/` enthält die **gemeinsamen** Dateien, jeder Workflow darf sie nutzen.
- `files/workflow-quellen/<Workflow-ID>/` gehört **genau einem** Workflow. Workflows sehen den
  gemeinsamen Ordner, aber nicht die Ordner anderer Workflows.
- Beim Hochladen wählt der Nutzer „nur für diesen Workflow“ oder „für alle Workflows“.
- Contaos Dateiauswahl lässt sich nicht auf diese Sicht filtern (`eval.path` ist breaking, 0.3).
  Deshalb prüft das Bundle beim Speichern: Eine Datei aus dem Ordner eines anderen Workflows wird
  abgelehnt, mit Hinweis auf den gemeinsamen Ordner.
- Das Upload-Feld listet die vorhandenen gemeinsamen Dateien zur Auswahl, jeweils mit den Workflows,
  die sie nutzen; so braucht niemand Contaos Dateiauswahl oder eine Dateifreigabe.
- Eine gemeinsame Datei **ersetzen** betrifft alle Workflows, die sie nutzen. Das Bundle nennt sie
  vorher. Hat einer davon Antworten, müssen die Spalten passen, sonst wird nichts ersetzt. Danach
  zeigt jeder betroffene Workflow „Quelldatei geändert – neu importieren“. „Danach importieren“ in
  der Übersicht gilt für alle betroffenen, die importbereit sind.
- Eine gemeinsame Datei wird nie stillschweigend überschrieben: Eine andere Datei unter vorhandenem
  Namen bekommt einen Zusatz; Ersetzen nur ausdrücklich.
- Was mit vorherigen Dateien geschieht, richtet sich nach E5 (behalten), solange nicht anders
  entschieden.
- **Nachrüstbar ohne Breaking Change:** `sourceFile` ist eine UUID, und `Dbafs::moveResource()`
  behält sie. Dateien aus dem zentralen Ordner lassen sich später in Unterordner verschieben, ohne
  dass ein Workflow seinen Verweis verliert.

## 7. Produktion (tsvkorntal.de)

- Bis 3.6.2 installiert ist: Datei von Hand in einen geschützten Ordner verschieben (UUID bleibt),
  alte Adresse prüfen, Zugriffs-Logs prüfen, Vorfall nach DSGVO mit dem Datenschutz-Verantwortlichen
  des Vereins bewerten und dokumentieren (Meldefrist 72 Stunden, außer voraussichtlich kein Risiko).
- Nach dem Update: Übersicht prüfen; erscheint eine rote Zeile, „In geschützten Ordner verschieben“.
