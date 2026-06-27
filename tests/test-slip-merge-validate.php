<?php
/**
 * Tests for the dompdf/Imagick-FREE logic of MealsDB_Slip_Merge (directive 03):
 * the doc 3 validation that gates the Combine button. Rasterization + overlay
 * are verified on the live host (Imagick/dompdf); here we pin validate_doc3 and
 * its PDF detection + page-count heuristic (the non-Imagick fallback path,
 * which is what runs when Imagick is absent — including this CLI).
 *
 *   V-1  a well-formed N-page PDF whose count matches → ok
 *   V-2  count mismatch → blocked, reason names both counts
 *   V-3  a non-PDF file → blocked ("not a PDF")
 *   V-4  an unreadable path → blocked
 *   V-5  a PDF with no detectable pages → blocked
 *
 * Run: php tests/test-slip-merge-validate.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($c, $l) { chk((bool) $c, true, $l); }

/** Minimal multi-page PDF: a real %PDF- header + N page objects. */
function make_pdf(string $tag, int $pages): string {
    $p = sys_get_temp_dir() . '/merge-' . $tag . '-' . getmypid() . '.pdf';
    $body = "%PDF-1.4\n";
    $body .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $kids = [];
    for ($i = 0; $i < $pages; $i++) {
        $kids[] = (3 + $i) . ' 0 R';
    }
    $body .= "2 0 obj\n<< /Type /Pages /Count {$pages} /Kids [" . implode(' ', $kids) . "] >>\nendobj\n";
    for ($i = 0; $i < $pages; $i++) {
        $n = 3 + $i;
        $body .= "{$n} 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\n";
    }
    $body .= "%%EOF\n";
    file_put_contents($p, $body);
    return $p;
}

$cleanup = [];

// V-1 — matching count.
$pdf2 = make_pdf('a', 2); $cleanup[] = $pdf2;
$r = MealsDB_Slip_Merge::validate_doc3($pdf2, 2);
chk($r['ok'], true, 'V-1 ok when count matches');
chk($r['page_count'], 2, 'V-1 page_count = 2');
chk($r['reason'], '', 'V-1 no reason on success');

// V-2 — mismatch.
$r = MealsDB_Slip_Merge::validate_doc3($pdf2, 3);
chk($r['ok'], false, 'V-2 blocked on mismatch');
chk($r['page_count'], 2, 'V-2 reports actual page count');
chk_true(strpos($r['reason'], '2') !== false && strpos($r['reason'], '3') !== false,
    'V-2 reason names both counts');

// V-3 — non-PDF.
$txt = sys_get_temp_dir() . '/merge-notpdf-' . getmypid() . '.txt';
file_put_contents($txt, "just text, not a pdf\n"); $cleanup[] = $txt;
$r = MealsDB_Slip_Merge::validate_doc3($txt, 1);
chk($r['ok'], false, 'V-3 non-PDF blocked');
chk_true(stripos($r['reason'], 'not a pdf') !== false, 'V-3 reason says not a PDF');

// V-4 — unreadable path.
$r = MealsDB_Slip_Merge::validate_doc3('/nonexistent/path/x.pdf', 1);
chk($r['ok'], false, 'V-4 missing file blocked');

// V-5 — PDF header but no page objects.
$empty = sys_get_temp_dir() . '/merge-empty-' . getmypid() . '.pdf';
file_put_contents($empty, "%PDF-1.4\n%%EOF\n"); $cleanup[] = $empty;
$r = MealsDB_Slip_Merge::validate_doc3($empty, 1);
chk($r['ok'], false, 'V-5 zero-page PDF blocked');
chk($r['page_count'], 0, 'V-5 page_count 0');

// A 5-page doc also counts correctly (heuristic robustness).
$pdf5 = make_pdf('e', 5); $cleanup[] = $pdf5;
$r = MealsDB_Slip_Merge::validate_doc3($pdf5, 5);
chk($r['ok'], true, 'V-6 five-page PDF validates');
chk($r['page_count'], 5, 'V-6 counts 5 pages');

foreach ($cleanup as $f) { @unlink($f); }

echo "\n=== MealsDB_Slip_Merge::validate_doc3 ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
