# Task: Backfill allowance_mains, allowance_sides, and fix requisition_period on meals_clients

## Problem

The migration in `includes/services/class-migration.php` has three bugs in how it maps old WordPress user meta to `meals_clients`:

1. **Lines 870–875:** `mains` and `sides` from `wp_usermeta` are concatenated into a notes string (`"Mains: 7, Sides: 5"`) and appended to `notes_to_service_provider`. They are never written to `allowance_mains` or `allowance_sides`.

2. **Line 896:** `$req_period = $meta['rate']` maps the old `rate` user meta field (which holds a price like `"14.66"`) to `requisition_period`. The correct source is `$meta['service']`, which holds the billing frequency (`day`, `week`, or `month`).

3. The old `service` user meta key is never read anywhere in the migration.

As a result, every migrated `meals_clients` record has:
- `allowance_mains` = NULL
- `allowance_sides` = NULL
- `requisition_period` = wrong value (a price string instead of `day`/`week`/`month`) or NULL

## What to do

### Part 1: Write a one-time backfill script

Create a new file: `includes/services/class-backfill-allowances.php`

This class reads the original `mains`, `sides`, and `service` values from the WordPress `wp_usermeta` table (where the old Enzebra plugins stored them) and writes them to the correct columns on the corresponding `meals_clients` row in the external database.

```php
<?php
/**
 * One-time backfill: populate allowance_mains, allowance_sides, and fix
 * requisition_period on meals_clients from legacy wp_usermeta values.
 *
 * @package MealsDB
 */

if (!defined('ABSPATH')) {
    exit;
}

class MealsDB_Backfill_Allowances {

    const BATCH_SIZE = 100;

    /**
     * Run the backfill (or dry-run).
     *
     * @param bool $dry_run If true, count and log but do not write.
     * @return array{updated: int, skipped: int, errors: int, total: int}
     */
    public static function run(bool $dry_run = true): array {
        global $wpdb;

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return ['error' => 'Cannot connect to external Meals DB.'];
        }

        $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));

        // Get all meals_clients rows that have a wp_user_id.
        $sql = sprintf(
            "SELECT client_id, wp_user_id, requisition_period, allowance_mains, allowance_sides
             FROM `%s`
             WHERE wp_user_id > 0
             ORDER BY client_id ASC",
            $clients_table
        );

        $result = $conn->query($sql);
        if (!MealsDB_DB::is_mysqli_result($result)) {
            return ['error' => 'Failed to query meals_clients.'];
        }

        $clients = [];
        while ($row = $result->fetch_assoc()) {
            $clients[] = $row;
        }
        $result->free();

        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0, 'total' => count($clients)];

        // Prepare the UPDATE statement.
        $update_sql = sprintf(
            "UPDATE `%s`
             SET allowance_mains = ?, allowance_sides = ?, requisition_period = ?
             WHERE client_id = ?",
            $clients_table
        );

        $stmt = null;
        if (!$dry_run) {
            $stmt = $conn->prepare($update_sql);
            if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
                return ['error' => 'Failed to prepare UPDATE statement: ' . ($conn->error ?? 'unknown')];
            }
        }

        foreach ($clients as $client) {
            $wp_user_id = (int) $client['wp_user_id'];
            $client_id  = (int) $client['client_id'];

            // Read the three legacy user meta values from wp_usermeta.
            $meta_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->usermeta}
                 WHERE user_id = %d AND meta_key IN ('mains', 'sides', 'service')",
                $wp_user_id
            ), ARRAY_A);

            $meta = [];
            if (is_array($meta_rows)) {
                foreach ($meta_rows as $mr) {
                    $meta[$mr['meta_key']] = $mr['meta_value'];
                }
            }

            $old_mains   = isset($meta['mains']) && $meta['mains'] !== '' ? (int) $meta['mains'] : null;
            $old_sides   = isset($meta['sides']) && $meta['sides'] !== '' ? (int) $meta['sides'] : null;
            $old_service = isset($meta['service']) && $meta['service'] !== '' ? strtolower(trim($meta['service'])) : null;

            // Normalize service to the expected values.
            // The old system used lowercase: 'day', 'week', 'month'.
            // meals_clients.requisition_period stores: 'Day', 'Week', 'Month' (or 'Weekly', 'Monthly', 'Daily').
            // The client form uses a select with values like 'Day', 'Week', 'Month'.
            $period_map = [
                'day'     => 'Day',
                'week'    => 'Week',
                'weekly'  => 'Week',
                'month'   => 'Month',
                'monthly' => 'Month',
                'daily'   => 'Day',
            ];
            $normalized_period = isset($period_map[$old_service]) ? $period_map[$old_service] : null;

            // Skip if there's nothing to write.
            if ($old_mains === null && $old_sides === null && $normalized_period === null) {
                $stats['skipped']++;
                continue;
            }

            if ($dry_run) {
                $stats['updated']++;
                error_log(sprintf(
                    '[MealsDB Backfill] DRY RUN: client_id=%d wp_user_id=%d → mains=%s sides=%s period=%s',
                    $client_id, $wp_user_id,
                    $old_mains ?? 'NULL', $old_sides ?? 'NULL', $normalized_period ?? 'NULL'
                ));
                continue;
            }

            // Write to meals_clients.
            // bind_param: allowance_mains (int|null), allowance_sides (int|null),
            //             requisition_period (string|null), client_id (int)
            $bind_mains  = $old_mains;
            $bind_sides  = $old_sides;
            $bind_period = $normalized_period;
            $bind_id     = $client_id;

            $stmt->bind_param('iisi', $bind_mains, $bind_sides, $bind_period, $bind_id);

            if ($stmt->execute()) {
                $stats['updated']++;
            } else {
                $stats['errors']++;
                error_log(sprintf(
                    '[MealsDB Backfill] ERROR updating client_id=%d: %s',
                    $client_id, $stmt->error ?? 'unknown'
                ));
            }
        }

        if ($stmt) {
            $stmt->close();
        }

        return $stats;
    }
}
```

