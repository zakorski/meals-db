# Task: Add Shadow Mode (parallel-run safety gate)

Apply `shadow-mode.patch` to the **meals-db** plugin checkout.

## Ordering

Third in the series. Apply in this order:
1. `consolidation.patch`
2. `quickorder-fees.patch`
3. `shadow-mode.patch`  ← this one

This patch's context assumes the first two are applied. `git apply --check`
will fail loudly if they are not, rather than apply anything wrong.

## What this does

Shadow mode lets the plugin run alongside the existing (legacy) system on the
SAME live database for comparison, WITHOUT affecting day-to-day operations or
anything legacy can see. When ON it suppresses exactly three legacy-visible
side effects:

1. **Quick Order** — disabled (AJAX `create_order`/`clone_order` refuse; the
   page shows a notice instead of the form).
2. **Order fee application** — `MealsDB_Order_Fees::apply_to_order()` returns
   early, so no fee line items are written to WooCommerce orders. (The
   allocation hook still observes the order and populates `meals_*`.)
3. **Sync write-back to WordPress users** — `update_wp_user()` (the chokepoint
   every `wp_update_user`/`update_user_meta` flows through) refuses.

Everything else runs normally: allocation observation, private-client intake,
and ALL invoice/report/document GENERATION (these only read or write `meals_*`,
which is invisible to legacy). Per operator decision, the daily-report email
and the admin product-tax write are intentionally left live and are NOT gated.

New flag class `MealsDB_Shadow_Mode` (`includes/class-shadow-mode.php`) with a
checkbox under Meals DB → Settings → Shadow Mode.

## FAIL-SAFE (important)

`MealsDB_Shadow_Mode::is_enabled()` returns TRUE (shadow ON) unless the setting
is explicitly, readably OFF. Missing option, corrupt option, or absent key all
read as ON. The intent: a misconfiguration must never silently let live writes
through during the trial. A `MEALSDB_SHADOW_MODE` constant (define in
wp-config) overrides the option if you need to pin it.

Consequence to expect: **on a fresh install with no settings saved, the plugin
is in shadow mode by default.** To go live (cutover), an admin must explicitly
uncheck the box and save (or define `MEALSDB_SHADOW_MODE` false).

## Steps

```bash
grep -m1 "Version:" meals-db-main.php        # 1.0.353
git apply --check shadow-mode.patch          # clean
git apply shadow-mode.patch
git add -A && git status --short
```

Expected (10 files): 2 added (`class-shadow-mode.php`, `test-shadow-mode.php`),
8 modified (settings js/php/view, quick-order ajax+ui, order-fees, sync-mutate,
test-order-fees).

```bash
# Lint
for f in includes/class-shadow-mode.php includes/services/class-order-fees.php \
         includes/services/sync/class-sync-mutate.php includes/class-quick-order-ajax.php \
         includes/class-quick-order-ui.php includes/ajax/class-ajax-settings.php views/settings.php; do
  php -l "$f" || echo "LINT FAILED: $f"
done

# Targeted tests
php tests/test-shadow-mode.php     # expect: Ran 11 checks: 11 passed
php tests/test-order-fees.php      # expect: Ran 12 checks: 12 passed

# Full suite (needs mysqli, mbstring, gd, dom/xml)
clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"
  else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

Expected: **53 / 53 clean**.

## Staging validation

With shadow mode ON (default):
1. Meals DB → Settings shows the Shadow Mode checkbox, checked.
2. Open Quick Order → it shows the "disabled in shadow mode" notice, no form.
3. Place an order through the NORMAL WooCommerce screen for a government
   client → confirm NO fee products get added to the order (legacy sees a
   clean order), but `meals_*` allocations still populate.
4. Trigger a sync that would push a field to a WP user → confirm the WP user
   record is unchanged.
5. Generate an SDNB/VAC invoice and a report → confirm they still produce
   output (generation is not gated).

Then flip OFF (uncheck, save) on staging and confirm the inverse: Quick Order
works, fees apply, sync writes through. Do NOT turn shadow OFF on production
until cutover.

Report back: `git status`, lint, `RESULT: X / Y`.
