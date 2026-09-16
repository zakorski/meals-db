<?php
/**
 * DIRECTIVE K16 — surface the real order entry time (_mealsdb_wallclock_created)
 * on the order edit screen and orders list, DISPLAY ONLY. date_created stays the
 * operator-entered order date (allocation/billing read it) and must NOT change.
 *
 * The timezone conversion and markup live in two pure helpers so they can be
 * tested without the permission / wc_get_order gating (which mirrors the
 * already-shipped delivery-date sibling). The column + sort methods are pure.
 *
 * Run: php tests/test-admin-ui-entered-time.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');

if (!function_exists('__'))          { function __($t, $d = null) { return $t; } }
if (!function_exists('esc_html'))    { function esc_html($t) { return $t; } }
if (!function_exists('esc_attr'))    { function esc_attr($t) { return $t; } }
if (!function_exists('esc_html__'))  { function esc_html__($t, $d = null) { return $t; } }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)); } }
if (!function_exists('wp_unslash'))  { function wp_unslash($v) { return $v; } }
if (!function_exists('get_option'))  {
    function get_option($k, $d = false) {
        return ['date_format' => 'M j, Y', 'time_format' => 'g:i a'][$k] ?? $d;
    }
}
// wp_date() formats an absolute Unix timestamp in the SITE timezone. The site
// runs America/Halifax (UTC-3 in September DST), so a 12:33 UTC stamp is 9:33 am.
if (!function_exists('wp_date')) {
    function wp_date($format, $ts = null, $tz = null) {
        $d = new DateTime('@' . (int) $ts);
        $d->setTimezone(new DateTimeZone('America/Halifax'));
        return $d->format($format);
    }
}

// WC_Order stub: serves _mealsdb_wallclock_created and records any attempt to
// change date_created (the regression that matters — Test 6).
if (!class_exists('WC_Order')) {
    class WC_Order {
        public array $meta = [];
        public int $set_date_created_calls = 0;
        public function __construct(array $meta = []) { $this->meta = $meta; }
        public function get_id() { return 4242; }
        public function get_customer_id() { return 7; }
        public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
        public function set_date_created($v) { $this->set_date_created_calls++; }
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
require_once __DIR__ . '/../includes/class-admin-ui.php';

$failures = []; $passed = 0;
function chk($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}
function chk_contains($label, $needle, $haystack): void {
    global $failures, $passed;
    if (is_string($haystack) && strpos($haystack, $needle) !== false) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected to contain: %s\n  actual: %s", $label,
        var_export($needle, true), var_export($haystack, true));
}

$ui  = (new ReflectionClass('MealsDB_Admin_UI'))->newInstanceWithoutConstructor();
$fmt = new ReflectionMethod('MealsDB_Admin_UI', 'format_entered_time_for_display');
$fmt->setAccessible(true);
$build = new ReflectionMethod('MealsDB_Admin_UI', 'build_entered_time_field_html');
$build->setAccessible(true);

// K16 Test 1: a UTC stamp renders in SITE time. 12:33 UTC → 9:33 am Halifax.
// FAILS against v1.0.580 (method does not exist).
chk_contains('K16-1 UTC 12:33 renders as 9:33 am site time',
    '9:33 am', $fmt->invoke($ui, '2026-09-16 12:33:00'));

// K16 Test 2: absent stamp → empty string (no label, no em dash).
chk('K16-2 empty stamp formats to empty', '', $fmt->invoke($ui, ''));

// K16 Test 2b: the edit-screen field builder renders nothing when meta absent.
$order_none = new WC_Order([]);
chk('K16-2b field builder empty when meta absent', '', $build->invoke($ui, $order_none));

// K16 Test 2c: the field builder renders the labelled read-only value when present.
$order_stamped = new WC_Order(['_mealsdb_wallclock_created' => '2026-09-16 12:33:00']);
$field_html = $build->invoke($ui, $order_stamped);
chk_contains('K16-2c field has the distinct label', 'Entered (Meals DB)', $field_html);
chk_contains('K16-2c field shows site time', '9:33 am', $field_html);
// Test 6 (field): building the field never touches date_created.
chk('K16-6 field build does not call set_date_created', 0, $order_stamped->set_date_created_calls);

// K16 Test 3: list column renders the time for a stamped order, empty for one without.
ob_start();
$ui->render_entered_time_order_column('mealsdb_entered_time', $order_stamped);
$col_stamped = ob_get_clean();
chk_contains('K16-3 column renders site time for stamped order', '9:33 am', $col_stamped);

ob_start();
$ui->render_entered_time_order_column('mealsdb_entered_time', $order_none);
$col_empty = ob_get_clean();
chk('K16-3 column empty for unstamped order (no date_created fallback)', '', $col_empty);
chk('K16-6 column render does not call set_date_created', 0, $order_none->set_date_created_calls);

// Wrong column name → renders nothing.
ob_start();
$ui->render_entered_time_order_column('some_other_column', $order_stamped);
chk('K16-3 column ignores other columns', '', ob_get_clean());

// K16 column ordering: Order Date → Entered → Delivery Date.
$cols = $ui->add_entered_time_order_column([
    'order_date'            => 'Order Date',
    'mealsdb_delivery_date' => 'Delivery Date',
    'order_total'           => 'Total',
]);
$keys = array_keys($cols);
$pos_order    = array_search('order_date', $keys, true);
$pos_entered  = array_search('mealsdb_entered_time', $keys, true);
$pos_delivery = array_search('mealsdb_delivery_date', $keys, true);
chk('K16 entered column exists', true, $pos_entered !== false);
chk('K16 entered sits after Order Date', true, $pos_entered > $pos_order);
chk('K16 entered sits before Delivery Date', true, $pos_entered < $pos_delivery);

// K16 sort registration.
$sortable = $ui->register_entered_time_sortable_column([]);
chk('K16 entered column is sortable', 'mealsdb_entered_time', $sortable['mealsdb_entered_time'] ?? null);

// K16 Test 4/5: sort query args. Lexical meta_value sort on the zero-padded
// Y-m-d H:i:s string is chronologically correct; meta_key + orderby=meta_value
// (NOT an EXISTS meta_query) keeps unstamped orders in the list.
$_GET['orderby'] = 'mealsdb_entered_time';
$_GET['order']   = 'asc';
$args_asc = $ui->sort_orders_by_entered_time([]);
chk('K16-4 sort sets meta_key', '_mealsdb_wallclock_created', $args_asc['meta_key'] ?? null);
chk('K16-4 sort uses meta_value (lexical, chronological)', 'meta_value', $args_asc['orderby'] ?? null);
chk('K16-4 ascending honoured', 'ASC', $args_asc['order'] ?? null);
chk('K16-5 no EXISTS meta_query that would hide unstamped orders', false, isset($args_asc['meta_query']));

$_GET['order'] = 'desc';
$args_desc = $ui->sort_orders_by_entered_time([]);
chk('K16-4 descending honoured', 'DESC', $args_desc['order'] ?? null);

// A different orderby must pass through untouched.
$_GET['orderby'] = 'order_total';
chk('K16 unrelated orderby untouched', ['foo' => 'bar'], $ui->sort_orders_by_entered_time(['foo' => 'bar']));
unset($_GET['orderby'], $_GET['order']);

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
