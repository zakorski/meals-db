<?php
/**
 * Tests for the pure per-pipeline serializers + the VAC billing-model
 * correction (directive INV-DRAFT-3).
 *
 * The serializers are pure rows→string functions: generate_*() = collect →
 * build → serialize, so feeding hand-built phase-2 rows through the SAME
 * public serialize_*() the generators call IS the byte-output the direct
 * download produces. These tests exercise the real serializers (no DB).
 *
 *   T-A1  SDNB legacy serializer — column/value layout (the format the
 *         direct download emits; serialize is the shared bottom-half).
 *   T-A2  SDNB new-portal serializer — line layout.
 *   T-A3  VAC clean veteran — New Total = mains×rate, Bill HST 0, no side line.
 *   T-B1  VAC sides NOT in the total — a veteran WITH allocated sides + fold 0
 *         bills mains-only (contrast with the OLD mains+sides+HST behavior).
 *   T-B2  VAC fold flows to total + HST cell; the PDF "(includes HST)" cell =
 *         fold_hst (verified through build_vac_pdf_html's positional mapping).
 *   T-B4  Rates from Definitions — an sdnb_side override flows through the
 *         SDNB serializer's HST; the VAC serializer carries the
 *         Definitions-derived monthly-allowance figure faithfully.
 *   T-B5  Reference characterization — a clean veteran and a folded veteran in
 *         one run reproduce the mains-only / folded totals VAC actually pays.
 *   T-B3  The new editable fields (bill_rate/fold_amount/fold_hst/bill_mains)
 *         classify correctly for the edit+audit path (fold_hst is money, not
 *         text — the bug the classify_field 'hst' fix prevents).
 *
 * Run: php tests/test-invoice-serialize.php
 */

if (!defined('ABSPATH'))  { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A'))  { define('ARRAY_A', 'ARRAY_A'); }

// ---------------------------------------------------------------------------
// WP / WC stubs. HST is sourced live from WC_Tax (LB-7); 15% standard rate.
// ---------------------------------------------------------------------------
$GLOBALS['__wc_hst_percent'] = 15.0;
if (!class_exists('WC_Tax')) {
    class WC_Tax {
        public static function get_rates($tax_class = '') {
            $p = $GLOBALS['__wc_hst_percent'] ?? null;
            return $p === null ? [] : [['rate' => $p]];
        }
    }
}

// Rate-definitions option store (T-B4 overrides this).
$GLOBALS['TEST_OPTIONS'] = [];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $GLOBALS['TEST_OPTIONS'][$name] ?? $default; }
}
if (!function_exists('__'))            { function __($t, $d = null) { return $t; } }
if (!function_exists('wp_timezone'))   { function wp_timezone() { return new DateTimeZone('UTC'); } }
if (!function_exists('get_userdata'))  { function get_userdata($id) { return false; } } // → no new-user flag

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
$failures = []; $passed = 0;
function chk($got, $exp, string $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label, var_export($exp, true), var_export($got, true));
}
function chk_true($cond, string $label) {
    global $failures, $passed;
    if ($cond === true) { $passed++; return; }
    $failures[] = "FAIL: $label (expected true)";
}
/** First CSV data line whose first cell equals $marker, parsed. */
function csv_row_with(string $csv, string $marker): ?array {
    foreach (explode("\n", $csv) as $line) {
        if ($line === '') { continue; }
        $cells = str_getcsv($line);
        if (($cells[0] ?? null) === $marker) { return $cells; }
    }
    return null;
}

// ===========================================================================
// T-A1 — SDNB legacy serializer.
// ===========================================================================
$sdnb_rows = [
    501 => [
        'client_id' => 501, 'wp_user_id' => 9001,
        'first_name' => 'Ada', 'last_name' => 'Brammah',
        'service_id' => '356029', 'requisition_id' => 'REQ-1',
        'individual_id' => 'IND-501', 'individual_id_index' => 'idx501',
        'delivery_area_zone' => 'M',
        'allocated_mains' => 10, 'allocated_sides' => 0,
        'allocated_tax_sides' => 0, 'allocated_nontax_sides' => 0,
        'resolved_rate' => 11.40, 'contribution_cents' => 0,
    ],
];
$legacy_csv = MealsDB_Invoice_Generator::serialize_sdnb_legacy($sdnb_rows, [
    'zone' => 'M', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31',
]);
// Invoice number row (row marker '2' is the metadata-values row).
$meta = csv_row_with($legacy_csv, '2');
chk_true($meta !== null, 'T-A1: metadata values row present');
chk($meta[1] ?? null, '2026 Jan 31 M', 'T-A1: invoice number from end date + zone');
// Data row (marker '3').
$data = csv_row_with($legacy_csv, '3');
chk_true($data !== null, 'T-A1: data row present');
chk($data[6] ?? null, '10.00', 'T-A1: units = allocated mains');
chk($data[8] ?? null, '11.40', 'T-A1: rate = resolved rate');
chk($data[9] ?? null, '114.00', 'T-A1: basic cost = 10 × 11.40');
chk($data[34] ?? null, '0.00', 'T-A1: tax = 0 (no taxable sides)');
chk($data[35] ?? null, '114.00', 'T-A1: total line cost');

