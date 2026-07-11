/**
 * Purchase Order — one-click draft generation (spec 2026-07-11).
 *
 * Generate posts mealsdb_po_save_draft (the server REGENERATES the forecast
 * rows and pallet-optimizes them; the browser never supplies row data) and
 * redirects to the new draft's detail page. The old forecast preview (table
 * render, pallet summary banner, CSV export) lives on only as the draft
 * detail page itself.
 */
(function ($) {
    'use strict';

    var _el  = document.getElementById('mealsdb-purchase-order-data');
    var data = _el ? JSON.parse(_el.textContent || '{}') : {};

    var i18n    = data.i18n || {};
    var ajaxUrl = data.ajaxUrl || window.ajaxurl;

    // Shared report helpers. Guard so an absent report-utils.js degrades
    // (minimal inline fallback) rather than crashing the view.
    var R = window.MealsDBReport || {};

    function t(key, fallback) {
        return (i18n[key] != null) ? i18n[key] : fallback;
    }

    function showStatus(msg, type) {
        var $el = $('#mealsdb-po-status');
        if (R.showStatus) {
            R.showStatus($el, msg, type);
            return;
        }
        $el.show()
            .removeClass('notice-info notice-success notice-error notice-warning')
            .addClass('notice-' + type)
            .html($('<p>').text(msg == null ? '' : String(msg))); // .text() — no HTML injection
    }

    $('#mealsdb-po-generate').on('click', function () {
        // Disabled while in flight: a double-click must not create two drafts.
        var $btn = $(this).prop('disabled', true);
        showStatus(t('generating', 'Generating…'), 'info');
        $.post(ajaxUrl, {
            action: 'mealsdb_po_save_draft',
            nonce: data.poNonce || ''
        }, function (res) {
            if (res && res.success && res.data && res.data.po_id) {
                window.location.href = (data.poAdminUrl || '') + '&po_id=' + parseInt(res.data.po_id, 10);
                return;
            }
            $btn.prop('disabled', false);
            showStatus((res && res.data && res.data.message) || t('draftSaveFailed', 'Could not save the draft purchase order.'), 'error');
        }).fail(function () {
            $btn.prop('disabled', false);
            showStatus(t('requestFailed', 'Request failed.'), 'error');
        });
    });
})(jQuery);
