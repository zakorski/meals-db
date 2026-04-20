/**
 * Order Error Report page behaviour.
 *
 * Extracted from views/order-errors.php. Uses the shared MealsDBReport
 * helpers for CSV quoting and status rendering. Config is injected via
 * `window.mealsdbOrderErrors`.
 */
(function ($, Report) {
    'use strict';

    if (!Report) {
        return;
    }

    var cfg     = window.mealsdbOrderErrors || {};
    var ajaxUrl = cfg.ajaxUrl || window.ajaxurl || '';
    var nonce   = cfg.nonce   || '';
    var editUrl = cfg.editUrl || '';

    var csvData = '';

    // Map error_type → semantic CSS class. Colours live in admin.css
    // under the .mealsdb-row-tint-* rules; injecting them inline here
    // would require 'unsafe-inline' in style-src and would bypass any
    // site-level CSS override.
    var errorClasses = {
        missing_first_name: 'mealsdb-row-tint-error',
        missing_last_name:  'mealsdb-row-tint-error',
        nickname_too_long:  'mealsdb-row-tint-warn',
        missing_zone:       'mealsdb-row-tint-zone',
        invalid_zone:       'mealsdb-row-tint-zone',
        missing_address:    'mealsdb-row-tint-zone',
        no_client_record:   'mealsdb-row-tint-info'
    };

    function buildSummary(summary) {
        var html = '<div class="mealsdb-error-summary">';
        html += '<strong>Orders checked:</strong> ' + (parseInt(summary.total_orders_checked, 10) || 0);
        html += ' &nbsp;|&nbsp; <strong>Orders with errors:</strong> ' + (parseInt(summary.orders_with_errors, 10) || 0);

        var counts = summary.error_counts || {};
        var keys   = Object.keys(counts);
        if (keys.length) {
            html += '<br><br>';
            $.each(keys, function (_i, k) {
                var cls = errorClasses[k] || 'mealsdb-row-tint-info';
                html += '<span class="mealsdb-error-summary-pill ' + cls + '">';
                html += Report.esc(k.replace(/_/g, ' ')) + ': ' + (parseInt(counts[k], 10) || 0);
                html += '</span>';
            });
        }

        html += '</div>';
        return html;
    }

    function buildTable(data) {
        var errors  = data.errors  || [];
        var summary = data.summary || {};

        if (!errors.length) {
            return '<p>No errors found for this date range.</p>';
        }

        var html = buildSummary(summary);

        csvData = Report.csvRow(['Order ID', 'Order Date', 'Customer Name', 'WP User ID', 'Error Type', 'Error Detail']);

        html += '<table class="wp-list-table widefat striped"><thead><tr>';
        html += '<th>Order ID</th><th>Date</th><th>Customer</th><th>Error Type</th><th>Error Detail</th>';
        html += '</tr></thead><tbody>';

        $.each(errors, function (_i, e) {
            var cls     = errorClasses[e.error_type] || '';
            var attr    = cls ? ' class="' + cls + '"' : '';
            var orderId = parseInt(e.order_id, 10) || 0;
            html += '<tr' + attr + '>';
            html += '<td><a href="' + editUrl + orderId + '" target="_blank">#' + orderId + '</a></td>';
            html += '<td>' + Report.esc(e.order_date) + '</td>';
            html += '<td>' + Report.esc(e.customer_name) + '</td>';
            html += '<td>' + Report.esc(String(e.error_type || '').replace(/_/g, ' ')) + '</td>';
            html += '<td>' + Report.esc(e.error_detail) + '</td>';
            html += '</tr>';

            csvData += Report.csvRow([e.order_id, e.order_date, e.customer_name, e.wp_user_id, e.error_type, e.error_detail]);
        });

        html += '</tbody></table>';
        return html;
    }

    $('#errors-run').on('click', function () {
        var start = $('#errors-start').val();
        var end   = $('#errors-end').val();
        if (!start || !end) {
            Report.showStatus($('#errors-status'), 'Please select both start and end dates.', 'error');
            return;
        }
        Report.showStatus($('#errors-status'), 'Running report...', 'info');
        $('#errors-output').hide().empty();
        $('#errors-export').hide();
        csvData = '';

        $.post(ajaxUrl, {
            action: 'mealsdb_order_error_report',
            nonce: nonce,
            start_date: start,
            end_date: end
        }, function (resp) {
            if (resp && resp.success) {
                $('#errors-status').hide();
                $('#errors-output').show().html(buildTable(resp.data));
                if (csvData) {
                    $('#errors-export').show();
                }
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Report failed.';
                Report.showStatus($('#errors-status'), msg, 'error');
            }
        }).fail(function () {
            Report.showStatus($('#errors-status'), 'Request failed.', 'error');
        });
    });

    $('#errors-export').on('click', function () {
        if (csvData) {
            Report.exportCsv(csvData, 'order-errors-' + $('#errors-start').val() + '-' + $('#errors-end').val() + '.csv');
        }
    });
})(jQuery, window.MealsDBReport);
