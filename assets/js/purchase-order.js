/**
 * Purchase Order — Seasonally-Adjusted Projection admin view.
 *
 * Extracted from an inline <script> block in views/purchase-order.php per
 * the CLAUDE.md "no inline logic > 20 lines" rule. Server data is read from
 * the JSON island #mealsdb-purchase-order-data instead of PHP interpolation.
 *
 * HTML-escaping, %.2f formatting, the notice/status renderer, and the CSV
 * download were previously hand-rolled here; they now delegate to the shared
 * window.MealsDBReport helpers (with minimal inline fallbacks so a missing
 * report-utils.js degrades instead of throwing).
 */
(function ($) {
    'use strict';

    var _el  = document.getElementById('mealsdb-purchase-order-data');
    var data = _el ? JSON.parse(_el.textContent || '{}') : {};

    var i18n    = data.i18n || {};
    var nonce   = data.nonce || '';
    var ajaxUrl = data.ajaxUrl || window.ajaxurl;

    // Shared report helpers. Guard so an absent report-utils.js degrades
    // (minimal inline fallbacks) rather than crashing the view.
    var R = window.MealsDBReport || {};
    var esc = R.esc || function (str) {
        var d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    };
    var fmt = R.fmt || function (val) {
        return parseFloat(val).toFixed(2);
    };

    var csvData = '';

    function t(key, fallback) {
        return (i18n[key] != null) ? i18n[key] : fallback;
    }

    // Integer display formatter — distinct from the shared %.2f fmt(); the
    // shared helper is not a substitute because these columns are whole units.
    function intText(v) {
        return String(parseInt(v, 10) || 0);
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

    function exportCsv(csvString, filename) {
        if (R.exportCsv) {
            R.exportCsv(csvString, filename);
            return;
        }
        var blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href     = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function rowStyle(si) {
        if (si > 1.5) return ' style="background:#fff3cd; font-weight:bold;"';
        if (si > 1.2) return ' style="background:#fff3cd;"';
        if (si < 0.8) return ' style="background:#d6eaf8;"';
        return '';
    }

    function renderTable(rows) {
        if (!rows.length) return '<p>' + esc(t('noData', 'No demand data found for the trailing period.')) + '</p>';

        var html = '<table class="widefat striped"><thead><tr>';
        html += '<th>' + esc(t('colSku', 'SKU')) + '</th><th>' + esc(t('colProduct', 'Product')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colAvgWk', 'Avg/Wk')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colSeasonal', 'Seasonal')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colAdjWk', 'Adj/Wk')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colProjected', 'Projected')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colStock', 'Stock')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colCases', 'Cases')) + '</th>';
        html += '<th style="text-align:right">' + esc(t('colOrderQty', 'Order Qty')) + '</th>';
        html += '<th>' + esc(t('colNote', 'Note')) + '</th>';
        html += '</tr></thead><tbody>';

        var totalCases = 0, totalQty = 0;
        $.each(rows, function (i, r) {
            totalCases += r.cases_to_buy;
            totalQty   += r.order_quantity;
            html += '<tr' + rowStyle(r.seasonal_index) + '>';
            html += '<td>' + esc(r.sku) + '</td>';
            html += '<td>' + esc(r.product_name) + '</td>';
            html += '<td style="text-align:right">' + fmt(r.weighted_avg_weekly) + '</td>';
            html += '<td style="text-align:right">' + fmt(r.seasonal_index) + '</td>';
            html += '<td style="text-align:right">' + fmt(r.adjusted_weekly) + '</td>';
            html += '<td style="text-align:right">' + fmt(r.projected_need) + '</td>';
            html += '<td style="text-align:right">' + intText(r.total_available) + '</td>';
            html += '<td style="text-align:right"><strong>' + intText(r.cases_to_buy) + '</strong></td>';
            html += '<td style="text-align:right">' + intText(r.order_quantity) + '</td>';
            html += '<td>' + esc(r.seasonal_note) + '</td>';
            html += '</tr>';
        });

        html += '</tbody><tfoot><tr>';
        html += '<th colspan="7">' + esc(t('total', 'TOTAL')) + '</th>';
        html += '<th style="text-align:right">' + intText(totalCases) + '</th>';
        html += '<th style="text-align:right">' + intText(totalQty) + '</th>';
        html += '<th></th>';
        html += '</tr></tfoot></table>';

        return html;
    }

    $('#mealsdb-po-generate').on('click', function () {
        // The forecast model is fixed (validated 3-week-buffer model); no
        // tunable parameters are sent.
        showStatus(t('generating', 'Generating...'), 'info');
        $('#mealsdb-po-export').hide();

        $.post(ajaxUrl, {
            action: 'mealsdb_generate_purchase_order',
            nonce: nonce
        }, function (res) {
            if (!res.success) {
                showStatus(res.message || t('errorGenerating', 'Error generating purchase order.'), 'error');
                return;
            }
            $('#mealsdb-po-status').hide();
            $('#mealsdb-po-output').show().html(renderTable(res.data));
            csvData = res.csv || '';
            if (csvData) {
                $('#mealsdb-po-export').show();
            }
        }).fail(function () {
            showStatus(t('requestFailed', 'Request failed.'), 'error');
        });
    });

    $('#mealsdb-po-export').on('click', function () {
        if (!csvData) return;
        var filename = 'purchase-order-' + new Date().toISOString().slice(0, 10) + '.csv';
        exportCsv(csvData, filename);
    });
})(jQuery);
