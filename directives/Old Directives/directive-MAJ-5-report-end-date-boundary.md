# Directive MAJ-5 — Report end-date boundary drops last-day orders

**Status:** ready to implement (code-only, contained)
**Severity:** MAJOR — confirmed correctness bug. Reports silently undercount the final day of any
range; on a government billing reconciliation that means under-reported totals.
**Verified at:** v1.0.406, `includes/services/class-reports.php`.

---

## THE BUG (confirmed in code)

`normalise_dates()` (line ~1405) formats the end bound as midnight:

```php
'end' => gmdate('Y-m-d H:i:s', $end),   // '2026-01-31' -> '2026-01-31 00:00:00'
```

The report queries then filter `o.date_created_gmt` against that bound. There are **two
conventions in the same file**, and BOTH drop the last day:

- **`<= %s` (inclusive operator, 4 sites):** lines ~116, ~178, ~982, ~1218. With end =
  `2026-01-31 00:00:00`, an order at `2026-01-31 09:00:00` is `> end` → **excluded**. Only orders
  at exactly midnight of the 31st survive.
- **`< %s` (exclusive operator, 3 sites):** lines ~337, ~441, ~496. With the same midnight end,
  everything `>= 2026-01-31 00:00:00` is excluded → the entire 31st is dropped.

Either way, "report through Jan 31" loses Jan 31's orders. (Contrast: `MealsDB_WC_Order_Query::
get_orders_for_users` does this correctly — it advances end by +1 day for a `<` compare,
DST-safe via gmdate. That path is the CORRECT MODEL to mirror; do not change it.)

---

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>
# Confirm the two conventions and their line numbers haven't moved.
grep -n "date_created_gmt >= %s\|date_created_gmt <= %s\|date_created_gmt < %s" includes/services/class-reports.php
grep -n "function normalise_dates" includes/services/class-reports.php
# Read the CORRECT model so the fix matches its DST-safe approach:
grep -n "end_date_exclusive\|+1 day UTC" includes/services/class-wc-order-query.php   # ~line 86
# STOP if normalise_dates already pushes end-of-day (someone may have fixed it) — re-scope.
```

---

## THE FIX — make the bound consistent, then make every query use it consistently

The cleanest fix is the one the WC_Order_Query path already uses: **a half-open interval with an
exclusive next-day end.** That requires every report query to use `< end_exclusive`, and
`normalise_dates` to return that exclusive bound. Mixed `<=`/`<` is the trap — standardize.

### Step 1 — `normalise_dates` returns a half-open `[start, end_exclusive)`

```php
private function normalise_dates($start_date, $end_date): ?array {
    $start = is_int($start_date) ? $start_date : strtotime((string) $start_date);
    $end   = is_int($end_date)   ? $end_date   : strtotime((string) $end_date);
    if ($start === false || $end === false) {
        return null;
    }
    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }
    // Half-open interval: start of the start day (inclusive) to start of the
    // day AFTER the end day (exclusive). gmdate/UTC so the boundary does not
    // drift across DST (mirrors MealsDB_WC_Order_Query::get_orders_for_users).
    return [
        'start'         => gmdate('Y-m-d 00:00:00', $start),
        'end_exclusive' => gmdate('Y-m-d 00:00:00', strtotime('+1 day', $end)),
    ];
}
```

**Note the key name CHANGES** from `end` to `end_exclusive`. This is deliberate — it forces every
call site to be revisited (a silent value-semantics change under the same key name would be the
real hazard). Every consumer of `$dates['end']` must be updated in Step 2; a leftover
`$dates['end']` will throw an undefined-index notice in tests, which is the desired tripwire.

### Step 2 — every report query uses `>= start AND < end_exclusive`

Convert ALL seven query sites to the half-open form:

- The 4 `<= %s` sites (116, 178, 982, 1218): change operator to `< %s` and bind `end_exclusive`.
- The 3 `< %s` sites (337, 441, 496): keep operator, bind `end_exclusive` (they were already `<`
  but binding the OLD midnight `end` — they need the new exclusive bound to stop dropping the
  last day).

Mechanically, at each site:
- SQL: ensure the clause reads `o.date_created_gmt >= %s AND o.date_created_gmt < %s`
  (note: some sites write the start/end on separate lines — preserve formatting, just fix the
  operator + the bound variable).
- Binding: replace `$dates['end']` with `$dates['end_exclusive']` and confirm `$dates['start']`
  is the first bound.

Grep after editing to prove no `<= %s` against `date_created_gmt` and no `$dates['end']` remain:
```bash
grep -n "date_created_gmt <= %s" includes/services/class-reports.php   # expect: none
grep -n "\$dates\['end'\]" includes/services/class-reports.php          # expect: none (only end_exclusive)
```

### Step 3 — check for OTHER consumers of normalise_dates outside this file
```bash
grep -rn "normalise_dates\|->normalise_dates" includes/ --include=*.php
```
If `normalise_dates` is private to Reports (it is, `private function`), no external consumers —
good. If any sibling copies the same pattern, note it but do NOT fix it under this directive
(scope creep); record it as a follow-up.

---

## TESTS (`tests/test-reports-date-boundary.php`, or extend an existing reports test)

Use the in-memory $wpdb stub pattern. The key assertions are about the BOUND, not live data:

- **T-1 last-day inclusion:** for a range ending `2026-01-31`, capture the prepared SQL + bound
  params and assert the end bound passed is `2026-02-01 00:00:00` (exclusive next-day), and the
  operator against `date_created_gmt` is `<` (not `<=`). This proves an order at
  `2026-01-31 23:59:59` would match.
- **T-2 start inclusive:** start bound is `<start-day> 00:00:00` and operator `>=`.
- **T-3 all query sites consistent:** exercise each report method that calls `normalise_dates`
  (resupply, the others at 178/337/441/496/982/1218) and assert NONE bind a midnight `end` or use
  `<=` against `date_created_gmt`. (Drive each method with the stub; scan the captured SQL.)
- **T-4 reversed range still normalizes:** end < start input still yields start <= end_exclusive.
- **T-5 malformed date:** unparseable end → `normalise_dates` returns null and the caller handles
  it as today (no fatal).
- **T-6 single-day range:** start == end == `2026-01-15` → `[2026-01-15 00:00:00,
  2026-01-16 00:00:00)` → a full single day, non-empty (mirrors the WC_Order_Query single-date
  behavior).

Run the new test + the FULL suite. The reports tests are the regression-sensitive ones —
confirm they still pass (the bound change shifts which orders match, so any fixture asserting a
specific last-day count may need its expectation corrected to the NOW-correct value; if a test
breaks because it was encoding the buggy behavior, fix the test's expectation, and note it).

---

## ACCEPTANCE CRITERIA

1. `normalise_dates` returns `start` (inclusive, start-of-day) and `end_exclusive`
   (exclusive, start of day-after-end), gmdate/UTC, DST-safe.
2. All seven report query sites use `>= start AND < end_exclusive` against `date_created_gmt`;
   no `<= %s` and no `$dates['end']` remain.
3. An order on the final day of a range is now INCLUDED (T-1).
4. New test green; full suite green; any fixture that encoded the old last-day-dropping behavior
   has its expectation corrected (and the correction noted).

---

## OUT OF SCOPE

- The `MealsDB_WC_Order_Query` path — already correct; do not touch.
- MAJ-6 (slips by creation vs delivery date) — a different bug (wrong COLUMN, not boundary);
  separate directive, blocked on an operator question.
- Timezone semantics of `date_created_gmt` vs site-local reporting — out of scope; this directive
  preserves the existing UTC-bound approach, only fixing the day-boundary inclusion.
