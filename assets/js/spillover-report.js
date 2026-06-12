/**
 * Over-Allowance Spillover Report — phase 3.
 *
 * Month picker -> AJAX -> table. Multi-month-error rows are highlighted.
 * CSV export uses the server-built csv string.
 *
 * Config injected via window.mealsdbSpilloverReport.
 */
(function ($, Report) {
    'use strict';

    if (!Report) {
        return;
    }

    var cfg     = window.mealsdbSpilloverReport || {};
    var ajaxUrl = cfg.ajaxUrl || window.ajaxurl || '';
    var nonce   = cfg.nonce   || '';

    var csvData = '';

    // Prefer the shared MealsDBReport.esc (STR-2 consolidation); fall back to the
    // local entity-replace so escaping is never disabled. Used in text context
    // only here, so the canonical (text) escaper renders identically.
    function esc(s) {
        if (s === null || s === undefined) { return ''; }
        if (window.MealsDBReport && typeof window.MealsDBReport.esc === 'function') {
            return window.MealsDBReport.esc(s);
        }
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function buildTable(rows) {
        if (!rows || !rows.length) {
            return '<p>' + 'No over-allowance spillover for the selected month.' + '</p>';
        }

        var html = '<table class="widefat striped">';
        html += '<thead><tr>';
        html += '<th>Delivery Date</th>';
        html += '<th>Client</th>';
        html += '<th>Order</th>';
        html += '<th>Mains in Month</th>';
        html += '<th>Sides in Month</th>';
        html += '<th>Mains Spilled</th>';
        html += '<th>Sides Spilled</th>';
        html += '<th>Status</th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function (r) {
            var rowClass = r.is_multi_month_error ? ' class="mealsdb-row-tint-error"' : '';
            var status   = r.is_multi_month_error
                ? '<strong>Multi-Month Error</strong><br><span style="font-size:11px;">' + esc(r.error_message || '') + '</span>'
                : 'Spilled to next month';

            html += '<tr' + rowClass + '>';
            html += '<td>' + esc(r.delivery_date || '') + '</td>';
            html += '<td>' + esc(r.client_name || '') + '</td>';
            html += '<td>' + esc(String(r.wc_order_id || '')) + '</td>';
            html += '<td>' + Number(r.mains_in_month || 0) + '</td>';
            html += '<td>' + Number(r.sides_in_month || 0) + '</td>';
            html += '<td>' + Number(r.mains_spilled || 0) + '</td>';
            html += '<td>' + Number(r.sides_spilled || 0) + '</td>';
            html += '<td>' + status + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    $('#spill-run').on('click', function () {
        var month = $('#spill-month').val();
        if (!month) {
            Report.showStatus($('#spill-status'), 'Please pick a billing month.', 'error');
            return;
        }
        Report.showStatus($('#spill-status'), 'Running report...', 'info');
        $('#spill-output').hide().empty();
        $('#spill-export').hide();
        csvData = '';

        $.post(ajaxUrl, {
            action: 'mealsdb_spillover_report',
            nonce: nonce,
            billing_month: month
        }, function (resp) {
            if (resp && resp.success) {
                var data = resp.data || {};
                var rows = data.rows || [];
                csvData  = data.csv  || '';
                $('#spill-status').hide();
                $('#spill-output').show().html(buildTable(rows));
                if (csvData) {
                    $('#spill-export').show();
                }
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Report failed.';
                Report.showStatus($('#spill-status'), msg, 'error');
            }
        }).fail(function () {
            Report.showStatus($('#spill-status'), 'Request failed.', 'error');
        });
    });

    $('#spill-export').on('click', function () {
        if (!csvData) { return; }
        var month = $('#spill-month').val() || 'spillover';
        var blob  = new Blob([csvData], { type: 'text/csv;charset=utf-8;' });
        var url   = URL.createObjectURL(blob);
        var a     = document.createElement('a');
        a.href = url;
        a.download = 'spillover-report-' + month + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });

})(jQuery, window.MealsDBReport);
