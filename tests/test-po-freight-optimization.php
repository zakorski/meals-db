<?php
/**
 * Tests for MealsDB_Reports::optimize_po_for_pallets() —
 * DIRECTIVE-po-freight-pallet-optimization.
 *
 * The freight pass is a PURE post-processor over generate_purchase_order()
 * rows: it snaps the total case count to a whole Apetito pallet
 * (APETITO_CASES_PER_PALLET = 75), FILLing up when the partial is >= 1/3 pallet
 * and DROPping the partial off otherwise — but only if the drop stays at/above
 * the 7-week coverage floor; if the drop gets stuck it ROUNDS UP instead. Every
 * single-case step goes to the least-covered (fill) / most-covered (drop) row
 * and the pool is re-ranked between steps so the change spreads.
 *
 *   FR-1  partial == 0            → no-op (action 'none', rows untouched)
 *   FR-2  partial >= 1/3 pallet   → FILL to the next whole pallet, spread wide
 *   FR-3  partial <  1/3 pallet   → DROP the partial (feasible), whole pallets
 *   FR-4  drop-stuck              → ROUND UP (fill from base), never drop below floor
 *   FR-5  whole-case integrity    → order_quantity == cases_to_buy * case_size
 *   FR-6  7-week floor / 52-week ceiling never breached on changed rows
 *   FR-7  changed rows tagged (freight_delta_cases + seasonal_note); base copy untouched
 *   FR-8  empty input             → no crash, action 'none'
 *
 * Run: php tests/test-po-freight-optimization.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function chk_true($c, $l) { chk((bool) $c, true, $l); }

/**
 * Build one PO row with internally-consistent stock/coverage.
 * coverage weeks = (current_stock + order_quantity) / adjusted_weekly.
 */
function po_row(string $sku, int $cases, int $case_size, float $aw, int $current_stock = 0): array {
    $oq = $cases * $case_size;
    return [
        'sku'                 => $sku,
        'product_name'        => 'P-' . $sku,
        'weighted_avg_weekly' => $aw,
        'seasonal_index'      => 1.0,
        'adjusted_weekly'     => $aw,
        'projected_need'      => (int) ceil($aw * 9),
        'current_stock'       => $current_stock,
        'total_available'     => $current_stock,
        'units_needed'        => max(0, (int) ceil($aw * 9) - $current_stock),
        'case_size'           => $case_size,
        'cases_to_buy'        => $cases,
        'order_quantity'      => $oq,
        'seasonal_note'       => '',
    ];
}
function total_cases(array $rows): int {
    $t = 0; foreach ($rows as $r) { $t += (int) $r['cases_to_buy']; } return $t;
}
function coverage_ok(array $rows): bool {
    // Every row with demand must sit in [7, 52] weeks (float tolerance).
    foreach ($rows as $r) {
        $aw = (float) $r['adjusted_weekly'];
        if ($aw <= 0) { continue; }
        $cov = ((int) $r['current_stock'] + (int) $r['order_quantity']) / $aw;
        if ($cov < 7.0 - 1e-9 || $cov > 52.0 + 1e-9) { return false; }
    }
    return true;
}
function whole_cases_ok(array $rows): bool {
    foreach ($rows as $r) {
        if ((int) $r['order_quantity'] !== (int) $r['cases_to_buy'] * (int) $r['case_size']) { return false; }
    }
    return true;
}
function changed_count(array $rows): int {
    $n = 0; foreach ($rows as $r) { if ((int) ($r['freight_delta_cases'] ?? 0) !== 0) { $n++; } } return $n;
}

$CPP = (int) MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET;
chk($CPP, 75, 'sanity: APETITO_CASES_PER_PALLET is 75');

// ---------------------------------------------------------------------------
// FR-1 — partial == 0: already whole pallets → no-op.
// 15 rows * 5 cases = 75 = exactly one pallet.
// ---------------------------------------------------------------------------
$rows = [];
for ($i = 0; $i < 15; $i++) { $rows[] = po_row('N' . $i, 5, 5, 5.0); }
chk(total_cases($rows), 75, 'FR-1 setup base = 75');
$res = MealsDB_Reports::optimize_po_for_pallets($rows);
chk($res['summary']['action'], 'none', 'FR-1 action none when already whole pallets');
chk($res['summary']['final_cases'], 75, 'FR-1 final unchanged');
chk($res['summary']['cases_changed'], 0, 'FR-1 nothing changed');
chk_true(!array_key_exists('freight_delta_cases', $res['rows'][0]), 'FR-1 no-op does not tag rows');

// ---------------------------------------------------------------------------
// FR-2 — FILL: 30 rows * 9 cases = 270; 270 % 75 = 45 (>= 25) → fill 30 → 300 (4 pallets).
// Each row coverage 9, room to grow to 52 → adjustment spreads one case per row.
// ---------------------------------------------------------------------------
$rows = [];
for ($i = 0; $i < 30; $i++) { $rows[] = po_row('F' . $i, 9, 10, 10.0); }   // coverage (0+90)/10 = 9
chk(total_cases($rows), 270, 'FR-2 setup base = 270 (partial 45)');
$res = MealsDB_Reports::optimize_po_for_pallets($rows);
chk($res['summary']['action'], 'fill', 'FR-2 action fill (partial 45 >= 25)');
chk($res['summary']['final_cases'], 300, 'FR-2 final 300 = next whole pallet');
chk($res['summary']['final_cases'] % $CPP, 0, 'FR-2 final is a whole number of pallets');
chk((float) $res['summary']['pallets'], 4.0, 'FR-2 pallets = 4');
chk($res['summary']['cases_changed'], 30, 'FR-2 30 cases added');
chk($res['summary']['incomplete'], false, 'FR-2 fill completed');
chk(changed_count($res['rows']), 30, 'FR-2 spread across all 30 rows (one case each)');
chk_true(whole_cases_ok($res['rows']), 'FR-2 whole-case integrity (qty = cases*case_size)');
chk_true(coverage_ok($res['rows']), 'FR-2 all rows within [7,52] weeks');

