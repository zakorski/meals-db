# Directive MAJ-6 — Delivery slips select orders by CREATION date, not delivery date

**Status:** ready to implement. Operator confirmed the model: delivery day comes from the
client's `delivery_day` field (maintained from the editable zone→day schedule page); delivery
DATE is that day + the client's frequency. Use the stored `delivery_day` (B-stored) — no live
zone resolution, no override hybrid.
**Severity:** MAJOR — confirmed correctness bug with physical-world impact: orders placed ahead of
their delivery day land on the WRONG day's packer/driver slips.
**Verified at:** v1.0.412.

---

## OPERATOR ANSWER (resolves the prior A/B/C gate → Option B)

> "Each client has a delivery date based on their ZONE location in their profile, plus a delivery
> FREQUENCY in the profile. That's what determines their dates."

So an order belongs to the client's **next scheduled delivery occurrence**, computed from
(zone → delivery day) + (frequency) — NOT the order's creation date, NOT a per-order choice. This
is Option B. The data + tools already exist:

- `mealsdb_zone_delivery_schedule` (settings option): the **zone → delivery-day** mapping. NOTE it
  keys on **`delivery_area_name`** (the backfill matches `WHERE delivery_area_name = %s`), not
  `delivery_area_zone` — use the SAME key or it matches nothing.
- `clients.delivery_day`: the materialized weekday, blank-filled FROM the zone schedule by
  `run_phase_delivery_day` / the `backfill_delivery_day` AJAX (blank-fill only — never overwrites
  an existing value).
- `clients.delivery_frequency` + `clients.next_delivery_date` (meta): the cycle.
- `MealsDB_Date_Calculator::next_date($last_date, $frequency, $delivery_day)`: pure function —
  project forward by `frequency` weeks, snap to the delivery weekday. Already tested.

---

## THE BUG (unchanged, confirmed)

Slip pipeline filters `o.date_created_gmt` (creation) while the caller passes a delivery date:
`get_orders_for_date($ids, $delivery_date)` → `get_orders_for_users(...)` →
`WHERE o.date_created_gmt >= %s AND < %s` (~76–77). Order-ahead (operator-confirmed) → order on
the wrong day's slip. It's a wrong-COLUMN bug; the boundary handling is correct.

---

## DELIVERY-DAY SOURCE (settled — B-stored)

