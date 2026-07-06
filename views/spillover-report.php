<?php
/**
 * Over-Allowance Spillover Report — phase 3.
 *
 * Lists meals delivered in the selected month that spilled into the next
 * month under the allocation engine's allowance fill (phase 1), plus any
 * multi-month-spillover errors the rebuilder logged.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;
MealsDB_Permissions::enforce();

// Default the picker to the current month in the SITE timezone (this is a
// display-layer default the operator works in, not a stored value) — server-
// local date() can pre-select the wrong month near a boundary.
$default_month = wp_date('Y-m');
?>
<div id="mealsdb-spillover-report">

    <h2><?php esc_html_e('Over-Allowance Spillover', 'meals-db'); ?></h2>
    <p class="description">
        <?php esc_html_e(
            "Meals delivered in the selected month that exceeded the client's monthly allowance and spilled into the next month. Multi-month-spillover errors (where the next month also could not absorb the overflow) are flagged separately and need attention.",
            'meals-db'
        ); ?>
    </p>

    <div class="mealsdb-spill-controls" style="margin-bottom:16px; display:flex; gap:10px; align-items:flex-end;">
        <div>
            <label for="spill-month"><?php esc_html_e('Billing Month', 'meals-db'); ?></label><br>
            <input type="month" id="spill-month" class="regular-text" value="<?php echo esc_attr($default_month); ?>" />
        </div>
        <div>
            <button type="button" class="button button-primary" id="spill-run"><?php esc_html_e('Run Report', 'meals-db'); ?></button>
            <button type="button" class="button" id="spill-export" style="display:none;"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
        </div>
    </div>

    <div id="spill-status" class="notice" style="display:none;"></div>
    <div id="spill-output" style="display:none;"></div>

</div>
