<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Stamping-Benchmark: vergleicht euren heutigen SetaPDF-Weg mit gc_disable(),
 * mit einer einmalig optimierten Quelle und mit einem qpdf-Overlay – ueber einen
 * ganzen Ordner voller PDFs.
 *
 * Pro PDF:
 *   1) ANALYSE   – charakterisiert die Datei ("wie optimal ist sie?")
 *   2) MESSUNG   – Median aus N Laeufen je Variante, jeder Lauf in FRISCHEM Prozess
 *                  (sauberer Speicher + spiegelt euer kurzlebiges Request-Szenario):
 *        A  SetaPDF REWRITE_ALL            (euer heutiger Weg) – auf Original
 *        B  SetaPDF REWRITE_ALL + gc_disable()                – auf Original
 *        O  qpdf-Optimierung der Quelle (EINMALIG je Buch)    – Zeit + Groessen-Delta
 *        C  qpdf-Overlay (Artifact-Stamp) auf opt. Quelle     – Pro-Request-Kosten
 *        D  SetaPDF REWRITE_ALL auf opt. Quelle               – isoliert Effekt der Optimierung
 *   3) VALIDIERUNG – bleibt der Struktur-Tree erhalten? (optional veraPDF: PDF/UA)
 *
 * Aufruf:
 *   php yii pdf-benchmark/run @app/tests/pdfs --repeats=3
 *   php yii pdf-benchmark/run /abs/pfad --security=1 --verapdf=/opt/verapdf/verapdf
 *
 * Voraussetzungen auf dem Host: qpdf, pdfinfo (poppler-utils). Optional: verapdf.
 */
class PdfBenchmarkController extends Controller
{
    /* ====================================================================
     * HIER ANPASSEN: vollstaendiger Klassenname EURES PdfBehavior.
     * In sendPdf() steht nur "PdfBehavior::class" – traeg hier den FQN ein,
     * den der use-Import oben in eurer Datei aufloest.
     * ==================================================================== */
    private const PDF_BEHAVIOR = \common\components\PdfBehavior::class;

    // Optionen (siehe options())
    public $repeats = 3;
    public $text = 'Lizenziert für XYZ, 12.345.67.89 am 08.06.2026 um 16:03 Uhr';
    public $security = false;        // SetaPDF-Verschluesselung mitmessen (Produktion: setSecurity=true)
    public $licensor = '';           // copyrightText (= soz_drm_licensor)
    public $stampCopyright = false;  // Copyright mitstempeln
    public $verapdf = null;          // Pfad zur veraPDF-CLI -> echte PDF/UA-Pruefung
    public $optimizeImages = true;   // qpdf --optimize-images bei der Quelloptimierung
    public $keep = false;            // Temp-Dateien behalten
    public $recursive = false;       // Unterordner einbeziehen

    public function options($actionID)
    {
        if ($actionID === 'run') {
            return ['repeats', 'text', 'security', 'licensor', 'stampCopyright',
                'verapdf', 'optimizeImages', 'keep', 'recursive'];
        }
        if ($actionID === 'stamp-once') {
            return ['security', 'licensor', 'stampCopyright'];
        }
        return parent::options($actionID);
    }

