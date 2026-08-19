# Weekly Order Audit — Add Item, SKU column, sort by last name Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the operator add an unexpected shipped item to a weekly-audit row, surface each item's SKU, and sort the audit table by client last name — with **zero inventory effect**.

**Architecture:** Added items live in a NEW `added_items` payload key (never through `edited_items`, whose keys are WC `order_item_id`s). `edit_row()` gains an `$added` param that replaces the row's added-items in the same `mutate_row()` pass (draft-only, concurrency-guarded), validating each product against the Quick Order catalogue and resolving name/SKU server-side. SKU is resolved for snapshot items in the non-pure caller (`build_week_rows`) from the same QO catalogue and passed into the pure builder as a `pid→sku` map. Sorting adds `client_last_name` to the snapshot.

**Tech Stack:** PHP 8.2 / WordPress admin AJAX / vanilla-jQuery. Tests are the repo's in-memory-`wpdb` harness run with `php tests/<file>.php`.

**Source directive:** `directives/DIRECTIVE-order-audit-add-item-sku-sort.md` (baseline v1.0.553).

**Files:**
- Modify: `includes/services/class-order-audit.php` (snapshot builder + `build_week_rows` + `edit_row` + `confirm_row` + `revert_row`)
- Modify: `includes/ajax/class-ajax-order-audit.php` (`edit()` parses `added`; new `products` endpoint)
- Modify: `includes/admin/class-order-audit-page.php` (SKU column, editor SKU + added-item rendering, Add Item button, sort)
- Modify: `assets/js/order-audit.js` (Add Item / remove; collect added on save; product dropdown)
- Test (modify): `tests/test-order-audit.php`

### Two corrections to the directive (confirmed against the code)
1. **SKU source.** The directive says resolve snapshot SKU "against `meals_products.sku`". `meals_products` has **no `sku` column** (`MealsDB_Products::get_product_data` selects `wc_product_id, product_type, taxable, …` — no SKU); SKU lives in WooCommerce. And `build_rows_from_orders()` is documented **pure, no DB access**. Resolution: build a `pid→sku` map in `build_week_rows()` (non-pure caller) from `MealsDB_Quick_Order_Products::get_all_quick_order_products()` (which carries `product_id` + `sku` from WC `get_sku()`) and pass it into the builder. Added items resolve SKU from the same catalogue — one source, no divergence.
2. **`added_items` reset paths.** The directive points at "the revert path (~389)" — line 389 is inside `confirm_row()`. There are **two** clear paths: `confirm_row()` (~389) and `revert_row()` (~455). Both must reset `added_items`, plus the snapshot builder init. Missing `revert_row()` would strand added items on a reverted row.

