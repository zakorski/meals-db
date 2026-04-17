<?php
/**
 * Order Error Report admin view.
 *
 * Data quality checks across WC orders for a date range.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

?>
<div id="mealsdb-order-errors">

    <h2><?php esc_html_e('Order Error Report', 'meals-db'); ?></h2>
    <p class="description">
        <?php esc_html_e('Scans WooCommerce orders for common data quality issues: missing names, invalid initials, missing zones/addresses, and unlinked accounts.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-error-controls" style="margin-bottom:16px; display:flex; gap:10px; align-items:flex-end;">
        <div>
            <label for="errors-start"><?php esc_html_e('Start Date', 'meals-db'); ?></label><br>
            <input type="date" id="errors-start" class="regular-text" />
        </div>
        <div>
            <label for="errors-end"><?php esc_html_e('End Date', 'meals-db'); ?></label><br>
            <input type="date" id="errors-end" class="regular-text" />
        </div>
        <div>
            <button type="button" class="button button-primary" id="errors-run"><?php esc_html_e('Run Report', 'meals-db'); ?></button>
            <button type="button" class="button" id="errors-export" style="display:none;"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
        </div>
    </div>

    <div id="errors-status" class="notice" style="display:none;"></div>
    <div id="errors-output" style="display:none;"></div>

</div>

<script>
(function($) {
    'use strict';

    var nonce = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';
    var editUrl = '<?php echo esc_js(admin_url('post.php?action=edit&post=')); ?>';
    var csvData = '';

    var errorColors = {
        missing_first_name: '#f8d7da',
        missing_last_name:  '#f8d7da',
        nickname_too_long:  '#fff3cd',
        missing_zone:       '#ffe0b2',
        invalid_zone:       '#ffe0b2',
        missing_address:    '#ffe0b2',
        no_client_record:   '#d6eaf8'
    };

    function showStatus(msg, type) {
        $('#errors-status').show()
            .removeClass('notice-info notice-success notice-error notice-warning')
            .addClass('notice-' + type).html('<p>' + msg + '</p>');
    }

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function exportCsv(csvString, filename) {
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

    function buildSummary(summary) {
        var html = '<div style="margin-bottom:16px; padding:12px; background:#f9f9f9; border:1px solid #ddd;">';
        html += '<strong>Orders checked:</strong> ' + summary.total_orders_checked;
        html += ' &nbsp;|&nbsp; <strong>Orders with errors:</strong> ' + summary.orders_with_errors;

        var counts = summary.error_counts || {};
        var keys = Object.keys(counts);
        if (keys.length) {
            html += '<br><br>';
            $.each(keys, function(i, k) {
                var bg = errorColors[k] || '#eee';
                html += '<span style="display:inline-block; margin:2px 8px 2px 0; padding:2px 8px; background:' + bg + '; border-radius:3px;">';
                html += esc(k.replace(/_/g, ' ')) + ': ' + counts[k];
                html += '</span>';
            });
        }

        html += '</div>';
        return html;
    }

    function buildTable(data) {
        var errors  = data.errors || [];
        var summary = data.summary || {};

        if (!errors.length) return '<p>No errors found for this date range.</p>';

        var html = buildSummary(summary);

        // Build CSV.
        csvData = 'Order ID,Order Date,Customer Name,WP User ID,Error Type,Error Detail\n';

        html += '<table class="wp-list-table widefat striped"><thead><tr>';
        html += '<th>Order ID</th><th>Date</th><th>Customer</th><th>Error Type</th><th>Error Detail</th>';
        html += '</tr></thead><tbody>';

        $.each(errors, function(i, e) {
            var bg = errorColors[e.error_type] || '';
            var style = bg ? ' style="background:' + bg + ';"' : '';
            html += '<tr' + style + '>';
            html += '<td><a href="' + editUrl + e.order_id + '" target="_blank">#' + e.order_id + '</a></td>';
            html += '<td>' + esc(e.order_date) + '</td>';
            html += '<td>' + esc(e.customer_name) + '</td>';
            html += '<td>' + esc(e.error_type.replace(/_/g, ' ')) + '</td>';
            html += '<td>' + esc(e.error_detail) + '</td>';
            html += '</tr>';

            csvData += e.order_id + ',' + e.order_date + ',"' + (e.customer_name || '').replace(/"/g, '""') + '",'
                     + e.wp_user_id + ',' + e.error_type + ',"' + (e.error_detail || '').replace(/"/g, '""') + '"\n';
        });

        html += '</tbody></table>';
        return html;
    }

    $('#errors-run').on('click', function() {
        var start = $('#errors-start').val();
        var end   = $('#errors-end').val();
        if (!start || !end) {
            showStatus('Please select both start and end dates.', 'error');
            return;
        }
        showStatus('Running report...', 'info');
        $('#errors-output').hide().empty();
        $('#errors-export').hide();
        csvData = '';

        $.post(ajaxurl, {
            action: 'mealsdb_order_error_report',
            nonce: nonce,
            start_date: start,
            end_date: end
        }, function(resp) {
            if (resp.success) {
                $('#errors-status').hide();
                $('#errors-output').show().html(buildTable(resp.data));
                if (csvData) {
                    $('#errors-export').show();
                }
            } else {
                var msg = (resp.data && resp.data.message) ? resp.data.message : 'Report failed.';
                showStatus(msg, 'error');
            }
        }).fail(function() {
            showStatus('Request failed.', 'error');
        });
    });

    $('#errors-export').on('click', function() {
        if (csvData) {
            exportCsv(csvData, 'order-errors-' + $('#errors-start').val() + '-' + $('#errors-end').val() + '.csv');
        }
    });

})(jQuery);
</script>
