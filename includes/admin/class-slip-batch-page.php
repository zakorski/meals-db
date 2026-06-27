<?php
/**
 * Admin page: MealsDB → Packing Slips (directive 06).
 *
 * The operator's view onto the two-phase Midland workflow. A "Generate batch"
 * control (zone + delivery date) over a HISTORY TABLE of saved batches —
 * modeled on the invoice-draft history view (MealsDB_Invoice_Draft_Page), reusing
 * the same machinery (submenu + page-scoped enqueue + localized config + the
 * shared on-page notice helper). Only the columns and per-row actions differ.
 *
 * Capability: manage_options — the doc 4 / merged downloads expose DECRYPTED
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
                    'confirmCancel' => __('Cancel this batch? This permanently deletes the saved driver sheets and any uploaded scan. This cannot be undone.', 'meals-db'),
                    'uploading'   => __('Uploading…', 'meals-db'),
                    'combining'   => __('Combining…', 'meals-db'),
                ],
            ]) . ';',
            'before'
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

        echo '</div>';
    }

    // -----------------------------------------------------------------
    // Generate control
    // -----------------------------------------------------------------

    private static function render_generate_form(): void {
        echo '<h2>' . esc_html__('Generate a batch', 'meals-db') . '</h2>';
        echo '<p class="description">'
            . esc_html__('Generates the packer + driver documents for one zone and delivery date, and saves the driver sheets so the scan can be combined later.', 'meals-db')
            . '</p>';

        echo '<div id="mealsdb-slip-generate" style="margin:8px 0;">';

        echo '<label>' . esc_html__('Zone', 'meals-db') . ' ';
        $schedule = get_option('mealsdb_zone_delivery_schedule', []);
        echo '<select id="mealsdb-slip-zone">';
        echo '<option value="">' . esc_html__('— select —', 'meals-db') . '</option>';
        if (is_array($schedule)) {
            foreach (array_keys($schedule) as $zone_name) {
                echo '<option value="' . esc_attr((string) $zone_name) . '">'
                    . esc_html((string) $zone_name) . '</option>';
            }
        }
        echo '</select></label> ';

        echo '<label>' . esc_html__('Delivery date', 'meals-db')
            . ' <input type="date" id="mealsdb-slip-date" /></label> ';

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
        foreach (['Zone', 'Delivery date', '# orders', 'Generated (UTC)', 'Status', 'Actions'] as $h) {
            echo '<th>' . esc_html__($h, 'meals-db') . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr class="mealsdb-slip-empty"><td colspan="6"><em>'
                . esc_html__('No batches yet.', 'meals-db') . '</em></td></tr>';
        }
        foreach ($rows as $row) {
            self::render_row($row);
        }

        echo '</tbody></table>';
    }

    /**
     * Render one batch row. Download links are server-built GET URLs carrying
     * the workflow nonce (MealsDB_Ajax_Slip_Batch::download_url); the mutating
     * actions (upload / combine / cancel) are data-attr buttons the JS drives.
     */
    private static function render_row(array $row): void {
        $id      = (int) ($row['batch_id'] ?? 0);
        $zone    = (string) ($row['zone_name'] ?? '');
        $date    = (string) ($row['delivery_date'] ?? '');
        $count   = (int) ($row['order_count'] ?? 0);
        $created = (string) ($row['created_at'] ?? '');
        $status  = (string) ($row['status'] ?? '');
        $has_doc3   = !empty($row['has_doc3']);
        $has_merged = !empty($row['has_merged']);

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

        // Always-available downloads.
        echo '<a class="button" href="' . esc_url($dl('doc1')) . '">' . esc_html__('Doc 1 (cover)', 'meals-db') . '</a> ';
        echo '<a class="button" href="' . esc_url($dl('doc2')) . '">' . esc_html__('Doc 2 (packer)', 'meals-db') . '</a> ';
        echo '<a class="button" href="' . esc_url($dl('doc4')) . '">' . esc_html__('Doc 4 (driver)', 'meals-db') . '</a> ';

        // Upload doc 3 (hidden file input + trigger button).
        echo '<span class="mealsdb-slip-upload" style="display:inline-block;">';
        echo '<input type="file" accept="application/pdf,.pdf" class="mealsdb-slip-doc3-file" style="display:none;" />';
        echo '<button type="button" class="button mealsdb-slip-upload-btn">' . esc_html__('Upload Doc 3', 'meals-db') . '</button>';
        echo '</span> ';

        // Combine — greyed until a valid doc 3 is present.
        $combine_attr = ($has_doc3 || $has_merged) ? '' : ' disabled="disabled"';
        echo '<button type="button" class="button mealsdb-slip-combine-btn"' . $combine_attr . '>'
            . esc_html__('Combine', 'meals-db') . '</button> ';

        // Download merged — only once a merge exists.
        echo '<a class="button mealsdb-slip-merged-link" href="' . esc_url($dl('merged')) . '"'
            . ($has_merged ? '' : ' style="display:none;"') . '>'
            . esc_html__('Download merged', 'meals-db') . '</a> ';

        // Cancel (confirm popup in JS).
        echo '<button type="button" class="button mealsdb-slip-cancel-btn">' . esc_html__('Cancel', 'meals-db') . '</button>';

        echo ' <span class="mealsdb-slip-row-msg" style="margin-left:6px;"></span>';
        echo '</td>';
        echo '</tr>';
    }
}
