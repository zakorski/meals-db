# Zone Delivery Day Source of Truth Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The zone delivery schedule becomes the sole source of truth for client delivery days: clients pick a zone from a dropdown, `delivery_day` is a zone-derived read-only cache (lowercase full day names), with propagation on schedule change, a one-time resync + orphan report, and nightly drift enforcement.

**Architecture:** A new pure-lookup service (`MealsDB_Zone_Day`) is the single zone→day implementation, used by the client-form save (derives, ignores posted values), the settings-save propagation, the resync endpoint, and the existing nightly drift checker. The client form's `WED AM` vocabulary is deleted; the `delivery_area_name` field becomes a schedule-constrained dropdown with a live read-only day display fed by a JSON island.

**Tech Stack:** WordPress plugin PHP 8.2 (`$wpdb`, admin-ajax), jQuery, standalone `php tests/test-*.php` scripts (ABSPATH define + autoloader + function stubs pattern).

**Spec:** `docs/superpowers/specs/2026-07-11-zone-delivery-day-source-of-truth-design.md`

**Baseline note:** two PDF tests fail locally (missing mbstring/imagick) — pre-existing; ignore exactly those two.

**Key existing facts (verified against the code):**
- Zone schedule option `mealsdb_zone_delivery_schedule` = `['Zone 1' => ['day' => 'Wednesday', 'label' => '…'], …]`. Settings editor: `views/settings.php:151-238` (already has Mon–Sun day dropdowns). Save handler zone block: `includes/ajax/class-ajax-settings.php:249-266` (`$valid_days` includes Sat/Sun).
- Client form renderer: `includes/class-admin-ui.php` — `delivery_day` select row ~1552-1567, `delivery_area_name` text row ~1569-1576, `'delivery_day' => 'upper'` format map entry ~1196, `$delivery_day_options`/`$delivery_day_value` setup ~1218/~1260.
- Validation: `includes/class-client-form.php` — `get_enum_validation_rules()` ~1965 (the `WED AM` list + `mealsdb_allowed_delivery_days` filter), enum loop in `validate()` ~421-444.
- Save paths: `save()` calls `map_form_to_db` at line 876, `update()` at line 1110; both must derive `delivery_day` after mapping (field names are identical form/DB side for `delivery_day` and `delivery_area_name`).
- Old blank-fill backfill: endpoint `mealsdb_backfill_delivery_day` in `includes/ajax/class-ajax-delivery-slips.php:34` + `backfill_delivery_day()` ~109-178; button in `views/data-ops.php:148-159`; JS handler `assets/js/settings.js:38-59`.
- Drift checker: `MealsDB_Derived_Value_Check::expected_delivery_day()` in `includes/services/class-derived-value-check.php:175-197`; autocorrect option `mealsdb_derived_autocorrect` (`MealsDB_Derived_Value_Audit::AUTOCORRECT_OPTION`), read with `[]` default at `class-derived-value-audit.php:76`; settings UI caption at `views/settings.php:222-226`.
- Zone-schedule seeding block (pattern to copy for autocorrect seeding): `meals-db-main.php:203-212`.
- Audit log: `MealsDB_Logger::log(string $action, int $target_id, string $field, ?string $old, ?string $new, string $source = 'mealsdb')`.
- Client-form JS enqueue block (add/edit views): `includes/class-admin-ui.php:543-575` (`mealsdb-client-actions`, then the `$tab === 'add' || …edit` gate for `mealsdb-client-wp-user`).

---

### Task 0: Branch

- [ ] **Step 1:**

```bash
cd /mnt/fastssd/meals-db && git checkout -b feat/zone-delivery-day
```

---

### Task 1: `MealsDB_Zone_Day` lookup service (TDD)

**Files:**
- Create: `includes/services/class-zone-day.php`
- Create: `tests/test-zone-day.php`

- [ ] **Step 1: Write the failing test**

Create `tests/test-zone-day.php`:

```php
<?php
/**
 * Tests for MealsDB_Zone_Day — the single zone→delivery-day lookup
 * (spec 2026-07-11: zone schedule is the sole source of truth).
 *
 * Run: php tests/test-zone-day.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// get_option stub: tests control the schedule via $GLOBALS['zd_schedule'].
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        if ($key === 'mealsdb_zone_delivery_schedule') {
            return $GLOBALS['zd_schedule'] ?? $default;
        }
        return $default;
    }
}
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = []; $passed = 0;
function zd_check(string $label, $actual, $expected): void {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; return; }
    $failures[] = sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
}

$GLOBALS['zd_schedule'] = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'Wednesday morning - Moncton Downtown'],
    'Zone 5' => ['day' => 'Friday',    'label' => 'Friday - Dieppe / Riverview'],
    'Broken' => 'not-an-array',
    'NoDay'  => ['label' => 'day key missing'],
    'Blank'  => ['day' => '', 'label' => 'blank day'],
];

// Happy path: lowercase full day name.
zd_check('known zone', MealsDB_Zone_Day::day_for_zone('Zone 1'), 'wednesday');
zd_check('second zone', MealsDB_Zone_Day::day_for_zone('Zone 5'), 'friday');
// Whitespace tolerated on the lookup key.
zd_check('trimmed key', MealsDB_Zone_Day::day_for_zone('  Zone 1  '), 'wednesday');
// Null/blank/unknown → null (skip semantics, never a fatal).
zd_check('null zone', MealsDB_Zone_Day::day_for_zone(null), null);
zd_check('blank zone', MealsDB_Zone_Day::day_for_zone('   '), null);
zd_check('unknown zone', MealsDB_Zone_Day::day_for_zone('Zone 99'), null);
// Corrupt entries → null, no warning.
zd_check('non-array config', MealsDB_Zone_Day::day_for_zone('Broken'), null);
zd_check('missing day', MealsDB_Zone_Day::day_for_zone('NoDay'), null);
zd_check('blank day', MealsDB_Zone_Day::day_for_zone('Blank'), null);

// schedule(): only well-formed entries, day preserved in original case for display.
$sched = MealsDB_Zone_Day::schedule();
zd_check('schedule keys', array_keys($sched), ['Zone 1', 'Zone 5']);
zd_check('schedule day case', $sched['Zone 1']['day'], 'Wednesday');
zd_check('schedule label', $sched['Zone 5']['label'], 'Friday - Dieppe / Riverview');

// Empty/absent option → everything null/empty.
$GLOBALS['zd_schedule'] = [];
zd_check('empty schedule lookup', MealsDB_Zone_Day::day_for_zone('Zone 1'), null);
zd_check('empty schedule map', MealsDB_Zone_Day::schedule(), []);

if (empty($failures)) {
    echo "PASS — {$passed} checks\n";
    exit(0);
}
echo "FAIL\n" . implode("\n", $failures) . "\n";
exit(1);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd /mnt/fastssd/meals-db && php tests/test-zone-day.php`
Expected: fatal — class `MealsDB_Zone_Day` not found.

