# Apetito PO — `accepted` status, inventory-commit move, cadence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an `accepted` PO lifecycle state, move the inventory commit from Received to Accepted (with a reversible Un-accept), and derive the Apetito 4-week order cadence from the order date.

**Architecture:** The status ENUM gains `accepted` (and drops the dead `counted`). `mark_accepted()` becomes the single inventory-commit point; `mark_received()` becomes a pure state marker; `unaccept()` reverses the exact committed quantities by reusing a direction-parameterized `apply_inventory_bump()` (one inventory-write implementation, made symmetric). A pure static helper derives inventory-due / ship / expected-arrival / next-order dates from a single order date. AJAX, view, and JS gain the two new transitions and the cadence display.

**Tech Stack:** PHP 8.2 / WordPress (HPOS) / WooCommerce stock API / vanilla-jQuery admin JS. Tests are the repo's hand-rolled in-memory-`wpdb` harness run with `php tests/<file>.php` (no PHPUnit).

**Source directive:** `directives/DIRECTIVE-apetito-po-accepted-status.md` (baseline v1.0.553).

**Key files:**
- Modify: `includes/class-schema.php` (ENUM + two columns + comment)
- Modify: `includes/services/class-purchase-orders.php` (constants, labels, docblocks, `apply_inventory_bump` signature, `mark_accepted`, `mark_received`, `unaccept`, cadence helper, `approve` default)
- Modify: `includes/ajax/class-ajax-purchase-orders.php` (two endpoints)
- Modify: `views/purchase-orders.php` (buttons, status/accepted display, cadence block)
- Modify: `assets/js/purchase-orders.js` (ACTION_MAP, reason prompt, confirm wording) and the island i18n in the view
- Test (modify): `tests/test-po-draft-lifecycle.php`, `tests/test-po-reconcile-deltas.php`
- Test (create): `tests/test-po-accepted-status.php`

**Out of scope / known fallout (do NOT fix here):** `tests/test-po-task-types.php` and `tests/test-po-task-bridge.php` drive `placed → mark_received` through the task bridge, which the new guard blocks by design. The directive states the task system is not in use, will be wiped, and its compatibility must NOT be preserved. Leave those two files as-is; they fail as expected against a subsystem slated for deletion. Flag this at handoff — do not retrofit the task bridge for `accepted`.

---

## Lifecycle after this change

```
planned (Draft) → placed (Approved) → accepted (Accepted) → arrived (Received) → reconciled
                                                                  cancelled (terminal, from Draft)
```

- **Inventory is committed at Accepted** (vendor confirmed), reversed at Un-accept.
- **Mark Received no longer touches inventory** and is reachable ONLY from `accepted`.
- Transition-first, stock-write-second ordering is preserved in every inventory-touching method (the double-click guard).

---

## Task 1: Schema — ENUM swap, `accepted_by`/`accepted_at`, comment

**Files:**
- Modify: `includes/class-schema.php:490` (status ENUM), `:494-499` (comment), `:503-506` (columns)

- [ ] **Step 1: Swap the status ENUM (add `accepted`, drop `counted`)**

Replace line 490:

```php
                    'status'           => "ENUM('planned','placed','arrived','counted','reconciled','cancelled') NOT NULL DEFAULT 'planned'",
```

with:

```php
                    'status'           => "ENUM('planned','placed','accepted','arrived','reconciled','cancelled') NOT NULL DEFAULT 'planned'",
```

- [ ] **Step 2: Add the `accepted_by` / `accepted_at` columns**

After the `approved_at` column (line 504), insert two lines mirroring the received pattern:

```php
                    'approved_by'      => 'BIGINT UNSIGNED NULL',
                    'approved_at'      => 'DATETIME NULL',
                    'accepted_by'      => 'BIGINT UNSIGNED NULL',
                    'accepted_at'      => 'DATETIME NULL',
                    'received_by'      => 'BIGINT UNSIGNED NULL',
                    'received_at'      => 'DATETIME NULL',
```

- [ ] **Step 3: Correct the stale ADDITIVE-ONLY comment**

The comment at lines 494-499 claims the workflow "reuses the existing status ENUM ... instead of adding new ENUM values." That is no longer true. Replace that comment block with:

```php
                    // --- PO draft workflow (2026-07 spec; 'accepted' added 2026-08).
                    // ADDITIVE column changes are auto-applied by Schema_Sync; the
                    // status ENUM edit (add 'accepted', drop the dead 'counted') is a
                    // RISKY change (ENUM narrowing) surfaced in Data-Ops → Schema
                    // Changes for a typed ALTER — safe here because existing POs are
                    // wiped at cutover, so no row holds 'counted'. payload IS NULL
                    // marks a legacy task-created PO (read-only in the new UI).
```

- [ ] **Step 4: Bump the plugin version so the upgrade hook fires**

CI owns version bumps in this repo (see feature-track-workflow). Do NOT hand-edit `MEALS_DB_VERSION`. Note in the PR body that this change requires a schema upgrade and the operator must apply the ENUM narrowing via **Data-Ops → Schema Changes** after deploy (the two new columns auto-add; the ENUM edit is a manual typed `ALTER`).

- [ ] **Step 5: Commit**

```bash
git add includes/class-schema.php
git commit -m "feat(po): add 'accepted' status column + drop dead 'counted' from schema"
```

---

## Task 2: Service — constants, allowed statuses, label, docblocks

**Files:**
- Modify: `includes/services/class-purchase-orders.php` (lines 6-9, 22-26, 28-35, 50-72, 431-438)

- [ ] **Step 1: Add the `STATUS_ACCEPTED` constant**

Change lines 22-26 from:

```php
    public const STATUS_PLANNED    = 'planned';
    public const STATUS_PLACED     = 'placed';
    public const STATUS_ARRIVED    = 'arrived';
    public const STATUS_RECONCILED = 'reconciled';
    public const STATUS_CANCELLED  = 'cancelled';
```

to:

```php
    public const STATUS_PLANNED    = 'planned';
    public const STATUS_PLACED     = 'placed';
    public const STATUS_ACCEPTED   = 'accepted';
    public const STATUS_ARRIVED    = 'arrived';
    public const STATUS_RECONCILED = 'reconciled';
    public const STATUS_CANCELLED  = 'cancelled';
```

- [ ] **Step 2: Add `STATUS_ACCEPTED` to `ALLOWED_STATUSES`**

Change lines 66-72 to insert accepted between placed and arrived:

```php
    public const ALLOWED_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_PLACED,
        self::STATUS_ACCEPTED,
        self::STATUS_ARRIVED,
        self::STATUS_RECONCILED,
        self::STATUS_CANCELLED,
    ];
```

- [ ] **Step 3: Add the `Accepted` label**

In `status_label()` (lines 431-438), add a case after `STATUS_PLACED`:

