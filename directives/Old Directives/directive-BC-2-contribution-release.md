# Directive BC-2: `contribution_applied` is never released (and is keyed to the wrong month)

**Audit reference:** 2026-06 review, billing subsystem. Verified directly in source.
**Severity:** LAUNCH BLOCKER (cutover) — a cancelled/refunded first-of-month order silently suppresses the client's monthly contribution for the rest of the month.
**Scope:** ~20–30 lines across `includes/services/class-allocation-engine.php` and `includes/services/class-order-fees.php`. **Risk:** LOW-MED.

---

## Background — the flag is set but never cleared

The monthly client contribution must ride on exactly one order per billing month. The guard is the `contribution_applied` / `contribution_order_id` columns on the `meals_client_allocations` summary row:

- `MealsDB_Order_Fees::mark_contribution_applied()` (line ~393) sets `contribution_applied = 1, contribution_order_id = <order>` via `INSERT … ON DUPLICATE KEY UPDATE`.
- `MealsDB_Order_Fees::contribution_applied_this_month()` (line ~375) reads the flag; if `1`, no further order gets the contribution.

Both the file header (lines 52–55) and `mark_contribution_applied`'s docblock (lines 389–391) state:

> "deallocate_order() … resets contribution_applied / contribution_order_id when that order is cancelled/refunded, releasing the month for the next qualifying order."

**This reset does not exist.** `grep -n contribution_applied includes/` confirms the only writes are the `=1` upsert above. `MealsDB_Allocation_Engine::deallocate_order()` (line ~510) only calls `mark_dirty()`:

```php
public function deallocate_order(int $wc_order_id): void {
    $affected = $this->wpdb->get_results(...DISTINCT client_id, billing_month WHERE wc_order_id = %d...);
    if (empty($affected)) { return; }
    $rebuilder = new MealsDB_Allocation_Rebuilder();
    foreach ($affected as $row) {
        $rebuilder->mark_dirty((int) $row['client_id'], (string) $row['billing_month']);
    }
}
```

