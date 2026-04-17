<?php
/**
 * Private Customer Sales Report admin view.
 *
 * Per-client totals of mains, sides, and financials for private customers.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

?>
<div id="mealsdb-private-sales">

    <h2><?php esc_html_e('Private Customer Sales Report', 'meals-db'); ?></h2>
    <p class="description">
        <?php esc_html_e('Generates per-client totals of mains, sides, subtotals, tax, and final totals for private (non-government) customers within a date range.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-private-controls" style="margin-bottom:16px; display:flex; gap:10px; align-items:flex-end;">
        <div>
            <label for="private-start"><?php esc_html_e('Start Date', 'meals-db'); ?></label><br>
            <input type="date" id="private-start" class="regular-text" />
        </div>
        <div>
            <label for="private-end"><?php esc_html_e('End Date', 'meals-db'); ?></label><br>
            <input type="date" id="private-end" class="regular-text" />
        </div>
        <div>
            <button type="button" class="button button-primary" id="private-run"><?php esc_html_e('Run Report', 'meals-db'); ?></button>
            <button type="button" class="button" id="private-export" style="display:none;"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
        </div>
    </div>

    <div id="private-status" class="notice" style="display:none;"></div>
    <div id="private-output" style="display:none;"></div>

</div>

<script>
(function($) {
    'use strict';

    var nonce = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';
    var csvData = '';

    function showStatus(msg, type) {
        $('#private-status').show()
            .removeClass('notice-info notice-success notice-error notice-warning')
            .addClass('notice-' + type).html('<p>' + msg + '</p>');
    }

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function fmt(val) {
        return parseFloat(val).toFixed(2);
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

    function buildTable(data) {
        var rows   = data.rows || [];
        var totals = data.grand_totals || {};

        if (!rows.length) return '<p>No private customer data found for this date range.</p>';

        var html = '<table class="wp-list-table widefat striped"><thead><tr>';
        html += '<th>First Name</th><th>Last Name</th>';
        html += '<th style="text-align:right">Total Mains</th>';
        html += '<th style="text-align:right">Total Sides</th>';
        html += '<th style="text-align:right">Total Before Tax</th>';
        html += '<th style="text-align:right">Total Tax</th>';
        html += '<th style="text-align:right">Final Total</th>';
        html += '</tr></thead><tbody>';

        $.each(rows, function(i, r) {
            html += '<tr>';
            html += '<td>' + esc(r.first_name) + '</td>';
            html += '<td>' + esc(r.last_name) + '</td>';
            html += '<td style="text-align:right">' + r.total_mains + '</td>';
            html += '<td style="text-align:right">' + r.total_sides + '</td>';
            html += '<td style="text-align:right">$' + fmt(r.total_before_tax) + '</td>';
            html += '<td style="text-align:right">$' + fmt(r.total_tax) + '</td>';
            html += '<td style="text-align:right">$' + fmt(r.final_total) + '</td>';
            html += '</tr>';
        });

        html += '</tbody><tfoot><tr>';
        html += '<th colspan="2">Grand Total</th>';
        html += '<th style="text-align:right">' + (totals.total_mains || 0) + '</th>';
        html += '<th style="text-align:right">' + (totals.total_sides || 0) + '</th>';
        html += '<th style="text-align:right">$' + fmt(totals.total_before_tax || 0) + '</th>';
        html += '<th style="text-align:right">$' + fmt(totals.total_tax || 0) + '</th>';
        html += '<th style="text-align:right">$' + fmt(totals.final_total || 0) + '</th>';
        html += '</tr></tfoot></table>';

        return html;
    }

    $('#private-run').on('click', function() {
        var start = $('#private-start').val();
        var end   = $('#private-end').val();
        if (!start || !end) {
            showStatus('Please select both start and end dates.', 'error');
            return;
        }
        showStatus('Running report...', 'info');
        $('#private-output').hide().empty();
        $('#private-export').hide();
        csvData = '';

        $.post(ajaxurl, {
            action: 'mealsdb_private_customer_report',
            nonce: nonce,
            start_date: start,
            end_date: end
        }, function(resp) {
            if (resp.success) {
                $('#private-status').hide();
                $('#private-output').show().html(buildTable(resp.data));
                csvData = resp.csv || '';
                if (csvData) {
                    $('#private-export').show();
                }
            } else {
                var msg = (resp.data && resp.data.message) ? resp.data.message : 'Report failed.';
                showStatus(msg, 'error');
            }
        }).fail(function() {
            showStatus('Request failed.', 'error');
        });
    });

    $('#private-export').on('click', function() {
        if (csvData) {
            exportCsv(csvData, 'private-customer-report-' + $('#private-start').val() + '-' + $('#private-end').val() + '.csv');
        }
    });

})(jQuery);
</script>
