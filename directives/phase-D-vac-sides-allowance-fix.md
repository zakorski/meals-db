# Phase D — Fix VAC Sides Allowance Calculation

## Objective

Replace the hardcoded `$sides_allowance = 10` placeholder in `generate_vac_csv()` with the correct monetary-allowance-derived formula from the old billing system, and add the 5-week month corrections.

---

## Context — How the old Veterans billing model works

Veterans Affairs Canada uses a **monetary allowance** model, not a unit-count model. The flow:

1. Each veteran has a monthly monetary allowance (based on their service frequency)
2. Billable mains are costed first: `vet_mains_cost = bill_mains × rate`
3. The remaining allowance converts to a number of sides: `new_sides = floor(remaining / $4.715)`
4. Taxable sides are allocated first against `new_sides`, then non-taxable with the remainder
5. Sides are costed at $4.10 each, HST at 15% on taxable sides only

### The old system's exact constants (from `vet-invoice.php`):

| Constant | Value | Purpose |
|---|---|---|
| Per-main allowance rate | $10.64 | `monthly_allowance = mains_allowed × 10.64` |
| Sides conversion divisor | $4.715 | `new_sides = floor(allowance_remaining / 4.715)` |
| Sides cost rate | $4.10 | `sides_cost = total_bill_sides × 4.10` |
| HST rate on taxable sides | 15% | `bill_hst = round((bill_tax_sides × 4.10) × 0.15, 2)` |

### 5-week month corrections (from `vet-invoice.php`, lines 362–371):

When weekly allowances produce values that overshoot the month, the old system corrects:

```
if mains_allowed == 35: mains_allowed = 31
if mains_allowed == 70: mains_allowed = 62
if sides_allowed == 35: sides_allowed = 31
if sides_allowed == 70: sides_allowed = 62
```

---

## Step 1 — Add VAC billing constants

**File:** `includes/services/class-invoice-generator.php`

Add a new private static property after the existing `$vac_allowances` property (after line 54):

```php
/**
 * VAC billing constants (contractual rates).
 */
private static $vac_billing = [
    'per_main_allowance'     => 10.64,  // Monthly allowance = mains_allowed × this
    'sides_conversion_rate'  => 4.715,  // Remaining allowance ÷ this = sides allowed
    'sides_cost_rate'        => 4.10,   // Cost per billable side
    'sides_hst_rate'         => 0.15,   // HST on taxable sides (15%)
];
```

---

## Step 2 — Rewrite the allowance and billing calculations in `generate_vac_csv()`

**File:** `includes/services/class-invoice-generator.php`

### 2.1 Update the client query to include allowance fields

In `generate_vac_csv()`, the SQL query (around line 461) currently selects:

```sql
SELECT client_id, wp_user_id, first_name, last_name, requisition_id,
       vet_health_card, requisition_period, client_contribution, default_rate_id,
       apartment_number, street_number, street_name, city, postal_code, client_phone_1
FROM meals_clients
WHERE client_type = ? AND active = 1 AND wp_user_id > 0
```

Add the allowance columns:

```sql
SELECT client_id, wp_user_id, first_name, last_name, requisition_id,
       vet_health_card, requisition_period, client_contribution, default_rate_id,
       apartment_number, street_number, street_name, city, postal_code, client_phone_1,
       allowance_mains, allowance_sides
FROM meals_clients
WHERE client_type = ? AND active = 1 AND wp_user_id > 0
```

### 2.2 Replace the billing calculation block

Find the block starting at the comment `// Get allowance info` (around line 593) through the line `$bill_sides = $bill_nontax_sides + $bill_tax_sides;` (around line 621).

Replace that entire block with:

