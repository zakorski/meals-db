# SDNB Billing System — Gap Analysis & Implementation Spec

## Summary

Your MealsDB plugin covers the invoice *output formats* well (SDNB Legacy CSV, SDNB New Portal CSV, VAC CSV, VAC PDF) but is missing the **billing logic engine** that sits between raw order data and those outputs. The old system's six plugins implemented a complex allowance→billable→overage pipeline that your plugin doesn't yet replicate.

This document maps every gap, explains what the old system did, and specs what needs to be built.

---

## 1. Schema gaps

### Fields missing from `meals_clients`

The old system stored these per-user values as WordPress user meta. Your schema has `units` (single INT) and `requisition_period`, but the old system tracked mains and sides separately:

| Old field (user meta) | Purpose | Current MealsDB equivalent | Action needed |
|---|---|---|---|
| `mains` | Number of main meals allowed per billing period | None (only `units` exists) | Add `allowance_mains INT NULL` |
| `sides` | Number of side items allowed per billing period | None | Add `allowance_sides INT NULL` |
| `service` | Billing frequency: `day`, `week`, or `month` | `requisition_period` (same values) | Already exists — use this field |
| `basic_cost` | Per-meal rate | `default_rate_id` → `meals_client_rates.rate` | Already exists — better design |
| `contribution` | Client co-pay amount | `client_contribution` | Already exists |
| `customer_group` | `veterans` or empty | `client_type` = `Veteran` | Already exists — better design |

**Recommendation:** Add two columns to `meals_clients`:

```sql
ALTER TABLE meals_clients
    ADD COLUMN allowance_mains INT NULL AFTER units,
    ADD COLUMN allowance_sides INT NULL AFTER allowance_mains;
```

The `units` field can remain as a general-purpose value. The new fields are specific to the allowance engine. Populate them during the historical data migration from the old WordPress user meta fields.

---

## 2. SDNB allowance calculation engine (biggest gap)

### What the old system does

For each SDNB client, the old `sdnb-month-end.php` converts per-user allowance settings into a monthly total:

```
Input: user.mains, user.sides, user.service, weeks_in_month, days_in_month

switch (service):
    case 'month':
        mains_allowed = (mains == 31) ? days_in_month : mains
        sides_allowed = (sides == 31) ? days_in_month : sides

    case 'week':
        mains_allowed = mains * weeks_in_month
        sides_allowed = sides * weeks_in_month
        // Override: 7/day = every day of month, 14/day = twice per day
        if mains == 7: mains_allowed = days_in_month
        if mains == 14: mains_allowed = 2 * days_in_month
        if sides == 7: sides_allowed = days_in_month
        if sides == 14: sides_allowed = 2 * days_in_month

    case 'day':
        mains_allowed = mains * days_in_month
        sides_allowed = sides * days_in_month
```

### What your plugin does

Your `generate_sdnb_legacy()` method treats each WC order as a flat row: `total_units × resolved_rate`. There's no concept of mains vs sides, no allowance cap, and no overage detection.

### What needs to happen

The SDNB Legacy invoice generator needs an allowance engine between the data fetch and the CSV build. This means:

1. The invoice form needs a **"Number of Wednesdays"** input (the old system had this)
2. Each client's orders need to be **split by product type** (meal vs side) using `meals_products.product_type`
3. Sides need to be further split into **taxable vs non-taxable** using `meals_products.taxable`
4. The allowance calculation above needs to run per-client
5. Items within the allowance are "billable"; items beyond are "overages"

---

## 3. Taxable vs non-taxable sides allocation (missing)

### What the old system does

The old system tracked four side categories by WooCommerce product category ID:
- **Taxable:** Desserts (cat 25) + Muffins (cat 37)
- **Non-taxable:** Cereal (cat 23) + Soup (cat 43)

Your `meals_products` table already has a `taxable` flag and a `product_type` enum — this is actually a better design than hardcoded category IDs. The allocation logic works like this:

```
total_sides = taxable_sides + non_taxable_sides

// Taxable sides get priority against the allowance
bill_tax_sides = min(sides_allowed, taxable_sides)
overage_tax_sides = taxable_sides - bill_tax_sides

// Whatever allowance remains goes to non-taxable
remaining_sides = max(0, sides_allowed - taxable_sides)  // only if overage_tax = 0
bill_nontax_sides = min(non_taxable_sides, remaining_sides)
overage_nontax_sides = non_taxable_sides - bill_nontax_sides

bill_sides = bill_tax_sides + bill_nontax_sides
```

### What your plugin does

Your VAC CSV generator distinguishes `sides_ordered_taxable` and `sides_ordered_nontax` using `meals_products.product_type` and `.taxable`, which is good. But the SDNB generator doesn't do this at all, and even the VAC generator has a placeholder `$sides_allowance = 10`.

### What needs to happen

Both the SDNB and VAC generators need the taxable-first allocation logic above. Your `meals_products` table already provides the foundation — you just need to aggregate by `(product_type, taxable)` per client in the invoice data fetch.

---

## 4. Two-line invoice logic (missing — was old Part Two plugin)

### What the old system does

The old `sdnb-part-two.php` takes the Part 1 CSV (with all the intermediate calculations) and produces the simplified government-submission CSV. The key logic: if a client's billable items don't all fit on one invoice line (because mains and sides have different rates), the client gets split across two lines:

**Line 1:** Primary rate ($14.66 or $15.47), units = min(bill_mains, bill_sides) if sides exist, otherwise just bill_mains. HST calculated with rate-specific multipliers.