- [ ] **Step 3: Implement the service**

Create `includes/services/class-zone-day.php`:

```php
<?php
/**
 * MealsDB_Zone_Day — the single zone→delivery-day implementation
 * (spec 2026-07-11: the zone delivery schedule is the SOLE source of truth
 * for client delivery days).
 *
 * Every writer and deriving reader of meals_clients.delivery_day routes
 * through this class: the client-form save (derives, ignoring posted
 * values), the settings-save propagation, the resync backfill, and
 * MealsDB_Derived_Value_Check::expected_delivery_day(). One lookup means
 * the vocabulary can never fork again (the old fork — form-side 'WED AM'
 * vs consumer-side 'wednesday' — silently dropped clients off every
 * packer/driver slip).
 *
 * Canonical stored format: LOWERCASE full English day name ('wednesday'),
 * which is what the slip SQL (LOWER(delivery_day) = …), the occurrence
 * mapper, and MealsDB_Date_Calculator::DAY_OFFSET already expect.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Zone_Day {

    public const SCHEDULE_OPTION = 'mealsdb_zone_delivery_schedule';

    /**
     * Delivery day for a zone, in canonical stored form (lowercase full
     * day name), or null when it cannot be resolved: blank/unknown zone,
     * missing/empty schedule, or a malformed entry. Null is SKIP
     * semantics for callers — never a fatal, never a guess.
     */
    public static function day_for_zone(?string $area_name): ?string {
        if ($area_name === null) {
            return null;
        }
        $key = trim($area_name);
        if ($key === '') {
            return null;
        }
        $schedule = self::schedule();
        if (!isset($schedule[$key])) {
            return null;
        }
        return strtolower($schedule[$key]['day']);
    }

    /**
     * The validated zone schedule: only well-formed entries (array config
     * with a non-empty day). Day keeps its stored case ('Wednesday') for
     * display; use day_for_zone() for the canonical stored form.
     *
     * @return array<string, array{day: string, label: string}>
     */
    public static function schedule(): array {
        $raw = function_exists('get_option') ? get_option(self::SCHEDULE_OPTION, []) : [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $zone => $config) {
            // Operator-set option — don't trust its shape (same defense as
            // the old backfill): skip malformed rows instead of warning.
            if (!is_array($config)) {
                continue;
            }
            $day = trim((string) ($config['day'] ?? ''));
            if ($day === '') {
                continue;
            }
            $out[(string) $zone] = [
                'day'   => $day,
                'label' => (string) ($config['label'] ?? ''),
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php tests/test-zone-day.php`
Expected: `PASS — 14 checks`

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-zone-day.php tests/test-zone-day.php
git commit -m "feat(clients): MealsDB_Zone_Day — single zone→delivery-day lookup

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 2: Propagation + resync on the service (TDD)

**Files:**
- Modify: `includes/services/class-zone-day.php`
- Modify: `tests/test-zone-day.php` (append)

- [ ] **Step 1: Append the failing tests**

Append to `tests/test-zone-day.php`, ABOVE the final `if (empty($failures))` block. Also add these stubs right after the existing `__()` stub near the top of the file:

```php
if (!class_exists('MealsDB_Logger')) {
    class MealsDB_Logger {
        public static array $logged = [];
        public static function log(string $action, int $target_id, string $field, ?string $old, ?string $new, string $source = 'mealsdb') {
            self::$logged[] = compact('action', 'target_id', 'field', 'old', 'new');
        }
        public static function error(string $m): void {}
    }
}
if (!class_exists('MealsDB_Event_Log')) {
    class MealsDB_Event_Log {
        public static array $events = [];
        public static function record(array $e): void { self::$events[] = $e; }
    }
}
if (!class_exists('MealsDB_DB')) {
    class MealsDB_DB { public static function get_table_name(string $t): string { return 'wp_' . $t; } }
}
if (!class_exists('MealsDB_Tables')) {
    class MealsDB_Tables { public const CLIENTS = 'meals_clients'; }
}

/**
 * wpdb stub: records queries; get_results returns canned rows per call;
 * query() returns a canned rows_affected per matching UPDATE.
 */
class ZdWpdb {
    public array $queries = [];
    public array $results_queue = [];
    public int $rows_affected = 0;
    public int $affected_per_update = 0;
    public function prepare($sql, ...$args) {
        foreach ($args as $a) {
            $sql = preg_replace('/%[ds]/', is_int($a) ? (string) $a : "'" . $a . "'", $sql, 1);
        }
        return $sql;
    }
    public function query($sql) {
        $this->queries[] = $sql;
        $this->rows_affected = $this->affected_per_update;
        return $this->affected_per_update;
    }
    public function get_results($sql, $output = null) {
        $this->queries[] = $sql;
        return array_shift($this->results_queue) ?? [];
    }
    public function get_var($sql) {
        $this->queries[] = $sql;
        return array_shift($this->results_queue) ?? 0;
    }
}
```

Then the test body (before the final pass/fail block):

