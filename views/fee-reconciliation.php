<?php
/**
 * Fee Reconciliation admin view.
 *
 * Two sub-sections: Contribution Checker and Delivery Fee Checker.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

?>
<div id="mealsdb-fee-reconciliation">

    <h2><?php esc_html_e('Contribution Checker', 'meals-db'); ?></h2>
    <p class="description">
        <?php esc_html_e('Compares expected client contributions (from meals_clients) against actual payments via WooCommerce product for a date range.', 'meals-db'); ?>
    </p>
    <div class="mealsdb-fee-controls" style="margin-bottom:16px; display:flex; gap:10px; align-items:flex-end;">
        <div>
            <label for="contrib-start"><?php esc_html_e('Start Date', 'meals-db'); ?></label><br>
            <input type="date" id="contrib-start" class="regular-text" />
        </div>
        <div>
            <label for="contrib-end"><?php esc_html_e('End Date', 'meals-db'); ?></label><br>
            <input type="date" id="contrib-end" class="regular-text" />
        </div>
        <div>
            <button type="button" class="button button-primary" id="contrib-run"><?php esc_html_e('Run Report', 'meals-db'); ?></button>
            <button type="button" class="button" id="contrib-export" style="display:none;"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
        </div>
    </div>
    <div id="contrib-status" class="notice" style="display:none;"></div>
    <div id="contrib-output" style="display:none;"></div>

    <hr style="margin:24px 0;" />

    <h2><?php esc_html_e('Delivery Fee Checker', 'meals-db'); ?></h2>
    <p class="description">
        <?php esc_html_e('Compares expected delivery fees (num_orders x fee rate) against actual payments via WooCommerce product for a date range.', 'meals-db'); ?>
    </p>
    <div class="mealsdb-fee-controls" style="margin-bottom:16px; display:flex; gap:10px; align-items:flex-end;">
        <div>
            <label for="delfee-start"><?php esc_html_e('Start Date', 'meals-db'); ?></label><br>
            <input type="date" id="delfee-start" class="regular-text" />
        </div>
        <div>
            <label for="delfee-end"><?php esc_html_e('End Date', 'meals-db'); ?></label><br>
            <input type="date" id="delfee-end" class="regular-text" />
        </div>
        <div>
            <button type="button" class="button button-primary" id="delfee-run"><?php esc_html_e('Run Report', 'meals-db'); ?></button>
            <button type="button" class="button" id="delfee-export" style="display:none;"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
        </div>
    </div>
    <div id="delfee-status" class="notice" style="display:none;"></div>
    <div id="delfee-output" style="display:none;"></div>

</div>
<?php
// Behaviour is in assets/js/fee-reconciliation.js, which depends on
// assets/js/report-utils.js (shared esc / csvCell / exportCsv /
// showStatus helpers). Both are enqueued by
// MealsDB_Admin_UI::enqueue_report_scripts() when $tab === 'fees'.
// Config is attached via window.mealsdbFeeReconciliation.