**Line 2:** Secondary rate ($10.18 or $10.93 for remaining mains, $4.48 or $4.54 for remaining sides). Client contribution = $0 on line 2.

The "Second Line Flag" triggers when Line 2 has any units.

### What your plugin does

Your `generate_sdnb_legacy()` outputs one row per WC order, not per client. The old system aggregated all orders per client first, then applied the two-line split. Your output structure is entirely different.

### What needs to happen

The SDNB Legacy generator needs to:
1. Aggregate all orders per client (not one row per order)
2. Compute the allowance split (billable vs overage)
3. Apply the two-line logic
4. Output the simplified rows in the government format

**Important:** The rate-specific HST multipliers (0.672, 0.82, 0.681) and secondary rates ($10.18, $10.93, $4.48, $4.54) should be stored as configuration rather than hardcoded. Your `meals_client_rates` table could be extended, or a new `meals_billing_config` table/settings row could hold these.

---

## 5. VAC sides allowance model (partially implemented, wrong formula)

### What the old system does

```
monthly_allowance = mains_allowed * 10.64
vet_mains_cost = bill_mains * rate
allowance_remaining = max(monthly_allowance - vet_mains_cost, 0)
new_sides = max(floor(allowance_remaining / 4.715), 0)

// Taxable sides allocated first against new_sides
bill_tax_sides = min(new_sides, taxable_overages)
sides_remaining = max(new_sides - bill_tax_sides, 0)
bill_nontax_sides = min(sides_remaining, non_taxable_sides)

sides_cost = (bill_nontax_sides + bill_tax_sides) * 4.10
bill_hst = round((bill_tax_sides * 4.10) * 0.15, 2)
new_total = vet_mains_cost + sides_cost + bill_hst
```

The old system also had 5-week month corrections:
```
if mains_allowed == 35: mains_allowed = 31
if mains_allowed == 70: mains_allowed = 62
if sides_allowed == 35: sides_allowed = 31
if sides_allowed == 70: sides_allowed = 62
```

### What your plugin does

Your `generate_vac_csv()` has the monetary allowance framework with `$vac_allowances`, but:
- `$sides_allowance = 10` is a static placeholder — it should be dynamically derived from `floor(allowance_remaining / 4.715)`
- The cost-per-side ($4.10), HST rate (15%), and conversion rate ($4.715, $10.64) are not present
- The 5-week correction is missing
- The taxable-first allocation against derived sides allowance is missing

### What needs to happen

Replace the `$sides_allowance = 10` block with the old system's monetary allowance → sides conversion logic. The magic numbers ($10.64, $4.715, $4.10, 15%) should be stored as configurable values.

---

## 6. Error detection and new user flags (missing)

### What the old system does

For each client, it checks:
- Missing `service_id` → error flag
- Missing `requisition_id` → error flag
- Missing `individual_id` → error flag
- `individual_id` appearing on more than 2 user records (SDNB) or more than 1 (Veterans) → "Duplicate person" flag
- User registered during the billing period → "New account" flag with registration date

### What your plugin does

The error column in your VAC CSV is always empty (`$errors = ''`). The new user flag is always `'No'`.

### What needs to happen

Add validation logic before the CSV row is built. Your data model is better positioned for this — you can check `meals_clients` directly rather than querying WordPress user meta. The duplicate check can use the `individual_id_index` column you already have.

---

## 7. Overages import pipeline (entirely missing)

### What the old system does

Two plugins (`overages-importer` and `vet-overages`) take the month-end CSV report, parse the overage columns (BNM Mains, Overage Tax Sides, Overage Non-Taxable Sides), and create WooCommerce orders for those overages using three hardcoded product IDs:
- Product 5056 = Overage Main
- Product 5059 = Overage Non-Taxable Side
- Product 5180 = Overage Taxable Side

The workflow is: upload CSV → preview which clients have overages → confirm → create WC orders.

A third plugin (`historical-overages`) exports a report of who has been charged overages in a given date range.

### What your plugin does

Nothing. No overages import capability exists.

### What needs to happen

This is the last piece of the pipeline and depends on the allowance engine (gaps 2–5) being built first, since overages are defined as the excess over allowance. The implementation could be:

1. A new admin page under the MealsDB menu: "Import Overages"
2. Upload the month-end CSV (or better: generate the overages directly from the same data the invoice used, skipping the CSV round-trip)
3. Preview clients with non-zero overages
4. Create WC orders via `wc_create_order()` with the overage products
5. Write `mealsdb_rate_id` and `mealsdb_client_id` meta on the created orders

The historical overages exporter is a reporting function that could be added to your existing `MealsDB_Reports` class.

---

## 8. Implementation priority

| Priority | Gap | Effort | Dependency |
|---|---|---|---|
| 1 | Schema: add `allowance_mains`, `allowance_sides` | Small | None |
| 2 | SDNB allowance calculation engine | Large | Schema change |
| 3 | Taxable/non-taxable sides allocation | Medium | Allowance engine |
| 4 | VAC sides allowance fix ($4.715 formula) | Small | None |
| 5 | Two-line invoice logic | Medium | Allowance + sides allocation |
| 6 | Error detection and new user flags | Small | None |
| 7 | HST multiplier configuration | Small | Two-line logic |
| 8 | Overages importer | Medium | Allowance engine |
| 9 | Historical overages report | Small | Overages importer |

Items 1–3 are the critical path. The SDNB Legacy invoice cannot produce correct output without them. Item 4 is independent and can be done anytime. Items 5–7 complete the SDNB pipeline. Items 8–9 close the loop.
