# Directive BC-3: Orders into a finalized month are silently dropped; unfinalize doesn't re-dirty

**Audit reference:** 2026-06 review, allocation/billing subsystem. Extends LB-3 (finalized immutability) — LB-3 correctly stops the rebuilder *mutating* finalized months, but introduced/left two silent-loss gaps on the *boundary*.
**Severity:** LAUNCH BLOCKER (cutover) — late or corrected orders for a submitted month vanish with no operator-visible signal.
**Scope:** ~25–40 lines across `includes/services/class-allocation-rebuilder.php` and `includes/services/class-invoice-draft.php`. **Risk:** LOW.

---

## Background — two boundary gaps left by LB-3

LB-3 made `rebuild_client_month` skip a finalized target month. But:

### Gap 1 — the dirty flag is consumed on skip, so the order is lost forever

`rebuild_client_month` on a finalized target (lines ~161–169) logs to `error_log`, **calls `clear_dirty()`**, and returns zeros:

```php
if (isset($finalized[$billing_month])) {
    error_log(sprintf('[MealsDB Rebuilder] Skipped finalized target month %s for client %d.', ...));
    $this->clear_dirty($client_id, $billing_month); // <-- consumes the flag
    return ['mains_unplaced' => 0, 'sides_unplaced' => 0];
}
```

So when a back-dated order arrives for a finalized month, `allocate_order` marks it dirty → the next rebuild (or the nightly `rebuild_all_dirty`) sees the finalized flag, **throws the dirty marker away**, and the order is never materialised. Unlike the spill-into-finalized case (which surfaces as a `meals_allocation_errors` spillover row), this path emits **no** error row and **no** degraded event — it just disappears. The operator has no way to know a meal was ordered for a closed month.

### Gap 2 — unfinalize doesn't re-queue the month

`MealsDB_Invoice_Draft::unfinalize()` (line ~420 area) clears the finalized lock via `unfinalize_month()` but **never calls `mark_dirty()`**. So the operator who deliberately unlocks a month to *fix exactly this problem* gets a month that still doesn't rebuild — nothing re-materialises the orders that arrived while it was locked until some unrelated order re-dirties that client-month. The unlock appears to do nothing.

---

## Pre-flight verification

**P1 — Confirm the dirty-flag consumption on finalized skip.**
```bash
sed -n '161,169p' includes/services/class-allocation-rebuilder.php
```
Expect `clear_dirty(...)` inside the finalized-target branch.

**P2 — Confirm unfinalize doesn't re-dirty.**
```bash
grep -n "unfinalize\|mark_dirty\|unfinalize_month" includes/services/class-invoice-draft.php
```
Expect an `unfinalize_month` call with NO adjacent `mark_dirty`.

**P3 — Confirm a degraded-event helper is available** (to surface the dropped order).
```bash
grep -n "MealsDB_Event_Log::record\|'degraded'" includes/services/class-allocation-rebuilder.php
```

---

## The fix

### Step 1 — On finalized-target skip: DON'T consume the flag; DO surface it

Replace the finalized-target branch so the dirty marker survives (the month is genuinely still dirty — it has unmaterialised work) and the operator is told:

```php
if (isset($finalized[$billing_month])) {
    // BC-3: a dirty marker on a FINALIZED month means an order arrived (or
    // changed) for a submitted invoice. We cannot rewrite the invoice, but we
    // must NOT pretend the work is done: leave the dirty flag in place so it
    // resurfaces, and emit a degraded event so the operator can decide whether
    // to unfinalize-and-rebuild or handle it out of band. Consuming the flag
    // here (the pre-BC-3 behaviour) silently dropped the order.
    error_log(sprintf(
        '[MealsDB Rebuilder] Dirty marker on FINALIZED month %s for client %d — left queued, not rebuilt.',
        $billing_month, $client_id
    ));
    if (class_exists('MealsDB_Event_Log')) {
        MealsDB_Event_Log::record([
            'severity'    => 'warning',
            'category'    => 'allocation',
            'subsystem'   => 'allocation_rebuilder',
            'event'       => 'rebuild.dirty_finalized_month',
            'outcome'     => 'degraded',
            'message'     => 'Order activity for a finalized (submitted) month was not materialised.',
            'context'     => ['client_id' => $client_id, 'billing_month' => $billing_month],
        ]);
    }
    // Deliberately NO clear_dirty(): the month stays queued.
    return ['mains_unplaced' => 0, 'sides_unplaced' => 0];
}
```

> **Important interaction with the nightly job:** because the flag now persists, `rebuild_all_dirty` will revisit this month every night and re-emit the degraded event. That is the desired "nag until handled" behaviour, but confirm the event digest de-dups or that the volume is acceptable (finalized-month order activity should be rare). If repeated nightly events are noisy, gate the event on a once-per-day transient keyed by `client_id:billing_month` — but never resume `clear_dirty` here.

### Step 2 — Unfinalize re-dirties every affected client-month

In `MealsDB_Invoice_Draft::unfinalize()`, after `unfinalize_month()` succeeds, mark each affected client-month dirty so the now-open month rebuilds and picks up anything that accumulated while locked:

```php
// BC-3: unlocking a month exists precisely to let it rebuild. Re-queue every
// client-month we just unfinalized so the rebuilder materialises orders that
// arrived while it was finalized (BC-3 Gap 1 left those queued-but-skipped).
$rebuilder = new MealsDB_Allocation_Rebuilder();
foreach ($affected_client_ids as $cid) {            // the set this unfinalize covered
    $rebuilder->mark_dirty((int) $cid, $billing_month);
}
```

Use whatever client-scope `unfinalize()` already iterates (the shared-lock cascade it confirms). If unfinalize is month-wide, mark dirty for every client that had a summary row in that month.

---

## Testing

`tests/test-finalized-order-boundary.php` (mock `$wpdb`):
1. **Flag survives:** finalize month M for client C; mark (C, M) dirty; `rebuild_client_month(C, M)` → assert the dirty flag is **still present** and a `degraded` event was recorded; assert no detail rows changed.
2. **Unfinalize re-queues:** finalize M; mark (C, M) dirty (simulating a late order); unfinalize M → assert (C, M) is dirty; rebuild → assert the late order is now materialised.
3. **Normal open month unaffected:** dirty open month rebuilds and clears as before (no regression).

**Manual:** finalize a month on staging, place a back-dated order into it, observe the degraded event on the Event Log dashboard; unfinalize, run `rebuild_all_dirty`, confirm the order is now billed.

---

## Out of scope

- Do not change how finalization/unfinalization is gated or audited (INV-2 owns unfinalize gating).
- Do not auto-unfinalize — the operator must decide. This directive only stops the silent drop and makes unlock actually rebuild.

## Acceptance criteria

- [ ] Finalized-target skip no longer calls `clear_dirty`; it emits a `degraded` event instead.
- [ ] `unfinalize()` marks every affected client-month dirty.
- [ ] Tests cover flag-survival + degraded event, unfinalize re-queue, and open-month no-regression.
- [ ] (If applicable) nightly re-emit noise is bounded by a once-per-day transient, without resuming `clear_dirty`.
