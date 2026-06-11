# Directive BC-5: Invoice contribution sum hardcodes product 5675, sums the wrong column, and double-deducts on spill

**Audit reference:** 2026-06 review, billing subsystem (`class-invoice-generator.php`).
**Severity:** MEDIUM — wrong contribution deducted on the government invoice (over- or under-billing the department). Pairs with BC-2 (the apply/release side).
**Scope:** ~20–40 lines, 1 file (`includes/services/class-invoice-generator.php`). **Risk:** LOW-MED (financial output).

---

## Background — three defects in how the invoice reads the contribution

### Defect 1 — hardcoded product ID 5675

`sum_contribution_for_orders()` (lines ~270–294) hardcodes the contribution product ID:

```php
INNER JOIN `{$meta_table}` pm ON ... AND pm.meta_key = '_product_id' AND pm.meta_value = '5675'
```

But the contribution product ID is operator-tunable via the `mealsdb_fee_product_ids` option, resolved everywhere else through `MealsDB_Order_Fees::fee_product_ids()` / `get_fee_product_ids()` (e.g. `MealsDB_WC_Order_Query::get_total_fee_paid_for_user`). If the operator ever changes the ID, fees apply under the new ID but the invoice finds **zero** contribution → the department is over-billed (department cost = basic − contribution, so a missing contribution inflates the bill).

### Defect 2 — sums `_line_total` while the reconciliation layer sums `_line_subtotal`

This query sums `_line_total`; `MealsDB_WC_Order_Query` (the reconciliation/order-query layer) sums `_line_subtotal`. For a fee with no discount they're equal, but the two layers disagreeing means the invoice and the reconciliation report can diverge by any per-line adjustment. Pick one basis and use it everywhere (the order-query layer's `_line_subtotal` is the established choice).

### Defect 3 — contribution double-deducted when the carrying order spills across a month boundary

`get_phase2_billing_data()` builds the per-month order list (lines ~178–187):

```sql
SELECT DISTINCT client_id, wc_order_id FROM delivery_allocations WHERE billing_month = %s
```

An order whose meals spilled across a month boundary has `delivery_allocations` rows in **both** months, so its `wc_order_id` appears in both months' lists, and its 5675 contribution line is summed into **both** months' `contribution_cents` → deducted twice. Edge case (the first-of-month contribution order rarely spills), but it's penny-real on a government CSV and compounds with BC-2's flag bugs.

---

## Pre-flight verification

**P1 — Confirm the hardcoded ID and the column.**
```bash
sed -n '270,294p' includes/services/class-invoice-generator.php
```

**P2 — Confirm the canonical resolver and the column the order-query layer uses.**
```bash
grep -n "fee_product_ids\|get_fee_product_ids\|client_contribution" includes/services/class-order-fees.php
grep -n "_line_subtotal\|_line_total\|contribution" includes/services/class-wc-order-query.php
```

**P3 — Confirm the per-month order list is built from `delivery_allocations` (spill duplication).**
```bash
sed -n '175,190p' includes/services/class-invoice-generator.php
grep -n "contribution_order_id" includes/services/class-invoice-generator.php
```

---

## The fix

### Step 1 — Resolve the contribution product ID dynamically

