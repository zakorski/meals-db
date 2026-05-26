# Task: Fix frequency interpretation (weeks) in allocation engine + Quick Order

Apply `frequency-weeks-fix.patch` to the **meals-db** plugin checkout.

## Ordering (fifth in the series)

1. import-removal.patch
2. reorg-menus.patch
3. task-dates-delivery.patch   (introduced MealsDB_Date_Calculator as weeks)
4. frequency-weeks-fix.patch   ← this one

Requires the date calculator from patch 3. `git apply --check` confirms fit.

## Why

ordering_frequency / delivery_frequency are a WEEK multiplier (1 = weekly,
2 = biweekly, ...). The date calculator already treats them correctly, but
two older consumers still treated the value as a raw DAY count. This corrects
them to weeks (interval = frequency * 7 days).

## Changes (3 files)

**class-allocation-engine.php** — coverage-period math, 4 spots:
- coverage_end = delivery + (frequency*7 - 1) days  (a weekly delivery covers
  7 days: the delivery day through the day before the next)
- cursor step = + (frequency*7) days
- skip-ahead jump uses period = frequency*7 days (both the floor-divide and
  the jump)
This changes allocation/billing coverage output — review accordingly.

**class-quick-order-ajax.php**
- `persist_next_dates` and `get_next_dates` now compute via
  MealsDB_Date_Calculator (weeks + snap to delivery day) instead of raw days;
  both now read delivery_day.
- The QO next-order/next-delivery fields are meant to AUTO-FILL with the
  computed "after this order" dates; the operator may edit them and whatever
  is submitted is persisted (supports one-off frequency changes — e.g. a
  client temporarily on weekly delivery — that the system resumes from on the
  next order).
- Removed the duplicate `last_order_date` usermeta write (was Y-m-d H:i:s).
  last_order_date is now written once, as Y-m-d, by the allocation hook
  (MealsDB_Client_Dates::advance_on_order) — the format the reader expects.
  QO still writes last_call_date.

**assets/js/quick-order.js**
- The next-date fields now prefill with the computed post-order date
  (rule_default_*) first, falling back to the stored value — so the operator
  sees what the dates WILL become after submitting.

## Steps

```bash
git checkout -b frequency-weeks-fix
git apply --check frequency-weeks-fix.patch
git apply frequency-weeks-fix.patch
php -l includes/services/class-allocation-engine.php
php -l includes/class-quick-order-ajax.php
node -e "new Function(require('fs').readFileSync('assets/js/quick-order.js','utf8'))" && echo "JS ok"

clean=0; total=0; fails=""
for t in tests/test-*.php; do
  total=$((total+1)); out=$(php "$t" 2>&1)
  if echo "$out" | grep -qE "\[FAIL\]|[1-9][0-9]* failed|PHP Fatal|Uncaught|Parse error|not found"; then
    fails="$fails $(basename $t)"; else clean=$((clean+1)); fi
done
echo "RESULT: $clean / $total clean${fails:+ -- FAILS:$fails}"
```

Expected: **54 / 54 clean**.

## Staging validation

1. Allocation: pick a biweekly client (delivery_frequency = 2), run an
   allocation for a month, confirm coverage windows are 14 days and deliveries
   step every 14 days — not 2.
2. Quick Order: open QO for a client. The next-order/next-delivery fields
   should auto-fill with today + frequency (weeks), snapped to delivery day.
3. Edit a next date in QO, submit, confirm the edited value is what's stored.
   Place the client's next order later and confirm it resumes normal cadence.
4. Confirm last_order_date usermeta is written as Y-m-d after a QO order.

Report back: lint, `RESULT: X / Y`, staging results.