```php
// ------------------------------------------------------------------
// propagate_schedule_change()
// ------------------------------------------------------------------
$GLOBALS['zd_schedule'] = []; // propagate takes schedules as args, not the option
$wpdb_stub = new ZdWpdb();
$wpdb_stub->affected_per_update = 3;
$GLOBALS['wpdb'] = $wpdb_stub;
MealsDB_Logger::$logged = [];
MealsDB_Event_Log::$events = [];

$old = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'a'],
    'Zone 2' => ['day' => 'Wednesday', 'label' => 'b'],
    'Gone'   => ['day' => 'Friday',    'label' => 'c'],
];
$new = [
    'Zone 1' => ['day' => 'Thursday',  'label' => 'a'],  // changed
    'Zone 2' => ['day' => 'Wednesday', 'label' => 'b2'], // label-only change: no propagation
];
$wpdb_stub->results_queue = [2]; // get_var: 2 active clients still reference 'Gone'
$stats = MealsDB_Zone_Day::propagate_schedule_change($old, $new);

zd_check('propagate: changed zones', $stats['changed_zones'], ['Zone 1']);
zd_check('propagate: rows updated', $stats['clients_updated'], 3);
zd_check('propagate: dropped zones', $stats['dropped_zones'], ['Gone' => 2]);
$has_update = false;
foreach ($wpdb_stub->queries as $q) {
    if (strpos($q, 'UPDATE') !== false && strpos($q, "'thursday'") !== false && strpos($q, "'Zone 1'") !== false) {
        $has_update = true;
    }
}
zd_check('propagate: UPDATE uses lowercase day + zone key', $has_update, true);
zd_check('propagate: audit-logged', count(MealsDB_Logger::$logged), 1);
zd_check('propagate: dropped zone degraded event', MealsDB_Event_Log::$events[0]['outcome'] ?? '', 'degraded');

// No changes → no writes, no events.
$wpdb_stub->queries = [];
MealsDB_Event_Log::$events = [];
$stats = MealsDB_Zone_Day::propagate_schedule_change($new, $new);
zd_check('propagate no-op: zones', $stats['changed_zones'], []);
zd_check('propagate no-op: no queries', $wpdb_stub->queries, []);
zd_check('propagate no-op: no events', MealsDB_Event_Log::$events, []);

// ------------------------------------------------------------------
// resync_all()
// ------------------------------------------------------------------
$GLOBALS['zd_schedule'] = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'a'],
    'Zone 5' => ['day' => 'Friday',    'label' => 'b'],
];
$wpdb_stub = new ZdWpdb();
$wpdb_stub->affected_per_update = 4; // per-zone UPDATE reports 4 corrected rows
$GLOBALS['wpdb'] = $wpdb_stub;
MealsDB_Event_Log::$events = [];
// One get_results call: the orphan SELECT.
$wpdb_stub->results_queue = [
    [
        ['client_id' => 7, 'first_name' => 'A', 'last_name' => 'B', 'delivery_area_name' => 'Old Zone'],
    ],
];
$out = MealsDB_Zone_Day::resync_all();

zd_check('resync: updated', $out['updated'], 8); // 2 zones × 4
zd_check('resync: orphan count', count($out['orphans']), 1);
zd_check('resync: orphan zone value', $out['orphans'][0]['delivery_area_name'], 'Old Zone');
zd_check('resync: orphan degraded event', MealsDB_Event_Log::$events[0]['event'] ?? '', 'delivery_day.orphaned_clients');
$zone_updates = 0;
foreach ($wpdb_stub->queries as $q) {
    if (strpos($q, 'UPDATE') !== false) { $zone_updates++; }
}
zd_check('resync: one UPDATE per zone', $zone_updates, 2);

// Empty schedule → refuses (null), no queries.
$GLOBALS['zd_schedule'] = [];
$wpdb_stub->queries = [];
zd_check('resync: empty schedule refuses', MealsDB_Zone_Day::resync_all(), null);
zd_check('resync: empty schedule no queries', $wpdb_stub->queries, []);
```

- [ ] **Step 2: Run to verify failure**

Run: `php tests/test-zone-day.php`
Expected: fatal — `propagate_schedule_change` undefined.

- [ ] **Step 3: Implement propagation + resync**

Append to `includes/services/class-zone-day.php` (inside the class, after `schedule()`):

