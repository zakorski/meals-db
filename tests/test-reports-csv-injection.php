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
            'first_name'       => '-2',
            'last_name'        => '@SUM(A1:A10)',
            'total_mains'      => 0,
            'total_sides'      => 0,
            'total_before_tax' => 0,
            'total_tax'        => 0,
            'final_total'      => 0,
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
assert_contains("\"'=1+1+cmd", $csv, "leading '=' in first_name is prefixed with a single quote");
assert_contains("'+2+2",       $csv, "leading '+' in last_name is prefixed");
assert_contains("'-2",         $csv, "leading '-' in first_name is prefixed");
assert_contains("'@SUM",       $csv, "leading '@' in last_name is prefixed");

// The raw, un-neutralised form must not appear as a leading cell value.
// A naive fputcsv would emit `=1+1+cmd...` — the leading `=` unescaped
// at the start of a cell.
assert_not_contains(",=1+1+cmd", $csv, "raw '=' at cell start is not present");
assert_not_contains(",+2+2",     $csv, "raw '+' at cell start is not present");
assert_not_contains(",-2,",      $csv, "raw '-' at cell start is not present");
assert_not_contains(",@SUM",     $csv, "raw '@' at cell start is not present");

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

// ---------------------------------------------------------------------------
// export_purchase_order_csv: product_name is user-supplied via WC.
// ---------------------------------------------------------------------------
$po = [[
    'sku'                 => 'ABC',
    'product_name'        => '=IMPORTXML("bad","//x")',
    'weighted_avg_weekly' => 0,
    'seasonal_index'      => 1.0,
    'adjusted_weekly'     => 0,
    'projected_need'      => 0,
    'buffer'              => 0,
    'qty_needed'          => 0,
    'current_stock'       => 0,
    'future_inventory'    => 0,
    'total_available'     => 0,
    'units_needed'        => 0,
    'case_size'           => 1,
    'cases_to_buy'        => 0,
    'order_quantity'      => 0,
    'seasonal_note'       => '',
]];
$csv = $reports->export_purchase_order_csv($po);

assert_contains("'=IMPORTXML", $csv, 'purchase-order export neutralises leading = in product_name');
assert_not_contains(',=IMPORTXML', $csv, 'raw =IMPORTXML at cell start is absent');

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
