# Delivery-Date "Next Week's Weekday" Rule + Backfill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An order's delivery date defaults to the client's delivery weekday in the calendar week *following* the order date (frequency ignored, no anchor/history); make slips AND billing-month attribution agree on that date; and backfill the ~14,352 existing orders so July invoicing and slips are correct.

**Architecture:** ITEM 1 replaces the derivation in one shared resolver (`delivery_occurrence_for_order`) via a new pure `MealsDB_Date_Calculator::next_week_delivery_date()`, and removes the stale `next_delivery_date` anchor from the QO prefill. ITEM 2 makes billing follow the same dates by **writing each order's `_delivery_date` meta** (which the rebuilder already honors as an authoritative override) via a repeatable migration phase, tagged with a `_delivery_date_src='auto'` marker so human-set dates are never clobbered, then rebuilding allocations. A ground-truth scorecard is the acceptance test.

**Tech Stack:** PHP 8.2, WooCommerce HPOS (`wc_orders`, `wc_orders_meta`), `$wpdb`, UTC `DateTimeImmutable`, plain-PHP tests (`php tests/<file>.php`), the existing consolidated-migration phase pipeline + `assets/js/admin-migration.js` driver.

---

## Decisions locked (from the operator, before coding)

- **The rule:** delivery = **Monday of the week following the order date, then advance to the client's delivery weekday** (Monday-based week). Frequency is NOT read. Validated at 96.4% (672/697) against Enzebra July ground truth; residuals are retroactive entry + one-off route shuffles.
- **Billing mechanism = write `_delivery_date` per order.** The rebuilder's `resolve_delivery_date()` already returns `[$override, $override]` when `_delivery_date` is set (`class-allocation-rebuilder.php:548-551`) — so writing the new-rule date into each order's `_delivery_date` makes billing month follow it, with `coverage_end = delivery_date`, reusing tested override + finalized-month semantics. The commence-anchored `calculate_delivery_schedule()` is left untouched (only runs for orders with no `_delivery_date`).
- **Repeatability = marker meta.** System writes tag `_delivery_date_src='auto'`. The backfill overwrites only orders that are auto-tagged OR have no `_delivery_date`; it **skips** any order with `_delivery_date` but no `auto` marker (= human-set, ~15 today). QO create tags its write `auto` when the submitted date equals the computed occurrence, `manual` when the operator changed it.
- **Blank means blank.** If neither `delivery_day` nor a zone-derived day is available, write NO delivery date; the field/prefill stays empty and requires manual entry. No today/order-date guess.
- **`delivery_day` canonical form** is a lowercase full weekday name (`'wednesday'`), mapped in `MealsDB_Date_Calculator::DAY_OFFSET` (`sunday=0..saturday=6`). Zones map to days via `MealsDB_Zone_Day::day_for_zone()` (exceptionless).

## Operator preconditions (ITEM 3 — not code; must precede the ITEM 2 backfill run)

1. Fill the 8 zone-derivable `delivery_day` gaps; decide a day-or-blank for the ~20 no-zone ordering clients; fix the two bogus zone values ("Zone 0", "A"). The backfill reads `delivery_day` (with zone fallback) — run cleanup first or those orders backfill to blank and you re-run.
2. Confirm **no billing month in scope is finalized** (`meals_client_allocations.is_finalized=1`). Pre-cutover this should be none. The backfill/rebuild will refuse to mutate a finalized month and will report any order whose corrected month differs while its current month is finalized (e.g. order 27454 June→July only completes if June is not finalized).
3. Have the ground-truth CSV ready (`order_number,delivery_date` from the July packer PDFs) for the Task 8 scorecard.

## File structure

