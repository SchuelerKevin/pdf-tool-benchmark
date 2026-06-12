# Stamping-Benchmark

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
./verapdf-install   # interaktiv; als Installationspfad /opt/verapdf eingeben
```

Der Installer fragt nach dem Installationspfad – dort `/opt/verapdf` eingeben,
dann passt der Aufruf `--verapdf=/opt/verapdf/verapdf` aus dieser Anleitung.
Wird der Default übernommen, landet veraPDF stattdessen unter `~/verapdf`
(im Container als root: `/root/verapdf`) und der Aufruf lautet
`--verapdf=/root/verapdf/verapdf`.

**Optional: PDFlib** – für Variante E. Die Evaluierungsversion ist kostenlos
und stempelt ein Demo-Wasserzeichen ins Ergebnis – für die Zeitmessung völlig
ausreichend. Ohne Extension wird E automatisch übersprungen (mit Hinweis am
Ende des Reports).

Installation (PHP 8.2 NTS, Linux x64):

```bash
# 1. Paket herunterladen (PDFlib 11.0.0, Linux x64, PHP):
wget https://www.pdflib.com/binaries/PDFlib/1100/PDFlib-11.0.0-Linux-x64-php.tar.gz

# 2. Entpacken
tar xzf PDFlib-11.0.0-Linux-x64-php.tar.gz
cd PDFlib-11.0.0-Linux-x64-php/

# 3. .so für PHP 8.2 ins Extension-Verzeichnis kopieren
cp bind/php/php-820-nts/php_pdflib.so $(php -r "echo ini_get('extension_dir');")

# 4. Als eigene ini-Datei im conf.d-Verzeichnis eintragen (Docker-typisch,
#    analog zu den anderen Extensions wie docker-php-ext-mongodb.ini):
echo "extension=php_pdflib.so" > /usr/local/etc/php/conf.d/docker-php-ext-pdflib.ini

# 5. Prüfen
php -r "echo class_exists('PDFlib') ? 'PDFlib geladen' : 'FEHLER';"
```

## 2. Controller einsetzen

`PdfBenchmarkController.php` liegt in `console/controllers/pdf-tool-benchmark/`
(separates Git-Repo, in `console/.gitignore` eingetragen).

Der Namespace oben in der Datei muss `console\controllers` sein (nicht der
Unterordnerpfad). Weil die Datei nicht dort liegt, wo der Composer-Autoloader
sie nach PSR-4 erwarten würde, braucht es zwei Einträge in
`common/config/applications/console/main-dev.php`: ein manuelles Class-Mapping
(damit PHP die Datei findet) und den `controllerMap`-Eintrag (damit Yii den
Befehl `pdf-benchmark` kennt):

```php
<?php

// Datei liegt im Unterordner pdf-tool-benchmark/, daher manuelles Class-Mapping
Yii::$classMap['console\controllers\PdfBenchmarkController'] =
    dirname(__DIR__, 4) . '/console/controllers/pdf-tool-benchmark/PdfBenchmarkController.php';

return [
    'controllerMap' => [
        'pdf-benchmark' => \console\controllers\PdfBenchmarkController::class,
    ],
];
```

`dirname(__DIR__, 4)` geht von `common/config/applications/console/` zum
Projekt-Root. Falls die Verzeichnisstruktur abweicht, stattdessen den absoluten
Pfad zur Controller-Datei eintragen.

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
| `--security=1` | Verschlüsselung mitmessen: SetaPDF hängt einen generischen AES-256-SecHandler an (nicht den `setSecurity`-Pfad des PdfBehaviors, der kundenspezifische Einstellungen bräuchte), qpdf verschlüsselt identisch (AES-256, Drucken ja / Kopieren+Ändern nein). Ohne diese Option misst der Benchmark *weniger* Arbeit als die Produktion. |
| `--verapdf=…` | PDF/UA-Prüfung der gestampten Ergebnisse (C und E) |
| `--licensor="…"` / `--stampCopyright=1` | Copyright-Zeile wie in Produktion |
| `--optimizeImages=0` | Bild-Recompression bei der Quelloptimierung aus |
| `--recursive=1` | Unterordner einbeziehen |
| `--keep=1` | Gestampte Ergebnisse behalten, um sie zu vergleichen. Pro PDF und Variante wird nur das letzte Exemplar aufgehoben (Zwischenmessungen werden sofort gelöscht), also maximal `PDFs × Varianten` Dateien. Pfad zum Verzeichnis steht am Ende des Reports. Dateien per `docker cp <container>:/tmp/stampbench_.../ ~/Downloads/` auf den lokalen Rechner kopieren. |

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

- **SetaPDF-Spalten n/a:** Fehlermeldung steht unter dem Block. Meist
  PDF_BEHAVIOR-Klassenname falsch; die Fehlermeldung steht direkt darunter.
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