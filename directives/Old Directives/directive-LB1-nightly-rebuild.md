# Directive LB-1: Nightly allocation sync must REBUILD dirty months, not just re-sum

**Audit reference:** recon-03 (CRIT, allocation engine), recon-14 §2 LB-1. Highest-leverage fix in the audit — relieves LB-4 and the reporting/Quick-Order symptoms of the stale-allocation gap.
**Severity:** LAUNCH BLOCKER (cutover). **Scope:** ~5–15 lines, 1 file (`includes/class-allocation-hooks.php`). **Risk:** LOW — the correct method already exists and is already used by two other callers.

---

## Background (why this is broken)

The allocation system is event-sourced. When an order changes, a hook calls `MealsDB_Allocation_Rebuilder::mark_dirty($client_id, $billing_month)`, which records the pair in the `meals_client_month_dirty` table. The **rebuilder** is the only thing that writes per-delivery rows into `meals_delivery_allocations` (by calling `allocate_order` / `fill_months`).

The nightly cron handler `MealsDB_Allocation_Hooks::nightly_sync()` (in `includes/class-allocation-hooks.php`, ~line 304) calls `$engine->bulk_recalculate_month($month)`. That method (`class-allocation-engine.php` ~line 524) loops every active SDNB/Veteran client and calls `recalculate_month_totals()` — which **only re-sums existing `meals_delivery_allocations` rows into the summary**. It never calls the rebuilder.