- **Modify** `includes/services/class-date-calculator.php` — add pure `next_week_delivery_date()`; `DAY_OFFSET` already present.
- **Modify** `includes/services/class-delivery-slip-generator.php:513` — `delivery_occurrence_for_order()` delegates to the new method; frequency removed.
- **Modify** `includes/class-quick-order-ajax.php` — `resolve_delivery_prefill()` drops the `next_delivery_date` anchor; QO create tags `_delivery_date_src`.
- **Modify** `includes/services/class-migration-consolidated.php` — new phase `run_phase_delivery_dates` (write `_delivery_date`+marker, chunked per order, skip human, mark client-months dirty, report counts); register in `phases()`.
- **Create** `includes/services/class-delivery-date-scorecard.php` — ground-truth match-rate report (repeatable).
- **Create/Modify tests** — new `tests/test-next-week-delivery-rule.php`; update `tests/test-slips-delivery-date.php` and `tests/test-quick-order-next-dates-derivation.php` expectations to the new rule; new `tests/test-delivery-date-backfill.php`.

---

# ITEM 1 — The rule (Tasks 1–4)

### Task 1: `next_week_delivery_date()` in the date calculator

**Files:**
- Modify: `includes/services/class-date-calculator.php`
- Test: `tests/test-next-week-delivery-rule.php`

- [ ] **Step 1: Write the failing test** — create `tests/test-next-week-delivery-rule.php`:

```php
<?php
/**
 * DIRECTIVE delivery-date-next-week-rule ITEM 1: delivery defaults to the
 * client's delivery weekday in the calendar week FOLLOWING the order date.
 * Frequency is not used. Pure function of (order_date, delivery_day).
 *
 * Run: php tests/test-next-week-delivery-rule.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function nw_eq($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}
$f = ['MealsDB_Date_Calculator', 'next_week_delivery_date'];

// Wednesday client, order placed Monday 2026-08-03 -> NEXT week's Wednesday (08-12), not this week's (08-05).
nw_eq('Wed client, Mon order -> next-week Wed', '2026-08-12', $f('2026-08-03', 'wednesday'));
// Wednesday client, order placed Thursday 2026-08-06 -> next week's Wednesday.
nw_eq('Wed client, Thu order -> next-week Wed', '2026-08-12', $f('2026-08-06', 'wednesday'));
// Zone 4 Tuesday client, order placed Friday 2026-08-07 -> next week's Tuesday (08-11).
nw_eq('Tue client, Fri order -> next-week Tue', '2026-08-11', $f('2026-08-07', 'tuesday'));
// Case-insensitive day input.
nw_eq('case-insensitive day', '2026-08-12', $f('2026-08-03', 'Wednesday'));
// Sunday order (week-boundary edge): 2026-08-09 is a Sunday; following week's Monday is 08-10.
nw_eq('Sun order, Mon client -> 08-10', '2026-08-10', $f('2026-08-09', 'monday'));
nw_eq('Sun order, Sun client -> 08-16', '2026-08-16', $f('2026-08-09', 'sunday'));
// Blank / unknown day -> null (blank means blank).
nw_eq('blank day -> null', null, $f('2026-08-03', ''));
nw_eq('unknown day -> null', null, $f('2026-08-03', 'someday'));
nw_eq('null day -> null', null, $f('2026-08-03', null));
// Malformed date -> null.
nw_eq('bad date -> null', null, $f('2026/08/03', 'wednesday'));

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
```

- [ ] **Step 2: Run it, verify it fails**

Run: `php tests/test-next-week-delivery-rule.php`
Expected: FATAL — `Call to undefined method MealsDB_Date_Calculator::next_week_delivery_date()`.

- [ ] **Step 3: Implement the method** — add to `includes/services/class-date-calculator.php` (public static, near `snap_to_delivery_day`; do NOT modify `snap_to_delivery_day`/`next_date` — they have other callers):