`recalculate_month_totals()` preserves the column (doesn't touch it). So: the contribution lands on the month's first order → that order is cancelled/refunded → flag stays `1` → no subsequent order re-applies the contribution → the client is **never billed/collected the contribution that month**, silently.

## Secondary bug — the flag is keyed to the wrong month

`apply_to_order()` (line ~115) keys the flag on `gmdate('Y-m')` — the **hook-fire wall-clock month**, not the order's billing month:

```php
$billing_month = gmdate('Y-m');
```

But the allocation engine derives `billing_month` from the order's `date_created_gmt` (engine `resolve_billing_month_for_order`). A back-dated order entered on the 2nd of the month for *last* month applies the contribution flag to the **current** month while the fee line item rides an order that allocations bill to the **prior** month. The flag and the invoice then disagree — the prior month can double-collect (its flag is still clear) while the current month is wrongly blocked.

---

## Pre-flight verification

**P1 — Confirm no reset anywhere.**
```bash
grep -rn "contribution_applied\s*=\s*0\|contribution_order_id\s*=\s*NULL\|contribution_order_id\s*=\s*null" includes/
```
Expect **no output**. If a reset exists, STOP — re-scope.

**P2 — Confirm the column is keyed `(client_id, billing_month)`.**
```bash
grep -n "contribution_applied\|contribution_order_id\|billing_month" includes/class-schema.php
```

**P3 — Confirm the order's billing-month resolver name** (the function `apply_to_order` should use instead of `gmdate('Y-m')`).
```bash
grep -n "resolve_billing_month_for_order\|billing_month_for_order\|function.*billing_month" includes/services/class-allocation-engine.php
```

---

## The fix

### Step 1 — Release the flag in `deallocate_order`

In `MealsDB_Allocation_Engine::deallocate_order()`, before marking dirty, clear the contribution flag for any month where the leaving order was the contribution carrier:

```php
public function deallocate_order(int $wc_order_id): void {
    $delivery_alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);
    $summary_table        = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);

    // BC-2: release the monthly contribution if THIS order was carrying it, so
    // the next qualifying order in the month re-applies it. Do this BEFORE the
    // rebuild marks months dirty. Guarded on contribution_order_id so we never
    // clear a flag set by a different (still-live) order.
    $this->wpdb->query($this->wpdb->prepare(
        "UPDATE `{$summary_table}`
         SET contribution_applied = 0, contribution_order_id = NULL
         WHERE contribution_order_id = %d",
        $wc_order_id
    ));

    $affected = $this->wpdb->get_results($this->wpdb->prepare(
        "SELECT DISTINCT client_id, billing_month FROM `{$delivery_alloc_table}` WHERE wc_order_id = %d",
        $wc_order_id
    ), ARRAY_A);
    if (empty($affected)) {
        // Even with no delivery rows, the contribution release above may have
        // happened — that's fine; mark_dirty below only runs for allocated rows.
        return;
    }
    $rebuilder = new MealsDB_Allocation_Rebuilder();
    foreach ($affected as $row) {
        $rebuilder->mark_dirty((int) $row['client_id'], (string) $row['billing_month']);
    }
}
```

> A contribution-only order (fee applied, no meals) may have **no** `meals_delivery_allocations` rows, so the early-return path must not skip the release — hence the release runs first, unconditionally, keyed on `contribution_order_id`.

### Step 2 — Key the flag to the order's billing month

In `MealsDB_Order_Fees::apply_to_order()` (line ~115), replace the wall-clock month with the order's resolved billing month:

```php
// BC-2: key the contribution to the order's BILLING month (derived from the
// order's creation date, same basis as the allocation engine), NOT the
// wall-clock month — otherwise a back-dated order flags the wrong month.
$billing_month = MealsDB_Allocation_Engine::resolve_billing_month_for_order($order);
if ($billing_month === '') {
    $billing_month = gmdate('Y-m'); // defensive fallback only
}
```

(Use the actual resolver name/signature from P3; if it's an instance method, instantiate or expose a static helper. Keep the fallback so a missing date can't fatal the checkout hook.)

### Step 3 — Fix the now-accurate docblocks

Update the header comment (lines 52–55) and `mark_contribution_applied`'s docblock (lines 389–391) to describe the real mechanism: "deallocate_order() (engine) clears these when the contribution-carrying order leaves an active status, keyed on contribution_order_id."

---

## Testing

`tests/test-contribution-lifecycle.php` (mock `$wpdb`):
1. **Release:** apply contribution on order #1 (month M) → flag set, `contribution_order_id = 1`. Deallocate #1 → flag cleared. Apply on order #2 (month M) → flag set again, `contribution_order_id = 2`. Assert the client is billed exactly once.
2. **No cross-order clobber:** flag set by #1; deallocate a *different* order #9 (not the carrier) → flag unchanged.
3. **Contribution-only order:** order with fee but no meal rows; deallocate it → flag released (the empty-delivery early-return must not skip the release).
4. **Billing-month keying:** back-dated order (created in M, date_created in M-1) → contribution flag lands on M-1, matching where allocations bill it.

**Manual:** on staging, apply a contribution, cancel that order in WC, place a new order same month, confirm the new order carries the contribution and the month is billed once.

---

## Out of scope

- Do not change the contribution **amount** logic (per-client `client_contribution` column — LB-2 owns that).
- Do not change which fee mechanism carries the contribution (product-shape line item — see BC-5 for the read side).

## Acceptance criteria

- [ ] `deallocate_order` clears `contribution_applied`/`contribution_order_id` keyed on `contribution_order_id`, before marking dirty, on the unconditional path.
- [ ] `apply_to_order` keys the flag on the order's billing month, not `gmdate('Y-m')`.
- [ ] Docblocks corrected to match reality.
- [ ] Tests cover release, no-clobber, contribution-only order, and billing-month keying.
