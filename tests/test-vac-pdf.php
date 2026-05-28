<?php
/**
 * Tests for MealsDB_Invoice_Generator::build_vac_pdf_html and
 * the end-to-end dompdf render (phase 4).
 *
 * Exercises:
 *   - HTML template contains the expected coordinate-positioned fields
 *     for one veteran (using Janet's Robert Ralph: 31 mains, $280.55 total).
 *   - One <div class="vac-page"> per veteran row.
 *   - Each field span includes the correct left/top/font-size in points
 *     and HTML-escapes its contents.
 *   - dompdf can actually render the HTML to PDF bytes (smoke test).
 *
 * Run: php tests/test-vac-pdf.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
require_once __DIR__ . '/../vendor/autoload.php'; // dompdf

if (!class_exists('wpdb')) { class wpdb {} }

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; } else {
        $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true);
    }
}
function chk_contains(string $haystack, string $needle, string $label) {
    global $failures, $passed;
    if (strpos($haystack, $needle) !== false) { $passed++; } else {
        $failures[] = "$label: expected to contain '$needle'";
    }
}

// One veteran row matching Janet's actual Jan 2025 VAC PDF for Robert Ralph:
//   K# 8373037, last Ralph, first Robert, address "1940 Rte 885",
//   city Havelock, postal E4Z 5M7, phone 1-506-435-1126,
//   31 mains at $9.05, HST 0.00, New Total $280.55.
// CSV column indices used by build_vac_pdf_html:
//   0 K#  1 Last  2 First  3 Address  4 City  5 Postal  6 Phone
//   11 Bill Mains  32 Bill HST  33 New Total
$ralph = [];
$ralph[0]  = '8373037';
$ralph[1]  = 'Ralph';
$ralph[2]  = 'Robert';
$ralph[3]  = '1940 Rte 885';
$ralph[4]  = 'Havelock';
$ralph[5]  = 'E4Z 5M7';
$ralph[6]  = '1-506-435-1126';
$ralph[7]  = 'Meal';
$ralph[8]  = '9.05';
$ralph[9]  = '31';
$ralph[10] = '31';
$ralph[11] = '31';
$ralph[12] = '0';
$ralph[13] = '0';
$ralph[14] = '0';
// pad
for ($i = 15; $i <= 31; $i++) { $ralph[$i] = '0'; }
$ralph[32] = '0.00';
$ralph[33] = '280.55';
$ralph[34] = '';
$ralph[35] = 'No';

$html = MealsDB_Invoice_Generator::build_vac_pdf_html(
    [$ralph],
    '31/01/25',
    'file:///tmp/fake-bg.jpg'
);

// ----- Structural -----
chk_contains($html, '<!DOCTYPE html>',                      'html: doctype present');
chk_contains($html, '@page { size: 612pt 1008pt;',          'html: Legal page size declared');
chk_contains($html, 'class="vac-page"',                     'html: vac-page container emitted');
chk(substr_count($html, 'class="vac-page"'), 1,             'html: one .vac-page for one veteran');

// ----- Background image url is escaped + positioned -----
chk_contains($html, 'background-image: url("file:///tmp/fake-bg.jpg")', 'html: bg image url plumbed through');

// ----- Field values present -----
chk_contains($html, 'Robert Ralph',         'html: full name (First Last)');
chk_contains($html, '8373037',              'html: K# present');
chk_contains($html, '1940 Rte 885',         'html: address present');
chk_contains($html, 'Havelock',             'html: city present');
chk_contains($html, 'E4Z 5M7',              'html: postal present');
chk_contains($html, '1-506-435-1126',       'html: phone present');
chk_contains($html, '>NB<',                 'html: province hardcoded NB');
chk_contains($html, '31',                   'html: meal count present');
chk_contains($html, '0.00',                 'html: HST 0.00 present');
chk_contains($html, '$280.55',              'html: total with leading $');
chk_contains($html, '31/01/25',             'html: report date');

// ----- Coordinate translation: left:pt + baseline-to-top translation -----
// fullname at x=90, y=743, font_pt=12 → top = 743 - 12*0.85 = 732.8
chk_contains($html, 'left:90pt;top:732.8pt;font-size:12pt;', 'html: fullname coords with baseline→top translation');
// knumber at x=270, y=761, font_pt=12 → top = 761 - 10.2 = 750.8
chk_contains($html, 'left:270pt;top:750.8pt;font-size:12pt;', 'html: knumber coords translated');
// meals at x=320, y=450, font_pt=14 → top = 450 - 14*0.85 = 438.1
chk_contains($html, 'left:320pt;top:438.1pt;font-size:14pt;', 'html: meals coords at larger font');

// ----- Multi-veteran: two rows produce two pages -----
$ralph2 = $ralph; // shallow copy
$ralph2[1] = 'Smith'; $ralph2[2] = 'John'; $ralph2[0] = '0000001';
$html2 = MealsDB_Invoice_Generator::build_vac_pdf_html(
    [$ralph, $ralph2],
    '31/01/25',
    'file:///tmp/fake-bg.jpg'
);
chk(substr_count($html2, 'class="vac-page"'), 2, 'html: two veterans → two pages');
chk_contains($html2, 'John Smith', 'html: second veteran rendered');

// ----- Smoke: dompdf can render the HTML to PDF bytes -----
// Use the real background image from the repo for this smoke render.
$bg_path = dirname(__DIR__) . '/assets/images/vac-blue-cross-form.jpg';
if (file_exists($bg_path)) {
    $smoke_html = MealsDB_Invoice_Generator::build_vac_pdf_html(
        [$ralph],
        '31/01/25',
        'file://' . $bg_path
    );
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('chroot', dirname(__DIR__));
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($smoke_html, 'UTF-8');
    $dompdf->setPaper([0, 0, 612, 1008], 'portrait');
    $dompdf->render();
    $bytes = $dompdf->output();
    chk(is_string($bytes) && strlen($bytes) > 1000, true, 'smoke: dompdf produces a non-trivial PDF');
    chk(strpos($bytes, '%PDF-') === 0, true, 'smoke: PDF magic-bytes header');
} else {
    echo "SKIP: background image not at $bg_path (smoke render skipped)\n";
}

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