```php
    /**
     * The client's delivery weekday in the calendar week FOLLOWING $date.
     *
     * DIRECTIVE delivery-date-next-week-rule: replaces the old
     * snap-within-week + roll-by-frequency derivation, which landed non-weekly
     * clients a fortnight/month out and (for early-week days like Tuesday)
     * almost always mis-fired. This is a pure function of (order date,
     * delivery weekday) — no frequency, no anchor, no history. Validated at
     * 96.4% against Enzebra July ground truth.
     *
     * Monday-based week: from $date, step to the following week's Monday
     * (PHP 'N': Mon=1..Sun=7, so days-to-next-Monday = 8 - N), then advance to
     * the delivery weekday measured from Monday.
     *
     * @param string      $date         Y-m-d (order date).
     * @param string|null $delivery_day Weekday name (any case) or null/blank.
     * @return string|null Y-m-d, or null when the date or weekday is invalid —
     *                     the null preserves the "blank means blank" contract.
     */
    public static function next_week_delivery_date(string $date, ?string $delivery_day): ?string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $offset_sun0 = self::day_offset($delivery_day); // Sun=0..Sat=6, null if unknown/blank.
        if ($offset_sun0 === null) {
            return null;
        }
        try {
            $base = new DateTimeImmutable($date, new DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
        // Monday of the FOLLOWING calendar week.
        $iso                 = (int) $base->format('N'); // Mon=1..Sun=7
        $days_to_next_monday = 8 - $iso;                 // Mon->7, Sun->1
        $monday_next         = $base->modify('+' . $days_to_next_monday . ' days');
        // Delivery weekday as an offset from Monday (Mon=0..Sun=6).
        $offset_mon0 = ($offset_sun0 + 6) % 7;           // Sun(0)->6, Mon(1)->0, Wed(3)->2 ...
        return $monday_next->modify('+' . $offset_mon0 . ' days')->format('Y-m-d');
    }
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `php tests/test-next-week-delivery-rule.php`
Expected: `OK (10 passed)`.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-date-calculator.php tests/test-next-week-delivery-rule.php
git commit -m "feat(dates): add next_week_delivery_date() — following-week delivery weekday (ITEM 1)"
```

---

### Task 2: `delivery_occurrence_for_order()` delegates; frequency removed

**Files:**
- Modify: `includes/services/class-delivery-slip-generator.php:513-539`
- Test: `tests/test-slips-delivery-date.php` (update expectations to the new rule)

- [ ] **Step 1: Replace the method body**

Replace `delivery_occurrence_for_order()` (lines 513-539) with:

```php
    public static function delivery_occurrence_for_order(string $order_created_date, array $client): ?string {
        $created = substr($order_created_date, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $created)) {
            return null;
        }
        $delivery_day = isset($client['delivery_day']) ? (string) $client['delivery_day'] : '';

        // DIRECTIVE delivery-date-next-week-rule: delivery defaults to the
        // client's delivery weekday in the calendar week FOLLOWING the order
        // date. Frequency is deliberately NOT read here — a parameter that no
        // longer affects the result is how the next reader reintroduces the old
        // snap-within-week + roll-by-frequency bug. Blank/unknown day -> null
        // (order falls out of every slip; "blank means blank").
        return MealsDB_Date_Calculator::next_week_delivery_date($created, $delivery_day);
    }
```

Also rewrite the method's doc-block (lines ~484-512) to describe the new rule; delete the stale "snap within week / roll one cycle / B1 fortnightly limitation" paragraphs and the `delivery_frequency optional` `@param` note — frequency is no longer read.

- [ ] **Step 2: Update the existing occurrence test to the new rule**

Read `tests/test-slips-delivery-date.php`. It calls `delivery_occurrence_for_order()` directly (helper `$occ` ~line 69) with OLD expectations (e.g. `T-1: Monday order 2026-05-25 for Thursday client => 2026-05-28`). **Re-derive every expected occurrence under the new rule** = `next_week_delivery_date(order_date, delivery_day)`. Worked example for T-1: `2026-05-25` is a Monday → following Monday `2026-06-01` → Thursday offset +3 → **`2026-06-04`**. Apply the same recomputation to each `$occ(...)` assertion, and to any assertion that depended on frequency rolling (those clients now get the same next-week date regardless of frequency). Update the human-readable labels to say "next week's <day>". Do NOT weaken assertions — recompute the correct value; a reviewer will independently re-derive two of them.

