# Directive BC-4: Contribution reconciliation reports false discrepancies

**Audit reference:** 2026-06 review, reports subsystem (`class-reports.php::contribution_reconciliation`).
**Severity:** MEDIUM — a `manage_options` financial checker that produces wrong "underpaid/overpaid" numbers; false positives defeat the purpose of the tool and can trigger wrong collection action.
**Scope:** ~20–30 lines, 1 file (`includes/services/class-reports.php`); optional 1-line UI constraint in `views/fee-reconciliation.php`. **Risk:** LOW.

---

## Background — expected is flat, actual is range-summed

`contribution_reconciliation()` (lines ~742–805) computes, per client:

```php
$expected    = (float) $client['client_contribution'];               // ONE month's flat contribution
$actual_paid = $this->order_query->get_total_fee_paid_for_user(      // summed over the WHOLE date range
    $wp_user_id, 'contribution', $start_date, $end_date);
$difference  = round($expected - $actual_paid, 2);
```

The contribution is billed **once per billing month** (`MealsDB_Order_Fees`, confirmed by BC-2). But the UI date pickers (`views/fee-reconciliation.php`) are free `start_date`/`end_date`, and `class-ajax-reports.php` only validates `Y-m-d`. So:

- A **3-month** range → every client shows "overpaid" by ~2× their contribution (paid 3, expected 1).
- A **mid-month-to-mid-month** range → 0× or 2× depending on where the month boundaries fall.

The sibling `delivery_fee_reconciliation()` (just below) does this correctly — it scales expected by the order count in the range. This report has no scaling.

### Secondary bug — no `client_type` filter

The client query (lines ~752–757) selects every active client with `client_contribution > 0`:

```sql
WHERE client_contribution > 0 AND active = 1 AND wp_user_id > 0
```

But `MealsDB_Order_Fees` only applies the contribution to SDNB/Veteran clients. A **Private** client with a non-zero `client_contribution` column is included, shows `$0` paid, and is reported as permanently "underpaid" — a false discrepancy on every run.

---

## Pre-flight verification

**P1 — Confirm the flat-expected vs range-summed mismatch.**
```bash
sed -n '742,805p' includes/services/class-reports.php
```

**P2 — Confirm the sibling scales correctly (the pattern to copy).**
```bash
sed -n '807,880p' includes/services/class-reports.php   # delivery_fee_reconciliation
```
Expect `expected = num_orders * fee` or similar scaling.

**P3 — Confirm which client types are billed the contribution.**
```bash
grep -n "client_type\|SDNB\|Veteran\|Private\|contribution" includes/services/class-order-fees.php | head
```

**P4 — Decide the scaling basis.** Two valid choices:
- **(A) Constrain the UI to a single calendar month** (simplest, matches how the contribution is conceived). Then expected = 1 × contribution.
- **(B) Scale expected by the count of distinct billing months in the range** that the client was active. More flexible, more code.
Recommend **(A)** — the contribution is inherently monthly; a single-month reconciliation is what the operator actually wants.

---

## The fix (Option A — single-month, recommended)

### Step 1 — Filter to billed client types

```sql
SELECT client_id, wp_user_id, first_name, last_name, client_contribution, client_type
FROM `{$clients_table}`
WHERE client_contribution > 0 AND active = 1 AND wp_user_id > 0
  AND client_type IN ('SDNB', 'Veteran')      -- BC-4: only types the fee engine bills
```

(Use the exact `client_type` values confirmed in P3.)

### Step 2 — Reject multi-month ranges (or normalise to one month)

At the top of `contribution_reconciliation`, after the auth/guard checks:

```php
// BC-4: the client contribution is a per-billing-month charge. Reconciling it
// over a multi-month range compares one month's expected against several
// months' paid, which always reports a false discrepancy. Require the range to
// fall within a single calendar month.
$start_m = substr($start_date, 0, 7);
$end_m   = substr($end_date, 0, 7);
if ($start_m !== $end_m) {
    return [
        'rows'    => [],
        'summary' => self::empty_contribution_summary(),
        'error'   => __('Contribution reconciliation must be run for a single calendar month.', 'meals-db'),
    ];
}
```

Have the AJAX handler / view surface the `error` key (mirror however other reports surface a validation message).

### Step 3 — (UI) constrain the picker

In `views/fee-reconciliation.php`, for the contribution report default the range to first-of-month … last-of-month and/or add a note that it is single-month. Keep the server guard regardless (the server is the source of truth).

> **If the operator insists on multi-month (Option B):** instead of the single-month guard, compute `$months = ` count of distinct `YYYY-MM` between start and end (inclusive), and set `$expected = (float) $client['client_contribution'] * $months;`. Still apply Step 1's type filter. Note this assumes the client was active and billable every month in the range — document that assumption.

---

## Testing

`tests/test-contribution-reconciliation.php`:
1. **Single month, exact:** client billed = expected → difference 0.
2. **Multi-month range rejected** (Option A) → returns the error, empty rows.
3. **Private client excluded:** a Private client with `client_contribution > 0` does not appear.
4. **Veteran/SDNB included** and reconciled correctly.

**Manual:** run the report for the current month on staging; confirm clients who paid show difference ≈ 0, not 2×.

---

## Out of scope

- Do not change `get_total_fee_paid_for_user` (it correctly sums both fee mechanisms — that fix landed earlier).
- Do not touch `delivery_fee_reconciliation` (already scales correctly).

## Acceptance criteria

- [ ] Client query filters to billed client types (SDNB/Veteran).
- [ ] Multi-month ranges are rejected (Option A) or expected is scaled by month count (Option B) — pick one, document it.
- [ ] UI reflects the single-month constraint (Option A).
- [ ] Tests cover exact-month match, multi-month handling, and Private exclusion.
