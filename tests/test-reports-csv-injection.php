<?php
/**
 * Formula-injection tests for MealsDB_Reports CSV exporters.
 *
 * Locks in the H2 guarantee: every cell flows through MealsDB_CSV::cell(),
 * so a client name or product name starting with =, +, -, @, tab, or CR
 * receives a leading single quote and is no longer interpreted as a
 * spreadsheet formula by Excel / Numbers / Sheets.
 *
 * Run with: php tests/test-reports-csv-injection.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $t, $v, ...$a) { return $v; } }

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = 'OBJECT') { return []; }
        public function get_row($query, $output = 'OBJECT', $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
    }
}

$failures = [];
$passed   = 0;
function assert_contains(string $needle, string $haystack, string $label) {
    global $failures, $passed;
    if (strpos($haystack, $needle) !== false) { $passed++; return; }
    $failures[] = "FAIL: $label (expected substring " . var_export($needle, true) . ")\n  CSV was:\n" . $haystack;
}
function assert_not_contains(string $needle, string $haystack, string $label) {
    global $failures, $passed;
    if (strpos($haystack, $needle) === false) { $passed++; return; }
    $failures[] = "FAIL: $label (unexpectedly found " . var_export($needle, true) . ")\n  CSV was:\n" . $haystack;
}
function assert_same(string $expected, string $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = "FAIL: $label (expected " . var_export($expected, true)
        . ", got " . var_export($actual, true) . ")";
}

$reports = new MealsDB_Reports(new wpdb());

// ---------------------------------------------------------------------------
// export_private_report_csv: malicious first_name prefixed with =, +, -, @.
// ---------------------------------------------------------------------------
$payload = [
    'rows' => [
        [
            'first_name'       => '=1+1+cmd|"/C calc"!A1',
            'last_name'        => '+2+2',
            'total_mains'      => 0,
            'total_sides'      => 0,
            'total_before_tax' => 0,
            'total_tax'        => 0,
            'final_total'      => 0,
        ],
        [
            // A NAME that is a leading-'-' formula (not a plain number): this is
            // a genuine injection vector and must STILL be neutralised after
            // QW-3 — the numeric exemption only applies to well-formed numbers.
            'first_name'       => '-2+3',
            'last_name'        => '@SUM(A1:A10)',
            'total_mains'      => 0,
            'total_sides'      => 0,
            'total_before_tax' => 0,
            'total_tax'        => 0,
            // QW-3 (audit MAJ-3): a legitimate NEGATIVE money amount in a
            // numeric column must export intact as -10.24, NOT be corrupted
            // into the text '-10.24 by the formula guard. number_format() in
            // the exporter emits the leading '-' that previously tripped it.
            'final_total'      => -10.24,
        ],
    ],
    'grand_totals' => [
        'total_mains'      => 0,
        'total_sides'      => 0,
        'total_before_tax' => 0,
        'total_tax'        => 0,
        'final_total'      => 0,
    ],
];

$csv = $reports->export_private_report_csv($payload);

// Each malicious prefix must be preceded by a single-quote. The cell
// also contains characters (comma, quote) that require RFC-4180 quoting,
// so the single-quote appears right after the opening " character.
// TEXT fields: a leading =, +, @, or a '-' that begins a NON-numeric string
// (e.g. the formula "-2+3") must still be neutralised with a single quote.
assert_contains("\"'=1+1+cmd", $csv, "leading '=' in first_name is prefixed with a single quote");
assert_contains("'+2+2",       $csv, "leading '+' in last_name is prefixed");
assert_contains("'-2+3",       $csv, "leading '-' on a NON-numeric name (formula) is prefixed");
assert_contains("'@SUM",       $csv, "leading '@' in last_name is prefixed");

// The raw, un-neutralised formula form must not appear as a leading cell value.
// A naive fputcsv would emit `=1+1+cmd...` — the leading `=` unescaped
// at the start of a cell.
assert_not_contains(",=1+1+cmd", $csv, "raw '=' at cell start is not present");
assert_not_contains(",+2+2",     $csv, "raw '+' at cell start is not present");
assert_not_contains(",-2+3",     $csv, "raw '-2+3' formula at cell start is not present");
assert_not_contains(",@SUM",     $csv, "raw '@' at cell start is not present");

// ---------------------------------------------------------------------------
// QW-3 numeric exemption: the distinction is numeric-VALUE vs text-FIELD.
// A well-formed number (incl. negative money) is not a formula and must NOT
// be prefixed — otherwise government-bound CSVs report '-10.24 as text in a
// money column. Previously the '-' guard corrupted exactly this (audit MAJ-3).
// ---------------------------------------------------------------------------
assert_contains(",-10.24",   $csv, "negative money exports intact (unprefixed) in a numeric column");
assert_not_contains("'-10.24", $csv, "negative money is NOT corrupted into text with a leading quote");

// Direct unit-level proof of the numeric-vs-text rule on MealsDB_CSV::cell().
assert_same('-10.24',  MealsDB_CSV::cell('-10.24'),  'cell(): negative number passes through unquoted');
assert_same('+15',     MealsDB_CSV::cell('+15'),     'cell(): positive-signed number unquoted');
assert_same('-5',      MealsDB_CSV::cell('-5'),      'cell(): negative integer unquoted');
assert_same("'-2+3",   MealsDB_CSV::cell('-2+3'),    'cell(): minus-then-formula stays quoted');
assert_same("'=SUM(A1)", MealsDB_CSV::cell('=SUM(A1)'), 'cell(): = formula stays quoted');
assert_same('1024',    MealsDB_CSV::cell('1024'),    'cell(): plain integer unchanged');
// A thousands-separated value is NOT matched by the strict numeric regex, so it
// gets the formula quote; the embedded comma then triggers RFC-4180 wrapping.
// (Conservative + correct: the exporter emits raw numbers without commas.)
assert_same("\"'-1,024.50\"", MealsDB_CSV::cell('-1,024.50'), 'cell(): comma-grouped value stays quoted (not plain numeric)');

// ---------------------------------------------------------------------------
// export_to_csv: header row AND body rows must both be neutralised.
// A hostile caller could supply a report where the HEADER row is
// attacker-controlled (e.g. product custom-attribute names), so headers
// must be neutralised too.
// ---------------------------------------------------------------------------
$rows_generic = [
    ['=EVIL' => 'ok', 'name' => '@payload'],
];
$csv = $reports->export_to_csv($rows_generic);

assert_contains("'=EVIL", $csv, 'generic export neutralises leading = in headers');
assert_contains("'@payload", $csv, 'generic export neutralises leading @ in body values');

// NOTE: the PHP export_purchase_order_csv() serializer was removed as dead code
// (audit T8 dead-code sweep) — the live PO CSV is now built client-side in
// purchase-orders.js via Report.csvRow/csvCell (report-utils.js). Its formula-
// injection neutralisation is a JS-side concern; the shared server-side guard
// MealsDB_CSV::row() remains covered by the generic export_to_csv case above.

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}

fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
