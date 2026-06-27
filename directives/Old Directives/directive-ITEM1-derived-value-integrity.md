# Directive ITEM1-DERIVED — Nightly derived-value integrity check

**Status:** ready to implement
**Verified at:** v1.0.416 (MAJ-6 + MAJ-7 shipped; full suite 72/72).
**Reuses:** the canonical date logic in `MealsDB_Client_Dates` / `MealsDB_Date_Calculator`, the
nightly-sync cron, the STR-LOG event trunk (`degraded` events) + audit log boundary, MAJ-7's
re-run-safety lesson.

**Goal:** a nightly pass that detects (and optionally corrects) client-profile *derived* values
that have drifted out of sync with the inputs they're computed from — closing the windows where a
stored derived value silently disagrees with its source.

---

## THE DRIFT VECTORS (verified in code — this is narrower & sharper than "re-derive everything")

The derived date fields are kept fresh by EVENTS, not by time:
- `next_order_date` ← recomputed by `MealsDB_Client_Dates::advance_on_order()`, fired from the
  allocation lifecycle hook on EVERY order (`class-allocation-hooks.php:111`).
- `next_delivery_date` ← recomputed by `MealsDB_Client_Dates::mark_delivered()`, fired from the
  `client_delivery` task's "Mark as Delivered" (`class-task-type-client-delivery.php:63`).

Both call `MealsDB_Date_Calculator::next_date($base, $frequency, $delivery_day)`. So they do NOT
drift with the passage of time. They drift from TWO specific paths, both confirmed:

1. **Input drift.** The client form lets an operator edit `delivery_frequency`,
   `ordering_frequency`, and `delivery_day`. The save path (`MealsDB_Client_Form::save` →
   `update_client`) does NOT recompute the dates. So after a frequency/day edit, the stored
   `next_*_date` is computed from STALE inputs until the next order/delivery event fires. (e.g.
   switch a client weekly→biweekly today; `next_delivery_date` keeps the weekly cadence until
   their next delivery is marked complete.)
2. **Direct edit.** `next_order_date` and `next_delivery_date` are THEMSELVES editable fields in
   the client form (field list lines ~81–82). An operator can type a value with no check that it's
   consistent with frequency + delivery_day.

**No existing integrity/re-derive pass exists** (confirmed: nightly-sync has none) — we are not
duplicating anything.

---

## SCOPE — which fields are in, and their classification

| Field | Derived from | In scope? | Default action |
|---|---|---|---|
| `next_order_date` | `last_order_date` + `ordering_frequency` + `delivery_day` (via `Date_Calculator`) | **YES** | **Flag** (see below) |
| `next_delivery_date` | `last_delivery_date` + `delivery_frequency` + `delivery_day` | **YES** | **Flag** (see below) |
| `delivery_day` | zone → day via `mealsdb_zone_delivery_schedule` | **YES** | **Flag only** |
| `service_name_zone` | (was zone-derived at migration) | **NO** | excluded — it's operator-editable in the client form (`service_zone` ↔ `service_name_zone`), so there is no single "correct" derived value to check against. |
| `delivery_initials` | — | **NO** | operator-entered + validated, not computed. |
| `*_termination_date` | — | **NO** | entered via the form's date handling, not derived. |

### The auto-correct vs. flag decision (the heart of the directive)
My first instinct was "the date fields are pure math → auto-correct." Reading the code corrected
that: **`next_order_date` / `next_delivery_date` are directly operator-editable.** So a stored
value that differs from the computed value might be a deliberate manual override, not drift.
Blindly auto-correcting would stomp operator intent — the exact failure mode the blank-fill-only
`delivery_day` backfill was designed to avoid.

**Therefore: FLAG by default for every in-scope field. Auto-correct is OPT-IN, per-field, via a
setting, and OFF by default.** Rationale:
- Flagging is always safe: it surfaces the mismatch (a `degraded` trunk event) for a human to
  judge; it never overwrites anything.
- Auto-correct, where enabled, is appropriate ONLY for a field the operator confirms has no
  legitimate manual override (likely the date fields, once the operator decides they never
  hand-set them). `delivery_day` should almost certainly stay flag-only (overrides are real — the
  backfill preserves them).
- This makes the directive safe to ship with everything flag-only, and lets the operator turn on
  auto-correct field-by-field later once they're comfortable — no code change needed.

