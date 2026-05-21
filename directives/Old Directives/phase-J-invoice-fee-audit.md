# Phase J: Invoice Fee Handling Audit & Fixes

## Goal

Ensure `client_contribution` and `delivery_fee` are correctly reflected on all invoice types. This phase is mostly a verification — most of the work is already done. But there are two gaps to close.

## Current state of fee handling per invoice type

### SDNB Legacy Invoice (`generate_sdnb_legacy()`)

**`client_contribution`: ALREADY HANDLED**

The invoice already:
- Reads `client_contribution` from the `meals_clients` query (line 616)
- Subtracts it from total cost: `$total_cost = $basic_cost + $tax_amount - $client_contribution` (line 167)
- Outputs it in CSV column 23 (line 783): `$row[23] = number_format($line['client_contribution'], 2, '.', '')`
- Only applies contribution on line 1 of a two-line invoice; line 2 always has `client_contribution = 0` (line 477)

**`delivery_fee`: NOT ON THIS INVOICE — CORRECT**

The old `sdnb-month-end.php` plugin never included delivery fees on the SDNB invoice. This is correct — delivery fees are collected separately via WC product 4122 and reconciled through the delivery fee checker. No change needed.

### SDNB New Portal Invoice (`generate_sdnb_new_portal()`)

**`client_contribution`: PARTIALLY HANDLED — NEEDS FIX**

The new portal CSV has a "Client Contribution" column (column index 15 in the header at line 825). However, the data rows currently output an empty string for this column:

```php
'', // Client Contribution
```

This needs to be populated with the actual `client_contribution` value.

**Fix required in `generate_sdnb_new_portal()`** (around line 855):

Find the line that outputs the Client Contribution column (look for the comment `// Client Contribution`):

```php
// BEFORE:
'', // Client Contribution
```

Replace with:
```php
number_format((float) ($r['client_contribution'] ?? 0), 2, '.', ''), // Client Contribution
```

The `$r` variable comes from the `$invoice_rows` array which already contains `client_contribution` because `get_invoice_data_for_clients()` reads it from meals_clients.

Verify that `client_contribution` is included in the SELECT for the new portal query. Check the SQL around line 808:

```sql
client_contribution, default_rate_id
```

If `client_contribution` is already in the SELECT (it is), this should be the only change needed.

**`delivery_fee`: NOT ON THIS INVOICE — CORRECT**

Same as legacy. No change needed.

### VAC CSV Invoice (`generate_vac_csv()`)

**`client_contribution`: NOT USED — CORRECT**

The VAC billing model uses a monetary allowance system, not a contribution deduction. Client contribution is not part of the VAC invoice format. The old `vet-invoice.php` read the value but never output it. No change needed.

**`delivery_fee`: NOT ON THIS INVOICE — CORRECT**

No change needed. Delivery fees are collected separately.

### VAC PDF Invoice (`generate_vac_pdf()`)

Calls `generate_vac_csv()` internally. No separate fee logic needed.

---

## Summary of changes required

| Invoice Type | `client_contribution` | `delivery_fee` | Action |
|---|---|---|---|
| SDNB Legacy | Already deducted from total + output in col 23 | Not applicable | None |
| SDNB New Portal | Column exists in header but data outputs empty string | Not applicable | **FIX: populate the Client Contribution data column** |
| VAC CSV | Not applicable to VAC billing model | Not applicable | None |
| VAC PDF | Calls VAC CSV | Not applicable | None |

---

## The fix

**File:** `includes/services/class-invoice-generator.php`

**Method:** `generate_sdnb_new_portal()`

Find the `sprintf()` call that builds each CSV data row (around lines 838–858). Locate the `// Client Contribution` placeholder and replace the empty string with the actual value.

The invoice rows come from `get_invoice_data_for_clients()` which returns rows with these keys: `client_contribution`, `total_units`, `resolved_rate`, `tax_amount`, etc.

The fix is a single line change — replace `''` with `number_format((float) ($r['client_contribution'] ?? 0), 2, '.', '')`.

---

## Delivery fee on delivery slips (separate from invoices)

Delivery fees are NOT on government invoices but they ARE displayed on Jim's delivery driver PDF. Your plugin's `class-delivery-slip-generator.php` does not currently show delivery fees. If you want parity with the old `woo-order-export` plugin's Jim's export, you would need to:

1. Add `delivery_fee` to the delivery slip SQL SELECT
2. In the PDF/HTML output, display "Delivery Fee: $X" and "Collect: $X" for clients with a non-zero fee

This is a separate enhancement beyond the scope of this invoice fix. The driver slips in `woo-order-export` used a completely different code path (TCPDF with inline layout) that showed:
- For private customers paying cash: "Collect: $(total + delivery_fee)"  
- For non-private customers with a fee: "Collect: $(delivery_fee)"
- Always if fee > 0: "Delivery Fee: $(delivery_fee)"

If you want to replicate this, create a separate directive for it.

---

## Key constraints

- Only one line of code needs to change for the invoice fix
- Do NOT add delivery fees to government invoices — they are collected separately via WC order line items
- The `client_contribution` value is a fixed amount per client, NOT computed from order data
- Do NOT modify the CSV header — it already has the correct column name
