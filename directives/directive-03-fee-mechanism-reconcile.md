# Directive: Reconcile Quick Order Fee Mechanism with Legacy Reports

**Severity:** CRITICAL (CRIT-3)
**Audit reference:** `recon-08-phase-w.md` "CRITICAL OBSERVATION"; `recon-09-synthesis.md` CRIT-3
**Target files:** Multiple — see scope below
**Estimated scope:** ~150-250 lines across 3-5 files (read-only audit + 1-3 small writes)
**Risk:** MEDIUM — touches billing report calculations; must not double-count fees
**Must complete before:** shadow-mode trial comparison reports are run (otherwise comparison shows false discrepancies)

---

## Context

The plugin has **two distinct fee mechanisms** for SDNB/Veteran client orders:

### Mechanism A: Legacy product-ID-as-fee
The original Enzebra system added fees as WC order line items keyed to specific product IDs:
- Client Contribution: product ID **5675**
- Delivery Fee: product ID **4122**

The total appears as a `WC_Order_Item_Product` (line item), not as a `WC_Order_Item_Fee`. Reports built for this mechanism query order items by product ID.

### Mechanism B: Quick Order's WC_Order_Item_Fee
The Quick Order subsystem (`MealsDB_Quick_Order_Ajax::create_wc_order`, around lines 853-907 of `class-quick-order-ajax.php`) adds fees as proper `WC_Order_Item_Fee` objects named "Delivery Fee" and "Client Contribution". Product IDs 5675/4122 are not involved.

**Both mechanisms produce orders that bill the customer correctly.** The contribution-once-per-month rule is enforced in both via `meals_client_allocations.contribution_applied`. **The financial math is correct in both cases.**

The problem is **reporting visibility**:

- Reports that query order items WHERE `product_id = 5675` will see Mechanism A fees but miss Mechanism B fees.
- Reports that query order items WHERE `order_item_type = 'fee'` AND name matches will see Mechanism B fees but miss Mechanism A fees.

During the shadow-mode trial, comparison reports between the legacy and new systems will show **systematic differences** that aren't real billing differences. This will obscure real bugs.

---

## Pre-flight verification

### Step P1: Confirm both mechanisms exist on the staging DB

Run these queries against staging:

```bash
# Count of orders with Mechanism A fees (product 5675 as line item)
wp db query "SELECT COUNT(DISTINCT order_id)
  FROM 2xnIt_woocommerce_order_itemmeta
  WHERE meta_key = '_product_id' AND meta_value = '5675'"

# Count of orders with Mechanism B fees (Order Item Fee named 'Client Contribution')
wp db query "SELECT COUNT(DISTINCT order_id)
  FROM 2xnIt_woocommerce_order_items
  WHERE order_item_type = 'fee' AND order_item_name = 'Client Contribution'"
```

Expected: both counts > 0. The first count covers historical legacy-system orders; the second covers any orders already created via Quick Order in v1.0.346.

If the second count is zero, this directive's risk is reduced — there are no Mechanism B fees in production yet, so reports haven't yet shown discrepancies. Note this in your response but still proceed.

### Step P2: Identify ALL reports that need updating

Read each of the following files and identify whether they query fee data, and if so, by which mechanism:

| File | Location | What to look for |
|---|---|---|
| `includes/class-invoice-generator.php` | All methods | Any query referencing product IDs 5675, 4122, or matching on order item meta `_product_id` for fees |
| `includes/services/class-reports-fee-reconciliation.php` (or similar — locate the fee reconciliation report file) | Read fully | Same as above |
| `includes/services/class-reports-private-customer.php` (or wherever private sales report lives) | All methods | Same as above |
| `includes/services/class-reports-order-errors.php` (or wherever the errors report lives) | All methods | Same as above |
| `includes/class-allocation-engine.php` | All methods | Skip — allocation engine queries by category, not by fee product ID. Confirm this by reading. |
| `includes/services/class-reports-private-customer.php` | All methods | Look for fee-related queries |

**Confirm the actual file paths** — the audit referenced them generically. Find them with:

