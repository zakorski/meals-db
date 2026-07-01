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

    // Recalculate Allocations — manual phase-1 rebuild of all dirty client-months.
    $('#mealsdb-recalculate-allocations').on('click', function () {
        var $btn    = $(this);
        var $result = $('#mealsdb-recalculate-allocations-result');
        $btn.prop('disabled', true);
        $result.text('Running...'); tint($result, '#666');

        $.post(ajaxUrl, {
            action: 'mealsdb_recalculate_allocations',
            nonce: nonces.general || ''
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success) {
                var d = resp.data || {};
                $result.text(
                    'Rebuilt ' + (d.rebuilt || 0) + ' client-months' +
                    ((d.errors || 0) > 0 ? ' (' + d.errors + ' with spillover errors)' : '') + '.'
                );
                tint($result, (d.errors || 0) > 0 ? '#dba617' : '#46b450');
            } else {
                $result.text((resp && resp.data && resp.data.message) || 'Failed.');
                tint($result, '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    });

    // Private customer backfill — preview (read-only).
    $('#mealsdb-private-backfill-preview').on('click', function () {
        var $btn    = $(this);
        var $run    = $('#mealsdb-private-backfill-run');
        var $result = $('#mealsdb-private-backfill-result');
        var $rows   = $('#mealsdb-private-backfill-rows');
        var lookback = parseInt($('#mealsdb-private-backfill-lookback').val(), 10) || 24;
        $btn.prop('disabled', true);
        $run.prop('disabled', true);
        $rows.hide().empty();
        $result.text('Loading preview...'); tint($result, '#666');

        $.post(ajaxUrl, {
            action: 'mealsdb_preview_private_backfill',
            nonce: nonces.general || '',
            lookback_months: lookback
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success) {
                var count = (resp.data && resp.data.count) || 0;
                var rows  = (resp.data && resp.data.rows) || [];
                $result.text(count + ' user(s) eligible for promotion.'); tint($result, '#46b450');
                if (rows.length) {
                    var html = '<table class="widefat striped"><thead><tr><th>WP User</th><th>Name</th><th>Email</th><th>Orders</th></tr></thead><tbody>';
                    for (var i = 0; i < rows.length; i++) {
                        var r = rows[i];
                        html += '<tr><td>' + r.wp_user_id + '</td><td>' + $('<div>').text(r.name || '').html() +
                                '</td><td>' + $('<div>').text(r.email || '').html() +
                                '</td><td>' + r.order_count + '</td></tr>';
                    }
                    html += '</tbody></table>';
                    $rows.html(html).show();
                    $run.prop('disabled', false);
                }
            } else {
                $result.text((resp && resp.data && resp.data.message) || 'Preview failed.');
                tint($result, '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    });

    // Private customer backfill — run (mutating).
    $('#mealsdb-private-backfill-run').on('click', function () {
        var $btn    = $(this);
        var $result = $('#mealsdb-private-backfill-result');
        var lookback = parseInt($('#mealsdb-private-backfill-lookback').val(), 10) || 24;
        if (!window.confirm('Promote all eligible WC users into meals_clients as Private customers?')) {
            return;
        }
        $btn.prop('disabled', true);
        $result.text('Running backfill...'); tint($result, '#666');

        $.post(ajaxUrl, {
            action: 'mealsdb_run_private_backfill',
            nonce: nonces.general || '',
            lookback_months: lookback
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success) {
                var d = resp.data || {};
                $result.text(
                    'Promoted ' + (d.promoted || 0) + ' of ' + (d.eligible || 0) +
                    ' (errors: ' + (d.errors || 0) + ', skipped: ' + (d.skipped || 0) + ').'
                );
                tint($result, '#46b450');
                $('#mealsdb-private-backfill-rows').hide().empty();
            } else {
                $result.text((resp && resp.data && resp.data.message) || 'Backfill failed.');
                tint($result, '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    });

    // Private deactivation sweep — preview.
    $('#mealsdb-private-deact-preview').on('click', function () {
        var $btn    = $(this);
        var $run    = $('#mealsdb-private-deact-run');
        var $result = $('#mealsdb-private-deact-result');
        var $rows   = $('#mealsdb-private-deact-rows');
        var lookback = parseInt($('#mealsdb-private-deact-lookback').val(), 10) || 24;
        $btn.prop('disabled', true);
        $run.prop('disabled', true);
        $rows.hide().empty();
        $result.text('Loading preview...'); tint($result, '#666');

        $.post(ajaxUrl, {
            action: 'mealsdb_preview_private_deactivation',
            nonce: nonces.general || '',
            lookback_months: lookback
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success) {
                var count = (resp.data && resp.data.count) || 0;
                var rows  = (resp.data && resp.data.rows) || [];
                $result.text(count + ' stale Private record(s) found.'); tint($result, '#46b450');
                if (rows.length) {
                    var html = '<table class="widefat striped"><thead><tr><th>Client ID</th><th>WP User</th><th>Name</th></tr></thead><tbody>';
                    for (var i = 0; i < rows.length; i++) {
                        var r = rows[i];
                        html += '<tr><td>' + r.client_id + '</td><td>' + r.wp_user_id +
                                '</td><td>' + $('<div>').text(r.name || '').html() + '</td></tr>';
                    }
                    html += '</tbody></table>';
                    $rows.html(html).show();
                    $run.prop('disabled', false);
                }
            } else {
                $result.text((resp && resp.data && resp.data.message) || 'Preview failed.');
                tint($result, '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    });

    // Private deactivation sweep — run.
    $('#mealsdb-private-deact-run').on('click', function () {
        var $btn    = $(this);
        var $result = $('#mealsdb-private-deact-result');
        var lookback = parseInt($('#mealsdb-private-deact-lookback').val(), 10) || 24;
        if (!window.confirm('Deactivate every stale Private customer identified by the preview?')) {
            return;
        }
        $btn.prop('disabled', true);
        $result.text('Running sweep...'); tint($result, '#666');

        $.post(ajaxUrl, {
            action: 'mealsdb_run_private_deactivation',
            nonce: nonces.general || '',
            lookback_months: lookback
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success) {
                var d = resp.data || {};
                $result.text(
                    'Deactivated ' + (d.deactivated || 0) + ' of ' + (d.candidates || 0) +
                    ' (errors: ' + (d.errors || 0) + ').'
                );
                tint($result, '#46b450');
                $('#mealsdb-private-deact-rows').hide().empty();
            } else {
                $result.text((resp && resp.data && resp.data.message) || 'Sweep failed.');
                tint($result, '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    });

    // Enrich existing Private skeleton rows. Dry Run and live use the
    // same endpoint with a dry_run flag — keep them in one helper so
    // the result formatting stays consistent.
    function runEnrichSkeletons(dryRun) {
        var $dry    = $('#mealsdb-private-enrich-dry');
        var $run    = $('#mealsdb-private-enrich-run');
        var $result = $('#mealsdb-private-enrich-result');

        if (!dryRun && !window.confirm('Refill blank columns on every Private meals_clients row from usermeta + recent orders? Admin-set values are preserved.')) {
            return;
        }

        $dry.prop('disabled', true);
        $run.prop('disabled', true);
        $result.text(dryRun ? 'Running dry run...' : 'Enriching skeletons...');
        tint($result, '#666');

        var data = {
            action: 'mealsdb_enrich_private_skeletons',
            nonce: nonces.general || ''
        };
        if (dryRun) {
            data.dry_run = 1;
        }

        $.post(ajaxUrl, data, function (resp) {
            $dry.prop('disabled', false);
            $run.prop('disabled', false);
            if (resp && resp.success) {
                var d = resp.data || {};
                var prefix = dryRun ? 'Dry run: would enrich ' : 'Enriched ';
                $result.text(
                    prefix + (d.enriched || 0) + ' of ' + (d.scanned || 0) +
                    ' (skipped: ' + (d.skipped || 0) + ', errors: ' + (d.errors || 0) + ').'
                );
                tint($result, '#46b450');
            } else {
                $result.text((resp && resp.data && resp.data.message) || 'Enrich failed.');
                tint($result, '#dc3232');
            }
        }).fail(function () {
            $dry.prop('disabled', false);
            $run.prop('disabled', false);
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    }

    $('#mealsdb-private-enrich-dry').on('click', function () { runEnrichSkeletons(true); });
    $('#mealsdb-private-enrich-run').on('click', function () { runEnrichSkeletons(false); });

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

    // Case Count Sync — backfill case sizes from legacy data.
    $('#mealsdb-case-count-sync').on('click', function () {
        var $btn    = $(this);
        var $result = $('#mealsdb-case-count-sync-result');
        $btn.prop('disabled', true);
        $result.text('Syncing case counts...'); tint($result, '#666');

        $.post(ajaxUrl, {
            action: 'mealsdb_case_count_sync',
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
            // Checkbox: send '1' when checked, '0' when not, so the server can
            // distinguish an explicit "off" from "never set" (fail-safe ON).
            shadow_mode: $('input[name="shadow_mode"]').is(':checked') ? '1' : '0',
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
