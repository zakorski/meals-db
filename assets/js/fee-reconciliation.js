/**
 * Fee Reconciliation report page behaviour.
 *
 * Extracted from views/fee-reconciliation.php. Uses the shared
 * MealsDBReport helpers for CSV quoting and status rendering.
 * Config is injected via `window.mealsdbFeeReconciliation`.
 */
(function ($, Report) {
    'use strict';

    if (!Report) {
        return;
    }

    var cfg     = window.mealsdbFeeReconciliation || {};
    var ajaxUrl = cfg.ajaxUrl || window.ajaxurl || '';
    var nonce   = cfg.nonce   || '';
    var editUrl = cfg.editUrl || '';

    var contribCsvData = '';
    var delfeeCsvData  = '';

    // ── Contribution Checker ──

    function buildContribTable(data) {
        var rows    = data.rows    || [];
        var summary = data.summary || {};
        var html = '<table class="wp-list-table widefat striped"><thead><tr>' +
            '<th>First Name</th><th>Last Name</th><th>Client Type</th>' +
            '<th style="text-align:right;">Expected</th><th style="text-align:right;">Actual Paid</th>' +
            '<th style="text-align:right;">Difference</th></tr></thead><tbody>';

        var csv = Report.csvRow(['First Name', 'Last Name', 'Client Type', 'Expected', 'Actual Paid', 'Difference']);

        for (var i = 0; i < rows.length; i++) {
            var r     = rows[i];
            var diff  = parseFloat(r.difference);
            var style = Math.abs(diff) > 0.005 ? ' style="background:#fff3cd;"' : '';
            var link  = '<a href="' + editUrl + (parseInt(r.client_id, 10) || 0) + '">' + Report.esc(r.first_name) + '</a>';
            html += '<tr' + style + '><td>' + link + '</td><td>' + Report.esc(r.last_name) + '</td>' +
                '<td>' + Report.esc(r.client_type) + '</td>' +
                '<td style="text-align:right;">$' + Report.fmt(r.expected) + '</td>' +
                '<td style="text-align:right;">$' + Report.fmt(r.actual_paid) + '</td>' +
                '<td style="text-align:right;">$' + Report.fmt(r.difference) + '</td></tr>';
            csv += Report.csvRow([r.first_name, r.last_name, r.client_type, Report.fmt(r.expected), Report.fmt(r.actual_paid), Report.fmt(r.difference)]);
        }

        html += '</tbody><tfoot><tr><th colspan="3"><strong>TOTAL</strong></th>' +
            '<th style="text-align:right;"><strong>$' + Report.fmt(summary.total_expected) + '</strong></th>' +
            '<th style="text-align:right;"><strong>$' + Report.fmt(summary.total_paid) + '</strong></th>' +
            '<th style="text-align:right;"><strong>$' + Report.fmt(summary.total_difference) + '</strong></th>' +
            '</tr></tfoot></table>';

        csv += Report.csvRow(['TOTAL', '', '', Report.fmt(summary.total_expected), Report.fmt(summary.total_paid), Report.fmt(summary.total_difference)]);

        contribCsvData = csv;
        return html;
    }

    $('#contrib-run').on('click', function () {
        var start = $('#contrib-start').val();
        var end   = $('#contrib-end').val();
        if (!start || !end) {
            Report.showStatus($('#contrib-status'), 'Please select both start and end dates.', 'error');
            return;
        }

        Report.showStatus($('#contrib-status'), 'Running report...', 'info');
        $('#contrib-output').hide().empty();
        $('#contrib-export').hide();

        $.post(ajaxUrl, {
            action: 'mealsdb_contribution_reconciliation',
            nonce: nonce,
            start_date: start,
            end_date: end
        }, function (resp) {
            if (resp && resp.success) {
                $('#contrib-status').hide();
                $('#contrib-output').show().html(buildContribTable(resp.data));
                $('#contrib-export').show();
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Report failed.';
                Report.showStatus($('#contrib-status'), msg, 'error');
            }
        }).fail(function () {
            Report.showStatus($('#contrib-status'), 'Request failed.', 'error');
        });
    });

    $('#contrib-export').on('click', function () {
        if (contribCsvData) {
            Report.exportCsv(contribCsvData, 'contribution-reconciliation-' + $('#contrib-start').val() + '-' + $('#contrib-end').val() + '.csv');
        }
    });

    // ── Delivery Fee Checker ──

    function buildDelfeeTable(data) {
        var rows    = data.rows    || [];
        var summary = data.summary || {};
        var html = '<table class="wp-list-table widefat striped"><thead><tr>' +
            '<th>First Name</th><th>Last Name</th><th>Client Type</th>' +
            '<th style="text-align:right;">Fee Rate</th><th style="text-align:right;"># Orders</th>' +
            '<th style="text-align:right;">Total Owed</th><th style="text-align:right;">Actual Paid</th>' +
            '<th style="text-align:right;">Difference</th></tr></thead><tbody>';

        var csv = Report.csvRow(['First Name', 'Last Name', 'Client Type', 'Fee Rate', '# Orders', 'Total Owed', 'Actual Paid', 'Difference']);

        for (var i = 0; i < rows.length; i++) {
            var r     = rows[i];
            var diff  = parseFloat(r.difference);
            var style = Math.abs(diff) > 0.005 ? ' style="background:#fff3cd;"' : '';
            var link  = '<a href="' + editUrl + (parseInt(r.client_id, 10) || 0) + '">' + Report.esc(r.first_name) + '</a>';
            html += '<tr' + style + '><td>' + link + '</td><td>' + Report.esc(r.last_name) + '</td>' +
                '<td>' + Report.esc(r.client_type) + '</td>' +
                '<td style="text-align:right;">$' + Report.fmt(r.delivery_fee) + '</td>' +
                '<td style="text-align:right;">' + (parseInt(r.num_orders, 10) || 0) + '</td>' +
                '<td style="text-align:right;">$' + Report.fmt(r.total_owed) + '</td>' +
                '<td style="text-align:right;">$' + Report.fmt(r.actual_paid) + '</td>' +
                '<td style="text-align:right;">$' + Report.fmt(r.difference) + '</td></tr>';
            csv += Report.csvRow([r.first_name, r.last_name, r.client_type, Report.fmt(r.delivery_fee), r.num_orders, Report.fmt(r.total_owed), Report.fmt(r.actual_paid), Report.fmt(r.difference)]);
        }

        html += '</tbody><tfoot><tr><th colspan="5"><strong>TOTAL</strong></th>' +
            '<th style="text-align:right;"><strong>$' + Report.fmt(summary.total_owed) + '</strong></th>' +
            '<th style="text-align:right;"><strong>$' + Report.fmt(summary.total_paid) + '</strong></th>' +
            '<th style="text-align:right;"><strong>$' + Report.fmt(summary.total_difference) + '</strong></th>' +
            '</tr></tfoot></table>';

        csv += Report.csvRow(['TOTAL', '', '', '', '', Report.fmt(summary.total_owed), Report.fmt(summary.total_paid), Report.fmt(summary.total_difference)]);

        delfeeCsvData = csv;
        return html;
    }

    $('#delfee-run').on('click', function () {
        var start = $('#delfee-start').val();
        var end   = $('#delfee-end').val();
        if (!start || !end) {
            Report.showStatus($('#delfee-status'), 'Please select both start and end dates.', 'error');
            return;
        }

        Report.showStatus($('#delfee-status'), 'Running report...', 'info');
        $('#delfee-output').hide().empty();
        $('#delfee-export').hide();

        $.post(ajaxUrl, {
            action: 'mealsdb_delivery_fee_reconciliation',
            nonce: nonce,
            start_date: start,
            end_date: end
        }, function (resp) {
            if (resp && resp.success) {
                $('#delfee-status').hide();
                $('#delfee-output').show().html(buildDelfeeTable(resp.data));
                $('#delfee-export').show();
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Report failed.';
                Report.showStatus($('#delfee-status'), msg, 'error');
            }
        }).fail(function () {
            Report.showStatus($('#delfee-status'), 'Request failed.', 'error');
        });
    });

    $('#delfee-export').on('click', function () {
        if (delfeeCsvData) {
            Report.exportCsv(delfeeCsvData, 'delivery-fee-reconciliation-' + $('#delfee-start').val() + '-' + $('#delfee-end').val() + '.csv');
        }
    });
})(jQuery, window.MealsDBReport);
