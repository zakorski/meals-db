<?php
/**
 * Purchase Order — one-click draft generation (spec 2026-07-11).
 *
 * The forecast preview that used to render here (table, pallet-optimisation
 * summary, CSV export, optimize toggle) was removed: Generate persists a
 * pallet-optimized draft server-side and navigates straight to the draft
 * detail page, which is the real review surface (editable steppers, coverage
 * warnings, pallet totals, CSV export).
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

    <p class="description">
        <?php echo esc_html__('Generating creates a pallet-optimized draft PO and opens it for review. The order is snapped to whole Apetito pallets (75 cases): filled up if the partial pallet is at least a third full, otherwise trimmed — within a 7–52 week coverage guard. Adjust individual rows on the draft page.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-po-controls" style="margin-bottom:16px;">
        <button type="button" class="button button-primary" id="mealsdb-po-generate">
            <?php echo esc_html__('Generate draft PO', 'meals-db'); ?>
        </button>
    </div>

    <div id="mealsdb-po-status" class="notice" style="display:none;"></div>
</div>

<?php
// Server data for assets/js/purchase-order.js — JSON island, read by element
// id. JSON_HEX_* makes it safe inside the <script> tag (do NOT esc_js — this
// is JSON data, not JS source).
$mealsdb_po_island = array(
    'ajaxUrl' => admin_url('admin-ajax.php'),
    // PO draft workflow: its own nonce context (destructive family) and the
    // detail page to open after a successful save.
    'poNonce'    => wp_create_nonce(MealsDB_Ajax_Purchase_Orders::NONCE_ACTION),
    'poAdminUrl' => admin_url('admin.php?page=mealsdb&tab=po_admin'),
    'i18n'    => array(
        'generating'      => __('Generating…', 'meals-db'),
        'requestFailed'   => __('Request failed.', 'meals-db'),
        'draftSaveFailed' => __('Could not save the draft purchase order.', 'meals-db'),
    ),
);
?>
<script type="application/json" id="mealsdb-purchase-order-data"><?php echo wp_json_encode($mealsdb_po_island, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
