# Directive LB-3: Rebuilder must treat finalized months as immutable

**Audit reference:** recon-03 (BUG-HIGH), recon-04, recon-14 §2 LB-3. **Prerequisite for LB-1** — LB-1 runs the rebuilder nightly, so without this guard LB-1 would wipe finalized/submitted detail every night.
**Severity:** LAUNCH BLOCKER (cutover). **Scope:** ~30–50 lines, 1 file (`includes/services/class-allocation-rebuilder.php`); optional small change in `class-invoice-generator.php`. **Risk:** LOW-MEDIUM — touches the core fill algorithm; well-contained with a clear guard point.

---

## Background (why this is broken)

A "finalized" client-month is a submitted government invoice — it must never change. Finalization sets `is_finalized = 1` on the summary row in `meals_client_allocations` (per `client_id` + `billing_month`); see `MealsDB_Allocation_Engine::finalize_month()` (~line 504).

The **re-sum** path correctly guards it: `recalculate_month_totals()` (engine ~line 340) reads `is_finalized` and returns early if set.

The **rebuilder does NOT guard it at all** (confirmed: `grep is_finalized` in `class-allocation-rebuilder.php` returns nothing). Two problems:

1. **No target guard.** `rebuild_client_month()` (~line 99) skips only `Private` clients. It never checks `is_finalized`, so rebuilding a finalized client-month proceeds.
2. **The fill DELETEs and rewrites detail across a 3-month window.** `rebuild_client_month` computes caps for `{prior, current, next}` months (~lines 114–137) and calls `fill_months()`, whose first action is an **unconditional DELETE** of all `meals_delivery_allocations` rows for the client across all three months (~lines 248–255), then re-inserts from scratch. So:
   - Rebuilding a finalized month wipes its submitted detail.
   - Even rebuilding an OPEN month wipes a finalized NEIGHBOUR (e.g. rebuilding open June pulls in May; if May is finalized, May's submitted detail is deleted).

Re-running invoice generation on a finalized month hits the same path (recon-04). Once live billing starts, this silently destroys the audit trail of submitted invoices and can change amounts after submission.

**Why this blocks LB-1:** LB-1 makes the nightly cron call `rebuild_all_dirty()`, which funnels every dirty client-month through `rebuild_client_month` → `fill_months`. Without this guard, the nightly job would delete-and-rewrite any finalized month that is dirty or is a neighbour of a dirty month — every night.

---

## Pre-flight verification

**P1 — Confirm the finalized flag location and granularity.**
```bash
grep -n "is_finalized\|finalize_month" includes/services/class-allocation-engine.php
```
Expect `is_finalized` on `meals_client_allocations` keyed by `(client_id, billing_month)`, set by `finalize_month()`.

**P2 — Confirm the rebuilder currently has no finalized guard.**
```bash
grep -n "is_finalized\|finaliz" includes/services/class-allocation-rebuilder.php
```
Expect NO output. If there IS output, STOP and report — a guard may already exist and this directive needs revising.

**P3 — Confirm the fill DELETE spans the whole window.**
```bash
sed -n '244,256p' includes/services/class-allocation-rebuilder.php
```
Expect a `DELETE ... WHERE client_id = %d AND billing_month IN (...)` over all months in `$caps` (prior/current/next).

**P4 — Inventory current finalized months on staging (so you can test against real locked data).**
```sql
SELECT client_id, billing_month, finalized_at
FROM 2xnIt_meals_client_allocations
WHERE is_finalized = 1
ORDER BY billing_month DESC LIMIT 20;
```

---

## The fix

The guard must protect finalized months **both as the rebuild target and as a neighbour in the window**. Implement at two levels: a fast helper, a target-skip, and a per-month exclusion from the fill's DELETE/insert.

### Step 1 — Add a finalized-lookup helper to the rebuilder

Add near the top of `MealsDB_Allocation_Rebuilder` (private helper):

```php
    /**
     * Which of the given (client_id, month) pairs are finalized?
     *
     * Finalized months are submitted invoices and must never be deleted or
     * rewritten by the fill. Returns the subset of $months that are finalized
     * for this client.
     *
     * @param int      $client_id
     * @param string[] $months  YYYY-MM values
     * @return array<string,bool> month => true, for finalized months only
     */
    private function finalized_months(int $client_id, array $months): array {
        if (empty($months)) {
            return [];
        }
        $alloc_summary = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        $placeholders  = implode(',', array_fill(0, count($months), '%s'));
        $params        = array_merge([$client_id], $months);
        $rows = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT billing_month FROM `{$alloc_summary}`
             WHERE client_id = %d AND billing_month IN ({$placeholders}) AND is_finalized = 1",
            $params
        ));
        $out = [];
        foreach ((array) $rows as $m) {
            $out[(string) $m] = true;
        }
        return $out;
    }
```

### Step 2 — Skip a finalized TARGET month in `rebuild_client_month`

In `rebuild_client_month()` (~line 99), after the Private-client skip (~line 112) and before computing caps, add:

```php
        // LB-3: never rebuild a finalized month — it's a submitted invoice.
        // (The window below also protects finalized PRIOR/NEXT neighbours.)
        $finalized = $this->finalized_months($client_id, [
            self::prior_month($billing_month),
            $billing_month,
            self::next_month($billing_month),
        ]);
        if (isset($finalized[$billing_month])) {
            error_log(sprintf(
                '[MealsDB Rebuilder] Skipped finalized target month %s for client %d.',
                $billing_month, $client_id
            ));
            $this->clear_dirty($client_id, $billing_month); // consume the flag; nothing to do
            return ['mains_unplaced' => 0, 'sides_unplaced' => 0];
        }
```

Then pass the `$finalized` set into the fill so it also protects neighbours. Change the `fill_months(...)` call (~line 132) to add the finalized set as a new argument:

```php
        $unplaced = $this->fill_months(
            $client_id,
            [$prior_month => $cap_prior, $billing_month => $cap_curr, $next_month => $cap_next],
            $deliveries,
            $billing_month,
            $finalized              // LB-3: months in here are never deleted or written
        );
```

And guard the three `recalculate_month_totals` refreshes (~lines 140–142) so a finalized neighbour's summary isn't recomputed (the engine already guards this internally, but skip explicitly for clarity):

```php
        foreach ([$prior_month, $billing_month, $next_month] as $m) {
            if (!isset($finalized[$m])) {
                $this->engine->recalculate_month_totals($client_id, $m);
            }
        }
```

### Step 3 — Exclude finalized months from the DELETE and the inserts in `fill_months`

Change the signature (~line 244):

```php
    private function fill_months(int $client_id, array $caps, array $deliveries, ?string $error_month = null, array $finalized = []): array {
```

Make the DELETE skip finalized months (~lines 248–255):

```php
        // Wipe the slate for the affected months — we recompute from scratch.
        // LB-3: but NEVER delete a finalized month (submitted invoice).
        $months_to_clear = array_values(array_filter($months, static function ($m) use ($finalized) {
            return empty($finalized[$m]);
        }));
        if (!empty($months_to_clear)) {
            $placeholders = implode(',', array_fill(0, count($months_to_clear), '%s'));
            $params = array_merge([$client_id], $months_to_clear);
            $this->wpdb->query($this->wpdb->prepare(
                "DELETE FROM `{$alloc_table}`
                 WHERE client_id = %d AND billing_month IN ({$placeholders})",
                $params
            ));
        }
```

Prevent the fill from WRITING into a finalized month. In the `$place_to_month` closure (~line 278), make a finalized month behave like one outside the window (don't place, leave the meals to spill/he counted as unplaced):

```php
            $place_to_month = function(string $month) use (
                &$remaining_mains, &$remaining_tax_sides, &$remaining_nontax_sides,
                &$headroom, $d, $client_id, $alloc_table, $finalized
            ) {
                if (!isset($headroom[$month]) || !empty($finalized[$month])) {
                    return; // outside our window, OR finalized (immutable) — leave for caller
                }
                // ... unchanged ...
```

This way a finalized neighbour keeps exactly the rows it had at finalization: not deleted, not added to.

> **Design note:** excluding a finalized month from the fill means meals that would have spilled INTO a finalized month have nowhere to go and are counted as unplaced (a spillover error) — which is the correct behavior. You cannot retroactively add meals to a submitted invoice; surfacing it as an error is right. Do NOT try to "make room" in a finalized month.

### Step 4 (optional, recommended) — guard the invoice-gen rebuild entry too

`rebuild_for_invoice()` and `rebuild_all_dirty()` both funnel through `rebuild_client_month`, so Step 2's target-guard covers them. No separate change needed — but confirm via the tests below that re-running invoice generation on a finalized month is now a no-op for that month.

---

## Testing

### Automated
Add `tests/test-rebuilder-skips-finalized.php` (standalone, mock-`$wpdb`). Cases:
1. **Finalized target:** mark a client-month finalized with known detail rows; call `rebuild_client_month` for it; assert the detail rows are UNCHANGED (not deleted, not re-inserted) and the dirty flag is cleared.
2. **Finalized neighbour:** finalize May; mark June dirty with orders; rebuild June; assert May's detail is UNCHANGED and June is rebuilt normally.
3. **Spill into finalized:** finalize June; a May delivery that would spill into June; rebuild May; assert the overflow is counted as unplaced (spillover error) and NO row is written into June.
4. **Re-invoice no-op:** finalized month → `rebuild_for_invoice` → assert that month's detail is untouched.

### Manual (dev)
1. On staging, pick a finalized month with known `meals_delivery_allocations` rows (P4). Snapshot them.
2. Mark that client-month dirty and run `rebuild_all_dirty()` (the manual Data-Ops button).
3. Confirm the finalized month's rows are byte-identical to the snapshot.
4. Confirm an OPEN dirty month still rebuilds correctly in the same run.

---

## Out of scope

- Do NOT change `finalize_month()` or how finalization is set — only how the rebuilder RESPECTS it.
- Do NOT change the spillover/error-logging semantics beyond the natural consequence that meals can't be placed into a finalized month (that SHOULD log as unplaced).
- Do NOT touch the engine's existing re-sum guard (it's already correct).
- LB-1 (nightly calling the rebuilder) is a separate directive — but this one MUST land first or together with it.

---

## Acceptance criteria

- [ ] `finalized_months()` helper added.
- [ ] `rebuild_client_month` skips a finalized target month (and clears its dirty flag).
- [ ] `fill_months` accepts the finalized set, excludes those months from the DELETE, and never inserts into them.
- [ ] Finalized-neighbour summaries are not recomputed.
- [ ] Tests cover: finalized target, finalized neighbour, spill-into-finalized, re-invoice no-op.
- [ ] Manual staging test confirms finalized detail is byte-identical before/after a rebuild run.
- [ ] **LB-1 unblocked:** with this in place, `rebuild_all_dirty()` is safe to run nightly. Update LB-1's pre-flight P4 note accordingly.

---

## Relationship to LB-1

LB-1's pre-flight P4 asks "does `rebuild_all_dirty()` skip finalized months?" — the answer today is NO. **This directive is what makes the answer YES.** Ship LB-3 first (or in the same change as LB-1). Once LB-3 lands, LB-1 can route the nightly cron through `rebuild_all_dirty()` safely.
