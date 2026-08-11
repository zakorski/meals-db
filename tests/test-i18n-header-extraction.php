<?php
/**
 * Guard: admin table headers must be translated from STRING LITERALS, never
 * from a loop variable (audit T8 / i18n).
 *
 * The bug: several admin pages rendered their table headers with
 *   foreach ([...labels...] as $h) { echo '<th>' . esc_html__($h, 'meals-db') . '</th>'; }
 * Passing a runtime variable to esc_html__()/__() does NOT make the strings
 * translatable — the WP/​xgettext string-extraction tools only see STRING
 * LITERALS, so these labels never land in the .pot file and are never
 * translated (the call just returns the original, escaped). It is also
 * misleading: it looks localized but isn't.
 *
 * The fix wraps each label literal in __() at the array definition (so it is
 * extractable) and uses plain esc_html($h) in the loop. English output is
 * unchanged (an untranslated __() returns its source string).
 *
 * This is a full-file SOURCE ASSERTION (like test-reports-date-boundary T-3):
 *   1. none of the affected files pass a variable to a __()-family call;
 *   2. a sample of the header labels is present as an extractable __('...')
 *      literal in each file.
 *
 * Run: php tests/test-i18n-header-extraction.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

$failures = []; $passed = 0;
function chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = 'FAIL: ' . $label;
}

$base = dirname(__DIR__) . '/includes/admin/';

// 1. The anti-pattern — a translation call whose FIRST argument is a variable —
//    must not appear in any admin page. Matches __($x, _e($x, esc_html__($x,
//    esc_attr__($x (optionally with whitespace before the $).
$anti = '/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|_e|__)\(\s*\$/';

$files = [
    'class-invoice-draft-page.php',
    'class-event-log-page.php',
    'class-slip-batch-page.php',
];
foreach ($files as $f) {
    $src = (string) file_get_contents($base . $f);
    chk(!preg_match($anti, $src), "{$f}: no translation call takes a variable first arg");
}

// 2. A representative header label from each table is now an extractable literal.
$expect = [
    'class-invoice-draft-page.php' => ["__('Pipeline', 'meals-db')", "__('Finalized (UTC)', 'meals-db')"],
    'class-event-log-page.php'     => ["__('Occurred (UTC)', 'meals-db')", "__('When', 'meals-db')"],
    'class-slip-batch-page.php'    => ["__('Zone', 'meals-db')", "__('Delivery date', 'meals-db')"],
];
foreach ($expect as $f => $needles) {
    $src = (string) file_get_contents($base . $f);
    foreach ($needles as $needle) {
        chk(strpos($src, $needle) !== false, "{$f}: header literal present — {$needle}");
    }
}

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $x) { echo $x . "\n"; }
exit(empty($failures) ? 0 : 1);
