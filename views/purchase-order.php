<?php
/**
 * Purchase Order — Seasonally-Adjusted Projection admin view.
 *
 * Weighted demand with seasonal awareness, inventory subtraction,
 * and category exclusions.
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

?>
<div id="mealsdb-purchase-order" class="mealsdb-purchase-order">
    <p class="description">
        <?php echo esc_html__('Generate a seasonally-adjusted purchase order. Uses recency-weighted demand, year-over-year seasonal indices, and current inventory levels.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-po-controls" style="margin-bottom:16px; display:flex; gap:12px; align-items:flex-end;">
        <div>
            <label for="mealsdb-po-trailing"><?php echo esc_html__('Trailing Period:', 'meals-db'); ?></label><br>
            <select id="mealsdb-po-trailing">
                <option value="8"><?php echo esc_html__('8 weeks', 'meals-db'); ?></option>
                <option value="12" selected><?php echo esc_html__('12 weeks', 'meals-db'); ?></option>
                <option value="16"><?php echo esc_html__('16 weeks', 'meals-db'); ?></option>
            </select>
        </div>
        <div>
            <label for="mealsdb-po-horizon"><?php echo esc_html__('Order Horizon:', 'meals-db'); ?></label><br>
            <select id="mealsdb-po-horizon">
                <option value="4"><?php echo esc_html__('4 weeks', 'meals-db'); ?></option>
                <option value="6" selected><?php echo esc_html__('6 weeks', 'meals-db'); ?></option>
                <option value="8"><?php echo esc_html__('8 weeks', 'meals-db'); ?></option>
            </select>
        </div>
        <div>
            <button type="button" class="button button-primary" id="mealsdb-po-generate">
                <?php echo esc_html__('Generate', 'meals-db'); ?>
            </button>
            <button type="button" class="button" id="mealsdb-po-export" style="display:none;">
                <?php echo esc_html__('Export CSV', 'meals-db'); ?>
            </button>
        </div>
    </div>

    <div id="mealsdb-po-status" class="notice" style="display:none;"></div>

    <div id="mealsdb-po-output" style="display:none;"></div>
</div>

<script>
(function($) {
    'use strict';

    var nonce  = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';
    var csvData = '';

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function showStatus(msg, type) {
        $('#mealsdb-po-status').show()
            .removeClass('notice-info notice-success notice-error notice-warning')
            .addClass('notice-' + type).html('<p>' + msg + '</p>');
    }

    function rowStyle(si) {
        if (si > 1.5) return ' style="background:#fff3cd; font-weight:bold;"';
        if (si > 1.2) return ' style="background:#fff3cd;"';
        if (si < 0.8) return ' style="background:#d6eaf8;"';
        return '';
    }

    function renderTable(data) {
        if (!data.length) return '<p>No demand data found for the trailing period.</p>';

        var html = '<table class="widefat striped"><thead><tr>';
        html += '<th>SKU</th><th>Product</th>';
        html += '<th style="text-align:right">Avg/Wk</th>';
        html += '<th style="text-align:right">Seasonal</th>';
        html += '<th style="text-align:right">Adj/Wk</th>';
        html += '<th style="text-align:right">Projected</th>';
        html += '<th style="text-align:right">Buffer</th>';
        html += '<th style="text-align:right">Needed</th>';
        html += '<th style="text-align:right">Stock</th>';
        html += '<th style="text-align:right">Cases</th>';
        html += '<th style="text-align:right">Order Qty</th>';
        html += '<th>Note</th>';
        html += '</tr></thead><tbody>';

        var totalCases = 0, totalQty = 0;
        $.each(data, function(i, r) {
            totalCases += r.cases_to_buy;
            totalQty   += r.order_quantity;
            html += '<tr' + rowStyle(r.seasonal_index) + '>';
            html += '<td>' + esc(r.sku) + '</td>';
            html += '<td>' + esc(r.product_name) + '</td>';
            html += '<td style="text-align:right">' + r.weighted_avg_weekly + '</td>';
            html += '<td style="text-align:right">' + r.seasonal_index + '</td>';
            html += '<td style="text-align:right">' + r.adjusted_weekly + '</td>';
            html += '<td style="text-align:right">' + r.projected_need + '</td>';
            html += '<td style="text-align:right">' + r.buffer + '</td>';
            html += '<td style="text-align:right">' + r.qty_needed + '</td>';
            html += '<td style="text-align:right">' + r.total_available + '</td>';
            html += '<td style="text-align:right"><strong>' + r.cases_to_buy + '</strong></td>';
            html += '<td style="text-align:right">' + r.order_quantity + '</td>';
            html += '<td>' + esc(r.seasonal_note) + '</td>';
            html += '</tr>';
        });

        html += '</tbody><tfoot><tr>';
        html += '<th colspan="9">TOTAL</th>';
        html += '<th style="text-align:right">' + totalCases + '</th>';
        html += '<th style="text-align:right">' + totalQty + '</th>';
        html += '<th></th>';
        html += '</tr></tfoot></table>';

        return html;
    }

    $('#mealsdb-po-generate').on('click', function() {
        var trailing = $('#mealsdb-po-trailing').val();
        var horizon  = $('#mealsdb-po-horizon').val();

        showStatus('Generating...', 'info');
        $('#mealsdb-po-export').hide();

        $.post(ajaxurl, {
            action: 'mealsdb_generate_purchase_order',
            nonce: nonce,
            trailing_weeks: trailing,
            order_horizon_weeks: horizon
        }, function(res) {
            if (!res.success) {
                showStatus(res.message || 'Error generating purchase order.', 'error');
                return;
            }
            $('#mealsdb-po-status').hide();
            $('#mealsdb-po-output').show().html(renderTable(res.data));
            csvData = res.csv || '';
            if (csvData) {
                $('#mealsdb-po-export').show();
            }
        }).fail(function() {
            showStatus('Request failed.', 'error');
        });
    });

    $('#mealsdb-po-export').on('click', function() {
        if (!csvData) return;
        var blob = new Blob([csvData], { type: 'text/csv;charset=utf-8;' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href     = url;
        a.download = 'purchase-order-' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
})(jQuery);
</script>
