# Directive LB-4: Driver slips must not over-collect the monthly contribution

**Audit reference:** recon-09 (BUG, money), consequence of recon-03 LB-1. recon-14 §2 LB-4.
**Severity:** LAUNCH BLOCKER (cutover) — real cash over-collected from clients. **Scope:** ~20–30 lines, 1 file (`includes/services/class-slip-pdf-generator.php`). **Risk:** LOW.

---

## Background (why this is broken)

The monthly client contribution should be collected ONCE per billing month — on the first delivery. The driver slip decides this via `MealsDB_Slip_PDF_Generator::is_first_delivery_of_month()` (~line 385), which returns `true` (= "collect the contribution") in THREE cases:
- bad input (line 387),
- no `$wpdb` (line 390),
- **`MIN(delivery_date)` from `meals_delivery_allocations` is NULL/empty — i.e. no allocation rows exist for the month (line 404).**

That third case is the problem. Per LB-1, `meals_delivery_allocations` is only populated when the rebuilder has run. Early in a billing month — before any invoice run or recalc — a client-month has no allocation rows, so `MIN(delivery_date)` is NULL → the method returns `true` for **every** delivery → the collection calculator adds the contribution to **every** driver slip. Drivers over-collect the monthly contribution on every visit until allocations are materialised.

**The dangerous default is backwards:** when the system doesn't *know* whether the contribution was already collected, it currently defaults to collecting it (the expensive, client-harming direction). It should default to NOT over-collecting.

### There is an authoritative source for "was the contribution already collected?"

The fee system already tracks this directly. `meals_client_allocations` has a `contribution_applied` flag (0/1) per `(client_id, billing_month)`, set to 1 when the contribution is billed — see `MealsDB_Order_Fees::contribution_applied_this_month()` and `mark_contribution_applied()`. This flag is the DIRECT answer to "should the driver collect the contribution on this delivery?", and it is independent of whether allocation DETAIL rows were materialised. The slip's `MIN(delivery_date)` approach is an indirect proxy for the same question — and it's the proxy that breaks when detail rows are missing.

---

## Pre-flight verification

**P1 — Confirm the three fail-to-true branches.**
```bash
sed -n '385,409p' includes/services/class-slip-pdf-generator.php
```
Expect three `return true;` branches (bad input, no wpdb, no allocation rows).

**P2 — Confirm the contribution_applied flag exists and its semantics.**
```bash
grep -n "contribution_applied" includes/services/class-order-fees.php
sed -n '/function contribution_applied_this_month/,/^    }/p' includes/services/class-order-fees.php
```
Expect a `contribution_applied` column on `meals_client_allocations`, set to 1 once the contribution is billed for the month.

**P3 — Confirm the slip has `client_id` + `billing_month` available where the check is called.**
```bash
sed -n '341,356p' includes/services/class-slip-pdf-generator.php
```
Expect `$client['client_id']` and a `$delivery_date` (whose first 7 chars are the billing month) in scope.

**P4 — Confirm relationship to LB-2.** The `contribution_applied` flag is written by the fee path. Under shadow mode, fees aren't applied — so during the trial the flag may not be set. That's fine: shadow mode also means no real collection happens. The flag matters at/after cutover, when fees ARE applied. Note this interaction; it does not block the fix.

---

## The fix

Make "first delivery of month" consult the authoritative `contribution_applied` ledger, and flip the unknown-case defaults to the financially-safe direction.

Replace `is_first_delivery_of_month()` (~lines 385–409) with a version that:
1. Returns the genuine answer from allocation rows when they exist (unchanged primary path);
2. When allocation rows are ABSENT, falls back to the `contribution_applied` flag instead of blindly returning `true`;
3. Treats true error conditions (bad input, no wpdb) as "do not over-collect" — but only if it can't determine the answer at all.

