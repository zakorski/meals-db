# Directive BC-1: Rebuilder loses spilled meals and double-counts dual-program clients

**Audit reference:** 2026-06 review, allocation/billing subsystem (two HIGH findings in `class-allocation-rebuilder.php::load_deliveries_for_months` and the order pull).
**Severity:** LAUNCH BLOCKER (cutover) — silent under-billing AND silent double-billing.
**Scope:** ~60–100 lines, 1 file (`includes/services/class-allocation-rebuilder.php`). **Risk:** MED-HIGH — touches the core fill window and the order-to-client routing. **Write the failing tests FIRST** (this directive mandates TDD; the invariants are subtle).

---

## Background — two distinct bugs in one method

`load_deliveries_for_months()` (lines ~440–555) and `rebuild_client_month()` (lines ~133–212) recompute a client-month's allocations from raw WC orders. Two independent defects:

### Bug A — spilled meals are deleted but never re-placed (under-billing)

The rebuild window is `{prior, current, next}` (line ~178). `fill_months()` **DELETEs all `meals_delivery_allocations` rows whose `billing_month` is in that window**, then re-inserts from the deliveries that `load_deliveries_for_months` returns. But that loader keeps only deliveries **whose `delivery_date`-month is in the window** (line 536: `if (!in_array(substr($delivery_date, 0, 7), $months, true)) continue;`).

The mismatch: a delivery's meals can be *billed* to a later month than they were *delivered* (forward spill when a month is over cap). Concretely — client over cap in **March**, so a March delivery's overflow is billed to **April** (`billing_month='2026-04'`, `delivery_date` in March). Later, rebuilding **May** uses window `{April, May, June}`:

1. `fill_months` DELETEs the April-billed row (billing_month April ∈ window). ✔ gets deleted
2. `load_deliveries_for_months` does **not** reload the March delivery (delivery-month March ∉ `{April,May,June}`). ✘ never reloaded
3. `recalculate_month_totals('2026-04')` re-sums April **lower** — the spilled meals vanish.

No dirty flag, no `meals_allocation_errors` row, no event. The header comment (lines 105–127) reasons about prior→current spill but never about **prior-prior → prior** spill, which is exactly what the DELETE window's leading edge exposes.

### Bug B — order→client routing matches the wrong client (double-billing & mis-billing)

The order pull (lines ~457–474):

```php
$wp_user_id = (int) $this->wpdb->get_var(... "SELECT wp_user_id FROM clients WHERE client_id = %d" ...);
$orders = $this->wpdb->get_results(... "SELECT ... FROM wc_orders o
    WHERE o.customer_id = %d AND o.status NOT IN (...) AND DATE(o.date_created_gmt) BETWEEN %s AND %s" ...,
    $wp_user_id, ...);
```

Three failure modes:

1. **No `wp_user_id > 0` guard.** A government client whose `wp_user_id` is NULL/0 (e.g. a Quick Order client pinned only by `mealsdb_client_id` meta, marked dirty by `allocate_order`) yields `$wp_user_id = 0`, so `WHERE o.customer_id = 0` matches **every guest-checkout order in the window** and allocates them all to that client.
2. **The `mealsdb_client_id` order meta is ignored.** The comment at lines 453–455 claims orders are found "either via `mealsdb_client_id` meta or by joining … on `customer_id`" — but the meta path **does not exist in the code**. An order pinned by meta to a client whose `wp_user_id` differs from `customer_id` is marked dirty yet never materialised → those meals are never billed.
3. **Dual-program users double-count (MAJ-1).** For the operator-confirmed legitimate case (one WP user = two client records, e.g. SDNB recipient who is also a Veteran), **both** client rebuilds run this query, both match all the user's orders by `customer_id`, and the same meals are allocated to **both** clients. The `mealsdb_rate_id` disambiguation added at the engine/order-fees resolver layer (`resolve_client_id_by_wp_user`) is completely bypassed here.

---

## Pre-flight verification

**P1 — Confirm the loader filters by delivery-month (Bug A).**
```bash
sed -n '534,538p' includes/services/class-allocation-rebuilder.php   # the delivery-month filter
sed -n '244,256p' includes/services/class-allocation-rebuilder.php   # the billing_month DELETE
```
Expect: DELETE keyed on `billing_month IN (window)`, load filtered on `delivery_date`-month ∈ window. The asymmetry IS the bug.

