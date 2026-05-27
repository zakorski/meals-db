<?php
/**
 * Tests for MealsDB_Allocation_Engine::calculate_permitted_for_month —
 * the daily-rate requisition normalization (phase 0 of the billing overhaul).
 *
 *   permitted = floor( (allowance / days_in_period) * effective_days )
 *   days_in_period: day=1, week=7, month=days_in_month
 *
 * Run: php tests/test-permitted-normalization.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// WC_Order_Query type-hints wpdb; define a minimal base so our fake satisfies it.
if (!class_exists('wpdb')) { class wpdb {} }

// Minimal wpdb returning one configurable client row.
class PermWpdb extends wpdb {
    public $prefix = 'wp_';
    public array $client;
    public function __construct(array $c) { $this->client = $c; }
    public function prepare($q, ...$a) { if (count($a)===1 && is_array($a[0])) $a=$a[0]; $i=0; return preg_replace_callback('/%[ds]/', function($m) use (&$i,$a){ $v=$a[$i]??''; $i++; return $m[0]==='%s'?"'".$v."'":(string)(int)$v; }, $q); }
    public function get_row($q, $o=null) { return $this->client; }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) { global $failures, $passed; if ($got === $exp) { $passed++; } else { $failures[] = "$label: expected $exp got $got"; } }

function permitted(array $client, string $month): array {
    $GLOBALS['wpdb'] = new PermWpdb($client);
    $e = new MealsDB_Allocation_Engine();
    return $e->calculate_permitted_for_month(1, $month);
}

// helper to build a client row
function client($period, $mains, $sides, $commence=null, $term=null) {
    return [
        'allowance_mains' => $mains, 'allowance_sides' => $sides,
        'requisition_period' => $period,
        'service_commence_date' => $commence, 'termination_date' => $term,
    ];
}

// Jan 2025 = 31 days.
// day: 1/day -> floor(1/1 * 31) = 31
$r = permitted(client('day', 1, 0), '2025-01'); chk($r['permitted_mains'], 31, 'day 1/day mains=31');
// day: 2/day -> 62 (the real VAC multi-meal case)
$r = permitted(client('day', 2, 0), '2025-01'); chk($r['permitted_mains'], 62, 'day 2/day mains=62');
// week: 7/week -> floor(7/7 * 31) = 31
$r = permitted(client('week', 7, 0), '2025-01'); chk($r['permitted_mains'], 31, 'week 7/week mains=31');
// week: 14/week -> floor(14/7 * 31) = 62
$r = permitted(client('week', 14, 0), '2025-01'); chk($r['permitted_mains'], 62, 'week 14/week mains=62');
// week: 2/week -> floor(2/7 * 31) = floor(8.857) = 8
$r = permitted(client('week', 2, 0), '2025-01'); chk($r['permitted_mains'], 8, 'week 2/week mains=8 (floored)');
// week: 3/week -> floor(3/7 * 31) = floor(13.28) = 13
$r = permitted(client('week', 3, 0), '2025-01'); chk($r['permitted_mains'], 13, 'week 3/week mains=13 (floored)');
// month: 30/month over 31-day month -> floor(30/31 * 31) = 30
$r = permitted(client('month', 30, 0), '2025-01'); chk($r['permitted_mains'], 30, 'month 30/month mains=30');
// month: 31/month -> floor(31/31 * 31) = 31
$r = permitted(client('month', 31, 0), '2025-01'); chk($r['permitted_mains'], 31, 'month 31/month mains=31');

// mains and sides independent
$r = permitted(client('day', 1, 2), '2025-01');
chk($r['permitted_mains'], 31, 'independent mains=31');
chk($r['permitted_sides'], 62, 'independent sides=62');

// proration: 1/day, commence mid-month Jan 16 -> effective days 16..31 = 16 -> floor(1/1*16)=16
$r = permitted(client('day', 1, 0, '2025-01-16'), '2025-01'); chk($r['permitted_mains'], 16, 'proration commence mid-month=16');
// proration on a weekly client: 7/week, half month (16 days) -> floor(7/7*16)=16
$r = permitted(client('week', 7, 0, '2025-01-16'), '2025-01'); chk($r['permitted_mains'], 16, 'proration weekly half-month=16');

// unknown period -> 0
$r = permitted(client('fortnight', 5, 5), '2025-01'); chk($r['permitted_mains'], 0, 'unknown period mains=0');

// Feb (28 days): 30/month -> floor(30/28 * 28) = 30
$r = permitted(client('month', 30, 0), '2025-02'); chk($r['permitted_mains'], 30, 'Feb month 30/month=30');
// Feb: 1/day -> 28
$r = permitted(client('day', 1, 0), '2025-02'); chk($r['permitted_mains'], 28, 'Feb day 1/day=28');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
