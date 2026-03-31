# Phase C — Two-Line Invoice Logic & SDNB Legacy CSV Rewrite

## Objective

Rewrite the CSV-building portion of `generate_sdnb_legacy()` to consume the allowance data from Phase B and produce the government-format CSV with proper two-line invoice splitting. This replaces the old "Part Two" plugin entirely — your system will produce the final submission-ready CSV in a single step.

---

## Context — How the old two-line logic worked

The old system needed TWO plugins (Part One + Part Two) because Part One generated a 37-column diagnostic CSV and Part Two transformed it into the 11-column government submission CSV. Your plugin should do this in one pass.

The two-line logic exists because SDNB billing uses different rates when a client's billable mains and sides don't align perfectly. When a client has billable mains AND billable sides, the items are split across two invoice lines at different rates.

### Rate tiers (from old system)

The old system supported exactly two rate tiers. The specific rate values were:

| Primary rate | Secondary rate (mains) | Secondary rate (sides) | HST multiplier Line 1 | HST multiplier Line 2 |
|---|---|---|---|---|
| $14.66 | $10.18 | $4.48 | 0.672 | 0.672 |
| $15.47 | $10.93 | $4.54 | 0.82 | 0.681 |

### Line splitting rules

```
IF client has sides AND mains:
    Line 1 units = min(bill_mains, bill_sides)
    Line 1 rate  = primary rate
    Line 1 tax   = taxable_sides_on_line_1 * HST_multiplier_line_1

    Line 2 units = remaining mains + remaining taxable sides + remaining non-taxable sides
    Line 2 rate  = secondary rate (mains rate if remaining mains > 0, sides rate otherwise)
    Line 2 tax   = taxable_sides_on_line_2 * HST_multiplier_line_2
    Line 2 client_contribution = 0

ELSE (mains only, no sides):
    Line 1 units = bill_mains
    Line 1 rate  = primary rate
    Line 1 tax   = HST from Line 1
    No Line 2.
```

---

## Step 1 — Add rate tier configuration

**File:** `includes/services/class-invoice-generator.php`

Add a private static property after the existing `$service_centers` property (after line 45). This replaces the hardcoded values scattered throughout the old system:

```php
/**
 * SDNB rate tiers for two-line invoice calculations.
 *
 * Each primary rate maps to its secondary rates and HST multipliers.
 * These values come from the old billing system and are contractual.
 */
private static $sdnb_rate_tiers = [
    '14.66' => [
        'secondary_rate_mains' => 10.18,
        'secondary_rate_sides' => 4.48,
        'hst_multiplier_line1' => 0.672,
        'hst_multiplier_line2' => 0.672,
    ],
    '15.47' => [
        'secondary_rate_mains' => 10.93,
        'secondary_rate_sides' => 4.54,
        'hst_multiplier_line1' => 0.82,
        'hst_multiplier_line2' => 0.681,
    ],
];
```

---

## Step 2 — Add the two-line split method

**File:** `includes/services/class-invoice-generator.php`

Add this private static method after `get_allowance_data_for_clients()` (from Phase B):