**P2 — Confirm the order pull keys only on customer_id (Bug B).**
```bash
sed -n '457,474p' includes/services/class-allocation-rebuilder.php
grep -rn "mealsdb_client_id\|mealsdb_rate_id" includes/services/class-allocation-engine.php includes/services/class-order-fees.php
```
Expect the resolver pattern in engine/order-fees but NOT in the rebuilder.

**P3 — How is an order pinned to a client?** Confirm the meta keys QO writes:
```bash
grep -rn "update_meta_data\|add_meta_data\|mealsdb_client_id\|mealsdb_rate_id" includes/class-quick-order-ajax.php
```
Record the exact meta keys — the fix reads them.

**P4 — Staging data probe.** Find a real spill row to test against:
```sql
SELECT client_id, billing_month, delivery_date, mains
FROM 2xnIt_meals_delivery_allocations
WHERE billing_month <> DATE_FORMAT(delivery_date, '%Y-%m')
ORDER BY delivery_date DESC LIMIT 20;
```
Each such row is a cross-month spill — the BC-1a regression target.

---

## The fix

### Part 1 (Bug B — routing): make the order pull client-correct

Replace the `customer_id`-only query with a resolver that (a) refuses to run on `wp_user_id <= 0` without a meta anchor, (b) honours the `mealsdb_client_id` order meta, and (c) for `customer_id`-matched orders on a multi-client user, keeps only the orders that resolve to **this** client via the existing rate/client resolver.

```php
        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $wp_user_id = (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT wp_user_id FROM `{$clients_table}` WHERE client_id = %d",
            $client_id
        ));

        $orders_table = $this->wpdb->prefix . 'wc_orders';
        $meta_table   = $this->wpdb->prefix . 'wc_orders_meta';
        $date_lo = (new DateTime($range_start))->modify('-7 days')->format('Y-m-d');
        $date_hi = (new DateTime($range_end))->modify('+7 days')->format('Y-m-d');

        // (a) Orders explicitly pinned to THIS client by meta — authoritative,
        //     independent of customer_id (Quick Order writes mealsdb_client_id).
        $pinned = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT o.id, DATE(o.date_created_gmt) AS order_date
             FROM `{$orders_table}` o
             INNER JOIN `{$meta_table}` m ON m.order_id = o.id
                 AND m.meta_key = 'mealsdb_client_id' AND m.meta_value = %s
             WHERE o.status NOT IN ('wc-cancelled','wc-failed','wc-refunded','wc-trash','wc-checkout-draft')
               AND o.type = 'shop_order'
               AND DATE(o.date_created_gmt) BETWEEN %s AND %s",
            (string) $client_id, $date_lo, $date_hi
        ), ARRAY_A);

        // (b) Orders matched by customer_id — ONLY when this client owns the
        //     wp_user link, and EXCLUDING any order already pinned to a
        //     different client by meta. For a dual-program user (two clients
        //     share wp_user_id, MAJ-1), keep only orders that resolve to THIS
        //     client via the rate/client resolver; never blanket-claim them.
        $by_customer = [];
        if ($wp_user_id > 0) {
            $by_customer = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT o.id, DATE(o.date_created_gmt) AS order_date
                 FROM `{$orders_table}` o
                 WHERE o.customer_id = %d
                   AND o.type = 'shop_order'
                   AND o.status NOT IN ('wc-cancelled','wc-failed','wc-refunded','wc-trash','wc-checkout-draft')
                   AND DATE(o.date_created_gmt) BETWEEN %s AND %s
                   AND NOT EXISTS (
                       SELECT 1 FROM `{$meta_table}` mx
                       WHERE mx.order_id = o.id AND mx.meta_key = 'mealsdb_client_id'
                             AND mx.meta_value <> %s
                   )",
                $wp_user_id, $date_lo, $date_hi, (string) $client_id
            ), ARRAY_A);

            // If this wp_user maps to more than one active client, filter the
            // customer_id matches down to the ones that resolve to THIS client
            // (mirrors MealsDB_Allocation_Engine::resolve_client_id_by_wp_user,
            // which uses mealsdb_rate_id to disambiguate). Single-client users
            // keep all of them — no extra work.
            if ($this->wp_user_has_multiple_clients($wp_user_id)) {
                $by_customer = array_values(array_filter($by_customer, function ($o) use ($client_id) {
                    return $this->engine->resolve_client_id_for_order((int) $o['id']) === $client_id;
                }));
            }
        }

        // Merge, de-dup by order id (a pinned order may also match customer_id).
        $orders = [];
        foreach (array_merge($pinned, $by_customer) as $o) {
            $orders[(int) $o['id']] = $o;
        }
        $orders = array_values($orders);
```

