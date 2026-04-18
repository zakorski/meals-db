<?php
/**
 * Order Error Report admin view.
 *
 * Data quality checks across WC orders for a date range.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

?>
<div id="mealsdb-order-errors">

    <h2><?php esc_html_e('Order Error Report', 'meals-db'); ?></h2>
    <p class="description">
        <?php esc_html_e('Scans WooCommerce orders for common data quality issues: missing names, invalid initials, missing zones/addresses, and unlinked accounts.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-error-controls" style="margin-bottom:16px; display:flex; gap:10px; align-items:flex-end;">
        <div>
            <label for="errors-start"><?php esc_html_e('Start Date', 'meals-db'); ?></label><br>
            <input type="date" id="errors-start" class="regular-text" />
        </div>
        <div>
            <label for="errors-end"><?php esc_html_e('End Date', 'meals-db'); ?></label><br>
            <input type="date" id="errors-end" class="regular-text" />
        </div>
        <div>
            <button type="button" class="button button-primary" id="errors-run"><?php esc_html_e('Run Report', 'meals-db'); ?></button>
            <button type="button" class="button" id="errors-export" style="display:none;"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
        </div>
    </div>

    <div id="errors-status" class="notice" style="display:none;"></div>
    <div id="errors-output" style="display:none;"></div>

</div>
<?php
// Behaviour is in assets/js/order-errors.js, which depends on
// assets/js/report-utils.js (shared esc / csvCell / exportCsv /
// showStatus helpers). Both are enqueued by
// MealsDB_Admin_UI::enqueue_report_scripts() when $tab === 'errors'.
// Config is attached via window.mealsdbOrderErrors.