**Result:** a client-month that was marked dirty but never materialised (no invoice run, no manual "Recalculate Allocations" button press) has NO detail rows to sum. The nightly job re-sums nothing, writes a zero/stale summary, and calls `MealsDB_Job_Logger::finish()` with success. The dirty flag is never consumed by the nightly path. Downstream this causes: stale/empty billing data, zero/stale reconciliation + spillover reports, **driver slips over-collecting the monthly contribution on every delivery** (LB-4 — the slip's "first delivery of month?" check sees no rows and defaults to true), and a stale allowance in the Quick Order preview.

**The correct behavior already exists and is proven.** The rebuilder exposes `rebuild_all_dirty()` (processes every dirty client-month) and `rebuild_for_invoice()` (scoped). Two callers already use the rebuilder correctly:
- Invoice generation: `class-invoice-generator.php:461` → `rebuild_for_invoice($billing_month, ...)`.
- The manual Data-Ops button: `class-ajax-settings.php:300` → `rebuild_all_dirty()`.
- The migration tool materialises per-order via `allocate_order` (recon-11).

The nightly cron is simply calling the wrong method. The fix is to have it consume the dirty queue via the rebuilder before (or instead of) the re-sum.

---

## Pre-flight verification (run before changing anything)

**P1 — Confirm the dirty table exists and has the expected shape.**
```sql
SELECT * FROM 2xnIt_meals_client_month_dirty LIMIT 5;
```
Expect columns identifying `client_id` and `billing_month`. If the table is missing, STOP — the mark-dirty mechanism isn't deployed and this fix's assumptions are wrong.

**P2 — Confirm the rebuilder method signatures (do not assume).**
```bash
grep -n "public function rebuild_all_dirty\|public function rebuild_for_invoice\|public function mark_dirty" includes/services/class-allocation-rebuilder.php
```
Expect `rebuild_all_dirty(): array` and `rebuild_for_invoice(string $billing_month, ?array $client_ids = null): array`.

**P3 — Confirm the nightly handler currently calls only `bulk_recalculate_month`.**
```bash
sed -n '304,356p' includes/class-allocation-hooks.php
```
Expect to see two `bulk_recalculate_month(...)` calls (current month + next month) and NO call to any rebuilder method. If you see a rebuilder call already, STOP and report — the bug may already be partly fixed and this directive needs revising.

**P4 — Confirm `rebuild_all_dirty()` returns a stats array (for logging).**
```bash
sed -n '203,243p' includes/services/class-allocation-rebuilder.php
```
Note the shape of the returned array (e.g. counts of client-months rebuilt, errors) so the job-logger `finish()` call can record it.

---

## The fix

In `includes/class-allocation-hooks.php`, method `nightly_sync()` (~line 304), insert a rebuild-dirty pass **before** the re-sum. The rebuild materialises any un-built dirty months; the existing `bulk_recalculate_month` calls then correctly re-sum (now non-empty) detail. This is intentionally additive — it keeps the existing re-sum as a belt-and-suspenders consistency pass rather than removing it.

**Current code (~lines 316–319):**
```php
        try {
            $engine = new MealsDB_Allocation_Engine();

            $current_count = $engine->bulk_recalculate_month($current_month);
```

**Change to:**
```php
        try {
            $engine = new MealsDB_Allocation_Engine();

            // LB-1 fix: materialise any dirty client-months BEFORE re-summing.
            // bulk_recalculate_month() only RE-SUMS existing meals_delivery_allocations
            // rows; it does NOT create them. Dirty months that were never built (no
            // invoice run, no manual recalc) have no rows to sum, so the nightly job
            // used to write zero/stale summaries and report success. Consuming the
            // dirty queue via the rebuilder here is what the invoice path and the
            // manual Data-Ops button already do (rebuild_for_invoice / rebuild_all_dirty).
            $rebuilder    = new MealsDB_Allocation_Rebuilder();
            $rebuild_stats = $rebuilder->rebuild_all_dirty();

            $current_count = $engine->bulk_recalculate_month($current_month);
```

**Then fold the rebuild stats into the success log.** Update the `MealsDB_Job_Logger::finish()` call (~lines 337–345) to include the rebuild outcome, so the daily report can see how many dirty months were materialised:

```php
            if ($log_id > 0) {
                MealsDB_Job_Logger::finish($log_id, [
                    'records_processed'   => $current_count + $next_count,
                    'records_updated'     => $current_count + $next_count,
                    'months_recalculated' => [$current_month, $next_month],
                    'current_month_count' => $current_count,
                    'next_month_count'    => $next_count,
                    'dirty_rebuild_stats' => $rebuild_stats,   // LB-1: record what was materialised
                ]);
            }
```

(Adjust the `'dirty_rebuild_stats'` key/shape to match the actual return of `rebuild_all_dirty()` confirmed in P4. The job-logger routes unknown keys into the context JSON, so any associative shape is safe to pass.)

**Do NOT remove** the `bulk_recalculate_month` calls. Rebuilding then re-summing is correct and cheap; the re-sum also covers the (rare) case where a summary drifted from its detail without a dirty flag.

---

## Testing

### Automated
There is currently **no test that the nightly path materialises dirty months** (recon-12.5 — this gap is exactly why the bug shipped). Add one:

Create `tests/test-nightly-allocation-rebuilds-dirty.php` (standalone, mock-`$wpdb` style matching the existing tests). It must:
1. Seed a `meals_client_month_dirty` row for a client-month that has orders but NO `meals_delivery_allocations` detail rows.
2. Invoke `MealsDB_Allocation_Hooks::nightly_sync()` (or, if static-mocking the engine is impractical, invoke the rebuild step directly and assert it's wired into the handler).
3. Assert that after the run, `meals_delivery_allocations` detail rows EXIST for that client-month (i.e. the rebuilder ran), not just that a summary row exists.
4. Assert the dirty flag for that client-month is cleared/consumed.

The key assertion is **detail rows were created**, because a re-sum-only implementation would pass a "summary exists" check but fail a "detail rows exist" check. (Contrast with the existing `test-allocation-rebuilder.php`, which tests the rebuilder in isolation — that's why the bug hid: the rebuilder works; nothing tested that nightly CALLS it.)

### Manual (dev)
1. On a staging copy, find or create a client with orders in the current month but an un-built allocation (e.g. mark dirty without running an invoice).
2. Confirm `meals_delivery_allocations` has no rows for that client-month.
3. Trigger the nightly hook manually: `wp cron event run mealsdb_nightly_allocation_sync` (or via WP-CLI / the cron debugger).
4. Confirm `meals_delivery_allocations` now has detail rows for that client-month, the summary is correct, and the dirty flag is gone.
5. Confirm the daily report / job log shows the rebuild stats.

---

## Out of scope (do NOT do these here)

- Do **not** change `bulk_recalculate_month` or `recalculate_month_totals` themselves — they are correct re-sum primitives; the bug is the caller.
- Do **not** touch the finalized-month guarding — that's LB-3 (separate directive). Note: `rebuild_all_dirty()` must already respect finalized months; if P4 shows it does NOT skip finalized client-months, STOP and coordinate with the LB-3 fix before shipping this, because rebuilding a finalized month would wipe submitted detail.
- Do **not** change the HPOS trash/delete hooks — that's LB-5.
- Do **not** alter the cron schedule time (03:00 is fine).

> **Cross-check with LB-3 — RESOLVED:** this directive makes the nightly job rebuild dirty months. The ordering dependency was that `rebuild_all_dirty()` / `fill_months` must skip finalized months, or running it nightly would rewrite finalized/submitted detail. **Directive LB-3 has now landed:** `rebuild_client_month` skips a finalized target month and `fill_months` excludes finalized months (target or neighbour) from both the DELETE and the inserts. So the P4 question "does `rebuild_all_dirty()` skip finalized months?" now answers YES, and the nightly rebuild is safe to ship.

---

## Acceptance criteria

- [ ] `nightly_sync()` calls `MealsDB_Allocation_Rebuilder::rebuild_all_dirty()` before the `bulk_recalculate_month` re-sum.
- [ ] The rebuild stats are recorded in the job-logger `finish()` payload.
- [ ] A new test asserts that nightly materialises DETAIL rows for a dirty, previously-unbuilt client-month (not just a summary).
- [ ] Manual staging test confirms a dirty-but-unbuilt client-month gets materialised by the nightly run.
- [ ] Verified (P4 / LB-3 cross-check) that `rebuild_all_dirty()` skips finalized months — OR LB-3 lands first.
- [ ] CLAUDE.md §10's LB-1 warning is removed/updated once this ships (the doc currently says "nightly does NOT rebuild — today it does not").

---

## Why this fix is low-risk

The rebuilder is already the production path for invoicing and the manual recalc button; `rebuild_all_dirty()` is already called by `class-ajax-settings.php`. This directive routes the nightly cron through the same proven method. The only genuinely new surface is "running the rebuilder unattended, nightly, across all dirty months" — which is why the LB-3 finalized-month cross-check is mandatory before shipping.