- [ ] **Step 3: Run the tests**

Run: `php tests/test-slips-delivery-date.php` and `php tests/test-next-week-delivery-rule.php`
Expected: both pass. Also `php -l includes/services/class-delivery-slip-generator.php` → clean.

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-delivery-slip-generator.php tests/test-slips-delivery-date.php
git commit -m "fix(slips): delivery occurrence = following-week weekday, drop frequency (ITEM 1)"
```

---

### Task 3: `resolve_delivery_prefill()` — drop the stale `next_delivery_date` anchor

**Files:**
- Modify: `includes/class-quick-order-ajax.php:1439-1465`
- Test: `tests/test-quick-order-next-dates-derivation.php` (update to new rule)

- [ ] **Step 1: Replace the method body**

Replace `resolve_delivery_prefill()` (lines 1439-1465) with:

```php
    public static function resolve_delivery_prefill(array $client, string $order_date_ymd): array {
        $delivery_day = strtolower(trim((string) ($client['delivery_day'] ?? '')));
        if ($delivery_day === '') {
            // Zone fallback: zones map to weekdays with zero exceptions.
            $delivery_day = (string) (MealsDB_Zone_Day::day_for_zone(
                isset($client['delivery_area_name']) ? (string) $client['delivery_area_name'] : null
            ) ?? '');
        }

        // DIRECTIVE delivery-date-next-week-rule: the stored next_delivery_date
        // preference is dropped — the column is NULL for all zoned clients and
        // is a live trap. Always compute from the shared resolver so the QO
        // prefill, the slip, and (via the _delivery_date write on create)
        // billing all agree. Frequency is not used. Blank day -> null prefill.
        $next_delivery = '';
        if ($delivery_day !== '') {
            $next_delivery = (string) MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order(
                $order_date_ymd,
                ['delivery_day' => $delivery_day]
            );
        }

        return [
            'delivery_day'       => $delivery_day !== '' ? $delivery_day : null,
            'next_delivery_date' => $next_delivery !== '' ? $next_delivery : null,
        ];
    }
