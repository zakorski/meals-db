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

// Audit-storage normalization: the `rev` write-revision column on the header,
// and the new per-order rows table.
oa_chk(in_array('rev', $cols, true), 'header carries the rev write-revision column');

oa_chk(defined('MealsDB_Tables::ORDER_AUDIT_ROWS') && MealsDB_Tables::ORDER_AUDIT_ROWS === 'meals_order_audit_rows',
    'ORDER_AUDIT_ROWS constant is meals_order_audit_rows');
oa_chk(in_array(MealsDB_Tables::ORDER_AUDIT_ROWS, MealsDB_Tables::all(), true),
    'ORDER_AUDIT_ROWS is in the MealsDB_Tables::all() registry (installer + uninstall coverage)');

$rschema = MealsDB_Schema::get_table_schema(MealsDB_Tables::ORDER_AUDIT_ROWS);
oa_chk(is_array($rschema), 'rows-table canonical schema entry exists');
$rcols = array_keys($rschema['columns'] ?? []);
foreach (['row_id', 'audit_id', 'wc_order_id', 'wp_user_id', 'client_id', 'zone',
          'delivery_date', 'mains_count', 'sides_count', 'audit_status',
          'audited_by', 'audited_at', 'detail_enc', 'edits_enc', 'created_at', 'updated_at'] as $c) {
    oa_chk(in_array($c, $rcols, true), "rows-table column {$c} declared");
}
oa_chk(($rschema['primary_key'] ?? null) === ['row_id'], 'rows-table primary key is row_id');
$has_uniq = false;
foreach ($rschema['indexes'] ?? [] as $i) {
    if (($i['type'] ?? '') === 'UNIQUE' && ($i['columns'] ?? []) === ['audit_id', 'wc_order_id']) { $has_uniq = true; }
}
oa_chk($has_uniq, 'rows-table has UNIQUE(audit_id, wc_order_id) for one-row-per-order + idempotent upsert');
oa_chk(stripos($rschema['columns']['audit_status'] ?? '', "ENUM('pending','confirmed','edited')") !== false,
    'rows-table audit_status ENUM is pending|confirmed|edited');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