**Operator question to confirm (does NOT block — flag-only is the safe default):** "Do you ever
hand-edit a client's Next Order Date / Next Delivery Date to something other than what the
schedule would compute? If never, we can let the nightly check auto-fix them; if sometimes, it
should only flag them for you."

---

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>
# 1. The canonical calculator to REUSE (do not reimplement the snapping logic).
grep -n "function next_date" includes/services/class-date-calculator.php
grep -n "function advance_on_order\|function mark_delivered" includes/services/class-client-dates.php
# 2. Confirm the drift: form saves frequency/day/next_* without recompute.
grep -n "next_order_date\|next_delivery_date\|delivery_frequency\|Client_Dates" includes/class-client-form.php | head
# 3. Confirm no integrity pass already exists (don't duplicate).
grep -rn "integrity\|re-deriv\|rederive\|drift\|reconcile" includes/class-task-cron.php includes/services/class-sync.php
# 4. The nightly entry point to hook into + MAJ-7's spawn lock (reuse the re-run-safety pattern).
grep -n "function nightly_sync\|mealsdb_task_spawn_running\|transient" includes/class-task-cron.php
# STOP if next_date's signature changed, or if an integrity pass now exists.
```

---

## THE FIX

### Step 1 — A read-only checker service: `MealsDB_Derived_Value_Check`
New file `includes/services/class-derived-value-check.php`. Pure detection — computes the expected
value and compares; does NOT write in its default (flag-only) mode.

```php
class MealsDB_Derived_Value_Check {

    /**
     * Check one active client's derived fields. Returns a list of mismatches:
     * [ ['field'=>'next_delivery_date','stored'=>'2026-02-05','expected'=>'2026-02-12','reason'=>'frequency/day changed'], ... ]
     * Pure: no writes.
     */
    public static function check_client(array $client): array { /* ... */ }