```php
    /**
     * Apply a schedule edit to client rows: any zone whose DAY changed
     * updates every active client in that zone (delivery_day is a synced
     * cache — spec 2026-07-11). Zones present in $old but dropped from
     * $new are counted and reported as a degraded event when active
     * clients still reference them (they become orphans until the
     * operator re-zones them).
     *
     * @param array<string, mixed> $old_schedule Option value before the save.
     * @param array<string, mixed> $new_schedule Option value after the save.
     * @return array{changed_zones: string[], clients_updated: int, dropped_zones: array<string, int>}
     */
    public static function propagate_schedule_change(array $old_schedule, array $new_schedule): array {
        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $norm = static function ($config): ?string {
            if (!is_array($config)) {
                return null;
            }
            $day = strtolower(trim((string) ($config['day'] ?? '')));
            return $day === '' ? null : $day;
        };

        $stats = ['changed_zones' => [], 'clients_updated' => 0, 'dropped_zones' => []];

        foreach ($new_schedule as $zone => $config) {
            $new_day = $norm($config);
            if ($new_day === null) {
                continue;
            }
            $old_day = $norm($old_schedule[(string) $zone] ?? null);
            if ($new_day === $old_day) {
                continue; // day unchanged (label-only edits don't touch clients)
            }
            $affected = (int) $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET delivery_day = %s
                 WHERE delivery_area_name = %s AND active = 1",
                $new_day,
                (string) $zone
            ));
            $stats['changed_zones'][]   = (string) $zone;
            $stats['clients_updated']  += max(0, $affected);
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log(
                    'zone_day_propagated',
                    0,
                    'delivery_day',
                    (string) ($old_day ?? ''),
                    sprintf('%s (%s, %d clients)', $new_day, $zone, max(0, $affected))
                );
            }
        }

        foreach ($old_schedule as $zone => $config) {
            if (array_key_exists((string) $zone, $new_schedule)) {
                continue;
            }
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE delivery_area_name = %s AND active = 1",
                (string) $zone
            ));
            if ($count > 0) {
                $stats['dropped_zones'][(string) $zone] = $count;
            }
        }

        if (!empty($stats['dropped_zones']) && class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'sync',
                'subsystem' => 'zone_day',
                'event'     => 'zone_schedule.zone_dropped',
                'outcome'   => 'degraded',
                'message'   => 'Zone(s) removed from the delivery schedule while active clients still reference them.',
                'context'   => ['dropped_zones' => $stats['dropped_zones']],
            ]);
        }

        return $stats;
    }

    /**
     * One-time (re-runnable) resync: overwrite every active client's
     * delivery_day from their zone's schedule day, and report ORPHANS —
     * active clients whose delivery_area_name resolves to no zone. Orphans
     * are the clients at risk of silently falling off packer/driver slips;
     * they are surfaced in the return value AND as one degraded event.
     *
     * Returns null when no schedule is configured (refuses rather than
     * blanking 890 rows).
     *
     * @return array{updated: int, orphans: array<int, array<string, mixed>>}|null
     */
    public static function resync_all(): ?array {
        $schedule = self::schedule();
        if (empty($schedule)) {
            return null;
        }

        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // Orphans first: active clients whose zone is not a schedule key.
        // Names are fine to return to this manage_options-only caller
        // (first/last name are not in ENCRYPTED_CLIENT_COLUMNS); the EVENT
        // context carries ids only (PII-lean by construction).
        $placeholders = implode(',', array_fill(0, count($schedule), '%s'));
        $orphans = $wpdb->get_results($wpdb->prepare(
            "SELECT client_id, first_name, last_name, delivery_area_name
             FROM `{$table}`
             WHERE active = 1
               AND (delivery_area_name IS NULL OR delivery_area_name = ''
                    OR delivery_area_name NOT IN ({$placeholders}))
             ORDER BY delivery_area_name, last_name",
            ...array_keys($schedule)
        ), ARRAY_A);
        $orphans = is_array($orphans) ? $orphans : [];

        // One UPDATE per zone: only rows whose cached day is wrong.
        $updated = 0;
        foreach ($schedule as $zone => $config) {
            $day = strtolower($config['day']);
            $affected = (int) $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET delivery_day = %s
                 WHERE delivery_area_name = %s AND active = 1
                   AND (delivery_day IS NULL OR delivery_day <> %s)",
                $day,
                (string) $zone,
                $day
            ));
            $updated += max(0, $affected);
        }

        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log(
                'delivery_day_resync',
                0,
                'delivery_day',
                null,
                sprintf('%d updated, %d orphans', $updated, count($orphans))
            );
        }
        if (!empty($orphans) && class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'sync',
                'subsystem' => 'zone_day',
                'event'     => 'delivery_day.orphaned_clients',
                'outcome'   => 'degraded',
                'message'   => sprintf('%d active client(s) reference no configured delivery zone and will not appear on slips.', count($orphans)),
                'context'   => ['client_ids' => array_map(static fn($o) => (int) $o['client_id'], $orphans)],
            ]);
        }

        return ['updated' => $updated, 'orphans' => $orphans];
    }
```

Note: `resync: updated == 8` works because the stub returns 4 per UPDATE and there are 2 zones; the UPDATE's `delivery_day <> %s` guard makes "updated" mean "was wrong, now fixed" — the number the operator needs.

- [ ] **Step 4: Run the test**

Run: `php tests/test-zone-day.php`
Expected: `PASS — 30 checks` (14 from Task 1 + 16 new)

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-zone-day.php tests/test-zone-day.php
git commit -m "feat(clients): zone-day propagation on schedule change + resync with orphan report

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 3: Client form derives delivery_day from zone (validation + save paths)

**Files:**
- Modify: `includes/class-client-form.php` (enum rules ~1965-2041, `validate()` ~421, `save()` ~876, `update()` ~1110)
- Test: `tests/test-client-form-zone-day.php` (create), plus existing `tests/test-client-form.php` and `tests/test-client-save-index-guard.php` fixture updates

- [ ] **Step 1: Write the failing test**

Create `tests/test-client-form-zone-day.php`. Follow the stub pattern at the top of `tests/test-client-form.php` (copy its ABSPATH/autoloader/stub preamble verbatim — it already stubs `__`, `sanitize_text_field`, `apply_filters`, wpdb, etc.), add the `get_option` schedule stub from `tests/test-zone-day.php`, then:

```php
$GLOBALS['zd_schedule'] = [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'Moncton Downtown'],
];

// 1. validate(): a zone that is not a schedule key is a field error.
$data = mealsdb_test_minimal_valid_client(); // reuse/adapt the fixture builder from test-client-form.php
$data['delivery_area_name'] = 'Nope Zone';
unset($data['delivery_day']);
$errors = MealsDB_Client_Form::validate($data);
zd_check('validate: unknown zone rejected', isset($errors['delivery_area_name']), true);

// 2. validate(): schedule zone passes; no delivery_day input is required or checked.
$data['delivery_area_name'] = 'Zone 1';
$errors = MealsDB_Client_Form::validate($data);
zd_check('validate: known zone accepted', isset($errors['delivery_area_name']), false);

// 3. validate(): a posted delivery_day value is IGNORED, not validated
//    (stale form / old vocabulary must not block a save).
$data['delivery_day'] = 'WED AM';
$errors = MealsDB_Client_Form::validate($data);
zd_check('validate: posted delivery_day ignored', isset($errors['delivery_day']), false);

// 4. save(): stored delivery_day is derived (lowercase), posted value discarded.
//    Use the wpdb-capture stub from test-client-form.php and assert the
//    INSERT/UPDATE payload contains delivery_day = 'wednesday'.
```

