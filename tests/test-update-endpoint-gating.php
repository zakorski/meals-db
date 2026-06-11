<?php
/**
 * SEC regression test — the update/maintenance service layer must re-check
 * manage_options (defense in depth), so a caller that reaches these methods
 * without going through the AJAX handler (or with only manage_woocommerce)
 * cannot deploy code or run the installer.
 *
 * Findings:
 *   - mealsdb_run_update / check_updates deployed code (git pull / release zip)
 *     at baseline capability with no service-layer re-check.
 *   - mealsdb_update_database ran the installer at baseline capability.
 *
 * Run: php tests/test-update-endpoint-gating.php
 */
if (!defined('ABSPATH'))         { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('MEALS_DB_PLUGIN_DIR')) { define('MEALS_DB_PLUGIN_DIR', dirname(__DIR__) . '/'); }
if (!defined('MEALS_DB_VERSION')) { define('MEALS_DB_VERSION', '1.0.0'); }

$GLOBALS['__sec_can'] = false;
if (!function_exists('current_user_can'))  { function current_user_can($c) { return (bool) ($GLOBALS['__sec_can'] ?? false); } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('__'))                { function __($t, $d = 'default') { return $t; } }
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message; public $data;
        public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($cond, $label) {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = $label; }
}
function is_forbidden($r): bool {
    return ($r instanceof WP_Error) && stripos((string) $r->get_error_code(), 'forbid') !== false;
}

// Without manage_options, every dangerous entry point must refuse with a
// forbidden WP_Error — BEFORE doing any git / installer work.
$GLOBALS['__sec_can'] = false;

chk(is_forbidden(MealsDB_Updates::pull_updates()),
    '[SEC] pull_updates refuses without manage_options');
chk(is_forbidden(MealsDB_Updates::check_for_updates()),
    '[SEC] check_for_updates refuses without manage_options');
chk(is_forbidden(MealsDB_Updates::run_database_maintenance()),
    '[SEC] run_database_maintenance refuses without manage_options');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