// ---------------------------------------------------------------------------
// FR-3 — DROP: 14 rows * 12 cases = 168; 168 % 75 = 18 (< 25) → drop 18 → 150 (2 pallets).
// Each row coverage 12, slack down to 7 → drop is feasible and spreads.
// ---------------------------------------------------------------------------
$rows = [];
for ($i = 0; $i < 14; $i++) { $rows[] = po_row('D' . $i, 12, 5, 5.0); }     // coverage (0+60)/5 = 12
chk(total_cases($rows), 168, 'FR-3 setup base = 168 (partial 18)');
$res = MealsDB_Reports::optimize_po_for_pallets($rows);
chk($res['summary']['action'], 'drop', 'FR-3 action drop (partial 18 < 25, feasible)');
chk($res['summary']['final_cases'], 150, 'FR-3 final 150 = pallet below');
chk($res['summary']['final_cases'] % $CPP, 0, 'FR-3 final is a whole number of pallets');
chk((float) $res['summary']['pallets'], 2.0, 'FR-3 pallets = 2');
chk($res['summary']['cases_changed'], 18, 'FR-3 18 cases removed');
chk_true(whole_cases_ok($res['rows']), 'FR-3 whole-case integrity after drop');
chk_true(coverage_ok($res['rows']), 'FR-3 no row dropped below the 7-week floor');

// ---------------------------------------------------------------------------
// FR-4 — DROP-STUCK → ROUND UP. 38 rows * 2 cases = 76; 76 % 75 = 1 (< 25) → would DROP,
// but each row is coverage 10 with case_size 5 / aw 1: a single drop → coverage 5 (< 7),
// so no drop is possible. The pass must ROUND UP instead (fill 74 → 150 = 2 pallets),
// and MUST NOT drive any row below the floor.
// ---------------------------------------------------------------------------
$rows = [];
for ($i = 0; $i < 38; $i++) { $rows[] = po_row('S' . $i, 2, 5, 1.0); }      // coverage (0+10)/1 = 10
chk(total_cases($rows), 76, 'FR-4 setup base = 76 (partial 1, drop-stuck)');
$res = MealsDB_Reports::optimize_po_for_pallets($rows);
chk($res['summary']['action'], 'fill', 'FR-4 drop-stuck rounds UP to a fill');
chk($res['summary']['final_cases'], 150, 'FR-4 final 150 = next whole pallet (rounded up)');
chk($res['summary']['final_cases'] % $CPP, 0, 'FR-4 final is a whole number of pallets');
chk($res['summary']['cases_changed'], 74, 'FR-4 74 cases added (75 - 1 partial)');
$min_delta = PHP_INT_MAX;
foreach ($res['rows'] as $r) { $min_delta = min($min_delta, (int) ($r['freight_delta_cases'] ?? 0)); }
chk_true($min_delta >= 0, 'FR-4 no row was trimmed (all deltas >= 0 — nothing dropped below floor)');
chk_true(coverage_ok($res['rows']), 'FR-4 every row still within [7,52] weeks');
chk_true(whole_cases_ok($res['rows']), 'FR-4 whole-case integrity');

// ---------------------------------------------------------------------------
// FR-7 — tagging + base copy left untouched. Reuse the FILL scenario.
// ---------------------------------------------------------------------------
$rows = [];
for ($i = 0; $i < 30; $i++) { $rows[] = po_row('T' . $i, 9, 10, 10.0); }
$res = MealsDB_Reports::optimize_po_for_pallets($rows);
$tagged = null;
foreach ($res['rows'] as $r) { if ((int) ($r['freight_delta_cases'] ?? 0) > 0) { $tagged = $r; break; } }
chk_true($tagged !== null, 'FR-7 at least one row tagged with a positive delta');
chk_true(strpos((string) $tagged['seasonal_note'], 'Freight fill') !== false, 'FR-7 seasonal_note carries a Freight note');
chk($rows[0]['seasonal_note'], '', 'FR-7 base input row NOT mutated (pure function)');
chk_true(!array_key_exists('freight_delta_cases', $rows[0]), 'FR-7 base input row not tagged');

// ---------------------------------------------------------------------------
// FR-8 — empty input: no crash, action 'none'.
// ---------------------------------------------------------------------------
$res = MealsDB_Reports::optimize_po_for_pallets([]);
chk($res['summary']['action'], 'none', 'FR-8 empty input → action none');
chk($res['summary']['final_cases'], 0, 'FR-8 empty input → 0 cases');
chk_true(is_array($res['rows']), 'FR-8 empty input → rows is an array');

echo "\n=== PO freight / pallet optimisation ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