```

Rewrite the doc-block above it (lines ~1410-1438): remove the "next_delivery_date: stored column first" paragraph; state that the prefill always computes via the shared resolver and that a manual `_delivery_date` still wins on create.

- [ ] **Step 2: Update the derivation test to the new rule**

Read `tests/test-quick-order-next-dates-derivation.php`. It passes `next_delivery_date => '2026-08-10'` expecting it to be preferred, and asserts derived dates under the OLD occurrence. Update: (a) any assertion that the stored `next_delivery_date` is *preferred* must change to expect the **computed** next-week date (the stored value is now ignored); (b) recompute each expected `next_delivery_date` as `next_week_delivery_date(order_date, resolved_day)`. Keep the zone-fallback and stored-day-preferred-over-zone assertions (those still hold). Recompute, don't weaken.

- [ ] **Step 3: Run the tests**

Run: `php tests/test-quick-order-next-dates-derivation.php` and `php -l includes/class-quick-order-ajax.php`
Expected: pass / no syntax errors.

- [ ] **Step 4: Commit**

```bash
git add includes/class-quick-order-ajax.php tests/test-quick-order-next-dates-derivation.php
git commit -m "fix(quick-order): prefill drops next_delivery_date anchor, uses shared resolver (ITEM 1)"
```

---

### Task 4: Verify the empty prefill is handled client-side (no code change expected)

**Files:**
- Inspect: `assets/js/quick-order.js` (`refreshDeliveryDateWarning`, `deliveryDateWarning`, prefill consumer ~line 1875)

- [ ] **Step 1: Confirm current behavior**

Read the three JS spots. Confirm: when the AJAX prefill `next_delivery_date` is null, `$('#mealsdb-qo-delivery-date').val('')` sets the field empty; `deliveryDateWarning('', day)` returns `''` (regex fails on empty) so the "Heads up:" warning is hidden. This already satisfies "empty prefill without error, warning must not fire on empty."

- [ ] **Step 2: If (and only if) a gap exists, fix it minimally**

If the inspection shows the warning could fire or JS could throw on an empty/null prefill, add the guard (early `return ''` when the date field is empty) and note it. Otherwise record "verified: empty prefill already handled, no code change" and proceed. Do NOT change the three date-field ids or the warning text.

- [ ] **Step 3: Commit only if changed** (otherwise skip)

```bash
git add assets/js/quick-order.js
git commit -m "fix(quick-order): guard delivery-date warning against empty prefill (ITEM 1)"
```

---

# ITEM 2 — Backfill + scorecard (Tasks 5–8)

### Task 5: QO create records `_delivery_date` provenance

**Files:**
- Modify: `includes/class-quick-order-ajax.php` (`apply_delivery_date_override` + its caller ~line 366, and `create_wc_order` where the client/order-date are known)

- [ ] **Step 1: Extend the override writer to tag provenance**

`apply_delivery_date_override()` (line 488) currently writes only `_delivery_date`. Add a provenance argument so the caller can mark whether the value is the system default or an operator change:

```php
    public static function apply_delivery_date_override($order, $raw, string $src = 'manual'): string {
        $ymd = MealsDB_Delivery_Date_Advisor::sanitize_ymd($raw);
        if ($ymd === '' || !is_object($order) || !method_exists($order, 'update_meta_data')) {
            return '';
        }
        $order->update_meta_data('_delivery_date', $ymd);
        // DIRECTIVE delivery-date-next-week-rule: provenance marker so the
        // repeatable backfill can overwrite system-derived dates ('auto') but
        // never a human-set one (no marker / 'manual'). 'auto' is written only
        // when the submitted value equals the computed occurrence.
        $order->update_meta_data('_delivery_date_src', $src === 'auto' ? 'auto' : 'manual');
        return $ymd;
    }
```

- [ ] **Step 2: Compute provenance at the call site**

At the caller (~line 366), before calling the writer, compute the new-rule occurrence for this order/client and mark `auto` when the posted delivery date equals it, else `manual`:

```php
            $posted_delivery = isset($_POST['delivery_date']) ? wp_unslash((string) $_POST['delivery_date']) : '';
            // Provenance: if the operator submitted exactly the system default,
            // tag 'auto' (a future backfill may re-correct it); if they changed
            // it, tag 'manual' (backfill must preserve). Blank writes nothing.
            $computed_occurrence = MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order(
                $order_date->format('Y-m-d'),
                ['delivery_day' => (string) ($client['delivery_day'] ?? '')]
            );
            $delivery_src = ($posted_delivery !== '' && $posted_delivery === (string) $computed_occurrence)
                ? 'auto' : 'manual';
            $delivery_override = self::apply_delivery_date_override($order, $posted_delivery, $delivery_src);
