<?php
/**
 * Appetito Purchase Order admin view.
 *
 * Peak-of-3-periods algorithm with buffer, inventory, and category exclusions.
 */
?>
<div id="mealsdb-purchase-order" class="mealsdb-purchase-order">
    <p class="description">
        <?php echo esc_html__('Generate an Appetito-style purchase order. Uses the highest sold quantity across three equal periods, adds buffer, and subtracts current + future inventory.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-po-controls" style="margin-bottom:16px; display:flex; gap:10px; align-items:flex-end;">
        <div>
            <label for="mealsdb-po-end-date"><?php echo esc_html__('End Date:', 'meals-db'); ?></label><br>
            <input type="date" id="mealsdb-po-end-date" value="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
        </div>
        <div>
            <label for="mealsdb-po-weeks"><?php echo esc_html__('Weeks per Period:', 'meals-db'); ?></label><br>
            <input type="number" id="mealsdb-po-weeks" value="6" min="1" max="12" style="width:70px;" />
        </div>
        <div>
            <label for="mealsdb-po-future-date"><?php echo esc_html__('Future Inventory Date:', 'meals-db'); ?></label><br>
            <input type="date" id="mealsdb-po-future-date" value="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
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

    function renderTable(data) {
        if (!data.length) return '<p>No demand data found for the selected periods.</p>';

        var html = '<table class="widefat striped"><thead><tr>';
        html += '<th>SKU</th><th>Product</th>';
        html += '<th style="text-align:right">P1</th><th style="text-align:right">P2</th><th style="text-align:right">P3</th>';
        html += '<th style="text-align:right">Highest</th><th style="text-align:right">Buffer</th>';
        html += '<th style="text-align:right">Case</th><th style="text-align:right">Stock</th>';
        html += '<th style="text-align:right">Future</th><th>Future Date</th>';
        html += '<th style="text-align:right">Need</th><th style="text-align:right">Units</th>';
        html += '<th style="text-align:right">Cases</th>';
        html += '</tr></thead><tbody>';

        var totalCases = 0;
        $.each(data, function(i, r) {
            totalCases += r.cases_to_buy;
            html += '<tr>';
            html += '<td>' + esc(r.sku) + '</td>';
            html += '<td>' + esc(r.product_name) + '</td>';
            html += '<td style="text-align:right">' + r.period_1 + '</td>';
            html += '<td style="text-align:right">' + r.period_2 + '</td>';
            html += '<td style="text-align:right">' + r.period_3 + '</td>';
            html += '<td style="text-align:right">' + r.highest_sold + '</td>';
            html += '<td style="text-align:right">' + r.buffer + '</td>';
            html += '<td style="text-align:right">' + r.case_size + '</td>';
            html += '<td style="text-align:right">' + r.current_inventory + '</td>';
            html += '<td style="text-align:right">' + r.future_inventory + '</td>';
            html += '<td>' + esc(r.future_inventory_date) + '</td>';
            html += '<td style="text-align:right">' + r.qty_needed + '</td>';
            html += '<td style="text-align:right">' + r.units_needed + '</td>';
            html += '<td style="text-align:right"><strong>' + r.cases_to_buy + '</strong></td>';
            html += '</tr>';
        });

        html += '</tbody><tfoot><tr>';
        html += '<th colspan="13">TOTAL</th>';
        html += '<th style="text-align:right">' + totalCases + '</th>';
        html += '</tr></tfoot></table>';

        return html;
    }

    $('#mealsdb-po-generate').on('click', function() {
        var endDate    = $('#mealsdb-po-end-date').val();
        var weeks      = $('#mealsdb-po-weeks').val();
        var futureDate = $('#mealsdb-po-future-date').val();

        if (!endDate) {
            showStatus('Please select an end date.', 'warning');
            return;
        }

        showStatus('Generating...', 'info');
        $('#mealsdb-po-export').hide();

        $.post(ajaxurl, {
            action: 'mealsdb_generate_purchase_order',
            nonce: nonce,
            end_date: endDate,
            weeks_per_period: weeks,
            future_inv_date: futureDate
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
        a.download = 'purchase-order-' + $('#mealsdb-po-end-date').val() + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
})(jQuery);
</script>