```php
            case self::STATUS_PLACED:     return __('Approved', 'meals-db');
            case self::STATUS_ACCEPTED:   return __('Accepted', 'meals-db');
            case self::STATUS_ARRIVED:    return __('Received', 'meals-db');
```

- [ ] **Step 4: Update the two lifecycle docblocks**

Class-file header (lines 6-7): change

```php
     * Workflow states (status column doubles as state):
     *   planned=Draft → placed=Approved → arrived=Received → reconciled
```

to

```php
     * Workflow states (status column doubles as state):
     *   planned=Draft → placed=Approved → accepted=Accepted → arrived=Received → reconciled
     * Inventory is committed at ACCEPTED (vendor confirmed); Received is a pure
     * state marker. Un-accept reverses the committed stock.
```

Constant docblock (lines 28-35): change the `planned=Draft, placed=Approved, arrived=Received` line to include `accepted=Accepted`:

```php
     *   planned=Draft, placed=Approved, accepted=Accepted, arrived=Received, reconciled, cancelled.
```

Also delete the now-obsolete `counted`-tolerance note in the `ALLOWED_STATUSES` HISTORY block (lines 60-64, the sentence beginning "The schema ENUM in class-schema.php still tolerates 'counted' for now …") and replace with:

```php
     * place in the workflow. Removed for clarity. The schema ENUM no longer
     * carries 'counted' either (dropped alongside adding 'accepted', 2026-08).
```

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-purchase-orders.php
git commit -m "feat(po): STATUS_ACCEPTED constant, label, and lifecycle docblocks"
```

---

## Task 3: Direction-parameterize `apply_inventory_bump()`

This is the "one implementation, made symmetric" fix that lets Un-accept reverse stock without a second inventory-write path.

**Files:**
- Modify: `includes/services/class-purchase-orders.php:930` (signature + loop + audit label)
- Test: `tests/test-po-accepted-status.php` (created in Task 10; the assertion below is proven there)

- [ ] **Step 1: Change the signature and validate direction**

Change line 930 from:

```php
    public static function apply_inventory_bump(array $items): void {
```

to:

```php
    /**
     * @param string $direction 'increase' (accept) or 'decrease' (un-accept).
     *        Un-accept reverses the SAME stored quantities that accept committed,
     *        so this stays the single inventory-write path (directive 2c).
     */
    public static function apply_inventory_bump(array $items, string $direction = 'increase'): void {
        // Whitelist: anything but the explicit reversal is an increase.
        $direction = ($direction === 'decrease') ? 'decrease' : 'increase';
```

- [ ] **Step 2: Pass the direction into the WC stock write and label the audit**

In the loop, change the stock write (currently line ~959):

```php
            $new_stock = wc_update_product_stock($product, $qty, 'increase');
```

to:

```php
            $new_stock = wc_update_product_stock($product, $qty, $direction);
```

And change the audit action label so a reversal is distinguishable. Currently:

```php
                MealsDB_Logger::log(
                    'po_inventory_bump',
```

becomes:

```php
                MealsDB_Logger::log(
                    $direction === 'decrease' ? 'po_inventory_unbump' : 'po_inventory_bump',
```

- [ ] **Step 3: Update the method's summary comment**

Change the summary at lines 924-928 from "Bump WC stock for each item by quantity_ordered (UNITS)." to:

```php
    /**
     * Adjust WC stock for each item by quantity_ordered (UNITS), in $direction.
     * Silently skips items with unknown SKUs, logging each miss. This is the
     * ONLY stock-write path for POs — accept increases, un-accept decreases.
     */
```

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-purchase-orders.php
git commit -m "feat(po): direction-parameterize apply_inventory_bump for reversal"
```

---

## Task 4: `mark_accepted()` + demote `mark_received()` to a pure marker

**Files:**
- Modify: `includes/services/class-purchase-orders.php` (add `mark_accepted` before `mark_received` at ~651; edit `mark_received` 643-682)
- Test: `tests/test-po-accepted-status.php` (Task 10)

- [ ] **Step 1: Write the failing test first (accept commits stock; received does not)**

This is authored in full in Task 10 (`tests/test-po-accepted-status.php`, cases A-1…A-4). If executing strictly TDD, create that file now with cases A-1…A-4 and run it — it fails because `mark_accepted` does not exist.

Run: `php tests/test-po-accepted-status.php`
Expected: FAIL (`Call to undefined method ... mark_accepted()` or WP_Error `locked`).

- [ ] **Step 2: Add `mark_accepted()` immediately above `mark_received()`**

Insert before the `mark_received()` docblock at line 643:

```php
    /**
     * Approved → Accepted. THE inventory commit point (directive 2a): the
     * vendor has confirmed the order, so stock is committed now — before
     * physical delivery. The guarded transition runs FIRST so a double-click
     * can't bump twice (the loser's UPDATE matches 0 rows and returns before
     * any stock write). Do NOT reorder.
     *
     * @return true|WP_Error
     */
    public function mark_accepted(int $po_id) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        $po = $this->require_workflow_po($po_id, self::STATUS_PLACED,
            __('Only approved purchase orders can be marked accepted.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }

        $ok = $this->transition($po_id, self::STATUS_PLACED, self::STATUS_ACCEPTED, [
            'accepted_by' => get_current_user_id() ?: null,
            'accepted_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            return new WP_Error('race', __('Could not mark accepted (a concurrent change happened) — reload.', 'meals-db'));
        }

        self::apply_inventory_bump((array) $po['items']);
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_accepted', $po_id, 'status', self::STATUS_PLACED, self::STATUS_ACCEPTED);
        }
        if (function_exists('do_action')) {
            do_action('mealsdb_po_accepted', $po_id);
        }
        return true;
    }
```

- [ ] **Step 3: Demote `mark_received()` — guard from `accepted`, DELETE the bump**

Replace the `mark_received()` docblock + body (lines 643-682) so the guard is `STATUS_ACCEPTED → STATUS_ARRIVED` and the `apply_inventory_bump` call is gone:

```php
    /**
     * Accepted → Received. A PURE state marker (directive 2b): stock was
     * already committed at Accept, so this method must NOT touch inventory —
     * doing so would double-count every PO. It records the ACTUAL arrival date.
     *
     * @return true|WP_Error
     */
    public function mark_received(int $po_id, ?string $arrival_date = null) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        $po = $this->require_workflow_po($po_id, self::STATUS_ACCEPTED,
            __('Only accepted purchase orders can be marked received.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }

        // A task completed after the fact may carry the TRUE arrival date;
        // the PO page passes null and gets today (UTC), as before.
        $arrival_date = self::normalize_date($arrival_date) ?? gmdate('Y-m-d');

        $ok = $this->transition($po_id, self::STATUS_ACCEPTED, self::STATUS_ARRIVED, [
            'received_by'  => get_current_user_id() ?: null,
            'received_at'  => gmdate('Y-m-d H:i:s'),
            'arrival_date' => $arrival_date,
        ]);
        if (!$ok) {
            return new WP_Error('race', __('Could not mark received (a concurrent change happened) — reload.', 'meals-db'));
        }

        // NO inventory bump here — stock was committed at Accept (directive 2b).
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_received', $po_id, 'status', self::STATUS_ACCEPTED, self::STATUS_ARRIVED);
        }
        if (function_exists('do_action')) {
            do_action('mealsdb_po_received', $po_id);
        }
        return true;
    }
```

- [ ] **Step 4: Run the accept assertions**

Run: `php tests/test-po-accepted-status.php`
Expected: A-1…A-4 PASS (accept commits stock once; received does not change it; received is unreachable from `placed`).

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-purchase-orders.php tests/test-po-accepted-status.php
git commit -m "feat(po): commit inventory at Accepted; Received is now a pure marker"
```

---

## Task 5: `unaccept()` — reversible, reason-required

**Files:**
- Modify: `includes/services/class-purchase-orders.php` (add `unaccept()` after `mark_accepted`)
- Test: `tests/test-po-accepted-status.php` (Task 10, cases A-5…A-6)

- [ ] **Step 1: Write the failing test (Task 10 cases A-5, A-6) and run it**

Run: `php tests/test-po-accepted-status.php`
Expected: FAIL (`Call to undefined method ... unaccept()`).

- [ ] **Step 2: Add `unaccept()` (model on `unapprove()`, but reverse the stock)**

Insert directly after `mark_accepted()`:

```php
    /**
     * Accepted → Approved, reason required and audited (mirrors unapprove).
     * Because Accept committed stock, this REVERSES it: the exact quantities
     * from the stored PO items are decremented — never a recomputation, so the
     * reversal mirrors the accept bump 1:1 even if live stock drifted between
     * (directive 2c). Transition FIRST, stock write SECOND — the accept guard.
     *
     * @return true|WP_Error
     */
    public function unaccept(int $po_id, string $reason) {
        if (class_exists('MealsDB_Permissions') && !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error('forbidden', __('Insufficient permissions.', 'meals-db'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            return new WP_Error('reason_required', __('A reason is required to un-accept (it is audited).', 'meals-db'));
        }
        $po = $this->require_workflow_po($po_id, self::STATUS_ACCEPTED,
            __('Only accepted (not yet received) purchase orders can be un-accepted.', 'meals-db'));
        if (is_wp_error($po)) {
            return $po;
        }

        $ok = $this->transition($po_id, self::STATUS_ACCEPTED, self::STATUS_PLACED, [
            'accepted_by' => null,
            'accepted_at' => null,
        ]);
        if (!$ok) {
            return new WP_Error('race', __('Could not un-accept (a concurrent change happened) — reload.', 'meals-db'));
        }

        self::apply_inventory_bump((array) $po['items'], 'decrease');
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('po_unaccepted', $po_id, 'reason', null,
                substr($reason, 0, self::MAX_NOTE_LEN));
        }
        if (function_exists('do_action')) {
            do_action('mealsdb_po_unaccepted', $po_id, $reason);
        }
        return true;
    }
```

- [ ] **Step 3: Run the reversal assertions**

Run: `php tests/test-po-accepted-status.php`
Expected: A-5 (reason required; status back to placed; stock returns to pre-accept level, exact quantities) and A-6 (double-click accept bumps once) PASS.

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-purchase-orders.php tests/test-po-accepted-status.php
git commit -m "feat(po): unaccept reverses committed stock with an audited reason"
```

---

## Task 6: Cadence helper + `approve()` default expected-arrival

**Files:**
- Modify: `includes/services/class-purchase-orders.php` (add helper near `normalize_date` ~276; edit `approve()` ~530)
- Test: `tests/test-po-accepted-status.php` (Task 10, cases A-7…A-8)

- [ ] **Step 1: Write the failing cadence test (Task 10 cases A-7, A-8) and run it**

Run: `php tests/test-po-accepted-status.php`
Expected: FAIL (`Call to undefined method ... po_schedule_from_order_date()`).

- [ ] **Step 2: Add the pure cadence helper**

Insert after `normalize_date()` (after line 284):

```php
    /**
     * Derive the Apetito order cadence from a single order date T (a Tuesday in
     * the normal 4-week cycle). Calendar-day math anchored to UTC so it is
     * DST-proof (Pattern 11): a Y-m-d in, Y-m-d out.
     *
     *   inventory_due    = T + 8   (Wed, following week)
     *   ship_date        = T + 10  (Fri)
     *   expected_arrival = T + 13  (Mon after the Fri ship — confirmed 2026-08-14)
     *   next_order_date  = T + 28  (Tue, four weeks on)
     *
     * is_off_cycle is true when T is not a Tuesday; the offsets are still taken
     * from the actual date (never snapped) so an off-cycle order is shown, not
     * blocked (directive item 3).
     *
     * @return array{order_date:string,inventory_due:string,ship_date:string,expected_arrival:string,next_order_date:string,is_off_cycle:bool}|null
     *         null when $order_date is not a valid Y-m-d.
     */
    public static function po_schedule_from_order_date(string $order_date): ?array {
        $base   = DateTimeImmutable::createFromFormat('!Y-m-d', $order_date, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($base === false || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }
        $add = static function (int $days) use ($base): string {
            return $base->modify('+' . $days . ' days')->format('Y-m-d');
        };
        return [
            'order_date'       => $base->format('Y-m-d'),
            'inventory_due'    => $add(8),
            'ship_date'        => $add(10),
            'expected_arrival' => $add(13),
            'next_order_date'  => $add(28),
            // Tuesday === ISO-8601 weekday 2.
            'is_off_cycle'     => $base->format('N') !== '2',
        ];
    }
```

- [ ] **Step 3: Default `approve()`'s expected-arrival to T+13**

In `approve()`, immediately after the existing `$expected_arrival = self::normalize_date($expected_arrival);` (line 530), add:

```php
        $expected_arrival = self::normalize_date($expected_arrival);

        // When the caller passes no date, derive the cadence's expected arrival
        // (T + 13) from today's order date, keeping expected_arrival meaningful
        // rather than NULL (directive item 3). placed_date is set to this same
        // gmdate() below, so T is consistent.
        if ($expected_arrival === null) {
            $schedule = self::po_schedule_from_order_date(gmdate('Y-m-d'));
            if ($schedule !== null) {
                $expected_arrival = $schedule['expected_arrival'];
            }
        }
```

- [ ] **Step 4: Run the cadence assertions**

Run: `php tests/test-po-accepted-status.php`
Expected: A-7 (Tue 2026-08-04 → due 08-12, ship 08-14, arrival 08-17, next 09-01, not off-cycle) and A-8 (a Wednesday derives offsets from its own date and is flagged off-cycle; invalid date → null) PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-purchase-orders.php tests/test-po-accepted-status.php
git commit -m "feat(po): derive Apetito order cadence; default approve arrival to T+13"
```

---

## Task 7: AJAX — `mark_accepted` + `unaccept` endpoints

**Files:**
- Modify: `includes/ajax/class-ajax-purchase-orders.php` (register at 35-42; methods at 119-123; dispatch at 144-155)

- [ ] **Step 1: Register the two new actions**

In `init()` after the `mealsdb_po_unapprove` registration (line 39), add:

```php
        add_action('wp_ajax_mealsdb_po_unapprove',          [__CLASS__, 'unapprove']);
        add_action('wp_ajax_mealsdb_po_mark_accepted',      [__CLASS__, 'mark_accepted']);
        add_action('wp_ajax_mealsdb_po_unaccept',           [__CLASS__, 'unaccept']);
        add_action('wp_ajax_mealsdb_po_mark_received',      [__CLASS__, 'mark_received']);
```

- [ ] **Step 2: Add the two thin dispatch methods**

After `mark_received()` (line 121), add:

```php
    public static function mark_received(): void      { self::transition_endpoint('mark_received'); }
    public static function mark_accepted(): void      { self::transition_endpoint('mark_accepted'); }
    public static function unaccept(): void           { self::transition_endpoint('unaccept'); }
```

- [ ] **Step 3: Thread the un-accept reason through `transition_endpoint()`**

In `transition_endpoint()`, extend the `unapprove` branch (lines 144-146) so `unaccept` also reads a reason:

```php
            if ($method === 'unapprove' || $method === 'unaccept') {
                $reason = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
                $result = $service->{$method}($po_id, $reason);
            } elseif ($method === 'approve') {
```

(This replaces the old `if ($method === 'unapprove') { ... $service->unapprove($po_id, $reason); }` block. `mark_accepted` needs no reason and falls through to the generic `$service->{$method}($po_id)` else-branch.)

- [ ] **Step 4: Update the class docblock count**

The header says "Eight endpoints" (line 5). It is now ten. Change "Eight endpoints, each carrying" to "Ten endpoints, each carrying".

- [ ] **Step 5: Lint**

Run: `php -l includes/ajax/class-ajax-purchase-orders.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add includes/ajax/class-ajax-purchase-orders.php
git commit -m "feat(po): AJAX endpoints for mark_accepted and unaccept"
```

---

## Task 8: View — buttons, status display, cadence block

**Files:**
- Modify: `views/purchase-orders.php` (island i18n 36-52; detail actions 143-162; form-table 124-141; list actions 425-436; add cadence render helper + block)

- [ ] **Step 1: Add island i18n strings for the new actions and re-word receive**

In `$mealsdb_po_render_island`'s `i18n` array (lines 37-52), change `confirmReceive` and add three keys:

```php
            'confirmApprove'   => __('Approve this purchase order? Approved orders are locked (un-approve requires an audited reason).', 'meals-db'),
            'confirmAccept'    => __('Mark this purchase order as accepted? The vendor has confirmed it, so ordered quantities will be ADDED to inventory now.', 'meals-db'),
            'confirmReceive'   => __('Mark this purchase order as received? Stock was already committed at Accept — this only records arrival.', 'meals-db'),
            'confirmCancel'    => __('Cancel this draft purchase order?', 'meals-db'),
            'confirmComplete'  => __('Complete reconciliation? Stock will be corrected for every adjusted row and the purchase order will be locked.', 'meals-db'),
            'promptExpectedArrival' => __('Expected arrival date (YYYY-MM-DD) — OK approves:', 'meals-db'),
            'promptUnapprove'  => __('Enter a reason for un-approving (required — it is audited):', 'meals-db'),
            'promptUnaccept'   => __('Enter a reason for un-accepting (required — it reverses inventory and is audited):', 'meals-db'),
```

- [ ] **Step 2: Detail-view action buttons — split Accept out of placed, add accepted state**

Replace the detail action block (lines 145-158) so `placed` offers **Accept**/Un-approve (NOT Mark received), and a new `accepted` branch offers **Mark received**/Un-accept:

```php
                <?php if ($status === MealsDB_Purchase_Orders::STATUS_PLANNED): ?>
                    <label for="mealsdb-po-expected-arrival"><?php esc_html_e('Expected arrival:', 'meals-db'); ?></label>
                    <input type="date" id="mealsdb-po-expected-arrival"
                        value="<?php echo esc_attr(gmdate('Y-m-d', strtotime('+13 days'))); ?>" />
                    <button type="button" class="button button-primary mealsdb-po-action" data-po-action="approve" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Approve', 'meals-db'); ?></button>
                    <button type="button" class="button mealsdb-po-action" data-po-action="cancel" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Cancel draft', 'meals-db'); ?></button>
                <?php elseif ($status === MealsDB_Purchase_Orders::STATUS_PLACED): ?>
                    <button type="button" class="button button-primary mealsdb-po-action" data-po-action="accept" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Accept', 'meals-db'); ?></button>
                    <button type="button" class="button mealsdb-po-action" data-po-action="unapprove" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Un-approve', 'meals-db'); ?></button>
                <?php elseif ($status === MealsDB_Purchase_Orders::STATUS_ACCEPTED): ?>
                    <button type="button" class="button button-primary mealsdb-po-action" data-po-action="receive" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Mark received', 'meals-db'); ?></button>
                    <button type="button" class="button mealsdb-po-action" data-po-action="unaccept" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Un-accept', 'meals-db'); ?></button>
                <?php elseif ($status === MealsDB_Purchase_Orders::STATUS_ARRIVED && $mode !== 'reconcile'): ?>
                    <a class="button button-primary" href="<?php echo esc_url(add_query_arg(['po_id' => $po_id, 'action' => 'reconcile'], $base_url)); ?>"><?php esc_html_e('Reconcile', 'meals-db'); ?></a>
                <?php elseif ($mode === 'reconcile'): ?>
                    <button type="button" class="button button-primary" id="mealsdb-po-complete-reconcile" data-po-id="<?php echo (int) $po_id; ?>"><?php esc_html_e('Complete reconciliation', 'meals-db'); ?></button>
                <?php endif; ?>
```

Also update the read-only notice block (lines 114-117): the `STATUS_PLACED` "approved and is shown read-only" notice is correct; add an `accepted` case after it:

```php
        <?php if ($is_workflow && $mode === 'locked' && $status === MealsDB_Purchase_Orders::STATUS_PLACED): ?>
            <div class="notice notice-info"><p><?php esc_html_e('This purchase order is approved and is shown read-only. Accept it (vendor confirmed) to commit inventory, or un-approve to make changes.', 'meals-db'); ?></p></div>
        <?php elseif ($is_workflow && $mode === 'locked' && $status === MealsDB_Purchase_Orders::STATUS_ACCEPTED): ?>
            <div class="notice notice-info"><p><?php esc_html_e('This purchase order is accepted and its stock is committed. Mark it received when it arrives, or un-accept to reverse the inventory commit.', 'meals-db'); ?></p></div>
        <?php elseif ($is_workflow && $mode === 'locked' && $status === MealsDB_Purchase_Orders::STATUS_ARRIVED): ?>
```

- [ ] **Step 3: Show `accepted_at` in the detail form-table**

In the `form-table` (after the Placed Date row, line 129), add an Accepted row:

```php
                <tr><th><?php esc_html_e('Placed Date', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['placed_date'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Accepted', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['accepted_at'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Expected Arrival', 'meals-db'); ?></th>
```

- [ ] **Step 4: Add the derived-cadence block on the detail view**

Immediately after the `</table>` that closes the form-table (line 141), before the `<?php if ($is_workflow): ?>` actions paragraph, insert a schedule block. It previews from today for a draft, and uses the real `placed_date` once approved:

```php
        <?php
        if ($is_workflow) {
            $sched_base = !empty($po['placed_date']) ? (string) $po['placed_date'] : gmdate('Y-m-d');
            $sched = MealsDB_Purchase_Orders::po_schedule_from_order_date($sched_base);
            if ($sched !== null):
                $sched_is_preview = empty($po['placed_date']);
        ?>
        <table class="form-table mealsdb-po-schedule" role="presentation">
            <tbody>
                <tr><th colspan="2"><h3 style="margin:0;">
                    <?php echo $sched_is_preview
                        ? esc_html__('Order schedule (preview — set at approval)', 'meals-db')
                        : esc_html__('Order schedule', 'meals-db'); ?>
                </h3></th></tr>
                <tr><th><?php esc_html_e('Order date (T)', 'meals-db'); ?></th>
                    <td><?php echo esc_html($sched['order_date']);
                        if ($sched['is_off_cycle']): ?>
                        <span class="mealsdb-po-flag mealsdb-po-warn" title="<?php esc_attr_e('Off-cycle: order date is not a Tuesday', 'meals-db'); ?>">!</span>
                        <em><?php esc_html_e('off-cycle (not a Tuesday)', 'meals-db'); ?></em>
                        <?php endif; ?></td></tr>
                <tr><th><?php esc_html_e('Inventory in system by (T+8)', 'meals-db'); ?></th>
                    <td><?php echo esc_html($sched['inventory_due']); ?></td></tr>
                <tr><th><?php esc_html_e('Apetito ships (T+10)', 'meals-db'); ?></th>
                    <td><?php echo esc_html($sched['ship_date']); ?></td></tr>
                <tr><th><?php esc_html_e('Expected arrival (T+13)', 'meals-db'); ?></th>
                    <td><?php echo esc_html($sched['expected_arrival']); ?></td></tr>
                <tr><th><?php esc_html_e('Next order (T+28)', 'meals-db'); ?></th>
                    <td><?php echo esc_html($sched['next_order_date']); ?></td></tr>
            </tbody>
        </table>
        <?php endif; } ?>
```

- [ ] **Step 5: List-view action buttons — add accept/accepted**

Replace the list-view action branches (lines 427-435) so `placed` shows **Accept**/Un-approve and a new `accepted` branch shows **Mark received**/Un-accept:

```php
                            <?php if ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_PLANNED): ?>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="approve" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Approve', 'meals-db'); ?></button>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="cancel" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Cancel', 'meals-db'); ?></button>
                            <?php elseif ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_PLACED): ?>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="accept" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Accept', 'meals-db'); ?></button>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="unapprove" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Un-approve', 'meals-db'); ?></button>
                            <?php elseif ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_ACCEPTED): ?>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="receive" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Mark received', 'meals-db'); ?></button>
                                <button type="button" class="button button-small mealsdb-po-action" data-po-action="unaccept" data-po-id="<?php echo $rid; ?>"><?php esc_html_e('Un-accept', 'meals-db'); ?></button>
                            <?php elseif ($is_wf && $st === MealsDB_Purchase_Orders::STATUS_ARRIVED): ?>
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(['po_id' => $rid, 'action' => 'reconcile'], $base_url)); ?>"><?php esc_html_e('Reconcile', 'meals-db'); ?></a>
                            <?php endif; ?>
```

- [ ] **Step 6: Lint**

Run: `php -l views/purchase-orders.php`
Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add views/purchase-orders.php
git commit -m "feat(po): Accept/Un-accept buttons, accepted display, cadence schedule block"
```

---

## Task 9: JS — ACTION_MAP, un-accept reason prompt

**Files:**
- Modify: `assets/js/purchase-orders.js` (ACTION_MAP 180-185; reason-prompt branch 208-215)

- [ ] **Step 1: Add accept + unaccept to ACTION_MAP**

Replace the `ACTION_MAP` object (lines 180-185):

```javascript
    var ACTION_MAP = {
        approve:   { action: 'mealsdb_po_approve',       confirm: t('confirmApprove', 'Approve this purchase order?') },
        accept:    { action: 'mealsdb_po_mark_accepted', confirm: t('confirmAccept', 'Mark accepted? Quantities will be added to inventory.') },
        receive:   { action: 'mealsdb_po_mark_received', confirm: t('confirmReceive', 'Mark received? Stock was already committed at Accept.') },
        cancel:    { action: 'mealsdb_po_cancel',        confirm: t('confirmCancel', 'Cancel this draft purchase order?') },
        unapprove: { action: 'mealsdb_po_unapprove',     confirm: null },
        unaccept:  { action: 'mealsdb_po_unaccept',      confirm: null }
    };
```

- [ ] **Step 2: Route un-accept through the reason prompt**

The reason-prompt branch currently only matches `unapprove` (lines 208-215). Generalise it to both un-* actions and pick the right prompt string:

```javascript
        } else if (kind === 'unapprove' || kind === 'unaccept') {
            var promptKey = (kind === 'unaccept') ? 'promptUnaccept' : 'promptUnapprove';
            var promptTxt = (kind === 'unaccept')
                ? t(promptKey, 'Enter a reason for un-accepting (required):')
                : t(promptKey, 'Enter a reason for un-approving (required):');
            var reason = window.prompt(promptTxt);
            if (reason === null) { return; }
            if (!reason.replace(/\s/g, '').length) {
                msg(t('reasonRequired', 'A reason is required.'), true);
                return;
            }
            data.reason = reason;
        } else if (!window.confirm(map.confirm)) {
            return;
        }
```

- [ ] **Step 3: Update the header comment**

The file header (lines 5-7) lists "approve / un-approve / receive / cancel / complete-reconcile". Change to "approve / accept / un-accept / un-approve / receive / cancel / complete-reconcile".

- [ ] **Step 4: Syntax check**

Run: `node --check assets/js/purchase-orders.js`
Expected: exits 0 (no output). If `node` is unavailable, visually confirm the braces balance.

- [ ] **Step 5: Commit**

```bash
git add assets/js/purchase-orders.js
git commit -m "feat(po): client actions for Accept and Un-accept"
```

---

## Task 10: Tests — new accepted-status suite + fix in-scope regressions

**Files:**
- Create: `tests/test-po-accepted-status.php`
- Modify: `tests/test-po-draft-lifecycle.php` (T-10, T-11 now route through Accept)
- Modify: `tests/test-po-reconcile-deltas.php` (`arrived_po` helper inserts an Accept step)

- [ ] **Step 1: Create `tests/test-po-accepted-status.php`**

This mirrors the harness and stubs of `test-po-draft-lifecycle.php` (in-memory `PoWpdb`, WC stock stub with a working `'decrease'` op, `chk`/`chk_true`). Write the file:

```php
<?php
/**
 * Apetito PO 'accepted' status + inventory-commit move + cadence
 * (directive DIRECTIVE-apetito-po-accepted-status.md).
 *
 *   A-1..A-4  mark_accepted commits stock once; mark_received is a pure marker
 *   A-5       unaccept reverses the EXACT committed quantities (reason required)
 *   A-6       double-click Accept bumps once
 *   A-7..A-8  po_schedule_from_order_date cadence + off-cycle + invalid
 *
 * Run with: php tests/test-po-accepted-status.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $tag, $v, ...$a) { return $v; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 7; } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('current_time')) { function current_time($fmt) { return gmdate('Y-m-d H:i:s'); } }
if (!function_exists('get_option')) { function get_option($k, $d = '') { return $d; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string) $s))); } }
if (!function_exists('do_action')) { function do_action(string $hook, ...$args) { $GLOBALS['fired_actions'][] = ['hook' => $hook, 'args' => $args]; } }
if (!function_exists('add_action')) { function add_action(string $hook, $cb, int $prio = 10, int $accepted = 1) {} }
$GLOBALS['fired_actions'] = [];
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code; private $message; private $data;
        public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!class_exists('wpdb')) { class wpdb {} }

// In-memory wpdb honoring the guarded-update contract (WHERE status mismatch → 0 rows).
class AcceptWpdb extends wpdb {
    public $prefix = 'wp_'; public $insert_id = 0; public $last_error = '';
    public array $pos = []; public array $audit = []; private int $next_id = 1;
    public function prepare($q, ...$a) {
        if (count($a) === 1 && is_array($a[0])) { $a = $a[0]; }
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $a) {
            $v = $a[$i] ?? ''; $i++;
            return $m[0] === '%s' ? "'" . addslashes((string) $v) . "'" : (string) (int) $v;
        }, $q);
    }
    public function insert($table, $data, $formats = null) {
        if (strpos($table, 'meals_purchase_orders') !== false) {
            $id = $this->next_id++; $data['po_id'] = $id;
            $data += ['reconciled_at' => null];
            $this->pos[$id] = $data; $this->insert_id = $id; return 1;
        }
        $this->insert_id = 1; return 1;
    }
    public function get_row($q, $o = null) {
        if (stripos($q, 'meals_purchase_orders') !== false && preg_match('/po_id = (\d+)/', $q, $m)) { return $this->pos[(int) $m[1]] ?? null; }
        return null;
    }
    public function get_results($q, $o = null) {
        if (stripos($q, 'meals_purchase_orders') !== false) { return array_values($this->pos); }
        return [];
    }
    public function update($table, $data, $where, $df = null, $wf = null) {
        if (strpos($table, 'meals_purchase_orders') !== false) {
            $id = (int) ($where['po_id'] ?? 0);
            if (!isset($this->pos[$id])) { return 0; }
            if (isset($where['status']) && ($this->pos[$id]['status'] ?? '') !== $where['status']) { return 0; }
            foreach ($data as $k => $v) { $this->pos[$id][$k] = $v; }
            return 1;
        }
        return 0;
    }
    public function query($q) { if (stripos($q, 'meals_audit_log') !== false) { $this->audit[] = $q; } return 1; }
}

// WC stock stub — 'decrease' op works (used by unaccept).
class FakeWCProduct2 {
    public int $product_id;
    public function __construct(int $id) { $this->product_id = $id; }
    public function get_stock_quantity() { return $GLOBALS['wc_stock'][$this->product_id]; }
}
$GLOBALS['wc_sku_map'] = ['CD-001' => 101, 'SD-002' => 102];
if (!function_exists('wc_get_product_id_by_sku')) { function wc_get_product_id_by_sku($sku) { return $GLOBALS['wc_sku_map'][$sku] ?? 0; } }
if (!function_exists('wc_get_product')) { function wc_get_product($id) { return isset($GLOBALS['wc_stock'][$id]) ? new FakeWCProduct2($id) : null; } }
if (!function_exists('wc_update_product_stock')) {
    function wc_update_product_stock($product, $qty, $op = 'increase') {
        $id = $product->product_id;
        $GLOBALS['wc_stock'][$id] += ($op === 'increase' ? $qty : -$qty);
        return $GLOBALS['wc_stock'][$id];
    }
}

$failures = []; $passed = 0;
function chk($got, $exp, $label) { global $failures, $passed; if ($got === $exp) { $passed++; } else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); } }
function chk_true($cond, $label) { chk((bool) $cond, true, $label); }
function forecast_rows(): array {
    return [
        ['sku' => 'CD-001', 'product_name' => 'Chicken Dinner', 'weighted_avg_weekly' => 10.0, 'seasonal_index' => 1.1, 'adjusted_weekly' => 11.0, 'projected_need' => 99, 'current_stock' => 40, 'total_available' => 40, 'units_needed' => 59, 'case_size' => 6, 'cases_to_buy' => 10, 'order_quantity' => 60, 'seasonal_note' => '', 'weekly_history' => []],
        ['sku' => 'SD-002', 'product_name' => 'Side Salad', 'weighted_avg_weekly' => 4.0, 'seasonal_index' => 1.0, 'adjusted_weekly' => 4.0, 'projected_need' => 36, 'current_stock' => 20, 'total_available' => 20, 'units_needed' => 16, 'case_size' => 12, 'cases_to_buy' => 2, 'order_quantity' => 24, 'seasonal_note' => '', 'weekly_history' => []],
    ];
}
function fresh_accept(): AcceptWpdb {
    $w = new AcceptWpdb(); $GLOBALS['wpdb'] = $w;
    $GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
    return $w;
}

// ===========================================================================
// A-1..A-4: accept commits stock once; received is a pure marker.
// ===========================================================================
$w = fresh_accept();
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$svc->approve($id);

// A-1: mark_received is UNREACHABLE from placed (only route is via accepted).
chk($svc->mark_received($id)->get_error_code(), 'locked', 'A-1: received unavailable on placed PO');
chk($GLOBALS['wc_stock'][101], 50, 'A-1: no stock change from a refused receive');

// A-2: mark_accepted commits stock exactly once.
$r = $svc->mark_accepted($id);
chk($r, true, 'A-2: mark_accepted succeeds');
$po = $svc->get_with_payload($id);
chk($po['status'], 'accepted', 'A-2: status → accepted');
chk_true(!empty($po['accepted_at']), 'A-2: accepted_at set');
chk((int) $po['accepted_by'], 7, 'A-2: accepted_by = current user');
// CD-001: 10×6=60 onto 50 → 110; SD-002: 2×12=24 onto 20 → 44.
chk($GLOBALS['wc_stock'][101], 110, 'A-2: CD-001 committed at accept');
chk($GLOBALS['wc_stock'][102], 44, 'A-2: SD-002 committed at accept');

// A-3: mark_received now changes NO inventory (the double-count check).
$r = $svc->mark_received($id);
chk($r, true, 'A-3: mark_received succeeds from accepted');
chk($svc->get_with_payload($id)['status'], 'arrived', 'A-3: status → arrived');
chk($GLOBALS['wc_stock'][101], 110, 'A-3: received does NOT re-bump CD-001');
chk($GLOBALS['wc_stock'][102], 44, 'A-3: received does NOT re-bump SD-002');

// A-4: accept is unreachable from anything but placed (double-accept loses guard).
chk($svc->mark_accepted($id)->get_error_code(), 'locked', 'A-4: accept refused once past placed');

// ===========================================================================
// A-5: unaccept reverses the EXACT committed quantities; reason required.
// ===========================================================================
$w = fresh_accept();
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$svc->approve($id);
$svc->mark_accepted($id);
chk($GLOBALS['wc_stock'][101], 110, 'A-5: precondition committed');

chk($svc->unaccept($id, '')->get_error_code(), 'reason_required', 'A-5: empty reason rejected');
chk($svc->unaccept($id, '   ')->get_error_code(), 'reason_required', 'A-5: whitespace reason rejected');
$r = $svc->unaccept($id, 'Vendor cancelled the confirmation');
chk($r, true, 'A-5: unaccept succeeds');
$po = $svc->get_with_payload($id);
chk($po['status'], 'placed', 'A-5: status back to placed (Approved)');
chk($po['accepted_by'], null, 'A-5: accepted_by cleared');
chk($po['accepted_at'], null, 'A-5: accepted_at cleared');
chk($GLOBALS['wc_stock'][101], 50, 'A-5: CD-001 stock reversed to pre-accept');
chk($GLOBALS['wc_stock'][102], 20, 'A-5: SD-002 stock reversed to pre-accept');
chk($svc->unaccept($id, 'again')->get_error_code(), 'locked', 'A-5: unaccept from placed rejected');

// ===========================================================================
// A-6: double-click Accept bumps once (guard on the transition).
// ===========================================================================
$w = fresh_accept();
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
$svc->approve($id);
$svc->mark_accepted($id);
chk($svc->mark_accepted($id)->get_error_code(), 'locked', 'A-6: second accept rejected');
chk($GLOBALS['wc_stock'][101], 110, 'A-6: no double bump on CD-001');

// ===========================================================================
// A-7..A-8: cadence helper.
// ===========================================================================
$s = MealsDB_Purchase_Orders::po_schedule_from_order_date('2026-08-04'); // Tuesday
chk_true(is_array($s), 'A-7: schedule returns array for a valid date');
chk($s['order_date'], '2026-08-04', 'A-7: order_date echoed');
chk($s['inventory_due'], '2026-08-12', 'A-7: inventory due T+8');
chk($s['ship_date'], '2026-08-14', 'A-7: ship T+10');
chk($s['expected_arrival'], '2026-08-17', 'A-7: arrival T+13 (Mon after Fri)');
chk($s['next_order_date'], '2026-09-01', 'A-7: next order T+28');
chk($s['is_off_cycle'], false, 'A-7: Tuesday is on-cycle');

$s2 = MealsDB_Purchase_Orders::po_schedule_from_order_date('2026-08-05'); // Wednesday
chk($s2['is_off_cycle'], true, 'A-8: Wednesday flagged off-cycle');
chk($s2['expected_arrival'], '2026-08-18', 'A-8: off-cycle still derives from its own date (T+13)');
chk(MealsDB_Purchase_Orders::po_schedule_from_order_date('nonsense'), null, 'A-8: invalid date → null');
chk(MealsDB_Purchase_Orders::po_schedule_from_order_date('2026-13-40'), null, 'A-8: impossible date → null');

echo "\n" . $passed . " passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: $f\n"; }
exit(empty($failures) ? 0 : 1);
```

- [ ] **Step 2: Run the new suite**

Run: `php tests/test-po-accepted-status.php`
Expected: `NN passed, 0 failed`.

- [ ] **Step 3: Fix `test-po-draft-lifecycle.php` T-10 for the new flow**

T-10 (lines 404-424) currently expects `mark_received` from `placed` to bump stock. Under the new flow, the bump is at Accept and `mark_received` needs `accepted`. Replace the T-10 body (lines 406-424) with:

```php
$w = fresh();
$GLOBALS['wc_stock'] = [101 => 50, 102 => 20];
$svc = new MealsDB_Purchase_Orders();
$id = $svc->create_draft(forecast_rows());
chk($svc->mark_accepted($id)->get_error_code(), 'locked', 'T-10: accept before approve rejected');
$svc->approve($id);
chk($svc->mark_received($id)->get_error_code(), 'locked', 'T-10: receive before accept rejected');
// Accept commits stock exactly once.
$r = $svc->mark_accepted($id);
chk($r, true, 'T-10: mark_accepted succeeds');
$po = $svc->get_with_payload($id);
chk($po['status'], 'accepted', 'T-10: status → accepted');
chk_true(!empty($po['accepted_at']), 'T-10: accepted_at set');
chk($GLOBALS['wc_stock'][101], 110, 'T-10: CD-001 stock committed at accept');
chk($GLOBALS['wc_stock'][102], 44, 'T-10: SD-002 stock committed at accept');
chk_true(audit_has($w, 'po_accepted'), 'T-10: po_accepted audited');
// Received is now a pure marker: status advances, stock unchanged.
$r = $svc->mark_received($id);
chk($r, true, 'T-10: mark_received succeeds from accepted');
chk($svc->get_with_payload($id)['status'], 'arrived', 'T-10: status → arrived');
chk($GLOBALS['wc_stock'][101], 110, 'T-10: received does not re-bump');
chk_true(audit_has($w, 'po_received'), 'T-10: po_received audited');
// Second receive click: guard loses.
chk($svc->mark_received($id)->get_error_code(), 'locked', 'T-10: double receive rejected');
```

- [ ] **Step 4: Fix `test-po-draft-lifecycle.php` T-11 (insert an Accept before Received)**

In T-11 (lines 462-467), the block drives `approve → mark_received`. Insert an accept between them. Replace lines 462-467:

```php
// mark_accepted commits stock, then mark_received with an explicit arrival date.
chk($svc->mark_accepted($id), true, 'T-11: mark_accepted before receive');
chk(count(fired('mealsdb_po_accepted')), 1, 'T-11: mealsdb_po_accepted fired');
chk($svc->mark_received($id, '2026-07-22'), true, 'T-11: mark_received accepts arrival_date');
$po = $svc->get_with_payload($id);
chk($po['arrival_date'], '2026-07-22', 'T-11: explicit arrival_date stored');
chk(count(fired('mealsdb_po_received')), 1, 'T-11: mealsdb_po_received fired');
chk(fired('mealsdb_po_received')[0]['args'], [$id], 'T-11: received hook args');
```

- [ ] **Step 5: Run the draft-lifecycle suite**

Run: `php tests/test-po-draft-lifecycle.php`
Expected: `NN passed, 0 failed`.

- [ ] **Step 6: Fix `test-po-reconcile-deltas.php` — `arrived_po` inserts Accept**

The `arrived_po` helper (lines 213-220) drives `approve → mark_received`, which now requires accept. Insert an accept call. Change the helper body so the sequence is `create_draft → approve → mark_accepted → mark_received`:

```php
function arrived_po(PoWpdb $w): array {
    $svc = new MealsDB_Purchase_Orders();
    $id  = $svc->create_draft(forecast_rows());
    $svc->approve($id);
    $svc->mark_accepted($id); // stock committed here now (110 / 44)
    $svc->mark_received($id);  // pure marker; stock unchanged
    return [$svc, $id];
}
```

(Verify the exact current lines in that helper before editing; keep whatever `forecast_rows()`/stock reset the file already uses. The only change is adding the `mark_accepted($id)` line before `mark_received($id)`. The post-condition "stock now 110 / 44" comment stays true because accept—not receive—now performs the bump.)

- [ ] **Step 7: Run the reconcile-deltas suite**

Run: `php tests/test-po-reconcile-deltas.php`
Expected: `NN passed, 0 failed` (reconcile deltas apply on top of the accepted quantities exactly as before).

- [ ] **Step 8: Commit**

```bash
git add tests/test-po-accepted-status.php tests/test-po-draft-lifecycle.php tests/test-po-reconcile-deltas.php
git commit -m "test(po): accepted-status suite + update lifecycle/reconcile for the new flow"
```

---

## Task 11: Full-suite sweep + known-fallout note

**Files:** none (verification only)

- [ ] **Step 1: Run every PO + related test**

Run:
```bash
for f in tests/test-po-accepted-status.php tests/test-po-draft-lifecycle.php tests/test-po-reconcile-deltas.php tests/test-po-freight-optimization.php tests/test-po-full-catalog.php tests/test-purchase-order-3week-buffer.php; do echo "== $f =="; php "$f"; done
```
Expected: each prints `... 0 failed`.

- [ ] **Step 2: Confirm the expected task-bridge fallout**

Run:
```bash
php tests/test-po-task-types.php; php tests/test-po-task-bridge.php
```
Expected: FAILURES here are EXPECTED — these drive the to-be-wiped task bridge through `placed → mark_received`, which the new guard blocks by design (directive: task system not in use, will be wiped, compatibility NOT preserved). Do NOT fix them in this branch. Record the exact failing assertions in the PR body and flag for Zak to decide (delete vs. defer to the task-wipe directive).

- [ ] **Step 3: Lint the touched PHP**

Run:
```bash
php -l includes/class-schema.php && php -l includes/services/class-purchase-orders.php && php -l includes/ajax/class-ajax-purchase-orders.php && php -l views/purchase-orders.php
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 4: Commit any stragglers, then hand off**

```bash
git status
```
Expected: clean tree (all work committed across Tasks 1-10).

---

## Verify (maps to directive §Verify — do these on staging with the operator)

1. Draft → Approve → **Accept**: inventory increases by the PO's quantities at **Accept**. 📷 (auto: A-2, T-10)
2. Accept → Mark Received: inventory does **not** change again. 📷 (auto: A-3, T-10) — the double-count check.
3. Mark Received unavailable on a `placed` PO; only route is via Accepted. 📷 (auto: A-1)
4. Un-accept: status → Approved, reason required + logged, stock returns to pre-accept level (exact quantities). 📷 (auto: A-5)
5. Double-click Accept → one bump; double-click Received → no bump. (auto: A-6, T-10)
6. Reconcile after Received still applies deltas on top of the accepted quantities. (auto: reconcile-deltas suite)
7. Cadence: PO dated Tue 2026-08-04 → due 08-12, ship 08-14, arrival 08-17, next 09-01. 📷 (auto: A-7)
8. Off-cycle: a Wednesday PO derives offsets from its own date and is flagged, not blocked. 📷 (auto: A-8)
9. Schema tool reports clean after the ENUM change; `counted` gone, `accepted` present. (manual, on staging after the Data-Ops → Schema Changes ALTER)

## Self-review notes (author)

- **Spec coverage:** Item 1 → Tasks 1-2; Item 2a → Task 4; 2b → Task 4; 2c → Tasks 3+5; 2d (reconcile unchanged) → verified by the reconcile-deltas suite (Task 10 step 6-7); Item 3 → Task 6+8; Item 4 (UI) → Tasks 7-9. "Must NOT change" invariants (transition-before-write ordering, single bump implementation, approve encode guard, reconcile semantics) are all preserved.
- **Single inventory-write path:** kept — `apply_inventory_bump` is the only stock writer; Task 3 makes it symmetric rather than adding a second path (directive constraint honoured in letter and spirit).
- **Type/name consistency:** `mark_accepted`, `unaccept`, `po_schedule_from_order_date`, `STATUS_ACCEPTED`, actions `mealsdb_po_mark_accepted` / `mealsdb_po_unaccept`, data-po-action `accept` / `unaccept` — used identically across service, AJAX, view, JS, and tests.
- **Open decision for handoff:** the two task-bridge test files (Task 11 step 2). Everything else is deterministic.