```php
    /**
     * Should the monthly client contribution be collected on this delivery?
     *
     * The contribution is collected once per billing month, on the first
     * delivery. Historically this was inferred from MIN(delivery_date) in
     * meals_delivery_allocations — but those detail rows only exist after the
     * allocation rebuilder has run (see LB-1). Before materialisation, the old
     * code defaulted to TRUE and over-collected the contribution on every
     * delivery (LB-4).
     *
     * The authoritative signal is the contribution_applied flag on
     * meals_client_allocations, set when the fee path bills the contribution.
     * We use that as the source of truth: if the contribution has already been
     * applied this month, do NOT collect again. When we genuinely cannot
     * determine state, we fail to the financially-safe direction (do not
     * over-collect).
     */
    private function is_first_delivery_of_month(int $client_id, string $delivery_date): bool {
        if ($client_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
            return false; // can't identify the client/month — do not over-collect
        }
        if (!isset($GLOBALS['wpdb'])) {
            return false; // no DB — do not over-collect
        }

        $wpdb          = $GLOBALS['wpdb'];
        $billing_month = substr($delivery_date, 0, 7);
        $summary_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        $alloc_table   = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);

        // 1) Authoritative: has the contribution already been applied/collected
        //    this month? If so, this is NOT a collect-the-contribution delivery.
        $already_applied = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT contribution_applied FROM `{$summary_table}`
             WHERE client_id = %d AND billing_month = %s",
            $client_id,
            $billing_month
        ));
        if ($already_applied === 1) {
            return false;
        }

        // 2) If allocation detail rows exist, use the genuine earliest-delivery
        //    signal (correct once the rebuilder has materialised the month).
        $earliest = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(delivery_date) FROM `{$alloc_table}`
             WHERE client_id = %d AND billing_month = %s",
            $client_id,
            $billing_month
        ));
        if ($earliest !== null && $earliest !== '') {
            return (string) $earliest === $delivery_date;
        }

        // 3) No allocation rows AND contribution not yet applied. We cannot
        //    prove this is the earliest delivery. Per LB-4, do NOT default to
        //    collecting (the old bug). Once LB-1 materialises allocations and/or
        //    the fee path sets contribution_applied, the correct delivery will
        //    collect it. Failing safe here means at worst a contribution is
        //    collected one delivery later — never over-collected every visit.
        return false;
    }
```

> **Design rationale for the §3 default:** the two error directions are not symmetric. The OLD bug (default true) over-collects on EVERY delivery — real money taken from clients repeatedly, hard to detect, hard to refund. The NEW default (false) at worst delays a single legitimate collection until allocations/fees are in place — a smaller, self-correcting error, and one the contribution ledger will catch up on. For a system handling vulnerable clients' cash, "never over-collect" is the correct bias. LB-1 remains the real fix that makes the primary path reliable; this directive is the cash-safety belt that holds even if LB-1 hasn't run.

---

## Testing

### Automated
The slip collection tests (`test-pdf-slip-collection-govt-first-of-month.php` and the `not-first` sibling) currently only stub the allocations-present happy path (recon-12.5). Add cases:
1. **contribution_applied = 1, allocations present:** assert contribution is NOT collected (already applied this month).
2. **No allocation rows, contribution_applied = 0:** assert contribution is NOT collected (the LB-4 fix — old code would collect).
3. **Allocations present, this IS the earliest delivery, contribution not applied:** assert contribution IS collected (genuine first delivery).
4. **Allocations present, this is NOT the earliest delivery:** assert contribution is NOT collected.

Case 2 is the regression guard for this bug specifically.

### Manual (dev, staging — post-cutover-like state)
1. SDNB client with `client_contribution > 0`, current month, NO allocation rows yet, `contribution_applied = 0`.
2. Generate a driver slip for a delivery: confirm the contribution is NOT on the collect breakdown.
3. Run the allocation rebuild (or bill the contribution so `contribution_applied = 1`).
4. Generate the slip for the genuine first delivery: confirm the contribution IS collected exactly once; subsequent deliveries that month do NOT collect it again.

---

## Out of scope

- Do NOT change `MealsDB_Collection_Calculator::for_government` — it correctly takes `$is_first` as input; we're fixing what we feed it.
- Do NOT change the fee/contribution-applied writing logic (that's the order-fees path, LB-2).
- Do NOT remove the `MIN(delivery_date)` path — it's still the correct signal once allocations exist; we're adding the authoritative ledger check in front of it and fixing the unknown-case default.
- LB-1 is the broader fix (materialise allocations); this directive does not depend on LB-1 landing first, and is valuable precisely because it holds even before LB-1 runs.

---

## Acceptance criteria

- [ ] `is_first_delivery_of_month` checks `contribution_applied` first and returns false if the contribution was already applied this month.
- [ ] When allocation rows are absent and the contribution is not yet applied, the method returns FALSE (no over-collection) — not the old `true`.
- [ ] The genuine first-delivery case (allocations present, earliest date, not yet applied) still returns true.
- [ ] Tests cover all four cases above, including the no-rows regression guard.
- [ ] Manual staging test confirms no over-collection before materialisation, and exactly-once collection after.

---

## Relationship to other directives

- **Complements LB-1, doesn't depend on it.** LB-1 makes the allocation data reliable; LB-4 makes the slip safe even when it isn't. Both should ship for cutover.
- **Interacts with LB-2.** The `contribution_applied` flag is set by the fee path (LB-2's area). The two are consistent: LB-2 ensures the right amount is billed and the flag is set; LB-4 ensures the slip reads that flag to avoid double-collection.