```bash
find includes/ -name "*.php" | xargs grep -l "5675\|4122" 2>/dev/null
find includes/ -name "*.php" | xargs grep -l "fee_reconciliation\|Fee_Reconciliation" 2>/dev/null
```

Document the full list in your response before proceeding.

### Step P3: Determine the canonical fee mechanism for v1.1

This is a **decision point** that requires the dev's input before code changes. Present the dev with these two options in your response:

**Option (a) — Make Quick Order use product IDs 5675/4122:**
- Pros: Reports keep working as-is. No report rewrites needed.
- Cons: Perpetuates the legacy product-ID-as-fee pattern. Quick Order would need a WC_Order_Item_Product with product_id=5675 and the contribution amount as a `total`. This is a workaround.
- Implementation: ~30 lines change in `class-quick-order-ajax.php`.

**Option (b) — Update reports to handle both mechanisms:**
- Pros: Cleaner long-term. Order Item Fees are the canonical WC pattern. The legacy product-ID-as-fee mechanism dies naturally as legacy orders age out of reports.
- Cons: ~3-5 reports need their fee queries updated. Each query needs to UNION the two mechanisms.
- Implementation: ~150-200 lines across 3-5 files.

**Recommended:** Option (b), per the synthesis. But the dev must confirm before code changes.

**DO NOT PROCEED with code changes until the dev confirms which option to implement.**

---

## Option (b) — Update reports to handle both mechanisms

**Only proceed past this point if the dev has confirmed Option (b).**

If the dev confirmed Option (a), STOP and write a different directive (or modify this one) to update Quick Order instead of the reports.

### Step F1: Define a canonical "fees in this order" helper

Create a new method that, given an order ID (or WC_Order object), returns the total fees by category. This becomes the single point of truth that reports call.

Decide on the right home:
- If there's an existing `MealsDB_Order_Fees` or similar helper class, add the method there.
- If there's a fee-related class in `includes/services/`, add it there.
- Otherwise, create `includes/services/class-order-fees.php` with class `MealsDB_Order_Fees`.

Method signature:

```php
/**
 * Get the fee totals for an order, handling both fee mechanisms.
 *
 * Mechanism A (legacy): fees stored as WC_Order_Item_Product with
 * specific product IDs (5675 = Client Contribution, 4122 = Delivery Fee).
 *
 * Mechanism B (Quick Order): fees stored as WC_Order_Item_Fee with
 * name matching "Client Contribution" or "Delivery Fee".
 *
 * @param int|WC_Order $order_or_id Order ID or order object.
 * @return array{contribution: float, delivery_fee: float, other_fees: float}
 *   All values in dollars, with two decimals. Returns zeros if order not found.
 */
public static function get_order_fees($order_or_id): array {
    $order = is_object($order_or_id) ? $order_or_id : wc_get_order($order_or_id);

    if (!$order || !($order instanceof WC_Order)) {
        return ['contribution' => 0.0, 'delivery_fee' => 0.0, 'other_fees' => 0.0];
    }

    $contribution = 0.0;
    $delivery_fee = 0.0;
    $other_fees   = 0.0;

    // Mechanism A: Line items with the legacy fee product IDs.
    // Hardcoded IDs match MealsDB session context and CLAUDE.md
    // "Operational constants" section.
    foreach ($order->get_items('line_item') as $item) {
        $product_id = (int) $item->get_product_id();
        $total = (float) $item->get_total();

        if ($product_id === 5675) {
            $contribution += $total;
        } elseif ($product_id === 4122) {
            $delivery_fee += $total;
        }
    }

    // Mechanism B: WC_Order_Item_Fee with matching name.
    // Names are case-insensitive matched to defend against
    // small typos in legacy data ("delivery fee" vs "Delivery Fee").
    foreach ($order->get_items('fee') as $fee_item) {
        $name = strtolower((string) $fee_item->get_name());
        $total = (float) $fee_item->get_total();

        if ($name === 'client contribution') {
            $contribution += $total;
        } elseif ($name === 'delivery fee') {
            $delivery_fee += $total;
        } else {
            $other_fees += $total;
        }
    }

    return [
        'contribution' => round($contribution, 2),
        'delivery_fee' => round($delivery_fee, 2),
        'other_fees'   => round($other_fees, 2),
    ];
}
```