> **Resolver reuse:** if `MealsDB_Allocation_Engine` does not already expose a public `resolve_client_id_for_order(int $wc_order_id): ?int` and a way to tell whether a `wp_user_id` maps to multiple active clients, add thin wrappers around the existing private `resolve_client_id_by_wp_user`/`mealsdb_rate_id` logic rather than duplicating it (CLAUDE.md: "Two parallel … paths invariably diverge"). Confirm the exact private method name during P2 and wire to it.

### Part 2 (Bug A — spill): expand the load window, keep the write window

Load deliveries for a **4-month** window `[prior-prior, prior, current, next]` so a prior-prior delivery's forward spill into `prior` is recomputed, but **delete and write only** the original `{prior, current, next}` window. The earliest month is *consume-only*: its deliveries are placed (so we know how much overflows into `prior`) but its own base rows are neither deleted nor written (they already exist, untouched, outside the DELETE window).

1. In `rebuild_client_month`, compute `$prior_prior = self::prior_month($prior_month);` and pass it to the loader and the fill, tagged consume-only.
2. Extend `load_deliveries_for_months` to accept the consume-only month: load its deliveries (line-536 filter must admit it) but tag each `'consume_only' => true`.
3. In `fill_months`: include `$prior_prior` in `$caps`/`$headroom` so placement math is correct, but (a) exclude it from the DELETE month set, and (b) when writing rows, skip any row whose resulting `billing_month === $prior_prior` (its base meals are already billed there; only its spill into `prior` gets written). This reuses the finalized-month "don't write this month" plumbing from LB-3 — generalise that exclusion set from "finalized" to "do-not-write" and add `$prior_prior` to it, while STILL granting it headroom (finalized months get no headroom; consume-only months do — keep the two sets distinct).

> **Why not just reload by `billing_month`?** Because spill is computed by *delivery* sequence, not stored billing month; you must replay the deliveries through the cap logic to reproduce the split. Expanding the replay window one month earlier is the minimal correct replay.

> **Chained spill (prior-prior-prior):** two consecutive over-cap months chaining a spill across three boundaries is possible but rare. A one-month leading expansion covers the common case; document the residual as a known limit and rely on the nightly `rebuild_all_dirty` (which rebuilds each dirty month at its own center) to converge multi-hop chains over successive nights. Do **not** widen the window indefinitely.

---

## Testing (write these FIRST — TDD)

Standalone tests with a mock `$wpdb`, `tests/test-rebuilder-spill-and-routing.php`:

**Bug A — conservation across rebuilds:**
1. Over-cap March → meals spill to April. Snapshot total `mains` across all months = N.
2. Mark May dirty; rebuild May. Assert total `mains` across all months **still = N** (no loss); assert the April row sourced from the March delivery still exists with the same quantity.
3. Reverse: over-cap April spilling to May, rebuild April — assert no double-write into May.

**Bug B — routing:**
4. Client with `wp_user_id = 0` pinned via `mealsdb_client_id` meta: rebuild pulls exactly the pinned orders, and a guest order (`customer_id = 0`) in the window is **not** attached.
5. Dual-program user (two clients, same `wp_user_id`, orders tagged with each client's `mealsdb_rate_id`): rebuild client A pulls only A's orders, rebuild client B pulls only B's; assert the shared user's meals are **not** counted twice.
6. Single-client user: all customer_id orders still pulled (no regression).

**Manual (staging):** pick the spill row from P4, snapshot the client's per-month totals, mark a later month dirty, run `rebuild_all_dirty`, confirm totals conserved.

---

## Out of scope

- Do not change cap/allowance math (`calculate_permitted_for_month`) or the schedule mapping.
- Do not add a UNIQUE on `wp_user_id` (MAJ-1: duplicates are legitimate).
- Do not touch finalized-month handling beyond reusing its exclusion plumbing (BC-3 / LB-3 own that).

## Acceptance criteria

- [ ] Tests for Bug A (conservation) and Bug B (routing, dual-program, guest-isolation) written first and failing against current code.
- [ ] Order pull honours `mealsdb_client_id` meta, guards `wp_user_id > 0`, excludes orders pinned to other clients, and disambiguates dual-program users via the existing resolver (no duplicated logic).
- [ ] Order pull adds `o.type = 'shop_order'` and excludes `wc-checkout-draft` (shared with BC-6/BC-7).
- [ ] Load+fill window expanded one month on the leading edge as consume-only; DELETE/WRITE window unchanged.
- [ ] All tests green; manual staging conservation check passes.
- [ ] Comment at lines 453–455 corrected to describe what the code actually does.