```php
private static function sum_contribution_for_orders(array $wc_order_ids): int {
    if (empty($wc_order_ids)) { return 0; }
    global $wpdb;

    // BC-5: resolve the contribution product ID the same way the fee engine and
    // the reconciliation layer do — never hardcode 5675. A changed option must
    // not silently zero out the invoice's contribution.
    $fee_ids = MealsDB_Order_Fees::fee_product_ids();
    $contribution_pid = (int) ($fee_ids['client_contribution'] ?? 0);
    if ($contribution_pid <= 0) { return 0; }

    $order_list  = implode(',', array_map('intval', $wc_order_ids));
    $items_table = $wpdb->prefix . 'woocommerce_order_items';
    $meta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';

    // BC-5: sum _line_subtotal to match MealsDB_WC_Order_Query (single basis
    // across invoice + reconciliation).
    $sql = $wpdb->prepare("
        SELECT COALESCE(SUM(CAST(ls.meta_value AS DECIMAL(12,4))), 0)
        FROM `{$items_table}` i
        INNER JOIN `{$meta_table}` pm ON pm.order_item_id = i.order_item_id
                                      AND pm.meta_key = '_product_id'
                                      AND pm.meta_value = %s
        INNER JOIN `{$meta_table}` ls ON ls.order_item_id = i.order_item_id
                                      AND ls.meta_key = '_line_subtotal'
        WHERE i.order_id IN ({$order_list})
          AND i.order_item_type = 'line_item'
    ", (string) $contribution_pid);

    $sum_decimal = (string) $wpdb->get_var($sql);
    return MealsDB_Money::to_cents($sum_decimal);
}
```

> Note `$order_list` is built from `intval`-mapped ints, so interpolating it is injection-safe; the product ID is now `%s`-bound. Confirm `fee_product_ids()` is static (it is, per usage elsewhere); adjust the call if not.

### Step 2 — Attribute the contribution to ONE month (kill the spill double-count)

The robust fix: attribute each order's contribution to the month its `contribution_order_id` flag was recorded against (the BC-2 single source of truth), or — if you don't want a cross-table read here — to the order's **primary** allocation month (`MIN(billing_month)` for that `wc_order_id`).

Minimal approach using the existing per-month order list: before summing, drop any `wc_order_id` from this month's list whose *primary* allocation month is a different month:

```php
// BC-5: an order that spilled across a month boundary appears in both months'
// allocation rows. Attribute its contribution to the order's PRIMARY month
// (the earliest billing_month it touches) so it is deducted exactly once.
$primary_month = (string) $wpdb->get_var($wpdb->prepare(
    "SELECT MIN(billing_month) FROM `{$alloc_table}` WHERE wc_order_id = %d",
    $wc_order_id
));
if ($primary_month !== $billing_month) {
    // This order's contribution belongs to $primary_month, not the month
    // currently being summed — skip it here.
    continue;   // or filter it out of $orders_by_cid before summing
}
```

Wire this into wherever `sum_contribution_for_orders` is fed its per-client order list inside `get_phase2_billing_data`, so each order's contribution counts only in its primary month.

> **Preferred (if BC-2 is already merged):** key off `contribution_order_id` on the summary row — the order that the flag says carries the contribution is, by definition, the one whose contribution counts, in the month the flag is set. That avoids the `MIN(billing_month)` heuristic entirely. Use this if BC-2 has landed.

---

## Testing

`tests/test-invoice-contribution-sum.php` (mock `$wpdb`):
1. **Dynamic ID:** set `mealsdb_fee_product_ids['client_contribution'] = 9999`; an order with a 9999 line is summed; a 5675 line is **not**.
2. **Column basis:** assert `_line_subtotal` is the summed column.
3. **Spill single-count:** an order with allocation rows in both May and June → its contribution counts in exactly one month, not both.
4. **Zero-config safety:** option unset / ID 0 → returns 0 (no fatal, no stray sum).

**Manual:** regenerate a known-good month's invoice on staging; confirm the contribution line matches the per-client `client_contribution` × (number of contributing clients), with no doubling on month-boundary orders.

---

## Out of scope

- Do not change the contribution **apply/release** logic (BC-2).
- Do not change HST/rate derivation (LB-7).
- Do not switch the fee mechanism from product-shape to `WC_Order_Item_Fee` (LB-2 deliberately kept product-shape).

## Acceptance criteria

- [ ] Contribution product ID resolved via `MealsDB_Order_Fees::fee_product_ids()`, not hardcoded.
- [ ] Sum uses `_line_subtotal` (matching the order-query layer).
- [ ] Each order's contribution is attributed to exactly one month (primary month or `contribution_order_id`).
- [ ] Tests cover dynamic ID, column basis, spill single-count, zero-config safety.
