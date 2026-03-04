<?php
/**
 * Daily Slips admin view — packing, picking, and delivery slips.
 */
?>
<div id="mealsdb-daily-slips" class="mealsdb-daily-slips">
    <p class="description">
        <?php echo esc_html__('Generate packing, picking, and delivery slips for a given delivery date.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-slip-controls" style="margin-bottom:16px;">
        <label for="mealsdb-slip-date"><?php echo esc_html__('Delivery Date:', 'meals-db'); ?></label>
        <input type="date" id="mealsdb-slip-date" value="<?php echo esc_attr(date('Y-m-d')); ?>" />
        <button type="button" class="button" id="mealsdb-gen-packing">
            <?php echo esc_html__('Packing Slip', 'meals-db'); ?>
        </button>
        <button type="button" class="button" id="mealsdb-gen-picking">
            <?php echo esc_html__('Picking Slip', 'meals-db'); ?>
        </button>
        <button type="button" class="button" id="mealsdb-gen-delivery">
            <?php echo esc_html__('Delivery Slip', 'meals-db'); ?>
        </button>
        <button type="button" class="button" id="mealsdb-slip-print" style="display:none;">
            <?php echo esc_html__('Print', 'meals-db'); ?>
        </button>
    </div>

    <div id="mealsdb-slip-status" class="notice" style="display:none;"></div>

    <div id="mealsdb-slip-output" class="mealsdb-slip-output" style="display:none;"></div>
</div>

<style>
@media print {
    #wpadminbar, #adminmenumain, #adminmenuback, #adminmenuwrap,
    #wpfooter, .mealsdb-tab-nav, .mealsdb-slip-controls,
    #mealsdb-slip-status, .notice, .update-nag,
    #screen-meta, #screen-meta-links { display: none !important; }
    #wpcontent, #wpbody, #wpbody-content { margin: 0 !important; padding: 0 !important; }
    .mealsdb-slip-output { margin: 0; padding: 0; }
    .mealsdb-slip-output table { border-collapse: collapse; width: 100%; font-size: 11px; }
    .mealsdb-slip-output th, .mealsdb-slip-output td { border: 1px solid #333; padding: 4px 6px; }
    .mealsdb-slip-output th { background: #eee; }
    .mealsdb-slip-output h3 { margin: 12px 0 4px; }
}
.mealsdb-slip-output table { border-collapse: collapse; width: 100%; margin-bottom: 16px; }
.mealsdb-slip-output th, .mealsdb-slip-output td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
.mealsdb-slip-output th { background: #f1f1f1; }
.mealsdb-slip-output h3 { margin: 16px 0 6px; }
</style>

<script>
(function($) {
    'use strict';

    var nonce = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';

    function getDate() {
        return $('#mealsdb-slip-date').val();
    }

    function showStatus(msg, type) {
        $('#mealsdb-slip-status').show()
            .removeClass('notice-info notice-success notice-error notice-warning')
            .addClass('notice-' + type).html('<p>' + msg + '</p>');
    }

    function showOutput(html) {
        $('#mealsdb-slip-output').show().html(html);
        $('#mealsdb-slip-print').show();
    }

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function renderPackingSlip(data) {
        if (!data.length) return '<p>No orders found.</p>';
        var html = '<h3>Packing Slip</h3>';
        html += '<table><thead><tr><th>Initials</th><th>Zone</th><th>Area</th><th>Items</th></tr></thead><tbody>';
        $.each(data, function(i, entry) {
            var items = [];
            $.each(entry.items, function(j, item) {
                items.push(item.quantity + 'x ' + esc(item.name) + ' (' + esc(item.product_type) + ')');
            });
            html += '<tr><td>' + esc(entry.initials) + '</td><td>' + esc(entry.zone) + '</td><td>' + esc(entry.area_name) + '</td><td>' + items.join('<br>') + '</td></tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function renderPickingSlip(data) {
        if (!data.length) return '<p>No orders found.</p>';
        var html = '<h3>Picking Slip</h3>';
        html += '<table><thead><tr><th>Product</th><th>Type</th><th>Total Qty</th></tr></thead><tbody>';
        $.each(data, function(i, item) {
            html += '<tr><td>' + esc(item.product_name) + '</td><td>' + esc(item.product_type) + '</td><td>' + item.total_quantity + '</td></tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function renderDeliverySlip(data) {
        if (!data.length) return '<p>No orders found.</p>';
        var html = '<h3>Delivery Slip</h3>';
        $.each(data, function(i, group) {
            html += '<h3>' + esc(group.zone) + ' &mdash; ' + esc(group.area) + '</h3>';
            html += '<table><thead><tr><th>Initials</th><th>Address</th><th>Items</th></tr></thead><tbody>';
            $.each(group.stops, function(j, stop) {
                html += '<tr><td>' + esc(stop.initials) + '</td><td>' + esc(stop.address) + '</td><td>' + esc(stop.item_summary) + '</td></tr>';
            });
            html += '</tbody></table>';
        });
        return html;
    }

    function generate(action, renderer) {
        var date = getDate();
        if (!date) {
            showStatus('Please select a date.', 'warning');
            return;
        }
        showStatus('Generating...', 'info');
        $.post(ajaxurl, { action: action, nonce: nonce, delivery_date: date }, function(res) {
            if (!res.success) {
                showStatus(res.message || 'Error.', 'error');
                return;
            }
            $('#mealsdb-slip-status').hide();
            showOutput(renderer(res.data));
        }).fail(function() {
            showStatus('Request failed.', 'error');
        });
    }

    $('#mealsdb-gen-packing').on('click', function() { generate('mealsdb_generate_packing_slip', renderPackingSlip); });
    $('#mealsdb-gen-picking').on('click', function() { generate('mealsdb_generate_picking_slip', renderPickingSlip); });
    $('#mealsdb-gen-delivery').on('click', function() { generate('mealsdb_generate_delivery_slip', renderDeliverySlip); });
    $('#mealsdb-slip-print').on('click', function() { window.print(); });
})(jQuery);
</script>