// ===========================================================================
// T-A2 — SDNB new-portal serializer.
// ===========================================================================
$np_rows = [
    601 => [
        'client_id' => 601, 'first_name' => 'Ann', 'last_name' => 'Lee',
        'sdnb_service_request_id' => 'SR-1',
        'allocated_mains' => 10, 'resolved_rate' => 11.40,
        'contribution_cents' => 0, 'tax_cents' => 0,
    ],
];
$np_csv = MealsDB_Invoice_Generator::serialize_sdnb_new_portal($np_rows);
chk_true(strpos($np_csv, 'Service Confirmation Item Id') !== false, 'T-A2: header present');
$np_line = null;
foreach (explode("\n", $np_csv) as $l) { if (strpos($l, 'ANN LEE') !== false) { $np_line = str_getcsv($l); } }
chk_true($np_line !== null, 'T-A2: client line present');
chk($np_line[2] ?? null, 'SR-1', 'T-A2: service request id');
chk($np_line[3] ?? null, 'ANN LEE', 'T-A2: client name uppercased');
chk($np_line[4] ?? null, '10', 'T-A2: units');
chk($np_line[6] ?? null, '11.40', 'T-A2: rate');

// ===========================================================================
// Helper: a VAC veteran row in the shape build_vac_draft_rows produces.
// ===========================================================================
function vac_vet(array $over = []): array {
    return array_merge([
        'client_id' => 700, 'wp_user_id' => 7700,
        'vet_health_card' => 'K123', 'last_name' => 'Smith', 'first_name' => 'John',
        'street_name' => '1 Main St', 'city' => 'Moncton', 'postal_code' => 'E1A 1A1',
        'client_phone_1' => '5065550000', 'requisition_period' => 'week',
        'individual_id' => 'V-1', 'individual_id_index' => 'vidx1',
        'allocated_mains' => 12, 'allocated_tax_sides' => 5, 'allocated_nontax_sides' => 2,
        'bill_mains' => 12, 'bill_rate' => 9.05, 'fold_amount' => 0.0, 'fold_hst' => 0.0,
        'info_mains_allowance' => 31, 'info_sides_allowance' => 31,
        'info_monthly_allowance_cents' => 33000, 'info_sides_cost_cents' => 2870,
        'new_user_flag' => 'No',
    ], $over);
}

// ===========================================================================
// T-A3 / T-B1 — VAC clean veteran: mains-only, sides NOT in the total.
// ===========================================================================
$vac_csv = MealsDB_Invoice_Generator::serialize_vac_csv([700 => vac_vet()]);
$vrow    = csv_row_with($vac_csv, 'K123');
chk_true($vrow !== null, 'T-A3: VAC veteran row present');
chk($vrow[8]  ?? null, '9.05',   'T-A3: Rate = bill_rate');
chk($vrow[11] ?? null, '12',     'T-A3: Bill Mains');
chk($vrow[29] ?? null, '108.60', 'T-A3: Vet Mains Cost = 12 × 9.05');
chk($vrow[32] ?? null, '0.00',   'T-A3: Bill HST = fold_hst = 0');
chk($vrow[33] ?? null, '108.60', 'T-A3: New Total = mains-only (fold 0)');
chk($vrow[36] ?? null, '0.00',   'T-A3: Fold Amount = 0');
// T-B1: the OLD model would have added sides_cost + sides_HST = 140.38.
chk_true(($vrow[33] ?? '') !== '140.38', 'T-B1: sides are NOT summed into the VAC total (≠ old 140.38)');
// The allocated side COUNTS still appear for reference (info), just not billed.
chk($vrow[13] ?? null, '7', 'T-B1: Sides Ordered still shown (informational)');

// ===========================================================================
// T-B2 — fold flows into the total + the HST cell; PDF maps them positionally.
// ===========================================================================
$folded_csv = MealsDB_Invoice_Generator::serialize_vac_csv([
    700 => vac_vet(['fold_amount' => 28.70, 'fold_hst' => 3.08]),
]);
$frow = csv_row_with($folded_csv, 'K123');
chk($frow[32] ?? null, '3.08',   'T-B2: Bill HST cell = fold_hst');
chk($frow[33] ?? null, '140.38', 'T-B2: New Total = mains + fold_amount + fold_hst');
chk($frow[36] ?? null, '28.70',  'T-B2: Fold Amount column');
// The PDF stamps col 11 (meals), col 32 (HST), col 33 (total) — verify the
// positional mapping survives into the rendered HTML.
$pdf_html = MealsDB_Invoice_Generator::build_vac_pdf_html([$frow], '31/05/26', 'file:///x.jpg');
chk_true(strpos($pdf_html, '>12<') !== false, 'T-B2: PDF stamps the meal count (12)');
chk_true(strpos($pdf_html, '3.08') !== false, 'T-B2: PDF "(includes HST)" cell = fold_hst');
chk_true(strpos($pdf_html, '$140.38') !== false, 'T-B2: PDF total = vac_total');

