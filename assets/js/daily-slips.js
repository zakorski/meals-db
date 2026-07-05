/**
 * Daily Slips admin view behaviour (extracted from views/daily-slips.php).
 *
 * Handles the slip-mode toggle (zone + date range vs. single delivery day),
 * the "All Zones" quick-select, and submitting a hidden POST form so the
 * browser handles the binary PDF download instead of parsing it as JSON.
 *
 * Server-provided values (nonce, ajax URL, translated status strings) are
 * read from the JSON data island #mealsdb-daily-slips-data emitted by the
 * view. Status rendering reuses window.MealsDBReport.showStatus so the
 * notice-class swapping logic lives in exactly one place (report-utils.js).
 */
(function ($) {
    'use strict';

    var _el  = document.getElementById('mealsdb-daily-slips-data');
    var data = _el ? JSON.parse(_el.textContent || '{}') : {};

    var R = window.MealsDBReport || {};
    var i18n = data.i18n || {};

    // admin-ajax endpoint: prefer the island value, fall back to WP's
    // global `ajaxurl` (always defined in wp-admin), which the inline
    // script used directly.
    var ajaxUrl = data.ajaxUrl || window.ajaxurl;
    var nonce   = data.nonce || '';

    // Render a status notice. Reuse the shared helper when present; fall
    // back to a minimal inline implementation so a missing dependency
    // degrades to plain text rather than a crash.
    function showStatus(msg, type) {
        var $el = $('#mealsdb-slip-status');
        if (typeof R.showStatus === 'function') {
            R.showStatus($el, msg, type);
            return;
        }
        $el.show()
            .removeClass('notice-info notice-success notice-error notice-warning')
            .addClass('notice-' + type)
            .empty()
            .append($('<p>').text(msg == null ? '' : String(msg))); // .text() — no HTML injection
    }

    // Mode toggle visibility.
    $('input[name="slip-mode"]').on('change', function () {
        if ($(this).val() === 'zone') {
            $('#mealsdb-zone-controls').show();
            $('#mealsdb-day-controls').hide();
        } else {
            $('#mealsdb-zone-controls').hide();
            $('#mealsdb-day-controls').show();
        }
    });

    // All Zones quick-select.
    $('#mealsdb-select-all-zones').on('click', function () {
        $('#mealsdb-zone-select option').prop('selected', true);
    });

    function getMode() {
        return $('input[name="slip-mode"]:checked').val();
    }

    // Submit a hidden form so the browser handles the binary PDF
    // download instead of trying to parse it as JSON in-tab.
    function submitDownloadForm(action, fields) {
        var $form = $('<form>', {
            method: 'POST',
            action: ajaxUrl,
            target: '_self'
        });
        $form.append($('<input>', { type: 'hidden', name: 'action', value: action }));
        $form.append($('<input>', { type: 'hidden', name: 'nonce',  value: nonce }));
        $.each(fields, function (name, value) {
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    $form.append($('<input>', { type: 'hidden', name: name + '[]', value: item }));
                });
            } else {
                $form.append($('<input>', { type: 'hidden', name: name, value: value }));
            }
        });
        // .trigger('submit') rather than the deprecated .submit() shorthand
        // (JQMIGRATE warns on jQuery.fn.submit() as an event-binding alias).
        $form.appendTo('body').trigger('submit').remove();
    }

    function buildRequestForMode(kind) {
        var mode = getMode();
        var fields = {};
        var action;

        if (mode === 'zone') {
            var zones = $('#mealsdb-zone-select').val();
            var start = $('#mealsdb-zone-start').val();
            var end   = $('#mealsdb-zone-end').val();
            if (!zones || !zones.length) {
                showStatus(i18n.selectZone || 'Please select at least one zone.', 'warning');
                return null;
            }
            if (!start || !end) {
                showStatus(i18n.selectDates || 'Please select a start and end date.', 'warning');
                return null;
            }
            action = (kind === 'packer') ? 'mealsdb_zone_packer_pdf' : 'mealsdb_zone_driver_pdf';
            fields.zones      = zones;
            fields.start_date = start;
            fields.end_date   = end;
        } else {
            var date = $('#mealsdb-slip-date').val();
            if (!date) {
                showStatus(i18n.selectDate || 'Please select a date.', 'warning');
                return null;
            }
            action = (kind === 'packer') ? 'mealsdb_packer_pdf' : 'mealsdb_driver_pdf';
            fields.delivery_date = date;
        }

        return { action: action, fields: fields };
    }

    function generate(kind) {
        var req = buildRequestForMode(kind);
        if (!req) {
            return;
        }

        showStatus(i18n.generating || 'Generating PDF — your download will start shortly.', 'info');
        submitDownloadForm(req.action, req.fields);
    }

    $('#mealsdb-gen-packer-pdf').on('click', function () { generate('packer'); });
    $('#mealsdb-gen-driver-pdf').on('click', function () { generate('driver'); });
})(jQuery);
