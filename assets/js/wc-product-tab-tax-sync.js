/**
 * Meals DB WooCommerce product tab — tax field sync.
 *
 * When the Meals DB "Product Type" is set to "meal", the standard
 * WooCommerce tax controls (taxable checkbox, tax status, tax class)
 * must be disabled and zeroed out so the resulting product cannot be
 * accidentally taxed at the WC level — Meals DB handles meal tax
 * server-side via its own pipeline.
 *
 * For non-meal products, the WC tax controls follow the Meals DB
 * "Taxable" checkbox. Toggling either control re-syncs the WC fields.
 *
 * This script was extracted from class-wc-product-tab.php; the original
 * was attached via wp_add_inline_script to the wc-admin-product-meta-boxes
 * handle. The dependency on that handle is preserved at enqueue time so
 * the execution order (and therefore the resulting DOM state) matches
 * the previous inline behavior.
 */
jQuery(function ($) {
    var productType = $('#_mealsdb_product_type');
    var taxableCheckbox = $('#_mealsdb_taxable');
    var taxStatus = $('#_tax_status');
    var taxClass = $('#_tax_class');

    function syncTaxFields() {
        var isMeal = productType.val() === 'meal';
        var isTaxable = taxableCheckbox.is(':checked');

        taxableCheckbox.prop('disabled', isMeal);

        if (isMeal) {
            taxableCheckbox.prop('checked', false);
        }

        taxStatus.prop('disabled', isMeal);
        taxClass.prop('disabled', isMeal);

        if (isMeal) {
            taxStatus.val('none').trigger('change');
            taxClass.val('').trigger('change');
            return;
        }

        taxStatus.val(isTaxable ? 'taxable' : 'none').trigger('change');
    }

    syncTaxFields();
    productType.on('change', syncTaxFields);
    taxableCheckbox.on('change', syncTaxFields);
});
