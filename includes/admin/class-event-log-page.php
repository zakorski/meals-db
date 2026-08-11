<?php
/**
 * Admin page: MealsDB → Event Log (directive STR-LOG dashboard).
 *
 * A WP-INTERNAL operator view over the operational event-log trunk. There
 * is deliberately NO public REST route and NO externally reachable
 * endpoint — the page renders server-side under a manage_options gate, and
 * the one mutating-ish surface (CSV export) goes through admin-post with
 * the standard three layers (capability + nonce + rate limit).
 *
 * Two tabs, honoring the hard boundary between the two logging worlds:
 *   - "Events & Errors" → meals_event_log (operational, pruned freely).
 *   - "Audit Trail"     → meals_audit_log (compliance, read-only here).
 * They share the page but never the table.
 *
 * Default trunk view: outcome IN (failed, degraded), last 72h, newest
 * first — the things an operator actually needs to see. Filters narrow by
 * severity, category, subsystem, date range, entity, correlation_id (one
 * full run/request as a thread), and free text.
 *
 * Output is escaped at the point of emission (the view layer is XSS-clean
 * — keep it). CSV export routes through MealsDB_CSV::cell() so it inherits
 * the formula-injection guard (and the QW-3 negative-money fix).
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Event_Log_Page {

    public const PAGE_SLUG     = 'mealsdb_event_log';
    public const EXPORT_ACTION = 'mealsdb_event_log_export';

    /** Default lookback for the trunk tab's failed/degraded view. */
    private const DEFAULT_HOURS = 72;

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 21);
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'handle_export']);
    }

    public static function register_menu(): void {
        // Dashboard is admin-only (manage_options), stricter than the
        // baseline plugin capability — operational logs can name clients
        // and orders, so keep the audience tight.
        add_submenu_page(
            'mealsdb',
            __('Event Log', 'meals-db'),
            __('Event Log', 'meals-db'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'meals-db'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'events';
        if ($tab !== 'audit') {
            $tab = 'events';
        }

        echo '<div class="wrap"><h1>' . esc_html__('Meals DB — Event Log', 'meals-db') . '</h1>';

        $base = admin_url('admin.php?page=' . self::PAGE_SLUG);
        echo '<h2 class="nav-tab-wrapper">';
        printf(
            '<a href="%s" class="nav-tab%s">%s</a>',
            esc_url($base . '&tab=events'),
            $tab === 'events' ? ' nav-tab-active' : '',
            esc_html__('Events & Errors', 'meals-db')
        );
        printf(
            '<a href="%s" class="nav-tab%s">%s</a>',
            esc_url($base . '&tab=audit'),
            $tab === 'audit' ? ' nav-tab-active' : '',
            esc_html__('Audit Trail', 'meals-db')
        );
        echo '</h2>';

        if ($tab === 'audit') {
            self::render_audit_tab();
        } else {
            self::render_events_tab();
        }

        echo '</div>';
    }

    // ---------------------------------------------------------------------
    //  Events & Errors tab (the trunk)
    // ---------------------------------------------------------------------

    private static function render_events_tab(): void {
        $filters = self::read_filters();

        self::render_filter_form($filters);

        $rows = MealsDB_Event_Log::query(self::filters_to_query($filters));

        // Export button (carries the active filters so the CSV matches
        // what's on screen).
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:8px 0;">';
        wp_nonce_field(self::EXPORT_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::EXPORT_ACTION) . '" />';
        foreach ($filters as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '" />';
        }
        submit_button(__('Export CSV', 'meals-db'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        // Wrap each label at the literal so string-extraction sees it; loop escapes.
        $headers = [
            __('Occurred (UTC)', 'meals-db'), __('Sev', 'meals-db'), __('Category', 'meals-db'),
            __('Subsystem', 'meals-db'), __('Event', 'meals-db'), __('Outcome', 'meals-db'),
            __('Entity', 'meals-db'), __('Correlation', 'meals-db'), __('Message', 'meals-db'),
        ];
        foreach ($headers as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="9"><em>' . esc_html__('No matching events.', 'meals-db') . '</em></td></tr>';
        }
        foreach ($rows as $row) {
            $outcome = (string) ($row['outcome'] ?? '');
            $color   = self::outcome_color($outcome);
            $entity  = '';
            if (!empty($row['entity_type'])) {
                $entity = $row['entity_type'] . ' #' . (int) ($row['entity_id'] ?? 0);
            }
            printf(
                '<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td>'
                . '<td><strong style="color:%s;">%s</strong></td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
                esc_html((string) ($row['occurred_at'] ?? '')),
                esc_html((string) ($row['severity'] ?? '')),
                esc_html((string) ($row['category'] ?? '')),
                esc_html((string) ($row['subsystem'] ?? '')),
                esc_html((string) ($row['event'] ?? '')),
                esc_attr($color),
                esc_html($outcome),
                esc_html($entity),
                esc_html((string) ($row['correlation_id'] ?? '')),
                esc_html((string) ($row['message'] ?? ''))
            );
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__('Showing newest first, capped at the query limit. Narrow with the filters above. The default view shows failed and degraded events from the last 72 hours.', 'meals-db') . '</p>';
    }

    private static function render_filter_form(array $filters): void {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:12px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '" />';
        echo '<input type="hidden" name="tab" value="events" />';

        self::select('severity', __('Severity', 'meals-db'), ['', 'debug', 'info', 'notice', 'warning', 'error', 'critical'], (string) $filters['severity']);
        self::select('outcome', __('Outcome', 'meals-db'), ['', 'failed', 'degraded', 'succeeded', 'running'], (string) $filters['outcome']);
        self::text('category', __('Category', 'meals-db'), (string) $filters['category'], 10);
        self::text('subsystem', __('Subsystem', 'meals-db'), (string) $filters['subsystem'], 14);
        self::text('entity_type', __('Entity type', 'meals-db'), (string) $filters['entity_type'], 8);
        self::text('entity_id', __('Entity ID', 'meals-db'), (string) $filters['entity_id'], 8);
        self::text('correlation_id', __('Correlation', 'meals-db'), (string) $filters['correlation_id'], 14);
        self::text('search', __('Text', 'meals-db'), (string) $filters['search'], 18);
        self::text('since', __('Since (UTC)', 'meals-db'), (string) $filters['since'], 18);
        self::text('until', __('Until (UTC)', 'meals-db'), (string) $filters['until'], 18);

        echo '<button type="submit" class="button">' . esc_html__('Filter', 'meals-db') . '</button> ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=events')) . '" class="button">' . esc_html__('Reset', 'meals-db') . '</a>';
        echo '</form>';
    }

    private static function select(string $name, string $label, array $options, string $current): void {
        echo '<label style="margin-right:10px;">' . esc_html($label) . ' <select name="' . esc_attr($name) . '">';
        foreach ($options as $opt) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($opt),
                selected($current, $opt, false),
                esc_html($opt === '' ? '—' : $opt)
            );
        }
        echo '</select></label>';
    }

    private static function text(string $name, string $label, string $current, int $size): void {
        printf(
            '<label style="margin-right:10px;">%s <input type="text" name="%s" value="%s" size="%d" /></label>',
            esc_html($label),
            esc_attr($name),
            esc_attr($current),
            (int) $size
        );
    }

    // ---------------------------------------------------------------------
    //  Audit Trail tab (read-only, separate table — boundary preserved)
    // ---------------------------------------------------------------------

    private static function render_audit_tab(): void {
        echo '<p class="description">' . esc_html__('Compliance audit trail — committed changes to client/data records. Append-only, separate from the operational trunk. PII values are shown as fingerprints.', 'meals-db') . '</p>';

        $rows = class_exists('MealsDB_Logger') ? MealsDB_Logger::get_recent_logs(200) : [];

        echo '<table class="widefat striped"><thead><tr>';
        // Wrap each label at the literal so string-extraction sees it; loop escapes.
        $headers = [
            __('When', 'meals-db'), __('User', 'meals-db'), __('Action', 'meals-db'),
            __('Target', 'meals-db'), __('Field', 'meals-db'), __('Old', 'meals-db'),
            __('New', 'meals-db'), __('Source', 'meals-db'),
        ];
        foreach ($headers as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="8"><em>' . esc_html__('No audit rows.', 'meals-db') . '</em></td></tr>';
        }
        foreach ($rows as $row) {
            printf(
                '<tr><td><code>%s</code></td><td>%d</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html((string) ($row['created_at'] ?? '')),
                (int) ($row['user_id'] ?? 0),
                esc_html((string) ($row['action'] ?? '')),
                (int) ($row['target_id'] ?? 0),
                esc_html((string) ($row['field_changed'] ?? '')),
                esc_html((string) ($row['old_value'] ?? '')),
                esc_html((string) ($row['new_value'] ?? '')),
                esc_html((string) ($row['source'] ?? ''))
            );
        }
        echo '</tbody></table>';
    }

    // ---------------------------------------------------------------------
    //  CSV export
    // ---------------------------------------------------------------------

    public static function handle_export(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', 'meals-db'));
        }
        check_admin_referer(self::EXPORT_ACTION);

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_die(esc_html__('Rate limit exceeded. Try again shortly.', 'meals-db'), '', ['response' => 429]);
        }

        $filters = self::read_filters();
        // Export a generous-but-bounded slice.
        $query = self::filters_to_query($filters);
        $query['limit'] = 1000;
        $rows = MealsDB_Event_Log::query($query);

        $columns = [
            'occurred_at', 'severity', 'category', 'subsystem', 'event', 'outcome',
            'entity_type', 'entity_id', 'correlation_id', 'duration_seconds',
            'records_processed', 'records_updated', 'records_skipped', 'records_errored',
            'message',
        ];

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="meals-event-log-' . gmdate('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'w');
        // Header row.
        fputcsv($out, array_map([self::class, 'csv_cell'], $columns));
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = self::csv_cell((string) ($row[$col] ?? ''));
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    /** Route every cell through the shared formula-injection guard. */
    private static function csv_cell($value): string {
        return class_exists('MealsDB_CSV') ? MealsDB_CSV::cell($value) : (string) $value;
    }

    // ---------------------------------------------------------------------
    //  Filter plumbing
    // ---------------------------------------------------------------------

    /**
     * Read + sanitize the GET filters. When no explicit outcome/since is
     * supplied, fall back to the default failed/degraded last-72h view.
     *
     * @return array<string, string>
     */
    private static function read_filters(): array {
        $g = static function (string $key): string {
            return isset($_REQUEST[$key]) ? sanitize_text_field(wp_unslash((string) $_REQUEST[$key])) : '';
        };

        return [
            'severity'       => $g('severity'),
            'outcome'        => $g('outcome'),
            'category'       => $g('category'),
            'subsystem'      => $g('subsystem'),
            'entity_type'    => $g('entity_type'),
            'entity_id'      => $g('entity_id'),
            'correlation_id' => $g('correlation_id'),
            'search'         => $g('search'),
            'since'          => $g('since'),
            'until'          => $g('until'),
        ];
    }

    /**
     * Translate the form filters into a MealsDB_Event_Log::query() arg
     * array, applying the default failed/degraded + 72h view when the
     * operator hasn't narrowed outcome or time themselves.
     *
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private static function filters_to_query(array $filters): array {
        $query = [];
        foreach (['severity', 'category', 'subsystem', 'entity_type', 'entity_id', 'correlation_id', 'search', 'since', 'until'] as $k) {
            if ($filters[$k] !== '') {
                $query[$k] = $filters[$k];
            }
        }

        // Outcome: explicit single value, or the default failed+degraded.
        if ($filters['outcome'] !== '') {
            $query['outcome'] = $filters['outcome'];
        } else {
            $query['outcome'] = [MealsDB_Event_Log::OUTCOME_FAILED, MealsDB_Event_Log::OUTCOME_DEGRADED];
        }

        // Default lookback only when the operator hasn't set any time
        // bound AND hasn't pinned a specific entity/correlation thread
        // (those are "show me everything for X", not "recent only").
        if ($filters['since'] === '' && $filters['until'] === ''
            && $filters['correlation_id'] === '' && $filters['entity_id'] === '') {
            $query['since'] = gmdate('Y-m-d H:i:s', time() - self::DEFAULT_HOURS * 3600);
        }

        $query['limit'] = 200;
        return $query;
    }

    private static function outcome_color(string $outcome): string {
        switch ($outcome) {
            case MealsDB_Event_Log::OUTCOME_FAILED:
                return '#dc3232';
            case MealsDB_Event_Log::OUTCOME_DEGRADED:
                return '#dba617';
            case MealsDB_Event_Log::OUTCOME_RUNNING:
                return '#2271b1';
            default:
                return '#46b450';
        }
    }
}
