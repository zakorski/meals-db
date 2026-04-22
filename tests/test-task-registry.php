<?php
/**
 * Tests for MealsDB_Task_Registry.
 *
 * Covers: registration, retrieval, unknown-type handling, form-schema
 * validation (required fields, type coercion, show_when gating).
 *
 * Run with: php tests/test-task-registry.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default') { return $text; }
}
if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value, ...$args) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0, $depth = 512) { return json_encode($data, $flags, $depth); }
}

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
        public int $rows_affected = 0;
        public function prepare($query, ...$args) { return $query; }
        public function get_results($query, $output = 'OBJECT') { return []; }
        public function get_row($query, $output = 'OBJECT', $y = 0) { return null; }
        public function get_var($query, $x = 0, $y = 0) { return null; }
        public function query($query) { return 0; }
        public function insert($table, $data, $format = null) { return 1; }
        public function update($table, $data, $where, $format = null, $where_format = null) { return 1; }
        public function delete($table, $where, $format = null) { return 1; }
    }
}

$failures = [];
$passed = 0;

function assert_equals($actual, $expected, string $label) {
    global $failures, $passed;
    if ($actual === $expected) {
        $passed++;
    } else {
        $failures[] = sprintf(
            '[%s] expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

function assert_true($value, string $label) {
    assert_equals((bool) $value, true, $label);
}

function assert_false($value, string $label) {
    assert_equals((bool) $value, false, $label);
}

// --- register / get / has / get_all ---
MealsDB_Task_Registry::reset();
assert_false(MealsDB_Task_Registry::has('foo'), 'empty registry has() is false');
assert_equals(MealsDB_Task_Registry::get('foo'), null, 'empty registry get() returns null');

MealsDB_Task_Registry::register('foo', [
    'label'       => 'Foo',
    'form_schema' => [
        ['name' => 'n', 'type' => 'text', 'required' => true],
    ],
]);
assert_true(MealsDB_Task_Registry::has('foo'), 'registered type is present');
$def = MealsDB_Task_Registry::get('foo');
assert_equals($def['label'], 'Foo', 'definition label preserved');
assert_equals($def['urgency'], 'routine', 'default urgency is routine');

$all = MealsDB_Task_Registry::get_all();
assert_equals(count($all), 1, 'get_all returns one type');

// Empty type_id is refused (silently logs).
MealsDB_Task_Registry::register('', ['label' => 'nope']);
assert_equals(count(MealsDB_Task_Registry::get_all()), 1, 'empty type_id not registered');

// --- validation: required field present / absent ---
MealsDB_Task_Registry::reset();
MealsDB_Task_Registry::register('t', [
    'form_schema' => [
        ['name' => 'a', 'type' => 'text', 'required' => true],
        ['name' => 'b', 'type' => 'number', 'required' => false, 'min' => 0, 'max' => 10],
        ['name' => 'c', 'type' => 'date', 'required' => false],
        ['name' => 'y', 'type' => 'yesno', 'required' => true],
    ],
]);

$errors = MealsDB_Task_Registry::validate_form_data('t', []);
assert_true(count($errors) >= 2, 'empty form rejects required fields');

$errors = MealsDB_Task_Registry::validate_form_data('t', ['a' => 'hi', 'y' => 'yes']);
assert_equals($errors, [], 'minimal valid form passes');

$errors = MealsDB_Task_Registry::validate_form_data('t', ['a' => 'x', 'y' => 'maybe']);
assert_true(!empty($errors), 'invalid yesno rejected');

$errors = MealsDB_Task_Registry::validate_form_data('t', ['a' => 'x', 'y' => 'no', 'b' => 99]);
assert_true(!empty($errors), 'number above max rejected');

$errors = MealsDB_Task_Registry::validate_form_data('t', ['a' => 'x', 'y' => 'no', 'b' => 'NaN']);
assert_true(!empty($errors), 'non-numeric number rejected');

$errors = MealsDB_Task_Registry::validate_form_data('t', ['a' => 'x', 'y' => 'no', 'c' => '2026/01/01']);
assert_true(!empty($errors), 'malformed date rejected');

// --- validation: unknown task type ---
$errors = MealsDB_Task_Registry::validate_form_data('nonexistent', []);
assert_true(!empty($errors), 'unknown type returns error');

// --- conditional show_when: required field hidden by condition is skipped ---
MealsDB_Task_Registry::reset();
MealsDB_Task_Registry::register('cond', [
    'form_schema' => [
        ['name' => 'arrived', 'type' => 'yesno', 'required' => true],
        ['name' => 'reason',  'type' => 'text',  'required' => true, 'show_when' => ['field' => 'arrived', 'equals' => 'no']],
    ],
]);

// arrived=yes hides reason, so it's not required.
$errors = MealsDB_Task_Registry::validate_form_data('cond', ['arrived' => 'yes']);
assert_equals($errors, [], 'hidden required field passes validation');

// arrived=no shows reason — its absence triggers an error.
$errors = MealsDB_Task_Registry::validate_form_data('cond', ['arrived' => 'no']);
assert_true(!empty($errors), 'visible required field still enforced');

// arrived=no with reason filled passes.
$errors = MealsDB_Task_Registry::validate_form_data('cond', ['arrived' => 'no', 'reason' => 'late truck']);
assert_equals($errors, [], 'conditional field passes when satisfied');

// --- report ---
echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) {
    echo "FAIL: $f\n";
}
exit(empty($failures) ? 0 : 1);
