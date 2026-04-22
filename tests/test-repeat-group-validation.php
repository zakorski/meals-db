<?php
/**
 * Tests for repeat_group form-schema validation + not_equals_field
 * conditional visibility.
 *
 * Run with: php tests/test-repeat-group-validation.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $tag, $v, ...$a) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public function prepare($q, ...$a) { return $q; }
        public function get_results($q, $o = 'OBJECT') { return []; }
        public function get_row($q, $o = 'OBJECT', $y = 0) { return null; }
        public function query($q) { return 0; }
        public function insert($t, $d, $f = null) { return 1; }
        public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
    }
}

$failures = [];
$passed = 0;
function assert_equals($a, $e, string $l) {
    global $failures, $passed;
    if ($a === $e) { $passed++; }
    else { $failures[] = sprintf('[%s] expected %s got %s', $l, var_export($e, true), var_export($a, true)); }
}
function assert_true($v, $l) { assert_equals((bool) $v, true, $l); }

// --- repeat_group: per-row required validation ---
MealsDB_Task_Registry::reset();
MealsDB_Task_Registry::register('pc', [
    'form_schema' => [
        ['name' => 'count_received', 'type' => 'yesno', 'required' => true],
        ['name'   => 'sku_adjustments',
         'type'   => 'repeat_group',
         'show_when' => ['field' => 'count_received', 'equals' => 'yes'],
         'fields' => [
             ['name' => 'sku',              'type' => 'text',   'readonly' => true],
             ['name' => 'quantity_ordered', 'type' => 'number', 'readonly' => true],
             ['name' => 'actual_count',     'type' => 'number', 'required' => true],
             ['name' => 'reason',           'type' => 'select',
              'options' => ['', 'damaged', 'not_received', 'backordered', 'overshipped', 'other'],
              'show_when' => ['field' => 'quantity_ordered', 'not_equals_field' => 'actual_count']],
             ['name' => 'reason_notes',     'type' => 'text',
              'show_when' => ['field' => 'reason', 'equals' => 'other']],
         ]],
    ],
]);

// Count not received — sku_adjustments hidden, validation passes.
$errors = MealsDB_Task_Registry::validate_form_data('pc', ['count_received' => 'no']);
assert_equals($errors, [], 'hidden repeat_group not enforced');

// Count received but no rows — should pass (empty repeat group is fine).
$errors = MealsDB_Task_Registry::validate_form_data('pc', ['count_received' => 'yes', 'sku_adjustments' => []]);
assert_equals($errors, [], 'empty repeat_group passes');

// Row with missing required actual_count fails.
$errors = MealsDB_Task_Registry::validate_form_data('pc', [
    'count_received' => 'yes',
    'sku_adjustments' => [
        ['sku' => 'A', 'quantity_ordered' => 10],
    ],
]);
assert_true(!empty($errors), 'row missing required actual_count rejected');

// Row with actual_count matches ordered — reason hidden by not_equals_field, not required anyway.
$errors = MealsDB_Task_Registry::validate_form_data('pc', [
    'count_received' => 'yes',
    'sku_adjustments' => [
        ['sku' => 'A', 'quantity_ordered' => 10, 'actual_count' => 10],
    ],
]);
assert_equals($errors, [], 'matched count passes without reason');

// Row with mismatch + valid reason.
$errors = MealsDB_Task_Registry::validate_form_data('pc', [
    'count_received' => 'yes',
    'sku_adjustments' => [
        ['sku' => 'A', 'quantity_ordered' => 10, 'actual_count' => 8, 'reason' => 'damaged'],
    ],
]);
assert_equals($errors, [], 'mismatch with valid reason passes');

// Row with mismatch + 'other' reason requires reason_notes (but only visible when
// reason === 'other'; our validator honors show_when, so without 'other' reason
// notes aren't required. With 'other' reason, notes is not marked required in
// the schema — but if it was marked required we'd expect rejection).
$errors = MealsDB_Task_Registry::validate_form_data('pc', [
    'count_received' => 'yes',
    'sku_adjustments' => [
        ['sku' => 'A', 'quantity_ordered' => 10, 'actual_count' => 8, 'reason' => 'other', 'reason_notes' => 'supplier error'],
    ],
]);
assert_equals($errors, [], 'mismatch with other + notes passes');

// Row with mismatch + invalid reason value.
$errors = MealsDB_Task_Registry::validate_form_data('pc', [
    'count_received' => 'yes',
    'sku_adjustments' => [
        ['sku' => 'A', 'quantity_ordered' => 10, 'actual_count' => 8, 'reason' => 'no_such_reason'],
    ],
]);
assert_true(!empty($errors), 'invalid reason value rejected');

// Multiple rows — one invalid, one valid.
$errors = MealsDB_Task_Registry::validate_form_data('pc', [
    'count_received' => 'yes',
    'sku_adjustments' => [
        ['sku' => 'A', 'quantity_ordered' => 10, 'actual_count' => 10],
        ['sku' => 'B', 'quantity_ordered' => 5],  // missing actual_count
    ],
]);
assert_true(!empty($errors), 'one-bad-row-in-list rejects');

// Path prefix should identify which row failed.
$has_path = false;
foreach ($errors as $err) {
    if (strpos($err, 'sku_adjustments[1]') !== false) {
        $has_path = true;
        break;
    }
}
assert_true($has_path, 'error path identifies row index');

// --- not_equals_field visibility ---
// Direct check: ordered == actual → reason not visible → not enforced.
MealsDB_Task_Registry::reset();
MealsDB_Task_Registry::register('neq', [
    'form_schema' => [
        ['name' => 'expected', 'type' => 'number'],
        ['name' => 'actual',   'type' => 'number'],
        ['name' => 'reason',   'type' => 'text', 'required' => true,
         'show_when' => ['field' => 'expected', 'not_equals_field' => 'actual']],
    ],
]);
$errors = MealsDB_Task_Registry::validate_form_data('neq', ['expected' => 5, 'actual' => 5]);
assert_equals($errors, [], 'equal values hide conditional required field');

$errors = MealsDB_Task_Registry::validate_form_data('neq', ['expected' => 5, 'actual' => 7]);
assert_true(!empty($errors), 'unequal values surface required reason');

$errors = MealsDB_Task_Registry::validate_form_data('neq', ['expected' => 5, 'actual' => 7, 'reason' => 'test']);
assert_equals($errors, [], 'unequal with reason passes');

echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
