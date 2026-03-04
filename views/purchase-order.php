<?php
/**
 * Purchase Order / Predictive Ordering admin view.
 */
?>
<div id="mealsdb-purchase-order" class="mealsdb-purchase-order">
    <p class="description">
        <?php echo esc_html__('Generate a purchase order projection based on historical order demand. The system averages weekly product demand over the trailing period and projects forward.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-po-controls" style="margin-bottom:16px;">
        <label for="mealsdb-po-trailing"><?php echo esc_html__('Trailing Period:', 'meals-db'); ?></label>
        <select id="mealsdb-po-trailing">
            <option value="4"><?php echo esc_html__('4 weeks', 'meals-db'); ?></option>
            <option value="8" selected><?php echo esc_html__('8 weeks', 'meals-db'); ?></option>
            <option value="12"><?php echo esc_html__('12 weeks', 'meals-db'); ?></option>
        </select>

        <label for="mealsdb-po-horizon" style="margin-left:12px;"><?php echo esc_html__('Order Horizon:', 'meals-db'); ?></label>
        <select id="mealsdb-po-horizon">
            <option value="1" selected><?php echo esc_html__('1 week', 'meals-db'); ?></option>
            <option value="2"><?php echo esc_html__('2 weeks', 'meals-db'); ?></option>
        </select>

        <button type="button" class="button button-primary" id="mealsdb-po-generate" style="margin-left:12px;">
            <?php echo esc_html__('Generate', 'meals-db'); ?>
        </button>
        <button type="button" class="button" id="mealsdb-po-export" style="display:none; margin-left:8px;">
            <?php echo esc_html__('Export CSV', 'meals-db'); ?>
        </button>
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
        if (!data.length) return '<p>No demand data found for the trailing period.</p>';

        var html = '<table class="widefat striped"><thead><tr>';
        html += '<th>Product</th><th>Type</th><th>Avg Weekly</th><th>Projected</th>';
        html += '<th>Case Size</th><th>Cases</th><th>Unit Cost</th><th>Est. Cost</th>';
        html += '</tr></thead><tbody>';

        var totalCases = 0, totalCost = 0;
        $.each(data, function(i, r) {
            totalCases += r.cases_needed;
            totalCost += r.estimated_cost;
            html += '<tr>';
            html += '<td>' + esc(r.product_name) + '</td>';
            html += '<td>' + esc(r.product_type) + '</td>';
            html += '<td>' + r.avg_weekly_demand + '</td>';
            html += '<td>' + r.projected_units + '</td>';
            html += '<td>' + r.case_size + '</td>';
            html += '<td>' + r.cases_needed + '</td>';
            html += '<td>$' + parseFloat(r.unit_cost).toFixed(2) + '</td>';
            html += '<td>$' + parseFloat(r.estimated_cost).toFixed(2) + '</td>';
            html += '</tr>';
        });

        html += '</tbody><tfoot><tr>';
        html += '<th colspan="5">TOTAL</th>';
        html += '<th>' + totalCases + '</th>';
        html += '<th></th>';
        html += '<th>$' + totalCost.toFixed(2) + '</th>';
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
            weeks_ahead: horizon,
            trailing_weeks: trailing
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
