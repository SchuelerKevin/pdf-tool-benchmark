# Stamping-Benchmark – Anleitung

Vergleicht fünf Varianten über einen Ordner voller PDFs:

| | Variante | misst |
|--|----------|-------|
| A | SetaPDF `REWRITE_ALL` auf Original | euren Ist-Zustand |
| B | wie A, mit `gc_disable()` | den SetaPDF-Support-Tipp |
| D | SetaPDF auf einmalig optimierter Quelle | Effekt der Quelloptimierung |
| C | qpdf-Overlay (Artifact-Stamp) auf opt. Quelle | Kandidat 1 |
| E | PDFlib+PDI-Overlay auf opt. Quelle | Kandidat 2 (nur mit PDFlib-Extension) |

Jede Messung läuft in einem frischen PHP-Prozess (Median aus N Läufen, 1 Warmup).
Gemessen wird nur die Stamp-Operation, nicht der Yii-Bootstrap.

---

## 1. Voraussetzungen

**Pflicht** – qpdf und poppler-utils im Container:

```bash
apt-get update && apt-get install -y qpdf poppler-utils
```

**Optional: veraPDF** – echte PDF/UA-Prüfung (Barrierefreiheit) der gestampten
Ergebnisse. Ohne veraPDF prüft das Skript nur, ob der Tag-Baum noch existiert;
veraPDF prüft die eigentlichen PDF/UA-Regeln (u. a. ob der Stamp korrekt als
Artifact durchgeht). Installation (braucht Java 8+):

```bash
# Java prüfen / installieren
java -version || apt-get install -y default-jre-headless

# veraPDF-Installer laden und headless installieren
wget https://software.verapdf.org/releases/verapdf-installer.zip
unzip verapdf-installer.zip && cd verapdf-greenfield-*
./verapdf-install   # interaktiv; Zielpfad z. B. /opt/verapdf
```

Danach mit `--verapdf=/opt/verapdf/verapdf` aufrufen.

**Optional: PDFlib** – für Variante E. PDFlib+PDI-Paket (PHP-Extension) von
pdflib.com installieren und in der php.ini laden (`extension=pdflib`). Die
**Evaluierungsversion ist kostenlos** und stempelt ein Demo-Wasserzeichen ins
Ergebnis – für die Zeitmessung völlig ausreichend. Ohne Extension wird E
automatisch übersprungen (mit Hinweis am Ende des Reports).

## 2. Controller einsetzen

`PdfBenchmarkController.php` liegt in `console/controllers/pdf-tool-benchmark/`
(separates Git-Repo, in `console/.gitignore` eingetragen).

Der Namespace oben in der Datei muss `console\controllers` sein (nicht der
Unterordnerpfad) — Yii findet die Klasse über den `controllerMap`-Eintrag in
`common/config/applications/console/main-dev.php`:

```php
return [
    'controllerMap' => [
        'pdf-benchmark' => \console\controllers\PdfBenchmarkController::class,
    ],
];
```

Der `PDF_BEHAVIOR`-Klassenname oben in der Datei ist bereits auf
`\common\components\PdfBehavior` gesetzt.

## 3. Lauf starten

```bash
cd /var/www/console
php yii_dev pdf-benchmark/run /var/www/console/runtime/benchpdfs --repeats=3
```

Vollausbau (alle Prüfungen aktiv):

```bash
php yii_dev pdf-benchmark/run /var/www/console/runtime/benchpdfs \
  --repeats=3 \
  --security=1 \
  --verapdf=/opt/verapdf/verapdf
```

## 4. Die Optionen im Detail

| Option | Bedeutung |
|--------|-----------|
| `--repeats=5` | mehr Wiederholungen → stabilerer Median |
| `--text="…"` | Wasserzeichen-Text |
| `--security=1` | **Produktionsgetreu messen:** SetaPDF verschlüsselt via `setSecurity` + euer Pdfsec (wird aus der DB geladen wie in `sendPdf()`); qpdf verschlüsselt zum fairen Vergleich ebenfalls (AES-256, Drucken ja / Kopieren+Ändern nein). Ohne diese Option misst der Benchmark *weniger* Arbeit als die Produktion. |
| `--verapdf=…` | PDF/UA-Prüfung der gestampten Ergebnisse (C und E) |
| `--licensor="…"` / `--stampCopyright=1` | Copyright-Zeile wie in Produktion |
| `--optimizeImages=0` | Bild-Recompression bei der Quelloptimierung aus |
| `--recursive=1` | Unterordner einbeziehen |
| `--keep=1` | Temp-Dateien behalten (gestampte Ergebnisse ansehen!) |

## 5. Report lesen

Pro PDF ein Block: erst die fünf Zeiten (inkl. Ausgabegröße in MB – relevant
für die Download-Dauer beim Nutzer), dann Quelloptimierungs-Effekt, dann
Tag-Erhalt und PDF/UA je Kandidat, dann eine automatische Interpretation in
Klartext. Bei mehreren PDFs folgt ein Gesamt-Median.

Entscheidungslogik:
- Ein Kandidat (C/E) ist nur dann **einsetzbar**, wenn „Tags erhalten: ja"
  und – bei Pflicht zur Barrierefreiheit – „PDF/UA: PASS".
- Unter den einsetzbaren gewinnt der schnellste.
- Ist keiner einsetzbar: bei SetaPDF bleiben und Quelloptimierung +
  ggf. gc_disable() mitnehmen.

## 6. Bekannte Stolpersteine

- **„VERSCHLUESSELT, übersprungen"** bei Quelldateien: Das sind PDFs, die schon
  durch eure Stamping-Pipeline gelaufen sind (setSecurity). Ungestampte
  Originale vom S3 verwenden.
- **SetaPDF-Spalten n/a:** Fehlermeldung steht unter dem Block. Meist
  PDF_BEHAVIOR-Klassenname oder (bei `--security=1`) Pdfsec/DB nicht erreichbar.
- **PDFlib-Variante fehlgeschlagen:** Die E-Implementierung ist eine
  Referenz nach PDFlib-Doku und konnte ohne Lizenz nicht vorab getestet
  werden – API-Abweichungen je nach PDFlib-Version erscheinen als Fehlertext
  im Report und sind meist mit kleinen Anpassungen in `pdflibOverlay()` behoben.

## 7. Wichtig vor einem echten Umstieg

Der Benchmark-Stempel (Variante C) nutzt eine **nicht eingebettete** Helvetica.
Für die Zeitmessung egal – für echte PDF/UA-Konformität muss die Stempelschrift
eingebettet sein. Produktionsweg, falls C gewinnt: die einseitige,
artifact-markierte Stempelseite mit eurem SetaPDF-Stempelcode erzeugen
(Schrift eingebettet, dauert <1 s) und qpdf nur für das teure Overlay nutzen.
Ergebnis mit veraPDF gegen `ua1` validieren.

`setXmetadata()` aus `sendPdf()` ist im Benchmark nicht enthalten (braucht das
Model, zeitlich vernachlässigbar).