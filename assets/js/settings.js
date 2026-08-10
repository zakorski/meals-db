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

    // runTool — the shared request lifecycle for the settings-page action
    // buttons. Every one of them did the same dance: (optional confirm) →
    // disable its button(s) and show a grey "running" line → POST → re-enable
    // → format the result, with an identical `.fail()` path. This centralises
    // that skeleton so a change to the lifecycle happens once; each caller
    // supplies only what varies via `done` (the success/error text, tint, and
    // any dependent buttons or result tables it manages itself).
    //
    // opts:
    //   action  {string}         AJAX action name (required).
    //   nonce   {string}         nonce value (default '').
    //   data    {object}         extra POST fields (optional).
    //   confirm {string}         window.confirm() gate; abort silently if the
    //                            operator declines (optional).
    //   buttons {jQuery[]}       buttons this call OWNS — disabled before the
    //                            request and re-enabled on BOTH success and
    //                            failure. Dependent buttons (e.g. a preview's
    //                            "run") are managed by the caller in `done`.
    //   $result {jQuery}         status-line element (optional).
    //   running {string}         text shown while in flight (default 'Running…').
    //   done    {function(resp)} response handler — owns all success/error
    //                            formatting. Called after the owned buttons are
    //                            re-enabled. NOT called on transport failure
    //                            (the shared `.fail()` path handles that).
    function runTool(opts) {
        if (opts.confirm && !window.confirm(opts.confirm)) {
            return;
        }
        var buttons = opts.buttons || [];
        function setDisabled(state) {
            for (var i = 0; i < buttons.length; i++) {
                buttons[i].prop('disabled', state);
            }
        }
        setDisabled(true);
        if (opts.$result) {
            opts.$result.text(opts.running || 'Running…');
            tint(opts.$result, '#666');
        }
        $.post(
            ajaxUrl,
            $.extend({ action: opts.action, nonce: opts.nonce || '' }, opts.data || {}),
            function (resp) {
                setDisabled(false);
                opts.done(resp);
            }
        ).fail(function () {
            setDisabled(false);
            if (opts.$result) {
                opts.$result.text('Request failed.');
                tint(opts.$result, '#dc3232');
            }
        });
    }

    // Generate key — no button/result lifecycle to share, so left standalone.
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

    // Resync delivery days from zones (replaced the retired Data-Ops
    // blank-fill button, 2026-07-11).
    $('#mealsdb-resync-delivery-days').on('click', function () {
        var $result  = $('#mealsdb-resync-result');
        var $orphans = $('#mealsdb-resync-orphans').hide().empty();
        runTool({
            action: 'mealsdb_resync_delivery_days',
            nonce: nonces.settings || '',
            buttons: [$(this)],
            $result: $result,
            running: 'Running…',
            done: function (res) {
                if (!res || !res.success) {
                    $result.text((res && res.data && res.data.message) || 'Request failed.');
                    tint($result, '#dc3232');
                    return;
                }
                var d = res.data || {};
                var orphans = d.orphans || [];
                $result.text(
                    d.updated + ' client(s) updated, ' +
                    (d.already_correct != null ? d.already_correct + ' already correct, ' : '') +
                    orphans.length + ' orphan(s).'
                );
                tint($result, orphans.length > 0 ? '#dba617' : '#46b450');
                if (orphans.length) {
                    var $list = $('<ul style="margin:4px 0 0 16px; list-style:disc;"></ul>');
                    $.each(orphans, function (_, o) {
                        // .text() per item — orphan names/zones are data, not HTML.
                        $('<li></li>').text(
                            '#' + o.client_id + ' ' + (o.first_name || '') + ' ' + (o.last_name || '')
                            + ' — zone: ' + (o.delivery_area_name || '(blank)')
                        ).appendTo($list);
                    });
                    $orphans.show()
                        .append($('<strong></strong>').text('Orphaned clients (fix their zone on the client form):'))
                        .append($list);
                }
            }
        });
    });

    // Backfill next_order_date / next_delivery_date
    $('#mealsdb-backfill-next-dates').on('click', function () {
        var $result = $('#mealsdb-backfill-next-dates-result');
        runTool({
            action: 'mealsdb_backfill_next_dates',
            nonce: nonces.general || '',
            buttons: [$(this)],
            $result: $result,
            running: 'Running...',
            done: function (resp) {
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
            }
        });
    });

    // Recalculate Allocations — manual phase-1 rebuild of all dirty client-months.
    $('#mealsdb-recalculate-allocations').on('click', function () {
        var $result = $('#mealsdb-recalculate-allocations-result');
        runTool({
            action: 'mealsdb_recalculate_allocations',
            nonce: nonces.general || '',
            buttons: [$(this)],
            $result: $result,
            running: 'Running...',
            done: function (resp) {
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
            }
        });
    });

    // Private customer backfill — preview (read-only). The dependent "run"
    // button is disabled up-front and only re-enabled here when the preview
    // returns rows, so it is NOT in runTool's owned-button set.
    $('#mealsdb-private-backfill-preview').on('click', function () {
        var $run    = $('#mealsdb-private-backfill-run');
        var $result = $('#mealsdb-private-backfill-result');
        var $rows   = $('#mealsdb-private-backfill-rows');
        var lookback = parseInt($('#mealsdb-private-backfill-lookback').val(), 10) || 24;
        $run.prop('disabled', true);
        $rows.hide().empty();
        runTool({
            action: 'mealsdb_preview_private_backfill',
            nonce: nonces.general || '',
            data: { lookback_months: lookback },
            buttons: [$(this)],
            $result: $result,
            running: 'Loading preview...',
            done: function (resp) {
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
            }
        });
    });

    // Private customer backfill — run (mutating).
    $('#mealsdb-private-backfill-run').on('click', function () {
        var $result = $('#mealsdb-private-backfill-result');
        var lookback = parseInt($('#mealsdb-private-backfill-lookback').val(), 10) || 24;
        runTool({
            action: 'mealsdb_run_private_backfill',
            nonce: nonces.general || '',
            data: { lookback_months: lookback },
            confirm: 'Promote all eligible WC users into meals_clients as Private customers?',
            buttons: [$(this)],
            $result: $result,
            running: 'Running backfill...',
            done: function (resp) {
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
            }
        });
    });

    // Private deactivation sweep — preview. Same dependent-button shape as the
    // backfill preview above.
    $('#mealsdb-private-deact-preview').on('click', function () {
        var $run    = $('#mealsdb-private-deact-run');
        var $result = $('#mealsdb-private-deact-result');
        var $rows   = $('#mealsdb-private-deact-rows');
        var lookback = parseInt($('#mealsdb-private-deact-lookback').val(), 10) || 24;
        $run.prop('disabled', true);
        $rows.hide().empty();
        runTool({
            action: 'mealsdb_preview_private_deactivation',
            nonce: nonces.general || '',
            data: { lookback_months: lookback },
            buttons: [$(this)],
            $result: $result,
            running: 'Loading preview...',
            done: function (resp) {
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
            }
        });
    });

    // Private deactivation sweep — run.
    $('#mealsdb-private-deact-run').on('click', function () {
        var $result = $('#mealsdb-private-deact-result');
        var lookback = parseInt($('#mealsdb-private-deact-lookback').val(), 10) || 24;
        runTool({
            action: 'mealsdb_run_private_deactivation',
            nonce: nonces.general || '',
            data: { lookback_months: lookback },
            confirm: 'Deactivate every stale Private customer identified by the preview?',
            buttons: [$(this)],
            $result: $result,
            running: 'Running sweep...',
            done: function (resp) {
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
            }
        });
    });

    // Enrich existing Private skeleton rows. Dry Run and live use the
    // same endpoint with a dry_run flag — keep them in one helper so
    // the result formatting stays consistent. Both buttons are owned.
    function runEnrichSkeletons(dryRun) {
        var $dry    = $('#mealsdb-private-enrich-dry');
        var $run    = $('#mealsdb-private-enrich-run');
        var $result = $('#mealsdb-private-enrich-result');
        runTool({
            action: 'mealsdb_enrich_private_skeletons',
            nonce: nonces.general || '',
            data: dryRun ? { dry_run: 1 } : {},
            confirm: dryRun ? null : 'Refill blank columns on every Private meals_clients row from usermeta + recent orders? Admin-set values are preserved.',
            buttons: [$dry, $run],
            $result: $result,
            running: dryRun ? 'Running dry run...' : 'Enriching skeletons...',
            done: function (resp) {
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
            }
        });
    }

    $('#mealsdb-private-enrich-dry').on('click', function () { runEnrichSkeletons(true); });
    $('#mealsdb-private-enrich-run').on('click', function () { runEnrichSkeletons(false); });

    // Sync product display data. NOTE: this endpoint returns a FLAT
    // { success, message } (wp_send_json), not the nested { data: {...} }
    // shape wp_send_json_success produces — so success/error text reads
    // resp.message, not resp.data.message. Same for Case Count Sync below.
    $('#mealsdb-sync-products').on('click', function () {
        var $result = $('#mealsdb-sync-products-result');
        runTool({
            action: 'mealsdb_sync_product_display',
            nonce: nonces.general || '',
            buttons: [$(this)],
            $result: $result,
            running: 'Syncing...',
            done: function (resp) {
                if (resp && resp.success) {
                    $result.text(resp.message || 'Done.'); tint($result, '#46b450');
                } else {
                    $result.text((resp && resp.message) || 'Sync failed.'); tint($result, '#dc3232');
                }
            }
        });
    });

    // Case Count Sync — backfill case sizes from legacy data. FLAT response
    // shape (see the Sync product note above).
    $('#mealsdb-case-count-sync').on('click', function () {
        var $result = $('#mealsdb-case-count-sync-result');
        runTool({
            action: 'mealsdb_case_count_sync',
            nonce: nonces.general || '',
            buttons: [$(this)],
            $result: $result,
            running: 'Syncing case counts...',
            done: function (resp) {
                if (resp && resp.success) {
                    $result.text(resp.message || 'Done.'); tint($result, '#46b450');
                } else {
                    $result.text((resp && resp.message) || 'Sync failed.'); tint($result, '#dc3232');
                }
            }
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

        // Per-field derived-value auto-correct toggles (directive
        // ITEM1-DERIVED). These were omitted from this hand-picked payload,
        // so the server — which rebuilds the option from POST on every save,
        // treating an absent key as all-off — silently reset auto-correct on
        // EVERY settings save and made the checkboxes impossible to enable.
        var derivedAutocorrect = {};
        $('input[name^="derived_autocorrect["]:checked').each(function () {
            var m = $(this).attr('name').match(/^derived_autocorrect\[(.+)\]$/);
            if (m) { derivedAutocorrect[m[1]] = '1'; }
        });

        $.post(ajaxUrl, {
            action: 'mealsdb_save_settings',
            nonce: nonces.settings || '',
            encryption_key: $('#mealsdb-enc-key').val(),
            // Checkbox: send '1' when checked, '0' when not, so the server can
            // distinguish an explicit "off" from "never set" (fail-safe ON).
            shadow_mode: $('input[name="shadow_mode"]').is(':checked') ? '1' : '0',
            // Advanced-tools menu visibility — same explicit '0'/'1'
            // convention as shadow_mode (server treats absent as '0').
            show_advanced_tools: $('input[name="show_advanced_tools"]').is(':checked') ? '1' : '0',
            overage_mains: $('#mealsdb-overage-mains').val(),
            overage_taxable_sides: $('#mealsdb-overage-taxable-sides').val(),
            overage_nontax_sides: $('#mealsdb-overage-nontax-sides').val(),
            zone_schedule: zoneSchedule,
            derived_autocorrect: derivedAutocorrect
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