```php
/**
 * Split a client's allowance row into one or two invoice lines.
 *
 * @param array $row Single client row from get_allowance_data_for_clients().
 * @return array Array of 1 or 2 invoice line arrays.
 */
private static function split_into_invoice_lines(array $row): array {
    $rate = (float) $row['resolved_rate'];
    $rate_key = number_format($rate, 2, '.', '');
    $tier = isset(self::$sdnb_rate_tiers[$rate_key]) ? self::$sdnb_rate_tiers[$rate_key] : null;

    $bill_mains       = (int) $row['bill_mains'];
    $bill_sides       = (int) $row['bill_sides'];
    $bill_tax_sides   = (int) $row['bill_tax_sides'];
    $bill_nontax_sides = (int) $row['bill_nontax_sides'];
    $client_contribution = (float) $row['client_contribution'];

    $hst_mult_l1 = $tier ? $tier['hst_multiplier_line1'] : 0;
    $hst_mult_l2 = $tier ? $tier['hst_multiplier_line2'] : 0;

    // Line 1 calculations.
    $mains_on_line_1 = ($bill_sides == 0) ? $bill_mains : min($bill_mains, $bill_sides);
    $tax_sides_on_line_1 = ($bill_sides == 0 || $bill_tax_sides == 0)
        ? 0 : min($mains_on_line_1, $bill_tax_sides);
    $nontax_sides_on_line_1 = ($bill_sides == 0 || $bill_nontax_sides == 0)
        ? 0 : min($mains_on_line_1 - $tax_sides_on_line_1, $bill_nontax_sides);
    $hst_line_1 = ($tax_sides_on_line_1 != 0) ? round($tax_sides_on_line_1 * $hst_mult_l1, 2) : 0;

    // Line 2 calculations.
    $mains_on_line_2       = max(0, $bill_mains - $mains_on_line_1);
    $tax_sides_on_line_2   = $bill_tax_sides - $tax_sides_on_line_1;
    $nontax_sides_on_line_2 = $bill_nontax_sides - $nontax_sides_on_line_1;
    $hst_line_2 = ($tax_sides_on_line_2 != 0) ? round($tax_sides_on_line_2 * $hst_mult_l2, 2) : 0;

    $has_second_line = ($mains_on_line_2 + $tax_sides_on_line_2 + $nontax_sides_on_line_2 + $hst_line_2) > 0;

    // Determine second line rate.
    $second_line_rate = 0;
    if ($has_second_line && $tier) {
        $second_line_rate = ($mains_on_line_2 > 0)
            ? $tier['secondary_rate_mains']
            : (($tax_sides_on_line_2 + $nontax_sides_on_line_2 > 0)
                ? $tier['secondary_rate_sides']
                : 0);
    }

    $client = $row['client'];
    $lines = [];

    // Line 1.
    $units_l1 = $mains_on_line_1;
    $lines[] = [
        'service_id'          => $client['service_id'] ?? '',
        'requisition_id'      => $client['requisition_id'] ?? '',
        'individual_id'       => $client['individual_id'] ?? '',
        'last_name'           => $client['last_name'] ?? '',
        'first_name'          => $client['first_name'] ?? '',
        'units'               => $units_l1,
        'unit_type'           => 'Meal',
        'rate'                => $rate,
        'basic_cost'          => $units_l1 * $rate,
        'client_contribution' => $client_contribution,
        'tax'                 => $hst_line_1,
    ];

    // Line 2 (if needed).
    if ($has_second_line) {
        $units_l2 = $mains_on_line_2 + $tax_sides_on_line_2 + $nontax_sides_on_line_2;
        $lines[] = [
            'service_id'          => $client['service_id'] ?? '',
            'requisition_id'      => $client['requisition_id'] ?? '',
            'individual_id'       => $client['individual_id'] ?? '',
            'last_name'           => $client['last_name'] ?? '',
            'first_name'          => $client['first_name'] ?? '',
            'units'               => $units_l2,
            'unit_type'           => 'Meal',
            'rate'                => $second_line_rate,
            'basic_cost'          => $units_l2 * $second_line_rate,
            'client_contribution' => 0, // Always 0 on second line
            'tax'                 => $hst_line_2,
        ];
    }

    return $lines;
}
```

---

## Step 3 — Rewrite the CSV-building section of `generate_sdnb_legacy()`

**File:** `includes/services/class-invoice-generator.php`

Replace everything from the current "Accumulate totals for header" comment (line 228) through the end of the method (line 381) with the following.

The government CSV format (Electronic Invoice Datasheet v36e) header rows (rows 1–6) remain exactly as they are now. The change is in how data rows are generated.

