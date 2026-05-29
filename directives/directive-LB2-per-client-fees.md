# Directive LB-2: Bill per-client contribution/delivery-fee amounts, not the flat catalog price

**Audit reference:** recon-04 (BUG-HIGH, Q4.1), corroborated recon-06 (form stores per-client values), recon-09 (slips use per-client), recon-10 (QO preview uses per-client). recon-14 §2 LB-2.
**Severity:** LAUNCH BLOCKER (cutover) — wrong dollar amounts on real invoices. **Scope:** ~15–30 lines, 1 file (`includes/services/class-order-fees.php`). **Risk:** LOW-MEDIUM — well-isolated; the fix is compatible with the existing reconciliation path (verified below).

---

## Background (why this is broken)

Each client has their own negotiated fee amounts stored as DECIMAL columns on `meals_clients`: `client_contribution` and `delivery_fee` (validated $0–1000 / $0–100 in the form, recon-06). These per-client amounts are treated as authoritative **everywhere except actual billing**: the form stores them, the driver slips collect them (recon-09), and the Quick Order preview displays them (recon-10).

But `MealsDB_Order_Fees::apply_to_order()` (the single fee applier on `woocommerce_new_order`) **ignores the amounts**. It reads them (lines 98–99) only as `> 0` on/off switches, then calls `add_fee_product()` → `$order->add_product($product, 1)` (line 245), which adds the fee product at its **flat WooCommerce catalog price**. So every client whose negotiated fee differs from the catalog default is billed the wrong amount. The per-client dollar values are loaded and discarded.

The contribution-reconciliation report (recon-07) will surface this during the shadow trial as systematic per-client discrepancies — that report is, in effect, an automatic detector for this bug.

### Why the fix must keep using the fee PRODUCT (not switch to WC_Order_Item_Fee)

The file header (lines 8–35) documents a deliberate decision: fees are added as the fee PRODUCTS `5675` (contribution) / `4122` (delivery fee), NOT as `WC_Order_Item_Fee` rows, specifically so they share the same on-order shape as legacy/normal orders and so the reconciliation/order-query layer can find them uniformly. Quick Order's old `WC_Order_Item_Fee` approach was removed for this reason. **Do not revert to `WC_Order_Item_Fee`** — keep the fee product, but override its line price to the per-client amount.

### Verified compatible with reconciliation

The order-query reconciliation sums the stored **`_line_subtotal`** meta of the fee line item (`MealsDB_WC_Order_Query::get_total_paid_for_product`, ~line 332), NOT the catalog price. So overriding the line subtotal/total to the per-client amount is read back correctly by reconciliation — the fix is fully compatible with the existing report path. (This is the key check that makes the fix safe.)

---

## Pre-flight verification

**P1 — Confirm the per-client columns and the discard.**
```bash
sed -n '97,124p' includes/services/class-order-fees.php
```
Expect `$delivery_fee`/`$client_contribution` read from the client row, used only in `> 0` conditions, then `add_fee_product($order, $fee_ids[...])` with no amount passed.

**P2 — Confirm `add_fee_product` uses the catalog price.**
```bash
sed -n '238,247p' includes/services/class-order-fees.php
```
Expect `$order->add_product($product, 1);` with no price override.

**P3 — Confirm reconciliation reads the stored line subtotal (not catalog price).**
```bash
sed -n '/function get_total_paid_for_product/,/_line_subtotal/p' includes/services/class-wc-order-query.php | head -20
```
Expect the SQL to `SUM(... _line_subtotal ...)`. This confirms a price-overridden line reconciles correctly. If it instead recomputes from catalog price, STOP and report — the fix strategy would need revising.

**P4 — Confirm the per-client values' units.** The form stores dollars (e.g. `40.00`), and `MealsDB_Money` works in integer cents. Check whether `client_contribution`/`delivery_fee` are plain decimals (dollars). Expect dollars. The override below must set the WC line amount in DOLLARS (WC line totals are float dollars), so no cents conversion is needed for the WC API call — but use `MealsDB_Money` to round cleanly.

---

## The fix

Pass the per-client amount into `add_fee_product()` and set the line item's subtotal/total to it.

### Step 1 — Thread the amount through the call sites

In `apply_to_order()`, change the two `add_fee_product` calls to pass the resolved per-client amount.

**Delivery fee (~line 108):**
```php
                if (self::add_fee_product($order, $fee_ids['delivery_fee'], $delivery_fee)) {
                    $dirty = true;
                }
```

**Contribution (~line 119):**
```php
                    if (self::add_fee_product($order, $fee_ids['client_contribution'], $client_contribution)) {
                        $dirty = true;
                        self::mark_contribution_applied($client_id, $billing_month, $wc_order_id);
                    }
```

### Step 2 — Override the line price in `add_fee_product`

Replace `add_fee_product` (~lines 238–247) with a version that sets the line subtotal/total to the per-client amount:

```php
    /**
     * Add a fee product to the order as a quantity-1 line item, priced at the
     * per-client amount (NOT the product's catalog price).
     *
     * LB-2: previously this added the product at its catalog price, ignoring
     * the client's negotiated client_contribution / delivery_fee. We keep using
     * the fee PRODUCT (5675/4122) so the on-order shape matches legacy/normal
     * orders and the reconciliation/order-query layer can find it (see file
     * header) — but we override the line subtotal/total to the per-client value.
     * The reconciliation report sums _line_subtotal, so the override is read
     * back correctly.
     *
     * Does NOT touch tax — WooCommerce applies the product's configured tax
     * during calculate_totals(). Returns true if added.
     *
     * @param float $amount Per-client fee amount in DOLLARS.
     */
    private static function add_fee_product(WC_Order $order, int $product_id, float $amount): bool {
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product) {
            error_log('[MealsDB Order Fees] Fee product ' . $product_id
                . ' not found; cannot apply fee to order ' . $order->get_id());
            return false;
        }

        // Round to cents via MealsDB_Money to avoid float drift, then express
        // as a dollar string for the WC line totals.
        $amount = (float) MealsDB_Money::round_dollars($amount); // see note below

        $item_id = $order->add_product($product, 1, [
            'subtotal' => $amount,
            'total'    => $amount,
        ]);

        if (!$item_id) {
            return false;
        }
        return true;
    }
```

> **Note on the rounding helper:** use whatever `MealsDB_Money` exposes for "round a dollar float to 2dp." If there is no dollars-in/dollars-out helper, convert via cents: `$cents = MealsDB_Money::to_cents($amount); $amount = MealsDB_Money::to_dollars($cents);` — confirm the exact method names in `class-money.php` during P4 and use them. The goal is simply to avoid passing a float like `40.00000001` into the WC line total. Do NOT do raw float math.

> **Note on `add_product` signature:** in modern WooCommerce, `WC_Order::add_product($product, $qty, $args)` accepts `subtotal`/`total` in `$args` and returns the new item ID (or 0/false on failure). Verify this against the WC version in use; if the installed WC returns void, fall back to constructing a `WC_Order_Item_Product`, calling `set_subtotal()`/`set_total()`, and `$order->add_item()`. Either way the line subtotal/total must end up as the per-client amount.

### Step 3 — Leave `calculate_totals()` as-is

The existing `if ($dirty) { $order->calculate_totals(); $order->save(); }` (lines 126–131) stays. `calculate_totals()` will apply the fee products' configured tax to the overridden amounts. (Fee products 5675/4122 are configured non-taxable per the file header, so tax stays zero — correct.)

---

## Testing

### Automated — and FIX the test that masks this bug
`tests/test-order-fees.php` currently makes this bug invisible: its mock `WC_Order::add_product` tracks only the COUNT of times a product is added, never the amount (recon-12.5). The fixtures even set per-client values (`delivery_fee => 8.50`, `client_contribution => 40.00`) that the assertions ignore.

Update the mock and add assertions:
1. Extend the mock `WC_Order`/`add_product` to record the `subtotal`/`total` passed in `$args` per line item.
2. Add assertions that after `apply_to_order`, the delivery-fee line's amount equals the client's `delivery_fee` ($8.50) and the contribution line's amount equals `client_contribution` ($40.00) — NOT the catalog price.
3. Keep the existing "added exactly once" / idempotency assertions.
4. Add a case where the per-client amount differs from the catalog price, and assert the ORDER reflects the per-client amount.

### Manual (dev, on staging)
1. Pick an SDNB client whose `client_contribution`/`delivery_fee` differ from the catalog prices of 5675/4122.
2. Create an order for them (outside shadow mode on staging).
3. Inspect the order's fee line items: confirm the line subtotal/total equals the client's per-client amounts, not the catalog price.
4. Run the contribution-reconciliation report: confirm it now reconciles (no discrepancy) for that client.

---

## Out of scope

- Do NOT switch to `WC_Order_Item_Fee` (breaks the reconciliation shape — see Background).
- Do NOT change the fee PRODUCT IDs or the `mealsdb_fee_product_ids` option mechanism.
- Do NOT change the contribution once-per-month logic (`contribution_applied_this_month` / `mark_contribution_applied`) — that's correct and stays.
- Do NOT touch tax config on the fee products (they're intentionally non-taxable).
- Do NOT change how the slip path computes collection (recon-09) — it already uses per-client amounts correctly; this directive aligns BILLING with what the slips already do.
- The Quick Order preview (recon-10) already shows per-client amounts; once this lands, preview and actual billing will agree (resolves the recon-10 preview-vs-actual FLAG automatically — no separate change).

---

## Acceptance criteria

- [ ] `add_fee_product` sets the line subtotal/total to the per-client amount (rounded via `MealsDB_Money`), keeping the fee PRODUCT shape.
- [ ] Both call sites pass the resolved per-client amount.
- [ ] `test-order-fees.php` mock records line amounts, and new assertions verify per-client amounts reach the order (catalog price no longer assumed).
- [ ] Manual staging test: a client with non-default fees is billed their per-client amounts, and reconciliation shows no discrepancy.
- [ ] No regression in the once-per-month contribution gating or idempotency.
- [ ] CLAUDE.md Billing section's LB-2 note updated once shipped.

---

## Relationship to other directives

- Independent of LB-1/LB-3 (different subsystem — fees vs allocation), can land in parallel.
- Resolves the recon-10 Quick-Order preview-vs-actual mismatch as a side effect (preview already uses per-client; now billing does too).
- The contribution-reconciliation report (recon-07) is the verification instrument: before the fix it shows per-client discrepancies; after, it should reconcile.
