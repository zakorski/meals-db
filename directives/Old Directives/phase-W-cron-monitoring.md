# Phase W — Cron Monitoring and Hook Observability

## Purpose

Add instrumentation around the plugin's scheduled jobs and real-time hooks so we can verify they're firing as intended in production. Operators receive daily reports showing what ran, what didn't, and what anomalies need investigation. This is foundational for a shadow-mode trial of the new billing flow — without it, silent failures during the trial month would invalidate the comparison against the old system.

## Production environment context

The live site uses **cPanel system cron**, not WP-Cron's request-driven default. The system cron runs the WP-Cron endpoint **every 30 minutes** (at :15 and :45 past each hour). This means scheduled WP events fire within at most 30 minutes of their target time, not on page-view dependency. The plugin should account for this offset when reporting "did the job run on time" — a 2:00 AM scheduled job that actually fires at 2:15 AM is normal, not late.

## Scope

This phase implements three things:

1. **Job execution logging** — every nightly cron job records its run to a persistent log
2. **Hook firing logging** — every real-time WC/WP hook the plugin cares about records its fire to a persistent log
3. **Daily report generation** — a separate cron job runs in the morning, reads both logs, computes anomalies, and emails/alerts the report

The reports must send even when other jobs fail. Silent failure is the specific risk being mitigated.

---

## 1. Job execution logging

### New table: `meals_job_log`

Add to `includes/class-tables.php`:

```php
public const JOB_LOG = 'meals_job_log';
```

Schema (add to `includes/class-schema.php`):

```php
MealsDB_Tables::JOB_LOG => [
    'table'   => MealsDB_Tables::JOB_LOG,
    'engine'  => 'InnoDB',
    'columns' => [
        'log_id'           => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'job_name'         => 'VARCHAR(100) NOT NULL',
        'started_at'       => 'DATETIME NOT NULL',
        'completed_at'     => 'DATETIME NULL',
        'duration_seconds' => 'INT UNSIGNED NULL',
        'status'           => "ENUM('running','success','failure','timeout') NOT NULL DEFAULT 'running'",
        'records_processed' => 'INT UNSIGNED NULL',
        'records_updated'   => 'INT UNSIGNED NULL',
        'records_skipped'   => 'INT UNSIGNED NULL',
        'records_errored'   => 'INT UNSIGNED NULL',
        'error_message'    => 'TEXT NULL',
        'context'          => 'JSON NULL',
    ],
    'primary' => 'log_id',
    'indexes' => [
        'idx_job_name_started' => '(job_name, started_at)',
        'idx_status'           => '(status)',
    ],
],
```

### New class: `MealsDB_Job_Logger`

Create `includes/class-job-logger.php`. Provides three static methods:

```php
class MealsDB_Job_Logger {
    /**
     * Record the start of a job run. Returns the log_id to pass to finish().
     */
    public static function start(string $job_name, array $context = []): int;

    /**
     * Record successful completion of a job. Pass the log_id from start().
     */
    public static function finish(int $log_id, array $stats = []): void;

    /**
     * Record a failed completion. Pass the log_id from start() and the exception/error.
     */
    public static function fail(int $log_id, string $error_message, array $stats = []): void;

    /**
     * Get the most recent successful run timestamp for a given job_name.
     */
    public static function last_success(string $job_name): ?string;

    /**
     * Get recent runs for a job (for the daily report).
     */
    public static function recent_runs(string $job_name, int $limit = 7): array;
}
```

The `$stats` array may include keys: `records_processed`, `records_updated`, `records_skipped`, `records_errored`. These all become columns.

The `$context` is an arbitrary JSON-encoded blob for additional debug info (e.g., months recalculated, batch size).

### Instrumenting existing jobs

Modify the three existing nightly jobs to use the logger. The wrapper pattern:

```php
public static function run_nightly_sync(): void {
    $log_id = MealsDB_Job_Logger::start('wp_to_mealsdb_sync');
    try {
        // ... existing logic, accumulating $synced_count, $skipped_count, $error_count ...

        MealsDB_Job_Logger::finish($log_id, [
            'records_processed' => $synced_count + $skipped_count + $error_count,
            'records_updated'   => $synced_count,
            'records_skipped'   => $skipped_count,
            'records_errored'   => $error_count,
        ]);
    } catch (\Throwable $e) {
        MealsDB_Job_Logger::fail($log_id, $e->getMessage(), [
            'records_processed' => $synced_count ?? 0,
            'records_errored'   => $error_count ?? 0,
        ]);
        throw $e; // re-throw so WP cron sees the failure
    }
}
```

Apply this pattern to:

- `class-sync.php`: `run_nightly_sync` → job_name `wp_to_mealsdb_sync`
- `class-allocation-hooks.php`: `nightly_sync` → job_name `nightly_allocation_sync`
- `class-task-cron.php`: the task processing function → job_name `task_cron`

For the allocation sync, also include in `context`:
```php
['months_recalculated' => [$current_month, $next_month]]
```

For the task cron, also include in `stats`:
```php
['tasks_completed' => $completed_count, 'tasks_deferred' => $deferred_count]
```
(extend the `meals_job_log` columns to include these if you don't want to use the JSON context).

### Heartbeat for jobs that might hang

Add a `heartbeat` method that long-running jobs can call mid-batch:

```php
public static function heartbeat(int $log_id, array $stats): void;
```

This updates `records_processed` etc. without changing status. Allows the daily report to detect "job started but hung" by finding rows with `status='running'` and `started_at` older than expected.

The allocation sync should call `heartbeat()` between months (after current month completes, before next month starts).

---

## 2. Hook firing logging

### New table: `meals_hook_log`

Schema:

```php
MealsDB_Tables::HOOK_LOG => [
    'table'   => MealsDB_Tables::HOOK_LOG,
    'engine'  => 'InnoDB',
    'columns' => [
        'log_id'        => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'hook_name'     => 'VARCHAR(100) NOT NULL',
        'fired_at'      => 'DATETIME NOT NULL',
        'target_type'   => "VARCHAR(20) NULL COMMENT 'order, user, etc.'",
        'target_id'     => 'BIGINT UNSIGNED NULL',
        'context'       => 'JSON NULL',
        'outcome'       => "ENUM('processed','skipped','errored') NOT NULL DEFAULT 'processed'",
        'error_message' => 'TEXT NULL',
    ],
    'primary' => 'log_id',
    'indexes' => [
        'idx_hook_fired' => '(hook_name, fired_at)',
        'idx_target'     => '(target_type, target_id)',
        'idx_outcome'    => '(outcome)',
    ],
],
```

### New class: `MealsDB_Hook_Logger`

Create `includes/class-hook-logger.php`:

```php
class MealsDB_Hook_Logger {
    /**
     * Record that a hook fired.
     */
    public static function record(
        string $hook_name,
        ?string $target_type = null,
        ?int $target_id = null,
        array $context = [],
        string $outcome = 'processed',
        ?string $error_message = null
    ): void;

    /**
     * Count hook fires in a time window (for daily report).
     */
    public static function count_in_window(string $hook_name, string $start, string $end): int;

    /**
     * Get hook fires by outcome in a window.
     */
    public static function count_by_outcome(string $hook_name, string $start, string $end): array;
}
```

### Hooks to instrument

The hooks the plugin already registers. Each one's existing callback gets a logging call added at entry and exit:

| Hook | Class | Target |
|---|---|---|
| `woocommerce_new_order` | class-allocation-hooks.php | order |
| `woocommerce_order_status_changed` | class-allocation-hooks.php | order |
| `woocommerce_order_status_cancelled` | class-allocation-hooks.php | order |
| `woocommerce_order_status_refunded` | class-allocation-hooks.php | order |
| `woocommerce_order_status_failed` | class-allocation-hooks.php | order |
| `woocommerce_order_status_trash` | class-allocation-hooks.php | order |
| `trashed_post` (for orders) | class-allocation-hooks.php | order |
| `before_delete_post` (for orders) | class-allocation-hooks.php | order |
| `profile_update` | class-sync.php | user |
| `woocommerce_customer_save_address` | class-sync.php | user |
| `woocommerce_created_customer` | class-sync.php | user |

The pattern for each callback:

```php
public static function on_order_created(int $order_id): void {
    MealsDB_Hook_Logger::record('woocommerce_new_order', 'order', $order_id);

    try {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            MealsDB_Hook_Logger::record(
                'woocommerce_new_order', 'order', $order_id,
                ['reason' => 'not_wc_order'], 'skipped'
            );
            return;
        }

        $client_user_id = (int) $order->get_meta('mealsdb_client_user_id');
        if ($client_user_id <= 0) {
            MealsDB_Hook_Logger::record(
                'woocommerce_new_order', 'order', $order_id,
                ['reason' => 'no_mealsdb_client'], 'skipped'
            );
            return;
        }

        $engine = new MealsDB_Allocation_Engine();
        $engine->allocate_order($order_id);
    } catch (\Throwable $e) {
        MealsDB_Hook_Logger::record(
            'woocommerce_new_order', 'order', $order_id,
            ['exception' => get_class($e)], 'errored', $e->getMessage()
        );
        throw $e;
    }
}
```

Note that **every** fire gets logged, including skipped ones (where the plugin chose not to act because the order isn't a meals client, etc.). This is important — if hook fire counts seem low, you need to be able to see whether they fired and got skipped vs didn't fire at all.

### Volume concern and mitigation

These tables will grow. At ~50 orders/day × 5+ hooks per order plus profile updates, the hook log accumulates ~10K rows/month. Add a retention policy:

```php
// In MealsDB_Hook_Logger::record(), occasionally (every ~1000 fires) trigger cleanup
DELETE FROM meals_hook_log WHERE fired_at < DATE_SUB(NOW(), INTERVAL 90 DAYS);
```

Job log can be kept longer (smaller volume) — retain 1 year.

---

## 3. Daily report

### New scheduled job: `mealsdb_daily_report`

Runs at **04:00 AM** daily. Since the cPanel system cron fires WP-Cron at :15 and :45, the actual fire will be 04:15 AM — that's fine. By then both nightly jobs (2 AM and 3 AM, actual fires ~02:15 and ~03:15) have had time to complete.

Register in a new class `class-daily-report.php`:

```php
class MealsDB_Daily_Report {
    const HOOK = 'mealsdb_daily_report';

    public static function register_hooks(): void {
        add_action(self::HOOK, [self::class, 'run']);
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(strtotime('tomorrow 04:00:00'), 'daily', self::HOOK);
        }
    }

    public static function run(): void {
        $log_id = MealsDB_Job_Logger::start('daily_report');
        try {
            $report = self::build_report();
            self::send_report($report);
            MealsDB_Job_Logger::finish($log_id);
        } catch (\Throwable $e) {
            MealsDB_Job_Logger::fail($log_id, $e->getMessage());
            throw $e;
        }
    }
}
```

### Report content

The `build_report()` method computes:

**Section 1: Nightly job execution**

For each of the three nightly jobs (`wp_to_mealsdb_sync`, `nightly_allocation_sync`, `task_cron`):

- Most recent run within the last 24 hours: status (✓/✗/⚠️), started_at, duration, records processed
- If no run in last 24 hours: flag as **MISSED** with last successful run timestamp
- If status is `running` and started_at > 2 hours ago: flag as **HUNG**

**Section 2: Hook activity for "yesterday"** (defined as the calendar day before the report runs, in site timezone)

For each instrumented hook:

- Total fires
- Breakdown by outcome: processed / skipped / errored
- Trailing 7-day average for comparison

**Section 3: Reconciliation checks**

These are the anomaly detectors. Each one queries the database and reports findings:

1. **Orders without allocations**: count orders created yesterday where `mealsdb_client_user_id` meta is set but no row exists in `meals_client_allocations` for that order. Should be zero. Show the order IDs (up to 10) if any.

2. **Allocations without orders**: count rows in `meals_client_allocations` where the source order no longer exists in `wp_posts`. Should be zero. Show the allocation IDs if any.

3. **Active orders without client meta**: count WC orders with status `processing` or `completed` from yesterday where `mealsdb_client_user_id` meta is missing AND the customer's `customer_group` is `sdnb`, `veterans`, or `private`. These are orders that should have been allocated but the meta wasn't set. Show the order IDs.

4. **Clients with WC orders but no meals_clients record**: SDNB/Veteran/Private customers in `wp_usermeta` who placed orders yesterday but don't have a `meals_clients` row. Indicates sync gap.

5. **Hook count anomalies**: any hook whose count yesterday was <50% of trailing 7-day average, AND yesterday is a normal business day (Mon-Fri). Flag as **WARNING**.

**Section 4: Summary**

- Overall status: ✓ all clear / ⚠️ warnings / ✗ failures
- Count of anomalies in each category
- Action items (if any)

### Report format

Plain-text email by default, with HTML formatting available. The body looks like:

```
MealsDB Daily Report — 2026-05-15 04:15 AM

OVERALL STATUS: ⚠️ WARNINGS

Nightly Jobs (last 24h):
  ✓ wp_to_mealsdb_sync         02:15:03 → 02:15:42  (39s)  482 records, 12 updated, 0 errors
  ✓ nightly_allocation_sync    03:15:02 → 03:16:34  (92s)  2,847 orders recalculated [2026-05, 2026-06]
  ⚠️ task_cron                  02:15:05 → FAILED    (2s)   Error: PDO connection timeout

Hook Activity Yesterday (2026-05-14):
  woocommerce_new_order              47 fires (processed: 47, skipped: 0, errored: 0)  [7-day avg: 52]
  woocommerce_order_status_changed   89 fires (processed: 76, skipped: 13, errored: 0) [7-day avg: 84]
  profile_update                      3 fires
  woocommerce_customer_save_address   1 fire
  woocommerce_created_customer        0 fires (last fire: 2026-05-12)

Reconciliation Checks:
  ✓ Orders without allocations: 0
  ✓ Allocations without orders: 0
  ✓ Active orders without client meta: 0
  ✓ Clients with orders but no meals_clients: 0
  ✓ Hook count anomalies: none

Action Items:
  1. Investigate task_cron failure — PDO timeout suggests DB connection issue
     Last successful run: 2026-05-14 02:15:08
```

### Recipients configuration

Add a settings page entry under Settings → MealsDB → Reports:

- **Daily report recipients**: comma-separated list of email addresses
- **Send only on anomalies**: checkbox (if checked, suppress reports when status is fully ✓)
- **Anomaly threshold for hook count**: percent below 7-day average to trigger warning (default 50%)

Store these as options under `mealsdb_daily_report_*` keys.

### Send method

Use `wp_mail()` for the initial implementation. Future enhancement could add Slack webhook support — leave a TODO for that. The HOOK fires the report regardless of whether wp_mail succeeds; if mail fails, the failure should be logged via `MealsDB_Job_Logger::fail()` but shouldn't crash.

---

## 4. Admin UI

### New admin page: `MealsDB → Cron Status`

Located under the MealsDB menu. Three sections:

**Section 1: Job execution history**

Table showing the last 14 runs of each of the four jobs (3 existing + new daily report). Columns: job name, started, duration, status, records processed, error.

Allow filtering by job name and date range. Allow click-through to view the full `context` JSON for any row.

**Section 2: Hook activity (last 7 days)**

Per-hook line chart of daily fire counts. Numeric breakdown by outcome.

**Section 3: Health snapshot**

Same content as the daily email's reconciliation checks section, but computed live when the page loads. So an operator can check "are we currently healthy?" without waiting for tomorrow's report.

Include a "Send Test Report Now" button that runs `MealsDB_Daily_Report::run()` immediately and sends to the configured recipients. Useful for verifying email delivery works before relying on it.

---

## 5. Wiring

### In the main plugin file (or initializer)

After existing setup, add:

```php
require_once __DIR__ . '/includes/class-job-logger.php';
require_once __DIR__ . '/includes/class-hook-logger.php';
require_once __DIR__ . '/includes/class-daily-report.php';

add_action('init', [MealsDB_Daily_Report::class, 'register_hooks']);
```

Schema migrations: add the new tables to the migration list so a fresh install creates them automatically.

### Backfill for existing installations

For sites that already have the plugin running, write a one-time migration that:

1. Creates the new tables if missing
2. Schedules the new daily report cron event
3. Doesn't try to backfill historical data (start fresh from install date)

---

## 6. Testing

### Required tests

1. **`MealsDB_Job_Logger::start()` returns a valid log_id, row is in `meals_job_log` with status='running'**
2. **`MealsDB_Job_Logger::finish()` updates the row to status='success' with correct duration and stats**
3. **`MealsDB_Job_Logger::fail()` updates the row to status='failure' with the error message**
4. **`MealsDB_Hook_Logger::record()` inserts a row with the correct hook_name and outcome**
5. **`MealsDB_Hook_Logger::count_in_window()` returns the right count for a given window**
6. **Daily report job logs its own execution via `MealsDB_Job_Logger`**
7. **Daily report correctly identifies missing nightly jobs** (simulate by clearing `meals_job_log` and running the report)
8. **Daily report correctly identifies hook count anomalies** (simulate by inserting hook log rows with a 7-day high pattern then a yesterday-low pattern)
9. **Daily report sends email when recipients are configured, doesn't crash when recipients list is empty**
10. **"Send Test Report Now" button in admin UI works end-to-end**

### Manual verification after deployment

1. After deploy, check `wp cron event list` to confirm all four jobs are scheduled (`mealsdb_nightly_sync`, `mealsdb_nightly_allocation_sync`, `mealsdb_task_cron`, `mealsdb_daily_report`)
2. Trigger the daily report manually via the admin UI button
3. Confirm email arrives
4. Wait for the next natural fire (next morning's report) and verify it matches what the admin UI shows

---

## 7. Notes for implementation

- The two new tables (`meals_job_log` and `meals_hook_log`) should follow the same engine/charset conventions as the existing `meals_*` tables
- All timestamps should be stored in UTC; convert to site timezone only at display time
- The hook logger's `record()` method should be **fast** — it's on the critical path of every order creation and status change. Use a single INSERT, no SELECTs, no extra queries
- The job logger's `heartbeat()` method should also be fast since it can be called many times during a batch job
- Errors logged via job/hook loggers should NOT be re-logged via the existing `MealsDB_Logger::error()` — that would create duplicates in the legacy `meals_logs` table. The new log tables replace error_log for these specific events
- If the daily report fails to send, the failure must be logged in `meals_job_log` so the next day's report can show "yesterday's report did not send"
- The cPanel cron's :15/:45 schedule means our scheduled times of :00 will actually fire at :15. Build the daily report's "did the job run on time" check with a 30-minute tolerance window
- Don't trust `wp_next_scheduled()` alone to verify scheduling — also write a wp-cli command (`wp mealsdb cron status`) that lists all four scheduled events with their next run times. This is the operator's go-to "is everything wired up" check

---

## 8. Definition of done

- All three nightly jobs use `MealsDB_Job_Logger` to log their runs
- All instrumented WP/WC hooks call `MealsDB_Hook_Logger::record()` on every fire (including skipped)
- The daily report runs at 04:00 (effective ~04:15) and emails configured recipients
- The Cron Status admin page exists and shows accurate live data
- The "Send Test Report Now" button works
- All 10 listed tests pass
- After 24 hours of running in production, the daily report shows realistic counts for hook fires and successful runs for the nightly jobs
- The dev confirms via wp-cli or admin page that all four cron events are scheduled