### Part 2: Wire it into an AJAX handler

Add a new AJAX action in `includes/ajax/class-ajax-migration.php`. Find the `init()` method and add:

```php
add_action('wp_ajax_mealsdb_backfill_allowances', [__CLASS__, 'backfill_allowances']);
```

Then add this handler method to the class:

```php
/**
 * Backfill allowance_mains, allowance_sides, and requisition_period
 * from legacy wp_usermeta values.
 */
public static function backfill_allowances() {
    if (!check_ajax_referer('mealsdb_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid security token.']);
        return;
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
        return;
    }

    $dry_run = !empty($_POST['dry_run']);

    require_once dirname(dirname(__FILE__)) . '/services/class-backfill-allowances.php';

    $result = MealsDB_Backfill_Allowances::run($dry_run);

    if (isset($result['error'])) {
        wp_send_json_error(['message' => $result['error']]);
        return;
    }

    wp_send_json_success($result);
}
```

### Part 3: Fix the migration for future runs

In `includes/services/class-migration.php`, make these three changes so any future migration runs populate the fields correctly:

**Fix 1 — Line 896:** Change:
```php
$req_period = $meta['rate']                 ?? null;
```
To:
```php
$req_period = $meta['service']              ?? null;
```

Then normalize it (add right after that line):
```php
$period_map = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'weekly' => 'Week', 'monthly' => 'Month', 'daily' => 'Day'];
$req_period = isset($period_map[strtolower(trim($req_period ?? ''))]) ? $period_map[strtolower(trim($req_period))] : $req_period;
```

**Fix 2 — Lines 870–876:** The mains/sides values are currently only written to notes. Keep the notes behavior (it's useful context), but ALSO store the values in dedicated variables. After line 876, add:

```php
$allowance_mains_val = ($mains !== '' && $mains !== '0') ? (int) $mains : null;
$allowance_sides_val = ($sides !== '' && $sides !== '0') ? (int) $sides : null;
```

**Fix 3 — Add `allowance_mains` and `allowance_sides` to the INSERT statement.** In the INSERT column list (line 962 area), add these two columns after `requisition_period, units, client_contribution,`:

Change that section to:
```
requisition_period, units, client_contribution,
allowance_mains, allowance_sides,
```

And in the VALUES placeholders, add two more `?` in the corresponding position.

In the `bind_param` call (line 1016), update the type string and parameter list to include the two new int-or-null values (`$allowance_mains_val` and `$allowance_sides_val`) in the matching position — right after `$contrib`.

The type characters for both are `i` (integer). Insert them after the `d` for `$contrib` in the type string.

### Part 4: Add a button to the Migration admin page

In `views/updates.php` (or wherever the migration UI lives), add a "Backfill Allowances" section with:

```html
<div class="card" style="margin-top: 20px;">
    <h2 class="title">Backfill Allowance Data</h2>
    <div style="padding: 20px;">
        <p>Reads <code>mains</code>, <code>sides</code>, and <code>service</code> from legacy WordPress user meta and writes them to <code>allowance_mains</code>, <code>allowance_sides</code>, and <code>requisition_period</code> on the corresponding <code>meals_clients</code> record.</p>
        <p>
            <button type="button" class="button" id="backfill-dry-run">Dry Run</button>
            <button type="button" class="button button-primary" id="backfill-run" disabled>Run Backfill</button>
        </p>
        <div id="backfill-result" style="margin-top: 10px;"></div>
    </div>
</div>
```

Wire it with JS that POSTs to `mealsdb_backfill_allowances` using the existing `mealsdb_nonce` nonce. The dry run button calls with `dry_run: 1`, displays the result, then enables the run button. The run button calls with `dry_run: 0` (or omits the field).

## Key constraints

- The external database connection uses `MealsDB_DB::get_connection()` which returns a `mysqli` instance — NOT `$wpdb`. All queries to `meals_clients` use `mysqli` prepared statements.
- The WordPress user meta is read via `$GLOBALS['wpdb']` (the standard WordPress database abstraction) since it's in the WordPress database, not the external one.
- The `meals_clients` table name is resolved via `MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS)` — never hardcode the table name.
- The `MealsDB_Tables::CLIENTS` constant resolves to `'meals_clients'`.
- Column names on `meals_clients` are exactly: `allowance_mains`, `allowance_sides`, `requisition_period`, `client_id`, `wp_user_id`.
- The old WordPress user meta keys are exactly: `mains`, `sides`, `service` (all lowercase, stored in `wp_usermeta` with those exact `meta_key` values).
- The `requisition_period` column on `meals_clients` should store capitalized values: `Day`, `Week`, or `Month` — matching the select options in the client form (`views/partials/client-form-fields.php` and `includes/class-admin-ui.php`).
- Do not modify `includes/class-schema.php` or `includes/class-tables.php` — the columns already exist from Phase A.
- Do not create any new database tables.
- The `require_once` for the new class file should be in the AJAX handler, not in the autoloader, since this is a one-time utility.
