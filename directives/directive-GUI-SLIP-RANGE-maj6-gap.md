# Directive GUI-SLIP-RANGE — "By Zone + Date Range" slips bypass the MAJ-6 delivery-occurrence filter

**Status:** ready to implement. Confirmed in code + in a generated artifact.
**Severity:** MAJOR — a slip generated for a given date (via the zone/date-range mode) includes
orders that do NOT belong to that delivery date. A packer working the slip stages the wrong food
for the wrong day. This is the SAME class of bug MAJ-6 fixed — MAJ-6 fixed it for the single-date
path but NOT the date-range path.
**Verified at:** v1.0.422. Surfaced by content-inspecting a slip PDF the Phase-1 GUI agent had
marked PASS on generation: a slip named `packer-slips-2025-12-03.pdf` contained 116 orders whose
printed delivery dates spanned SEVEN dates across Nov–Dec 2025 (61× Dec 1, 40× Dec 3, plus
Nov 6/15/20/27/28) — only 40 of 116 actually belong to Dec 3.

---

## ROOT CAUSE (confirmed in code)

Two slip paths exist; only one got the MAJ-6 treatment:

- **Single-date path (CORRECT):** `generate_packer_slips_for_date($date)` /
  `generate_driver_slips_for_date($date)` → `get_orders_for_delivery_date($clients, $date)` →
  filters each order with `self::delivery_occurrence_for_order($created, $client)` and keeps only
  those whose computed occurrence `=== $date` (class-delivery-slip-generator.php ~271–315). This is
  the MAJ-6 fix and it works.
- **Date-range / by-zone path (BUGGY):** `generate_packer_slips_by_zones(...)` /
  `generate_driver_slips_by_zones(...)` → `get_orders_for_range($wp_user_ids, $start, $end)`
  (line ~388) → `get_orders_with_items_for_users($ids, $start, $end)` — which filters on
  `date_created_gmt BETWEEN start AND end` with **NO `delivery_occurrence_for_order` filter at
  all.** So it returns every order CREATED in the range, regardless of delivery date. That is
  exactly the pre-MAJ-6 bug, still live on this path.

The agent used "By Zone + Date Range" for Dec 3, so the slip pulled orders created across a window
and printed each order's own (scattered) delivery date → the Nov+Dec mix.

---

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>
# The two paths + the occurrence filter.
grep -n "function generate_packer_slips_for_date\|function generate_packer_slips_by_zones\|function get_orders_for_delivery_date\|function get_orders_for_range\|delivery_occurrence_for_order" includes/services/class-delivery-slip-generator.php
# Confirm get_orders_for_range still calls the creation-date query with no occurrence filter (~388).
sed -n '388,400p' includes/services/class-delivery-slip-generator.php
# The AJAX handler mapping mode → generator fn.
grep -n "generate_packer_slips_by_zones\|generate_driver_slips_by_zones\|generate_packer_slips_for_date\|by_zone\|range" includes/ajax/class-ajax-delivery-slips.php
# STOP if get_orders_for_range already applies delivery_occurrence — then this is already fixed.
```

---

## THE FIX — apply the SAME occurrence filter to the range path

The MAJ-6 logic already exists and is correct; the fix is to route the date-range/zone path through
it instead of the raw creation-date range query. Two layers:

### Decide the intended semantics of "date range" first (small but real)
The single-date path answers "orders DELIVERED on date D." The range path should answer "orders
DELIVERED within [start, end]" — NOT "orders CREATED within [start, end]." Confirm that's the
intent (it almost certainly is: a packer slip is about what ships, and the UI labels it a delivery
selection). Given that:

### Implementation
- Make the by-zone/range path select candidate orders, then **filter by delivery occurrence the
  same way the single-date path does** — keep an order only if
  `delivery_occurrence_for_order($created, $client)` falls within `[start, end]` (inclusive),
  rather than keeping it because its `date_created_gmt` falls in the range.
- The cleanest structure: factor the occurrence test the single-date path uses
  (`get_orders_for_delivery_date`) into a form that accepts a date RANGE (occurrence ∈ [start,end])
  and have BOTH paths share it — single-date is just the range `[D, D]`. This guarantees the two
  paths can't drift again (the bug we're fixing exists precisely because they diverged).
- Keep the candidate-fetch window wide enough that order-ahead orders aren't missed: the single-date
  path already widens the creation-date pre-filter by `max_freq * 7` days before applying the
  occurrence test (line ~294) — the range path must do the same widening relative to `start`/`end`,
  or it will miss orders created well before their in-range delivery.

**Do NOT** simply swap `date_created_gmt` for a delivery-date column — there is no per-order
delivery-date column (that's why MAJ-6 computes occurrence from creation + client schedule). The
fix is to apply the SAME computed-occurrence filter, not to invent a column.

**Leave `get_orders_for_range` / `get_orders_with_items_for_users` available for any genuine
creation-date consumer** (reports/reconciliation legitimately select by creation date). Add the
occurrence-filtered behavior as the slip path's selection, the same way MAJ-6 added a new
delivery-basis path without disturbing the creation-date query other callers rely on.

---

## TESTS (`tests/test-slip-range-occurrence.php`, or extend the existing slip test)

- **T-1 HEADLINE (the bug):** seed orders whose CREATION dates fall in [Dec 1, Dec 3] but whose
  computed delivery occurrences are various dates (some in-range, some Nov). Generate a by-zone
  range slip for [Dec 1, Dec 3]. PASS only if the slip contains ONLY orders whose delivery
  occurrence is within [Dec 1, Dec 3] — no November-occurrence orders. (This reproduces the 116-vs-40
  artifact and asserts it's fixed.)
- **T-2 single-date unchanged:** the single-date path still returns exactly the occurrence-==-D
  orders (no regression to the working path).
- **T-3 shared logic:** single-date `[D,D]` and range both run through the same occurrence filter
  (guards against future divergence — assert identical results for a one-day range vs the
  single-date call).
- **T-4 order-ahead within range:** an order created well before `start` but DELIVERED within
  [start,end] IS included (the widening window works) — the order-ahead case, at range scope.
- **T-5 creation-date query intact:** `get_orders_with_items_for_users` (creation basis) is
  unchanged for its report/reconciliation callers.

Run new test + FULL suite. Then this is one of the cases the **GUI re-test** will content-verify:
a regenerated Dec-3 range slip must contain only Dec-3 delivery occurrences.

---

## ACCEPTANCE CRITERIA

1. The by-zone/date-range slip path filters by computed delivery OCCURRENCE within [start,end], not
   by `date_created_gmt`.
2. Single-date and range paths share one occurrence-filter implementation (no divergence).
3. The candidate window is widened (like the single-date path) so in-range deliveries created
   earlier aren't missed.
4. Creation-date query preserved for report/reconciliation callers.
5. T-1 (no out-of-range delivery occurrences on the slip) green; full suite green.
6. GUI re-test: a regenerated date-range slip content-checks to only in-range delivery dates.

---

## NOTES

- This is MAJ-6 **incomplete**, not regressed: MAJ-6 correctly fixed `get_orders_for_delivery_date`
  (single date) but `get_orders_for_range` (zone/range) kept the original creation-date selection.
  The lasting fix is to make both share the occurrence filter so a future edit can't re-split them.
- Found only by content-inspecting the generated PDF — the GUI agent saw "PDF generated, 200 OK"
  and marked PASS. Reinforces: artifacts whose correctness lives in a binary download need
  content verification, not just generation success.
