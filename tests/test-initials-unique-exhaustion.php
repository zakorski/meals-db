<?php
/**
 * Tests for directive GUI-INITIALS: delivery initials are GLOBALLY UNIQUE
 * (the same-address sharing exception is removed) and generator exhaustion
 * surfaces a specific, retryable message.
 *
 *   T-1 duplicate initials rejected even when the delivery ADDRESS matches
 *       (the old behaviour allowed this as "shared"; it must not anymore).
 *   T-2 duplicate initials rejected with a DIFFERENT address (no regression).
 *   T-3 a fresh, unused, non-banlisted code validates.
 *   T-4 generate() returns false when the space is exhausted AND the AJAX
 *       handler returns the specific "after 100 attempts … retry" message.
 *   T-5 banlist still enforced on validate(), and exposed via the blocked list.
 *
 * Run: php tests/test-initials-unique-exhaustion.php
 */

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// ---------------------------------------------------------------------------
// Minimal WP function stubs.
// ---------------------------------------------------------------------------
if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters($t, $v, ...$a) { return $v; } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; } }
if (!function_exists('check_ajax_referer')) { function check_ajax_referer($a, $n = false, $die = true) { return true; } }

// wp_send_json halts execution in WordPress; emulate that with an exception so
// the AJAX handler stops after its first emit and the payload is inspectable.
class InitialsJsonHalt extends Exception {
    public array $payload;
    public function __construct(array $payload) { $this->payload = $payload; parent::__construct('json'); }
}
if (!function_exists('wp_send_json')) {
    function wp_send_json($data, $status = null) { throw new InitialsJsonHalt((array) $data); }
}

// ---------------------------------------------------------------------------
// Stub plugin guard classes BEFORE the autoloader so the real ones don't load.
// ---------------------------------------------------------------------------
class MealsDB_Permissions {
    public static function can_access_plugin(): bool { return true; }
    public static function required_capability(): string { return 'manage_woocommerce'; }
}
class MealsDB_Rate_Limiter {
    public static function check_rate_limit(string $bucket, ?int $uid = null): bool { return true; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// ---------------------------------------------------------------------------
// In-memory $wpdb controlling the two reads the validator performs:
//   get_results()  → get_clients_with_initials() (uniqueness check)
//   get_col()      → get_all_existing_initials()  (random-search skip set)
// ---------------------------------------------------------------------------
if (!class_exists('wpdb')) { class wpdb {} }
class InitialsWpdb extends wpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<string, array<int, array<string,mixed>>> INITIALS => rows */
    public array $clients = [];
    public bool $allInUse = false;

    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }

    public function get_results($q, $o = ARRAY_A) {
        if ($this->allInUse) {
            return [['id' => 999, 'first_name' => 'Taken', 'last_name' => 'All']];
        }
        if (preg_match("/delivery_initials = '([A-Z]{3})'/", (string) $q, $m)) {
            return $this->clients[$m[1]] ?? [];
        }
        return [];
    }

    public function get_col($q, $x = 0) {
        return array_keys($this->clients);
    }
}

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($cond, $label) { chk((bool) $cond, true, $label); }

// One client already holds "ABC" at a known address.
$w = new InitialsWpdb();
$w->clients['ABC'] = [['id' => 1, 'first_name' => 'Jane', 'last_name' => 'Doe']];
$GLOBALS['wpdb'] = $w;

$same_address = [
    'delivery_address_street_name' => '123 Main St',
    'delivery_address_city'        => 'Moncton',
    'delivery_address_postal'      => 'E1C 1A1',
];
$diff_address = [
    'delivery_address_street_name' => '999 Elsewhere Ave',
    'delivery_address_city'        => 'Sackville',
    'delivery_address_postal'      => 'E4L 3M7',
];

// ---------------------------------------------------------------------------
// T-1: duplicate rejected even when the address matches (no sharing).
// ---------------------------------------------------------------------------
$r = MealsDB_Initials_Validator::validate('ABC', $same_address, null);
chk(false, $r['valid'], 'T-1: duplicate at SAME address is invalid (no sharing exception)');
chk(false, $r['shared'] ?? null, 'T-1: shared flag is hard-false');

// ---------------------------------------------------------------------------
// T-2: duplicate rejected with a different address (unchanged).
// ---------------------------------------------------------------------------
$r = MealsDB_Initials_Validator::validate('ABC', $diff_address, null);
chk(false, $r['valid'], 'T-2: duplicate at DIFFERENT address is invalid');

// Editing the SAME client that holds ABC must not collide with itself.
$r = MealsDB_Initials_Validator::validate('ABC', $same_address, 1);
chk(true, $r['valid'], 'T-2b: a client editing its own record keeps its code');

// ---------------------------------------------------------------------------
// T-3: a fresh unused, non-banlisted code validates.
// ---------------------------------------------------------------------------
$r = MealsDB_Initials_Validator::validate('XYZ', $same_address, null);
chk(true, $r['valid'], 'T-3: unused code is valid');
$r = MealsDB_Initials_Validator::validate('xyz', [], null);
chk(true, $r['valid'], 'T-3b: lowercase normalises and validates');

// ---------------------------------------------------------------------------
// T-4: generator exhaustion → false, and the AJAX handler surfaces the
//      specific retry message.
// ---------------------------------------------------------------------------
$w->allInUse = true;
$gen = MealsDB_Initials_Validator::generate('Test', 'User');
chk(false, $gen, 'T-4: generate() returns false when every code is taken');

$_POST = ['first_name' => 'Test', 'last_name' => 'User'];
$caught = null;
try {
    MealsDB_Ajax_Initials::generate_initials();
} catch (InitialsJsonHalt $e) {
    $caught = $e->payload;
}
chk_true(is_array($caught), 'T-4: AJAX handler emitted a JSON response');
chk(false, $caught['success'] ?? null, 'T-4: AJAX success is false on exhaustion');
chk_true(
    isset($caught['message']) && strpos($caught['message'], 'after 100 attempts') !== false,
    'T-4: AJAX message names the 100-attempt exhaustion'
);
chk_true(
    isset($caught['message']) && stripos($caught['message'], 'retry') !== false,
    'T-4: AJAX message tells the operator to retry'
);
$w->allInUse = false;

// ---------------------------------------------------------------------------
// T-5: banlist still enforced.
// ---------------------------------------------------------------------------
foreach (['XXX', 'ASS', 'GOD'] as $banned) {
    $r = MealsDB_Initials_Validator::validate($banned, [], null);
    chk(false, $r['valid'], "T-5: banned code {$banned} is invalid");
}
$blocked = MealsDB_Initials_Validator::get_blocked_initials();
chk_true(in_array('XXX', $blocked, true), 'T-5: blocked list still exposes XXX');

// Format guard unchanged.
$r = MealsDB_Initials_Validator::validate('AB1', [], null);
chk(false, $r['valid'], 'T-5b: non-letter code rejected');

// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