**Important:** the product IDs 5675 and 4122 are hardcoded. This is consistent with the rest of the codebase (CLAUDE.md flags these as "should be in constants but aren't yet"). Do NOT extract to constants in this directive — that's a separate cleanup. Just add a `TODO` comment near the IDs:

```php
// TODO: Extract to MealsDB_Operational_Constants per CLAUDE.md.
$product_id = (int) $item->get_product_id();
```

### Step F2: Add a batch variant for report queries

Reports typically process many orders. A per-order helper would be N+1. Add a batch method:

```php
/**
 * Batch variant: get fee totals for multiple orders.
 *
 * Uses two single bulk queries (one per mechanism) instead of
 * loading WC_Order objects per ID. Avoids the N+1 pattern that
 * makes the per-order helper unusable for reports over 100s of
 * orders.
 *
 * @param int[] $order_ids
 * @return array<int, array{contribution: float, delivery_fee: float, other_fees: float}>
 *   Keyed by order_id. Order IDs not found in either table return zeros.
 */
public static function get_order_fees_batch(array $order_ids): array {
    global $wpdb;

    // Normalise input
    $order_ids = array_map('intval', $order_ids);
    $order_ids = array_filter($order_ids, function ($id) { return $id > 0; });
    $order_ids = array_unique($order_ids);

    if (empty($order_ids)) {
        return [];
    }

    // Initialise result with zeros for all requested IDs
    $result = [];
    foreach ($order_ids as $id) {
        $result[$id] = ['contribution' => 0.0, 'delivery_fee' => 0.0, 'other_fees' => 0.0];
    }

    $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));

    // Mechanism A: product-keyed line items
    $sql_a = $wpdb->prepare(
        "SELECT oi.order_id, oim_pid.meta_value AS product_id, oim_total.meta_value AS line_total
         FROM {$wpdb->prefix}woocommerce_order_items oi
         INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_pid
             ON oi.order_item_id = oim_pid.order_item_id
             AND oim_pid.meta_key = '_product_id'
         LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_total
             ON oi.order_item_id = oim_total.order_item_id
             AND oim_total.meta_key = '_line_total'
         WHERE oi.order_id IN ({$placeholders})
           AND oi.order_item_type = 'line_item'
           AND oim_pid.meta_value IN ('5675', '4122')",
        ...$order_ids
    );

    $rows_a = $wpdb->get_results($sql_a, ARRAY_A) ?: [];

    foreach ($rows_a as $row) {
        $order_id = (int) $row['order_id'];
        $product_id = (int) $row['product_id'];
        $total = (float) $row['line_total'];

        if ($product_id === 5675) {
            $result[$order_id]['contribution'] += $total;
        } elseif ($product_id === 4122) {
            $result[$order_id]['delivery_fee'] += $total;
        }
    }

    // Mechanism B: WC_Order_Item_Fee
    $sql_b = $wpdb->prepare(
        "SELECT oi.order_id, oi.order_item_name, oim.meta_value AS fee_total
         FROM {$wpdb->prefix}woocommerce_order_items oi
         LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
             ON oi.order_item_id = oim.order_item_id
             AND oim.meta_key = '_line_total'
         WHERE oi.order_id IN ({$placeholders})
           AND oi.order_item_type = 'fee'",
        ...$order_ids
    );

    $rows_b = $wpdb->get_results($sql_b, ARRAY_A) ?: [];

    foreach ($rows_b as $row) {
        $order_id = (int) $row['order_id'];
        $name = strtolower((string) $row['order_item_name']);
        $total = (float) $row['fee_total'];

        if ($name === 'client contribution') {
            $result[$order_id]['contribution'] += $total;
        } elseif ($name === 'delivery fee') {
            $result[$order_id]['delivery_fee'] += $total;
        } else {
            $result[$order_id]['other_fees'] += $total;
        }
    }

    // Round to two decimals
    foreach ($result as &$row) {
        $row['contribution'] = round($row['contribution'], 2);
        $row['delivery_fee'] = round($row['delivery_fee'], 2);
        $row['other_fees']   = round($row['other_fees'], 2);
    }
    unset($row);

    return $result;
}
```