(Complete assertions 4 using the same wpdb capture mechanism `test-client-form.php` uses for its save assertions — read that file's save test and mirror it. The assertion: the captured column map has `'delivery_day' => 'wednesday'` even though the input said `'WED AM'`.)

- [ ] **Step 2: Run to verify failure**

Run: `php tests/test-client-form-zone-day.php`
Expected: FAIL — unknown zone is not rejected, and stored delivery_day is `'WED AM'`.

- [ ] **Step 3: Implement**

In `includes/class-client-form.php`:

1. **Delete the `WED AM` vocabulary.** In `get_enum_validation_rules()` (~1965): remove `$delivery_day_allowed` (the 5-value array), the `mealsdb_allowed_delivery_days` filter block, the normalization of that array, and the whole `'delivery_day' => […]` entry from the returned rules array. Leave the other enum rules untouched.

2. **Add zone validation.** In `validate()`, immediately after the enum-validation `foreach` loop (~444), add:

```php
        // Zone is the sole source of truth for delivery_day (spec
        // 2026-07-11): the zone itself must resolve. A blank zone is
        // handled by the existing required-field logic per client type;
        // a NON-blank zone that matches no schedule key would silently
        // drop the client off every packer/driver slip, so it is a hard
        // field error here.
        if (isset($sanitized['delivery_area_name']) && trim((string) $sanitized['delivery_area_name']) !== ''
            && class_exists('MealsDB_Zone_Day')
            && MealsDB_Zone_Day::day_for_zone((string) $sanitized['delivery_area_name']) === null) {
            $record_format_error('delivery_area_name', 'Delivery area does not match any configured delivery zone.');
        }
```

3. **Derive on save.** In `save()`, immediately after `$sanitized = self::map_form_to_db($sanitized);` (line 876), add:

```php
        // delivery_day is a zone-derived cache — NEVER trust the posted
        // value (spec 2026-07-11). Derive from the selected zone; with a
        // blank zone, drop the key entirely rather than persist a stale
        // posted day. validate() has already rejected unresolvable
        // non-blank zones, but this path re-guards for callers that skip
        // the form flow (defense-in-depth, Pattern 1).
        $sanitized = self::apply_zone_delivery_day($sanitized);
        if ($sanitized === null) {
            self::$last_save_error = __('Delivery area does not match any configured delivery zone.', 'meals-db');
            error_log('[MealsDB] Save aborted: unresolvable delivery zone.');
            return false;
        }
```

4. **Same in `update()`**, immediately after its `$sanitized = self::map_form_to_db($sanitized);` (line 1110) — identical block (same comment, `Update aborted` in the error_log line).

5. **The shared helper** (add near `map_form_to_db()`):

```php
    /**
     * Overwrite delivery_day from the zone (or strip it). Returns null
     * when a NON-blank zone cannot be resolved — the caller must abort.
     *
     * - zone present + resolvable  → delivery_day := lowercase day
     * - zone present + unresolvable → null (abort signal)
     * - zone blank                  → delivery_day key removed (never
     *                                 write a day the zone didn't produce)
     * - zone key absent from payload (partial update) → delivery_day key
     *   removed too: a delivery_day submitted without its zone has no
     *   authority.
     *
     * @param array<string, mixed> $sanitized DB-side payload.
     * @return array<string, mixed>|null
     */
    private static function apply_zone_delivery_day(array $sanitized): ?array {
        unset($sanitized['delivery_day']);
        if (!array_key_exists('delivery_area_name', $sanitized)) {
            return $sanitized;
        }
        $zone = trim((string) $sanitized['delivery_area_name']);
        if ($zone === '') {
            return $sanitized;
        }
        if (!class_exists('MealsDB_Zone_Day')) {
            return $sanitized; // degraded: cannot derive, but don't block the save on a missing class
        }
        $day = MealsDB_Zone_Day::day_for_zone($zone);
        if ($day === null) {
            return null;
        }
        $sanitized['delivery_day'] = $day;
        return $sanitized;
    }
```

- [ ] **Step 4: Run new + existing form tests; fix fixtures**

Run: `php tests/test-client-form-zone-day.php && php tests/test-client-form.php && php tests/test-client-save-index-guard.php`

Both existing files carry `'delivery_day' => 'WED AM'` fixtures (test-client-form.php:230, test-client-save-index-guard.php:248). Update those fixtures to the new model: add a `get_option` stub for the schedule (if the file doesn't have one), set `'delivery_area_name' => 'Zone 1'` (with `'Zone 1' => ['day' => 'Wednesday', …]` in the stubbed schedule), and adjust any assertion that expected `WED AM` to expect `wednesday`. Do NOT weaken unrelated assertions.

Expected: all three PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/class-client-form.php tests/test-client-form-zone-day.php tests/test-client-form.php tests/test-client-save-index-guard.php
git commit -m "feat(clients): derive delivery_day from zone; delete WED AM vocabulary

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 4: Client form UI — zone dropdown + read-only day display + live JS

**Files:**
- Modify: `includes/class-admin-ui.php` (rows ~1552-1576, format map ~1196, options setup ~1218/~1260, JS enqueue block ~543-575)
- Create: `assets/js/client-zone-day.js`

- [ ] **Step 1: Replace the two form rows**

In `includes/class-admin-ui.php`, inside `render_client_form()`:

1. Remove the `$delivery_day_options = MealsDB_Client_Form::get_allowed_options('delivery_day');` line (~1218) and the `$delivery_day_value = $normalize_field_value('delivery_day', …)` line (~1260). Add in their place:

```php
        $zone_schedule = class_exists('MealsDB_Zone_Day') ? MealsDB_Zone_Day::schedule() : [];
```

2. Remove the `'delivery_day' => 'upper',` entry from the `$field_formats` map (~1196).

3. Replace the `delivery_day` select row callback (~1552-1567) with a read-only display row:

```php
            static function (array $client) use ($zone_schedule) {
                $zone = trim((string) ($client['delivery_area_name'] ?? ''));
                $cfg  = $zone_schedule[$zone] ?? null;
                if ($cfg !== null) {
                    $display = $cfg['day'] . ($cfg['label'] !== '' ? ' — ' . $cfg['label'] : '');
                } elseif ($zone !== '') {
                    $display = __('⚠ zone not in schedule', 'meals-db');
                } else {
                    $display = '—';
                }
                ?>
                <tr>
                    <th><?php esc_html_e('Delivery Day', 'meals-db'); ?></th>
                    <td>
                        <span id="mealsdb-zone-day-display"><?php echo esc_html($display); ?></span>
                        <p class="description"><?php esc_html_e('Determined by the delivery zone (Settings → Zone Delivery Schedule). Not directly editable.', 'meals-db'); ?></p>
                    </td>
                </tr>
                <?php
            },
```

4. Replace the `delivery_area_name` text-input row callback (~1569-1576) with a schedule-constrained select:

```php
            static function (array $client) use ($zone_schedule) {
                $current = trim((string) ($client['delivery_area_name'] ?? ''));
                $known   = $current !== '' && isset($zone_schedule[$current]);
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_area_name"><?php esc_html_e('Delivery Area Name *', 'meals-db'); ?></label></th>
                    <td>
                        <select name="delivery_area_name" id="delivery_area_name" class="regular-text" required data-base-required="1">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <?php if ($current !== '' && !$known) : ?>
                                <?php // Legacy value not in the schedule: keep it selected-but-flagged so an
                                      // untouched record isn't corrupted, but editing forces a real choice. ?>
                                <option value="<?php echo esc_attr($current); ?>" selected>⚠ <?php echo esc_html($current); ?> <?php esc_html_e('(not in schedule)', 'meals-db'); ?></option>
                            <?php endif; ?>
                            <?php foreach (array_keys($zone_schedule) as $zone_name) : ?>
                                <option value="<?php echo esc_attr($zone_name); ?>" <?php selected($current, $zone_name); ?>><?php echo esc_html($zone_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php
            },
```

5. At the END of `render_client_form()` (after the closing form markup, alongside any existing island output), emit the zone map island:

```php
        // Zone→day map for the live read-only display (client-zone-day.js).
        // JSON island per the plugin pattern — JSON_HEX_* makes it <script>-safe.
        echo '<script type="application/json" id="mealsdb-zone-day-data">'
            . wp_json_encode($zone_schedule, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . '</script>';
```

- [ ] **Step 2: Create the JS**

Create `assets/js/client-zone-day.js`:

```js
/**
 * Client form — live zone→delivery-day display (spec 2026-07-11).
 *
 * delivery_day is zone-derived and read-only; this keeps the display in
 * sync as the operator changes the zone select. The server re-derives
 * authoritatively on save — this is purely cosmetic.
 */
(function ($) {
    'use strict';

    var el = document.getElementById('mealsdb-zone-day-data');
    if (!el) { return; }
    var zones = {};
    try { zones = JSON.parse(el.textContent || '{}'); } catch (e) { zones = {}; }

    function render() {
        var $out = $('#mealsdb-zone-day-display');
        if (!$out.length) { return; }
        var zone = String($('#delivery_area_name').val() || '');
        var cfg  = zones[zone];
        if (cfg && cfg.day) {
            $out.text(cfg.day + (cfg.label ? ' — ' + cfg.label : ''));
        } else if (zone !== '') {
            $out.text('⚠ zone not in schedule');
        } else {
            $out.text('—');
        }
    }

    $(document).on('change', '#delivery_area_name', render);
    $(render);
})(jQuery);
```

- [ ] **Step 3: Enqueue it**

In `includes/class-admin-ui.php`, inside the existing `if ($tab === 'add' || ($tab === 'clients' && $action === 'edit')) {` block (~566, where `mealsdb-client-wp-user` is enqueued), add after that script's enqueue:

```php
            $zone_day_path = MEALS_DB_PLUGIN_DIR . 'assets/js/client-zone-day.js';
            wp_enqueue_script(
                'mealsdb-client-zone-day',
                MEALS_DB_PLUGIN_URL . 'assets/js/client-zone-day.js',
                ['jquery'],
                file_exists($zone_day_path) ? filemtime($zone_day_path) : MEALS_DB_VERSION,
                true
            );
```

- [ ] **Step 4: Lint + stale-reference check**

```bash
cd /mnt/fastssd/meals-db && php -l includes/class-admin-ui.php && node --check assets/js/client-zone-day.js && grep -rn "mealsdb_allowed_delivery_days\|WED AM\|THURS AM" --include="*.php" --include="*.js" includes/ assets/ views/
```

Expected: lint clean; grep prints nothing (exit 1).

- [ ] **Step 5: Commit**

```bash
git add includes/class-admin-ui.php assets/js/client-zone-day.js
git commit -m "feat(clients): zone dropdown + read-only zone-derived delivery day on the client form

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 5: Settings — Mon–Fri trim, propagation on save, auto-correct default/copy

**Files:**
- Modify: `views/settings.php` (~164 `$days`, ~222-226 auto-correct caption)
- Modify: `includes/ajax/class-ajax-settings.php` (zone block ~249-266)
- Modify: `meals-db-main.php` (~212, after the zone-schedule seeding block)

- [ ] **Step 1: Trim the day lists to Monday–Friday**

In `views/settings.php` line ~164, change:

```php
                $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
```
to:
```php
                // Weekend deliveries don't exist operationally; keeping the
                // list tight prevents a misclick from parking a whole zone on
                // a day no driver runs (spec 2026-07-11).
                $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
```

In `includes/ajax/class-ajax-settings.php` (~251), make the identical change to `$valid_days`.

- [ ] **Step 2: Wire propagation into the settings save**

In `includes/ajax/class-ajax-settings.php`, the zone block currently reads (~249-266):

```php
        // Save zone delivery schedule if provided (Phase Q).
        if ( isset( $_POST['zone_schedule'] ) && is_array( $_POST['zone_schedule'] ) ) {
            …
            if ( ! empty( $schedule ) ) {
                update_option( 'mealsdb_zone_delivery_schedule', $schedule, false );
            }
        }
```

Change the inner `if ( ! empty( $schedule ) )` body to:

```php
            if ( ! empty( $schedule ) ) {
                $old_schedule = get_option( 'mealsdb_zone_delivery_schedule', [] );
                update_option( 'mealsdb_zone_delivery_schedule', $schedule, false );
                // delivery_day is a zone-derived cache: a day change here must
                // reach every active client in the zone NOW, not at the next
                // nightly drift pass (spec 2026-07-11). Dropped zones with
                // active clients are recorded as a degraded event inside.
                if ( is_array( $old_schedule ) && class_exists( 'MealsDB_Zone_Day' ) ) {
                    MealsDB_Zone_Day::propagate_schedule_change( $old_schedule, $schedule );
                }
            }
```

- [ ] **Step 3: Auto-correct — seed default ON for fresh installs, update the caption**

In `meals-db-main.php`, directly after the zone-schedule seeding block (after its closing `}` at ~212), add:

```php
        // delivery_day is zone-derived (spec 2026-07-11): fresh installs get
        // nightly auto-correct ON for it. Installs with a stored option keep
        // their choice (save_settings always writes all three keys, so any
        // operator who has ever saved settings has one).
        if (false === get_option('mealsdb_derived_autocorrect')) {
            add_option('mealsdb_derived_autocorrect', [
                'next_order_date'    => 0,
                'next_delivery_date' => 0,
                'delivery_day'       => 1,
            ], '', 'no');
        }
```

In `views/settings.php` (~222-226), replace:

```php
                                <?php if ( $field === 'delivery_day' ) : ?>
                                    <em><?php echo esc_html__( '(not recommended — zone overrides are legitimate)', 'meals-db' ); ?></em>
                                <?php endif; ?>
```
with:
```php
                                <?php if ( $field === 'delivery_day' ) : ?>
                                    <em><?php echo esc_html__( '(recommended ON — delivery day is derived from the zone; per-client overrides are no longer supported and drift is rewritten nightly, audit-logged)', 'meals-db' ); ?></em>
                                <?php endif; ?>
```

- [ ] **Step 4: Lint**

```bash
php -l views/settings.php && php -l includes/ajax/class-ajax-settings.php && php -l meals-db-main.php
```
Expected: clean ×3.

- [ ] **Step 5: Commit**

```bash
git add views/settings.php includes/ajax/class-ajax-settings.php meals-db-main.php
git commit -m "feat(settings): Mon–Fri zone days, propagate day changes to clients, delivery_day auto-correct default

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 6: Resync endpoint + button; retire the old blank-fill backfill

**Files:**
- Modify: `includes/ajax/class-ajax-settings.php` (new endpoint + registration)
- Modify: `includes/ajax/class-ajax-delivery-slips.php` (remove registration line 34 + `backfill_delivery_day()` method ~109-178)
- Modify: `views/data-ops.php` (remove the "Backfill Delivery Day" section ~148-159)
- Modify: `views/settings.php` (add resync button after the zone schedule table)
- Modify: `assets/js/settings.js` (replace handler at lines 38-59)

- [ ] **Step 1: Add the endpoint**

In `includes/ajax/class-ajax-settings.php` `init()`, add:

```php
        add_action( 'wp_ajax_mealsdb_resync_delivery_days', [ self::class, 'resync_delivery_days' ] );
```

Add the handler (after `backfill_next_dates()`; copy its guard spine — same nonce context, capability, and bucket):

```php
    /**
     * Resync every active client's delivery_day from their zone (spec
     * 2026-07-11). REPLACES the old blank-fill-only backfill
     * (mealsdb_backfill_delivery_day): this one OVERWRITES wrong values —
     * delivery_day is a derived cache, so overwriting is always safe —
     * and reports orphans (clients whose zone resolves to nothing).
     */
    public static function resync_delivery_days(): void {
        check_ajax_referer( 'mealsdb_settings_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'You are not allowed to perform this action.', 'meals-db' ) ], 403 );
        }
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'migration_destructive' ) ) {
            wp_send_json_error( [ 'message' => __( 'Resync is rate-limited. Please wait before retrying.', 'meals-db' ) ], 429 );
        }

        $result = MealsDB_Zone_Day::resync_all();
        if ( $result === null ) {
            wp_send_json_error( [ 'message' => __( 'No zone delivery schedule is configured — refusing to resync.', 'meals-db' ) ] );
        }
        wp_send_json_success( $result );
    }
```

**Verify the nonce context first:** open `backfill_next_dates()` in the same file and use the SAME `check_ajax_referer(...)` action string it uses (expected `mealsdb_settings_nonce`; if it differs, match it — settings.js posts that nonce).

- [ ] **Step 2: Remove the old endpoint and button**

1. `includes/ajax/class-ajax-delivery-slips.php`: delete the registration `add_action('wp_ajax_mealsdb_backfill_delivery_day', …)` (line 34) and the whole `backfill_delivery_day()` method (docblock at ~108 through its closing brace — it ends at the `wp_send_json([... 'Updated delivery_day for %d clients.' ...]);` + `}`).
2. `views/data-ops.php`: delete the "Backfill Delivery Day" `<h2>` + description + button paragraph block (~147-159, starting at the `<hr style="margin:24px 0;">` above the heading, ending before the "Backfill Next-Order / Next-Delivery Dates" `<h2>`).

- [ ] **Step 3: Add the settings button + JS**

In `views/settings.php`, immediately after the zone-schedule `</table>` (~193), add:

```php
        <p>
            <button type="button" class="button" id="mealsdb-resync-delivery-days">
                <?php echo esc_html__( 'Resync delivery days from zones', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-resync-result" style="margin-left:12px;"></span>
        </p>
        <div id="mealsdb-resync-orphans" style="display:none; margin-top:8px;"></div>
        <p class="description">
            <?php echo esc_html__( 'Overwrites every active client\'s delivery day from their zone\'s scheduled day (it is a derived value — this is always safe), and lists clients whose Delivery Area matches no zone; those clients will not appear on slips until re-zoned. After a clean resync, enable the Delivery Day auto-correct toggle below so drift cannot return.', 'meals-db' ); ?>
        </p>
```

In `assets/js/settings.js`, replace the old `#mealsdb-backfill-delivery-day` handler (lines 38-59) with:

```js
    $('#mealsdb-resync-delivery-days').on('click', function () {
        var $btn = $(this).prop('disabled', true);
        var $result = $('#mealsdb-resync-result');
        var $orphans = $('#mealsdb-resync-orphans').hide().empty();
        $result.text('Running…');
        $.post(ajaxurl, {
            action: 'mealsdb_resync_delivery_days',
            nonce: mealsdbSettings.nonce
        }, function (res) {
            $btn.prop('disabled', false);
            if (!res || !res.success) {
                $result.text((res && res.data && res.data.message) || 'Request failed.');
                return;
            }
            var d = res.data || {};
            var orphans = d.orphans || [];
            $result.text(d.updated + ' client(s) updated, ' + orphans.length + ' orphan(s).');
            if (orphans.length) {
                var $list = $('<ul style="margin:4px 0 0 16px; list-style:disc;"></ul>');
                $.each(orphans, function (_, o) {
                    // .text() per item — orphan names/zones are data, not HTML.
                    $('<li></li>').text(
                        '#' + o.client_id + ' ' + (o.first_name || '') + ' ' + (o.last_name || '')
                        + ' — zone: ' + (o.delivery_area_name || '(blank)')
                    ).appendTo($list);
                });
                $orphans.show()
                    .append($('<strong></strong>').text('Orphaned clients (fix their zone on the client form):'))
                    .append($list);
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.text('Request failed.');
        });
    });
```

**Verify the nonce variable first:** check how the surrounding handlers in `settings.js` reference the nonce (e.g. `mealsdbSettings.nonce` or an inline value) and match exactly. Also confirm `settings.js` is enqueued on the settings tab (it is — the existing zone-schedule save uses it).

- [ ] **Step 4: Lint + reference check**

```bash
php -l includes/ajax/class-ajax-settings.php && php -l includes/ajax/class-ajax-delivery-slips.php && php -l views/settings.php && php -l views/data-ops.php && node --check assets/js/settings.js && grep -rn "backfill_delivery_day\|backfill-delivery-day" --include="*.php" --include="*.js" includes/ views/ assets/
```
Expected: lint clean ×4 + node clean; grep prints nothing.

- [ ] **Step 5: Commit**

```bash
git add includes/ajax/class-ajax-settings.php includes/ajax/class-ajax-delivery-slips.php views/settings.php views/data-ops.php assets/js/settings.js
git commit -m "feat(settings): resync delivery days from zones (replaces blank-fill backfill), orphan report

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 7: Route the drift checker through the service

**Files:**
- Modify: `includes/services/class-derived-value-check.php` (`expected_delivery_day()` ~175-197)
- Modify: `tests/test-derived-value-check.php`

- [ ] **Step 1: Reimplement `expected_delivery_day()`**

Replace the method body (keep the docblock's first paragraph, update the rest):

```php
    /**
     * Expected delivery_day: the day the zone delivery schedule assigns to
     * the client's delivery_area_name — via MealsDB_Zone_Day, the single
     * zone→day implementation shared with the form save, the settings-save
     * propagation, and the resync (spec 2026-07-11).
     *
     * Returns null (skip) when the zone cannot be resolved — the resync's
     * orphan report and its degraded event own that failure mode; the
     * nightly checker only measures drift on resolvable zones.
     */
    private static function expected_delivery_day(array $client): ?string {
        if (!class_exists('MealsDB_Zone_Day')) {
            return null;
        }
        return MealsDB_Zone_Day::day_for_zone(
            self::nullable($client['delivery_area_name'] ?? null)
        );
    }
```

(`day_for_zone` already lowercases and skips blank/unknown/malformed — identical semantics to the deleted inline lookup.)

- [ ] **Step 2: Update the test**

`tests/test-derived-value-check.php` stubs `get_option` for the schedule already (its fixtures use full-day-name values). Run it:

```bash
php tests/test-derived-value-check.php
```

If it fails on autoload/class stubs for `MealsDB_Zone_Day`, the autoloader resolves the real class — no stub needed. Add one assertion documenting the routing: with the schedule stub containing `'Zone X' => ['day' => 'Tuesday']` and a client `['delivery_area_name' => 'Zone X', 'delivery_day' => 'monday', …]`, the mismatch list flags `delivery_day` with expected `'tuesday'` (this proves the shared lookup is live).

Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add includes/services/class-derived-value-check.php tests/test-derived-value-check.php
git commit -m "refactor(clients): drift checker derives delivery_day via MealsDB_Zone_Day

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
```

---

### Task 8: Full verification

**Files:** none new.

- [ ] **Step 1: Full test suite**

```bash
cd /mnt/fastssd/meals-db && fails=0; for f in tests/test-*.php; do \
  out=$(php "$f" 2>&1) || { fails=$((fails+1)); echo "FAIL: $f"; echo "$out" | tail -5; }; done; \
  echo "---"; echo "failing scripts: $fails"
```
Expected: only the two known PDF baseline failures (`test-pdf-slip-binary-output.php`, `test-vac-pdf.php`). Any other failure: STOP and fix (likely a missed `WED AM` fixture).

- [ ] **Step 2: Lint every touched PHP file**

```bash
for f in includes/services/class-zone-day.php includes/class-client-form.php \
  includes/class-admin-ui.php views/settings.php views/data-ops.php \
  includes/ajax/class-ajax-settings.php includes/ajax/class-ajax-delivery-slips.php \
  includes/services/class-derived-value-check.php meals-db-main.php; do php -l "$f"; done
```
Expected: clean ×9.

- [ ] **Step 3: Repo-wide vocabulary sweep**

```bash
grep -rn "WED AM\|WED PM\|THURS AM\|THURS PM\|FRI AM\|mealsdb_allowed_delivery_days\|backfill_delivery_day" --include="*.php" --include="*.js" includes/ assets/ views/ tests/
```
Expected: nothing (exit 1). Historical docs (`docs/`, `directives/`) are exempt.

- [ ] **Step 4: Commit anything outstanding, if the sweep required fixes**

```bash
git status --short
```
Expected: clean tree.