The delivery day is read from the client's **`delivery_day`** field. That field is maintained from
the **editable zone→day schedule page** (the front end for the `mealsdb_zone_delivery_schedule`
option — `class-ajax-settings.php` writes it; the slip AJAX reads it; `backfill_delivery_day`
stamps it onto clients). Operator confirms zones change rarely, so the stored `delivery_day` does
not meaningfully drift — there is NO need to resolve zone→day live at slip time and NO override
hybrid. Read `delivery_day` directly. (If a client's zone is ever changed on the page, re-running
the existing backfill — or the future nightly derived-value check — refreshes `delivery_day`; that
is out of scope here.)

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>
# The slip query path (the filter to change).
grep -n "get_orders_for_date\|get_orders_for_users\|date_created_gmt" includes/services/class-delivery-slip-generator.php includes/services/class-wc-order-query.php
# (Reference only — the slip fix reads client.delivery_day, not the schedule live. But note the
#  schedule page keys on delivery_area_name, relevant to the backfill that populates delivery_day.)
grep -n "mealsdb_zone_delivery_schedule\|delivery_area_name\|\['day'\]" includes/ajax/class-ajax-delivery-slips.php
# The date tool.
grep -n "function next_date\|function day_offset" includes/services/class-date-calculator.php
# Confirm which clients a slip date currently selects (the correct half — weekday match).
grep -n "function get_clients_for_delivery_date\|delivery_day" includes/services/class-delivery-slip-generator.php | head
# STOP if get_orders_for_date no longer filters date_created_gmt (someone may have started this).
```

---

## THE FIX

### Step 1 — A NEW delivery-basis order query (do not repurpose the creation-date one)
`MealsDB_WC_Order_Query::get_orders_for_users` filters by `date_created_gmt` and is used CORRECTLY
by reports/reconciliation (revenue is booked by creation date — MAJ-5 fixed its boundary, its
BASIS is intentional). Do NOT change it. Add a new method for the delivery basis.

The delivery basis maps each order to the client's delivery occurrence. With Option B, an order
created on date C belongs to the client's next delivery occurrence on/after C, computed from the
client's (delivery_day, frequency) via `Date_Calculator`. The slip for date D should include orders
whose computed occurrence == D.

Two implementation strategies — pick per how cleanly the data supports it:

**B1 — compute-and-filter (no schema change):** for the clients delivered on D (already selected
correctly by `get_clients_for_delivery_date`), fetch their candidate orders, and for each order
compute its intended delivery occurrence (`Date_Calculator::next_date` seeded from the order's
creation date + the client's frequency + the resolved delivery day) and keep those == D. Reuses
existing tools, no migration. The "which occurrence" rule must be stated precisely (see the
cutoff note) so edge orders bucket correctly.

**B2 — capture-on-order (schema/meta + backfill):** stamp an intended delivery date on each order
at creation (the model the `next_delivery_date` machinery already implies), filter slips on it,
backfill existing orders by the same computation. Cleaner long-term, larger surface (touches order
creation). 

RECOMMENDATION: **B1** for the fix (contained, reuses `Date_Calculator`, no checkout change), and
note B2 as the eventual hardening if order-time capture is later wanted. Resolve the delivery DAY
by reading the client's stored `delivery_day` field directly (B-stored, per the settled note).

### Step 2 — the occurrence/cutoff rule (state it explicitly)
The one ambiguity in B1: an order placed the MORNING OF a delivery day — this occurrence or next?
Default rule (confirm against operator's batching): an order created at/before the delivery day's
cutoff is for THAT occurrence; after, it rolls to the next. Simplest defensible default: order
created on or before delivery date D (date-only, `created_date <= D`) for a client delivered on D
belongs to D; later creations roll forward. Encode this as ONE documented helper
(`delivery_occurrence_for_order($order_created, $client)`) so the rule lives in one place and the
test pins it.

### Step 3 — wire it into the slip generator
`get_orders_for_date($ids, $delivery_date)` calls the NEW delivery-basis path instead of the
creation-date `get_orders_for_users`. `get_clients_for_delivery_date` (the weekday client
selection) is unchanged — it's the correct half.

---

## TESTS (`tests/test-slips-delivery-date.php`)

- **T-1 HEADLINE (the bug):** order created day C (e.g. Monday) for a client delivered day D≠C
  (Thursday) → appears on D's slip, NOT on C's. This scenario IS the bug; it's the headline test.
- **T-2 same-day still correct:** order created on the delivery day → still on that day's slip.
- **T-3 frequency respected:** a biweekly client's order maps to the correct fortnightly
  occurrence, not every weekly delivery day.
- **T-4 reads stored delivery_day:** the occurrence is computed from the client's stored
  `delivery_day` field (not re-resolved from zone); a blank `delivery_day` is handled gracefully
  (no fatal — order falls out / is flagged, per the helper's null-day handling).
- **T-6 occurrence cutoff:** an order created the morning of / day after the delivery day buckets
  per the Step-2 rule (pin the boundary).
- **T-7 creation-date query untouched:** `get_orders_for_users` still filters date_created_gmt
  (reports path unaffected) — a guard test so the refactor doesn't repurpose it.

Run new test + FULL suite (expect 71 + this; mbstring/gd for PDF slip tests).

---

## ACCEPTANCE CRITERIA

1. A NEW delivery-basis order path drives slips; `get_orders_for_users` (creation date) is
   UNCHANGED and still used by reports/reconciliation.
2. Delivery day read from the client's stored `delivery_day` field; delivery DATE computed via
   `Date_Calculator` from `delivery_day` + frequency.
3. Occurrence/cutoff rule lives in one documented helper; the boundary is tested.
4. HEADLINE test: order created day C for delivery day D≠C → on D's slip, not C's (T-1); same-day
   still correct (T-2).
5. Full suite green.

---

## OUT OF SCOPE

- `get_clients_for_delivery_date` (weekday client selection) — correct, unchanged.
- The creation-date reporting basis — intentional, unchanged (MAJ-5 owns its boundary).
- Redesigning the zone schedule or frequency model — the fix routes orders onto the EXISTING
  schedule, doesn't redesign it.
- B2 (order-time delivery-date capture) — noted as eventual hardening, not built here unless the
  operator wants order-time capture now.