```php
// --- Veteran allowance calculation ---
$user_mains   = (int) ($vet['allowance_mains'] ?? 0);
$user_sides   = (int) ($vet['allowance_sides'] ?? 0);
$service      = strtolower($vet['requisition_period'] ?: 'week');
$days_in_month = (int) date('t', strtotime($end_date));

// Calculate mains allowance from service frequency.
$mains_allowance = 0;
$sides_allowance_raw = 0;

switch ($service) {
    case 'month':
        $mains_allowance    = min($user_mains, $days_in_month);
        $sides_allowance_raw = min($user_sides, $days_in_month);
        break;
    case 'day':
        $mains_allowance    = $user_mains * $days_in_month;
        $sides_allowance_raw = $user_sides * $days_in_month;
        break;
    case 'week':
    default:
        // Weekly: standard multiplication.
        if ($user_mains == 7) {
            $mains_allowance = $days_in_month;
        } elseif ($user_mains == 14) {
            $mains_allowance = 2 * $days_in_month;
        } elseif ($user_mains <= 6) {
            // weeks_in_month not used for VAC; approximate with 4.
            $mains_allowance = $user_mains * 4;
        }
        if ($user_sides == 7) {
            $sides_allowance_raw = $days_in_month;
        } elseif ($user_sides == 14) {
            $sides_allowance_raw = 2 * $days_in_month;
        } elseif ($user_sides <= 6) {
            $sides_allowance_raw = $user_sides * 4;
        }
        break;
}

// 5-week month corrections.
if ($mains_allowance == 35) { $mains_allowance = 31; }
elseif ($mains_allowance == 70) { $mains_allowance = 62; }
if ($sides_allowance_raw == 35) { $sides_allowance_raw = 31; }
elseif ($sides_allowance_raw == 70) { $sides_allowance_raw = 62; }

// Mains billing.
$bill_mains       = min($mains_ordered, $mains_allowance);
$bnm_mains        = max(0, $mains_ordered - $mains_allowance);
$vet_mains_cost   = $bill_mains * $resolved_rate;

// Monetary allowance → sides conversion.
$monthly_allowance   = $mains_allowance * self::$vac_billing['per_main_allowance'];
$allowance_remaining = max(0, $monthly_allowance - $vet_mains_cost);
$new_sides           = max(0, (int) floor($allowance_remaining / self::$vac_billing['sides_conversion_rate']));

// Use the derived sides count as the actual sides allowance.
$sides_allowance = $new_sides;

// Taxable sides first against the derived allowance.
$bill_tax_sides       = min($sides_ordered_taxable, $sides_allowance);
$overage_tax_sides    = max(0, $sides_ordered_taxable - $sides_allowance);
$remaining_sides      = max(0, $sides_allowance - $bill_tax_sides);

// Non-taxable sides fill the remainder.
$bill_nontax_sides    = min($sides_ordered_nontax, $remaining_sides);
$overage_nontax_sides = max(0, $sides_ordered_nontax - $bill_nontax_sides);

$bill_sides = $bill_tax_sides + $bill_nontax_sides;

// Cost calculations.
$sides_cost = ($bill_tax_sides + $bill_nontax_sides) * self::$vac_billing['sides_cost_rate'];
$sides_tax  = round(($bill_tax_sides * self::$vac_billing['sides_cost_rate']) * self::$vac_billing['sides_hst_rate'], 2);
$new_total  = $vet_mains_cost + $sides_cost + $sides_tax;
```

### 2.3 Update the CSV row output

The existing CSV row builder (the `sprintf` block starting around line 627) already has the right column positions. Update the following field references in that block:

- `$sides_allowance` → now correctly computed (was hardcoded to 10)
- `$monthly_allowance` → now correctly computed from `$mains_allowance × 10.64`
- `$vet_mains_cost` → already correct
- `$allowance_remaining` → now correctly computed
- `$sides_cost` → now computed at $4.10/side
- `$sides_tax` → now computed as `bill_tax_sides × $4.10 × 0.15`
- `$new_total` → now computed as `vet_mains_cost + sides_cost + sides_tax`

These variable names already exist in the current `sprintf` block. The formulas have changed but the variable names feeding the output remain the same, so the CSV row output should work without additional changes to the `sprintf` template.

---

## Step 3 — Update `generate_vac_pdf()` (no code changes needed)

The PDF generator (`generate_vac_pdf()`, line 680) reads its data by calling `generate_vac_csv()` and parsing the CSV output. Since the CSV column positions are unchanged, the PDF will automatically reflect the corrected calculations. No changes needed.

---

## Verification checklist

- [ ] `$vac_billing` is a private static property on `MealsDB_Invoice_Generator` with keys: `per_main_allowance` (10.64), `sides_conversion_rate` (4.715), `sides_cost_rate` (4.10), `sides_hst_rate` (0.15)
- [ ] `$sides_allowance` is now derived from `floor(allowance_remaining / 4.715)`, NOT hardcoded to 10
- [ ] `$monthly_allowance` is computed as `$mains_allowance * 10.64`
- [ ] `$allowance_remaining` is `max(0, $monthly_allowance - $vet_mains_cost)`
- [ ] `$sides_cost` is `($bill_tax_sides + $bill_nontax_sides) * 4.10`
- [ ] `$sides_tax` is `round(($bill_tax_sides * 4.10) * 0.15, 2)`
- [ ] 5-week corrections are applied: 35→31, 70→62 for both mains and sides
- [ ] Taxable sides are allocated first against the derived allowance, non-taxable fill the remainder
- [ ] The SQL query includes `allowance_mains` and `allowance_sides` in the SELECT
- [ ] The CSV header row is unchanged (same column count and names)
- [ ] The PDF generator (`generate_vac_pdf`) was NOT modified — it reads from the CSV output
- [ ] No new files, classes, or database tables were created