```

Adapt variable names to what `create_wc_order`/the surrounding scope actually exposes (`$order_date` DateTimeImmutable and the client row). If the client row isn't in scope at that point, fetch it as done in the address work (`(new MealsDB_Clients_Repository())->get_client_by_id($client_id)`), or thread the already-resolved `delivery_day`. Read the surrounding method before editing.

- [ ] **Step 3: Update the override test**

`tests/test-quick-order-delivery-date-override.php` asserts `apply_delivery_date_override($order, '2026-07-25')` writes exactly `[['_delivery_date','2026-07-25']]`. Update the expected meta writes to include the new `_delivery_date_src` write, and add a case asserting `src='auto'` vs default `'manual'`. Recompute, don't weaken.

- [ ] **Step 4: Run + commit**

Run: `php tests/test-quick-order-delivery-date-override.php` and `php tests/test-quick-order-status.php` (ensure QO create still green) and `php -l includes/class-quick-order-ajax.php`.

```bash
git add includes/class-quick-order-ajax.php tests/test-quick-order-delivery-date-override.php
git commit -m "feat(quick-order): tag _delivery_date provenance (auto|manual) on create (ITEM 2)"
```

---

### Task 6: Repeatable delivery-date backfill phase

**Files:**
- Modify: `includes/services/class-migration-consolidated.php` (add `run_phase_delivery_dates` + register in `phases()`)
- Test: `tests/test-delivery-date-backfill.php`

- [ ] **Step 1: Write the failing test for the per-order decision logic**

Extract the per-order decision into a pure static helper so it's testable without `$wpdb`, then test it. Create `tests/test-delivery-date-backfill.php`:

```php
<?php
/**
 * DIRECTIVE delivery-date-next-week-rule ITEM 2: per-order backfill decision.
 * decide_backfill_write() returns the action for one order given its current
 * _delivery_date, provenance marker, order date, and resolved delivery_day.
 *
 * Run: php tests/test-delivery-date-backfill.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = []; $passed = 0;
function bf_eq($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}
$d = ['MealsDB_Migration_Consolidated', 'decide_backfill_write'];

// order_date, delivery_day, existing _delivery_date, src marker => [action, value]
// Fresh order (no existing date), Wed client, Mon order -> write next-week Wed, auto.
bf_eq('no existing -> write auto', ['write', '2026-08-12'],
    $d('2026-08-03', 'wednesday', '', ''));
// Auto-tagged existing wrong date -> overwrite with corrected, auto.
bf_eq('auto existing -> overwrite', ['write', '2026-08-12'],
    $d('2026-08-03', 'wednesday', '2026-08-05', 'auto'));
// Human-set (has date, no marker) -> SKIP, preserve.
bf_eq('human, no marker -> skip', ['skip_human', '2026-07-01'],
    $d('2026-08-03', 'wednesday', '2026-07-01', ''));
// Human-set explicitly manual -> SKIP.
bf_eq('manual marker -> skip', ['skip_human', '2026-07-01'],
    $d('2026-08-03', 'wednesday', '2026-07-01', 'manual'));
// No resolvable day -> blank means blank, no write.
bf_eq('no day -> blank', ['blank', null],
    $d('2026-08-03', '', '', ''));
// Idempotent: auto-tagged already-correct date -> still "write" (same value) is fine,
// but report as unchanged; assert value correctness.
bf_eq('auto already correct', ['write', '2026-08-12'],
    $d('2026-08-03', 'wednesday', '2026-08-12', 'auto'));

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
```

- [ ] **Step 2: Run it, verify it fails** (`decide_backfill_write` undefined).

- [ ] **Step 3: Implement the pure decision helper + the phase**

Add to `MealsDB_Migration_Consolidated`:

```php
    /**
     * Per-order backfill decision (pure; unit-tested).
     *
     * @return array{0:string,1:?string} action in
     *   {'write','skip_human','blank'} and the value (Y-m-d for 'write',
     *   the preserved date for 'skip_human', null for 'blank').
     */
    public static function decide_backfill_write(
        string $order_date_ymd,
        string $delivery_day,
        string $existing_delivery_date,
        string $src_marker
    ): array {
        // Human-set: has a _delivery_date but the system did not write it
        // ('auto'). Preserve outright.
        $has_existing = preg_match('/^\d{4}-\d{2}-\d{2}$/', $existing_delivery_date) === 1;
        if ($has_existing && $src_marker !== 'auto') {
            return ['skip_human', $existing_delivery_date];
        }
        $computed = MealsDB_Date_Calculator::next_week_delivery_date($order_date_ymd, $delivery_day);
        if ($computed === null) {
            return ['blank', null]; // blank means blank
        }
        return ['write', $computed];
    }
