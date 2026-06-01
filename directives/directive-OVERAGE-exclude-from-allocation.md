# Directive OVERAGE-EXCL (SURGICAL, v446) — Exclude legacy overage products from meal counting

## HOW TO EXECUTE — READ FIRST
- ONE edit in `includes/services/class-allocation-rebuilder.php`. `read` the file, find the EXACT
  verbatim FIND, apply. Do NOT regenerate the method. If FIND doesn't match, STOP and report.
- BILLING-CORRECTNESS change: this stops the new system counting the old system's overage line items.

**Problem:** the OLD system injects legacy "overage" products (real WooCommerce SKUs) into orders as
part of its own accounting. During the parallel/shadow run, those overage SKUs sit in the same orders
the NEW system reads. The new system's allocation rebuilder counts any line item whose
`meals_products.product_type` is 'meal' or 'side' — and the overage products were synced as 'meal'
(the sync's default), so they are being counted as mains. They must NOT be counted by the new system.

**Constraint (operator):** we CANNOT reclassify the overage products to 'fee'/'other' in
meals_products, because that breaks the OLD system's functionality (it still relies on them while
running in parallel). So we exclude them by PRODUCT ID in the new system's counting only — leaving the
products' WooCommerce data and meals_products rows untouched.

**Canonical source of the IDs (do NOT hardcode):** the overage product IDs already live in
`MealsDB_Operational_Constants` and are exposed as `default_overage_product_ids()`:
```
public static function default_overage_product_ids(): array {
    return [
        'mains'         => self::PRODUCT_ID_OVERAGE_MAIN,        // 5056
        'taxable_sides' => self::PRODUCT_ID_OVERAGE_SIDE_TAX,    // 5180
        'nontax_sides'  => self::PRODUCT_ID_OVERAGE_SIDE_NONTAX, // 5059
    ];
}
```
Read the IDs from there. (The slip generator already excludes these from slips via a parallel
`get_overage_product_ids()` — same intent; we mirror it in the allocation path. Use
`default_overage_product_ids()` as the source — its labels are correct; do NOT copy the slip
generator's getter, whose taxable/nontax labels are swapped.)

---

## EDIT — skip legacy overage products in the rebuilder's line-item count
**File:** `includes/services/class-allocation-rebuilder.php`
**FIND (verbatim — the classification loop inside load_deliveries_for_months):**
```
            $mains = 0; $tax_sides = 0; $nontax_sides = 0;
            foreach ($items as $it) {
                $pd = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT product_type, taxable FROM `{$products_table}` WHERE wc_product_id = %d",
                    (int) $it['wc_product_id']
                ), ARRAY_A);
                if (!$pd) continue;
                $qty = (int) $it['quantity'];
                if ($pd['product_type'] === 'meal') {
                    $mains += $qty;
                } elseif ($pd['product_type'] === 'side') {
                    if ((int) $pd['taxable'] === 1) $tax_sides += $qty;
                    else                            $nontax_sides += $qty;
                }
            }
```
**REPLACE WITH:**
```
            $mains = 0; $tax_sides = 0; $nontax_sides = 0;
            // Legacy overage products: the OLD system injects these SKUs into
            // orders for its own accounting. The new system must NOT count them
            // (they would inflate mains/sides during the parallel run). We
            // exclude by product ID — the products' meals_products rows are left
            // intact (the old system still needs them), so this is the ONLY
            // place they are filtered out of the new allocation count. IDs come
            // from the canonical constants, not hardcoded here.
            $overage_ids = array_map('intval', array_values(
                MealsDB_Operational_Constants::default_overage_product_ids()
            ));
            foreach ($items as $it) {
                $wc_pid = (int) $it['wc_product_id'];
                if (in_array($wc_pid, $overage_ids, true)) {
                    continue; // legacy overage SKU — never counted by the new system
                }
                $pd = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT product_type, taxable FROM `{$products_table}` WHERE wc_product_id = %d",
                    $wc_pid
                ), ARRAY_A);
                if (!$pd) continue;
                $qty = (int) $it['quantity'];
                if ($pd['product_type'] === 'meal') {
                    $mains += $qty;
                } elseif ($pd['product_type'] === 'side') {
                    if ((int) $pd['taxable'] === 1) $tax_sides += $qty;
                    else                            $nontax_sides += $qty;
                }
            }
```
**NOTE:** confirm `MealsDB_Operational_Constants` is referenced with the correct namespace/use as
elsewhere in this file (it's used across the codebase unprefixed — match the existing style). If the
class isn't already loaded in this path, it is a plugin-global class (no use statement needed in this
codebase's convention) — verify by how other files call it.
**OPTIONAL micro-optimization (only if trivial):** `$overage_ids` is constant per call; it may be
hoisted above the `foreach ($orders as $o)` loop rather than rebuilt per order. Functionally
identical — do NOT complicate the edit for this; inline is fine.

---

## AFTER THE CODE CHANGE — REBUILD AFFECTED MONTHS
The fix changes how counts are computed, but existing allocation summaries were built WITH the overage
products counted. They must be recomputed:
1. Deploy the code.
2. Re-mark affected client-months dirty (or rebuild directly) and run the rebuild so summaries reflect
   overage-excluded counts. Any NON-finalized month will recompute; FINALIZED months are protected
   (un-finalize first if a finalized month needs correcting).
3. Regenerate any invoices that were produced before the fix — their meal counts were inflated.

## VERIFICATION
```bash
cd <plugin-root>
grep -n "default_overage_product_ids\|legacy overage SKU" includes/services/class-allocation-rebuilder.php
php tests/test-*.php   # green
```
**Manual (staging):** pick a client+month whose orders are known to contain an overage SKU. Rebuild
that client-month, then check used_mains BEFORE vs AFTER the fix — it should DROP by exactly the
overage quantity. Confirm a client with NO overage SKU is unchanged. Confirm the overage product's
meals_products row is UNTOUCHED (still whatever it was — we did not reclassify it).

## DO NOT
- Do NOT reclassify the overage products in meals_products (breaks the old system — the whole reason
  for the ID-exclusion approach).
- Do NOT hardcode the IDs — read them from MealsDB_Operational_Constants::default_overage_product_ids().
- Do NOT touch the slip generator's overage handling (already correct for slips).
- Do NOT change how non-overage meals/sides are counted.

## NOTE FOR OPERATOR (not part of this edit)
The slip generator's get_overage_product_ids() maps 'overage_sides_taxable' to the NONTAX constant and
vice-versa (labels swapped). Harmless there (all IDs lumped into one exclusion set) and harmless for
this directive (we use the correctly-labeled default_overage_product_ids() and only need the SET). But
it's a latent bug if those keys are ever used to distinguish tax treatment — worth a future cleanup.
