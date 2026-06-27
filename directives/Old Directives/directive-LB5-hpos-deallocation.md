# Directive LB-5: HPOS-correct order trash/delete deallocation (and stop masking it in tests)

**Audit reference:** recon-03 (BUG), recon-07 Q7.6 (daily report monitors dead hooks), recon-12.5 (masking test), recon-13 (prior CRIT-1 only half-applied). recon-14 §2 LB-5.
**Severity:** LAUNCH BLOCKER (cutover) — deleted orders silently keep counting in the allocation ledger. **Scope:** ~20–40 lines across 3 files (`class-allocation-hooks.php`, `class-daily-report.php`, `tests/test-allocation-hooks-swallow.php`). **Risk:** LOW.

---

## Background (why this is broken)

The site is HPOS-exclusive: orders live in `wc_orders`, NOT `wp_posts`. Two deallocation handlers gate on the wp_posts world:

- `on_order_trashed()` (line 256): `if (get_post_type($post_id) !== 'shop_order') return;`
- `on_order_deleted()` (line 285): same guard.

Under HPOS, `get_post_type($order_id)` does NOT return `'shop_order'` for an order (it's not a post), so **both handlers always early-return and never deallocate.** When an order is trashed or permanently deleted, its meals keep counting against the client's monthly allowance forever. They're registered on the wp_posts hooks `trashed_post` / `before_delete_post` (lines 26–27), which under HPOS don't fire for orders anyway — so the handlers are doubly dead (wrong hook AND wrong guard).

**Three compounding facts:**
1. **The handlers are dead** (wrong hooks + wrong guard).
2. **The daily report monitors those dead hooks.** `MealsDB_Daily_Report::INSTRUMENTED_HOOKS` (lines 41–42) lists `trashed_post` / `before_delete_post`. Their silence reads as "healthy" when it actually means "never fires" — the report can't detect the problem (recon-07 Q7.6).
3. **A test masks it.** `tests/test-allocation-hooks-swallow.php` line 27 stubs `get_post_type($p)` to ALWAYS return `'shop_order'`, so in the test's fake world the guard passes and deallocation runs — the test passes while production is broken (recon-12.5).

**Prior remediation context (recon-13):** the previous audit's directive-01 fixed the daily-report reconciliation QUERIES to use HPOS tables, but did NOT fix these deallocation-hook guards or the monitored-hook list. This directive finishes that job.

### The good news: `deallocate_order` is already safe and self-validating

`MealsDB_Allocation_Engine::deallocate_order($wc_order_id)` (~line 420) looks up `meals_delivery_allocations` rows by `wc_order_id`, and if none exist it simply returns. It then marks the affected client-months dirty so the rebuilder reconciles them. So:
- The `get_post_type` guard was NOT protecting `deallocate_order` from bad input — `deallocate_order` already no-ops on a non-order ID. The guard's only stated purpose (lines 259–263) was to avoid logging noise from `trashed_post` firing for every post type.
- Once we subscribe to HPOS hooks that fire ONLY for orders, the noise concern disappears and the guard becomes unnecessary.
- Deallocation correctly defers to the rebuilder (which, after LB-3, respects finalized months) — so a deleted order's allocations are cleanly removed on the next rebuild.

---

## Pre-flight verification

**P1 — Confirm the dead guards.**
```bash
sed -n '256,299p' includes/class-allocation-hooks.php
```
Expect both handlers gated on `get_post_type($post_id) !== 'shop_order'`.

**P2 — Confirm `deallocate_order` is self-validating.**
```bash
sed -n '/function deallocate_order/,/^    }/p' includes/services/class-allocation-engine.php
```
Expect: looks up allocations by `wc_order_id`, returns early if none, marks dirty.

**P3 — Confirm the available HPOS order-lifecycle hooks in the installed WC version.**
```bash
grep -rn "woocommerce_trash_order\|woocommerce_delete_order\|woocommerce_before_trash_order\|woocommerce_before_delete_order" vendor/woocommerce 2>/dev/null | head
# or check the WC version's Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore
```
Expect `woocommerce_trash_order` and `woocommerce_delete_order` (both fire with an order ID, only for orders). Confirm exact names against the deployed WC version — do NOT assume. If the version uses `woocommerce_before_delete_order`, use that.

**P4 — Confirm the daily report monitored-hook list.**
```bash
sed -n '35,52p' includes/class-daily-report.php
```
Expect `trashed_post` / `before_delete_post` in `INSTRUMENTED_HOOKS`.

**P5 — Confirm the test stub.**
```bash
grep -n "get_post_type" tests/test-allocation-hooks-swallow.php
```
Expect the line-27 stub returning `'shop_order'` unconditionally.

---

## The fix

### Step 1 — Subscribe to HPOS order-lifecycle hooks; drop the wp_posts ones

In `init()` (lines 26–27), replace the two wp_posts hook registrations:

```php
        add_action('trashed_post', [self::class, 'on_order_trashed'], 20, 1);
        add_action('before_delete_post', [self::class, 'on_order_deleted'], 20, 1);
```

with the HPOS order hooks (use the exact names confirmed in P3):

```php
        // HPOS: orders are not posts, so trashed_post / before_delete_post never
        // fire for them. Subscribe to WooCommerce's own order-lifecycle hooks,
        // which fire with an order ID and only for orders. (LB-5)
        add_action('woocommerce_trash_order', [self::class, 'on_order_trashed'], 20, 1);
        add_action('woocommerce_delete_order', [self::class, 'on_order_deleted'], 20, 1);
```

> Keep the existing `woocommerce_order_status_trash` → `on_order_status_trashed` registration (line 25); it covers the status-transition-to-trash path and is HPOS-correct already. The new `woocommerce_trash_order` covers the trash ACTION; together they cover both routes.

### Step 2 — Remove the dead `get_post_type` guards

Rewrite `on_order_trashed()` and `on_order_deleted()` to drop the guard (the new hooks fire only for orders, and `deallocate_order` is self-validating). Route them through the existing shared helper for consistency:

```php
    /**
     * Handle order trashed (HPOS: woocommerce_trash_order). Fires with an
     * order ID, only for orders, so no post-type guard is needed.
     */
    public static function on_order_trashed(int $order_id): void {
        self::process_deallocation_hook('woocommerce_trash_order', $order_id);
    }

    /**
     * Handle order permanently deleted (HPOS: woocommerce_delete_order).
     */
    public static function on_order_deleted(int $order_id): void {
        self::process_deallocation_hook('woocommerce_delete_order', $order_id);
    }
```

This reuses `process_deallocation_hook()` (lines 236–249), which already does engine + try/log/swallow + hook recording — eliminating the duplicated try/catch blocks in the old handlers.

### Step 3 — Update the daily report's monitored hooks

In `class-daily-report.php`, `INSTRUMENTED_HOOKS` (lines 41–42), replace the dead hook names with the HPOS ones so the report monitors hooks that can actually fire:

```php
        'woocommerce_trash_order',
        'woocommerce_delete_order',
```

(Match the exact strings used in `safe_record_hook`/`process_deallocation_hook` so the report's "did this hook fire recently?" check aligns with what's actually recorded.)

### Step 4 — Fix the masking test

In `tests/test-allocation-hooks-swallow.php`:
- Remove or correct the line-27 stub `function get_post_type($p) { return 'shop_order'; }`. Since the handlers no longer call `get_post_type`, the stub is now irrelevant for the trash/delete path — remove it (or leave a no-op only if other code under test needs it).
- Add assertions that `on_order_trashed` / `on_order_deleted` actually invoke `deallocate_order` (e.g. via the mock engine recording the call) and swallow exceptions. The point: the test must now exercise the REAL path, not a `get_post_type`-gated fake.

Add a dedicated regression test `tests/test-hpos-deallocation.php` (or extend the existing file):
1. Seed `meals_delivery_allocations` rows for an order.
2. Call `on_order_deleted($order_id)`.
3. Assert the affected client-months were marked dirty (deallocation happened) — proving the handler runs without a `get_post_type` gate.

---

## Testing

### Automated
- The corrected `test-allocation-hooks-swallow.php` must no longer rely on a `get_post_type→'shop_order'` stub to make the trash/delete handlers run.
- New assertion: trash/delete handlers mark the order's client-months dirty (deallocation occurred).

### Manual (dev, staging — HPOS)
1. Create an order for an SDNB client; confirm it's allocated (`meals_delivery_allocations` has rows for it).
2. **Trash** the order via the WC admin. Confirm the client-month is marked dirty and, after a rebuild, the order's meals are no longer counted.
3. **Permanently delete** an allocated order. Confirm the same.
4. Confirm the daily report's hook-activity section now shows `woocommerce_trash_order` / `woocommerce_delete_order` firing (not perpetual silence on the old hooks).

---

## Out of scope

- Do NOT change `deallocate_order` — it's already correct (self-validating, marks dirty, defers to rebuilder).
- Do NOT change the status-transition hooks (`woocommerce_order_status_*`) — those are HPOS-correct.
- Do NOT add back any `get_post_type` / `wp_posts` order lookups anywhere (CLAUDE.md "don't query orders via wp_posts on HPOS").
- The reconciliation QUERIES in the daily report were already fixed by the prior directive-01 — don't touch them; only the monitored-hook LIST needs updating here.

---

## Acceptance criteria

- [ ] Trash/delete deallocation is registered on `woocommerce_trash_order` / `woocommerce_delete_order` (exact names per P3), not `trashed_post` / `before_delete_post`.
- [ ] `on_order_trashed` / `on_order_deleted` no longer use `get_post_type`; they route through `process_deallocation_hook`.
- [ ] `MealsDB_Daily_Report::INSTRUMENTED_HOOKS` lists the HPOS hooks, so monitoring reflects reality.
- [ ] `test-allocation-hooks-swallow.php` no longer masks the bug with a `get_post_type` stub; a regression test proves the handlers deallocate.
- [ ] Manual staging test confirms trashing and deleting an allocated order removes its allocation after rebuild.
- [ ] CLAUDE.md's LB-5 note updated once shipped.

---

## Relationship to other directives

- Independent of LB-1/LB-2/LB-3/LB-4 (different code path), can land in parallel.
- Benefits from LB-3: deallocation marks client-months dirty and defers to the rebuilder; after LB-3 the rebuilder won't touch finalized months, so deleting an order in a finalized month won't rewrite the submitted invoice (it would just be flagged). Worth noting in the LB-3 cross-check — deleting an order from a finalized month is an operations question (you can't un-submit an invoice by deleting an order), but the code will at least not silently rewrite it.
