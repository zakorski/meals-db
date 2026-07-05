<?php
/**
 * Private Customer Sales Report admin view.
 *
 * Per-client totals of mains, sides, and financials for private customers.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

?>
<div id="mealsdb-private-sales">

    <h2><?php esc_html_e('Private Customer Sales Report', 'meals-db'); ?></h2>
    <p class="description">
        <?php esc_html_e('Generates per-client totals of mains, sides, subtotals, tax, and final totals for private (non-government) customers within a date range.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-private-controls" style="margin-bottom:16px; display:flex; gap:10px; align-items:flex-end;">
        <div>
            <label for="private-start"><?php esc_html_e('Start Date', 'meals-db'); ?></label><br>
            <input type="date" id="private-start" class="regular-text" />
        </div>
        <div>
            <label for="private-end"><?php esc_html_e('End Date', 'meals-db'); ?></label><br>
            <input type="date" id="private-end" class="regular-text" />
        </div>
        <div>
            <button type="button" class="button button-primary" id="private-run"><?php esc_html_e('Run Report', 'meals-db'); ?></button>
            <button type="button" class="button" id="private-export" style="display:none;"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
        </div>
    </div>

    <div id="private-status" class="notice" style="display:none;"></div>
    <div id="private-output" style="display:none;"></div>

</div>

<?php
// Server data for assets/js/private-sales.js. This view renders on the
// Reports page where window.mealsdb is NOT localized, so the island carries
// its own nonce + ajaxUrl. User-facing strings are translated here (JS reads
// them from the island) so the client stays free of hardcoded English.
$island_data = array(
    'nonce'   => wp_create_nonce('mealsdb_nonce'),
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'i18n'    => array(
        'selectDates'       => __('Please select both start and end dates.', 'meals-db'),
        'running'           => __('Running report...', 'meals-db'),
        'reportFailed'      => __('Report failed.', 'meals-db'),
        'requestFailed'     => __('Request failed.', 'meals-db'),
        'noData'            => __('No private customer data found for this date range.', 'meals-db'),
        'colFirstName'      => __('First Name', 'meals-db'),
        'colLastName'       => __('Last Name', 'meals-db'),
        'colTotalMains'     => __('Total Mains', 'meals-db'),
        'colTotalSides'     => __('Total Sides', 'meals-db'),
        'colTotalBeforeTax' => __('Total Before Tax', 'meals-db'),
        'colTotalTax'       => __('Total Tax', 'meals-db'),
        'colFinalTotal'     => __('Final Total', 'meals-db'),
        'grandTotal'        => __('Grand Total', 'meals-db'),
    ),
);
?>
<script type="application/json" id="mealsdb-private-sales-data"><?php echo wp_json_encode($island_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
