<?php
/**
 * Admin page: MealsDB → Cron Status.
 *
 * Surfaces the new job/hook logs as a live operator view so the team
 * doesn't have to wait for tomorrow's email to ask "are we healthy
 * right now?". Also exposes a "Send Test Report Now" action so the
 * recipient list and SMTP config can be verified before relying on
 * the morning send.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Cron_Status_Page {

    public const PAGE_SLUG       = 'mealsdb_cron_status';
    public const TEST_ACTION     = 'mealsdb_send_test_report';
    public const SAVE_ACTION     = 'mealsdb_save_cron_report_settings';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 20);
        add_action('admin_post_' . self::TEST_ACTION, [self::class, 'handle_test_send']);
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'handle_save_settings']);
    }

    public static function register_menu(): void {
        if (!MealsDB_Permissions::can_access_plugin()) {
            return;
        }
        add_submenu_page(
            'mealsdb',
            __('Cron Status', 'meals-db'),
            __('Cron Status', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void {
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_die(esc_html__('Access denied.', 'meals-db'));
        }

        $report = MealsDB_Daily_Report::build_report();

        echo '<div class="wrap"><h1>' . esc_html__('Meals DB — Cron Status', 'meals-db') . '</h1>';

        if (isset($_GET['mealsdb_notice']) && check_admin_referer('mealsdb_cron_notice')) {
            $notice = sanitize_text_field((string) $_GET['mealsdb_notice']);
            if ($notice !== '') {
                echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($notice) . '</p></div>';
            }
        }

        self::render_summary($report);
        self::render_jobs_section();
        self::render_hooks_section($report);
        self::render_reconciliation_section($report);
        self::render_settings_form();
        self::render_test_button();

        echo '</div>';
    }

    private static function render_summary(array $report): void {
        $overall = (string) ($report['summary']['overall'] ?? 'UNKNOWN');
        $color   = $overall === 'CLEAR' ? '#46b450' : ($overall === 'WARNINGS' ? '#dba617' : '#dc3232');

        echo '<h2>' . esc_html__('Overall status', 'meals-db') . '</h2>';
        printf(
            '<p><strong style="color:%s;font-size:1.2em;">%s</strong></p>',
            esc_attr($color),
            esc_html($overall)
        );
        printf(
            '<p>%s: <code>%s</code> (UTC)</p>',
            esc_html__('Report window', 'meals-db'),
            esc_html((string) ($report['yesterday_label'] ?? ''))
        );
    }

    private static function render_jobs_section(): void {
        echo '<h2>' . esc_html__('Recent job runs', 'meals-db') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Job', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Started (UTC)', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Duration', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Status', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Records', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Error', 'meals-db') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (MealsDB_Daily_Report::MONITORED_JOBS as $job_name => $label) {
            $rows = MealsDB_Job_Logger::recent_runs($job_name, 5);
            if (empty($rows)) {
                printf(
                    '<tr><td><strong>%s</strong></td><td colspan="5"><em>%s</em></td></tr>',
                    esc_html($label),
                    esc_html__('No runs recorded yet.', 'meals-db')
                );
                continue;
            }
            foreach ($rows as $i => $row) {
                $rec = sprintf(
                    '%d processed / %d updated / %d skipped / %d errored',
                    (int) ($row['records_processed'] ?? 0),
                    (int) ($row['records_updated'] ?? 0),
                    (int) ($row['records_skipped'] ?? 0),
                    (int) ($row['records_errored'] ?? 0)
                );
                printf(
                    '<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    $i === 0 ? '<strong>' . esc_html($label) . '</strong>' : '',
                    esc_html((string) ($row['started_at'] ?? '')),
                    esc_html(isset($row['duration_seconds']) ? $row['duration_seconds'] . 's' : '—'),
                    esc_html(strtoupper((string) ($row['status'] ?? ''))),
                    esc_html($rec),
                    esc_html((string) ($row['error_message'] ?? ''))
                );
            }
        }
        echo '</tbody></table>';
    }

    private static function render_hooks_section(array $report): void {
        echo '<h2>' . esc_html__('Hook activity (yesterday)', 'meals-db') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Hook', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Total', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Processed', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Skipped', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Errored', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('7d avg', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Anomaly', 'meals-db') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($report['hooks'] ?? [] as $hook) {
            printf(
                '<tr><td><code>%s</code></td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%.1f</td><td>%s</td></tr>',
                esc_html((string) $hook['hook_name']),
                (int) $hook['count'],
                (int) ($hook['breakdown']['processed'] ?? 0),
                (int) ($hook['breakdown']['skipped'] ?? 0),
                (int) ($hook['breakdown']['errored'] ?? 0),
                (float) ($hook['avg_7day'] ?? 0),
                !empty($hook['is_anomaly']) ? '<strong style="color:#dba617;">' . esc_html__('YES', 'meals-db') . '</strong>' : '—'
            );
        }
        echo '</tbody></table>';
    }

    private static function render_reconciliation_section(array $report): void {
        echo '<h2>' . esc_html__('Reconciliation checks', 'meals-db') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Check', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Count', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Sample IDs', 'meals-db') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($report['reconciliation'] ?? [] as $check_name => $check) {
            $count = (int) ($check['count'] ?? 0);
            $sample = !empty($check['sample_ids'])
                ? implode(', ', array_map('intval', $check['sample_ids']))
                : '—';
            $color = $count > 0 ? '#dba617' : '#46b450';
            printf(
                '<tr><td><code>%s</code></td><td><strong style="color:%s;">%d</strong></td><td>%s</td></tr>',
                esc_html((string) $check_name),
                esc_attr($color),
                $count,
                esc_html($sample)
            );
        }
        echo '</tbody></table>';
    }

    private static function render_settings_form(): void {
        $recipients = (string) get_option(MealsDB_Daily_Report::OPT_RECIPIENTS, '');
        $only_anom  = (bool) get_option(MealsDB_Daily_Report::OPT_ONLY_ON_ANOMALIES, false);
        $threshold  = (float) get_option(MealsDB_Daily_Report::OPT_ANOMALY_THRESHOLD, 50);

        echo '<h2>' . esc_html__('Report settings', 'meals-db') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::SAVE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '" />';

        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="mealsdb_recipients">' . esc_html__('Recipients', 'meals-db') . '</label></th>';
        echo '<td><input type="text" id="mealsdb_recipients" name="recipients" value="' . esc_attr($recipients) . '" class="large-text" />';
        echo '<p class="description">' . esc_html__('Comma-separated email addresses. Invalid entries are silently dropped on save.', 'meals-db') . '</p></td></tr>';

        echo '<tr><th>' . esc_html__('Only send on anomalies', 'meals-db') . '</th>';
        echo '<td><label><input type="checkbox" name="only_on_anomalies" value="1"' . checked($only_anom, true, false) . ' /> ';
        echo esc_html__('Suppress reports when overall status is CLEAR', 'meals-db') . '</label></td></tr>';

        echo '<tr><th><label for="mealsdb_threshold">' . esc_html__('Hook anomaly threshold', 'meals-db') . '</label></th>';
        echo '<td><input type="number" id="mealsdb_threshold" name="anomaly_threshold" value="' . esc_attr((string) $threshold) . '" min="1" max="100" step="1" /> %';
        echo '<p class="description">' . esc_html__('Flag a hook as anomalous when yesterday\'s count is below this percent of the trailing 7-day average. Default 50.', 'meals-db') . '</p></td></tr>';

        echo '</tbody></table>';
        submit_button(__('Save report settings', 'meals-db'));
        echo '</form>';
    }

    private static function render_test_button(): void {
        echo '<h2>' . esc_html__('Send test report now', 'meals-db') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline;">';
        wp_nonce_field(self::TEST_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::TEST_ACTION) . '" />';
        submit_button(__('Send test report', 'meals-db'), 'secondary', 'submit', false);
        echo '</form>';
        echo '<p class="description">' . esc_html__('Runs the daily report immediately and emails the configured recipients.', 'meals-db') . '</p>';
    }

    public static function handle_test_send(): void {
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_die(esc_html__('Access denied.', 'meals-db'));
        }
        check_admin_referer(self::TEST_ACTION);

        $notice = '';
        try {
            MealsDB_Daily_Report::run();
            $notice = __('Test report dispatched. Check the configured recipients.', 'meals-db');
        } catch (\Throwable $e) {
            $notice = sprintf(__('Test report failed: %s', 'meals-db'), $e->getMessage());
        }

        self::redirect_with_notice($notice);
    }

    public static function handle_save_settings(): void {
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_die(esc_html__('Access denied.', 'meals-db'));
        }
        check_admin_referer(self::SAVE_ACTION);

        $recipients_raw = isset($_POST['recipients']) ? wp_unslash((string) $_POST['recipients']) : '';
        // Validate each entry; persist only the valid ones, comma-joined.
        $candidates = preg_split('/[\s,;]+/', $recipients_raw) ?: [];
        $valid = [];
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c === '') {
                continue;
            }
            // is_email() rejects CR/LF — first-line defense against
            // header injection if these ever flow into wp_mail() raw.
            if (is_email($c)) {
                $valid[] = sanitize_email($c);
            }
        }
        update_option(MealsDB_Daily_Report::OPT_RECIPIENTS, implode(',', $valid));

        $only = !empty($_POST['only_on_anomalies']) ? 1 : 0;
        update_option(MealsDB_Daily_Report::OPT_ONLY_ON_ANOMALIES, $only);

        $threshold = isset($_POST['anomaly_threshold']) ? (int) $_POST['anomaly_threshold'] : 50;
        if ($threshold < 1 || $threshold > 100) {
            $threshold = 50;
        }
        update_option(MealsDB_Daily_Report::OPT_ANOMALY_THRESHOLD, $threshold);

        self::redirect_with_notice(__('Report settings saved.', 'meals-db'));
    }

    private static function redirect_with_notice(string $notice): void {
        $url = add_query_arg(
            [
                'page'            => self::PAGE_SLUG,
                'mealsdb_notice'  => rawurlencode($notice),
                '_wpnonce'        => wp_create_nonce('mealsdb_cron_notice'),
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }
}