```

Then add `run_phase_delivery_dates(int $offset, bool $dry_run, array $args): array` following the established phase shape (guard_phase first; chunk by ORDER rows, e.g. 200/call, ordered by order id; return `['stats'=>..., 'offset'=>..., 'total'=>..., 'complete'=>...]`). Per chunk:
1. Select a page of orders from `wc_orders` (id, `date_created_gmt`) joined to `meals_clients` on `wc_orders.customer_id = meals_clients.wp_user_id` to get `delivery_day` + `delivery_area_name`; left-join `wc_orders_meta` for the current `_delivery_date` and `_delivery_date_src`. Resolve the effective day = `delivery_day` else `MealsDB_Zone_Day::day_for_zone(delivery_area_name)`.
2. Call `decide_backfill_write(order_date, day, existing, marker)`.
3. On `'write'` (and not `$dry_run`): upsert `_delivery_date` = value and `_delivery_date_src` = `'auto'` via HPOS (`wc_get_order($id)->update_meta_data(...)->save()` **or** direct `wc_orders_meta` upsert for speed — use `wc_get_order` unless profiling shows it too slow, to stay HPOS-correct), and mark the client-month dirty for BOTH the old and new billing month via the allocation engine's dirty marker (so the subsequent rebuild re-sums both). On `'skip_human'`/`'blank'`: no write.
4. Accumulate stats: `orders_scanned`, `written`, `skipped_human`, `left_blank`, `unchanged`, `finalized_conflicts` (order whose corrected month differs but its current billing month is finalized — count and log, do not mutate).

Report the stats array on screen (the `admin-migration.js` driver renders the returned `stats`). This is the "report counts" the directive demands.

Register in `phases()`:

```php
            9 => ['method' => 'run_phase_delivery_dates', 'label' => 'Backfill Delivery Dates'],
```

- [ ] **Step 4: Run the unit test + lint**

Run: `php tests/test-delivery-date-backfill.php` and `php -l includes/services/class-migration-consolidated.php`
Expected: `OK (6 passed)` / no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-migration-consolidated.php tests/test-delivery-date-backfill.php
git commit -m "feat(migration): repeatable delivery-date backfill phase with provenance marker (ITEM 2)"
```

---

### Task 7: Propagate to allocations + billing month

**Files:**
- Modify: `includes/services/class-migration-consolidated.php` (ensure the dirty marks from Task 6 feed the rebuild) — or document running the existing allocation rebuild after the delivery-date phase.

- [ ] **Step 1: Wire the rebuild**

The delivery-date phase marks client-months dirty. Ensure billing follows by running the existing, proven rebuild over the affected dirty months — either by having the operator run phase 7 (`run_phase_allocations`, which already skips finalized months) after phase 9, or by invoking `MealsDB_Allocation_Rebuilder::rebuild_all_dirty()` at the end of the delivery-date phase's final chunk. Prefer the explicit two-phase operator flow (phase 9 then phase 7) to keep each phase single-purpose and match the existing UI; document the order in the phase label/help text. Do NOT bypass the finalized-month guards.

- [ ] **Step 2: Confirm no finalized month is silently mutated**

Add/confirm that the rebuild path leaves finalized months byte-identical (it does — `rebuild_client_month` returns early on a finalized target, `fill_months` excludes finalized from clear+insert). The `finalized_conflicts` stat from Task 6 surfaces any order that *wanted* to move but couldn't because its month is finalized — that is the correct, visible outcome.

- [ ] **Step 3: Commit** (if code changed; otherwise this is a documentation/verification step)

```bash
git add includes/services/class-migration-consolidated.php
git commit -m "chore(migration): sequence delivery-date backfill before allocation rebuild (ITEM 2)"
```

---

### Task 8: Ground-truth scorecard (the acceptance test)

**Files:**
- Create: `includes/services/class-delivery-date-scorecard.php`
- Wire: a Data-Ops / migration-page button or a dev CLI entry that accepts the operator's ground-truth CSV.

- [ ] **Step 1: Implement the scorecard**

