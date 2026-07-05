<?php
/**
 * Purchase Order — Seasonally-Adjusted Projection admin view.
 *
 * Weighted demand with seasonal awareness, inventory subtraction,
 * and category exclusions.
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

?>
<div id="mealsdb-purchase-order" class="mealsdb-purchase-order">
    <p class="description">
        <?php echo esc_html__('Generate a seasonally-adjusted purchase order. Uses recency-weighted demand, year-over-year seasonal indices, and current inventory levels.', 'meals-db'); ?>
    </p>

    <p class="description">
        <?php echo esc_html__('Forecast model (fixed, validated by back-test): 12-week recency-weighted history, 6-week order horizon plus a 3-week demand-proportional safety buffer (9 weeks of coverage), seasonal index clamped to 0.3–3.0. Not configurable.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-po-controls" style="margin-bottom:16px; display:flex; gap:12px; align-items:flex-end;">
        <div>
            <button type="button" class="button button-primary" id="mealsdb-po-generate">
                <?php echo esc_html__('Generate', 'meals-db'); ?>
            </button>
            <button type="button" class="button" id="mealsdb-po-export" style="display:none;">
                <?php echo esc_html__('Export CSV', 'meals-db'); ?>
            </button>
        </div>
    </div>

    <div id="mealsdb-po-status" class="notice" style="display:none;"></div>

    <div id="mealsdb-po-output" style="display:none;"></div>
</div>

<?php
// Server data for assets/js/purchase-order.js. Every value the old inline
// script interpolated from PHP now travels through this JSON island; the JS
// reads it by element id. JSON_HEX_* makes it safe inside the <script> tag
// (do NOT esc_js — this is JSON data, not JS source).
$mealsdb_po_island = array(
    'nonce'   => wp_create_nonce('mealsdb_nonce'),
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'i18n'    => array(
        'generating'      => __('Generating...', 'meals-db'),
        'errorGenerating' => __('Error generating purchase order.', 'meals-db'),
        'requestFailed'   => __('Request failed.', 'meals-db'),
        'noData'          => __('No demand data found for the trailing period.', 'meals-db'),
        'colSku'          => __('SKU', 'meals-db'),
        'colProduct'      => __('Product', 'meals-db'),
        'colAvgWk'        => __('Avg/Wk', 'meals-db'),
        'colSeasonal'     => __('Seasonal', 'meals-db'),
        'colAdjWk'        => __('Adj/Wk', 'meals-db'),
        'colProjected'    => __('Projected', 'meals-db'),
        'colStock'        => __('Stock', 'meals-db'),
        'colCases'        => __('Cases', 'meals-db'),
        'colOrderQty'     => __('Order Qty', 'meals-db'),
        'colNote'         => __('Note', 'meals-db'),
        'total'           => __('TOTAL', 'meals-db'),
    ),
);
?>
<script type="application/json" id="mealsdb-purchase-order-data"><?php echo wp_json_encode($mealsdb_po_island, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
