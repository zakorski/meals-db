/**
 * Private Customer Sales Report view behaviour.
 *
 * Extracted from the inline <script> that used to live in
 * views/private-sales.php (CLAUDE.md bans inline logic blocks > 20 lines).
 * Server-provided values (nonce, ajax URL, translated UI strings) are read
 * from the JSON data island #mealsdb-private-sales-data. This view renders
 * on the Reports page where window.mealsdb is NOT present, so the island
 * carries its own nonce + ajaxUrl rather than relying on a localized global.
 *
 * HTML-escaping, %.2f formatting, and the CSV download are delegated to the
 * shared window.MealsDBReport helpers (report-utils.js) instead of the
 * per-view reimplementations the inline script used to carry.
 */
(function ($) {
    'use strict';

    var _el = document.getElementById('mealsdb-private-sales-data');
    var data = _el ? JSON.parse(_el.textContent || '{}') : {};

    // Shared report helpers (esc / fmt / exportCsv / showStatus). Guard in
    // case report-utils.js failed to enqueue, so the view degrades instead
    // of throwing on first click.
    var R = window.MealsDBReport || {};
    var esc = R.esc || function (s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    };
    var fmt = R.fmt || function (v) { return parseFloat(v).toFixed(2); };
    var exportCsv = R.exportCsv || function (csvString, filename) {
        var blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };
    var showStatus = R.showStatus || function ($container, msg, type) {
        $container.show()
            .removeClass('notice-info notice-success notice-error notice-warning')
            .addClass('notice-' + type)
            .html($('<p>').text(msg == null ? '' : String(msg)));
    };

    var nonce = data.nonce || '';
    var ajaxUrl = data.ajaxUrl || (window.ajaxurl || '');
    var i18n = data.i18n || {};
    var csvData = '';

    function t(key, fallback) {
        return (i18n && i18n[key] != null) ? i18n[key] : fallback;
    }

    function status(msg, type) {
        showStatus($('#private-status'), msg, type);
    }

    // Integer-count formatter for mains/sides. Not a %.2f value, so this is
    // intentionally NOT MealsDBReport.fmt.
    function intText(v) {
        return String(parseInt(v, 10) || 0);
    }

    function buildTable(payload) {
        var rows   = payload.rows || [];
        var totals = payload.grand_totals || {};

        if (!rows.length) {
            return '<p>' + esc(t('noData', 'No private customer data found for this date range.')) + '</p>';
        }

        var html = '<table class="wp-list-table widefat striped"><thead><tr>';
        html += '<th>' + esc(t('colFirstName', 'First Name')) + '</th>';
        html += '<th>' + esc(t('colLastName', 'Last Name')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colTotalMains', 'Total Mains')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colTotalSides', 'Total Sides')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colTotalBeforeTax', 'Total Before Tax')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colTotalTax', 'Total Tax')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colFinalTotal', 'Final Total')) + '</th>';
        html += '</tr></thead><tbody>';

        $.each(rows, function (i, r) {
            html += '<tr>';
            html += '<td>' + esc(r.first_name) + '</td>';
            html += '<td>' + esc(r.last_name) + '</td>';
            html += '<td style="text-align:right">' + intText(r.total_mains) + '</td>';
            html += '<td style="text-align:right">' + intText(r.total_sides) + '</td>';
            html += '<td style="text-align:right">$' + fmt(r.total_before_tax) + '</td>';
            html += '<td style="text-align:right">$' + fmt(r.total_tax) + '</td>';
            html += '<td style="text-align:right">$' + fmt(r.final_total) + '</td>';
            html += '</tr>';
        });

        html += '</tbody><tfoot><tr>';
        html += '<th colspan="2">' + esc(t('grandTotal', 'Grand Total')) + '</th>';
        html += '<th style="text-align:right">' + intText(totals.total_mains) + '</th>';
        html += '<th style="text-align:right">' + intText(totals.total_sides) + '</th>';
        html += '<th style="text-align:right">$' + fmt(totals.total_before_tax || 0) + '</th>';
        html += '<th style="text-align:right">$' + fmt(totals.total_tax || 0) + '</th>';
        html += '<th style="text-align:right">$' + fmt(totals.final_total || 0) + '</th>';
        html += '</tr></tfoot></table>';

        return html;
    }

    $('#private-run').on('click', function () {
        var start = $('#private-start').val();
        var end   = $('#private-end').val();
        if (!start || !end) {
            status(t('selectDates', 'Please select both start and end dates.'), 'error');
            return;
        }
        status(t('running', 'Running report...'), 'info');
        $('#private-output').hide().empty();
        $('#private-export').hide();
        csvData = '';

        $.post(ajaxUrl, {
            action: 'mealsdb_private_customer_report',
            nonce: nonce,
            start_date: start,
            end_date: end
        }, function (resp) {
            if (resp.success) {
                $('#private-status').hide();
                $('#private-output').show().html(buildTable(resp.data));
                csvData = resp.csv || '';
                if (csvData) {
                    $('#private-export').show();
                }
            } else {
                var msg = (resp.data && resp.data.message) ? resp.data.message : t('reportFailed', 'Report failed.');
                status(msg, 'error');
            }
        }).fail(function () {
            status(t('requestFailed', 'Request failed.'), 'error');
        });
    });

    $('#private-export').on('click', function () {
        if (csvData) {
            exportCsv(csvData, 'private-customer-report-' + $('#private-start').val() + '-' + $('#private-end').val() + '.csv');
        }
    });

})(jQuery);