    // ===================================================================
    //  ORCHESTRATOR
    // ===================================================================
    public function actionRun(string $folder)
    {
        $folder = Yii::getAlias($folder);
        if (!is_dir($folder)) { $this->stderr("Ordner nicht gefunden: $folder\n"); return ExitCode::USAGE; }

        foreach (['qpdf', 'pdfinfo'] as $bin) {
            if (trim((string)shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) === '') {
                $this->stderr("FEHLT: $bin (qpdf bzw. poppler-utils installieren)\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }
        $verapdf = ($this->verapdf && is_file($this->verapdf)) ? $this->verapdf : null;
        $repeats = max(1, (int)$this->repeats);

        // Pfade fuer den Worker-Subprozess (gleiches yii-Entry-Skript)
        $yii = $_SERVER['SCRIPT_FILENAME'] ?? (getcwd() . '/yii');
        $php = PHP_BINARY;
        $tmp = sys_get_temp_dir() . '/stampbench_' . getmypid();
        @mkdir($tmp, 0777, true);

        $pdfs = $this->collectPdfs($folder, (bool)$this->recursive);
        if (!$pdfs) { $this->stderr("Keine PDFs in $folder.\n"); return ExitCode::NOINPUT; }

        $this->stderr(sprintf("\n%d PDF(s), %d Messung(en)/Variante. Security: %s. veraPDF: %s\n\n",
            count($pdfs), $repeats, $this->security ? 'an' : 'aus', $verapdf ? 'ja' : 'nein'));

        $rows = [];
        $firstErr = null;
        foreach ($pdfs as $i => $pdf) {
            $name = basename($pdf);
            $this->stderr(sprintf("[%d/%d] %s\n", $i + 1, count($pdfs), $name));
            $a = $this->analyze($pdf);
            $this->stderr(sprintf("        %.2f MB, %d Seiten, v%s  →  %s\n",
                $a['size'] / 1048576, $a['pages'], $a['version'], $a['verdict']));
            if ($a['encrypted']) { $this->stderr("        übersprungen (verschlüsselt)\n"); continue; }

            $base = "$tmp/$i";
            $opt  = "$base.opt.pdf";
            $O = $this->optimizeSource($pdf, $opt, (bool)$this->optimizeImages);

            $A = $this->bench($php, $yii, 'setapdf_rewrite',    $pdf, $base, $repeats);
            $B = $this->bench($php, $yii, 'setapdf_rewrite_gc', $pdf, $base, $repeats);
            $C = $O['ok'] ? $this->bench($php, $yii, 'qpdf_overlay',    $opt, $base, $repeats) : ['ok' => false, 'note' => 'keine opt. Quelle'];
            $D = $O['ok'] ? $this->bench($php, $yii, 'setapdf_rewrite', $opt, $base, $repeats) : ['ok' => false, 'note' => 'keine opt. Quelle'];

            foreach ([$A, $B, $C, $D] as $r) { if (empty($r['ok']) && !$firstErr && !empty($r['note'])) $firstErr = $r['note']; }

            $structOK = ($a['tagged'] && !empty($C['ok']) && $C['out'])
                ? ($this->rootHasStructTree($C['out']) ? 'ja' : 'NEIN')
                : ($a['tagged'] ? '?' : '–');
            $ua = ($verapdf && !empty($C['ok'])) ? $this->veraUA($verapdf, $C['out']) : null;

            $rows[] = compact('name', 'a', 'O', 'A', 'B', 'C', 'D', 'structOK', 'ua');
        }

        $this->printReport($rows, $firstErr, $bootstrapOk = true, $verapdf);

        if (!$this->keep) { array_map('unlink', glob("$tmp/*") ?: []); @rmdir($tmp); }
        else $this->stdout("\nTemp behalten: $tmp\n");
        return ExitCode::OK;
    }

    // ===================================================================
    //  WORKER – fuehrt GENAU EINE getimte Operation aus, gibt JSON aus.
    //  Wird vom Orchestrator je Messung als eigener Prozess gestartet.
    // ===================================================================
    public function actionStampOnce(string $op, string $in, string $out, string $text)
    {
        $res = ['ok' => false, 'ms' => null, 'peak_mb' => null, 'note' => ''];
        try {
            switch ($op) {
                case 'setapdf_rewrite':
                    $res = $this->measure(fn() => $this->stampWithSetaPdf($in, $out, $text, false), $out);
                    break;
                case 'setapdf_rewrite_gc':
                    $res = $this->measure(fn() => $this->stampWithSetaPdf($in, $out, $text, true), $out);
                    break;
                case 'qpdf_overlay':
                    $res = $this->measure(fn() => $this->qpdfOverlay($in, $out, $text), $out);
                    break;
                default:
                    throw new \RuntimeException("unbekannte Operation: $op");
            }
        } catch (\Throwable $e) {
            $res['note'] = $e->getMessage();
        }
        echo json_encode($res) . "\n";
        return ExitCode::OK;
    }

    /* ---- Messung um eine Operation ---------------------------------- */
    private function measure(callable $op, string $out): array
    {
        $t0 = hrtime(true);
        $op();
        $ms = (hrtime(true) - $t0) / 1e6;
        return [
            'ok'      => is_file($out) && filesize($out) > 0,
            'ms'      => round($ms, 1),
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'note'    => '',
        ];
    }

    /* ---- SetaPDF: originalgetreu ueber EUER PdfBehavior --------------
     * Spiegelt sendPdf(): attachBehavior -> init() -> stamp() -> save(METHOD).
     * Es wird hier bewusst SAVE_METHOD_REWRITE_ALL gemessen (= euer Vergleichs-
     * Baseline), unabhaengig davon, was in sendPdf() gerade fest verdrahtet ist.
     * ---------------------------------------------------------------- */
    private function stampWithSetaPdf(string $in, string $out, string $text, bool $disableGc): void
    {
        $raw = file_get_contents($in);

        // Bei --security=1 braucht PdfBehavior evtl. ein Pdfsec-Modell ('rights').
        // Standard (security aus) kommt ohne DB aus.
        $pdfsec = null;
        if ($this->security) {
            // TODO: bei aktivierter Security euer echtes Pdfsec laden, z.B.:
            // $pdfsec = \common\models\Pdfsec::find()->where(['pid' => Yii::$app->params['pid']])->one();
        }

        $this->attachBehavior('pdf', [
            'class'          => self::PDF_BEHAVIOR,
            'rawPdf'         => $raw,
            'stampText'      => $text,
            'copyrightText'  => $this->licensor,
            'stampCopyright' => (bool)$this->stampCopyright,
            'stampFirst'     => false,
            'setSecurity'    => (bool)$this->security,
            'rights'         => $pdfsec,
        ]);

        if ($disableGc) gc_disable();       // Tipp von SetaPDF: PHP-GC vor dem Lauf aus
        try {
            $this->behaviors['pdf']->init();
            $this->stamp();                                   // vom Behavior bereitgestellt
            $writer = new \SetaPDF_Core_Writer_File($out);
            $this->pdf->setWriter($writer);
            $this->pdf->save(\SetaPDF_Core_Document::SAVE_METHOD_REWRITE_ALL)->finish();
        } finally {
            if ($disableGc) { gc_enable(); gc_collect_cycles(); }
            $this->detachBehavior('pdf');
        }
    }

    /* ---- qpdf-Overlay: Artifact-Stamp erzeugen + ueberlagern --------- */
    private function qpdfOverlay(string $in, string $out, string $text): void
    {
        [$w, $h] = $this->firstPageSize($in);
        $stamp = "$out.stamp.pdf";
        $this->generateArtifactStamp($stamp, $w, $h, $text);
        exec('qpdf ' . escapeshellarg($in) . ' --overlay ' . escapeshellarg($stamp)
            . ' --repeat=1 -- ' . escapeshellarg($out) . ' 2>&1', $o, $rc);
        @unlink($stamp);
        if ($rc !== 0 && $rc !== 3) throw new \RuntimeException("qpdf overlay rc=$rc: " . implode(' | ', $o));
    }

    /**
     * Einseitige, ALS ARTIFACT markierte Stempel-PDF (Wasserzeichen).
     * Die /Artifact-BDC..EMC-Klammer ist der PDF/UA-relevante Teil: Screenreader
     * ignorieren den Stempel, der Dokument-Tag-Tree bleibt unberuehrt.
     *
     * HINWEIS Produktion: hier nicht eingebettete Helvetica (fuer die Zeitmessung egal).
     * Fuer echte PDF/UA-Konformitaet die Stempelschrift einbetten – am einfachsten
     * diese eine Seite mit eurem SetaPDF-Stempelcode erzeugen und qpdf nur fuers Overlay.
     */
    private function generateArtifactStamp(string $path, float $w, float $h, string $text): void
    {
        $a = deg2rad(35); $cos = cos($a); $sin = sin($a); $cx = $w / 2; $cy = $h / 2;
        $t = strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
        $content = "/Artifact <</Type /Watermark>> BDC\nq\n/GS1 gs\n0.5 0.5 0.5 rg\nBT\n/F1 28 Tf\n"
            . sprintf("%.5f %.5f %.5f %.5f %.2f %.2f Tm\n", $cos, $sin, -$sin, $cos, $cx, $cy)
            . "-220 0 Td\n($t) Tj\nET\nQ\nEMC\n";
        $objs = [
            "<< /Type /Catalog /Pages 2 0 R >>",
            "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            sprintf("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] "
                . "/Resources << /Font << /F1 5 0 R >> /ExtGState << /GS1 6 0 R >> >> /Contents 4 0 R >>", $w, $h),
            sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content),
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
            "<< /Type /ExtGState /ca 0.30 /CA 0.30 >>",
        ];
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        foreach ($objs as $i => $o) { $off[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n"; }
        $x = strlen($pdf); $n = count($objs) + 1;
        $pdf .= "xref\n0 $n\n0000000000 65535 f \n";
        foreach ($off as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
        $pdf .= "trailer\n<< /Size $n /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
        file_put_contents($path, $pdf);
    }

    private function firstPageSize(string $pdf): array
    {
        $o = shell_exec('pdfinfo ' . escapeshellarg($pdf) . ' 2>/dev/null');
        if ($o && preg_match('/Page size:\s+([\d.]+)\s+x\s+([\d.]+)/', $o, $m)) return [(float)$m[1], (float)$m[2]];
        return [595.28, 841.89];
    }

    // ===================================================================
    //  ANALYSE / OPTIMIERUNG / VALIDIERUNG / REPORT
    // ===================================================================
    private function analyze(string $pdf): array
    {
        $size = filesize($pdf);
        $info = shell_exec('pdfinfo ' . escapeshellarg($pdf) . ' 2>/dev/null') ?: '';
        $pages   = preg_match('/Pages:\s+(\d+)/', $info, $m) ? (int)$m[1] : 0;
        $version = preg_match('/PDF version:\s+([\d.]+)/', $info, $m) ? $m[1] : '?';
        $encrypted = (bool)preg_match('/Encrypted:\s+yes/i', $info);

        $check = shell_exec('qpdf --check ' . escapeshellarg($pdf) . ' 2>/dev/null') ?: '';
        $linearized = stripos($check, 'File is linearized') !== false;

        $xref = shell_exec('qpdf --show-xref ' . escapeshellarg($pdf) . ' 2>/dev/null') ?: '';
        $usesObjStm = substr_count($xref, 'compressed') > 0;

        $imgList = shell_exec('pdfimages -list ' . escapeshellarg($pdf) . ' 2>/dev/null') ?: '';
        $lines = array_slice(array_filter(explode("\n", trim($imgList))), 2);
        $imgCount = count($lines); $hiRes = 0;
        foreach ($lines as $ln) { $c = preg_split('/\s+/', trim($ln)); if (isset($c[12]) && is_numeric($c[12]) && (float)$c[12] > 200) $hiRes++; }

        $tagged = $this->rootHasStructTree($pdf);

        $perPage = $pages ? $size / $pages : $size;
        $f = [];
        if ($tagged) $f[] = 'getaggt';
        if ($imgCount) $f[] = "bildlastig($imgCount" . ($hiRes ? ",$hiRes hochaufl." : '') . ')';
        if ($perPage > 250 * 1024) $f[] = 'gross/Seite';
        if (!$usesObjStm) $f[] = 'keine ObjStreams';
        if ($linearized) $f[] = 'linearisiert';
        if ($encrypted) $f[] = 'VERSCHLUESSELT';

        return compact('size', 'pages', 'version', 'encrypted', 'linearized', 'usesObjStm', 'imgCount', 'hiRes', 'tagged')
            + ['verdict' => implode(', ', $f) ?: 'schlank'];
    }

    private function optimizeSource(string $in, string $out, bool $optImages): array
    {
        $flags = '--object-streams=generate --compress-streams=y --recompress-flate --linearize';
        if ($optImages) $flags .= ' --optimize-images';
        $t0 = hrtime(true);
        exec('qpdf ' . escapeshellarg($in) . " $flags " . escapeshellarg($out) . ' 2>&1', $o, $rc);
        if (($rc !== 0 && $rc !== 3) || !is_file($out)) return ['ok' => false, 'note' => "qpdf rc=$rc"];
        return ['ok' => true, 'ms' => round((hrtime(true) - $t0) / 1e6, 1), 'size' => filesize($out)];
    }

    /** 1 Warmup + N Messungen, jeder Lauf in frischem Prozess. */
    private function bench(string $php, string $yii, string $op, string $in, string $base, int $repeats): array
    {
        $times = []; $peak = 0; $out = null;
        for ($i = 0; $i <= $repeats; $i++) {                 // i=0 = Warmup
            $o = "$base.$op.$i.pdf";
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($yii) . ' pdf-benchmark/stamp-once '
                . escapeshellarg($op) . ' ' . escapeshellarg($in) . ' ' . escapeshellarg($o) . ' ' . escapeshellarg($this->text)
                . ($this->security ? ' --security=1' : '')
                . ($this->stampCopyright ? ' --stampCopyright=1' : '')
                . ($this->licensor !== '' ? ' --licensor=' . escapeshellarg($this->licensor) : '');
            $json = shell_exec($cmd . ' 2>/dev/null');
            $lines = $json ? array_values(array_filter(array_map('trim', explode("\n", $json)), 'strlen')) : [];
            $r = $lines ? json_decode(end($lines), true) : null;   // letzte nicht-leere Zeile = JSON
            if (!is_array($r) || empty($r['ok'])) { @unlink($o); return ['ok' => false, 'note' => $r['note'] ?? 'Worker ohne gueltige Ausgabe']; }
            if ($i > 0) { $times[] = $r['ms']; $peak = max($peak, $r['peak_mb']); }
            if ($i < $repeats) @unlink($o); else $out = $o;        // letzten Output behalten (Validierung)
        }
        return ['ok' => true, 'median' => round($this->median($times), 1), 'min' => round(min($times), 1), 'peak_mb' => $peak, 'out' => $out];
    }

    private function rootHasStructTree(string $pdf): ?bool
    {
        $tr = shell_exec('qpdf --show-object=trailer ' . escapeshellarg($pdf) . ' 2>/dev/null');
        if (!$tr || !preg_match('#/Root\s+(\d+)#', $tr, $m)) return null;
        $root = shell_exec('qpdf --show-object=' . (int)$m[1] . ' ' . escapeshellarg($pdf) . ' 2>/dev/null');
        return $root === null ? null : (strpos($root, '/StructTreeRoot') !== false);
    }

    private function veraUA(string $verapdf, ?string $pdf): ?string
    {
        if (!$pdf || !is_file($pdf)) return null;
        $o = shell_exec(escapeshellarg($verapdf) . ' --flavour ua1 ' . escapeshellarg($pdf) . ' 2>/dev/null') ?: '';
        if (preg_match('/isCompliant="(true|false)"/', $o, $m)) return $m[1] === 'true' ? 'PASS' : 'FAIL';
        return '?';
    }

    private function median(array $xs): float { sort($xs); $n = count($xs); if (!$n) return 0.0; $m = intdiv($n, 2); return $n % 2 ? $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2; }

    private function collectPdfs(string $folder, bool $recursive): array
    {
        $pdfs = [];
        if ($recursive) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) if (strtolower($f->getExtension()) === 'pdf') $pdfs[] = $f->getPathname();
        } else {
            foreach (glob(rtrim($folder, '/') . '/*.[pP][dD][fF]') ?: [] as $f) $pdfs[] = $f;
        }
        sort($pdfs);
        return $pdfs;
    }

    private function printReport(array $rows, ?string $firstErr, bool $bootstrapOk, ?string $verapdf): void
    {
        $bar = str_repeat('═', 72);
        $sep = str_repeat('─', 72);
        $ms  = fn($r) => empty($r['ok']) ? '       n/a' : sprintf('%10s ms', number_format($r['median'], 0));

        foreach ($rows as $r) {
            $a = $r['a'];
            $this->stdout("\n$bar\n");
            $this->stdout(sprintf("  %s\n", $r['name']));
            $this->stdout(sprintf("  %.2f MB  ·  %d Seiten  ·  getaggt: %s\n",
                $a['size'] / 1048576, $a['pages'], $a['tagged'] ? 'ja' : 'nein'));
            $this->stdout("$sep\n");
            $this->stdout(sprintf("  %-30s  %s\n", 'A  SetaPDF heute',         $ms($r['A'])));
            $this->stdout(sprintf("  %-30s  %s\n", 'B  SetaPDF + gc_disable()', $ms($r['B'])));
            $this->stdout(sprintf("  %-30s  %s\n", 'D  SetaPDF opt. Quelle',   $ms($r['D'])));
            $this->stdout(sprintf("  %-30s  %s\n", 'C  qpdf-Overlay',          $ms($r['C'])));
            $this->stdout("$sep\n");

            // Einmalige Quelloptimierung
            if (!empty($r['O']['ok'])) {
                $this->stdout(sprintf("  Quelloptimierung (einmalig pro Buch):  %.2f MB  →  %.2f MB\n",
                    $a['size'] / 1048576, $r['O']['size'] / 1048576));
            }

            // Tags und PDF/UA
            $structLabel = match($r['structOK']) {
                'ja'   => 'ja – Barrierefreiheits-Tags bleiben erhalten',
                'NEIN' => 'NEIN – Tags gehen verloren!',
                '–'    => '– (PDF nicht getaggt)',
                default => $r['structOK'],
            };
            $this->stdout(sprintf("  Tags erhalten (qpdf-Weg):              %s\n", $structLabel));
            $uaLabel = $r['ua'] ?? ($verapdf ? '?' : 'nicht geprüft  (--verapdf angeben)');
            $this->stdout(sprintf("  PDF/UA-Konformität (qpdf-Ergebnis):    %s\n", $uaLabel));

            // Automatische Interpretation
            $this->stdout("$sep\n");
            $this->stdout("  Interpretation:\n\n");

            if (!empty($r['A']['ok']) && !empty($r['B']['ok'])) {
                $gcPct = ($r['B']['median'] - $r['A']['median']) / $r['A']['median'] * 100;
                if (abs($gcPct) < 5)
                    $this->stdout("  · gc_disable() bringt keinen messbaren Vorteil.\n");
                elseif ($gcPct < 0)
                    $this->stdout(sprintf("  · gc_disable() spart %.0f%% – billigster Gewinn ohne Tool-Wechsel.\n", -$gcPct));
                else
                    $this->stdout(sprintf("  · gc_disable() ist %.0f%% langsamer (ungewöhnlich, nochmals messen).\n", $gcPct));
            }

            if (!empty($r['A']['ok']) && !empty($r['D']['ok'])) {
                $optPct = ($r['A']['median'] - $r['D']['median']) / $r['A']['median'] * 100;
                if ($optPct > 10)
                    $this->stdout(sprintf("  · Einmalige Quelloptimierung spart bei SetaPDF %.0f%%.\n", $optPct));
                elseif ($optPct < -5)
                    $this->stdout("  · Quelloptimierung hilft SetaPDF nicht (leicht langsamer – normal bei REWRITE_ALL).\n");
                else
                    $this->stdout("  · Quelloptimierung hat keinen wesentlichen Einfluss auf SetaPDF.\n");
            }

            if (!empty($r['A']['ok']) && !empty($r['C']['ok'])) {
                $factor  = $r['A']['median'] / $r['C']['median'];
                $savePct = ($r['A']['median'] - $r['C']['median']) / $r['A']['median'] * 100;
                $this->stdout(sprintf("  · qpdf-Overlay ist %.1f× schneller als SetaPDF heute (%.0f%% weniger Zeit).\n",
                    $factor, $savePct));
                if ($r['structOK'] === 'ja')
                    $this->stdout("  · Tags bleiben erhalten – qpdf ist für barrierefreie PDFs einsetzbar.\n");
                elseif ($r['structOK'] === 'NEIN')
                    $this->stdout("  · Tags gehen verloren – qpdf ohne Nachbesserung NICHT einsetzbar!\n");
            }

            if (empty($r['A']['ok']) && $firstErr) {
                $this->stdout("\n  ⚠ SetaPDF-Varianten konnten nicht gemessen werden.\n");
                $this->stdout("    Fehlermeldung: $firstErr\n");
                $this->stdout("    Häufigste Ursache: PDF_BEHAVIOR-Klassenname falsch (ganz oben in der Datei anpassen).\n");
            }
        }

        // Gesamt-Median (nur bei mehr als einer PDF sinnvoll)
        $agg = ['A' => [], 'B' => [], 'C' => [], 'D' => []];
        foreach ($rows as $r) foreach (['A', 'B', 'C', 'D'] as $k) if (!empty($r[$k]['ok'])) $agg[$k][] = $r[$k]['median'];
        if (count($rows) > 1 && array_filter($agg)) {
            $med = fn($k) => $agg[$k] ? number_format($this->median($agg[$k]), 0) . ' ms' : 'n/a';
            $this->stdout("\n$bar\n  GESAMT-MEDIAN über alle PDFs\n$sep\n");
            $this->stdout(sprintf("  %-30s  %10s\n", 'A  SetaPDF heute',         $med('A')));
            $this->stdout(sprintf("  %-30s  %10s\n", 'B  SetaPDF + gc_disable()', $med('B')));
            $this->stdout(sprintf("  %-30s  %10s\n", 'D  SetaPDF opt. Quelle',   $med('D')));
            $this->stdout(sprintf("  %-30s  %10s\n", 'C  qpdf-Overlay',          $med('C')));
            $this->stdout("$bar\n");
        }
    }
}