### Step F3: Update each report to use the helper

For each fee-querying file identified in Step P2:

1. Find the existing query that loads fee data.
2. Replace it with a call to `MealsDB_Order_Fees::get_order_fees_batch(array_column($orders, 'order_id'))` (or appropriate equivalent).
3. Update the subsequent code that consumed the old query results to use the new structure (the helper returns `['contribution' => ..., 'delivery_fee' => ..., 'other_fees' => ...]` keyed by order_id).

**Do this one file at a time.** Test each file before moving to the next. The dev would rather review three correct small commits than one big one.

For each file modified, include:
- The exact lines changed (before / after).
- A short paragraph explaining what the report now does differently (or "no functional change, only the data source").
- Any edge cases discovered (e.g. "this report previously assumed contribution was always non-zero; the helper returns 0.0 for orders with no contribution, which the report now handles via a default").

### Step F4: Add HPOS compatibility note

The `woocommerce_order_items` and `woocommerce_order_itemmeta` tables ARE HPOS-compatible — WC keeps these tables for both classic and HPOS modes. So the helper above is correct on this HPOS site. Verify this assumption:

```bash
wp db query "SELECT COUNT(*) FROM 2xnIt_woocommerce_order_items LIMIT 1"
```

If this returns >0, the tables exist and are populated. Confirm this in your response.

If it returns 0 or table-doesn't-exist, the HPOS tables work differently than expected and this directive needs revisiting. **STOP and report.**

---

## Testing

### Step T1: Static checks

For each modified file: `php -l <filename>`. All must pass.

### Step T2: Unit-level verification

Write a quick verification script (do NOT add to the test suite if there isn't one; just run ad-hoc):

```bash
wp eval '
// Pick a known order with a Mechanism A fee (legacy)
$legacy_order_id = 12345; // dev fills in
$result_a = MealsDB_Order_Fees::get_order_fees($legacy_order_id);
print_r($result_a);

// Pick a known order with a Mechanism B fee (Quick Order)
$qo_order_id = 23456; // dev fills in
$result_b = MealsDB_Order_Fees::get_order_fees($qo_order_id);
print_r($result_b);

// Batch test
$batch = MealsDB_Order_Fees::get_order_fees_batch([$legacy_order_id, $qo_order_id]);
print_r($batch);
'
```

Include this in your response as **"Manual verification required: dev to run this with real order IDs"**.

### Step T3: Report sanity check

For each updated report, run it against a date range that contains both Mechanism A and Mechanism B orders. The totals should now include both. Compare to manual calculation if possible.

Include in your response: **"Manual report verification: dev to run Fee Reconciliation report for a recent month and confirm totals match expectations."**

### Step T4: Regression — old behavior on Mechanism A only

For a date range that contains only Mechanism A orders (pre-Quick-Order era), the report output should be **identical** to before this directive's changes. If it differs, there's a regression. Compare against a saved baseline if one exists.

---

## Out of scope for this directive

- Do NOT extract product IDs 5675 and 4122 to constants. That's a separate cleanup directive.
- Do NOT modify Quick Order's `create_wc_order` to use Mechanism A. The point of Option (b) is to KEEP Mechanism B and update reports.
- Do NOT modify the allocation engine. It queries by category, not by fee product ID, and is unaffected.
- Do NOT modify the legacy invoice generator's allowance calculation path. The fee math is correct in both mechanisms; only report visibility changes.

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight steps P1, P2 are complete and documented.
2. ✅ The dev has confirmed Option (b).
3. ✅ The `MealsDB_Order_Fees::get_order_fees` and `get_order_fees_batch` methods exist with the documented signatures.
4. ✅ Each fee-querying report identified in P2 has been updated to use the helper.
5. ✅ All modified files pass `php -l`.
6. ✅ The HPOS table compatibility note (Step F4) is verified.
7. ✅ Unit-level verification script (T2) is included in the response.
8. ✅ Manual report verification instructions (T3) are included in the response.

When complete, your final response should include:
- The full list of files modified.
- A diff (or summary) for each.
- The verification scripts and manual test instructions for the dev.
- Any edge cases or report-specific behavioral changes discovered.
