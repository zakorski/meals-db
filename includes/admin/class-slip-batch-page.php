<?php
/**
 * Admin page: MealsDB → Packing Slips (directive 06).
 *
 * The operator's view onto the Midland packing-slip batch workflow. A "Generate batch"
 * control (zone + delivery date) over a HISTORY TABLE of saved batches —
 * modeled on the invoice-draft history view (MealsDB_Invoice_Draft_Page), reusing
 * the same machinery (submenu + page-scoped enqueue + localized config + the
 * shared on-page notice helper). Only the columns and per-row actions differ.
 *
 * Capability: manage_options — the Packing Slips / Doc 4 downloads expose DECRYPTED
 * client PII (name/address/phone), the same tight audience as the invoice page;
 * do NOT loosen it. Every cell is escaped at emission; the interactive logic
 * lives in the enqueued assets/js/slip-batch.js (no inline <script> blob).
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Slip_Batch_Page {

    public const PAGE_SLUG = 'mealsdb-packing-slips';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 22);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'mealsdb',
            __('Packing Slips', 'meals-db'),
            __('Packing Slips', 'meals-db'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue_scripts($hook): void {
        if (!is_string($hook) || strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        $notice_handle = class_exists('MealsDB_Admin_UI')
            ? MealsDB_Admin_UI::register_notice_script()
            : 'jquery';

        wp_enqueue_script(
            'mealsdb-slip-batch-js',
            plugins_url('assets/js/slip-batch.js', dirname(dirname(__FILE__))),
            ['jquery', $notice_handle],
            defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : false,
            true
        );

        wp_add_inline_script(
            'mealsdb-slip-batch-js',
            'window.mealsdbSlipBatch = ' . wp_json_encode([
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(MealsDB_Ajax_Slip_Batch::NONCE_ACTION),
                'pageUrl' => admin_url('admin.php?page=' . self::PAGE_SLUG),
                'i18n'    => [
                    'working'     => __('Working…', 'meals-db'),
                    'genericErr'  => __('Something went wrong. Please try again.', 'meals-db'),
                    'pickZone'    => __('Choose a zone and delivery date first.', 'meals-db'),
                    'confirmCancel' => __('Cancel this batch? This permanently deletes the saved driver sheets. This cannot be undone.', 'meals-db'),
                ],
            ]) . ';',
            'before'
        );

        // On-demand section (merged Daily Slips, spec 2026-07-16): the view
        // emits the #mealsdb-daily-slips-data JSON island; daily-slips.js
        // reads it by element id. report-utils supplies the shared status
        // helper. The main page used to enqueue this per-tab — that site is
        // retired along with the tab.
        $report_utils = class_exists('MealsDB_Admin_UI')
            ? MealsDB_Admin_UI::register_report_utils_script()
            : 'jquery';

        wp_enqueue_script(
            'mealsdb-daily-slips-js',
            plugins_url('assets/js/daily-slips.js', dirname(dirname(__FILE__))),
            ['jquery', $report_utils],
            defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : false,
            true
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'meals-db'));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Meals DB — Packing Slips', 'meals-db') . '</h1>';

        self::render_generate_form();
        self::render_history_table();
        self::render_on_demand_section();

        echo '</div>';
    }

    // -----------------------------------------------------------------
    // Generate control
    // -----------------------------------------------------------------

    private static function render_generate_form(): void {
        // The Home page's "Today's deliveries" links prefill zone + date
        // via GET (spec 2026-07-16 §2). Read-only convenience — generating
        // still requires the explicit button click. Unknown zones simply
        // don't match an <option>; malformed dates are dropped.
        $prefill_zone = isset($_GET['zone']) && is_string($_GET['zone'])
            ? sanitize_text_field(wp_unslash($_GET['zone']))
            : '';
        $prefill_date = isset($_GET['date']) && is_string($_GET['date'])
            ? sanitize_text_field(wp_unslash($_GET['date']))
            : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefill_date)) {
            $prefill_date = '';
        }

        echo '<h2>' . esc_html__('Generate a batch', 'meals-db') . '</h2>';
        echo '<p class="description">'
            . esc_html__('Generates and saves the packer slips (with cover) and the driver sheets for one zone and delivery date, for manual handling.', 'meals-db')
            . '</p>';

        echo '<div id="mealsdb-slip-generate" style="margin:8px 0;">';

        echo '<label>' . esc_html__('Zone', 'meals-db') . ' ';
        $schedule = get_option('mealsdb_zone_delivery_schedule', []);
        echo '<select id="mealsdb-slip-zone">';
        echo '<option value="">' . esc_html__('— select —', 'meals-db') . '</option>';
        if (is_array($schedule)) {
            foreach (array_keys($schedule) as $zone_name) {
                echo '<option value="' . esc_attr((string) $zone_name) . '"'
                    . selected($prefill_zone, (string) $zone_name, false) . '>'
                    . esc_html((string) $zone_name) . '</option>';
            }
        }
        echo '</select></label> ';

        echo '<label>' . esc_html__('Delivery date', 'meals-db')
            . ' <input type="date" id="mealsdb-slip-date" value="' . esc_attr($prefill_date) . '" /></label> ';

        echo '<button type="button" class="button button-primary" id="mealsdb-slip-generate-btn">'
            . esc_html__('Generate batch', 'meals-db') . '</button>';
        echo ' <span id="mealsdb-slip-generate-msg" style="margin-left:8px;"></span>';
        echo '</div>';
    }

    // -----------------------------------------------------------------
    // History table
    // -----------------------------------------------------------------

    private static function render_history_table(): void {
        $rows = class_exists('MealsDB_Slip_Batch') ? MealsDB_Slip_Batch::list_batches() : [];

        echo '<h2>' . esc_html__('Batches', 'meals-db') . '</h2>';
        echo '<table class="widefat striped" id="mealsdb-slip-table"><thead><tr>';
        // Wrap each label at the literal so string-extraction sees it; loop escapes.
        $headers = [
            __('Zone', 'meals-db'), __('Delivery date', 'meals-db'), __('# orders', 'meals-db'),
            __('Generated (UTC)', 'meals-db'), __('Status', 'meals-db'), __('Actions', 'meals-db'),
        ];
        foreach ($headers as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr class="mealsdb-slip-empty"><td colspan="6"><em>'
                . esc_html__('No batches yet.', 'meals-db') . '</em></td></tr>';
        }
        foreach ($rows as $row) {
            // Directive 4: weekend batches are shown WITHIN their original batch's
            // action cell (as the "Weekend" button pair), not as standalone rows.
            if ((string) ($row['batch_type'] ?? 'full') === 'weekend') {
                continue;
            }
            self::render_row($row);
        }

        echo '</tbody></table>';
    }

    // -----------------------------------------------------------------
    // On-demand PDFs (merged Daily Slips)
    // -----------------------------------------------------------------

    /**
     * On-demand slip PDFs — the retired Daily Slips tab, relocated here
     * (admin UI consolidation spec 2026-07-16 §4: "batches won"). Renders
     * views/daily-slips.php inside a collapsed <details>: immediate
     * packer/driver PDFs by zone + date range or by delivery day, streamed
     * to the browser. Nothing is saved — batch history/cancel above does
     * not apply to these.
     */
    private static function render_on_demand_section(): void {
        echo '<hr style="margin:24px 0;" />';
        echo '<details id="mealsdb-on-demand-slips">';
        echo '<summary style="cursor:pointer;"><strong>'
            . esc_html__('On-demand PDFs (not saved)', 'meals-db')
            . '</strong></summary>';
        include MealsDB_Plugin::path('views/daily-slips.php');
        echo '</details>';
    }

    /**
     * Render one batch row. The two download links — Packing Slips (combined
     * cover + packer slips) and Doc 4 (driver sheets) — are server-built GET
     * URLs carrying the workflow nonce (MealsDB_Ajax_Slip_Batch::download_url).
     * Cancel is a data-attr button the JS drives.
     */
    private static function render_row(array $row): void {
        $id      = (int) ($row['batch_id'] ?? 0);
        $zone    = (string) ($row['zone_name'] ?? '');
        $date    = (string) ($row['delivery_date'] ?? '');
        $count   = (int) ($row['order_count'] ?? 0);
        $created = (string) ($row['created_at'] ?? '');
        $status  = (string) ($row['status'] ?? '');

        $dl = static function (string $which) use ($id): string {
            return class_exists('MealsDB_Ajax_Slip_Batch')
                ? MealsDB_Ajax_Slip_Batch::download_url($id, $which)
                : '#';
        };

        echo '<tr data-batch-id="' . esc_attr((string) $id) . '">';
        echo '<td>' . esc_html($zone) . '</td>';
        echo '<td>' . esc_html($date) . '</td>';
        echo '<td>' . esc_html((string) $count) . '</td>';
        echo '<td>' . esc_html($created) . '</td>';
        echo '<td class="mealsdb-slip-status">' . esc_html($status) . '</td>';

        echo '<td class="mealsdb-slip-actions">';

        // Directive 4: a weekend follow-up batch, if one has been generated for
        // this original batch. Its presence turns the single button pair into
        // three pairs (original · weekend · all).
        $weekend = class_exists('MealsDB_Slip_Batch') ? MealsDB_Slip_Batch::find_weekend_child($id) : null;
        $weekend_id = $weekend ? (int) ($weekend['batch_id'] ?? 0) : 0;
        $wk = static function (string $which) use ($weekend_id): string {
            return ($weekend_id > 0 && class_exists('MealsDB_Ajax_Slip_Batch'))
                ? MealsDB_Ajax_Slip_Batch::download_url($weekend_id, $which)
                : '#';
        };

        // Row 1 — the original Friday set. Directive 6 (ITEM 5): buttons are
        // labelled by RECIPIENT (Midland = packer, Jim = driver). Buttons only —
        // the PDFs and their filenames are unchanged.
        echo '<div class="mealsdb-slip-row mealsdb-slip-row--original" style="margin-bottom:4px;">';
        echo '<a class="button" href="' . esc_url($dl('packing_slips')) . '">' . esc_html__('Midland Slips', 'meals-db') . '</a> ';
        echo '<a class="button" href="' . esc_url($dl('doc4')) . '">' . esc_html__('Jim Slips', 'meals-db') . '</a>';
        echo '</div>';

        if ($weekend_id > 0) {
            // Row 2 — weekend orders only.
            echo '<div class="mealsdb-slip-row mealsdb-slip-row--weekend" style="margin-bottom:4px;">';
            echo '<a class="button" href="' . esc_url($wk('packing_slips')) . '">' . esc_html__('Weekend Midland Slips', 'meals-db') . '</a> ';
            echo '<a class="button" href="' . esc_url($wk('doc4')) . '">' . esc_html__('Weekend Jim Slips', 'meals-db') . '</a>';
            echo '</div>';
            // Row 3 — both, fresh render.
            echo '<div class="mealsdb-slip-row mealsdb-slip-row--all" style="margin-bottom:4px;">';
            echo '<a class="button" href="' . esc_url($dl('all_packing_slips')) . '">' . esc_html__('All Midland Slips', 'meals-db') . '</a> ';
            echo '<a class="button" href="' . esc_url($dl('all_doc4')) . '">' . esc_html__('All Jim Slips', 'meals-db') . '</a>';
            echo '</div>';
        } else {
            // No weekend batch yet — offer to generate it. Stays enabled even
            // when there are no weekend orders (JS shows an in-place message).
            echo '<div class="mealsdb-slip-row mealsdb-slip-row--weekend-gen" style="margin-bottom:4px;">';
            echo '<button type="button" class="button mealsdb-slip-weekend-btn">'
                . esc_html__('Generate Weekend Orders', 'meals-db') . '</button>';
            echo '</div>';
        }

        // Cancel (confirm popup in JS).
        echo '<button type="button" class="button mealsdb-slip-cancel-btn">' . esc_html__('Cancel', 'meals-db') . '</button>';

        echo ' <span class="mealsdb-slip-row-msg" style="margin-left:6px;"></span>';
        echo '</td>';
        echo '</tr>';
    }
}
