# Directive BC-6: Partial refunds never reduce allocations

**Audit reference:** 2026-06 review, allocation/billing subsystem (`class-allocation-hooks.php`, `class-allocation-rebuilder.php`).
**Severity:** MEDIUM — partially refunded meals stay billed to the government.
**Scope:** ~20–40 lines across `includes/class-allocation-hooks.php` and `includes/services/class-allocation-rebuilder.php`. **Risk:** MED (touches order-status hooks + the per-line quantity used in the fill).

---

## Background — only full-status transitions are wired

`MealsDB_Allocation_Hooks::register_hooks()` (lines ~20–30) wires the **full** order-status transitions:

```php
add_action('woocommerce_order_status_cancelled', ...);
add_action('woocommerce_order_status_refunded',  ...);   // FULL refund only
add_action('woocommerce_order_status_failed',    ...);
add_action('woocommerce_trash_order', ...);   // LB-5 HPOS
add_action('woocommerce_delete_order', ...);  // LB-5 HPOS
```

A **partial** refund (a refund object is created against some line items; the order stays `processing`/`completed`) fires `woocommerce_order_refunded` / `woocommerce_refund_created` — **neither is handled**. And the rebuilder counts raw ordered quantity (`load_deliveries_for_months`, lines ~501–507):

```php
$qty = (int) $it['quantity'];
if ($pd['product_type'] === 'meal')      { $mains += $qty; }
elseif ($pd['product_type'] === 'side')  { ... += $qty; }
```

`$it['quantity']` is the **ordered** quantity from the line item — there is no subtraction of refunded quantity. So if the operator refunds 3 of 10 meals on an order, all 10 stay allocated and billed.

---

## Pre-flight verification

**P1 — Confirm only full-status refund is hooked.**
```bash
grep -n "refund\|order_status_refunded\|order_refunded\|refund_created" includes/class-allocation-hooks.php
```
Expect `woocommerce_order_status_refunded` only; no `woocommerce_order_refunded`.

**P2 — Confirm the rebuilder counts raw ordered qty.**
```bash
sed -n '494,512p' includes/services/class-allocation-rebuilder.php
```

**P3 — Confirm how items are fetched** (the layer where refunded qty must be subtracted).
```bash
grep -n "get_order_items\|function get_order_items" includes/services/class-wc-order-query.php
```
Decide whether to subtract refunded quantity inside `get_order_items` (cleaner, benefits all callers) or in the rebuilder loop. **Recommend `get_order_items`** so every consumer sees net quantities.

**P4 — WC refunded-quantity API.** On HPOS, refunded quantity per line item is available via `$order->get_qty_refunded_for_item($item_id)` (returns a negative number) or by iterating `$order->get_refunds()`. Confirm the method on the WC version in use.

---

## The fix

### Step 1 — Hook partial refunds to mark the order dirty

In `register_hooks()`:

```php
// BC-6: a partial refund leaves the order in an active status, so the
// full-status refund hook never fires. Mark the order dirty on refund creation
// so the rebuilder re-derives net (post-refund) quantities.
add_action('woocommerce_order_refunded', [self::class, 'on_order_partially_refunded'], 20, 2);
```

Add the handler (mirroring the existing swallow-and-log pattern):

```php
public static function on_order_partially_refunded(int $order_id, int $refund_id): void {
    self::process_dirty_hook('woocommerce_order_refunded', $order_id);
}
```

Where `process_dirty_hook` resolves the order's client-month(s) and calls `mark_dirty` (reuse the existing helper that the trash/cancel paths use — `deallocate_order` already marks dirty, so routing a refund through the same "mark dirty, let the rebuilder recompute" path is sufficient; the rebuilder then re-reads net quantities per Step 2). Wrap in `\Throwable` + `MealsDB_Hook_Logger` like the siblings.

> Note: `woocommerce_order_refunded` also fires for a **full** refund (alongside `order_status_refunded`). Marking dirty twice is harmless (idempotent flag). Do not try to distinguish — just mark dirty.

### Step 2 — Subtract refunded quantity when counting meals

In `MealsDB_WC_Order_Query::get_order_items()` (preferred), return the **net** quantity per line item:

```php
// BC-6: report NET quantity (ordered minus refunded) so allocation counts
// reflect partial refunds. get_qty_refunded_for_item returns <= 0.
$refunded = (int) $order->get_qty_refunded_for_item($item_id);   // negative or 0
$net_qty  = max(0, (int) $item->get_quantity() + $refunded);
```

and emit `$net_qty` as `'quantity'`. If `get_order_items` is a raw-SQL path (not order-object based), either switch it to hydrate the order for the refund lookup, or do the subtraction in the rebuilder loop using `wc_get_order($wc_order_id)->get_qty_refunded_for_item(...)` — but prefer fixing it once in the query layer.

> **Performance:** at 16k orders/year a year-wide rebuild that hydrates each order for refund lookup could be slow. If `get_order_items` is currently pure-SQL for speed, add the refunded-qty subtraction via a single batched query against `wc_order_items`/`wc_order_itemmeta` for refund-type rows (`order_item_type = 'line_item'` on refund orders carry negative `_qty`), rather than per-order hydration. Confirm the HPOS refund-line storage during P4.

---

## Testing

`tests/test-partial-refund-allocation.php` (mock `$wpdb` / stub order):
1. **Partial refund reduces count:** order with 10 meals, 3 refunded → allocation counts 7.
2. **Hook marks dirty:** firing `woocommerce_order_refunded` marks the order's client-month dirty.
3. **Full refund unchanged:** a full refund still zeros the allocation (no regression with the existing `order_status_refunded` path).
4. **No negative:** refunded qty ≥ ordered qty → net clamps to 0, never negative.

**Manual:** on staging, partially refund a meal order, run the rebuild, confirm the invoice/PO counts drop by the refunded quantity and the driver slip reflects it.

---

## Out of scope

- Do not change refund **money** handling in WC — only the allocation/meal-count consequence.
- Do not attempt to refund-adjust finalized months (BC-3/LB-3 keep those immutable; a refund into a finalized month surfaces as the BC-3 degraded event).

## Acceptance criteria

- [ ] `woocommerce_order_refunded` hooked → marks the order's client-month(s) dirty.
- [ ] Meal counting uses net (ordered − refunded) quantity, fixed once in the query layer where practical.
- [ ] Net quantity clamps at 0.
- [ ] Tests cover partial reduce, hook-marks-dirty, full-refund no-regression, clamp.