    /**
     * Expected next_delivery_date for a client: recompute from the SAME
     * inputs the event path uses, via the canonical calculator. Returns null
     * when inputs are insufficient (no frequency / no last_delivery_date) —
     * "can't compute" is NOT a mismatch (don't flag a client who simply has
     * no schedule yet).
     */
    private static function expected_next_delivery(array $client): ?string {
        // base = last_delivery_date (usermeta) ; freq = delivery_frequency ; day = delivery_day
        // return MealsDB_Date_Calculator::next_date($base, $freq, $day);
    }
    private static function expected_next_order(array $client): ?string { /* last_order_date + ordering_frequency + delivery_day */ }
    private static function expected_delivery_day(array $client): ?string { /* zone -> mealsdb_zone_delivery_schedule */ }
}
```

**Critical reuse rule:** `expected_next_*` MUST compute via `MealsDB_Date_Calculator::next_date`
with the identical argument order the event handlers use — NOT a reimplementation. The whole point
is to catch divergence from the canonical computation; a second copy of the snapping logic could
itself drift and produce false mismatches. If `Client_Dates` exposed a pure "compute next" helper
this could call, prefer that; otherwise call `Date_Calculator` directly with the same inputs
`Client_Dates` reads.

**"Can't compute" ≠ mismatch.** A client with no frequency, no last_delivery_date, or a blank
delivery_day yields a null expected value → SKIP (not a flag). Only a *computable* expected value
that differs from a *non-empty* stored value is a mismatch. This avoids drowning the operator in
"incomplete profile" noise (that's a different concern, not drift).

### Step 2 — The nightly pass: `MealsDB_Derived_Value_Audit` (job)
A scheduled job (daily, after the existing nightly work; reuse the cron registration pattern).
For each active client: `check_client()`; for each mismatch:
- **Always:** emit a `degraded` STR-LOG trunk event (`category='integrity'`,
  `subsystem='derived_values'`, `event='client.<field>.drift'`, entity=client) with stored vs.
  expected in context (no raw PII — these are dates/days/zones, not sensitive, but route through
  the trunk's scrubber as usual).
- **If auto-correct is enabled for that field** (per-field setting, default OFF): write the
  corrected value via the SAME path the event handler would
  (`MealsDB_Client_Dates`/repository update), and **audit the correction**
  (`MealsDB_Logger::log('derived_value_corrected', client_id, field, old, new)`) — a committed
  change to client data → audit log, per the STR-LOG boundary.
- Tally counts; finish the job with stats (clients checked, mismatches found, corrections applied).

**Re-run safety (MAJ-7 lesson):** the pass must be idempotent — running it twice produces the same
result (flag-only is naturally idempotent; auto-correct converges — a corrected value matches on
the second run, no-op). If it shares the nightly window with the task spawn, respect/extend the
existing overlap guard rather than running unguarded.

**Performance:** batch the client scan (LIMIT/offset or a single SELECT of the few needed columns
across active clients — it's a handful of columns, not the full encrypted row). Don't decrypt PII;
the checked fields (`delivery_day`, frequencies, `next_*_date`, zone) are non-encrypted columns +
two usermeta dates. Cap work per run if the client count is large (paginate across nights if
needed), mirroring the retention prune's bounded-pass discipline.

### Step 3 — Settings: per-field auto-correct toggles (default OFF)
A small settings option (e.g. `mealsdb_derived_autocorrect` = `['next_delivery_date'=>0,
'next_order_date'=>0,'delivery_day'=>0]`). Surface on an existing settings surface or the Event
Log/admin area. Default all OFF (flag-only). `delivery_day` auto-correct should be discouraged in
the UI copy (overrides are legitimate).

---

## TESTS (`tests/test-derived-value-check.php`)

- **T-1 in-sync client → no mismatch:** a client whose stored `next_delivery_date` equals the
  computed value → `check_client` returns empty.
- **T-2 input drift detected:** stored `next_delivery_date` reflects weekly, but
  `delivery_frequency` is now biweekly → mismatch reported (stored vs expected), reason noted.
- **T-3 direct-edit drift detected:** stored `next_order_date` hand-set to an arbitrary date that
  the calculator wouldn't produce → mismatch.
- **T-4 can't-compute is skipped:** a client with no frequency / blank delivery_day / no
  last_delivery_date → NO mismatch (null expected, skipped — not noise).
- **T-5 flag-only writes nothing:** with auto-correct OFF, the audit pass emits a `degraded` trunk
  event and does NOT modify the client row.
- **T-6 auto-correct on:** with the per-field toggle ON, the pass writes the computed value AND
  emits a `derived_value_corrected` audit row (old→new); a second run is a no-op (idempotent).
- **T-7 reuses the canonical calculator:** the expected value matches what
  `MealsDB_Date_Calculator::next_date` returns for the same inputs (guards against a divergent
  reimplementation — the test computes both and asserts equality).
- **T-8 delivery_day drift is flag-only even if a global autocorrect flag is on:** confirm
  `delivery_day` honors its own (off) toggle and is never auto-stomped by a blanket setting.

Run new test + FULL suite (expect 72 + this; mbstring/gd for PDF tests).

---

## ACCEPTANCE CRITERIA

1. `MealsDB_Derived_Value_Check::check_client()` detects drift for `next_order_date`,
   `next_delivery_date`, `delivery_day` by recomputing via the CANONICAL calculator (no
   reimplemented snapping logic); "can't compute" is skipped, not flagged.
2. A nightly audit job flags every mismatch as a `degraded` STR-LOG trunk event; default behavior
   is flag-only (no writes).
3. Per-field auto-correct toggles exist, default OFF; when ON, corrections write via the canonical
   path AND are audit-logged (old→new); the pass is idempotent.
4. `service_name_zone`, `delivery_initials`, termination dates are OUT of scope (operator-entered,
   not derived).
5. Re-run safe; respects the nightly overlap guard; bounded work per run.
6. New test green; full suite green.

---

## OUT OF SCOPE / FORWARD

- **Item 2 (postal → zone → delivery_day cascade)** builds ON this: it adds a `postal → zone`
  derivation link, and `delivery_day`'s checker here is where that cascade's integrity would
  eventually be verified. Not built here.
- **Incomplete-profile reporting** (clients missing frequency/day entirely) — a different concern
  from drift; explicitly skipped here (can't-compute ≠ mismatch). Could be a separate "profile
  completeness" report later.
- **Auto-recompute on client-form save** (closing drift vector #1 at the source, so the nightly
  check has less to catch) — a reasonable COMPLEMENTARY fix (call `Client_Dates` recompute when
  frequency/day changes in the form). Noted as an option; this directive is the safety NET
  (catches drift however it arises, including direct edits), which is valuable regardless of
  whether the form also recomputes. Consider both.
