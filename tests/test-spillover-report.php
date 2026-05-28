<?php
/**
 * Tests for MealsDB_Reports::spillover_report — phase 3.
 *
 * Exercises:
 *   - A single-month spill (order delivered in month, with rows in both
 *     this month and next month) is listed.
 *   - A multi-month-spillover error (logged by rebuilder) is listed and
 *     flagged is_multi_month_error=true.
 *   - Empty month returns empty list.
 *   - CSV export contains expected headers + rows.
 *
 * Run: php tests/test-spillover-report.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

// WP function stubs so MealsDB_Reports' defence-in-depth capability gate
// (is_authorized_to_read_reports -> MealsDB_Permissions::can_access_plugin)
// can run without a full WP stack. An authorized user is simulated.
if (!function_exists('is_user_logged_in')) { function is_user_logged_in(): bool { return true; } }
if (!function_exists('current_user_can'))  { function current_user_can($c): bool { return true; } }
if (!function_exists('apply_filters'))      { function apply_filters($tag, $value, ...$args) { return $value; } }

class SpillWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $scripted = []; // pattern => result for get_results
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function get_results($q, $o = null) {
        foreach ($this->scripted as $pat => $res) {
            if (stripos($q, $pat) !== false) { return $res; }
        }
        return [];
    }
    public function get_var($q)            { return null; }
    public function get_row($q, $o = null) { return null; }
    public function get_col($q)            { return []; }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; } else {
        $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true);
    }
}

// ----- Test 1: a single-month spill is listed (no error) -----
$wpdb = new SpillWpdb();
$wpdb->scripted = [
    // The single-month spill query joins delivery_allocations to itself.
    "INNER JOIN `wp_meals_delivery_allocations` a2" => [
        [
            'client_id'      => 42,
            'wc_order_id'    => 999,
            'delivery_date'  => '2025-01-28',
            'mains_in_month' => 4,
            'sides_in_month' => 0,
            'mains_spilled'  => 10,
            'sides_spilled'  => 0,
            'first_name'     => 'Heavy',
            'last_name'      => 'Orderer',
        ],
    ],
    // No errors in this test.
    "FROM `wp_meals_allocation_errors` e" => [],
];
$reports = new MealsDB_Reports($wpdb);
$rows = $reports->spillover_report('2025-01');
chk(count($rows), 1, 'single-spill: one row');
chk($rows[0]['client_id'], 42, 'single-spill: client_id');
chk((bool) $rows[0]['is_multi_month_error'], false, 'single-spill: not flagged as error');
chk($rows[0]['mains_in_month'], 4, 'single-spill: 4 mains in Jan');
chk($rows[0]['mains_spilled'], 10, 'single-spill: 10 mains spilled');
chk($rows[0]['client_name'], 'Heavy Orderer', 'single-spill: client name assembled');

// ----- Test 2: a multi-month-spillover error is listed and flagged -----
$wpdb = new SpillWpdb();
$wpdb->scripted = [
    "INNER JOIN `wp_meals_delivery_allocations` a2" => [],
    "FROM `wp_meals_allocation_errors` e" => [
        [
            'client_id'      => 99,
            'wc_order_id'    => 1500,
            'mains_unplaced' => 6,
            'sides_unplaced' => 0,
            'message'        => 'Delivery 2025-01-15 could not fit in 2025-01 or 2025-02. Mains short 6, sides short 0.',
            'delivery_date'  => '2025-01-15',
            'mains_in_month' => 5,
            'sides_in_month' => 0,
            'first_name'     => 'Misconfigured',
            'last_name'      => 'Client',
        ],
    ],
];
$reports = new MealsDB_Reports($wpdb);
$rows = $reports->spillover_report('2025-01');
chk(count($rows), 1, 'multi-month-error: one row');
chk((bool) $rows[0]['is_multi_month_error'], true, 'multi-month-error: flagged');
chk($rows[0]['mains_spilled'], 6, 'multi-month-error: 6 mains unplaced');
chk(strpos($rows[0]['error_message'], 'could not fit') !== false, true, 'multi-month-error: message carried');

// ----- Test 3: both kinds together (spill + error) -----
$wpdb = new SpillWpdb();
$wpdb->scripted = [
    "INNER JOIN `wp_meals_delivery_allocations` a2" => [
        ['client_id' => 1, 'wc_order_id' => 11, 'delivery_date' => '2025-01-05',
         'mains_in_month' => 8, 'sides_in_month' => 0, 'mains_spilled' => 2, 'sides_spilled' => 0,
         'first_name' => 'A', 'last_name' => 'B'],
    ],
    "FROM `wp_meals_allocation_errors` e" => [
        ['client_id' => 2, 'wc_order_id' => 22, 'mains_unplaced' => 3, 'sides_unplaced' => 0,
         'message' => 'err', 'delivery_date' => '2025-01-20',
         'mains_in_month' => 5, 'sides_in_month' => 0, 'first_name' => 'C', 'last_name' => 'D'],
    ],
];
$reports = new MealsDB_Reports($wpdb);
$rows = $reports->spillover_report('2025-01');
chk(count($rows), 2, 'mixed: 2 rows total');
$errors = array_filter($rows, function ($r) { return $r['is_multi_month_error']; });
chk(count($errors), 1, 'mixed: exactly one error row');

// ----- Test 4: empty month returns empty list -----
$wpdb = new SpillWpdb();
$wpdb->scripted = [];
$reports = new MealsDB_Reports($wpdb);
$rows = $reports->spillover_report('2025-06');
chk(count($rows), 0, 'empty month: no rows');

// ----- Test 5: invalid month returns empty -----
$reports = new MealsDB_Reports(new SpillWpdb());
chk(count($reports->spillover_report('not-a-month')), 0, 'invalid month: rejected');
chk(count($reports->spillover_report('2025')), 0, 'invalid month: short string rejected');
// Impossible month numbers must be rejected before DateTime sees them:
// 2025-13 would throw (500) and 2025-00 would normalise to 2024-12.
chk(count($reports->spillover_report('2025-13')), 0, 'invalid month: month > 12 rejected');
chk(count($reports->spillover_report('2025-00')), 0, 'invalid month: month 00 rejected');

// ----- Test 6: CSV export header + content -----
$rows = [
    [
        'client_id' => 42, 'client_name' => 'Heavy Orderer', 'wc_order_id' => 999,
        'delivery_date' => '2025-01-28', 'mains_in_month' => 4, 'sides_in_month' => 0,
        'mains_spilled' => 10, 'sides_spilled' => 0,
        'is_multi_month_error' => false, 'error_message' => null,
    ],
];
$csv = (new MealsDB_Reports(new SpillWpdb()))->export_spillover_csv($rows);
$lines = explode("\n", $csv);
chk(count($lines), 2, 'csv: header + 1 data row');
chk(strpos($lines[0], 'Delivery Date') !== false, true, 'csv: header has Delivery Date');
chk(strpos($lines[0], 'Multi-Month Error') !== false, true, 'csv: header has Multi-Month Error column');
chk(strpos($lines[1], 'Heavy Orderer') !== false, true, 'csv: data row has client name');
chk(strpos($lines[1], 'No') !== false, true, 'csv: error flag rendered as No for non-error');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
