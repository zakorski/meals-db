<?php
/**
 * Schema shape for meals_order_audits (weekly order audit — spec 2026-07-30).
 * Run with: php tests/test-order-audit-schema.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function oa_chk(bool $cond, string $label): void {
    global $failures, $passed;
    if ($cond) { $passed++; } else { $failures[] = 'FAIL: ' . $label; }
}

oa_chk(defined('MealsDB_Tables::ORDER_AUDITS') && MealsDB_Tables::ORDER_AUDITS === 'meals_order_audits',
    'ORDER_AUDITS constant is meals_order_audits');
oa_chk(in_array(MealsDB_Tables::ORDER_AUDITS, MealsDB_Tables::all(), true),
    'ORDER_AUDITS is in the MealsDB_Tables::all() registry (installer + uninstall coverage)');

$schema = MealsDB_Schema::get_table_schema(MealsDB_Tables::ORDER_AUDITS);
oa_chk(is_array($schema), 'canonical schema entry exists');
$cols = array_keys($schema['columns'] ?? []);
foreach (['audit_id', 'week_start', 'week_end', 'status', 'payload', 'row_count',
          'confirmed_count', 'edited_count', 'created_by', 'created_at',
          'finalized_by', 'finalized_at', 'unfinalized_at', 'unfinalize_reason'] as $c) {
    oa_chk(in_array($c, $cols, true), "column {$c} declared");
}
oa_chk(($schema['primary_key'] ?? null) === ['audit_id'], 'primary key is audit_id');
oa_chk(stripos($schema['columns']['status'] ?? '', "ENUM('draft','finalized')") !== false,
    'status ENUM is draft|finalized');
$index_cols = array_map(static fn($i) => $i['columns'], $schema['indexes'] ?? []);
oa_chk(in_array(['week_start'], $index_cols, true), 'week_start is indexed');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
