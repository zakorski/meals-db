<?php
/**
 * Regression test for DIRECTIVE-gui-minor-fixes FIX 2: combine() must
 * distinguish "the rasterizer produced NO pages" (tool unavailable) from a
 * genuine page-count mismatch.
 *
 * Before the fix, combine() collapsed every non-match — including 0 pages —
 * into the event `combine.page_count_mismatch` with the misleading message
 * "Doc 3 rasterized to 0 page(s); batch has N order(s)". After the fix:
 *   - 0 pages produced  → event `combine.rasterize_failed`, and a user-facing
 *     reason is exposed via MealsDB_Slip_Merge::last_error_reason() pointing
 *     at the unavailable PDF tool / manual Doc 4 fallback.
 *   - >0 but != count   → event `combine.page_count_mismatch` (unchanged).
 *
 * This runs on the CLI precisely BECAUSE Imagick is absent here: the real
 * rasterize_doc3() returns [] (logging rasterize.no_imagick), so combine()
 * naturally exercises the zero-pages branch — the exact scenario the test box
 * hit. (The >0-but-mismatch branch needs Imagick and is covered live.)
 *
 * Run: php tests/test-slip-merge-rasterize-failed.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

// Capturing stub for the Event Log — declared BEFORE the autoloader can load
// the real class, so class_exists('MealsDB_Event_Log') is true and record()
// is captured instead of hitting the DB.
class MealsDB_Event_Log {
    public const OUTCOME_SUCCEEDED = 'succeeded';
    public const OUTCOME_FAILED    = 'failed';
    public const OUTCOME_DEGRADED  = 'degraded';
    public const OUTCOME_RUNNING   = 'running';
    public static array $records = [];
    public static function record(array $e): int {
        self::$records[] = $e;
        return count(self::$records);
    }
    public static function events(): array {
        return array_map(static fn($r) => $r['event'] ?? '', self::$records);
    }
}

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($c, $l) { chk((bool) $c, true, $l); }

/** Minimal multi-page PDF (real %PDF- header + N page objects). */
function make_pdf(string $tag, int $pages): string {
    $p = sys_get_temp_dir() . '/rfail-' . $tag . '-' . getmypid() . '.pdf';
    $body = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $kids = [];
    for ($i = 0; $i < $pages; $i++) { $kids[] = (3 + $i) . ' 0 R'; }
    $body .= "2 0 obj\n<< /Type /Pages /Count {$pages} /Kids [" . implode(' ', $kids) . "] >>\nendobj\n";
    for ($i = 0; $i < $pages; $i++) { $n = 3 + $i; $body .= "{$n} 0 obj\n<< /Type /Page /Parent 2 0 R >>\nendobj\n"; }
    $body .= "%%EOF\n";
    file_put_contents($p, $body);
    return $p;
}

if (class_exists('Imagick')) {
    echo "SKIP — Imagick present; this test asserts the no-rasterizer branch.\n";
    exit(0);
}

$pdf = make_pdf('a', 2);
$orders = [['x' => 1], ['x' => 2]]; // 2 orders, 2-page doc — would MATCH if rasterized.

MealsDB_Event_Log::$records = [];
$out = MealsDB_Slip_Merge::combine($orders, $pdf);

// R-1 — combine still returns the '' failure sentinel.
chk($out, '', 'R-1 combine returns empty string on rasterize failure');

// R-2 — the honest event is logged.
chk_true(in_array('combine.rasterize_failed', MealsDB_Event_Log::events(), true),
    'R-2 logs combine.rasterize_failed when zero pages produced');

// R-3 — it does NOT mislabel zero-pages as a page-count mismatch.
chk_true(!in_array('combine.page_count_mismatch', MealsDB_Event_Log::events(), true),
    'R-3 does NOT log combine.page_count_mismatch on zero pages');

// R-4 — a user-facing reason is exposed for the AJAX layer to surface.
if (!method_exists('MealsDB_Slip_Merge', 'last_error_reason')) {
    $failures[] = 'R-4 MealsDB_Slip_Merge::last_error_reason() does not exist yet';
} else {
    $reason = MealsDB_Slip_Merge::last_error_reason();
    chk_true(is_string($reason) && $reason !== '', 'R-4 last_error_reason() returns a non-empty string');
}

@unlink($pdf);

echo "\n=== MealsDB_Slip_Merge::combine rasterize-failed branch ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