### Interpretation call (SKU column on a per-order row)
Each table row is one ORDER (multiple items, multiple SKUs). The SKU column renders the order's item SKUs joined by `, ` (blank for legacy snapshots). The editor shows each item's SKU next to its name (the directive's explicit ask). Flagged in the plan because the directive says "a SKU column" without resolving one-row-many-SKUs.

### Product dropdown choice
A native `<select>` populated ONCE from a new audit-side products endpoint (each `<option value="{product_id}" data-sku>{name} ({sku})</option>`), searchable via the browser's built-in type-ahead — reusing the QO product catalogue, not a new search, per the directive. Live keystroke typeahead is intentionally out of scope; the endpoint exists if wanted later.

---

## Task 1: Snapshot — `client_last_name`, per-item `sku`, `added_items` init

**Files:**
- Modify: `includes/services/class-order-audit.php` (`build_week_rows` ~55-76; `build_rows_from_orders` signature ~125 + item build ~174 + row build ~187)
- Test: `tests/test-order-audit.php`

- [ ] **Step 1: Write the failing test (snapshot carries sku + client_last_name)**

In `tests/test-order-audit.php`, after the existing `3.1` block (after line 182), add:

```php
// 3.1c: SKU resolves from the pid→sku map; client_last_name captured; added_items init [].
oa_reset();
$sku_map = [100 => 'BEEF-100', 200 => 'SIDE-200'];
$rows = MealsDB_Order_Audit::build_rows_from_orders(oa_orders(), oa_clients(), $sku_map);
oa_chk(($rows[501]['items'][0]['sku'] ?? null) === 'BEEF-100', '3.1c: item sku from map');
oa_chk(($rows[501]['items'][1]['sku'] ?? null) === 'SIDE-200', '3.1c: second item sku from map');
oa_chk(($rows[502]['items'][0]['sku'] ?? null) === 'BEEF-100', '3.1c: sku resolved across orders');
oa_chk(($rows[501]['client_last_name'] ?? null) === 'Doe', '3.1c: client_last_name captured');
oa_chk(($rows[501]['added_items'] ?? null) === [], '3.1c: added_items initialised empty');
// A pid absent from the map yields an empty sku, not an error.
$rows_no_map = MealsDB_Order_Audit::build_rows_from_orders(oa_orders(), oa_clients());
oa_chk(($rows_no_map[501]['items'][0]['sku'] ?? 'x') === '', '3.1c: missing map → empty sku');
```

- [ ] **Step 2: Run it — fails**

Run: `php tests/test-order-audit.php`
Expected: FAIL on `3.1c` (sku/client_last_name/added_items absent; third arg ignored).

- [ ] **Step 3: Extend the builder signature + docblock**

Change `build_rows_from_orders` (line 125) and its docblock. The signature becomes:

```php
    /**
     * @param array<int, array<string, mixed>> $orders  Orders from get_orders_for_delivery_range().
     * @param array<int, array<string, mixed>> $clients Clients keyed by wp_user_id.
     * @param array<int, string>               $sku_by_pid Optional wc_product_id => SKU map, resolved
     *        by the caller (this method stays pure / no DB). Missing pid → ''.
     * @return array<int, array<string, mixed>> Rows keyed by order_id.
     */
    public static function build_rows_from_orders(array $orders, array $clients, array $sku_by_pid = []): array {
```

- [ ] **Step 4: Capture `sku` per item**

Change the item push (lines 174-178) to include sku from the map:

```php
                $items[] = [
                    'item_key'     => (int) ($item['order_item_id'] ?? 0),
                    'product_name' => (string) ($item['order_item_name'] ?? ''),
                    'sku'          => (string) ($sku_by_pid[$pid] ?? ''),
                    'qty'          => $qty,
                ];
```

- [ ] **Step 5: Capture `client_last_name` + init `added_items`**

Change the row build (lines 187-202) to add two keys:

```php
            $rows[$oid] = [
                'order_id'        => $oid,
                'wp_user_id'      => $uid,
                'client_id'       => (int) ($client['client_id'] ?? 0),
                'client_name'     => trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? '')),
                'client_last_name'=> (string) ($client['last_name'] ?? ''),
                'zone'            => (string) ($client['delivery_area_zone'] ?? ''),
                'delivery_date'   => $delivery_date,
                'items'           => $items,
                'mains_count'     => $mains,
                'sides_count'     => $sides,
                'audit_status'    => self::ROW_PENDING,
                'edited_items'    => [],
                'added_items'     => [],
                'note'            => '',
                'audited_by'      => 0,
                'audited_at'      => '',
            ];
```

- [ ] **Step 6: Build the `pid→sku` map in `build_week_rows` and pass it**

Change the call at line 71:

```php
            $orders = $generator->get_orders_for_delivery_range($clients, $week_start, $week_end);

            // SKU is NOT in meals_clients/meals_products; resolve it from the QO
            // product catalogue (product_id => WC SKU) and hand it to the pure
            // builder so build_rows_from_orders stays DB-free. Added items use the
            // same catalogue, so snapshot and added SKUs share one source.
            $sku_by_pid = [];
            if (class_exists('MealsDB_Quick_Order_Products')) {
                foreach (MealsDB_Quick_Order_Products::get_all_quick_order_products() as $p) {
                    $pid = (int) ($p['product_id'] ?? 0);
                    if ($pid > 0) {
                        $sku_by_pid[$pid] = (string) ($p['sku'] ?? '');
                    }
                }
            }

            return self::build_rows_from_orders($orders, $clients, $sku_by_pid);
```

- [ ] **Step 7: Run — passes**

Run: `php tests/test-order-audit.php`
Expected: `3.1c` passes; all prior checks still pass.

- [ ] **Step 8: Commit**

```bash
git add includes/services/class-order-audit.php tests/test-order-audit.php
git commit -m "feat(order-audit): capture per-item SKU + client_last_name + added_items in snapshot"
```

---

## Task 2: Reset `added_items` in confirm + revert

**Files:**
- Modify: `includes/services/class-order-audit.php` (`confirm_row` ~388-390; `revert_row` ~454-456)
- Test: `tests/test-order-audit.php`

- [ ] **Step 1: Add the failing assertion**

After the edit tests in `tests/test-order-audit.php` (locate the `4.3` edit block ~line 276), append:

```php
// 4.7: confirm and revert both clear added_items (no orphan added lines).
oa_reset();
$id = MealsDB_Order_Audit::create_for_week('2026-07-20', '2026-07-26');
MealsDB_Order_Audit::edit_row($id, 501, [1 => 5, 2 => 3], 'extra sent', [['product_id' => 200, 'qty' => 2]]);
$a = MealsDB_Order_Audit::get($id);
oa_chk(count($a['payload']['current'][501]['added_items']) === 1, '4.7: added item persisted pre-confirm');
MealsDB_Order_Audit::confirm_row($id, 501);
$a = MealsDB_Order_Audit::get($id);
oa_chk(($a['payload']['current'][501]['added_items'] ?? 'x') === [], '4.7: confirm clears added_items');
// Re-add, then revert.
MealsDB_Order_Audit::edit_row($id, 501, [1 => 5], '', [['product_id' => 200, 'qty' => 1]]);
MealsDB_Order_Audit::revert_row($id, 501);
$a = MealsDB_Order_Audit::get($id);
oa_chk(($a['payload']['current'][501]['added_items'] ?? 'x') === [], '4.7: revert clears added_items');
```

- [ ] **Step 2: Run — fails**

Run: `php tests/test-order-audit.php`
Expected: FAIL on `4.7` (added_items not cleared; also `edit_row` 5-arg call not yet supported — that lands in Task 3, so this test currently errors on the extra arg. That is expected; it passes once Tasks 2 AND 3 are done. Proceed.)

- [ ] **Step 3: Clear `added_items` in `confirm_row`**

In `confirm_row()`, the confirm branch (lines 388-390) clears `edited_items`; add `added_items`:

```php
                $row['audit_status'] = self::ROW_CONFIRMED;
                $row['edited_items'] = [];
                $row['added_items']  = [];
                $row['note']         = '';
```

- [ ] **Step 4: Clear `added_items` in `revert_row`**

In `revert_row()` (lines 454-456):

```php
            $row['audit_status'] = self::ROW_PENDING;
            $row['edited_items'] = [];
            $row['added_items']  = [];
            $row['note']         = '';
```

- [ ] **Step 5: Defer running to Task 3 (needs the 5-arg `edit_row`)**

`4.7` depends on Task 3's `edit_row` signature. Continue to Task 3, then run.

- [ ] **Step 6: Commit**

```bash
git add includes/services/class-order-audit.php tests/test-order-audit.php
git commit -m "fix(order-audit): reset added_items on confirm and revert (both paths)"
```

---

## Task 3: `edit_row()` gains `$added` — validated, SKU/name from catalogue, audit-logged

**Files:**
- Modify: `includes/services/class-order-audit.php` (`edit_row` ~407-449)
- Test: `tests/test-order-audit.php`

- [ ] **Step 1: Write failing validation tests**

Append to `tests/test-order-audit.php` after the `4.7` block:

```php
// 4.8: add_item validation + persistence + audit-log token.
oa_reset();
$id = MealsDB_Order_Audit::create_for_week('2026-07-20', '2026-07-26');
$wpdb = $GLOBALS['wpdb'];
$before = count($wpdb->audit_log);
$res = MealsDB_Order_Audit::edit_row($id, 501, [], 'boxed two extra salads', [['product_id' => 200, 'qty' => 2]]);
oa_chk($res === true, '4.8: edit_row accepts a valid added item');
$a = MealsDB_Order_Audit::get($id);
$added = $a['payload']['current'][501]['added_items'];
oa_chk(count($added) === 1 && (int) $added[0]['product_id'] === 200, '4.8: added item stored');
oa_chk((int) $added[0]['qty'] === 2, '4.8: added qty stored');
oa_chk($added[0]['sku'] === 'SIDE-200', '4.8: sku resolved server-side from catalogue');
oa_chk($added[0]['product_name'] === 'Catalog Side', '4.8: product_name resolved server-side (not client-supplied)');
oa_chk($a['payload']['current'][501]['audit_status'] === 'edited', '4.8: row marked edited by an add');
oa_chk(count($wpdb->audit_log) === $before + 1, '4.8: one audit-log row for the edit');
oa_chk(strpos((string) $wpdb->audit_log[$before], '+200:2') !== false, '4.8: audit log carries +pid:qty, no PII');

// Validation: unknown product, bad qty, oversized note.
oa_chk(MealsDB_Order_Audit::edit_row($id, 501, [], '', [['product_id' => 999999, 'qty' => 1]]) instanceof WP_Error,
    '4.8: unknown product_id rejected');
oa_chk(MealsDB_Order_Audit::edit_row($id, 501, [], '', [['product_id' => 200, 'qty' => 0]]) instanceof WP_Error,
    '4.8: qty < 1 rejected');
oa_chk(MealsDB_Order_Audit::edit_row($id, 501, [], str_repeat('x', 501), []) instanceof WP_Error,
    '4.8: note over cap still rejected');

// Replace semantics: a second save with a different list REPLACES, not appends.
MealsDB_Order_Audit::edit_row($id, 501, [], '', [['product_id' => 100, 'qty' => 1]]);
$a = MealsDB_Order_Audit::get($id);
oa_chk(count($a['payload']['current'][501]['added_items']) === 1
    && (int) $a['payload']['current'][501]['added_items'][0]['product_id'] === 100,
    '4.8: added_items is replaced (full set), not appended');
// Empty list clears them.
MealsDB_Order_Audit::edit_row($id, 501, [], '', []);
oa_chk(MealsDB_Order_Audit::get($id)['payload']['current'][501]['added_items'] === [],
    '4.8: empty added list clears added_items');
```

- [ ] **Step 2: Add the catalogue stub to the test harness**

Near the top of `tests/test-order-audit.php`, after the other function stubs (after the `has_term` stub ~line 23), add a fake QO catalogue so the service can validate/resolve without WooCommerce:

```php
// Fake QO product catalogue for add-item validation (pid => name/sku).
if (!class_exists('MealsDB_Quick_Order_Products')) {
    class MealsDB_Quick_Order_Products {
        public static function get_all_quick_order_products(): array {
            return [
                ['product_id' => 100, 'name' => 'Catalog Beef', 'sku' => 'BEEF-100'],
                ['product_id' => 200, 'name' => 'Catalog Side', 'sku' => 'SIDE-200'],
            ];
        }
    }
}
```

- [ ] **Step 3: Extend `edit_row()`**

Replace the `edit_row()` signature + body (lines 407-449) with a version that takes `$added`, validates it against the catalogue, resolves name/SKU server-side, and folds added tokens into the audit-log line. Full method:

```php
    public static function edit_row(int $audit_id, int $order_id, array $qtys, string $note, array $added = []) {
        $note = trim($note);
        if (function_exists('mb_strlen') ? mb_strlen($note) > self::MAX_NOTE_LEN : strlen($note) > self::MAX_NOTE_LEN) {
            return new WP_Error('note_too_long', __('Note is too long (500 characters max).', 'meals-db'));
        }

        // Resolve + validate the added items against the QO catalogue BEFORE the
        // mutation. product_name / sku are taken from the catalogue, never the
        // client (same regenerate-server-side posture as Quick Order). The list
        // REPLACES added_items wholesale, so a removed line is simply absent.
        $catalogue = [];
        if (class_exists('MealsDB_Quick_Order_Products')) {
            foreach (MealsDB_Quick_Order_Products::get_all_quick_order_products() as $p) {
                $pid = (int) ($p['product_id'] ?? 0);
                if ($pid > 0) {
                    $catalogue[$pid] = [
                        'product_name' => (string) ($p['name'] ?? ''),
                        'sku'          => (string) ($p['sku'] ?? ''),
                    ];
                }
            }
        }
        $clean_added = [];
        foreach ($added as $entry) {
            if (!is_array($entry)) {
                return new WP_Error('bad_added', __('Malformed added item.', 'meals-db'));
            }
            $pid = (int) ($entry['product_id'] ?? 0);
            $qty = (int) ($entry['qty'] ?? 0);
            if (!isset($catalogue[$pid])) {
                return new WP_Error('unknown_product', __('Added item is not a known product.', 'meals-db'));
            }
            if ($qty < 1) {
                return new WP_Error('bad_added_qty', __('Added item quantity must be at least 1.', 'meals-db'));
            }
            $clean_added[] = [
                'product_id'   => $pid,
                'sku'          => $catalogue[$pid]['sku'],
                'product_name' => $catalogue[$pid]['product_name'],
                'qty'          => $qty,
            ];
        }

        $deltas = [];
        $result = self::mutate_row($audit_id, $order_id, static function (array $row) use ($qtys, $note, $clean_added, &$deltas) {
            $known = [];
            foreach ($row['items'] as $item) {
                $known[(int) $item['item_key']] = (int) $item['qty'];
            }
            $clean = [];
            foreach ($qtys as $key => $qty) {
                $key = (int) $key;
                $qty = (int) $qty;
                if (!array_key_exists($key, $known)) {
                    return new WP_Error('unknown_item', __('Unknown order item.', 'meals-db'));
                }
                if ($qty < 0) {
                    return new WP_Error('bad_qty', __('Quantities must be zero or more.', 'meals-db'));
                }
                $clean[$key] = $qty;
                if ($qty !== $known[$key]) {
                    $deltas[] = $key . ':' . $known[$key] . '→' . $qty;
                }
            }
            foreach ($clean_added as $a) {
                $deltas[] = '+' . $a['product_id'] . ':' . $a['qty'];
            }
            $row['audit_status'] = self::ROW_EDITED;
            $row['edited_items'] = $clean;
            $row['added_items']  = $clean_added;
            $row['note']         = $note;
            $row['audited_by']   = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            $row['audited_at']   = gmdate('Y-m-d H:i:s');
            return $row;
        });
        if ($result instanceof WP_Error) {
            return $result;
        }
        // Deltas only — item keys / product ids and counts, no PII. log_lifecycle
        // isolates the write: a broken audit-log backend must not make a
        // successfully stored edit report failure.
        self::log_lifecycle('order_audit_row_edited', $audit_id, 'order_' . $order_id,
            null, implode(', ', $deltas) . ($note !== '' ? ' (note)' : ''));
        return true;
    }
```

- [ ] **Step 4: Update the `edit_row` docblock**

Change the docblock above the method (lines 398-406) to note the `$added` param:

```php
    /**
     * Record a discrepancy: adjusted per-item quantities, a note, and/or items
     * that were shipped but not on the original order ($added). Quantities are a
     * map item_key => received qty for the items being changed; $added is the
     * FULL desired list of extra items (replaces the row's added_items). Product
     * name + SKU for added items are resolved server-side from the QO catalogue,
     * never trusted from the client. Edits ARE the audit's reason to exist, so
     * each is audit-logged with its deltas (added items as +product_id:qty).
     *
     * NO inventory effect (directive: the weekly audit never touches stock).
     *
     * @param array<int,int>                 $qtys  item_key => received qty (>= 0)
     * @param array<int,array<string,mixed>> $added list of ['product_id'=>int,'qty'=>int]
     * @return true|WP_Error
     */
```

- [ ] **Step 5: Run — Tasks 2 + 3 tests pass**

Run: `php tests/test-order-audit.php`
Expected: `4.7` and `4.8` pass; all prior checks still pass.

- [ ] **Step 6: Commit**

```bash
git add includes/services/class-order-audit.php tests/test-order-audit.php
git commit -m "feat(order-audit): edit_row accepts added items, validated + SKU-resolved server-side"
```

---

## Task 4: AJAX — parse `added`; add the products endpoint

**Files:**
- Modify: `includes/ajax/class-ajax-order-audit.php` (`init` ~35-41; `edit()` ~124-144; add `products()` + register)
- Test: `tests/test-ajax-order-audit.php` (smoke — optional; the service tests cover validation)

- [ ] **Step 1: Register the products endpoint**

In `init()` after the `delete` registration (line 41):

```php
        add_action('wp_ajax_mealsdb_order_audit_delete',     [__CLASS__, 'delete_draft']);
        add_action('wp_ajax_mealsdb_order_audit_products',   [__CLASS__, 'products']);
```

- [ ] **Step 2: Parse `added` in `edit()`**

In `edit()`, after building `$qtys` (line 133), add parsing of the `added` array and pass it to `edit_row`:

```php
            $qtys     = [];
            foreach ((array) wp_unslash($_POST['qtys'] ?? []) as $k => $v) {
                $qtys[absint($k)] = (int) $v;
            }
            // Added items: product_id + qty only. Name/SKU are resolved
            // server-side in edit_row from the catalogue — anything the client
            // sends for those is ignored. qty passes through as (int) so the
            // service's own >= 1 rejection fires (not silently clamped).
            $added = [];
            foreach ((array) wp_unslash($_POST['added'] ?? []) as $entry) {
                if (!is_array($entry)) { continue; }
                $added[] = [
                    'product_id' => absint($entry['product_id'] ?? 0),
                    'qty'        => (int) ($entry['qty'] ?? 0),
                ];
            }
            $result = MealsDB_Order_Audit::edit_row($audit_id, $order_id, $qtys, $note, $added);
```

- [ ] **Step 3: Add the `products()` handler**

After `delete_draft()` (line 222), add a read endpoint returning the QO catalogue (id, name, sku) for the dropdown. It reuses the same guard (read is fine on `order_audit_edit`'s bucket — or use a lighter existing read bucket; `order_audit_edit` is acceptable and keeps one bucket):

```php
    /**
     * Product catalogue for the Add-Item dropdown: id + name + SKU only, from
     * the shared QO product cache (no new product search). Read-only.
     */
    public static function products(): void {
        if (!self::guard('order_audit_edit')) { return; }
        try {
            $out = [];
            if (class_exists('MealsDB_Quick_Order_Products')) {
                foreach (MealsDB_Quick_Order_Products::get_all_quick_order_products() as $p) {
                    $pid = (int) ($p['product_id'] ?? 0);
                    if ($pid <= 0) { continue; }
                    $out[] = [
                        'product_id' => $pid,
                        'name'       => (string) ($p['name'] ?? ''),
                        'sku'        => (string) ($p['sku'] ?? ''),
                    ];
                }
            }
            wp_send_json_success(['products' => $out]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Order_Audit AJAX] products failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to load products. Please try again.', 'meals-db')]);
        }
    }
```

- [ ] **Step 4: Lint**

Run: `php -l includes/ajax/class-ajax-order-audit.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add includes/ajax/class-ajax-order-audit.php
git commit -m "feat(order-audit): AJAX parses added items + serves the product catalogue"
```

---

## Task 5: Page — SKU column, editor SKU + added items, Add Item button, sort by last name

**Files:**
- Modify: `includes/admin/class-order-audit-page.php` (sort ~221-229; header ~232-243 + colspans; `render_row` item loop ~335-352 + button row ~358-365)

- [ ] **Step 1: Sort by last name (fallback to trailing word of client_name)**

Replace the sort comparator (lines 221-229):

```php
        // Sort alphabetically by client last name (directive item 3). Legacy
        // snapshots lack client_last_name → fall back to the trailing word of
        // client_name so the page still sorts. client_name then delivery_date
        // break ties for same-surname clients.
        $last_of = static function (array $r): string {
            $ln = trim((string) ($r['client_last_name'] ?? ''));
            if ($ln === '') {
                $parts = preg_split('/\s+/', trim((string) ($r['client_name'] ?? '')));
                $ln = $parts ? (string) end($parts) : '';
            }
            return function_exists('mb_strtolower') ? mb_strtolower($ln) : strtolower($ln);
        };
        usort($rows, static function ($a, $b) use ($last_of) {
            $c = strcmp($last_of($a), $last_of($b));
            if ($c !== 0) { return $c; }
            $c = strcmp((string) ($a['client_name'] ?? ''), (string) ($b['client_name'] ?? ''));
            if ($c !== 0) { return $c; }
            return strcmp((string) ($a['delivery_date'] ?? ''), (string) ($b['delivery_date'] ?? ''));
        });
```

- [ ] **Step 2: Add the SKU column header + fix colspans**

Add a SKU `<th>` after Order # (line 237):

```php
        echo '<th>' . esc_html__('Order #', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('SKU', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Mains', 'meals-db') . '</th>';
```

The table is now 9 columns. Update the empty-state colspan (line 246) from `8` to `9`:

```php
            echo '<tr><td colspan="9"><em>'
```

- [ ] **Step 3: Render the SKU cell on each order row**

In `render_row()`, after the Order # cell (line 300), add a SKU cell listing the order's item SKUs (blank for legacy):

```php
        echo '<td>' . esc_html((string) $order_id) . '</td>';
        $skus = [];
        foreach ($items as $it) {
            $s = is_array($it) ? trim((string) ($it['sku'] ?? '')) : '';
            if ($s !== '') { $skus[] = $s; }
        }
        echo '<td class="oa-sku">' . esc_html(implode(', ', $skus)) . '</td>';
```

- [ ] **Step 4: Update the editor row colspan**

The editor row spans the full table (line 333). Change `colspan="8"` to `colspan="9"`:

```php
        echo '<tr class="oa-editor-row" data-order-id="' . esc_attr((string) $order_id)
            . '" style="display:none;"><td colspan="9">';
```

- [ ] **Step 5: Show SKU next to each editor item, and render saved added items**

Replace the editor items loop (lines 335-353) so each snapshot item shows its SKU, and previously-saved added items render (distinctly labelled, removable). Use a data attribute the JS reads:

```php
        echo '<div class="oa-editor-items">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_key = (int) ($item['item_key'] ?? 0);
            $qty      = (int) ($item['qty'] ?? 0);
            $isku     = (string) ($item['sku'] ?? '');
            $value    = array_key_exists($item_key, $edited) ? (int) $edited[$item_key] : $qty;
            echo '<label style="display:block;margin:4px 0;">';
            echo esc_html((string) ($item['product_name'] ?? ''));
            if ($isku !== '') {
                echo ' <span class="description oa-item-sku">' . esc_html($isku) . '</span>';
            }
            echo ' <input type="number" min="0" class="oa-qty" data-item-key="' . esc_attr((string) $item_key) . '" '
                . 'value="' . esc_attr((string) $value) . '" />';
            echo ' <span class="description">' . esc_html(sprintf(
                /* translators: %d: quantity delivered */
                __('(delivered: %d)', 'meals-db'),
                $qty
            )) . '</span>';
            echo '</label>';
        }
        echo '</div>';

        // Added items (shipped but not on the original order). Rendered into a
        // distinct container the JS appends new rows to; each carries product_id
        // so save can round-trip it. Visually marked as added.
        $added_items = (isset($row['added_items']) && is_array($row['added_items'])) ? $row['added_items'] : [];
        echo '<div class="oa-editor-added" style="margin:4px 0;">';
        foreach ($added_items as $ai) {
            if (!is_array($ai)) { continue; }
            $apid  = (int) ($ai['product_id'] ?? 0);
            $aqty  = (int) ($ai['qty'] ?? 1);
            $aname = (string) ($ai['product_name'] ?? '');
            $asku  = (string) ($ai['sku'] ?? '');
            echo '<div class="oa-added-line" data-product-id="' . esc_attr((string) $apid) . '" style="margin:3px 0;">';
            echo '<span class="oa-added-label" style="color:#8a6d00;">'
                . esc_html__('added — not on original order', 'meals-db') . '</span> ';
            echo esc_html($aname);
            if ($asku !== '') { echo ' <span class="description oa-item-sku">' . esc_html($asku) . '</span>'; }
            echo ' <input type="number" min="1" class="oa-added-qty" value="' . esc_attr((string) $aqty) . '" style="width:70px;" />';
            echo ' <button type="button" class="button-link oa-added-remove" aria-label="'
                . esc_attr__('Remove added item', 'meals-db') . '">&times;</button>';
            echo '</div>';
        }
        echo '</div>';
```

- [ ] **Step 6: Add the Add Item button to the editor button row**

In the button `<p>` (lines 358-365), add Add Item before Save:

```php
        echo '<p>';
        echo '<button type="button" class="button oa-editor-add-item">'
            . esc_html__('Add Item', 'meals-db') . '</button> ';
        echo '<button type="button" class="button button-primary oa-editor-save">'
            . esc_html__('Save', 'meals-db') . '</button> ';
        echo '<button type="button" class="button oa-editor-revert">'
            . esc_html__('Revert to pending', 'meals-db') . '</button> ';
        echo '<button type="button" class="button oa-editor-cancel">'
            . esc_html__('Cancel', 'meals-db') . '</button>';
        echo '</p>';
```

(Add Item is only rendered inside the editor row, which only renders when `$editable` — so it is automatically absent on a finalized audit, matching the directive's "Add Item must be unavailable on a finalized audit". No extra guard needed.)

- [ ] **Step 7: Lint**

Run: `php -l includes/admin/class-order-audit-page.php`
Expected: `No syntax errors detected`.

- [ ] **Step 8: Commit**

```bash
git add includes/admin/class-order-audit-page.php
git commit -m "feat(order-audit): SKU column, Add Item control, added-item rendering, sort by last name"
```

---

## Task 6: JS — Add Item / remove line; collect added on save; product dropdown

**Files:**
- Modify: `assets/js/order-audit.js` (add product-catalogue fetch + Add Item handlers; extend `.oa-editor-save`)

- [ ] **Step 1: Fetch the catalogue once and cache it**

After the `post()` helper (line 38), add a lazy catalogue loader:

```php
    // Product catalogue for Add-Item, fetched once and cached. Each entry is
    // {product_id, name, sku}. On failure the Add-Item control alerts and no-ops.
    var _catalogue = null;
    function withCatalogue(cb) {
        if (_catalogue) { cb(_catalogue); return; }
        post('mealsdb_order_audit_products', {}, function (d) {
            _catalogue = (d && d.products) || [];
            cb(_catalogue);
        });
    }
```

(Note: this block is JS, not PHP — the fence label is irrelevant; paste as-is into the `.js` file.)

- [ ] **Step 2: Add Item — append an editable added line**

After the `.oa-editor-cancel` handler (line 143), add:

```javascript
        // --- Add Item: append a product picker + qty + remove to the editor ---
        $('#oa-grid').on('click', '.oa-editor-add-item', function () {
            var $added = $(this).closest('.oa-editor-row').find('.oa-editor-added');
            withCatalogue(function (products) {
                var $line = $('<div class="oa-added-line" data-product-id="0" style="margin:3px 0;"></div>');
                var $sel = $('<select class="oa-added-select"></select>');
                $sel.append('<option value="0">' + esc(i18n.selectProduct || 'Select a product…') + '</option>');
                products.forEach(function (p) {
                    var label = p.name + (p.sku ? ' (' + p.sku + ')' : '');
                    $('<option></option>').attr('value', p.product_id).attr('data-sku', p.sku || '')
                        .text(label).appendTo($sel);
                });
                $sel.on('change', function () {
                    $line.attr('data-product-id', String(parseInt($(this).val(), 10) || 0));
                });
                var $qty = $('<input type="number" min="1" class="oa-added-qty" value="1" style="width:70px;" />');
                var $rm = $('<button type="button" class="button-link oa-added-remove">&times;</button>');
                $line.append('<span class="oa-added-label" style="color:#8a6d00;">'
                    + esc(i18n.addedLabel || 'added — not on original order') + '</span> ');
                $line.append($sel).append(' ').append($qty).append(' ').append($rm);
                $added.append($line);
            });
        });

        // --- Remove an added line (unsaved or saved; persistence is on Save) ---
        $('#oa-grid').on('click', '.oa-added-remove', function () {
            $(this).closest('.oa-added-line').remove();
        });
```

- [ ] **Step 3: Add an `esc()` helper if not present**

If `order-audit.js` has no HTML-escape helper (the file mostly uses `.text()`), add one near the top helpers (after `auditId()`):

```javascript
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
```

(Options are built with `.text()`/`.attr()` above, so `esc()` is only used for the two fixed i18n labels — still safe to include.)

- [ ] **Step 4: Collect added items in the save handler**

Extend the `.oa-editor-save` handler (lines 104-127) to gather added lines and send them:

```javascript
        $('#oa-grid').on('click', '.oa-editor-save', function () {
            var $editor = $(this).closest('.oa-editor-row');
            var orderId = $editor.data('order-id');
            var qtys = {};
            $editor.find('.oa-qty').each(function () {
                var key = $(this).data('item-key');
                var raw = parseInt($(this).val(), 10);
                qtys[key] = isNaN(raw) ? 0 : raw;
            });
            var added = [];
            $editor.find('.oa-added-line').each(function () {
                var pid = parseInt($(this).attr('data-product-id'), 10) || 0;
                if (pid <= 0) { return; } // an un-picked new line is skipped
                var q = parseInt($(this).find('.oa-added-qty').val(), 10);
                added.push({ product_id: pid, qty: isNaN(q) ? 1 : q });
            });
            var note = $editor.find('.oa-note').val() || '';
            post('mealsdb_order_audit_edit', {
                audit_id: auditId(),
                order_id: orderId,
                qtys: qtys,
                note: note,
                added: added
            }, function (d) {
                var $row = auditRow(orderId);
                applyRowStatus($row, 'edited');
                $row.find('.oa-delta').show();
                applyNoteIcon($row, note);
                $editor.hide();
                updateProgress(d);
            });
        });
```

- [ ] **Step 5: Add the i18n strings the JS references**

In `includes/admin/class-order-audit-page.php`, add two keys to the `i18n` array in `enqueue_scripts()` (after `errorGeneric`, ~line 86):

```php
                'errorGeneric'     => __('Something went wrong. Please try again.', 'meals-db'),
                'selectProduct'    => __('Select a product…', 'meals-db'),
                'addedLabel'       => __('added — not on original order', 'meals-db'),
```

- [ ] **Step 6: Syntax check**

Run: `node --check assets/js/order-audit.js && php -l includes/admin/class-order-audit-page.php`
Expected: JS exits 0; PHP `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add assets/js/order-audit.js includes/admin/class-order-audit-page.php
git commit -m "feat(order-audit): client Add Item / remove, product dropdown, added-item save"
```

---

## Task 7: Full sweep

**Files:** none (verification only)

- [ ] **Step 1: Run the order-audit test suites**

Run:
```bash
for f in test-order-audit test-ajax-order-audit test-order-audit-rate-bucket test-order-audit-schema test-derived-value-audit-correction; do echo "== $f =="; php "tests/$f.php" 2>&1 | tail -3; done
```
Expected: each reports 0 failures.

- [ ] **Step 2: Lint everything touched**

Run:
```bash
php -l includes/services/class-order-audit.php && php -l includes/ajax/class-ajax-order-audit.php && php -l includes/admin/class-order-audit-page.php && node --check assets/js/order-audit.js
```
Expected: clean.

- [ ] **Step 3: Confirm the tree is clean**

Run: `git status`
Expected: all work committed.

---

## Verify (maps to directive §Verify — do on staging with the operator)

1. Draft audit → Add Item on a row → pick product, qty 2, Save → row shows **Edited**; reopen shows the added item with SKU. (auto: 4.8; manual 📷)
2. **Inventory unchanged** by the add — no code path touches stock (the service has no inventory call; grep confirms). READ-ONLY before/after on staging. 📷 **Most important.**
3. Add then remove before save → nothing persists; add/save/reopen/remove/save → gone. (auto: 4.8 replace/clear; manual 📷)
4. `mains_count`/`sides_count` unchanged by an add; Δ shows. (snapshot counts never recomputed; manual 📷)
5. SKU column populates on a newly created audit; older draft shows blank SKUs, no error. (auto: 3.1c missing-map → ''; manual 📷)
6. Rows sort by last name — compound surname (Avery-Jones) and multi-word first name (Joseph Roger Cormier) land correctly. (manual 📷)
7. Finalize → Add Item absent (editor row only renders when `$editable`); existing added items still display in read-only. 📷
8. Quantity edits still work (regression). (auto: existing 4.3 + 4.8; manual 📷)
9. Audit log shows the add as `+product_id:qty`, no PII. (auto: 4.8)

## Self-review notes (author)

- **Spec coverage:** Item 1 → Tasks 3-6 (+ `added_items` in Task 1/2); Item 2 (SKU) → Tasks 1 (snapshot) + 5 (column/editor); Item 3 (sort) → Tasks 1 (field) + 5 (comparator). "Must NOT change": no inventory call anywhere; `edited_items`/`unknown_item` untouched (added go via `added_items`); `item_key` still the WC id; finalized gate preserved (editor-only render + `mutate_row` draft guard); `MAX_NOTE_LEN` reused; note is the add reason (no new reason code); `mutate_row` concurrency used; `log_lifecycle` isolation kept; `delivery_occurrence` untouched.
- **Directive corrections baked in:** SKU from QO catalogue (not `meals_products.sku`, which lacks the column) via a caller-built map into the pure builder; `added_items` reset in BOTH `confirm_row` and `revert_row`.
- **Type/name consistency:** `added_items` entries `{product_id,sku,product_name,qty}` identical in builder init, `edit_row`, page render, and JS collect; endpoint `mealsdb_order_audit_products`; classes/selectors `.oa-editor-add-item` / `.oa-added-line` / `.oa-added-qty` / `.oa-added-remove` used identically in page + JS.
- **Interpretation surfaced:** per-order SKU cell = comma-joined item SKUs; native `<select>` dropdown (browser typeahead), not a live keystroke search.
