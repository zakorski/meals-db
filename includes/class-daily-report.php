<?php
/**
 * Daily report job — reads meals_job_log and meals_hook_log, computes
 * anomalies, and emails configured recipients.
 *
 * Schedules itself for 04:00 site time (cPanel cron fires ~04:15)
 * which is far enough after the 02:00 / 03:00 nightly jobs that they
 * have completed even with the +15min offset.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Daily_Report {

    public const HOOK     = 'mealsdb_daily_report';
    public const JOB_NAME = 'daily_report';

    /**
     * Settings option keys.
     */
    public const OPT_RECIPIENTS         = 'mealsdb_daily_report_recipients';
    public const OPT_ONLY_ON_ANOMALIES  = 'mealsdb_daily_report_only_on_anomalies';
    public const OPT_ANOMALY_THRESHOLD  = 'mealsdb_daily_report_anomaly_threshold';

    /**
     * Hooks the plugin instruments. Single source of truth — used by
     * both the daily report and the Cron Status admin page so they
     * agree on which hooks to render.
     */
    public const INSTRUMENTED_HOOKS = [
        'woocommerce_new_order',
        'woocommerce_order_status_changed',
        'woocommerce_order_status_cancelled',
        'woocommerce_order_status_refunded',
        'woocommerce_order_status_failed',
        'woocommerce_order_status_trash',
        // HPOS order-lifecycle hooks (LB-5). The old wp_posts hooks
        // (trashed_post / before_delete_post) never fired for orders under
        // HPOS, so monitoring them read as "healthy" when it meant "dead".
        'woocommerce_trash_order',
        'woocommerce_delete_order',
        'profile_update',
        'woocommerce_customer_save_address',
        'woocommerce_created_customer',
    ];

    /**
     * Nightly jobs the report monitors. Maps log-table job_name to
     * a human-readable label.
     */
    public const MONITORED_JOBS = [
        'wp_to_mealsdb_sync'        => 'WP→MealsDB Sync',
        'nightly_allocation_sync'   => 'Nightly Allocation Sync',
        'task_cron'                 => 'Task Cron',
        'daily_report'              => 'Daily Report (this job)',
    ];

    /**
     * Tolerance window applied when checking "did the job fire on
     * time". cPanel system cron fires WP-Cron at :15 and :45, so a
     * 02:00 scheduled job actually runs ~02:15. 30 minutes covers
     * that offset comfortably without masking truly missed runs.
     */
    private const ON_TIME_TOLERANCE_MINUTES = 30;

    /**
     * Schedule + register the daily report hook.
     */
    public static function register_hooks(): void {
        add_action(self::HOOK, [self::class, 'run']);
        add_action('wp_mail_failed', [self::class, 'on_mail_failed']);

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(strtotime('tomorrow 04:00:00'), 'daily', self::HOOK);
        }
    }

    /**
     * Entry point for the scheduled job and the admin "Send Test
     * Report Now" button.
     */
    public static function run(): void {
        $log_id = MealsDB_Job_Logger::start(self::JOB_NAME);
        try {
            $report = self::build_report();
            $sent   = self::send_report($report);

            MealsDB_Job_Logger::finish($log_id, [
                'records_processed' => count(self::INSTRUMENTED_HOOKS) + count(self::MONITORED_JOBS),
                'mail_sent'         => $sent ? 1 : 0,
                'anomalies'         => $report['summary']['anomaly_count'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            MealsDB_Job_Logger::fail($log_id, $e->getMessage());
            // Re-throw so WP-Cron flags the failure too — operators
            // running `wp cron event run` see it as a failed event.
            throw $e;
        }
    }

    /**
     * Capture wp_mail send failures so the next day's report can
     * surface "yesterday's report did not send."
     *
     * @param WP_Error $error
     */
    public static function on_mail_failed($error): void {
        $message = is_object($error) && method_exists($error, 'get_error_message')
            ? (string) $error->get_error_message()
            : 'unknown';
        MealsDB_Logger::error('[Daily Report] wp_mail failure: ' . $message);
    }

    /**
     * Build the report data structure. Public so the admin "live
     * health snapshot" can reuse the same builders.
     *
     * @return array<string, mixed>
     */
    public static function build_report(): array {
        [$yesterday_start_utc, $yesterday_end_utc, $yesterday_label] = self::yesterday_window_utc();

        $jobs       = self::build_jobs_section($yesterday_end_utc);
        $hooks      = self::build_hooks_section($yesterday_start_utc, $yesterday_end_utc);
        $recon      = self::build_reconciliation_section($yesterday_start_utc, $yesterday_end_utc);
        $summary    = self::build_summary($jobs, $hooks, $recon);

        return [
            'generated_at_utc' => gmdate('Y-m-d H:i:s'),
            'yesterday_label'  => $yesterday_label,
            'jobs'             => $jobs,
            'hooks'            => $hooks,
            'reconciliation'   => $recon,
            'summary'          => $summary,
        ];
    }

    /**
     * Render and send the report. Returns true if wp_mail() reported
     * success (which is best-effort — wp_mail_failed catches the rest).
     */
    public static function send_report(array $report): bool {
        $recipients = self::get_recipients();
        if (empty($recipients)) {
            // No-op rather than throw. The job still records as
            // successful — "no one configured to receive" is an
            // operator config issue, not a system failure.
            return false;
        }

        // Suppress the send entirely when only-on-anomalies is on and
        // the overall status is clear. The job still records its run
        // so the next day's report sees that today executed.
        if (self::should_suppress_on_clear($report)) {
            return false;
        }

        $subject = self::build_subject($report);
        $body    = self::format_text($report);
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        return (bool) wp_mail($recipients, $subject, $body, $headers);
    }

    /**
     * Yesterday in site timezone, returned as UTC bounds + a display
     * label (YYYY-MM-DD in site tz).
     *
     * @return array{0:string, 1:string, 2:string}
     */
    public static function yesterday_window_utc(): array {
        $site_tz = self::site_timezone();
        $now_site = new DateTimeImmutable('now', $site_tz);
        $yesterday_site = $now_site->modify('-1 day')->setTime(0, 0, 0);
        $today_site     = $now_site->setTime(0, 0, 0);

        $label = $yesterday_site->format('Y-m-d');

        $start_utc = $yesterday_site->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end_utc   = $today_site->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return [$start_utc, $end_utc, $label];
    }

    /**
     * Determine the site timezone the same way WP does for
     * wp_timezone(), with a sensible UTC fallback.
     */
    private static function site_timezone(): DateTimeZone {
        if (function_exists('wp_timezone')) {
            $tz = wp_timezone();
            if ($tz instanceof DateTimeZone) {
                return $tz;
            }
        }
        $string = function_exists('get_option') ? (string) get_option('timezone_string') : '';
        if ($string !== '') {
            try {
                return new DateTimeZone($string);
            } catch (Exception $e) {
                // fall through to UTC
            }
        }
        return new DateTimeZone('UTC');
    }

    /**
     * Build the "nightly jobs status" section.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function build_jobs_section(string $end_utc): array {
        $window_start_unix = strtotime($end_utc . ' UTC') - 86400;
        $window_start_utc  = gmdate('Y-m-d H:i:s', $window_start_unix);

        $rows = [];
        foreach (self::MONITORED_JOBS as $job_name => $label) {
            $latest = MealsDB_Job_Logger::latest_in_window($job_name, $window_start_utc);
            $row    = [
                'job_name'      => $job_name,
                'label'         => $label,
                'status'        => 'MISSED',
                'started_at'    => null,
                'completed_at'  => null,
                'duration'      => null,
                'records'       => null,
                'error'         => null,
                'last_success'  => MealsDB_Job_Logger::last_success($job_name),
            ];
            if ($latest !== null) {
                $row['status']       = strtoupper((string) $latest['status']);
                $row['started_at']   = $latest['started_at'] ?? null;
                $row['completed_at'] = $latest['completed_at'] ?? null;
                $row['duration']     = isset($latest['duration_seconds'])
                    ? (int) $latest['duration_seconds']
                    : null;
                $row['records']      = [
                    'processed' => (int) ($latest['records_processed'] ?? 0),
                    'updated'   => (int) ($latest['records_updated'] ?? 0),
                    'skipped'   => (int) ($latest['records_skipped'] ?? 0),
                    'errored'   => (int) ($latest['records_errored'] ?? 0),
                ];
                $row['error']        = $latest['error_message'] ?? null;

                // Hang detection: still 'running' more than 2 hours
                // after start. The 2 hour ceiling is generous — the
                // longest legitimate nightly job (allocation sync)
                // completes well under an hour even with thousands
                // of clients, so anything past 2h is a real anomaly.
                if (strtolower((string) $latest['status']) === 'running') {
                    $age = time() - strtotime(($latest['started_at'] ?? '') . ' UTC');
                    if ($age > 7200) {
                        $row['status'] = 'HUNG';
                    }
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Build the "hook activity yesterday" section.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function build_hooks_section(string $start_utc, string $end_utc): array {
        $rows = [];
        $threshold = self::anomaly_threshold_pct();

        foreach (self::INSTRUMENTED_HOOKS as $hook_name) {
            $by_outcome = MealsDB_Hook_Logger::count_by_outcome($hook_name, $start_utc, $end_utc);
            $total      = array_sum($by_outcome);
            $trailing   = MealsDB_Hook_Logger::trailing_window_counts($hook_name, $start_utc, 7);

            // Anomaly only flags when yesterday is a normal business
            // day (Mon-Fri in site tz) AND the count is below the
            // threshold percentage of the 7-day average. Weekend dips
            // are normal noise we shouldn't escalate.
            $weekday        = (int) gmdate('N', strtotime($start_utc . ' UTC'));
            $is_business    = $weekday >= 1 && $weekday <= 5;
            $is_anomaly     = $is_business
                && $trailing['average'] > 0
                && ($total / $trailing['average']) * 100 < $threshold;

            $rows[] = [
                'hook_name'  => $hook_name,
                'count'      => $total,
                'breakdown'  => $by_outcome,
                'avg_7day'   => $trailing['average'],
                'last_fire'  => MealsDB_Hook_Logger::last_fire($hook_name),
                'is_anomaly' => $is_anomaly,
            ];
        }
        return $rows;
    }

    /**
     * HPOS table names. Centralised so the three reconciliation checks
     * don't repeat the same prefix concatenation.
     *
     * This site is HPOS-exclusive: orders live in wc_orders /
     * wc_orders_meta, not in wp_posts / wp_postmeta. Querying the
     * classic tables returned zero rows on every run — that was the
     * bug this helper exists to prevent recurring. See CLAUDE.md
     * section "Don't query orders via wp_posts on HPOS".
     *
     * @return array{orders: string, meta: string} Table names with prefix.
     */
    private static function get_hpos_tables(): array {
        global $wpdb;

        return [
            'orders' => $wpdb->prefix . 'wc_orders',
            'meta'   => $wpdb->prefix . 'wc_orders_meta',
        ];
    }

    /**
     * Reconciliation checks. We implement checks #1, #3, #4, #5 from
     * the directive. Check #2 ("allocations without orders") was
     * dropped because meals_client_allocations is keyed by
     * (client_id, billing_month) — it has no per-order row, so the
     * "source order missing" lookup the directive describes doesn't
     * map to this schema. Skipped deliberately.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function build_reconciliation_section(string $start_utc, string $end_utc): array {
        return [
            'orders_without_allocations'    => self::check_orders_without_allocations($start_utc, $end_utc),
            'active_orders_missing_meta'    => self::check_active_orders_missing_meta($start_utc, $end_utc),
            'clients_with_orders_no_record' => self::check_clients_with_orders_no_record($start_utc, $end_utc),
        ];
    }

    /**
     * Check #1: orders created yesterday with mealsdb_client_user_id
     * meta but no row in meals_delivery_allocations for that order.
     * meals_delivery_allocations is the per-order table; client_allocations
     * is per-month and isn't the right place to count from.
     *
     * HPOS NOTE: This site is HPOS-exclusive. Orders live in wc_orders /
     * wc_orders_meta, not in wp_posts / wp_postmeta. A previous version
     * of this method joined the classic tables filtered by
     * post_type='shop_order' and returned zero rows on every run —
     * operators received daily false "all clear" reports. See CLAUDE.md
     * section "Don't query orders via wp_posts on HPOS" for the
     * canonical translation table. The o.type = 'shop_order' filter
     * excludes refunds (HPOS uses a `type` column where classic CPT
     * used distinct post_type values).
     *
     * @return array{count: int, sample_ids: array<int, int>}
     */
    private static function check_orders_without_allocations(string $start_utc, string $end_utc): array {
        global $wpdb;

        if (!class_exists('WooCommerce')) {
            return ['count' => 0, 'sample_ids' => [], 'skipped_reason' => 'WooCommerce not active'];
        }

        $delivery_table = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);
        $tables = self::get_hpos_tables();

        $sql = $wpdb->prepare(
            "SELECT o.id FROM `{$tables['orders']}` o
             INNER JOIN `{$tables['meta']}` m
                 ON m.order_id = o.id AND m.meta_key = %s
             LEFT JOIN `{$delivery_table}` d
                 ON d.wc_order_id = o.id
             WHERE o.type = %s
               AND o.date_created_gmt >= %s
               AND o.date_created_gmt < %s
               AND CAST(m.meta_value AS UNSIGNED) > 0
               AND d.id IS NULL
             LIMIT 50",
            'mealsdb_client_user_id',
            'shop_order',
            $start_utc,
            $end_utc
        );
        $ids = $wpdb->get_col($sql);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];

        return [
            'count'      => count($ids),
            'sample_ids' => array_slice($ids, 0, 10),
        ];
    }

    /**
     * Check #3: WC orders with status processing/completed from
     * yesterday whose customer is a tracked SDNB/Veterans/Private
     * client but the order has no mealsdb_client_user_id meta.
     *
     * HPOS NOTE: This site is HPOS-exclusive. The customer's WP user
     * id is on wc_orders.customer_id directly — no JOIN through
     * postmeta._customer_user is needed. A previous version of this
     * method joined wp_posts / wp_postmeta filtered by post_type and
     * returned zero rows on every run. See CLAUDE.md "Don't query
     * orders via wp_posts on HPOS".
     *
     * @return array{count: int, sample_ids: array<int, int>}
     */
    private static function check_active_orders_missing_meta(string $start_utc, string $end_utc): array {
        global $wpdb;

        if (!class_exists('WooCommerce')) {
            return ['count' => 0, 'sample_ids' => [], 'skipped_reason' => 'WooCommerce not active'];
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $tables = self::get_hpos_tables();

        // Find shop_order rows in the yesterday window whose customer
        // (o.customer_id) is a tracked client in meals_clients, but
        // which lack the mealsdb_client_user_id meta the plugin sets
        // when it processes the order.
        $sql = $wpdb->prepare(
            "SELECT o.id FROM `{$tables['orders']}` o
             INNER JOIN `{$clients_table}` c
                 ON c.wp_user_id = o.customer_id
                AND c.client_type IN ('SDNB','Veteran','Private')
                AND c.active = 1
             LEFT JOIN `{$tables['meta']}` mm
                 ON mm.order_id = o.id AND mm.meta_key = %s
             WHERE o.type = %s
               AND o.status IN ('wc-processing','wc-completed')
               AND o.date_created_gmt >= %s
               AND o.date_created_gmt < %s
               AND mm.id IS NULL
             LIMIT 50",
            'mealsdb_client_user_id',
            'shop_order',
            $start_utc,
            $end_utc
        );
        $ids = $wpdb->get_col($sql);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];

        return [
            'count'      => count($ids),
            'sample_ids' => array_slice($ids, 0, 10),
        ];
    }

    /**
     * Check #4: customers who placed an order yesterday but have no
     * meals_clients row at all. Indicates the user→client sync is
     * lagging or missing entirely for those users.
     *
     * HPOS NOTE: This site is HPOS-exclusive. The customer's WP user
     * id is on wc_orders.customer_id directly, so the legacy join
     * through postmeta._customer_user is gone. usermeta is unchanged
     * by HPOS — the capabilities filter still works the same way.
     * A previous version of this method joined wp_posts / wp_postmeta
     * filtered by post_type and returned zero rows on every run. See
     * CLAUDE.md "Don't query orders via wp_posts on HPOS".
     *
     * @return array{count: int, sample_ids: array<int, int>}
     */
    private static function check_clients_with_orders_no_record(string $start_utc, string $end_utc): array {
        global $wpdb;

        if (!class_exists('WooCommerce')) {
            return ['count' => 0, 'sample_ids' => [], 'skipped_reason' => 'WooCommerce not active'];
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $tables = self::get_hpos_tables();

        // Customers with an order in the window whose user_id has no
        // matching meals_clients row. Restrict to users whose role is
        // one of the meals-tracked roles (sdnb/veterans/private) so
        // we don't false-positive on every customer.
        $sql = $wpdb->prepare(
            "SELECT DISTINCT o.customer_id AS user_id
             FROM `{$tables['orders']}` o
             INNER JOIN {$wpdb->usermeta} um
                 ON um.user_id = o.customer_id
                AND um.meta_key = %s
                AND (
                    um.meta_value LIKE %s OR
                    um.meta_value LIKE %s OR
                    um.meta_value LIKE %s
                )
             LEFT JOIN `{$clients_table}` c
                 ON c.wp_user_id = o.customer_id
             WHERE o.type = %s
               AND o.date_created_gmt >= %s
               AND o.date_created_gmt < %s
               AND o.customer_id > 0
               AND c.client_id IS NULL
             LIMIT 50",
            $wpdb->prefix . 'capabilities',
            '%sdnb%',
            '%veterans%',
            '%private%',
            'shop_order',
            $start_utc,
            $end_utc
        );
        $ids = $wpdb->get_col($sql);
        $ids = is_array($ids) ? array_map('intval', $ids) : [];

        return [
            'count'      => count($ids),
            'sample_ids' => array_slice($ids, 0, 10),
        ];
    }

    /**
     * Roll the section findings into an overall pass/warn/fail.
     */
    private static function build_summary(array $jobs, array $hooks, array $recon): array {
        $job_failures = 0;
        $job_missed   = 0;
        $job_hung     = 0;
        foreach ($jobs as $j) {
            if ($j['status'] === 'FAILURE') {
                $job_failures++;
            }
            if ($j['status'] === 'MISSED') {
                $job_missed++;
            }
            if ($j['status'] === 'HUNG') {
                $job_hung++;
            }
        }

        $hook_anomalies = 0;
        foreach ($hooks as $h) {
            if (!empty($h['is_anomaly'])) {
                $hook_anomalies++;
            }
        }

        $recon_findings = 0;
        foreach ($recon as $check) {
            if (!empty($check['count'])) {
                $recon_findings++;
            }
        }

        $overall = 'CLEAR';
        if ($job_failures > 0 || $job_hung > 0) {
            $overall = 'FAILURES';
        } elseif ($job_missed > 0 || $hook_anomalies > 0 || $recon_findings > 0) {
            $overall = 'WARNINGS';
        }

        return [
            'overall'        => $overall,
            'job_failures'   => $job_failures,
            'job_missed'     => $job_missed,
            'job_hung'       => $job_hung,
            'hook_anomalies' => $hook_anomalies,
            'recon_findings' => $recon_findings,
            'anomaly_count'  => $job_failures + $job_missed + $job_hung + $hook_anomalies + $recon_findings,
        ];
    }

    /**
     * Recipients list as a clean array of email strings. Stored as a
     * comma-separated string in the option, validated on read so a
     * value pasted directly into wp_options can't header-inject.
     *
     * @return string[]
     */
    public static function get_recipients(): array {
        $raw = get_option(self::OPT_RECIPIENTS, '');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $candidates = preg_split('/[\s,;]+/', $raw) ?: [];
        $valid = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            // is_email() is WP's own validator; rejects header
            // injection attempts (CR/LF in the address).
            if (function_exists('is_email') && is_email($candidate)) {
                $valid[] = $candidate;
            }
        }
        return array_values(array_unique($valid));
    }

    /**
     * Determine if "send only on anomalies" should suppress this run.
     */
    private static function should_suppress_on_clear(array $report): bool {
        if (!get_option(self::OPT_ONLY_ON_ANOMALIES, false)) {
            return false;
        }
        return ($report['summary']['overall'] ?? 'CLEAR') === 'CLEAR';
    }

    /**
     * Anomaly threshold percent (default 50).
     */
    private static function anomaly_threshold_pct(): float {
        $value = (float) get_option(self::OPT_ANOMALY_THRESHOLD, 50);
        if ($value <= 0 || $value > 100) {
            return 50.0;
        }
        return $value;
    }

    /**
     * Email subject line.
     */
    private static function build_subject(array $report): string {
        $site = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'MealsDB';
        return sprintf(
            '[%s] MealsDB Daily Report — %s — %s',
            $site,
            $report['yesterday_label'] ?? '',
            $report['summary']['overall'] ?? 'UNKNOWN'
        );
    }

    /**
     * Render report as plain text. Public for the admin "preview"
     * panel and the "Send Test Report Now" handler.
     */
    public static function format_text(array $report): string {
        $lines = [];
        $lines[] = sprintf('MealsDB Daily Report — %s', $report['yesterday_label'] ?? '');
        $lines[] = sprintf('Generated (UTC): %s', $report['generated_at_utc'] ?? '');
        $lines[] = '';
        $lines[] = sprintf('OVERALL STATUS: %s', $report['summary']['overall'] ?? 'UNKNOWN');
        $lines[] = '';

        $lines[] = 'Nightly Jobs (last 24h):';
        foreach ($report['jobs'] ?? [] as $job) {
            $line = sprintf('  [%s] %-30s', $job['status'], $job['label']);
            if (!empty($job['started_at'])) {
                $line .= ' started ' . $job['started_at'];
                if (!empty($job['completed_at'])) {
                    $line .= ' → ' . $job['completed_at'];
                }
                if (isset($job['duration'])) {
                    $line .= sprintf(' (%ds)', (int) $job['duration']);
                }
            }
            if (!empty($job['records']['processed'])) {
                $line .= sprintf(
                    ' — %d processed, %d updated, %d skipped, %d errored',
                    $job['records']['processed'] ?? 0,
                    $job['records']['updated'] ?? 0,
                    $job['records']['skipped'] ?? 0,
                    $job['records']['errored'] ?? 0
                );
            }
            if (!empty($job['error'])) {
                $line .= ' — Error: ' . $job['error'];
            }
            if ($job['status'] === 'MISSED' && !empty($job['last_success'])) {
                $line .= ' — Last success: ' . $job['last_success'];
            }
            $lines[] = $line;
        }
        $lines[] = '';

        $lines[] = sprintf('Hook Activity (%s):', $report['yesterday_label'] ?? '');
        foreach ($report['hooks'] ?? [] as $hook) {
            $flag = !empty($hook['is_anomaly']) ? ' [ANOMALY <50% of 7d avg]' : '';
            $lines[] = sprintf(
                '  %-40s %4d fires (processed: %d, skipped: %d, errored: %d) [7d avg: %.1f]%s',
                $hook['hook_name'],
                $hook['count'],
                $hook['breakdown']['processed'] ?? 0,
                $hook['breakdown']['skipped'] ?? 0,
                $hook['breakdown']['errored'] ?? 0,
                $hook['avg_7day'] ?? 0,
                $flag
            );
        }
        $lines[] = '';

        $lines[] = 'Reconciliation Checks:';
        foreach ($report['reconciliation'] ?? [] as $check_name => $check) {
            $count = (int) ($check['count'] ?? 0);
            $mark = $count === 0 ? 'OK' : 'WARN';
            $line = sprintf('  [%s] %-35s %d', $mark, $check_name, $count);
            if (!empty($check['sample_ids'])) {
                $line .= ' — sample: ' . implode(',', array_map('intval', $check['sample_ids']));
            }
            $lines[] = $line;
        }
        $lines[] = '';

        $lines[] = sprintf(
            'Summary: %d job failures, %d missed, %d hung, %d hook anomalies, %d recon findings',
            $report['summary']['job_failures'] ?? 0,
            $report['summary']['job_missed'] ?? 0,
            $report['summary']['job_hung'] ?? 0,
            $report['summary']['hook_anomalies'] ?? 0,
            $report['summary']['recon_findings'] ?? 0
        );

        return implode("\n", $lines) . "\n";
    }
}
