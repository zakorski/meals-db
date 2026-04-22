/**
 * Meals DB settings page behaviour.
 *
 * Extracted from the inline <script> block that used to live at the
 * bottom of views/settings.php so the file can be cached, minified,
 * and evaluated under a strict Content-Security-Policy without
 * requiring 'unsafe-inline'.
 *
 * Configuration (nonces, AJAX URL) is injected via the global
 * `window.mealsdbSettings` object that class-admin-ui.php builds
 * with wp_add_inline_script() + wp_json_encode().
 */
(function ($) {
    'use strict';

    var cfg = window.mealsdbSettings || {};
    var ajaxUrl = cfg.ajaxUrl || window.ajaxurl || '';
    var nonces  = cfg.nonces  || {};

    function tint($el, color) {
        $el.css('color', color);
    }

    // Generate key
    $('#mealsdb-generate-key').on('click', function () {
        $.post(ajaxUrl, {
            action: 'mealsdb_generate_encryption_key',
            nonce: nonces.settings || ''
        }, function (resp) {
            if (resp && resp.success && resp.data && resp.data.key) {
                $('#mealsdb-enc-key').val(resp.data.key);
                $('#mealsdb-key-warning').show();
            }
        });
    });

    // Backfill delivery_day
    $('#mealsdb-backfill-delivery-day').on('click', function () {
        var $btn    = $(this);
        var $result = $('#mealsdb-backfill-result');
        $btn.prop('disabled', true);
        $result.text('Running...'); tint($result, '#666');

        $.post(ajaxUrl, {
            action: 'mealsdb_backfill_delivery_day',
            nonce: nonces.general || ''
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success) {
                $result.text(resp.message || 'Done.'); tint($result, '#46b450');
            } else {
                $result.text((resp && resp.message) || 'Failed.'); tint($result, '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    });

    // Backfill next_order_date / next_delivery_date
    $('#mealsdb-backfill-next-dates').on('click', function () {
        var $btn    = $(this);
        var $result = $('#mealsdb-backfill-next-dates-result');
        $btn.prop('disabled', true);
        $result.text('Running...'); tint($result, '#666');

        $.post(ajaxUrl, {
            action: 'mealsdb_backfill_next_dates',
            nonce: nonces.general || ''
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success) {
                var d = resp.data || {};
                $result.text(
                    'Processed ' + (d.processed || 0) +
                    ' clients: ' + (d.order_updated || 0) + ' order dates, ' +
                    (d.delivery_updated || 0) + ' delivery dates updated (' +
                    (d.skipped || 0) + ' skipped).'
                );
                tint($result, '#46b450');
            } else {
                $result.text((resp && resp.data && resp.data.message) || 'Failed.');
                tint($result, '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    });

    // Sync product display data
    $('#mealsdb-sync-products').on('click', function () {
        var $btn    = $(this);
        var $result = $('#mealsdb-sync-products-result');
        $btn.prop('disabled', true);
        $result.text('Syncing...'); tint($result, '#666');

        $.post(ajaxUrl, {
            action: 'mealsdb_sync_product_display',
            nonce: nonces.general || ''
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success) {
                $result.text(resp.message || 'Done.'); tint($result, '#46b450');
            } else {
                $result.text((resp && resp.message) || 'Sync failed.'); tint($result, '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    });

    // Save settings
    $('#mealsdb-settings-form').on('submit', function (e) {
        e.preventDefault();

        var $result = $('#mealsdb-save-result');
        $result.text('Saving...'); tint($result, '#666');

        // Collect zone schedule data from the editable table rows.
        var zoneSchedule = {};
        $('#mealsdb-zone-schedule-table tbody tr').each(function () {
            var zoneName = $(this).find('td:first strong').text();
            var day      = $(this).find('.mealsdb-zone-day').val();
            var label    = $(this).find('input[type="text"]').val();
            if (zoneName) {
                zoneSchedule[zoneName] = { day: day, label: label };
            }
        });

        $.post(ajaxUrl, {
            action: 'mealsdb_save_settings',
            nonce: nonces.settings || '',
            encryption_key: $('#mealsdb-enc-key').val(),
            overage_mains: $('#mealsdb-overage-mains').val(),
            overage_taxable_sides: $('#mealsdb-overage-taxable-sides').val(),
            overage_nontax_sides: $('#mealsdb-overage-nontax-sides').val(),
            zone_schedule: zoneSchedule
        }, function (resp) {
            if (resp && resp.success) {
                $result.text('Settings saved.'); tint($result, '#46b450');
                $('#mealsdb-key-warning').hide();
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Save failed.';
                $result.text(msg); tint($result, '#dc3232');
            }
        }).fail(function () {
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    });
})(jQuery);