```php
// Apply allowance engine + two-line splits to get final invoice lines.
$all_invoice_lines = [];
foreach ($invoice_rows as $row) {
    $lines = self::split_into_invoice_lines($row);
    foreach ($lines as $line) {
        $all_invoice_lines[] = $line;
    }
}

// Accumulate totals for header.
$total_invoice_amount = 0;
$total_tax_amount     = 0;
foreach ($all_invoice_lines as $line) {
    $total_cost = $line['basic_cost'] + $line['tax'] - $line['client_contribution'];
    $total_invoice_amount += $total_cost;
    $total_tax_amount     += $line['tax'];
}

// Build CSV content.
$csv = [];

// Row 1: Blank row with commas (unchanged from current implementation)
$csv[] = str_repeat(',', 99);

// Row 3: Header with version (unchanged)
$row3 = array_fill(0, 100, '');
$row3[0] = '1';
$row3[1] = 'Social Development';
$row3[5] = 'Electronic Invoice Datasheet';
$row3[9] = 'version 36e';
$csv[] = implode(',', $row3);

// Row 4: Invoice metadata header row (unchanged from current)
$row4 = array_fill(0, 100, '');
$row4[0] = '1';
$row4[1] = 'Invoice No.';
$row4[2] = 'Vendor No.';
$row4[3] = 'Vendor Name';
$row4[5] = 'Vendor Address';
$row4[6] = 'Service Center No';
$row4[7] = 'Service Center Name';
$row4[10] = 'Service Center Address';
$row4[12] = 'Billing Period Start Date';
$row4[13] = 'Billing Period End Date';
$row4[14] = 'Tax Indicator';
$row4[15] = 'HST / GST #';
$row4[16] = 'Tax Amount';
$row4[17] = 'Total Invoice Amount';
$row4[18] = 'Contact Person';
$row4[20] = 'Contact Area Code';
$row4[21] = 'Contact Phone No.';
$row4[22] = 'Contact E-mail';
$row4[23] = '# of Invoice Lines';
$csv[] = implode(',', $row4);

// Row 5: Invoice metadata values (unchanged structure, updated totals)
$row5 = array_fill(0, 100, '');
$row5[0] = '2';
$row5[1] = $invoice_number;
$row5[2] = self::VENDOR_NUMBER;
$row5[3] = self::VENDOR_NAME;
$row5[5] = self::VENDOR_ADDRESS;
$row5[6] = $service_center['number'];
$row5[7] = $service_center['name'];
$row5[10] = $service_center['address'];
$row5[12] = str_replace('-', '', $start_date);
$row5[13] = str_replace('-', '', $end_date);
$row5[14] = 'Full';
$row5[15] = self::HST_NUMBER;
$row5[16] = number_format($total_tax_amount, 2, '.', '');
$row5[17] = number_format($total_invoice_amount, 2, '.', '');
$row5[18] = self::CONTACT_PERSON;
$row5[20] = self::CONTACT_AREA_CODE;
$row5[21] = self::CONTACT_PHONE;
$row5[22] = self::CONTACT_EMAIL;
$row5[23] = count($all_invoice_lines);
$row5[24] = 'F';
$csv[] = implode(',', $row5);

// Row 6: Column headers for data rows (unchanged)
$row6 = array_fill(0, 100, '');
$row6[0] = '1';
$row6[1] = 'Service Id';
$row6[2] = 'Requisition Id';
$row6[3] = 'Individual Id';
$row6[4] = 'Client Last Name';
$row6[5] = 'Client First Name';
$row6[6] = 'No. of Units';
$row6[7] = 'Unit Type';
$row6[8] = 'Rate';
$row6[9] = 'Basic Cost';
$row6[10] = 'Total Kilometers - (transportation - home support)';
$row6[11] = 'Other Cost (transportation - home support)';
$row6[12] = 'Total Kilometers (transportation - family support worker)';
$row6[13] = 'Other Cost (transportation - family support worker)';
$row6[14] = 'Other Cost (transportation - medical)';
$row6[15] = 'Other Cost (transportation - daycare)';
$row6[16] = 'Other Cost (transportation - other)';
$row6[17] = 'Other Cost (meals)';
$row6[18] = 'Other Cost (sundry)';
$row6[19] = 'Other Cost  (admin fees)';
$row6[20] = 'Other Cost (lodging)';
$row6[21] = 'Other Cost (recreation)';
$row6[22] = 'Other Cost (parking)';
$row6[23] = 'Client Contribution';
$row6[24] = 'Dept. Cost';
$row6[25] = 'Mileage Cost Indicator';
$row6[26] = 'Mileage Cost';
$row6[27] = 'Stat Holiday Units';
$row6[28] = 'Stat. Holiday Amt';
$row6[29] = 'Shift Diff. Units';
$row6[30] = 'Shift Diff. Rate';
$row6[31] = 'Shift Diff. Cost';
$row6[32] = 'Shift Diff. Stat Holiday Units';
$row6[33] = 'Shift Diff. Stat Holiday Cost';
$row6[34] = 'Tax';
$row6[35] = 'Total Invoice Line Cost';
$csv[] = implode(',', $row6);

// Data rows — one per invoice line.
foreach ($all_invoice_lines as $line) {
    $basic_cost = $line['basic_cost'];
    $total_line_cost = $basic_cost + $line['tax'] - $line['client_contribution'];

    $row = array_fill(0, 100, '');
    $row[0]  = '3';
    $row[1]  = $line['service_id'] ?: '356029';
    $row[2]  = $line['requisition_id'] ?: '';
    $row[3]  = $line['individual_id'] ?: '';
    $row[4]  = $line['last_name'] ?: '';
    $row[5]  = $line['first_name'] ?: '';
    $row[6]  = number_format($line['units'], 2, '.', '');
    $row[7]  = 'Meal';
    $row[8]  = number_format($line['rate'], 2, '.', '');
    $row[9]  = number_format($basic_cost, 2, '.', '');
    $row[23] = number_format($line['client_contribution'], 2, '.', '');
    $row[24] = number_format($basic_cost, 2, '.', '');
    $row[27] = number_format(0, 2, '.', '');
    $row[30] = number_format(0, 2, '.', '');
    $row[33] = number_format(0, 2, '.', '');
    $row[34] = number_format($line['tax'], 2, '.', '');
    $row[35] = number_format($total_line_cost, 2, '.', '');
    $row[36] = 'I';
    $csv[] = implode(',', $row);
}

return implode("\n", $csv);
```

---

## Verification checklist

- [ ] `$sdnb_rate_tiers` is a private static property on `MealsDB_Invoice_Generator` with entries for `'14.66'` and `'15.47'`
- [ ] `split_into_invoice_lines()` is a private static method that returns 1 or 2 line arrays
- [ ] Line 2 always has `client_contribution = 0`
- [ ] Line 2 rate is `secondary_rate_mains` when remaining mains > 0, `secondary_rate_sides` when only sides remain
- [ ] HST uses `hst_multiplier_line1` for Line 1 and `hst_multiplier_line2` for Line 2
- [ ] The CSV header rows (rows 1–6) are byte-identical to the current output
- [ ] Row 5 column 23 (`# of Invoice Lines`) reflects the total lines after splitting
- [ ] Data rows use row type `'3'` in column 0 and flag `'I'` in column 36
- [ ] Empty transportation/mileage/shift columns remain empty (unchanged from current)
- [ ] No new files, classes, or database tables were created
