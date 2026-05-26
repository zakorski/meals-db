# Task: Maintain order/delivery dates; add the Client Delivery task

Apply `task-dates-delivery.patch` to the **meals-db** plugin checkout.

## Ordering (fourth in the series)

1. import-removal.patch
2. reorg-menus.patch
3. task-dates-delivery.patch  ← this one

`git apply --check` will fail if the prior two aren't applied. All against
repo version 1.0.360.

## Why this exists

The task system was built and working, but no tasks ever appeared. Root
cause: the "clients due to reorder" spawn strategy and its seeded weekly rule
ALREADY existed and query `next_order_date` — but `next_order_date` was never
maintained, and the one function that computed it had a bug: it treated the
ordering/delivery frequency (a WEEK multiplier: 1=weekly, 2=biweekly, ...) as
a raw DAY count, so a weekly client got "next order = tomorrow" instead of
"+7 days". With wrong/stale dates, the strategy matched nobody.

## What this changes

**Canonical date calculator** — new `MealsDB_Date_Calculator::next_date($last,
$frequency, $delivery_day)`. One implementation used everywhere:
- frequency is interpreted as weeks (interval = frequency * 7 days) — fixes
  the bug above;
- the result is snapped to the client's delivery day within the projected
  date's **Sunday–Saturday** week (e.g. a weekly Thursday client who got a
  Friday delivery snaps back to that week's Thursday, not forward a week).

**Order-placement maintenance** — new `MealsDB_Client_Dates::advance_on_order`,
called from the allocation lifecycle hook (`on_order_created`) so EVERY order
(not just Quick Order) sets `last_order_date` (usermeta) and recomputes
`next_order_date`. Uses the order's own date so back-dated orders anchor
correctly. (Previously only Quick Order maintained last_order_date — the same
QO-only gap the fee applier had.)

**Backfill rerouted** — the consolidated engine's next-dates phase now uses
the calculator (so the backfill and live updates agree, and the backfill is
no longer affected by the week/day bug).

**Delivery side** — a delivery is recorded by an explicit action, since
WooCommerce has no "delivery happened" event:
- new `last_delivery_date` DATE column on meals_clients;
- new `client_delivery` task type (warehouse role) whose only completion
  action is **Mark as Delivered** — it advances `last_delivery_date` and
  recomputes `next_delivery_date` via the calculator
  (`MealsDB_Client_Dates::mark_delivered`);
- new `clients_due_for_delivery` spawn strategy (queries `next_delivery_date`
  within a window);
- a seeded **Weekly Delivery List** rule (spawns weekly, Sunday 06:00, 7-day
  window).

## Choices baked in (change if you disagree)

- Delivery rule spawns **weekly on Sunday**, 7-day window. The phone-call
  rule spawns Wednesdays; Sunday was chosen so the delivery list is ready for
  the week. Adjust in `includes/install-schema.php` (the seed) if wanted.
- `client_delivery` task is assigned to the **warehouse** role.

## IMPORTANT — existing data

This fixes the date *logic*; it does NOT retroactively fix dates the old
buggy backfill already wrote. After applying, re-run the next-dates backfill
(Data Ops → Backfill Next Dates, or the consolidated migration) so existing
`next_order_date` / `next_delivery_date` values are recomputed correctly.
Until then, the "due this week" lists may be wrong for already-processed
clients.

Also: the seeded Weekly Delivery List rule is created by the schema/seed
installer. On an existing install, confirm the seed runs (or create the rule
via Tasks → Rules) — applying a code patch does not itself insert seed rows.

## Steps

```bash
git checkout -b task-dates-delivery
git apply --check task-dates-delivery.patch
git apply task-dates-delivery.patch
git add -A && git status --short

# Lint
for f in includes/services/class-date-calculator.php \
         includes/services/class-client-dates.php \
         includes/task-types/class-task-type-client-delivery.php \
         includes/class-allocation-hooks.php \
         includes/services/class-migration-consolidated.php \
         includes/install-schema.php meals-db-main.php; do
  php -l "$f" || echo "LINT FAILED: $f"
done

# New tests
php tests/test-date-calculator.php   # Ran 8 checks: 8 passed
php tests/test-client-dates.php      # Ran 8 checks: 8 passed

# Full suite
clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"
  else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

Expected: **54 / 54 clean**.

## Staging validation

1. Run the schema update (Data Ops → Update Database Schema) so the new
   `last_delivery_date` column is added.
2. Re-run Backfill Next Dates so existing clients get corrected dates.
3. Place an order (normal WooCommerce screen, not Quick Order) for a client
   with an ordering_frequency set → confirm their `next_order_date` advances
   by frequency×7 days, snapped to their delivery day.
4. Tasks: confirm a Client Delivery task appears for clients whose
   next_delivery_date is within the week; complete one with Mark as Delivered
   and confirm last_delivery_date / next_delivery_date advance.
5. Confirm the Weekly Phone Call List now produces call tasks for clients due
   to reorder (it was empty before only because next_order_date was stale).

Report back: `git status`, lint, `RESULT: X / Y`.
