/**
 * Meals DB WooCommerce product tab — tax field sync.
 *
 * When the Meals DB "Product Type" is set to "meal", the standard
 * WooCommerce tax controls (tax status, tax class) are disabled and
 * zeroed out so the product cannot be accidentally taxed at the WC level
 * — Meals DB handles meal tax server-side via its own pipeline.
 *
 * The per-product "Taxable" checkbox this script used to drive was removed
 * (DIRECTIVE ITEM 3): side taxability is now purely category-derived and set
 * server-side on save (dessert/muffin => taxable). So for NON-meal products
 * this script no longer forces the WC tax status — the server is the
 * authority — it only handles the meal safety case above.
 *
 * The dependency on wc-admin-product-meta-boxes is preserved at enqueue time
 * so this runs after WC has built the tax controls.
 */
jQuery(function ($) {
    var productType = $('#_mealsdb_product_type');
    var taxStatus = $('#_tax_status');
    var taxClass = $('#_tax_class');

    function syncTaxFields() {
        var isMeal = productType.val() === 'meal';

        taxStatus.prop('disabled', isMeal);
        taxClass.prop('disabled', isMeal);

        if (isMeal) {
            taxStatus.val('none').trigger('change');
            taxClass.val('').trigger('change');
        }
    }

    syncTaxFields();
    productType.on('change', syncTaxFields);
});
