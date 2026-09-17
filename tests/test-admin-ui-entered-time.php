<?php
/**
 * DIRECTIVE K18 — the order-edit box displays and EDITS the real entry time
 * (_mealsdb_wallclock_created) while date_created keeps ending in 00:00:00
 * (billing reads its UTC date). Core's hour/minute inputs are hidden; a separate
 * time field is bound to the wall-clock meta; the orders-list Date column shows
 * date + entry time. Supersedes K16's read-only field + Entered column.
 *
 * Run: php tests/test-admin-ui-entered-time.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');

if (!function_exists('__'))          { function __($t, $d = null) { return $t; } }
if (!function_exists('esc_html'))    { function esc_html($t) { return $t; } }
if (!function_exists('esc_attr'))    { function esc_attr($t) { return $t; } }
if (!function_exists('esc_html__'))  { function esc_html__($t, $d = null) { return $t; } }
if (!function_exists('esc_html_e'))  { function esc_html_e($t, $d = null) { echo $t; } }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $k)); } }
if (!function_exists('wp_unslash'))  { function wp_unslash($v) { return $v; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($a = -1, $n = '_wpnonce', $r = true, $e = true) { echo ''; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v) { return $v; } }
if (!function_exists('wp_timezone')) { function wp_timezone() { return new DateTimeZone('America/Halifax'); } }
if (!function_exists('get_option'))  {
    function get_option($k, $d = false) { return ['date_format' => 'M j, Y', 'time_format' => 'g:i a'][$k] ?? $d; }
}
if (!function_exists('wp_date')) {
    function wp_date($format, $ts = null, $tz = null) {
        $d = new DateTime('@' . (int) $ts);
        $d->setTimezone(new DateTimeZone('America/Halifax'));
        return $d->format($format);
    }
}
if (!function_exists('date_i18n')) { function date_i18n($format, $ts) { return date($format, (int) $ts); } }
// Controllable nonce validity.
$GLOBALS['NONCE_OK'] = true;
if (!function_exists('wp_verify_nonce')) { function wp_verify_nonce($n, $a) { return !empty($GLOBALS['NONCE_OK']); } }

// Permissions stub: allow.
if (!class_exists('MealsDB_Permissions')) {
    // Real class is autoloaded; but ensure can_access_plugin returns true here.
}

// A WC_DateTime-ish stub: constructed from a UTC 'Y-m-d H:i:s'.
if (!class_exists('WC_DateTime')) {
    class WC_DateTime {
        private $ts;
        public function __construct($utc) { $this->ts = strtotime($utc . ' UTC'); }
        public function getTimestamp() { return $this->ts; }
        public function format($f) { return gmdate($f, $this->ts); }
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        public array $meta = [];
        public array $saved_meta = [];
        public int $set_date_created_calls = 0;
        private $created_utc;
        public function __construct(array $meta = [], string $created_utc = '2026-09-17 00:00:00') {
            $this->meta = $meta; $this->created_utc = $created_utc;
        }
        public function get_id() { return 4242; }
        public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
        public function get_date_created() { return new WC_DateTime($this->created_utc); }
        public function update_meta_data($key, $value) { $this->meta[$key] = $value; $this->saved_meta[$key] = $value; }
        public function delete_meta_data($key) { unset($this->meta[$key]); }
        public function set_date_created($v) { $this->set_date_created_calls++; }
        public function save() { return true; }
    }
}
if (!function_exists('wc_get_order')) { function wc_get_order($id) { return $GLOBALS['OA_ORDER'] ?? null; } }
if (!function_exists('get_current_screen')) {
    function get_current_screen() {
        if (empty($GLOBALS['MDB_SCREEN'])) { return null; }
        return (object) ['id' => $GLOBALS['MDB_SCREEN']];
    }
}

// Minimal $wpdb so MealsDB_Logger::log()'s audit write is a harmless no-op
// (the entered-time save audit-logs; these tests aren't about that write).
if (!class_exists('wpdb')) { class wpdb { public $prefix = 'wp_'; public $insert_id = 0; public $last_error = '';
    public function prepare($q, ...$a) { return $q; } public function query($q) { return 1; }
    public function insert($t, $d, $f = null) { return 1; } public function get_var($q) { return 0; } } }
$GLOBALS['wpdb'] = new wpdb();

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
require_once __DIR__ . '/../includes/class-admin-ui.php';

$failures = []; $passed = 0;
function chk($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($expected, true), var_export($actual, true));
}
function chk_contains($label, $needle, $haystack): void {
    global $failures, $passed;
    if (is_string($haystack) && strpos($haystack, $needle) !== false) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected to contain: %s\n  actual: %s", $label, var_export($needle, true), var_export($haystack, true));
}

$ui  = (new ReflectionClass('MealsDB_Admin_UI'))->newInstanceWithoutConstructor();
$fmt = new ReflectionMethod('MealsDB_Admin_UI', 'format_entered_time_for_display'); $fmt->setAccessible(true);
$inp = new ReflectionMethod('MealsDB_Admin_UI', 'entered_time_value_for_input'); $inp->setAccessible(true);

// K18-3: our field/input populates in SITE time. UTC 12:48 → 09:48 Halifax.
$order = new WC_Order(['_mealsdb_wallclock_created' => '2026-09-17 12:48:00']);
chk('K18-3 input value is site-time HH:MM', '09:48', $inp->invoke($ui, $order));

// K18-4: no stamp → field renders empty.
$order_none = new WC_Order([]);
chk('K18-4 no stamp → empty input value', '', $inp->invoke($ui, $order_none));

// K18-1/2: saving a time writes ONLY the meta (UTC), never date_created.
$_POST = ['mealsdb_entered_time_nonce' => 'x', 'mealsdb_entered_time' => '09:48'];
$GLOBALS['NONCE_OK'] = true;
$order_save = new WC_Order([], '2026-09-17 00:00:00');
$GLOBALS['OA_ORDER'] = $order_save;
$ui->save_entered_time_field(4242);
chk('K18-2 meta written', true, isset($order_save->saved_meta['_mealsdb_wallclock_created']));
// 09:48 Halifax on 2026-09-17 → 12:48 UTC.
chk('K18-2 stored as UTC', '2026-09-17 12:48:00', $order_save->saved_meta['_mealsdb_wallclock_created'] ?? null);
chk('K18-1 set_date_created NEVER called', 0, $order_save->set_date_created_calls);

// K18-5: empty field → existing meta left alone (not cleared).
$_POST = ['mealsdb_entered_time_nonce' => 'x', 'mealsdb_entered_time' => ''];
$order_keep = new WC_Order(['_mealsdb_wallclock_created' => '2026-09-01 15:00:00']);
$GLOBALS['OA_ORDER'] = $order_keep;
$ui->save_entered_time_field(4242);
chk('K18-5 empty leaves meta untouched', '2026-09-01 15:00:00', $order_keep->get_meta('_mealsdb_wallclock_created'));
chk('K18-5 no write on empty', false, isset($order_keep->saved_meta['_mealsdb_wallclock_created']));

// Malformed → left alone.
$_POST = ['mealsdb_entered_time_nonce' => 'x', 'mealsdb_entered_time' => '99:99'];
$order_bad = new WC_Order(['_mealsdb_wallclock_created' => '2026-09-01 15:00:00']);
$GLOBALS['OA_ORDER'] = $order_bad;
$ui->save_entered_time_field(4242);
chk('K18 malformed time leaves meta untouched', false, isset($order_bad->saved_meta['_mealsdb_wallclock_created']));

// K18-7: no nonce → touch nothing.
$_POST = ['mealsdb_entered_time' => '10:00']; // nonce field absent
$order_nonce = new WC_Order([]);
$GLOBALS['OA_ORDER'] = $order_nonce;
$ui->save_entered_time_field(4242);
chk('K18-7 no nonce → no meta write', false, isset($order_nonce->saved_meta['_mealsdb_wallclock_created']));
chk('K18-7 no nonce → no set_date_created', 0, $order_nonce->set_date_created_calls);

// K18-8: list column renders date + site-time; unstamped shows date only.
$stamped = new WC_Order(['_mealsdb_wallclock_created' => '2026-09-17 12:48:00'], '2026-09-17 00:00:00');
ob_start();
$ui->render_delivery_date_order_column('mealsdb_order_date', $stamped);
$col = ob_get_clean();
chk_contains('K18-8 order-date column shows the site time', '9:48 am', $col);

ob_start();
$ui->render_delivery_date_order_column('mealsdb_order_date', $order_none);
$col_none = ob_get_clean();
chk('K18-8 unstamped order-date column has no time', false, strpos($col_none, ':') !== false && strpos($col_none, 'am') !== false);
chk('K18-8 unstamped still renders (date only, not empty)', true, trim($col_none) !== '');

// K18-9/10: column layout replaces core date, adds NO "Entered" column.
$cols = $ui->add_delivery_date_order_column(['cb' => '', 'order_number' => 'Order', 'date' => 'Date', 'order_total' => 'Total']);
$keys = array_keys($cols);
chk('K18-10 core date column removed', false, in_array('date', $keys, true));
chk('K18-3 order-date replacement present', true, in_array('mealsdb_order_date', $keys, true));
chk('K18-10 no Entered column', false, in_array('mealsdb_entered_time', $keys, true));
$pos_od = array_search('mealsdb_order_date', $keys, true);
$pos_dd = array_search('mealsdb_delivery_date', $keys, true);
chk('K18 order-date sits before delivery-date', true, $pos_od !== false && $pos_dd !== false && $pos_od < $pos_dd);

// K18-9: order-date column sorts on date_created (not the meta).
$sortable = $ui->register_delivery_date_sortable_column([]);
chk('K18-9 order-date sortable on date_created', 'date', $sortable['mealsdb_order_date'] ?? null);

// K18-10: the removed K16 methods are gone.
chk('K18-10 add_entered_time_order_column removed', false, method_exists('MealsDB_Admin_UI', 'add_entered_time_order_column'));
chk('K18-10 build_entered_time_field_html (read-only) removed', false, method_exists('MealsDB_Admin_UI', 'build_entered_time_field_html'));

// K18-11: core's hour/minute inputs are hidden on the order edit screen.
$GLOBALS['MDB_SCREEN'] = 'woocommerce_page_wc-orders';
$_GET['action'] = 'edit';
ob_start();
$ui->hide_core_order_time_inputs();
$style = ob_get_clean();
chk_contains('K18-11 hides core hour input', 'order_date_hour', $style);
chk_contains('K18-11 hides core minute input', 'order_date_minute', $style);

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
