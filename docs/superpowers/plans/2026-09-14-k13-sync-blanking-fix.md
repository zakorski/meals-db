# K13 v2 — Sync Blanking Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the nightly sync from blanking `meals_clients` columns mapped to usermeta keys that don't exist, and add layered defences so the whole class of "empty overwrites real" can't recur.

**Architecture:** Four layers in `MealsDB_Sync` + `MealsDB_Sync_Mutate`. The decision logic is extracted into small **pure static helpers** on `MealsDB_Sync` (`would_blank_value`, `decide_field_action`, `tally_blank_candidates`, `is_over_mass_blank_threshold`) so it is unit-testable without `$wpdb`; the two nightly loops and the two mutator write paths call those helpers. Deliberate form clears are unaffected because they never route through the sync mutators.

**Tech Stack:** PHP 8.2, WordPress/WooCommerce (HPOS), `$wpdb`. Tests are standalone PHP scripts with WP stubs + `ReflectionMethod` (the repo's existing harness — **NOT** PHPUnit), run via `php tests/test-*.php`, exit 0 = pass.

---

## Spec reference

`docs/superpowers/specs/2026-09-14-k13-sync-blanking-design.md`. Directive: `directives/DIRECTIVE-K13-v2-sync-blanking-fix.md`.

## File structure

- **Modify** `includes/class-sync.php`
  - Add class constant `SYNC_MASS_BLANK_THRESHOLD_PCT`.
  - Add pure helpers: `would_blank_value()`, `decide_field_action()`, `tally_blank_candidates()`, `is_over_mass_blank_threshold()`, `read_wp_field_value_with_presence()`.
  - ITEM 1: rewrite 7 entries in `get_field_to_wp_meta_map()` (`:132-141`).
  - ITEM 2/3: rewire the per-field loop in `run_nightly_sync()` (`:512-547`) and in `sync_wp_fields_to_meals_db()` (`:770-793`) to use the presence helper + `decide_field_action()`; aggregate `sync.meta_key_missing`.
  - ITEM 4: add `preflight_mass_blank_scan()` and call it at the top of `run_nightly_sync()`.
- **Modify** `includes/services/sync/class-sync-mutate.php`
  - ITEM 3 WP->DB guard in `push_to_meals_db()` (after `$existing_value`, `:255`).
  - ITEM 3 DB->WP guard in `apply_wp_user_update()` meta branch (after `$old_value = get_user_meta`, `:1050`).
- **Create** `tests/test-sync-blanking-k13.php`.

## Conventions (match these exactly)

- Event logging: `MealsDB_Event_Log::record(['severity'=>..,'category'=>'sync','subsystem'=>'sync','event'=>'sync.<name>','outcome'=>'degraded','message'=>..,'context'=>[..]])`. `record()` never throws and returns 0 when `$wpdb` is unavailable.
- Refusal error code: `'mealsdb_sync_empty_overwrite_refused'` (new, used in both mutator guards).
- All comments explain **why** (the codebase convention), referencing K13.
- Do **not** bump `MEALS_DB_VERSION` — CI owns version bumps on merge.

---

### Task 1: ITEM 1 — Correct the 9 field mappings

**Files:**
- Modify: `includes/class-sync.php:132-141`
- Test: `tests/test-sync-blanking-k13.php`

- [ ] **Step 1: Write the failing test** (create the file with this first block)

```php
<?php
/**
 * K13 v2 — sync must not blank a column from a meta key that does not exist.
 * Run: php tests/test-sync-blanking-k13.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// --- WP / plugin stubs ----------------------------------------------------
class MealsDB_Logger {
    public static array $logs = [];
    public static function log(...$a): void { self::$logs[] = $a; }
    public static function error($m): void {}
}
class MealsDB_Event_Log {
    public const OUTCOME_DEGRADED = 'degraded';
    public static array $events = [];
    public static function record(array $e): int { self::$events[] = $e; return 1; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('apply_filters')) { function apply_filters(string $t, $v, ...$a) { return $v; } }
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message;
        public function __construct($c = '', $m = '') { $this->code = $c; $this->message = $m; }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!class_exists('wpdb')) { class wpdb { public $prefix = 'wp_'; public $last_error = ''; } }
if (!class_exists('WP_User')) { class WP_User { public $ID = 0; public $first_name=''; public $last_name=''; public $user_email=''; public function __construct($id=0){$this->ID=$id;} } }

// Controllable usermeta store shared by all tasks' tests.
$GLOBALS['meta_store']  = [];   // "uid|key" => value  (present)
$GLOBALS['meta_exists'] = [];   // "uid|key" => true   (metadata_exists)
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = false) { return $GLOBALS['meta_store']["$uid|$key"] ?? ''; }
}
if (!function_exists('metadata_exists')) {
    function metadata_exists($type, $uid, $key) { return !empty($GLOBALS['meta_exists']["$uid|$key"]); }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($uid, $key, $val) {
        $cur = $GLOBALS['meta_store']["$uid|$key"] ?? null;
        if ($cur !== null && (string) $cur === (string) $val) { return false; }
        $GLOBALS['meta_store']["$uid|$key"] = $val;
        $GLOBALS['meta_exists']["$uid|$key"] = true;
        return true;
    }
}

$failures = []; $passed = 0;
function chk($cond, string $l): void {
    global $failures, $passed;
    if ($cond) { $passed++; return; }
    $failures[] = $l;
}

// ===== Task 1: ITEM 1 map =====
$map = MealsDB_Sync::get_field_to_wp_meta_map();
chk(($map['street_name']['key'] ?? '') === 'billing_address_1', 'ITEM1: street_name -> billing_address_1');
chk(($map['delivery_street_name']['key'] ?? '') === 'shipping_address_1', 'ITEM1: delivery_street_name -> shipping_address_1');
chk(($map['client_phone_2']['key'] ?? '') === 'billing_phone_2', 'ITEM1: client_phone_2 -> billing_phone_2');
chk(($map['alternate_contact_name']['key'] ?? '') === 'alternate_contact_name', 'ITEM1: alt_contact_name unprefixed');
chk(($map['alternate_contact_phone_1']['key'] ?? '') === 'alternate_contact_phone_1', 'ITEM1: alt_phone_1 unprefixed');
chk(($map['alternate_contact_phone_2']['key'] ?? '') === 'alternate_contact_phone_2', 'ITEM1: alt_phone_2 unprefixed');
chk(($map['alternate_contact_email']['key'] ?? '') === 'alternate_contact_email', 'ITEM1: alt_email unprefixed');
// No remaining mapping may point at a mealsdb_* address/contact key.
foreach (['street_name','delivery_street_name','client_phone_2','alternate_contact_name','alternate_contact_phone_1','alternate_contact_phone_2','alternate_contact_email'] as $f) {
    chk(strpos($map[$f]['key'] ?? '', 'mealsdb_') !== 0, "ITEM1: {$f} no longer mealsdb_ prefixed");
}

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo "FAIL: {$f}\n"; }
exit(empty($failures) ? 0 : 1);
```

- [ ] **Step 2: Run the test — verify it fails**

Run: `php tests/test-sync-blanking-k13.php`
Expected: FAIL lines for the 7 remap checks (map still returns `mealsdb_*` keys).

- [ ] **Step 3: Apply ITEM 1** — replace `includes/class-sync.php:132-141` (the block from the `// Plugin-managed custom meta` comment through `alternate_contact_email`) with:

```php
            // K13 ITEM 1: these were mapped to `mealsdb_*` keys that have NEVER
            // existed on this install (0 usermeta rows, verified 2026-09-14).
            // get_user_meta() returned '' and the nightly sync wrote that ''
            // over the column, blanking all 992 clients. Each now points at the
            // key the migration actually reads from (class-migration-consolidated
            // .php) — the only path that populated these columns — so sync and
            // migration finally agree. ITEM 2 makes a still-absent key harmless.
            'client_phone_2'                => ['type' => 'meta', 'key' => 'billing_phone_2'],
            'street_name'                   => ['type' => 'meta', 'key' => 'billing_address_1'],
            'delivery_street_name'          => ['type' => 'meta', 'key' => 'shipping_address_1'],
            'alternate_contact_name'        => ['type' => 'meta', 'key' => 'alternate_contact_name'],
            'alternate_contact_phone_1'     => ['type' => 'meta', 'key' => 'alternate_contact_phone_1'],
            'alternate_contact_phone_2'     => ['type' => 'meta', 'key' => 'alternate_contact_phone_2'],
            'alternate_contact_email'       => ['type' => 'meta', 'key' => 'alternate_contact_email'],
            // next_order_date / next_delivery_date are COMPUTED by the next-dates
            // migration phase, not sourced from the WP user. They stay on their
            // mealsdb_* keys (0-9 rows) deliberately — ITEM 2 skips an absent key
            // so they can no longer blank. Whether they belong in this map at all
            // is a K13 follow-up, not this change.
            'next_order_date'               => ['type' => 'meta', 'key' => 'mealsdb_next_order_date'],
            'next_delivery_date'            => ['type' => 'meta', 'key' => 'mealsdb_next_delivery_date'],
```

- [ ] **Step 4: Run the test — verify it passes**

Run: `php tests/test-sync-blanking-k13.php`
Expected: PASS (all Task 1 checks).

- [ ] **Step 5: Commit**

```bash
git add includes/class-sync.php tests/test-sync-blanking-k13.php
git commit -m "K13 ITEM 1: point sync map at the real usermeta keys"
```

---

### Task 2: ITEM 2 — Absent key never blanks (presence gate + decision helper)

**Files:**
- Modify: `includes/class-sync.php` (add helpers; rewire both loops)
- Test: `tests/test-sync-blanking-k13.php`

- [ ] **Step 1: Write the failing tests** — append before the `echo "Ran ..."` summary line:

```php
// ===== Task 2: ITEM 2 pure decision helper =====
// decide_field_action(bool $present, string $wp_value, string $client_value): string
chk(MealsDB_Sync::decide_field_action(false, '', '123 Main St') === 'skip_absent', 'ITEM2: absent key -> skip_absent (test 1)');
chk(MealsDB_Sync::decide_field_action(false, '', '') === 'skip_absent', 'ITEM2: absent key wins even when column empty');
chk(MealsDB_Sync::decide_field_action(true, '5 Elm St', '5 Elm St') === 'noop', 'ITEM2: equal -> noop');
chk(MealsDB_Sync::decide_field_action(true, '5 Elm St', 'Old Rd') === 'write', 'ITEM2: real diff -> write (test 3)');
chk(MealsDB_Sync::decide_field_action(true, '', '123 Main St') === 'skip_blank', 'ITEM2/3: present-empty over real -> skip_blank (test 2)');
chk(MealsDB_Sync::decide_field_action(true, '', '') === 'noop', 'ITEM2: empty over empty -> noop');

// presence-aware read helper
$rmp = new ReflectionMethod('MealsDB_Sync', 'read_wp_field_value_with_presence');
$rmp->setAccessible(true);
$GLOBALS['meta_store']  = ['9|billing_address_1' => '5 Elm St'];
$GLOBALS['meta_exists'] = ['9|billing_address_1' => true];
[$val, $present] = $rmp->invoke(null, new WP_User(9), ['type'=>'meta','key'=>'billing_address_1']);
chk($val === '5 Elm St' && $present === true, 'ITEM2: present meta read (value+present) (test 4)');
[$val2, $present2] = $rmp->invoke(null, new WP_User(9), ['type'=>'meta','key'=>'mealsdb_street_name']);
chk($val2 === '' && $present2 === false, 'ITEM2: absent meta read -> present=false');
```

- [ ] **Step 2: Run — verify it fails**

Run: `php tests/test-sync-blanking-k13.php`
Expected: FAIL — `decide_field_action` / `read_wp_field_value_with_presence` not defined.

- [ ] **Step 3a: Add the pure helpers** to `includes/class-sync.php` (place immediately after `get_wp_authoritative_fields()`, before `get_field_to_wp_meta_map()`):

```php
    /**
     * K13 ITEM 3: an empty incoming value means "no data", never "delete the
     * data". Returns true only when we are about to overwrite a populated value
     * with an empty one — the single hazard that destroyed 992 addresses.
     */
    public static function would_blank_value(string $new_value, string $existing_value): bool {
        return trim($new_value) === '' && trim($existing_value) !== '';
    }

    /**
     * K13 ITEM 2/3: the single per-field decision shared by both WP->meals_db
     * loops. Kept pure so it is unit-testable without $wpdb.
     *
     * @param bool   $present      Does the mapped WP meta key EXIST for this user?
     * @param string $wp_value     Value read from WP ('' when absent OR empty).
     * @param string $client_value Current meals_clients column value.
     * @return string skip_absent | skip_blank | noop | write
     */
    public static function decide_field_action(bool $present, string $wp_value, string $client_value): string {
        // Absent wins first: get_user_meta() returns '' both for "set to empty"
        // and "key absent". An absent key is "no opinion" — never touch the DB,
        // and it is the case that blanked every client nightly (K13).
        if (!$present) {
            return 'skip_absent';
        }
        if (self::normalize_for_comparison($wp_value) === self::normalize_for_comparison($client_value)) {
            return 'noop';
        }
        if (self::would_blank_value($wp_value, $client_value)) {
            return 'skip_blank';
        }
        return 'write';
    }

    /**
     * Presence-aware read: like read_wp_field_value() but also reports whether
     * the mapped key EXISTS. For core fields presence is always true.
     *
     * @return array{0: string, 1: bool} [value, present]
     */
    private static function read_wp_field_value_with_presence(WP_User $user, array $descriptor): array {
        if ($descriptor['type'] === 'core') {
            return [self::read_wp_field_value($user, $descriptor), true];
        }
        $present = metadata_exists('user', (int) $user->ID, $descriptor['key']);
        return [self::read_wp_field_value($user, $descriptor), (bool) $present];
    }
```

- [ ] **Step 3b: Rewire the `run_nightly_sync()` loop.** Before the `while (true)` batch loop (around `:462`), declare the missing-field accumulator:

```php
        // K13 ITEM 2: collect fields whose mapped key is absent for EVERY user
        // so we log ONE sync.meta_key_missing per field per run, not 992 rows.
        $missing_fields = []; // field => mapped key
```

Then replace the per-field body at `:518-546` (from `$wp_value = self::read_wp_field_value(...)` through the `else { $pushed++; }`) with:

```php
                    [$wp_value, $present] = self::read_wp_field_value_with_presence($user, $field_map[$field]);
                    $client_value = isset($client[$field]) ? (string) $client[$field] : '';

                    $action = self::decide_field_action($present, $wp_value, $client_value);
                    if ($action === 'skip_absent') {
                        $missing_fields[$field] = $field_map[$field]['key'];
                        continue;
                    }
                    if ($action === 'noop') {
                        continue;
                    }
                    if ($action === 'skip_blank') {
                        // K13 ITEM 3: present-but-empty over a populated column.
                        // Refuse and surface it (bounded below the ITEM 4 mass
                        // threshold, so this is a handful of rows, not 992).
                        MealsDB_Event_Log::record([
                            'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync',
                            'event' => 'sync.empty_overwrite_refused', 'outcome' => 'degraded',
                            'message' => sprintf('%s: refused to blank a populated column for client %d', $field, $client_id),
                            'context' => ['client_id' => $client_id, 'field' => $field],
                        ]);
                        continue;
                    }

                    // action === 'write'
                    self::$syncing = true;
                    try {
                        $push_result = self::push_to_meals_db($client_id, $field, $wp_value);
                    } finally {
                        self::$syncing = false;
                    }

                    if (is_wp_error($push_result)) {
                        $error_count++;
                        error_log(sprintf(
                            '[MealsDB Sync] Nightly sync error for client %d, field %s: %s',
                            $client_id, $field, $push_result->get_error_message()
                        ));
                    } else {
                        $pushed++;
                    }
```

Then, after the `while` batch loop ends (after `:558`, before `$summary = ...`), emit the aggregated events:

```php
            // K13 ITEM 2: one row per absent field per run — the alarm that
            // would have surfaced the blanking in a day instead of a week.
            foreach ($missing_fields as $field => $key) {
                MealsDB_Event_Log::record([
                    'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync',
                    'event' => 'sync.meta_key_missing', 'outcome' => 'degraded',
                    'message' => sprintf('%s -> %s absent for tracked users; column left unchanged', $field, $key),
                    'context' => ['field' => $field, 'meta_key' => $key],
                ]);
            }
```

- [ ] **Step 3c: Rewire the real-time loop** `sync_wp_fields_to_meals_db()` (`:770-793`). Replace the body inside `foreach ($wp_fields as $field)` (from `$wp_value = self::read_wp_field_value(...)`) with the presence-aware decision — this loop is a SINGLE client, so absent keys skip silently (no aggregation needed) and skip_blank still refuses:

```php
                [$wp_value, $present] = self::read_wp_field_value_with_presence($user, $field_map[$field]);
                $client_value = isset($client[$field]) ? (string) $client[$field] : '';

                $action = self::decide_field_action($present, $wp_value, $client_value);
                if ($action === 'skip_absent' || $action === 'noop') {
                    continue;
                }
                if ($action === 'skip_blank') {
                    MealsDB_Event_Log::record([
                        'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync',
                        'event' => 'sync.empty_overwrite_refused', 'outcome' => 'degraded',
                        'message' => sprintf('%s: refused to blank a populated column for client %d (%s)', $field, $client_id, $action),
                        'context' => ['client_id' => $client_id, 'field' => $field],
                    ]);
                    continue;
                }

                $result = self::push_to_meals_db($client_id, $field, $wp_value);

                if (is_wp_error($result)) {
                    error_log(sprintf(
                        '[MealsDB Sync] %s error for client %d, field %s: %s',
                        $action, $client_id, $field, $result->get_error_message()
                    ));
                }
```

- [ ] **Step 4: Run — verify it passes**

Run: `php tests/test-sync-blanking-k13.php`
Expected: PASS (Task 1 + Task 2 checks).

- [ ] **Step 5: Commit**

```bash
git add includes/class-sync.php tests/test-sync-blanking-k13.php
git commit -m "K13 ITEM 2: absent meta key skips instead of blanking the column"
```

---

### Task 3: ITEM 3 — Never write empty over non-empty (both mutator directions)

**Files:**
- Modify: `includes/services/sync/class-sync-mutate.php` (`push_to_meals_db` `:255`, `apply_wp_user_update` meta branch `:1050`)
- Test: `tests/test-sync-blanking-k13.php`

- [ ] **Step 1: Write the failing tests** — append before the summary line:

```php
// ===== Task 3: ITEM 3 mutator guards =====
$GLOBALS['wpdb'] = new wpdb();
$mut = new MealsDB_Sync_Mutate();
$rm = new ReflectionMethod('MealsDB_Sync_Mutate', 'apply_wp_user_update');
$rm->setAccessible(true);

// DB->WP (test 5): push empty over a populated billing_address_1 -> refused.
$GLOBALS['meta_store']  = ['7|billing_address_1' => '5 Elm St'];
$GLOBALS['meta_exists'] = ['7|billing_address_1' => true];
MealsDB_Event_Log::$events = [];
$res = $rm->invoke($mut, new WP_User(7), 'street_name', '');
chk(is_wp_error($res), 'ITEM3 DB->WP: empty over real -> WP_Error (test 5)');
chk($res->get_error_code() === 'mealsdb_sync_empty_overwrite_refused', 'ITEM3 DB->WP: refusal error code');
chk(($GLOBALS['meta_store']['7|billing_address_1'] ?? '') === '5 Elm St', 'ITEM3 DB->WP: billing_address_1 unchanged (test 5)');
$refused = array_filter(MealsDB_Event_Log::$events, fn($e) => ($e['event'] ?? '') === 'sync.empty_overwrite_refused');
chk(count($refused) === 1, 'ITEM3 DB->WP: one empty_overwrite_refused event');

// DB->WP (test 6): real over a different real value -> succeeds.
$GLOBALS['meta_store']  = ['7|billing_address_1' => 'Old Rd'];
$GLOBALS['meta_exists'] = ['7|billing_address_1' => true];
$res = $rm->invoke($mut, new WP_User(7), 'street_name', '9 Oak Ave');
chk($res === true, 'ITEM3 DB->WP: real over real -> success (test 6)');
chk(($GLOBALS['meta_store']['7|billing_address_1'] ?? '') === '9 Oak Ave', 'ITEM3 DB->WP: real value persisted');

// DB->WP: empty over ALREADY-empty is allowed (no refusal).
$GLOBALS['meta_store']  = ['7|billing_address_1' => ''];
$GLOBALS['meta_exists'] = ['7|billing_address_1' => true];
MealsDB_Event_Log::$events = [];
$res = $rm->invoke($mut, new WP_User(7), 'street_name', '');
chk(!is_wp_error($res), 'ITEM3 DB->WP: empty over empty -> not refused');
```

- [ ] **Step 2: Run — verify it fails**

Run: `php tests/test-sync-blanking-k13.php`
Expected: FAIL — `apply_wp_user_update` currently persists the empty value (no refusal).

- [ ] **Step 3a: DB->WP guard** in `apply_wp_user_update()`. Insert immediately after `$old_value = get_user_meta($woo_user_id, $meta_key, true);` (`:1050`), before the `persist_user_meta` call:

```php
                    // K13 ITEM 3: never blank a populated WP meta value from a
                    // sync push. street_name/delivery_street_name resolve here to
                    // billing_address_1/shipping_address_1 (K13 ITEM 1) — the only
                    // surviving copy of the client's address. An empty incoming
                    // value means "no data", not "delete it". Deliberate operator
                    // clears go through the client form, which does NOT route
                    // through this mutator, so they are unaffected.
                    if (MealsDB_Sync::would_blank_value($new_value, is_scalar($old_value) ? (string) $old_value : '')) {
                        MealsDB_Event_Log::record([
                            'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync',
                            'event' => 'sync.empty_overwrite_refused', 'outcome' => 'degraded',
                            'message' => sprintf('DB->WP: refused to blank populated %s for user %d', $meta_key, $woo_user_id),
                            'context' => ['user_id' => $woo_user_id, 'field' => $field, 'meta_key' => $meta_key],
                        ]);
                        $error_code    = 'mealsdb_sync_empty_overwrite_refused';
                        $error_message = __('Refused to overwrite a populated field with an empty value. Clear it via the client form if that is intended.', 'meals-db');
                        break;
                    }
```

> Note: `$update_success` stays `false`, so the existing `if (!$update_success) { return new WP_Error($error_code, $error_message); }` block (`:1066-1072`) returns the refusal. Shadow mode (`update_wp_user` `:64`) still short-circuits earlier when enabled; this guard is the belt for when it is off.

- [ ] **Step 3b: WP->DB guard** in `push_to_meals_db()`. Insert after `$existing_value = is_scalar($existing_value) ? (string) $existing_value : '';` (`:255`), before `$update_success = $this->update_meals_client(...)`:

```php
        // K13 ITEM 3: covers callers that reach this method WITHOUT the nightly
        // loop's decide_field_action() pre-check — the real-time hooks and the
        // conflict-resolution AJAX (direction=woocommerce). An empty incoming
        // value must never blank a populated meals_clients column.
        if (MealsDB_Sync::would_blank_value($new_value, $existing_value)) {
            MealsDB_Event_Log::record([
                'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync',
                'event' => 'sync.empty_overwrite_refused', 'outcome' => 'degraded',
                'message' => sprintf('WP->DB: refused to blank populated %s for client %d', $field, $client_id),
                'context' => ['client_id' => $client_id, 'field' => $field],
            ]);
            return new WP_Error(
                'mealsdb_sync_empty_overwrite_refused',
                __('Refused to overwrite a populated field with an empty value.', 'meals-db')
            );
        }
```

- [ ] **Step 4: Run — verify it passes**

Run: `php tests/test-sync-blanking-k13.php`
Expected: PASS (Tasks 1-3).

- [ ] **Step 5: Run the existing sync mutator tests — verify no regression**

Run: `php tests/test-sync-mutate-meta-noop.php`
Expected: PASS (the no-op / real-change / forced-failure paths are unchanged; the guard only fires on empty-over-non-empty).

- [ ] **Step 6: Commit**

```bash
git add includes/services/sync/class-sync-mutate.php tests/test-sync-blanking-k13.php
git commit -m "K13 ITEM 3: refuse empty-over-non-empty in both sync directions"
```

---

### Task 4: ITEM 4 — Mass-blank circuit breaker (whole-run pre-flight)

**Files:**
- Modify: `includes/class-sync.php` (constant + `tally_blank_candidates` + `is_over_mass_blank_threshold` + `preflight_mass_blank_scan` + call it in `run_nightly_sync`)
- Test: `tests/test-sync-blanking-k13.php`

- [ ] **Step 1: Write the failing tests** — append before the summary line:

```php
// ===== Task 4: ITEM 4 mass-blank breaker (pure helpers) =====
// is_over_mass_blank_threshold(int $blank_count, int $active_count): bool — strictly > 20%.
chk(MealsDB_Sync::is_over_mass_blank_threshold(21, 100) === true,  'ITEM4: 21/100 over threshold');
chk(MealsDB_Sync::is_over_mass_blank_threshold(20, 100) === false, 'ITEM4: 20/100 NOT over (boundary)');
chk(MealsDB_Sync::is_over_mass_blank_threshold(1, 100) === false,  'ITEM4: 1/100 under (test 8)');
chk(MealsDB_Sync::is_over_mass_blank_threshold(1, 0) === false,    'ITEM4: guards divide-by-zero');

// tally_blank_candidates: count per field where WP-side is empty but column is not.
$rows = [];
for ($i = 1; $i <= 100; $i++) { $rows[] = ['wp_user_id' => $i, 'street_name' => '123 Main St']; }
$read_raw = function (int $uid, array $desc) { return ''; }; // every WP key empty/absent
$tally = MealsDB_Sync::tally_blank_candidates($rows, ['street_name'], MealsDB_Sync::get_field_to_wp_meta_map(), $read_raw, 'wp_user_id');
chk(($tally['street_name'] ?? 0) === 100, 'ITEM4: 100 blank candidates counted (test 7)');
chk(MealsDB_Sync::is_over_mass_blank_threshold($tally['street_name'], count($rows)) === true, 'ITEM4: 100/100 -> abort (test 7)');

// Under threshold: only 1 client would blank.
$rows2 = [];
for ($i = 1; $i <= 100; $i++) { $rows2[] = ['wp_user_id' => $i, 'street_name' => '123 Main St']; }
$read_one_empty = function (int $uid, array $desc) { return $uid === 1 ? '' : '5 Elm St'; };
$tally2 = MealsDB_Sync::tally_blank_candidates($rows2, ['street_name'], MealsDB_Sync::get_field_to_wp_meta_map(), $read_one_empty, 'wp_user_id');
chk(($tally2['street_name'] ?? -1) === 1, 'ITEM4: 1 blank candidate (test 8)');
chk(MealsDB_Sync::is_over_mass_blank_threshold($tally2['street_name'], count($rows2)) === false, 'ITEM4: 1/100 -> proceed (test 8)');
```

- [ ] **Step 2: Run — verify it fails**

Run: `php tests/test-sync-blanking-k13.php`
Expected: FAIL — `SYNC_MASS_BLANK_THRESHOLD_PCT` / `tally_blank_candidates` / `is_over_mass_blank_threshold` not defined.

- [ ] **Step 3a: Add the constant** near the top of `class MealsDB_Sync` (after the `$syncing` property `:19`):

```php
    /**
     * K13 ITEM 4: if a single nightly run would empty a currently-populated
     * column on more than this percentage of tracked clients, the run is not a
     * legitimate sync — it is a broken mapping, a bad usermeta migration, or a
     * partial restore. Abort the WHOLE run and write nothing. No real nightly
     * sync blanks the same field on hundreds of clients at once.
     */
    public const SYNC_MASS_BLANK_THRESHOLD_PCT = 20;
```

- [ ] **Step 3b: Add the pure helpers** (next to `decide_field_action`):

```php
    /**
     * K13 ITEM 4: strictly-greater-than-threshold, integer-safe (no float,
     * no divide-by-zero).
     */
    public static function is_over_mass_blank_threshold(int $blank_count, int $active_count): bool {
        if ($active_count <= 0) {
            return false;
        }
        return ($blank_count * 100) > ($active_count * self::SYNC_MASS_BLANK_THRESHOLD_PCT);
    }

    /**
     * K13 ITEM 4: count, per field, how many rows have a currently-populated
     * column that the WP side would set empty. Pure over injected data so it is
     * unit-testable without $wpdb: $read_raw($wp_user_id, $descriptor) returns
     * the raw WP value ('' when absent OR empty — deliberately, this measures
     * total blanking PRESSURE, the systemic signal, not the post-guard result).
     *
     * @param array<int,array<string,mixed>> $rows       Client rows.
     * @param string[]                       $wp_fields  WP-authoritative fields.
     * @param array<string,array>            $field_map  Field -> descriptor.
     * @param callable                       $read_raw   fn(int,$descriptor):string
     * @param string                         $wp_column  wp_user id column name.
     * @return array<string,int> field => blank-candidate count
     */
    public static function tally_blank_candidates(array $rows, array $wp_fields, array $field_map, callable $read_raw, string $wp_column): array {
        $tally = [];
        foreach ($rows as $row) {
            $uid = (int) ($row[$wp_column] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            foreach ($wp_fields as $field) {
                if (!isset($field_map[$field])) {
                    continue;
                }
                $client_value = isset($row[$field]) ? (string) $row[$field] : '';
                if (trim($client_value) === '') {
                    continue; // nothing to lose
                }
                $wp_value = (string) $read_raw($uid, $field_map[$field]);
                if (trim($wp_value) === '') {
                    $tally[$field] = ($tally[$field] ?? 0) + 1;
                }
            }
        }
        return $tally;
    }
```

- [ ] **Step 3c: Add the pre-flight scan** as a private method on `MealsDB_Sync` (near `resolve_wp_user_column`):

```php
    /**
     * K13 ITEM 4: walk the tracked-client population once WITHOUT writing and
     * tally per-field blank candidates. Returns the first field over threshold,
     * or null. Uses the SAME client_type filter and wp_user column the real
     * sync walks, so the percentage measures against the real population.
     *
     * @return array{field: string, count: int, active: int}|null
     */
    private static function preflight_mass_blank_scan(wpdb $wpdb, string $escaped_table, string $wp_column, array $field_map, array $wp_fields): ?array {
        $escaped_column = str_replace('`', '``', $wp_column);
        $batch_size = 500;
        $offset = 0;
        $active = 0;
        $tally = [];

        $read_raw = static function (int $uid, array $descriptor): string {
            $u = get_userdata($uid);
            return $u instanceof WP_User ? self::read_wp_field_value($u, $descriptor) : '';
        };

        while (true) {
            $batch = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$escaped_table}` WHERE client_type IN ('SDNB', 'Veteran', 'Private') AND `{$escaped_column}` > 0 ORDER BY client_id ASC LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                ),
                ARRAY_A
            );
            if (!is_array($batch) || empty($batch)) {
                break;
            }
            $active += count($batch);
            $ids = [];
            foreach ($batch as $row) { $uid = (int) ($row[$wp_column] ?? 0); if ($uid > 0) { $ids[$uid] = $uid; } }
            if (!empty($ids)) {
                if (function_exists('cache_users')) { cache_users(array_values($ids)); }
                update_meta_cache('user', array_values($ids));
            }
            $batch_tally = self::tally_blank_candidates($batch, $wp_fields, $field_map, $read_raw, $wp_column);
            foreach ($batch_tally as $field => $count) { $tally[$field] = ($tally[$field] ?? 0) + $count; }

            if (count($batch) < $batch_size) { break; }
            $offset += $batch_size;
        }

        foreach ($tally as $field => $count) {
            if (self::is_over_mass_blank_threshold((int) $count, $active)) {
                return ['field' => $field, 'count' => (int) $count, 'active' => $active];
            }
        }
        return null;
    }
```

- [ ] **Step 3d: Call the pre-flight** in `run_nightly_sync()`, immediately after `$escaped_column = str_replace('`', '``', $wp_column);` (`:460`) and before the `while (true)` write loop:

```php
        // K13 ITEM 4: whole-run circuit breaker. The nightly sync is a streaming
        // write loop with no stage-then-commit phase, so "write nothing" on a
        // mass blanking can only be honoured by a count-only pre-flight BEFORE
        // any write. If any field is over threshold the whole run is suspect —
        // a broken mapping casts doubt on every write this run, not just the
        // offending field — so abort entirely.
        $mass_blank = self::preflight_mass_blank_scan($wpdb, $escaped_table, $wp_column, $field_map, $wp_fields);
        if ($mass_blank !== null) {
            MealsDB_Event_Log::record([
                'severity' => 'error', 'category' => 'sync', 'subsystem' => 'sync',
                'event' => 'sync.mass_blank_refused', 'outcome' => 'degraded',
                'message' => sprintf(
                    'Nightly sync aborted: %d of %d tracked clients would have %s blanked (> %d%%). Wrote nothing.',
                    $mass_blank['count'], $mass_blank['active'], $mass_blank['field'], self::SYNC_MASS_BLANK_THRESHOLD_PCT
                ),
                'context' => $mass_blank,
            ]);
            if ($log_id > 0 && class_exists('MealsDB_Job_Logger')) {
                MealsDB_Job_Logger::fail($log_id, 'Mass-blank pre-flight refused the run: ' . $mass_blank['field']);
            }
            return; // write nothing
        }
```

> `$escaped_table` is already in scope (set at `:439`). The pre-flight re-reads rows; that is the deliberate, documented cost of ITEM 4 (two reads of ~992 rows nightly, off the hot path).

- [ ] **Step 4: Run — verify it passes**

Run: `php tests/test-sync-blanking-k13.php`
Expected: PASS (Tasks 1-4).

- [ ] **Step 5: Commit**

```bash
git add includes/class-sync.php tests/test-sync-blanking-k13.php
git commit -m "K13 ITEM 4: pre-flight mass-blank circuit breaker aborts a suspect run"
```

---

### Task 5: Full regression + PR

**Files:** none (verification + integration)

- [ ] **Step 1: Run the whole sync-related test set**

Run each and confirm `0 failed` / exit 0:
`php tests/test-sync-blanking-k13.php`
`php tests/test-sync-mutate-meta-noop.php`
`php tests/test-sync-mutate-tx.php`
`php tests/test-case-count-sync.php`

- [ ] **Step 2: Lint the two changed PHP files**

Run: `php -l includes/class-sync.php` and `php -l includes/services/sync/class-sync-mutate.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 3: Grep for leftover hazards** — confirm no address/contact field still points at a `mealsdb_` key

Run: `grep -nE "'(street_name|delivery_street_name|client_phone_2|alternate_contact_[a-z_0-9]+)'\s*=>.*mealsdb_" includes/class-sync.php`
Expected: no output (empty).

- [ ] **Step 4: Push and open the PR**

`git push -u origin k13-sync-blanking-fix`
`gh pr create --title "K13 v2: sync must not blank a column from a missing meta key" --body "See docs/superpowers/specs/2026-09-14-k13-sync-blanking-design.md. ITEMs 1-4 per DIRECTIVE-K13-v2. Tests: tests/test-sync-blanking-k13.php."`

- [ ] **Step 5: Report the PR URL to the operator.**

---

## Self-review notes

- **Spec coverage:** ITEM 1 -> Task 1; ITEM 2 (presence + aggregation) -> Task 2; ITEM 3 (both directions) -> Task 3; ITEM 4 (threshold + pre-flight) -> Task 4. Directive tests 1-9 mapped: 1->Task2 skip_absent; 2->Task2 skip_blank; 3->Task2 write; 4->Task1 map + Task2 presence read; 5/6->Task3 integration; 7/8->Task4 tally+threshold; 9->Task2 aggregation.
- **Type consistency:** decide_field_action returns the four literals skip_absent|skip_blank|noop|write used identically in both loops. would_blank_value used in decide_field_action, push_to_meals_db, apply_wp_user_update. Refusal code mealsdb_sync_empty_overwrite_refused consistent across both guards and the Task 3 test.
- **Known limitation (stated, not hidden):** the full two-pass wiring of run_nightly_sync/preflight_mass_blank_scan is exercised through its pure helpers rather than an end-to-end $wpdb fixture; the loop glue is thin and is confirmed on staging (spec "Operator verification"). This matches the repo's existing test style.
