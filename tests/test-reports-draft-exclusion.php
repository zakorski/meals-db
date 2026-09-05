<?php
/**
 * DIRECTIVE 1 (ITEM 1) regression test — draft orders (wc-checkout-draft) must
 * not leak into the two class-reports.php order-status filters that used a
 * "NOT IN (...)" denylist rather than a positive whitelist:
 *
 *   - the Order Audit query, which filtered only `NOT IN ('wc-trash','trash')`
 *     and therefore admitted a parked draft as if it were a live order;
 *   - the PO Forecast recent/seasonal queries, whose denylist listed the old
 *     'wc-draft'/'draft' statuses but missed the HPOS 'wc-checkout-draft'.
 *
 * A draft is not a placed order (Directive 1): it must not appear in the order
 * audit nor inflate PO demand. The fix routes both denylists through the single
 * source MealsDB_WC_Order_Query::DRAFT_STATUSES.
 *
 * These queries hit $wpdb and are not unit-testable without a live HPOS DB, so
 * this guards the fix at the source level: the vulnerable literal is gone and
 * both filter sites name the draft status.
 *
 * Run: php tests/test-reports-draft-exclusion.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!class_exists('wpdb')) { class wpdb {} }

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

// 1. The single-source draft status list exists and names the HPOS status.
$draft = MealsDB_WC_Order_Query::DRAFT_STATUSES;
chk(is_array($draft), true, 'DRAFT_STATUSES is an array');
chk(in_array('wc-checkout-draft', $draft, true), true, 'DRAFT_STATUSES includes wc-checkout-draft');

// 2. Source-level guard on the two leak sites in class-reports.php.
$reports = file_get_contents(__DIR__ . '/../includes/services/class-reports.php');

// The Order Audit vulnerable literal must be gone (it admitted every non-trash
// status, drafts included).
chk(strpos($reports, "status NOT IN ('wc-trash', 'trash')") === false, true,
    'Order Audit no longer uses the bare NOT IN (wc-trash, trash) denylist');

// Both leak sites (Order Audit + PO Forecast) must fold in the draft statuses
// from the single source rather than re-hardcoding them. Assert the canonical
// list is referenced at both sites.
$ref_count = substr_count($reports, 'MealsDB_WC_Order_Query::DRAFT_STATUSES');
chk($ref_count >= 2, true,
    "class-reports.php routes both leak sites through DRAFT_STATUSES (found {$ref_count} references, expected >= 2)");

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
