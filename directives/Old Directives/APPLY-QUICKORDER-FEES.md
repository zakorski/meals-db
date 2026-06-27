# Task: Make Quick Order a pure data-entry UI; apply program fees uniformly

You are working in the **meals-db** WordPress plugin checkout. Apply the
accompanying patch `quickorder-fees.patch`.

## Ordering relative to the consolidation patch

This patch was built on top of the consolidated-migration change
(`consolidation.patch` / `APPLY-CONSOLIDATION.md`). **Apply the consolidation
patch first, then this one.** They are independent in intent but this patch's
context lines assume consolidation is already applied.

- If you have already applied `consolidation.patch`: proceed.
- If not: apply it first (see `APPLY-CONSOLIDATION.md`), then return here.
- The two patches touch mostly different files; the only shared file is none
  (consolidation does not modify the QO/allocation-hook files this patch
  touches), so if you must apply this one alone, `git apply --check` will
  tell you whether the context still matches.

## What this changes and why

**Goal:** Quick Order should be nothing more than a faster interface for
placing an order — it must not do anything that normal WooCommerce order
creation wouldn't. That way the plugin can draw from orders placed through
ANY channel (normal WooCommerce screen, etc.) without missing fees or
contribution tracking.

**Before:** Quick Order added the delivery fee and monthly client
contribution itself, as `WC_Order_Item_Fee` objects, and wrote the
contribution-applied flag inline at order-creation. Consequences:
- Fees only landed on orders placed through Quick Order. A government
  client's order placed through the normal WooCommerce screen got no fee and
  no contribution tracking — a silent billing gap.
- `WC_Order_Item_Fee` is a different on-order shape than the fee PRODUCTS
  (5675 / 4122) that legacy and normal orders use, forcing every fee reader
  to understand two formats.

**After:**
- New class `MealsDB_Order_Fees` (`includes/services/class-order-fees.php`)
  applies the delivery fee and monthly contribution as fee PRODUCTS
  (5675 / 4122) to every qualifying government order, driven from the
  existing allocation hook on the active-status transition. It runs for QO
  and normal orders alike.
- The allocation hook (`includes/class-allocation-hooks.php`) no longer
  pre-filters on the QO-only `mealsdb_client_user_id` meta (which silently
  skipped normal orders). It now lets any shop_order through and resolves the
  client downstream via the order's native `customer_id`, same as
  `allocate_order()` already does.
- Quick Order (`includes/class-quick-order-ajax.php`) no longer creates fee
  items or writes the allocation flag. It still sets its identity meta and
  reads the contribution flag for the on-screen collection preview.

**Taxation:** This patch does NOT set or override tax anywhere. It adds the
fee product and lets WooCommerce tax it per that product's configuration. The
plugin reads tax; it never sets it. (The old QO code forced
`set_tax_status('none')` on its fee items — that override is now gone.)

## CRITICAL precondition — verify before a real run

Because the plugin no longer forces fees tax-off, the tax treatment of fees
is now entirely governed by the WooCommerce product configuration. Confirm in
WooCommerce that **products 5675 (Client Contribution) and 4122 (Delivery
Fee) are set to "None" / non-taxable**, matching how the collection math
treats them (flat amounts, no HST). If they are taxable, government invoices
will gain HST on fees the moment this goes live. (The site owner is
confirming this separately — do not assume it; surface it in your report.)

## Steps

```bash
# 0. Baseline (consolidation already applied per above)
grep -m1 "Version:" meals-db-main.php          # 1.0.353
git apply --check quickorder-fees.patch         # must be clean

# 1. Apply
git apply quickorder-fees.patch
git add -A
git status --short
```

Expected (4 files):
```
A  includes/services/class-order-fees.php
A  tests/test-order-fees.php
M  includes/class-allocation-hooks.php
M  includes/class-quick-order-ajax.php
```

```bash
# 2. Lint
for f in includes/services/class-order-fees.php \
         includes/class-allocation-hooks.php \
         includes/class-quick-order-ajax.php; do
  php -l "$f" || echo "LINT FAILED: $f"
done

# 3. New unit test (should print "Ran 12 checks: 12 passed")
php tests/test-order-fees.php

# 4. Full suite (extensions: mysqli, mbstring, gd, dom/xml)
clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"
  else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

**Expected: `52 / 52 clean`** (with both patches applied).

## Behavior change to validate on staging

This is live-billing code. After the suite is green, on **staging**:

1. Confirm products 5675 / 4122 are non-taxable in WooCommerce.
2. Place a test order for an SDNB/Veteran client **through the normal
   WooCommerce screen** (not Quick Order). Move it to processing. Confirm the
   delivery fee product appears on the order, and the contribution product
   appears if it's the client's first qualifying order of the month — this is
   the gap this patch closes.
3. Place a second order for the same client the same month; confirm the
   contribution is NOT applied again (monthly guard), but the delivery fee IS.
4. Cancel the contribution-bearing order; confirm the month frees up and the
   next qualifying order re-applies the contribution (handled by the existing
   `deallocate_order` flag release).
5. Place a Quick Order for a government client; confirm fees now come from the
   hook (fee products), and the QO collection-preview still shows the right
   "contribution due / already applied" status.

Report back: `git status`, lint results, `RESULT: X / Y`, and confirmation of
the 5675/4122 tax status.