// ===========================================================================
// T-B4 — rates from Definitions.
// ===========================================================================
// (a) SDNB side-rate override flows through the serializer's HST. With a
//     2-taxable-side row: HST = 2 × side_rate × 15%. Default side rate 4.48 →
//     1.34; override to 9.99 → 2 × 9.99 × 0.15 = 3.00.
$tax_row = [
    520 => [
        'client_id' => 520, 'wp_user_id' => 0,
        'first_name' => 'Tia', 'last_name' => 'Side', 'service_id' => '356029',
        'requisition_id' => 'REQ-2', 'individual_id' => 'IND-520', 'individual_id_index' => 'idx520',
        'delivery_area_zone' => 'M',
        'allocated_mains' => 2, 'allocated_sides' => 2,
        'allocated_tax_sides' => 2, 'allocated_nontax_sides' => 0,
        'resolved_rate' => 11.40, 'contribution_cents' => 0,
    ],
];
$GLOBALS['TEST_OPTIONS']['mealsdb_rate_definitions'] = []; // defaults
$default_csv = MealsDB_Invoice_Generator::serialize_sdnb_legacy($tax_row, ['zone' => 'M', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
chk((csv_row_with($default_csv, '3'))[34] ?? null, '1.34', 'T-B4: HST at the default Definitions side rate');
$GLOBALS['TEST_OPTIONS']['mealsdb_rate_definitions'] = ['rates' => ['sdnb_side' => 9.99]];
$over_csv = MealsDB_Invoice_Generator::serialize_sdnb_legacy($tax_row, ['zone' => 'M', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
chk((csv_row_with($over_csv, '3'))[34] ?? null, '3.00', 'T-B4: HST reflects the sdnb_side Definitions override');
$GLOBALS['TEST_OPTIONS']['mealsdb_rate_definitions'] = []; // reset

// (b) The VAC serializer carries the Definitions-derived monthly-allowance
//     figure (build sources info_monthly_allowance_cents from
//     vac_per_main_coverage) faithfully into column 28.
$cov_csv = MealsDB_Invoice_Generator::serialize_vac_csv([700 => vac_vet(['info_monthly_allowance_cents' => 34534])]);
chk((csv_row_with($cov_csv, 'K123'))[28] ?? null, '345.34', 'T-B4: VAC Monthly Allowance column carries the Definitions-derived figure');

// ===========================================================================
// T-B5 — reference characterization: a clean + a folded veteran in one run.
// ===========================================================================
$ref_csv = MealsDB_Invoice_Generator::serialize_vac_csv([
    700 => vac_vet(['vet_health_card' => 'K-CLEAN', 'last_name' => 'Clean', 'bill_mains' => 31, 'bill_rate' => 9.05]),
    701 => vac_vet(['client_id' => 701, 'vet_health_card' => 'K-FOLD', 'last_name' => 'Folded',
                    'bill_mains' => 31, 'bill_rate' => 9.05, 'fold_amount' => 12.40, 'fold_hst' => 1.86]),
]);
$clean = csv_row_with($ref_csv, 'K-CLEAN');
$fold  = csv_row_with($ref_csv, 'K-FOLD');
// Clean: total = 31 × 9.05 = 280.55, HST 0.00.
chk($clean[33] ?? null, '280.55', 'T-B5: clean veteran total = mains × rate');
chk($clean[32] ?? null, '0.00',   'T-B5: clean veteran HST 0.00');
// Folded: 280.55 + 12.40 + 1.86 = 294.81, HST 1.86.
chk($fold[33] ?? null, '294.81', 'T-B5: folded veteran bumped total');
chk($fold[32] ?? null, '1.86',   'T-B5: folded veteran (includes HST) = fold_hst');

// ===========================================================================
// T-B3 — the new editable fields classify correctly for the edit+audit path.
// fold_hst must be MONEY (it has no 'tax' substring — the classify_field 'hst'
// fix); fold_amount money; bill_rate money; bill_mains a count.
// ===========================================================================
$classify = new ReflectionMethod('MealsDB_Ajax_Invoice_Draft', 'classify_field');
$classify->setAccessible(true);
chk($classify->invoke(null, 'fold_hst'),    'money', 'T-B3: fold_hst classifies as money (not text)');
chk($classify->invoke(null, 'fold_amount'), 'money', 'T-B3: fold_amount classifies as money');
chk($classify->invoke(null, 'bill_rate'),   'money', 'T-B3: bill_rate classifies as money');
chk($classify->invoke(null, 'bill_mains'),  'count', 'T-B3: bill_mains classifies as a count');

// ---------------------------------------------------------------------------
echo "Ran " . ($passed + count($failures)) . " checks: $passed passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
