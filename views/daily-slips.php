<?php
/**
 * Daily Slips admin view — Phase T per-order PDF generation.
 *
 * Two PDF outputs per generation request:
 *   - Packer slips (no financial info, right column reserved for notes)
 *   - Driver slips (packer slip + collection breakdown + customer info)
 *
 * Slip-mode toggle (zone + date range vs. single delivery day) is
 * preserved from Phase Q.
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

// U06-slips-16 / U22-ajax-misc-4: the packer/driver PDF downloads generated from
// this page stream DECRYPTED client PII (name, address, phone). Per the
// operator, only administrators print these documents, so gate the view at
// manage_options too — matching the AJAX endpoints (verify_request()) and the
// Midland slip-batch page. enforce() only checks the baseline plugin cap.
if (!current_user_can('manage_options')) {
    wp_die(
        esc_html__('You do not have permission to generate delivery slips.', 'meals-db'),
        esc_html__('Access Denied', 'meals-db'),
        ['back_link' => true]
    );
}

$zone_schedule = get_option('mealsdb_zone_delivery_schedule', []);
?>
<div id="mealsdb-daily-slips" class="mealsdb-daily-slips">
    <p class="description">
        <?php echo esc_html__('Generate per-order packer and driver PDF slips. Use zone mode for the familiar zone + date range workflow, or delivery day mode to select by day-of-week.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-slip-controls" style="margin-bottom:16px;">
        <!-- Mode toggle -->
        <div style="margin-bottom:12px;">
            <label>
                <input type="radio" name="slip-mode" value="zone" checked />
                <?php echo esc_html__('By Zone + Date Range', 'meals-db'); ?>
            </label>
            <label style="margin-left:16px;">
                <input type="radio" name="slip-mode" value="day" />
                <?php echo esc_html__('By Delivery Day', 'meals-db'); ?>
            </label>
        </div>

        <!-- Zone mode controls (shown by default) -->
        <div id="mealsdb-zone-controls" style="margin-bottom:10px;">
            <label for="mealsdb-zone-start"><?php echo esc_html__('Start Date:', 'meals-db'); ?></label>
            <input type="date" id="mealsdb-zone-start" value="<?php echo esc_attr(date('Y-m-d')); ?>" />

            <label for="mealsdb-zone-end" style="margin-left:8px;"><?php echo esc_html__('End Date:', 'meals-db'); ?></label>
            <input type="date" id="mealsdb-zone-end" value="<?php echo esc_attr(date('Y-m-d')); ?>" />

            <label for="mealsdb-zone-select" style="margin-left:8px;"><?php echo esc_html__('Zones:', 'meals-db'); ?></label>
            <select id="mealsdb-zone-select" multiple style="min-width:220px; height:auto; vertical-align:middle;">
                <?php foreach ($zone_schedule as $zone_name => $config) : ?>
                    <option value="<?php echo esc_attr($zone_name); ?>">
                        <?php echo esc_html($zone_name . ' - ' . $config['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="mealsdb-select-all-zones" class="button button-small" style="margin-left:6px;">
                <?php esc_html_e('All Zones', 'meals-db'); ?>
            </button>
        </div>

        <!-- Day mode controls (hidden by default) -->
        <div id="mealsdb-day-controls" style="display:none; margin-bottom:10px;">
            <label for="mealsdb-slip-date"><?php echo esc_html__('Delivery Date:', 'meals-db'); ?></label>
            <input type="date" id="mealsdb-slip-date" value="<?php echo esc_attr(date('Y-m-d')); ?>" />
        </div>

        <button type="button" class="button button-primary" id="mealsdb-gen-packer-pdf">
            <?php echo esc_html__('Generate Packer Slips PDF', 'meals-db'); ?>
        </button>
        <button type="button" class="button button-primary" id="mealsdb-gen-driver-pdf">
            <?php echo esc_html__('Generate Driver Slips PDF', 'meals-db'); ?>
        </button>
    </div>

    <div id="mealsdb-slip-status" class="notice" style="display:none;"></div>
</div>

<?php
// Server data for assets/js/daily-slips.js. The inline behaviour script was
// extracted per CLAUDE.md (no inline <script> logic blocks > 20 lines); the
// nonce, ajax URL, and translated status strings the script interpolated from
// PHP now travel through this JSON island. JSON_HEX_* makes it safe inside the
// <script> tag — do NOT use esc_js here (this is JSON data, not JS source).
$daily_slips_data = array(
    'nonce'   => wp_create_nonce('mealsdb_nonce'),
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'i18n'    => array(
        'selectZone'  => __('Please select at least one zone.', 'meals-db'),
        'selectDates' => __('Please select a start and end date.', 'meals-db'),
        'selectDate'  => __('Please select a date.', 'meals-db'),
        'generating'  => __('Generating PDF — your download will start shortly.', 'meals-db'),
    ),
);
?>
<script type="application/json" id="mealsdb-daily-slips-data"><?php echo wp_json_encode($daily_slips_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