Create `MealsDB_Delivery_Date_Scorecard` with a static `score(array $ground_truth): array` where `$ground_truth` is `[order_number => 'Y-m-d actual delivery']` (parsed from the operator's CSV — `order_number,delivery_date`). It reads each order's stored `_delivery_date` (or, when absent, the rebuilder-resolved date via `resolve_delivery_date`) and returns:

```php
[
  'total'      => N,           // ground-truth rows joined to a real order
  'matched'    => M,           // stored == actual
  'match_rate' => M / N,       // float
  'misses'     => [ ['order' => 27454, 'stored' => '2026-06-27', 'actual' => '2026-07-01'], ... ],
]
```

The `misses` list must be nameable (order number + both dates) so the residual can be classified as retroactive-entry / route-shuffle per the directive. Keep it pure and repeatable (pass the CSV in; no external calls).

- [ ] **Step 2: Test with a small fixture**

Create a tiny in-test ground-truth map against a couple of mocked orders (or, if order reads need `$wpdb`, unit-test the pure comparison core: given `[order => stored]` and `[order => actual]`, compute rate + misses). Assert rate math and miss listing.

- [ ] **Step 3: Wire an operator entry point**

Add a button/handler on the migration or Data-Ops page (capability `manage_options`, nonce, rate-limited) that accepts the uploaded CSV and prints the scorecard (before/after `matched / total` and the miss list). Follow the existing migration AJAX + `admin-migration.js` patterns; do not invent a new nonce context if an existing migration one fits.

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-delivery-date-scorecard.php includes/admin includes/ajax tests/
git commit -m "feat(migration): delivery-date ground-truth scorecard (ITEM 2 acceptance test)"
```

---

## Acceptance / Verify (operator, on staging with real data)

**ITEM 1 (unit-proven + GUI spot-check):**
- Wednesday client, order Monday → next week's Wednesday (not this week's). Thursday order → next week's Wednesday. Zone 4 Tuesday client, Friday order → next week's Tuesday. Fortnightly & four-weekly clients get the SAME date as weekly. No day/zone → empty prefill, no error, no warning.

**ITEM 2 (scorecard is the gate):**
- Run phase 9 (Backfill Delivery Dates) then phase 7 (Backfill Allocations). Both report counts on screen.
- Post-backfill scorecard match rate **≥ 95%** (target ~800/833), with misses identifiable as retroactive entry or known one-off route changes. **If materially below 96%, stop and report.**
- **Order 27454 (Robert Gallant)** moves from a June delivery to July and appears on the July VAC invoice (requires June not finalized — see preconditions).
- **Zone 4 July slips** land on the legacy Tuesdays (Jun 30, Jul 14, Jul 28), not spread across the week.
- July SDNB + VAC drafts regenerate without error; record the new totals as the baseline (they **will** change — that's the correction).
- Re-run the backfill: human-set (`skip_human`) dates are untouched; auto dates are stable. Confirms repeatability.

## Self-review notes

- **Spec coverage:** ITEM 1 rule → Tasks 1-2; prefill anchor removal → Task 3; blank-means-blank + empty-warning → Tasks 1/3/4; single shared resolver (slip+prefill move together) → Tasks 2/3 both route through `next_week_delivery_date`; billing follows via `_delivery_date` write → Tasks 5-7; repeatable + skip-human → marker in Tasks 5-6; report counts → Task 6 stats; scorecard → Task 8. "Must NOT change": `snap_to_delivery_day`/`next_date` untouched (Task 1), the three QO field ids + warning text untouched (Task 4), manual override authority preserved (Tasks 5-6 skip_human).
- **Type consistency:** `next_week_delivery_date(string,?string):?string`, `decide_backfill_write(string,string,string,string):array{0:string,1:?string}`, `apply_delivery_date_override($order,$raw,string $src='manual'):string` used identically across tasks/tests.
- **Known dependency / sequencing:** ITEM 3 operator cleanup and the no-finalized-month confirmation must precede the ITEM 2 run; ITEM 1 is independently shippable and makes NEW orders correct on its own.
