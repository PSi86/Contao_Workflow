# Changelog

Alle nennenswerten Änderungen an diesem Bundle. Format angelehnt an
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/); Versionierung nach
[SemVer](https://semver.org/lang/de/).

## [Unreleased]

### Geändert
- **Das Zahlenformat einer Spalte wird jetzt beim Import übernommen**, nicht mehr nur beim
  Speichern des Formularfelds im Backend. Das Format beschreibt die Daten, also folgt es den
  Daten. Damit verhält sich das **Überschreiben der Quelldatei genauso wie das Auswählen einer
  neu benannten Datei** mit gleichem Inhalt — bisher wurde das Format nur im zweiten Fall
  aufgefrischt, weil dabei nebenbei der Workflow gespeichert wurde. Kann eine Spalte kein
  Zahlenfeld tragen (z. B. gemischte Nachkommastellen), behält das Feld sein bisheriges Format
  und der Import benennt das Problem, statt still weiterzurechnen.

- **Ein Formularfeld auf „Zahl" umzustellen warnt jetzt sofort**, wenn die Speicherspalte das
  nicht hergibt (etwa weil sie in Excel als Text formatiert ist). Bisher meldete sich das nur
  beim Speichern der Speicherfeld-Auswahl — und die ist gesperrt, sobald Antworten vorliegen,
  sodass die Warnung ausblieb. Die Meldung blockiert das Speichern **nicht**: Bei gesperrter
  Spalte hilft nur ein anderer Feldtyp oder eine Änderung in der Quelldatei, ein Speicherverbot
  würde nur den Weg dorthin versperren.

### Behoben
- **Zahlenfelder verloren die Nachkommastellen.** Aus `11,56` wurde `12`, und `0` bzw. `100`
  wurden nicht zu `0,00` bzw. `100,00` ergänzt. Ursache war ein fehlender Formatschnappschuss
  am Feld: Er wurde ausschließlich beim Speichern der Speicherfeld-Auswahl geschrieben — und
  genau die ist gesperrt, sobald Antworten vorliegen. Ohne Schnappschuss wurde das Format aus
  dem Wert **eines einzelnen Teilnehmers** geraten (wodurch dasselbe Feld je Teilnehmer anders
  rechnete) und fiel andernfalls auf „ganzzahlig" zurück. Ein unbekanntes Format bedeutet jetzt
  „so viele Nachkommastellen wie eingegeben" statt „keine".
- **Schreibgeschützte Datumsfelder waren auf Mobilgeräten trotzdem änderbar.** Mobile Browser
  ignorieren die Schreibsperre bei Datumsfeldern und öffnen den Datumswähler dennoch. Gespeichert
  wurde die Änderung nie – der Server verwirft die Werte gesperrter Felder grundsätzlich –, aber
  im Formular sah es aus, als wäre die Eingabe übernommen worden, und im Dokument stand
  anschließend der alte Wert. Solche Felder sind jetzt echt gesperrt, wie die
  Auswahlfelder es bereits waren.

## [3.0.2] – 2026-07-21

### Behoben
- **Absturz, wenn das eingestellte Tabellenblatt in der Quelldatei fehlte.** Statt einer
  Meldung erschien eine technische Fehlerseite („You tried to set a sheet active by the out of
  bounds index: 0"), und der Workflow ließ sich weder speichern noch importieren. Ausgelöst
  wurde das vor allem dadurch, dass die **Quelldatei an Ort und Stelle überschrieben** wurde:
  Trägt der neue Export ein anders benanntes Tabellenblatt, zeigt die Einstellung weiterhin auf
  den alten Namen — anders als beim Hochladen einer neuen Datei, wo die Blatt-Auswahl ohnehin
  neu getroffen wird. Jetzt nennt die Meldung das fehlende Blatt **und die tatsächlich
  vorhandenen**, sowohl beim Speichern als auch beim Import.

## [3.0.1] – 2026-07-19

Fehlerkorrektur zu 3.0.0; ersetzt diese vollständig.

### Behoben
- **„Formularfeld anlegen" endete bei gesperrten Workflows auf einer Fehlerseite.** Liegen
  bereits Antworten vor, sind Anlegen und Löschen von Formularfeldern gesperrt – die Knöpfe
  wurden aber weiterhin angeboten, weil die Sperre erst beim Öffnen des Dialogs griff. Statt
  einer Fehlermeldung („Table … is not creatable") werden „Neu" und das Löschen-Symbol jetzt
  gar nicht mehr angezeigt.

## [3.0.0] – 2026-07-19

Diese Version schützt erfasste Antworten konsequent gegen nachträgliche Änderungen an der
Konfiguration. Der Sprung auf eine neue Hauptversion ergibt sich daraus, dass Einstellungen,
die bisher jederzeit änderbar waren, nun gesperrt sein können, und dass sich das Verhalten von
Import und Versand geändert hat – siehe „Geändert" (im Einzelnen: Feld „Schritte" entfällt,
`workflow:import --force` entfällt, der Import läuft immer, ein unveröffentlichter Workflow
verschickt nichts mehr).

### Hinzugefügt
- **Quell-Einstellungen werden gesperrt, sobald Antworten vorliegen.** Tabellenblatt,
  Kopfzeile, E-Mail-Spalte und die Speicherspalten der Formularfelder lassen sich dann nicht
  mehr ändern, ebensowenig lassen sich Formularfelder anlegen oder löschen — jede dieser
  Änderungen würde beim nächsten Import die bereits erfassten Antworten überschreiben oder von
  ihrer Frage trennen, obwohl auf ihrer Grundlage bereits Dokumente ausgestellt und versendet
  wurden. Die betroffenen Felder sind in der Maske als gesperrt gekennzeichnet, mit Hinweis auf
  die Auswege. Wortlaut, Dokumenttexte, Optionen, Briefpapier und Benachrichtigungen bleiben
  frei änderbar.
- **Die Quelldatei bleibt dabei austauschbar** — allerdings nur gegen eine Datei mit exakt
  denselben Spalten. Genau das ist der Weg, mitten im Durchlauf Teilnehmer nachzumelden oder
  Daten zu korrigieren: einfach den aktuellen Export hochladen. Weichen die Spalten ab, wird
  das Speichern mit einer Meldung abgelehnt, die die Unterschiede benennt. Bereits beantwortete
  Teilnehmer bleiben beim folgenden Import unverändert.
- **Aktion „Alle Teilnehmer zurücksetzen"** in den Workflow-Einstellungen (eigener,
  zugeklappter Abschnitt am Ende, mit Sicherheitsabfrage und Nennung der Anzahl). Sie ist der
  Weg, eine Änderung **noch im laufenden Durchlauf** zu ermöglichen: alle Antworten verlieren
  ihre Gültigkeit, die Sperre fällt. Erhalten bleiben die erfassten Daten, die Links der
  Teilnehmer und die bisherigen Dokumente. Für den **nächsten Durchlauf** bleibt eine Kopie des
  Workflows der vorgesehene Weg.
- **Ein unveröffentlichter Workflow blockiert den Versand.** Bisher liefen Einladungen und
  Erinnerungen anstandslos durch, und **alle** Empfänger bekamen beim Klick auf ihren Link
  „ungültig" zu sehen — ein Fehler, der beim Versand unsichtbar blieb. Zusätzlich werden die
  Versandvoraussetzungen jetzt auch serverseitig geprüft und nicht nur in der Übersicht
  ausgeblendet.

### Behoben
- **Options-Listen konnten einen Fatal auslösen, wenn Contao sie ohne Datensatz-Kontext
  abfragt.** Die Callbacks für Tabellenblatt, E-Mail-Spalte, Unterschriftsdatum/-ort und
  Speicherfeld verlangten zwingend einen DataContainer, obwohl Contao dort auch `null`
  durchreicht (etwa aus dem MultiColumnWizard). Sie liefern nun eine leere Liste statt eines
  Fehlers.
- **Der Formular-Link eines Teilnehmers ließ sich nicht sauber kopieren.** Er stand als
  Fließtext im Hilfetext des Token-Felds, sodass ein Doppelklick die umgebenden Worte mitnahm
  und die Zeile mitten im Link umbrechen konnte. Er steht weiterhin an derselben Stelle, jetzt
  aber als eigenes Element: **ein Klick markiert genau die URL und kopiert sie zugleich in die
  Zwischenablage** (kurze Bestätigung „✓ kopiert"). Ohne Zwischenablage-Berechtigung bleibt die
  URL markiert und lässt sich von Hand kopieren. Die beiden Hinweis-Popups, die Contao über
  diesem Hilfetext einblendete – darunter eine funktionslose Kopie des Links, die beim
  Daraufzubewegen verschwand –, entfallen; auf Tablets öffnete sich sonst beim Antippen das
  Popup, statt den Link zu kopieren.
- **Gesperrte Auswahlfelder ließen sich trotz Sperre noch bedienen.** Gespeichert wurde die
  Änderung zwar nie, sie verschwand aber erst beim Speichern kommentarlos. Ursache waren zwei
  Schichten oberhalb des Servers: das Chosen-Suchfeld ersetzt das Auswahlfeld durch eigenes
  Markup, und die typabhängige Feldsteuerung der Formularfelder schaltete beim Umschalten alle
  sichtbaren Felder wieder aktiv. Beide berücksichtigen die Sperre jetzt.
- **Der Hinweis auf mehrdeutige Platzhalter erschien doppelt.** Wurde der Import aus der
  Bearbeitungsmaske heraus angestoßen, meldeten ihn sowohl der Import als auch die Maske selbst
  — inhaltlich identisch, nur anders formuliert. Der Import meldet ihn jetzt nur noch dann,
  wenn er aus der Übersicht kommt; dort ist er die einzige Quelle, während die Maske ihn beim
  Laden ohnehin je Gruppe anzeigt. Der Hinweis geht damit in keiner Situation verloren.
- **Zurückgesetzte Teilnehmer fehlten in „Offene Vorgänge".** Wurde der Status eines
  Teilnehmers im Backend zurückgesetzt, damit er das Formular erneut ausfüllen darf,
  verschwand er aus der Liste — und zwar für den gesamten Wiederholungs-Vorgang. Ursache: die
  Liste prüfte nur den Zustellungs-/Bestätigungszustand und **gar nicht den Status**, und der
  Zeitstempel der bereits erzeugten Bestätigung bleibt beim Zurücksetzen erhalten. Die Liste
  zeigt jetzt wie erwartet **alle Teilnehmer, die den letzten Schritt nicht fehlerfrei
  erreicht haben**. Damit stimmt sie auch wieder mit dem Zähler „offen" überein.

### Geändert
- **Status-Reset räumt jetzt vollständig auf.** Wird der Status eines Eintrags unter
  „Beantwortet" zurückgesetzt, werden zusätzlich Antwortzeitpunkt und Bestätigungsstatus
  geleert. Vorher galt der Eintrag intern weiterhin als beantwortet: der Nachbearbeitungs-Cron
  bzw. die Aktion „Bestätigung neu senden" hätten die **alte** Bestätigung erneut verschickt.
  Die erfassten Antworten selbst bleiben als Vorbelegung des Formulars erhalten, ebenso Token
  und damit der bereits versendete Link.
- **Status ist jetzt ein Auswahlfeld** (mit den Schritt-Bezeichnungen des Workflows) statt
  eines freien Zahlenfelds — es lässt sich kein Status mehr eintragen, den der Workflow gar
  nicht kennt.
- **Die Einträge-Liste zeigt die Schritt-Bezeichnung statt der Rohzahl** — also „Eingeladen"
  statt „[Status 1]". Bezeichnung und Auswahlfeld stammen jetzt aus derselben Quelle.
- **Das Feld „Schritte" entfällt in den Workflow-Einstellungen.** Die drei Zustände stehen
  fest; die Liste konnte sie nur umbenennen — ihre Länge und Reihenfolge legten dabei aber
  still fest, was die Zustände *bedeuten*. Ein vierter Schritt erklärte einen Zustand zum Ziel,
  den nichts je erreicht (Zähler „erledigt"/„offen" dauerhaft falsch, „Bestätigungen senden"
  ohne Empfänger, Import-Schutz ausgehebelt); ein Umsortieren beschriftete die Zustände
  schlicht falsch. Die Bezeichnungen kommen jetzt aus einer zentralen Definition. Bestehende
  Daten und das Konfigurations-Format bleiben unverändert.
- **Beantwortete Teilnehmer werden beim Import nicht mehr überschrieben.** Bisher wurden nur
  die Antwortspalten geschützt, alle übrigen Quellspalten eines abgeschlossenen Teilnehmers
  aber überschrieben — obwohl auf deren Basis bereits ein PDF ausgestellt und versendet wurde.
  Solche Einträge bleiben jetzt vollständig unangetastet (nur die Zeilennummer für die
  Export-Sortierung wird nachgeführt), und die Import-Meldung weist sie eigens aus. Maßgeblich
  ist der Antwortzeitpunkt statt des Status, der sich durch eine geänderte Schrittliste
  nachträglich verschieben konnte. Ein zurückgesetzter Teilnehmer wird wieder frisch importiert.
- **Import läuft immer, auch bei unveränderter Quelldatei.** Das erneute Einlesen ist der Weg,
  die ursprünglichen Quelldaten wiederherzustellen; der bisherige Abbruch per Prüfsumme stand
  dem im Weg. Die Prüfsumme wird weiterhin gepflegt und treibt unverändert den Hinweis
  „Quelldatei geändert – Import nötig". Die Option `--force` von `workflow:import` entfällt
  damit ersatzlos.

## [2.14.0] – 2026-07-17

### Hinzugefügt
- **Hinweis „Quelldatei geändert – Import nötig".** Wird die Quelldatei eines Workflows
  gewechselt, ohne den Import erneut auszuführen, zeigten Vorschau-Formular und Vorschau-PDF
  weiterhin die **alten** Daten und Zahlenformate. Solange dieser Zustand anhält (Quelldatei
  geändert, aber noch kein Import), erscheint jetzt ein Hinweis — **sowohl in den
  Workflow-Einstellungen als auch in der Übersicht** beim betroffenen Workflow. In den
  Einstellungen lässt sich der Import direkt über einen „Jetzt importieren"-Link ausführen
  (danach zurück zur Bearbeitungsmaske). Erkannt über die Prüfsumme der Datei gegen die des
  letzten Imports; DE + EN lokalisiert.
- **Diagnose der Bounce-Erkennung in der Workflow-Übersicht.** Oben in der Übersicht wird jetzt
  der Zustand des Bounce-Postfachs angezeigt, ohne dass die Seite selbst eine IMAP-Verbindung
  öffnet (sie liest den letzten Cron-Befund):
  - **Hinweis-Banner (blau)**, wenn **kein** Postfach konfiguriert ist bzw. die
    `WORKFLOW_BOUNCE_IMAP_DSN` nicht geladen wurde (z. B. Managed Edition mit `.env.local.php`,
    die `.env.local` nicht direkt liest) – in diesem Zustand werden Zustellfehler und Bounces
    nicht erkannt. Im System-Log erscheint dazu eine **Warnung**, kein Fehler.
  - **Fehler-Banner (rot)**, wenn ein Postfach konfiguriert, beim letzten Lauf aber nicht
    erreichbar war (falscher Host/Port, Zugangsdaten, Passwort-Format …). Grund und Zeitpunkt
    stehen im Banner und als **Fehler** im System-Log. `workflow:bounce:collect` (ohne `--dsn`)
    aktualisiert das Banner nach einer Korrektur sofort.
  - Alle Texte sind DE **und** EN lokalisiert.

### Geändert
- **Ein zentraler Slugger auf Basis von Symfonys `AsciiSlugger` ersetzt die vier bisherigen
  Slug-Varianten.** Deutsch bleibt bit-identisch (`Übungsleiter`→`uebungsleiter`, bewiesen an den
  echten Spaltennamen — kein bestehender `##data_*##`-Token bricht), aber jedes andere Skript
  wird jetzt korrekt transliteriert (`Отдел`→`otdel`, `人事部`→`ren_shi_bu`) statt zu einem
  leeren Slug zu kollabieren.
- **Download-Dateinamen behalten ihre Original-Zeichen.** Export, Konfig-Export und PDF-ZIP
  senden den echten Namen (`EStG Übungsleiter …xlsx`, `Отдел …xlsx`) als RFC-5987-Header
  (`filename*=UTF-8''…`) mit einer ASCII-Transliteration als Fallback für alte Clients — es wird
  kein Zeichen mehr verschluckt.
- **Spalten-Erkennung (Name/Vorname/Abteilung) transliteriert zuerst**, sodass ein Header wie
  „Übungsleiter-Nachname" nicht mehr an einem verschluckten Umlaut scheitert.
- **Der Bounce-Cron loggt seinen Konfigurationszustand nur noch bei einem Zustandswechsel.**
  Die frühere „nicht konfiguriert"-Zeile bei jedem Lauf (alle 15 Minuten) entfällt; stattdessen
  wird der Wechsel *in* einen Zustand einmal protokolliert (Warnung: nicht konfiguriert; Fehler:
  nicht erreichbar; Info: wieder erreichbar). Das dauerhaft sichtbare Signal ist das
  Übersichts-Banner, das System-Log bleibt die Wechsel-Chronik.

### Behoben
- **Umlaute und andere Nicht-ASCII-Zeichen wurden in Dateinamen und Token-Slugs verschluckt.**
  Der Export-Dateiname (XLSX/CSV) und der Konfig-Export (JSON) machten aus „EStG Übungsleiter"
  ein „EStG bungsleiter" (das Ü fiel ersatzlos weg), weil sie nicht transliterierten. Schwerer
  wog: der `##data_*##`-Token-Slug kannte nur die sieben deutschen Umlaut-Zeichen — bei jedem
  anderen Skript (Kyrillisch, CJK, Griechisch, Arabisch, akzentuiertes Latein) blieb nach dem
  Strip **nichts** übrig, sodass **alle** Spalten auf denselben leeren Slug kollidierten und nur
  die erste im Dokument adressierbar war.

## [2.12.0] – 2026-07-17

Fasst die zurückgezogene 2.11.2 mit zusammen (sie war nur kurz veröffentlicht und wurde nie
installiert).

### Behoben
- **Nach dem Kopieren blieben „Datum/Ort für Unterschriftszeile" unmarkiert.** Eine Kopie
  übernimmt die Quelldatei nicht (`doNotCopy`), weshalb alle Felder, die eine Spalte der
  Quelldatei benennen, rot umrandet und mit „Unbekannte Option" versehen werden. Bei diesen
  beiden griff das nicht:
  - **Keines von beiden wurde rot umrandet**: `WorkflowValidator::orphanedFields()` kannte nur
    `sourceSheet`, `emailField`, `questions` und `rules`. Beide Felder benennen aber genauso
    eine Spalte und werden jetzt nach derselben Regel geprüft — auch einzeln, wenn die
    Quelldatei vorhanden, der Wert darin aber nicht mehr enthalten ist.
  - **„Datum für Unterschriftszeile" zeigte zusätzlich kein „Unbekannte Option"**: seine
    Auswahlliste stammte aus den Datums-/„Aktuelle Zeit"-Antwortfeldern des Workflows statt aus
    der Quelldatei. Die Antwortfelder werden mitkopiert, also existierte die Option weiterhin
    und der Wert sah gültig aus. Die Liste ist jetzt auf Spalten beschränkt, die die aktuelle
    Quelldatei wirklich hat.

### Geändert
- **Die heruntergeladene PDF-ZIP heißt jetzt nach Workflow, Zeitpunkt und Anzahl.** Statt
  `workflow_pdfs_19.zip` (was im Download-Ordner nichts aussagte) z. B.
  `EStG_Uebungsleiter_20260717_131534_3-PDFs.zip` — gleiche Form wie der Tabellen-Export, also
  sortieren mehrere Pakete eines Workflows chronologisch.
- **Großgeschriebene Umlaute werden in Dateinamen korrekt transliteriert.** `Ü` ergab bisher
  `ue` statt `Ue`, weshalb ein PDF-Name mitten im Wort kleingeschrieben wurde
  („Verzicht_uebungsleiter_…"). Betrifft neu erzeugte Dateien; die Platzhalter-Auflösung
  (`##data_*##`) ist unberührt, sie schreibt ohnehin alles klein.
- **Der Bounce-Cron schreibt nur noch ins System-Log, wenn er etwas getan hat.** Die Zeile
  „Bounce-Postfach geprüft (…): 0 Nachricht(en), …" erschien bei jedem Lauf, also alle 15
  Minuten — ein leeres Postfach ist aber der Normalzustand, und die Meldung begrub die
  Einträge, auf die es ankommt. Sie erscheint jetzt nur noch, wenn Nachrichten verarbeitet
  wurden (auch wenn keine davon ein DSN trug — sie wurden trotzdem aus der INBOX verschoben).
  Fehler werden unverändert immer geloggt, und `workflow:bounce:collect` berichtet weiterhin
  jeden Schritt, sodass sich die Funktionsfähigkeit jederzeit prüfen lässt.

## [2.11.1] – 2026-07-17

### Behoben
- **Zu großer Abstand zwischen Fließtext und Unterschriftsfeld im PDF.** Der Abstand entstand
  aus zwei Quellen — dem `margin-bottom` des Body (18px) und dem `margin-top` des
  Signaturblocks (50px) — die mPDF unterhalb einer Schwelle kollabieren lässt, oberhalb aber
  addiert: 18px ergaben denselben Abstand wie 0px, 19px sprangen um 14px nach unten. Der Body
  hat jetzt kein unteres Margin mehr (er steht immer unmittelbar vor dem Signaturblock, das
  Margin wirkte nirgends sonst), womit der Abstand exakt dem `margin-top` des Blocks
  entspricht. Auf 30px halbiert: gemessen 66,2px → 33,0px. Betrifft beide Briefpapier-Master.

## [2.11.0] – 2026-07-17

### Behoben
- **Zahlenfeld verfälschte Werte um den Faktor 1000.** Eine Excel-Spalte mit
  Tausendertrennung (`#,##0`, „Benutzerdefiniert") wurde korrekt als `1.234` importiert, dann
  aber in ein `<input type="number">` gelegt. Dessen `value` muss laut HTML eine „valid
  floating-point number" sein — der Browser las den Punkt als **Dezimaltrenner**, verstand
  1,234 und zeigte in deutscher Locale `1,234`. Aus 1234 wurde 1,234. Das Zahlenfeld ist jetzt
  ein `type="text"` mit `inputmode="decimal"` und zeigt exakt den Wert, den auch das Dokument
  enthält — inklusive Tausendertrennung.
- **Live-Vorschau und PDF zeigten einen Dezimalpunkt statt Komma.** Zwei Ursachen mit
  demselben Symptom: die Vorschau las `input.value` (bei `type="number"` immer
  punkt-kanonisch), und das PDF bekam den Punkt von Contaos rgxp `digit`, der beim
  Validieren `,` → `.` umschrieb. Der rgxp entfällt; Zahlen werden gegen das Format der
  Quellspalte validiert und normalisiert.
- **Regelvergleiche auf formatierten Spalten fielen still auf Stringvergleich zurück.**
  `is_numeric("3.000,00 €")` ist `false`, also verglich „größer als" per `strcmp` — dort ist
  `"500"` größer als `"3.000,00 €"`. Verglichen wird jetzt der geparste Zahlenwert.
- **Währungssymbol aus einem reinen Locale-Marker.** Eine Maske `[$-407]` (Locale ohne
  Währung) lieferte `"$"`, weil das `$` des Markers als Symbol gelesen wurde; jede Zahl der
  Spalte bekam so ein `$`. Ebenso wurde `[$€-de-DE]` als wissenschaftliches Format erkannt
  („de-**DE**" enthält `E-`).

### Geändert
- **Neues zentrales Excel-Modul (`src/Excel/`).** Formatierung war bisher an genau einer
  Stelle implementiert (beim Import) und wurde danach verworfen — jeder weitere Pfad
  (Formular, Antwort, Vorschau, PDF, Export, Regeln) musste raten. Jetzt gilt: **ein Wert
  wird genau einmal formatiert — beim Import bzw. beim Speichern einer Antwort — und danach
  überall unverändert angezeigt. Geparst wird nur, wenn gerechnet oder verglichen wird.**
  Die Klassen `FormatCodeParser`, `ValueFormatter`, `ValueParser` und `ColumnCompatibility`
  sind rein und unit-getestet (vorher: private, untestbare Methoden im Importer).
- **Antworten werden im Format ihrer Spalte gespeichert.** Bisher hielt dieselbe Spalte zwei
  Schreibweisen: importierte Zeilen `"3.000,00 €"`, vom Teilnehmer beantwortete `"3000.5"`.
  Eine Eingabe wird tolerant gelesen (`1234`, `1234,5`, `1.234,50`, auch `1234.5`) und im
  Spaltenformat abgelegt. Das Währungssymbol wird bei Eingabe und Prüfung ignoriert, bleibt
  aber auf dem gespeicherten und gedruckten Wert.
- **Export behält die Zeilenreihenfolge der Quelldatei.** Neue Spalte
  `tl_workflow_entry.sourceRow`; der Export sortierte bisher alphabetisch nach E-Mail. Die
  Migration trägt bestehende Einträge in ihrer ursprünglichen Importreihenfolge nach.
- **Beschreibungstext und Erklärungsfeld erben die Formatierung des Body.** Die Beschreibung
  war bewusst als Hinweis abgesetzt (`#555`, `.9em`) und wirkte dadurch wie eine Fußnote; sie
  ist verfasster Inhalt und wird jetzt wie der übrige Formulartext dargestellt.
- **„General"-Zellen mit Nachkommastellen werden lokalisiert** (`3000.5` → `3000,5`).
  Ganzzahlen bleiben unverändert (`3000` → `3000`). Deutsches Excel zeigt solche Zellen
  ebenfalls mit Komma, der Import entspricht damit der Quelldatei.

### Hinzugefügt
- **Kompatibilitätsprüfung beim Speichern eines Zahlenfeldes.** Die gewählte Spalte wird
  über alle importierten Zeilen geprüft; akzeptiert werden nur 0 oder 2 Nachkommastellen
  (Tausendertrennung optional, Währungssymbol egal). Prozent-, Datums-, Bruch- und
  wissenschaftliche Formate sowie gemischte Nachkommastellen werden mit Angabe von Zeile,
  Wert und Maske abgelehnt und auf den Feldtyp „Freitext" verwiesen. Summenzeilen (ohne
  E-Mail) bleiben außen vor — sie werden auch nicht importiert. Dieselbe Prüfung meldet sich
  im Workflow-Validator, falls die Quelldatei später ausgetauscht wird.

## [2.10.1] – 2026-07-17

### Geändert
- **„Bestätigung senden" in den Dialog „E-Mails senden" integriert.** Statt eines separaten
  Buttons ist die Bestätigung jetzt die dritte Option neben „Einladungen/Erinnerungen senden"
  — nach derselben statusabhängigen Logik (Automatisch / Manuelle Auswahl), aber **nur für
  Teilnehmer im höchsten Status**: Vor dem Endstatus liegen keine Daten für das PDF vor, daher
  werden solche Einträge übersprungen. Erzeugt PDF und Ergebnis-Mail erneut (idempotent) und
  dient zugleich dem Neuversand nach einem Bounce bzw. einer Adress-Korrektur.

## [2.10.0] – 2026-07-17

### Geändert
- **„Ausstehende Antworten" → „Offene Vorgänge" – die Liste zeigt jetzt alles, was noch
  nicht sauber abgeschlossen ist.** Ein Eintrag verschwindet erst, wenn er beantwortet **und**
  die Bestätigung (PDF + Ergebnis-Mail) erfolgreich erzeugt wurde **und** kein Versandfehler
  oder Bounce vorliegt. Zustellprobleme beantworteter Einträge (Versandfehler/Bounce der
  Ergebnis-Mail) erscheinen damit direkt zeilenweise in der Liste — mit Name/Abteilung zur
  Zuordnung, in der sortierbaren Spalte „Zustellung". Die früheren separaten Boxen
  „Versandfehler" und „Ungültige Adressen" entfallen (die Information steht jetzt in der
  Liste).

### Hinzugefügt
- **Ausfallsichere Ergebnis-Zustellung.** Schlägt beim Absenden des Formulars die
  PDF-Erzeugung oder der Versand der Bestätigungs-Mail fehl, geht die **Antwort nie verloren**
  und der Teilnehmer sieht **keine Fehlerseite** mehr. Der Fehlschlag wird am Eintrag
  festgehalten und in „Offene Vorgänge" als **„Ausstehend"** angezeigt (Grund im Tooltip) —
  kein stiller, aussperrender Zustand mehr. Ein neuer Cron holt offene Bestätigungen
  automatisch nach (Selbstheilung transienter Fehler bzw. nach einer Ursachenbehebung), und
  die neue Aktion **„Bestätigung neu senden"** erzeugt PDF und Ergebnis-Mail für markierte
  Einträge erneut — das dient zugleich dem Neuversand nach einem Bounce/einer Adress-Korrektur.

## [2.9.5] – 2026-07-17

### Geändert
- **Spalte „Zustellung" zeigt jetzt auch den Erfolgsfall.** Statt bei fehlerfreiem Versand
  leer zu bleiben, erscheint ein grünes Badge **„Versendet"**. Leer bleibt die Spalte nur
  noch, solange **noch keine Mail versucht** wurde. „Versendet" ist bewusst ehrlich
  beschriftet – *angenommen, nicht garantiert zugestellt*: Ein später eintreffender Bounce
  kippt die Zeile automatisch auf „Unzustellbar" (der Tooltip erklärt das).

### Dokumentation
- DEPLOYMENT §3a: Hinweis auf Contaos Mailer-DSN-Generator im Handbuch (baut die `MAILER_DSN`
  inkl. korrekter URL-Kodierung zusammen).

## [2.9.4] – 2026-07-17

### Hinzugefügt
- **Spalte „Zustellung" in der Liste „Ausstehende Antworten".** Zustellprobleme erscheinen
  jetzt direkt in dieser Liste (mit Name/Abteilung zur Zuordnung), als eigenes, **sortierbares**
  Badge – **ohne** den Workflow-Status zu überschreiben: **amber „Versandfehler"** (Transport,
  wiederholbar) bzw. **rot „Unzustellbar"** (harter Bounce, ungültige Adresse); der genaue
  Grund steht im Tooltip. Ein harter Bounce hat Vorrang vor einem Transportfehler. Die
  separaten Übersichtsboxen „Versandfehler" und „Ungültige Adressen" bleiben erhalten (u. a.
  für Bounces der Ergebnis-Mail an bereits beantwortete Einträge, die nicht in dieser Liste
  stehen).

## [2.9.3] – 2026-07-16

### Behoben
- **Bounce-Abruf gegen strikte IMAP-Server (All-Inkl/Dovecot).** Die Postfach-Abfrage
  sendete ohne explizites Suchkriterium ein leeres `UID SEARCH`, das solche Server mit
  „BAD … Missing search parameters" ablehnen (bei Mailpit u. a. funktionierte es zufällig).
  Die Abfrage setzt jetzt explizit `ALL` (`UID SEARCH ALL`).

## [2.9.2] – 2026-07-16

### Hinzugefügt
- **Bounce-Abruf schreibt ins Contao-System-Log** (Backend: System → System-Log), damit ohne
  CLI-Zugriff nachvollziehbar ist, was der Cron tut: pro Lauf eine Zeile „Bounce-Postfach
  geprüft (…): N Nachrichten, X hart markiert, …", je hart markierter Adresse ein Eintrag,
  sowie klare Fehlermeldungen (leere/ungültige DSN, IMAP-Fehler, nicht zuordenbarer Bounce).
  Das umgeht das prod-Log, das per `fingers_crossed` nur bei Fehlern schreibt.

### Behoben
- **IMAP-Verbindungs-Timeout (20 s)**, damit ein nicht erreichbares Bounce-Postfach den
  (Web-)Cron `/_contao/cron` nicht blockiert.

## [2.9.1] – 2026-07-16

### Hinzugefügt
- **CLI-Kommando `workflow:bounce:collect`** zum sofortigen Abrufen bzw. zur Diagnose des
  Bounce-Postfachs, ohne bis zu 15 Minuten auf den Cron zu warten. Es zeigt Schritt für
  Schritt, ob die DSN erkannt wird, ob die IMAP-Verbindung steht, wie viele Nachrichten im
  Postfach liegen und ob ein Bounce einem Eintrag zugeordnet werden kann. `--dry-run`
  verändert nichts, `--dsn=…` testet die Verbindung unabhängig von `.env.local`.

### Dokumentation
- DEPLOYMENT §3c: Hinweis, dass die Contao Managed Edition bei vorhandener `.env.local.php`
  die `.env.local` **nicht** direkt liest (Neukompilieren mit `dotenv:dump prod`, über den
  Contao-Manager setzen oder `.env.local.php` löschen), plus Prüf- und Testbefehle
  (`debug:dotenv`, `workflow:bounce:collect --dry-run`).

## [2.9.0] – 2026-07-16

Zuverlässiges Zustand-Tracking der versendeten Mails inklusive Erkennung asynchroner
Unzustellbarkeit (Bounces/DSN), die bisher unbemerkt blieb.

### Hinzugefügt
- **Warnung bei problematischer Absenderdomain.** Beim Bearbeiten eines Workflows warnt das
  Backend jetzt, wenn die Absenderadresse der zugeordneten E-Mail-Vorlagen eine
  Beispiel-/Platzhalterdomain nutzt (z. B. `example.com`), keinen MX-Eintrag im DNS hat
  (Tippfehler wie `.de` statt `.com`) oder nicht zur Website-Domain passt. Genau das ist die
  häufigste Ursache dafür, dass Mails unbemerkt unzustellbar sind und Bounces lautlos
  verschwinden, obwohl der Versand gesund aussieht. Der Demo-Workflow setzt außerdem keine
  tote `example.com`-Adresse mehr, sondern leitet den Absender aus der Domain der Website ab
  (oder lässt ihn leer, sodass die System-Absenderadresse greift).
- **Ungültige Adressen werden nicht erneut angeschrieben.** Eine per hartem Bounce als
  dauerhaft unzustellbar erkannte Adresse wird aus künftigen Einladungs- und
  Erinnerungsläufen ausgeschlossen – kein wiederholter Versand an tote Adressen (und damit
  kein weiterer Bounce/Reputationsschaden). Solche Einträge stehen im Dashboard in einer
  eigenen Box **„Ungültige Adressen"**, getrennt von den (wiederholbaren) Versandfehlern:
  ein Transportproblem löst sich ggf. von selbst, eine nicht existierende Adresse braucht
  einen Menschen. Wird die E-Mail-Adresse des Eintrags korrigiert, hebt sich die Sperre
  automatisch auf.
- **Bounce-Erkennung: unzustellbare Adressen aufspüren (opt-in per IMAP).** Ein „250 OK"
  beim Absenden bedeutet nur „angenommen", nicht „zugestellt" – lehnt der Empfänger-Server
  später ab (`550 User unknown`), kommt das als eigene Unzustellbarkeitsmeldung (Bounce/DSN)
  zurück, die bisher unbemerkt blieb. Ein neuer Cronjob (alle 15 Minuten) liest das
  konfigurierte Bounce-Postfach per IMAP aus, erkennt **harte** Bounces und markiert die
  betroffene Person im Dashboard als „Unzustellbar (Bounce)". Aktivierung über
  `WORKFLOW_BOUNCE_IMAP_DSN` in `.env.local` (siehe DEPLOYMENT.md, Abschnitt 3c); ohne
  Konfiguration bleibt die Funktion aus. Temporäre Probleme (Greylisting, Postfach voll)
  werden nur protokolliert, nicht als Fehler gewertet.
- **Dashboard-Warnung „Versand hängt in der Queue".** Sind E-Mails seit über 15 Minuten zum
  Versand eingereiht, ohne dass ein Ergebnis vorliegt, zeigt die Workflow-Übersicht jetzt
  oben eine deutliche Warnung – typischerweise das Zeichen, dass der Cron bzw. der Worker
  nicht läuft. Vorher war dieser Zustand unsichtbar (Eintrag blieb „ausstehend", ohne Fehler).

### Geändert
- **Interne Versandprotokollierung in eigene Tabelle `tl_workflow_send`.** Der Zustand jeder
  einzeln versendeten Mail (Einladung / Erinnerung / Ergebnis) wird jetzt dauerhaft pro
  Notification-Center-Parcel-ID protokolliert – eingereiht → versendet → fehlgeschlagen –
  statt in einem einzigen Slot je Eintrag (`sendParcelId` / `sendKind`), der bei jedem neuen
  Versand überschrieben und nach dem Ergebnis gelöscht wurde. Das ist die Grundlage für die
  kommende Bounce-Erkennung und die Queue-Überwachung. Eine Datenbank-Migration legt die
  Tabelle an, übernimmt noch laufende Zuordnungen und entfernt die alten Spalten;
  `sendError` / `sendErrorAt` bleiben als Anzeigefelder am Eintrag erhalten. Ein täglicher
  Cron räumt abgeschlossene Protokollzeilen (versendet / fehlgeschlagen / unzustellbar) nach
  90 Tagen auf; noch eingereihte Zeilen bleiben erhalten (Signal für einen hängenden Versand).

### Behoben
- **Doppelte Einladung nach fehlgeschlagenem Erstversand.** Schlug der erste
  SMTP-Zustellversuch fehl (z. B. am 3-Verbindungen-Limit von all-inkl) und gelang erst
  der automatische Wiederholversuch von Symfony Messenger, blieb der Eintrag fälschlich
  auf „importiert" mit Versandfehler stehen – der nächste „Einladungen senden"-Lauf
  verschickte dann eine **zweite Einladung**. Die Zuordnung zwischen E-Mail und Eintrag
  wird jetzt erst bei **erfolgreicher** Zustellung aufgelöst, sodass ein Wiederholversuch
  weiterhin dem richtigen Eintrag zugeordnet wird.

### Dokumentation
- **DEPLOYMENT: Worker-Parallelität auf all-inkl begrenzen (autoscale).** Neuer Abschnitt 2c
  erläutert, wann Contaos autoscale-Worker greifen (CLI-Cron bzw. `contao:supervise-workers`,
  Default bis zu 10 parallele Prozesse) und wann stattdessen der sequenzielle Web-Worker
  drainiert (URL-Cron `/_contao/cron`), und empfiehlt für all-inkl (max. 3 gleichzeitige
  SMTP-Verbindungen) das Abschalten von autoscale (`autoscale: { enabled: false }`), sodass
  genau ein Worker läuft.

## [2.8.0] – 2026-07-11

### Hinzugefügt
- **Contao-Insert-Tags (`{{…}}`) im Platzhalter-Assistenten – ausgelöst durch „{".**
  Im Feld **PDF-Dateiname** (und ebenso in Überschrift, Einleitung, Dokument-Texten und
  Dokument-Textbausteinen) blendet die Eingabe von `{` jetzt eine Auswahl gebräuchlicher
  Contao-Insert-Tags ein – vor allem Datums-Tags wie `{{date::Y}}` (Jahr), `{{date::d.m.Y}}`
  oder `{{date::Y-m-d}}`. Ein PDF-Dateiname wie `Verzicht_##data_name##_{{date::Y}}` ergibt
  damit z. B. `Verzicht_Mustermann_2026.pdf`. Insert-Tags wurden in diesen Feldern schon
  immer aufgelöst; neu ist die **Vorschlagsliste** dafür. Beliebige weitere Insert-Tags
  lassen sich weiterhin von Hand eintippen.
- **Textauszeichnung (fett / kursiv / unterstrichen) in Dokument-Texten und Textbausteinen.**
  In den Feldern **Dokument-Text** (Regel), **Dokument-Text (Textbaustein)** je Formularfeld
  und **Dokument-Text** je Option – sowie im **Einleitungstext** und in der
  **Formularfeld-Beschreibung** – lässt sich Text jetzt mit einer schlanken, an die
  Platzhalter-Syntax angelehnten Auszeichnung formatieren:
  - `[b]…[/b]` → **fett**
  - `[i]…[/i]` → *kursiv*
  - `[u]…[/u]` → <u>unterstrichen</u>

  Die Marker fügen sich neben `##Platzhalter##` und `{{Insert-Tags}}` ein und werden **erst
  nach dem Escapen** in eine feste Whitelist (`<strong>`/`<em>`/`<u>`) umgewandelt – beliebiges
  HTML ist damit ausgeschlossen. Wirksam:
  - im **PDF** (echtes Fett/Kursiv/Unterstrichen),
  - im **Formular-Hinweis „So erscheint dies im Dokument"** (zeigt die Formatierung live, wie im PDF),
  - in **E-Mails** werden die Marker zu sauberem Text **entfernt** (kein sichtbares `[b]`).

  Nur **Admin-Texte** werden formatiert; Werte aus der Quelltabelle (`##data_*##`) bleiben
  unverändert – eine in importierten Daten enthaltene `[b]`-Zeichenfolge wird nicht als
  Formatierung interpretiert. Die **Überschrift** unterstützt bewusst keine Auszeichnung.

### Behoben
- **`##…##`-Platzhalter im Beschreibungsfeld eines Formularfelds funktionierten nicht.**
  Die optionale **Beschreibung** eines Formularfelds (Hinweis unter der Beschriftung im
  Formular) gab Platzhalter wie `##data_hoehe_der_uelp##` oder `##system_year##` bisher
  **wörtlich** aus. Sie werden jetzt – wie in Überschrift und Einleitung – aufgelöst
  (inklusive Contao-Insert-Tags), sodass die Beschreibung z. B. den aktuellen Wert aus der
  Quelltabelle anzeigen kann. Das Feld erhält zudem den Platzhalter-Assistenten.
- **Währungs-/Zahlenspalten aus Excel wurden im US-Format übernommen.** Eine als
  **„Währung"** formatierte Excel-Zelle (Wert `3000`) wurde bisher als `3,000.00 €`
  (US-Format) importiert. Solche Zahlen-/Währungszellen werden jetzt **deutsch lokalisiert**
  übernommen: `3.000,00 €` (Tausenderpunkt, Dezimalkomma, Währungssymbol aus der Zelle).
  Nachkommastellen und Tausendertrennung folgen dem Zellformat; das Symbol (`€`, `$`, …)
  wird aus dem Zahlenformat übernommen. Zellen im Format **„Standard"** bleiben unverändert
  (`3000` bleibt `3000`); Datums-, Prozent-, Bruch- und wissenschaftliche Formate sind nicht
  betroffen.

## [2.7.0] – 2026-07-08

### Hinzugefügt
- **PDF-Dokumente werden beim Löschen eines Workflows mitgelöscht.** Bisher blieben die
  erzeugten PDFs unter `var/workflow_pdfs/<id>/` nach dem Löschen eines Workflows als
  „verwaiste" Dateien liegen. Jetzt entfernt das Löschen eines Workflows auch dessen
  gesamtes PDF-Verzeichnis. Der **Lösch-Bestätigungsdialog** weist zusätzlich auf die
  Anzahl der dabei unwiderruflich gelöschten PDF-Dokumente hin.
- **Rot-Markierung ungültiger Verknüpfungen (Formularseite, Briefpapier, Benachrichtigungen).**
  Zeigt eine dieser Verknüpfungen auf ein **nicht (mehr) vorhandenes** Element – weil es
  gelöscht wurde („Unbekannte Option: <id>") **oder** weil ein Import es auf dieser
  Installation nicht zuordnen konnte – wird das betroffene Feld **rot markiert** und oben
  im Workflow ein Hinweis eingeblendet (analog zu unbekannten Datenfeldern). Nach
  korrekter Zuordnung und Speichern verschwindet die Markierung.
- **Gelöschtes Briefpapier ⇒ „nicht ausführbar".** Ein Workflow, dessen zugeordnetes
  Briefpapier gelöscht wurde, wird jetzt als **nicht ausführbar** gemeldet (Import/Versand/
  Export gesperrt) – vorher wäre still das Standard-Layout ohne Briefpapier-Variablen
  verwendet worden. Ebenso blockiert eine **gelöschte** E-Mail-Benachrichtigung den Versand
  (statt erst beim tatsächlichen Senden zu scheitern).

### Geändert
- **Export-/Import-Format der Workflow-Konfiguration auf `v5`** angehoben: Die
  **Formularseite** (ID **und** Name), das **Briefpapier** (ID) und die
  **E-Mail-Benachrichtigungen** (ID) werden jetzt exportiert. Beim Import werden diese
  Verknüpfungen **nur** wiederhergestellt, wenn **ID und Name** des Elements auf der
  Zielinstallation übereinstimmen (verhindert das versehentliche Verknüpfen mit einem
  fremden Element derselben ID). Dadurch behält ein Re-Import auf **derselben**
  Installation seine Formularseite, sein Briefpapier und seine Benachrichtigungen –
  bisher gingen diese Zuordnungen dabei verloren. Ältere Konfigurationen (v1–v4) lassen
  sich weiterhin importieren (ohne diese Verknüpfungen, die dann rot markiert werden).

### Behoben
- **Formularseite nach Import leer.** Die Formularseite war bisher gar nicht Teil des
  Exports und wurde beim Import fest auf „keine" gesetzt – nach einem Re-Import waren alle
  Formularseiten leer. Sie wird jetzt exportiert und (bei ID-/Namensgleichheit)
  wiederhergestellt.
- **Briefpapier/Benachrichtigungen nach Re-Import nicht verknüpft.** Existierte auf der
  Zielinstallation bereits ein Briefpapier bzw. eine E-Mail-Vorlage mit demselben Namen
  (z. B. weil nur die Workflows, nicht aber diese gemeinsam genutzten Elemente gelöscht
  wurden), wurde die Zuordnung übersprungen und der Workflow blieb **ohne** Briefpapier/
  Benachrichtigung. Jetzt wird bei ID-/Namensgleichheit auf das vorhandene Element
  verknüpft.
- **Edit-Mask-Hinweise erschienen über der Workflow-Liste.** Die Hinweise der Bearbeiten-Seite
  (uneindeutige Spalten/Platzhalter, unvollständige Konfiguration, ungültige Verknüpfungen,
  Datenschutz-Hinweis) konnten gelegentlich über der **Workflow-Liste** auftauchen (unklar,
  auf welchen Workflow bezogen). Ursache waren Backend-AJAX-Requests der Picker
  (Formularseite/Quelldatei) mit `act=edit`, die die Meldung setzten, aber nicht anzeigten;
  AJAX-Sub-Requests sind jetzt ausgeschlossen.
- **„Noch nicht ausführbar"-Meldung blieb nach dem Speichern stehen.** Nach dem Beheben eines
  Problems (z. B. gültiges Briefpapier zugeordnet) verschwindet die Meldung jetzt **sofort**
  statt erst nach einem Seiten-Refresh (die `onsubmit`-Prüfung liest den Datensatz jetzt frisch).

## [2.6.0] – 2026-07-08

### Hinzugefügt
- **Contao-Insert-Tags `{{…}}`** werden jetzt in **allen Textfeldern** eines Workflows
  aufgelöst (Überschrift, Einleitungstext, Dokument-Texte, Textbausteine, PDF-Dateiname) –
  z. B. `{{date::d.m.Y}}`. Ausgewertet werden nur die im Backend gepflegten Vorlagen, **nie**
  die eingegebenen Antwortdaten (kein Einschleusen von Insert-Tags über das Formular).
- **Neuer Formularfeld-Typ „Erklärung"**: ein reiner Textabsatz (kein Eingabefeld). Der Text
  steht im *Dokument-Text* und erscheint als Fließtext im Formular **und** im Dokument – so
  lässt sich flexibel zusätzlicher Text einpflegen. Im Dokument erscheint er dort, wo ein
  `##text_*##`-Platzhalter im Dokument-Text steht (siehe `##text_all##`).
- **Feld „Beschreibung"** je Formularfeld: ein Hinweistext, der **nur im Formular** unter der
  Überschrift angezeigt wird (nur wenn nicht leer) und **nie** im Dokument erscheint.
- **Option „Textbaustein im Formular anzeigen"** je Formularfeld: blendet die Vorschau des
  Dokument-Texts („So erscheint dies im Dokument") im Formular bei Bedarf aus (Standard: an).
- **„Speichern und schließen"** in den Dialogen der **Formularfelder** und **Dokument-Texte**:
  Der eingebettete dcaWizard-Dialog bot bisher nur „Speichern" und musste per „×" geschlossen
  werden; jetzt gibt es einen Knopf, der speichert und den Dialog direkt schließt.
- **Spalte „Abteilung"** in der Liste „Ausstehende Antworten" der Übersicht – wie Name/Vorname
  nur, wenn die Quelldatei eine passende Spalte enthält.

### Geändert
- **Umbenennungen (nur Beschriftungen, keine internen IDs/Tabellen):**
  „Antwortfeld"→**„Formularfeld"**, „Beschriftung"→**„Überschrift"**, „PDF-Inhalt"→
  **„Dokument-Einstellungen"** (Abschnitt) bzw. **„Dokument-Inhalt"** (Feld),
  „PDF-Regeln"/„Brieftext"→**„Dokument-Texte"**/**„Dokument-Text"** (EN: form field / heading /
  document settings / document content / document texts).
- **Export-/Import-Format der Workflow-Konfiguration auf `v4`** angehoben (enthält jetzt
  `description`, `showStatementInForm` und den Typ `explanation`). Ältere Konfigurationen
  (v1–v3) lassen sich weiterhin importieren (Textbaustein-Vorschau wird dabei standardmäßig
  aktiviert).

### Behoben
- **Optionen-Wizard der Auswahlfelder (Dropdown/Radio/Checkboxen):** Die Spalte
  „Dokument-Text" ist jetzt **mehrzeilig** und bekommt den Großteil der Dialogbreite,
  während „Wert" und „Options-Text" schmaler werden – so lassen sich auch längere
  Dokument-Texte bequem eingeben.
- **Nachname fehlte in der Unterschriftszeile.** Der Name wurde fest aus den Spalten
  `Vorname` + `Name` gebildet – hieß die Nachnamen-Spalte anders (z. B. `Nachname`,
  `Familienname`, `Surname`), fehlte der Nachname. Ein neuer, gemeinsam genutzter
  `PersonNameResolver` erkennt Vor- und Nachnamen-Spalte jetzt anhand gängiger
  Schreibweisen (dieselbe Logik wie die Namensspalten der Übersicht).
- **Datum aus der Quelldatei falsch formatiert** (z. B. Geburtsdatum als `12/17/1955`).
  Excel-Datumszellen wurden im (Datei-)Format ausgegeben; jetzt werden echte Datumswerte beim
  Import einheitlich als `d.m.Y` (bzw. `d.m.Y H:i` mit Uhrzeit) gespeichert. Reine Uhrzeit-Zellen
  bleiben unverändert. **Hinweis:** bereits beantwortete Einträge behalten ihren gespeicherten
  Wert (schreibgeschützte Speicherfelder werden beim Re-Import nicht überschrieben).
- **PDF-Schriftart der Unterschriftszeile:** „Ort, Datum" und „Unterschrift …" wurden im finalen
  PDF teils in einer anderen (Serifen-)Schrift als der übrige Text gesetzt. Ursache: die
  eingebaute Standardschrift von mPDF ist eine Serifenschrift, auf die verschachtelte
  Tabellenzellen zurückfielen. Behoben durch eine serifenlose Standardschrift und explizite
  Schriftfamilie im Kopf/Fuß und in der Unterschriftszeile.
- **PDF-Schriftgröße der Unterschriftszeile:** Im finalen PDF wurde die gesamte
  Unterschrifts-Tabelle (Ort/Datum + Unterschriftstext) kleiner als der Fließtext gesetzt, in
  der Vorschau nicht. Ursache: das Unterschriftsbild hatte keine feste Breite, wodurch mPDF die
  Tabelle als zu breit einstufte und ihre Schrift verkleinerte. Behoben durch eine feste
  Bildbreite (wie beim Logo) und eine explizite Schriftgröße = Fließtextgröße.

## [2.5.1] – 2026-07-03

### Behoben
- **Absturz bei `contao:migrate` auf Upgrade-Installationen behoben** („Unknown
  column"). Die Token-Umbenennungs-Migrationen (`RenameValueTokenMigration`,
  `RenameVarStmtTokensMigration`) prüften nur die Existenz der Tabelle, fragten dann
  aber Spalten (`pdfStatement`, `introText`) ab, die der Schema-Diff erst danach
  anlegt. Existierte die Tabelle bereits ohne diese Spalten – beim Upgrade einer
  älteren Version oder direkt nach dem Trainer→Workflow-Tabellen-Rename – brach die
  Migration mit „Unknown column" ab. Beide Migrationen prüfen jetzt die Spalten und
  überspringen sauber, wenn sie fehlen. Neuinstallationen waren nicht betroffen.

## [2.5.0] – 2026-07-03

### Hinzugefügt
- **Eingebaute `##system_*##`-Platzhalter** für Datum und Uhrzeit, die zur Laufzeit
  berechnet werden und **ohne jede Konfiguration überall** verfügbar sind (PDF-Text,
  Überschrift, Einleitung, Dateiname, E-Mails, Dokument-Texte): `##system_year##`
  (Jahr), `##system_month##` (Monat), `##system_today##` (Datum), `##system_time##`
  (Uhrzeit) und `##system_datetime##` (Datum + Uhrzeit). Sie erscheinen in der
  Platzhalter-Hilfe und werden im Bearbeiten-Dialog auf unbekannte Schreibweisen geprüft.

### Entfernt
- Die Briefpapier-Variable **`Jahr`** wird nicht mehr als PDF-Variable vorgeschlagen –
  das aktuelle Jahr liefert jetzt der eingebaute `##system_year##`. **`Verein`** und
  **`Ort`** bleiben unverändert als eigene Briefpapier-Variablen erhalten. Bereits
  gespeicherte `Jahr`-Werte bleiben gültig (`##letterhead_jahr##` löst weiterhin auf) –
  es ist **keine Migration nötig**. Die mitgelieferte Demo nutzt jetzt `##system_year##`.

### Dokumentation
- Klargestellt, dass Workflow-Mails **alle** Platzhalter auflösen (`##data_*##`,
  `##letterhead_*##`, `##system_*##`, `##text_*##` / `##text_all##`), obwohl die
  `##`-Vorschlagsliste des Notification Center nur eine Teilmenge zeigt
  (`##data_*##`, `##email##`, `##link##`, `##workflow_title##`, `##attachment##`) –
  die übrigen werden nicht vorgeschlagen, beim Versand aber ersetzt (README,
  `docs/ANLEITUNG.md`, Kommentar in `WorkflowNotificationType`).

### Geändert (kein Speichern ohne „Speichern"-Klick)
- **Kein automatisches Speichern mehr beim Umschalten von Auswahlfeldern.** Mehrere
  Felder lösten bisher per `submitOnChange`/`toggleSubpalette` ein sofortiges Speichern
  des gesamten Datensatzes aus, ohne dass „Speichern" geklickt wurde. Erste Umsetzung:
  - **PDF-Inhalt** (`pdfBodyType`) und **Unterschrift verlangen** (`requireSignature`) im
    Workflow sowie **Standardtext** (`isDefault`) in den PDF-Regeln blenden ihre
    abhängigen Felder jetzt **clientseitig** ein/aus (neues `workflow-field-toggle.js`),
    statt das Formular abzuschicken. Es wird nichts gespeichert, bis „Speichern" geklickt
    wird. Fällt das Skript aus, sind alle Felder sichtbar (gutartiger Rückfall, kein
    Auto-Speichern). Ein Standardtext ohne Bedingungen wird beim Speichern bereinigt.
  - **Body-Vorlage** (`pdfBodyTemplate`): überflüssiges `submitOnChange` entfernt.
  - **Layout-Vorlage des Briefpapiers** (`masterTemplate`): kein `submitOnChange`
    mehr.
  - **PDF-Variablen** (`pdfData`): neuer, vorlagen-geführter Editor (eigenes
    Backend-Widget statt MultiColumnWizard). Die zur gewählten Layout-Vorlage
    deklarierten Variablen erscheinen **sofort** als beschriftete Wertfelder und
    werden beim Wechsel der Vorlage **clientseitig** neu aufgebaut – ohne dass
    zwischendurch gespeichert werden muss. Zusätzliche eigene Variablen lassen sich
    in einem eigenen Bereich ergänzen. Speicherformat (Schlüssel/Wert-Paare) und
    Versionierung unverändert; PDF-Erzeugung, `##letterhead_*##`-Platzhalter und
    Import/Export bleiben kompatibel.
  - **Antwortfeld-Typ** (`type`): kein `submitOnChange` mehr. Die typabhängigen
    Felder (Dokument-Text, Optionen, „aus Formular ausblenden" bzw. die
    Pflicht-/Vorbeleg-/Schreibgeschützt-Optionen) werden clientseitig ein-/ausgeblendet
    – ein Typwechsel speichert den Antwortfeld-Datensatz also nicht mehr sofort.
  - **Antwortfeld-Reihenfolge** (Drag & Drop): schreibt nicht mehr sofort, sondern
    wird erst beim Speichern des Workflows übernommen; die Reihenfolge bleibt über
    das Hinzufügen/Bearbeiten einzelner Felder hinweg erhalten (verstecktes Feld
    `questionOrder` + erneutes Anwenden nach dcaWizard-Refresh). Die Reihenfolge ist
    nun ein **versioniertes** Workflow-Feld: Änderungen erscheinen in der Versions-
    historie und werden beim Wiederherstellen einer Version mit zurückgesetzt
    (neue Spalte `tl_workflow.questionOrder`).
  - Die betroffenen Hilfetexte („… wird sofort gespeichert") wurden angepasst.

### Behoben
- **Verwaiste „Regel/Antwortfeld"-Zeilen.** Wurde der „Neue Regel"- bzw.
  „Neues Antwortfeld"-Dialog ohne Speichern geschlossen, blieb eine leere Zeile
  (z. B. „Regel 82") in der Liste stehen und musste manuell gelöscht werden:
  Contao legt bei „Neu" sofort einen leeren Datensatz an, und die eingebettete
  Liste durchläuft Contaos eigene Aufräumroutine nie. Solche nie gespeicherten
  Zeilen (`tstamp = 0`) werden jetzt nicht mehr angezeigt und beim Öffnen des
  Workflows aus der Datenbank entfernt.

### Hinzugefügt
- **PDF- und Formular-Vorschau in der Workflow-Bearbeiten-Maske.** Im Abschnitt
  *PDF-Inhalt* öffnet ein Button das generierte **PDF mit Beispieldaten** in einem neuen
  Tab; im Formular-Abschnitt zeigt ein Button eine **Vorschau des Formulars** (Absenden
  deaktiviert). Die Beispieldaten stammen vom jüngsten echten Eintrag, sonst synthetisch aus
  den Quellspalten – alle Antwortfelder werden mit repräsentativen Werten gefüllt, damit
  Dokument und Formular vollständig erscheinen. Beide Vorschauen sind schreibgeschützt
  (kein Speichern, kein Versand). Die Formular-Ansicht nutzt denselben Renderer wie das
  echte Frontend-Formular (neuer `WorkflowFormView`), ist also feldgenau identisch.

### Geändert
- **Platzhalter-Grammatik vereinheitlicht.** Ein `##…##` ist jetzt immer entweder ein
  Präfix-Token (`##data_<slug>##`, `##letterhead_<slug>##`, `##text_<slug>##` / `##text_all##`),
  ein festes Token (`##workflow_title##`; Notification-Center unverändert: `##email##`,
  `##link##`, `##attachment##`) oder der feldlokale Slot **`##answer##`** im Dokument-Text
  einer Frage.
- **Anwenderfreundlichere Namespaces.** Die Platzhalter-Präfixe wurden an die UI-Begriffe
  angeglichen: `##var_*##` → **`##letterhead_*##`** (Briefpapier-Variablen) und
  `##stmt_*##` / `##stmt_all##` → **`##text_*##`** / **`##text_all##`** (Dokument-Texte /
  Textbausteine). Der feldlokale Slot `##value##` heißt **`##answer##`**. Bestehende
  Konfigurationen (Brieftexte, Überschrift/Einleitung, Frage- und Options-Texte sowie die
  zugehörigen Notification-Center-Mailtexte) werden per Migration automatisch umbenannt.
- Doppelte Map-Erzeugung und die deutsche Transliteration in `PlaceholderResolver`
  zusammengeführt; `PdfGenerator` nutzt dieselbe Transliteration.
- **Eindeutige Platzhalter-Slugs.** Ergeben mehrere Quellspalten denselben Slug (z. B.
  „Stundenlohn" und „Stundenlohn:" → `##data_stundenlohn##`), ist nur noch die **erste**
  Spalte über ihren Platzhalter erreichbar; die übrigen werden ignoriert (ihre Werte werden
  weiterhin importiert und exportiert, nur nicht per Platzhalter adressierbar). Eine Warnung mit
  den betroffenen Spalten erscheint **beim Import** (Backend-Meldung bzw. CLI) und proaktiv
  **auf der Workflow-Bearbeiten-Seite** – zum Auflösen die Spalten in der Quelldatei eindeutiger
  benennen.

### Entfernt
- **Rohspaltennamen-Aliase** im PDF (z. B. `##Davon Spende##`, `##Verein##`, `##Jahr##`) –
  ersatzlos. Stattdessen die kanonische Form `##data_<slug>##` bzw. `##letterhead_<slug>##`
  verwenden (vom Platzhalter-Assistenten ohnehin als einzige Form vorgeschlagen). In Mails
  galten die Aliase nie, ausgelieferte Konfigurationen/Presets/Demo nutzen sie nicht.

## [2.4.0] – 2026-06-12

### Hinzugefügt
- **Formular/PDF-Parität über Dokument-Texte (Textbausteine).** Formular und PDF nutzen
  dieselben Texte: Auswahl-Optionen tragen einen optionalen **Dokument-Text** (leer = der
  sichtbare Options-Text gilt wörtlich), Wert-Felder (Freitext, Zahl, Datum, Aktuelle Zeit)
  ein Satz-Template mit `##value##` (leer = „Beschriftung: Wert"). Das Formular zeigt den
  Text live unter dem Feld („So erscheint dies im Dokument"). Neue Platzhalter
  `##stmt_<speicherfeld>##` und `##stmt_all##` (alle Felder in Formular-Reihenfolge; Felder
  mit eigenem Dokument-Text beginnen als eigener Absatz) – identisch in PDF-Texten,
  Notification-Center-Mails und Body-Vorlagen (`$this->statements`). Der Dokument-Body wird
  zentral im neuen `DocumentBodyComposer` gerendert.
- **Überschrift & Einleitungstext für Formular und PDF.** Die Überschrift (bisher nur PDF)
  erscheint jetzt auch oben im Formular; dazu ein optionaler **Einleitungstext** nach der
  Überschrift in beiden. Beide stehen im neuen Workflow-Abschnitt **„Inhalt (Formular & PDF)"**;
  Body-Vorlagen erhalten sie als `$this->heading`/`$this->intro`.
- **Antwortfeld-Optionen „Mit Wert aus den Daten vorbelegen" und „Schreibgeschützt".**
  Vorbelegen füllt das editierbare Feld mit dem gespeicherten Wert (Outputfeld = Inputfeld;
  unpassende Werte bei Auswahlfeldern bleiben leer, das Backend warnt). Schreibgeschützt
  zeigt den Wert nur an (jeder Typ; ersetzt die bisherigen Workflow-„Anzeige-Felder" –
  eine Migration wandelt sie automatisch in schreibgeschützte Antwortfelder um).
- **Neuer Antwortfeld-Typ „Zahl"** (Zahleneingabe inkl. Dezimalwerte, automatische
  Komma-Konvertierung).
- **Antwortfelder per Drag & Drop sortieren** – direkt in der eingebetteten Liste der
  Bearbeitungsmaske (Griff links, sofort gespeichert).
- Neuer CLI-Befehl `workflow:demo:restore` (entspricht dem Wiederherstellen-Button).

### Geändert
- Formular-Validierung läuft über Contaos Form-Widgets (Pflichtfelder, Options-Whitelist,
  lokalisierte Fehlermeldungen).
- Einheitliches Formular-Markup: alle Felder als `.tw-field.tw-field--<art>` mit
  Label/Legende über dem Feld, randlos (die bisherige Fieldset-Box-Optik entfällt).
- Konfigurationsformat **v3** (Dokument-Texte, Vorbelegen/Schreibgeschützt, Zahl,
  Einleitungstext); ältere v1/v2-Dateien bleiben importierbar (`inputFields` bzw. der
  kurzlebige Typ „Anzeige" werden beim Import umgewandelt).
- Demo-Workflow zeigt die neuen Funktionen end-to-end (schreibgeschützte Felder,
  vorbelegtes Feld, Options-Dokument-Texte, Brieftexte aus `##stmt_all##`).

### Entfernt
- Workflow-Einstellung **„Anzeige-Felder (Input)"** (`tl_workflow.inputFields`) – ersetzt
  durch schreibgeschützte Antwortfelder (automatische Migration).

## [2.3.11] – 2026-06-06

### Geändert
- **„Konfiguration herunterladen" jetzt in der Workflow-Liste.** Der Konfigurations-Export ist als
  eigene Operation (Symbol) in der Liste unter „Workflows" (workflow_manage) verfügbar – neben
  „Bearbeiten", „Einträge", „Kopieren" usw. – und wurde dafür aus der Übersicht entfernt.
- **„Bearbeiten"-Button in der Workflow-Übersicht.** Jeder Workflow in der Übersicht hat jetzt einen
  „Bearbeiten"-Button, der direkt in die Bearbeiten-Ansicht (workflow_manage) dieses Workflows führt.
- Die Zugriffsprüfung der Workflow-Aktionsrouten akzeptiert nun das Übersichts- **oder** das
  Verwalten-Modul (damit der Export auch aus der Liste heraus funktioniert).

## [2.3.10] – 2026-06-05

### Behoben
- **Status-Aktualisierung nach Mailversand funktionierte bei asynchronem Versand nicht.** Der in
  2.3.6 eingeführte Ansatz stempelte einen Korrelations-Header über das `MessageEvent` auf die
  Mail – Symfony verwirft beim Einreihen in die Queue aber genau diese Änderungen (es stellt
  bewusst die *originale* Nachricht zu), sodass der Header beim echten Versand im Worker fehlte
  und der Teilnehmer-Status auf „0" stehen blieb, obwohl die Mail erfolgreich versendet wurde.
  Die Zuordnung läuft jetzt über die **Parcel-ID des Notification Centers** und dessen
  `AsynchronousReceiptEvent`: Beim Versand wird die Parcel-ID am Eintrag vermerkt und beim
  tatsächlichen (auch asynchronen) Zustellergebnis wieder aufgelöst. Erfolg → Einladung wechselt
  auf „eingeladen"; Fehler → Status bleibt unverändert und wird im Dashboard als „Versandfehler"
  angezeigt. Gilt für Einladung, Erinnerung und Ergebnis-Mail. (Neue Spalten
  `tl_workflow_entry.sendParcelId` / `sendKind` – Datenbank-Migration erforderlich.)

## [2.3.9] – 2026-06-05

### Geändert
- **Formularseite „Workflow-Formular" ist auf `noindex,nofollow` gesetzt.** Die Seite wird nur
  über individuelle Token-Links erreicht und soll nicht von Suchmaschinen indexiert werden. Das
  Robots-Tag wird beim Anlegen/Heilen der Seite gesetzt; eine Migration setzt es zudem auf einer
  bereits vorhandenen Formularseite (neuer und alter Alias).

## [2.3.8] – 2026-06-05

### Behoben
- **„Ausstehende Antworten" zeigte keinen Nachnamen.** Die Übersicht suchte die Namensspalte fest
  unter „Name" – Quelldaten mit „Nachname" (u. a. der Demo) blieben so ohne Namensspalte. Vor-/
  Nachname (und als Fallback die E-Mail) werden jetzt **automatisch anhand gängiger Feldnamen
  erkannt**, normalisiert (Groß-/Kleinschreibung, Leer-/Sonderzeichen) – z. B. Vorname/Rufname/
  First Name/Given Name, Nachname/Familienname/Surname/Last Name/Family Name (generisches „Name"
  als Fallback) sowie E-Mail/Mail/E-Mail-Adresse. Die Spalte „Status" bleibt unverändert (vom
  Plugin vorgegeben).

## [2.3.7] – 2026-06-05

### Geändert
- **Formularseite ist jetzt allgemein nutzbar statt demo-spezifisch.** Die bei der Installation
  angelegte Seite heißt nun „Workflow-Formular" mit Alias **`/workflow-formular`** (vorher
  „… (Demo)" / `workflow-formular-demo`). Da das Formularmodul Eintrag *und* Workflow allein aus
  dem Token in der URL auflöst, kann **eine einzige Seite alle Workflows bedienen** – neue
  Workflows verweisen einfach mit ihrer „Formularseite" darauf. Theme/Modul entsprechend generisch
  benannt. Eine Migration benennt eine vorhandene Demo-Seite (inkl. Artikel/Modul/Theme) in place
  um – die Seiten-ID bleibt erhalten, nur die URL wird zu `/workflow-formular/<token>` (bereits
  versendete Demo-Links auf die alte Adresse verlieren dadurch ihre Gültigkeit).

## [2.3.6] – 2026-06-05

### Geändert
- **Workflow-Schritt wird erst nach dem tatsächlichen Mail-Versand weitergesetzt.** Bisher
  wurde der Status sofort beim Klick hochgezählt – auch wenn die Mail (oft asynchron über die
  Queue) danach am SMTP-Server scheiterte; die grüne Meldung bestätigte fälschlich „versendet".
  Jetzt wird der Status ereignisgesteuert aus dem echten Sendeergebnis aktualisiert
  (`SentMessageEvent`/`FailedMessageEvent`): eine Einladung wechselt erst nach erfolgreicher
  Zustellung auf „eingeladen", ein **Fehlversand lässt den Schritt unverändert**. Gilt für
  Einladung, Erinnerung und Ergebnis-Mail. Die Bestätigungsmeldung lautet entsprechend
  „… zum Versand eingereiht".

### Hinzugefügt
- **Versandfehler im Dashboard.** Fehlgeschlagene Zustellungen werden pro Workflow in einer
  eigenen „Versandfehler"-Box (Empfänger + Fehlertext) sowie als Markierung an der betroffenen
  Zeile angezeigt. Ein späterer erfolgreicher Versand räumt die Markierung automatisch wieder ab.
  (Korrelation Mail↔Zeile über gestempelte Mail-Header; neue Spalten `tl_workflow_entry.sendError`
  / `sendErrorAt` – Datenbank-Migration erforderlich.)

## [2.3.5] – 2026-06-05

### Behoben
- **Hinweis auf übersprungene Elemente wurde nicht angezeigt.** Die Import-Meldungen (Erfolg sowie
  „… wegen Namenskonflikt übersprungen") wurden zwar gesetzt, aber in der Workflow-Übersicht nicht
  ausgegeben – ein eigenes Backend-Modul wird (anders als DC-Listen/Masken) nicht automatisch mit
  der Meldungsausgabe umrahmt. Das Dashboard rendert die Flash-Meldungen jetzt selbst.

## [2.3.4] – 2026-06-05

### Behoben
- **Import legte Duplikate mit gleichem Namen an.** Beim „Workflow-Konfiguration importieren"
  wurden Briefpapier und E-Mail-Vorlagen auch dann (doppelt) angelegt, wenn bereits gleichnamige
  Elemente existierten. Jetzt wird **nichts überschrieben und nichts unter einem bereits
  vergebenen Namen angelegt**: Ein belegter **Workflow-Titel** bricht den gesamten Import ab
  (keine verwaisten Elemente), ein belegtes **Briefpapier** bzw. eine belegte **E-Mail-Vorlage**
  wird einzeln übersprungen. Übersprungene Elemente werden nach dem Import **namentlich gemeldet**
  (vorhandenes umbenennen oder Namen in der JSON ändern und erneut importieren).

### Geändert
- **Eindeutige Namen erzwungen (Anlegen/Bearbeiten/Duplizieren).** Workflow- und Briefpapier-Titel
  müssen jetzt eindeutig sein (`eval.unique`): beim Duplizieren wird der Titel geleert und im
  Bearbeiten-Formular ein freier Name verlangt; ein bereits vergebener Name wird beim Speichern mit
  einer Warnung abgelehnt.

## [2.3.3] – 2026-06-05

### Behoben
- **Demo-Formularseite: „Unterseitenlayout" zeigte „Unbekannte Option".** Beim Anlegen und beim
  Heilen der Seite wird `subpageLayout` jetzt explizit auf **0 (= Seitenlayout vererben)** gesetzt
  – vorher blieb dort ein ungültiger Wert (Verweis auf das entfernte dedizierte Demo-Layout).

## [2.3.2] – 2026-06-05

### Behoben
- **Demo-Formularseite erschien in der Navigation und brachte ein eigenes, nacktes Layout mit.**
  Sie wird jetzt **aus dem Menü versteckt** (`hide`), **erbt ein vorhandenes Site-Layout** (statt
  eines eigenen) und bindet das „Workflow-Formular"-Modul über **Artikel + Inhaltselement** ein –
  ohne bestehende Seiten/Layouts zu verändern. Das frühere dedizierte Demo-Layout wird entfernt;
  eine bereits angelegte Demo-Seite wird beim Wiederherstellen entsprechend korrigiert.

### Doku
- ANLEITUNG Abschnitt 1: genaue Anleitung, wie die Formularseite das **Website-Layout übernimmt**,
  **aus dem Menü** genommen wird und das Modul per Inhaltselement erhält – ohne andere Teile der
  Website zu verändern oder Fehler zu verursachen.

## [2.3.1] – 2026-06-05

### Behoben
- **Sonderzeichen wurden beim Speichern kodiert.** Text-/Textarea-Felder ohne `decodeEntities`
  ließen Contao `( ) # < > = \` als HTML-Entities speichern – z. B. wurde ein Titel
  „… (synthetische Daten)" beim erneuten Speichern zu „… &#40;synthetische Daten&#41;", und
  `##platzhalter##` wären über `#` → `&#35;` zerstört worden. `decodeEntities => true` an allen
  Inhalts-Textfeldern ergänzt (Titel/Label, `pdfTitle`, `pdfFileName`, `pdfBody`, Antwort-Optionen,
  PDF-Variablen, Bedingungswerte). Bereits verfälschte Werte heilen beim nächsten Speichern bzw.
  beim Wiederherstellen des Demos.

## [2.3.0] – 2026-06-05

### Hinzugefügt
- **Der Demo bringt eine Formularseite mit** und ist damit end-to-end versendbar. Beim Anlegen/
  Wiederherstellen wird (idempotent) eine funktionierende Formularseite erzeugt: Theme + Layout +
  „Workflow-Formular"-Modul + eine reguläre Seite unter einer vorhandenen **veröffentlichten
  Root-Seite**; danach wird sie am Demo-Workflow als *Formularseite* gesetzt. Vorhandene Records
  werden per Marker-Name **wiederverwendet** (kein Duplikat), **keine Datei wird überschrieben**.
  Ohne veröffentlichte Root-Seite entfällt nur die Formularseite.
- **Echter Formular-Link im Backend-Eintrag.** Beim Token wird jetzt der **tatsächliche** Link
  (`<URL der Formularseite>/<Token>`) angezeigt statt des statischen „…/workflow/…".

### Geändert
- Klargestellt (ANLEITUNG/Eintrag): Die Formular-URL ergibt sich aus dem **Alias der
  Formularseite** + Token (nicht fix `/workflow/…`); häufige 404-Ursache (falscher Alias /
  abschließender Slash) dokumentiert.

## [2.2.3] – 2026-06-05

### Behoben
- **Versand-Versuch ohne Formularseite scheiterte ohne sichtbare Rückmeldung.** Ein Workflow ohne
  (gültige) Formularseite oder ohne zugeordnete E-Mail-Benachrichtigung kann keine Einladungen
  versenden (der `##link##` braucht die Formularseite) – der Versand brach erst beim Klick mit
  einer leicht zu übersehenden Meldung ab. Die Übersicht zeigt das jetzt **vorab** als deutliche
  Warnung („Versand nicht möglich: …") und **deaktiviert** den Senden-Button. Betrifft u. a. den
  nicht-invasiven Demo-Workflow (keine Formularseite).

## [2.2.2] – 2026-06-05

### Geändert
- Redundante Beispiel-CSV `docs/sample-trainers.csv` entfernt. Als Beispiel-Quelldatei dient
  jetzt die (synthetische) Demo-Quelle `src/Resources/demo/demo-teilnehmer.csv` – es gibt nur
  noch **eine** Demo-CSV.

## [2.2.1] – 2026-06-05

### Geändert
- **Keine vorgefertigten Workflow-Vorlagen mehr im Paket** (außer dem synthetischen Demo). Der
  Konfigurations-Import erfolgt jetzt **nur per Datei-Upload** (JSON-Export); die Auswahl
  mitgelieferter Presets entfällt. Vereinsspezifische Vorlagen werden als externe Dateien
  bereitgestellt, nicht im Paket/Repo.
- **Vereinsspezifische Inhalte entfernt.** Die mitgelieferten Templates (`pdf_master`,
  `pdf_body_verzicht`), die Hilfetexte und die Doku verwenden jetzt durchgängig neutrale
  Platzhalter („Musterverein e.V."). `pdf_master` ist damit ein **neutraler Beispiel-Briefkopf**.

### Hinzugefügt
- Der **Demo-Workflow** legt jetzt zusätzlich passende **E-Mail-Vorlagen** an (Notification
  Center, jeweils mit „(Demo)" im Namen) und verknüpft sie; beim Wiederherstellen werden sie
  ersetzt. Das gemeinsame E-Mail-Gateway bleibt unangetastet.

## [2.2.0] – 2026-06-05

### Hinzugefügt
- **Workflow-Konfigurationen importieren/exportieren.** In der Workflow-Übersicht lässt sich
  jede Workflow-Konfiguration als portable **JSON-Datei exportieren** und eine solche Datei
  wieder **importieren** (Datei-Upload). Beim Import optional auch die
  **Briefpapier-Konfiguration** und die **E-Mail-Vorlagen** (Notification Center:
  Einladung/Erinnerung/Ergebnis) mit anlegen (vorhandenes E-Mail-Gateway wird wiederverwendet).
  Der importierte Workflow hat bewusst **keine Quelldatei** → nach der bestehenden Prüfung
  „nicht ausführbar", bis eine passende Quelle zugeordnet wird.
- Export/Import lassen Logo, Quelldatei-UUID und Formularseite (site-spezifisch) bewusst aus.
  Der Demo-Seeder nutzt jetzt denselben Materializer (`WorkflowConfigImporter`).

## [2.1.0] – 2026-06-05

### Hinzugefügt
- **Synthetischer Demo-Workflow.** Bei der Erstinstallation wird einmalig ein komplett
  synthetischer Demo-Workflow („Musterverein", `@example.org`) angelegt: Briefkopf
  (`pdf_master_generic`), Antwortfelder (Radio + „Aktuelle Zeit"), PDF-Regeln und fünf
  importierte Beispiel-Teilnehmer. Updates legen ihn **nicht** erneut an (Marker-Datei
  `var/workflow_demo_installed`). In der Workflow-Übersicht gibt es den Button **„Demo-Workflow
  wiederherstellen"**, der den Demo idempotent neu anlegt (vorhandener gleichen Namens wird
  ersetzt). Nicht-invasiv: legt **keine** Seiten/Module/Notification-Center-Datensätze an –
  das Live-Formular braucht weiterhin die dokumentierte Formularseite (siehe ANLEITUNG.md).

## [2.0.2] – 2026-06-05

### Behoben
- Bearbeiten eines Workflows warf im **Produktiv**-Container eine
  `ServiceNotFoundException` für `AnswerConfigListener` („removed or inlined"), weil der
  per `System::importStatic()` aufgelöste DCA-Callback-Service privat war. Jetzt `public`
  (wie die übrigen container-aufgelösten Helfer). Nur in Prod sichtbar – der dev-Container
  inlinet private Services nicht.

## [2.0.1] – 2026-06-05

### Behoben
- Anlegen/Bearbeiten eines Workflows schlug auf einer frischen Installation mit einem
  SQL-Syntaxfehler fehl (`… WHERE  ORDER BY id …`). Ursache war ein `findBy([], …)` im
  Master-Vorauswahl-Callback (`WorkflowOptionsListener::preselectMaster`); ersetzt durch
  `findAll(['order' => 'id', 'limit' => 1])`. Trat auf, sobald das Feld „Briefkopf-Vorlage"
  leer war (also wenn noch kein Master angelegt ist).

## [2.0.0] – 2026-06-04

Großer Umbau: Umbenennung **Trainer → Workflow** auf allen Ebenen (Code, DB, UI)
plus zahlreiche neue Funktionen und Korrekturen. **Breaking** durch die
Umbenennung von Paket, Namespace und DB-Tabellen.

### Breaking – Umbenennung Trainer → Workflow
- Paket `psimandl/contao-trainer-workflow` → **`psimandl/contao-workflow`**;
  Verzeichnis `contao-trainer-workflow/` → **`contao-workflow/`**.
- Namespace `Psimandl\TrainerWorkflowBundle` → **`Psimandl\WorkflowBundle`**;
  Bundle-Klasse `ContaoTrainerWorkflowBundle` → **`ContaoWorkflowBundle`**.
- DB-Tabellen `tl_trainer_workflow/_entry/_question/_rule/_master` →
  **`tl_workflow`, `tl_workflow_entry`, `tl_workflow_question`, `tl_workflow_rule`, `tl_workflow_master`**.
- CLI-Befehle `trainer:import|send|export` → **`workflow:import|send|export`**.
- Backend-Routen `/contao/trainer` + `trainer_*` → **`/contao/workflow`** + `workflow_*`.
- BE-Module `trainer/_overview/_workflow/_master` → **`workflow/workflow_overview/workflow_manage/workflow_master`**.
- FE-Modul-Typ `trainer_form` → **`workflow_form`**; Templates `be_trainer_dashboard`/`mod_trainer_form`
  → **`be_workflow_dashboard`/`mod_workflow_form`**; Assets `trainer-*.{css,js}` → **`workflow-*`**;
  Asset-Bundle `bundles/contaotrainerworkflow` → **`bundles/contaoworkflow`**.
- Notification-Center-Typ `trainer_workflow` → **`workflow`**.
- PDF-Speicherpfad `var/trainer_pdfs/` → **`var/workflow_pdfs/`**.
- **Migration** `RenameTrainerToWorkflowMigration` benennt die fünf Tabellen um, aktualisiert
  `tl_nc_notification.type` und `tl_module.type`, schreibt `tl_workflow_entry.pdfPath` um und
  verschiebt das PDF-Verzeichnis. Läuft im ersten Migrations-Pass vor dem Schema-Diff.
- DDEV-Projekt `trainer-workflow` → **`workflow`** (URL `https://workflow.ddev.site`),
  Demo-Seiten-Alias `trainer` → `workflow` (Formular-Link `/workflow/<token>`).

### Added – einheitliche Platzhalter
- **`Service\PlaceholderResolver`** als einzige Token-Quelle für PDF, E-Mail und Export:
  kanonische, überall identische Platzhalter **`##data_<slug>##`** (Quellspalten inkl.
  gespeicherter Antwortwerte) und **`##var_<slug>##`** (Briefkopf-Variablen). `<slug>` =
  kleingeschrieben, deutsche Umlaute transliteriert (ä→ae, ö→oe, ü→ue, ß→ss), Rest → `_`
  (z. B. „davon Spende" → `##data_davon_spende##`). Im PDF gilt zusätzlich der Rohspaltenname
  (`##Spalte##`) als Alias; in Mails nur die kanonische Form (NC-Token ohne Leerzeichen).

### Added – Antwortfelder & PDF
- Neuer Antwortfeld-Typ **„Aktuelle Zeit" (`currentTime`)**: wird beim Absenden serverseitig
  automatisch mit dem aktuellen Datum gefüllt (ignoriert das Formular). Zusatzoption
  **„Feld im Formular ausblenden" (`hideInForm`)**; bei diesem Typ entfällt „Pflichtfeld".
- Workflow-Felder **`pdfSignatureDate`** (Datum) und **`pdfSignatureLocation`** (Ort, z. B. Wohnort
  der Person) speisen die Unterschriftszeile aus echten Datenfeldern. Beide liegen in der
  Subpalette von **„Unterschrift verlangen"** und sind nur sichtbar, wenn diese aktiv ist.
- **Konfigurierbarer PDF-Dateiname (`pdfFileName`)** mit Platzhaltern
  (z. B. `Verzicht_##data_name##_##data_vorname##`); zu einem sicheren Dateinamen bereinigt,
  bei Namensgleichheit wird ein kurzer Token angehängt; erneute Erzeugung überschreibt die
  eigene Datei. Leer = Eintrags-Token.

### Added – Validierung & Kopieren
- **`Service\WorkflowValidator`** + **`WorkflowIntegrityListener`**: ein Workflow ohne (lesbare)
  Quelldatei bzw. mit Spalten, die nicht zur Quelle passen (E-Mail-Spalte, Speicherfelder der
  Antwortfelder, Bedingungsfelder der PDF-Regeln), ist **nicht ausführbar**. Im Bearbeiten-Dialog:
  Info-Box + **rote Umrandung** der betroffenen Felder (inkl. Antwortfelder- und PDF-Regeln-Liste),
  Warnung beim Speichern. Import/Versand werden gesperrt; in der Übersicht erscheinen Badge +
  deaktivierte Aktionen. Im Regel-Dialog zeigt das „Antwortfeld"-Dropdown unbekannte Werte als
  **„Unbekannte Option: …"**.
- **Workflow kopieren** übernimmt jetzt Antwortfelder **und** PDF-Regeln, **nicht** aber Quelldatei
  und importierte Einträge; die Kopie startet **unveröffentlicht** (über Contao-Bordmittel:
  `ctable` + `doNotCopyRecords` + `eval.doNotCopy`). Eine Kopie greift nie auf die PDFs des
  Originals zu (eigene ID → eigenes Verzeichnis).

### Added – Dashboard / Übersicht
- **Ausstehende-Antworten-Liste**: zeigt zusätzlich **Name/Vorname** (falls vorhanden), ist je
  Spalte **sortierbar**, hat eine **Checkbox je Zeile**, Massenauswahl **„Alle"/„Alle aufheben"**
  und je Workflow-Schritt (außer dem letzten) einen **Auswahl-Button**, der alle Einträge dieses
  Status selektiert.
- **Ein** Button **„E-Mails senden"** statt zwei: öffnet einen Dialog mit **„Automatisch"/„Manuelle
  Auswahl"** und den Schaltflächen **„Einladungen senden"/„Erinnerungen senden"** samt Live-Anzahl;
  danach **Bestätigungsschritt** mit der konkreten Empfängerliste. Serverseitig eine
  `workflow_send`-**POST**-Route (`type=invite|reminder`, optional `ids[]`).
- **Warnung „kein Import ausgeführt"** für einen konfigurierten, lauffähigen Workflow ohne Einträge.
- Jeder Workflow wieder als **eigene Karte** (`.wf-box`), mit Contao-Theme-Variablen → **Dark-Mode-fest**.
- Workflow-Liste **neueste zuerst** als flache Liste (mode 1, `tstamp DESC`, `disableGrouping`).

### Added – Beispiel-Briefkopf (Master-Vorlage)
- `pdf_master.html5` neu als neutraler Beispiel-Briefkopf: blaue **Kopfzeile** „Musterverein e.V. • Musterstraße 1 • …"
  oben links, Logo oben rechts, blaue Linie darunter; **4-spaltige blaue Fußzeile** (Anschrift,
  Vorstände, Kontakt, Bankverbindungen) – umgesetzt als echte **mPDF-Lauf-Kopf/Fußzeile**
  (`<htmlpageheader>/<htmlpagefooter>`, dazu Seitenränder in `PdfGenerator::renderPdf`).
  **Signaturzeile gespiegelt**: links „<Ort>, <Datum>" über der Linie + Label „Ort, Datum",
  rechts Unterschriftsbild über der Linie + „Unterschrift <Name>".

### Added – Demo
- Zwei zusätzliche Demo-Workflows als weitere Vorlagen: **„EStG Übungsleiter"** (§ 3 Nr. 26 EStG)
  und **„Verzicht Ehrenamtspauschale"** (je eine `isDefault`-Regel, Signatur-Formular,
  verstecktes „Aktuelle Zeit"-Datumsfeld). `scripts/configure-demo-basistabelle.php` legt nun drei
  Workflows idempotent (nach Titel) an.

### Changed
- PDF-Brieftexte/Überschriften nutzen die kanonischen `##data_*##`/`##var_*##`-Tokens; der implizite
  Token **`##datum##`** und das automatische „aktuelle Datum" im PDF wurden **entfernt** – das
  gedruckte Datum kommt aus `pdfSignatureDate`, der Ort aus `pdfSignatureLocation` (PDF == DB == Export).
- `NotificationDispatcher` liefert E-Mail-Tokens über den `PlaceholderResolver` (zusätzlich `##var_*##`).

### Removed
- Legacy-Spalten der alten fixen Ja/Nein-Logik (`labelAccept`, `labelReject`, `decisionField`,
  `dateField`, `pdfBody`, `pdfBodyReject` an `tl_workflow`; `decision` an `tl_workflow_entry`) per
  **`DropLegacyColumnsMigration`** entfernt → die „Workflow-Details" (`act=show`) zeigen keine
  Altfelder mehr. `ConfigurableAnswersMigration` entfällt.

### Fixed
- **500-Fehler** „Call to undefined method `Contao\Message::addWarning()`" beim Speichern eines
  nicht-lauffähigen Workflows (u. a. `Kopfzeile` per submitOnChange) → `addInfo`.
- Demo-PDF: nicht aufgelöste Platzhalter (Tokens auf reale Spalten + kanonisches Schema umgestellt).
- PDF nutzte das **aktuelle Datum** statt des gespeicherten Antwortdatums → jetzt aus dem Datenfeld.
- Kopie verlor die **Antwortfelder** bzw. schleppte Quelldatei/Einträge mit.
- Sortier-Liste der Übersicht **sprang** beim Sortieren (Sortierpfeil in festem Slot hinter der
  Überschrift).
- **Dark Mode**: weiße Boxen mit unleserlichem Text in der Übersicht (Theme-Variablen statt
  fester Farben).
- Vertikale Ausrichtung/Beschriftung der Aktions-Buttons in der Übersicht.

[2.0.0]: #
