<?php
/**
 * Daily Slips admin view — packing, picking, and delivery slips.
 */
?>
<?php
$zone_schedule = get_option('mealsdb_zone_delivery_schedule', []);
?>
<div id="mealsdb-daily-slips" class="mealsdb-daily-slips">
    <p class="description">
        <?php echo esc_html__('Generate packing, picking, delivery, and driver slips. Use zone mode for the familiar zone + date range workflow, or delivery day mode to select by day-of-week.', 'meals-db'); ?>
    </p>

    <div class="mealsdb-slip-controls" style="margin-bottom:16px;">
        <!-- Mode toggle -->
        <div style="margin-bottom:12px;">
            <label>
                <input type="radio" name="slip-mode" value="zone" checked />
                <?php echo esc_html__('By Zone + Date Range', 'meals-db'); ?>
            </label>
            <label style="margin-left:16px;">
                <input type="radio" name="slip-mode" value="day" />
                <?php echo esc_html__('By Delivery Day', 'meals-db'); ?>
            </label>
        </div>

        <!-- Zone mode controls (shown by default) -->
        <div id="mealsdb-zone-controls" style="margin-bottom:10px;">
            <label for="mealsdb-zone-start"><?php echo esc_html__('Start Date:', 'meals-db'); ?></label>
            <input type="date" id="mealsdb-zone-start" value="<?php echo esc_attr(date('Y-m-d')); ?>" />

            <label for="mealsdb-zone-end" style="margin-left:8px;"><?php echo esc_html__('End Date:', 'meals-db'); ?></label>
            <input type="date" id="mealsdb-zone-end" value="<?php echo esc_attr(date('Y-m-d')); ?>" />

            <label for="mealsdb-zone-select" style="margin-left:8px;"><?php echo esc_html__('Zones:', 'meals-db'); ?></label>
            <select id="mealsdb-zone-select" multiple style="min-width:220px; height:auto; vertical-align:middle;">
                <?php foreach ($zone_schedule as $zone_name => $config) : ?>
                    <option value="<?php echo esc_attr($zone_name); ?>">
                        <?php echo esc_html($zone_name . ' - ' . $config['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Day mode controls (hidden by default) -->
        <div id="mealsdb-day-controls" style="display:none; margin-bottom:10px;">
            <label for="mealsdb-slip-date"><?php echo esc_html__('Delivery Date:', 'meals-db'); ?></label>
            <input type="date" id="mealsdb-slip-date" value="<?php echo esc_attr(date('Y-m-d')); ?>" />
        </div>

        <button type="button" class="button" id="mealsdb-gen-packing">
            <?php echo esc_html__('Packing Slip', 'meals-db'); ?>
        </button>
        <button type="button" class="button" id="mealsdb-gen-picking">
            <?php echo esc_html__('Picking Slip', 'meals-db'); ?>
        </button>
        <button type="button" class="button" id="mealsdb-gen-delivery">
            <?php echo esc_html__('Delivery Slip', 'meals-db'); ?>
        </button>
        <button type="button" class="button" id="mealsdb-gen-driver">
            <?php echo esc_html__('Driver Slips', 'meals-db'); ?>
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
    .mealsdb-slip-output .mealsdb-driver-zone + .mealsdb-driver-zone { page-break-before: always; }
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

    // Mode toggle visibility.
    $('input[name="slip-mode"]').on('change', function() {
        if ($(this).val() === 'zone') {
            $('#mealsdb-zone-controls').show();
            $('#mealsdb-day-controls').hide();
        } else {
            $('#mealsdb-zone-controls').hide();
            $('#mealsdb-day-controls').show();
        }
    });

    function getMode() {
        return $('input[name="slip-mode"]:checked').val();
    }

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
        var entries = data.entries || [];
        var noZone  = data.no_zone || [];
        var zoneSummaries = data.zone_summaries || [];
        if (!entries.length && !noZone.length) return '<p>No orders found.</p>';

        var html = '<h3>Packing Slip</h3>';

        // Zone summaries.
        if (zoneSummaries.length) {
            html += '<h3>Zone Summary</h3>';
            html += '<table><thead><tr><th>Zone</th><th>Orders</th><th>Mains</th><th>Sides</th><th>Soup</th><th>Muffins</th><th>Cereal</th><th>Dessert</th></tr></thead><tbody>';
            $.each(zoneSummaries, function(i, z) {
                var sd = z.side_breakdown || {};
                html += '<tr><td>' + esc(z.zone) + '</td><td>' + z.total_orders + '</td><td>' + z.total_mains + '</td><td>' + z.total_sides + '</td>';
                html += '<td>' + (sd.soup || 0) + '</td><td>' + (sd.muffins || 0) + '</td><td>' + (sd.cereal || 0) + '</td><td>' + (sd.dessert || 0) + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        // Main entries table.
        html += '<h3>Orders</h3>';
        html += '<table><thead><tr><th>Initials</th><th>Zone</th><th>Area</th><th>Mains</th><th>Sides</th><th>Items</th></tr></thead><tbody>';
        $.each(entries, function(i, entry) {
            var items = [];
            $.each(entry.items, function(j, item) {
                items.push(item.quantity + 'x ' + esc(item.name) + ' (' + esc(item.product_type) + ')');
            });
            html += '<tr><td>' + esc(entry.initials) + '</td><td>' + esc(entry.zone) + '</td><td>' + esc(entry.area_name) + '</td>';
            html += '<td>' + entry.mains_count + '</td><td>' + entry.sides_count + '</td>';
            html += '<td>' + items.join('<br>') + '</td></tr>';
        });
        html += '</tbody></table>';

        // No-zone warning section.
        if (noZone.length) {
            html += '<h3 style="color:#d63638;">Orders With No Zone</h3>';
            html += '<table style="border-color:#d63638;"><thead><tr><th>Initials</th><th>Mains</th><th>Sides</th><th>Items</th></tr></thead><tbody>';
            $.each(noZone, function(i, entry) {
                var items = [];
                $.each(entry.items, function(j, item) {
                    items.push(item.quantity + 'x ' + esc(item.name));
                });
                html += '<tr><td>' + esc(entry.initials) + '</td><td>' + entry.mains_count + '</td><td>' + entry.sides_count + '</td><td>' + items.join('<br>') + '</td></tr>';
            });
            html += '</tbody></table>';
        }

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
        var zones = data.zones || data;
        var cover = data.cover || [];
        if ((!zones.length && !cover.length)) return '<p>No orders found.</p>';
        var html = '<h3>Delivery Slip</h3>';

        // Cover sheet / delivery schedule.
        if (cover.length) {
            html += '<h3>Delivery Schedule</h3>';
            html += '<table><thead><tr><th>Zone</th><th>Area</th><th>Orders</th><th>Total Items</th></tr></thead><tbody>';
            $.each(cover, function(i, c) {
                html += '<tr><td>' + esc(c.zone) + '</td><td>' + esc(c.area) + '</td><td>' + c.order_count + '</td><td>' + c.total_items + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        // Zone detail.
        $.each(zones, function(i, group) {
            html += '<h3>' + esc(group.zone) + ' &mdash; ' + esc(group.area) + '</h3>';
            html += '<table><thead><tr><th>Initials</th><th>Address</th><th>Items</th></tr></thead><tbody>';
            $.each(group.stops, function(j, stop) {
                html += '<tr><td>' + esc(stop.initials) + '</td><td>' + esc(stop.address) + '</td><td>' + esc(stop.item_summary) + '</td></tr>';
            });
            html += '</tbody></table>';
        });
        return html;
    }

    function fmt(n) {
        return parseFloat(n).toFixed(2);
    }

    function renderDriverSlips(data) {
        if (!data.length) return '<p>No orders found.</p>';
        var html = '<h3>Driver Delivery Slips</h3>';

        $.each(data, function(i, zone) {
            html += '<div class="mealsdb-driver-zone">';
            html += '<h3>' + esc(zone.zone) + '</h3>';
            html += '<table><thead><tr>';
            html += '<th>Name</th><th>Address</th><th>City</th><th>Phone</th>';
            html += '<th style="text-align:right">Subtotal</th>';
            html += '<th style="text-align:right">Tax</th>';
            html += '<th style="text-align:right">Total</th>';
            html += '<th style="text-align:right">Collect</th>';
            html += '<th style="text-align:right">Delivery Fee</th>';
            html += '</tr></thead><tbody>';

            $.each(zone.orders, function(j, o) {
                html += '<tr>';
                html += '<td>' + esc(o.first_name) + ' ' + esc(o.last_name) + '</td>';
                html += '<td>' + esc(o.address) + '</td>';
                html += '<td>' + esc(o.city) + '</td>';
                html += '<td>' + esc(o.phone) + '</td>';
                html += '<td style="text-align:right">$' + fmt(o.subtotal) + '</td>';
                html += '<td style="text-align:right">$' + fmt(o.tax) + '</td>';
                html += '<td style="text-align:right">$' + fmt(o.total) + '</td>';
                html += '<td style="text-align:right">' + (o.collect !== null ? '$' + fmt(o.collect) : '') + '</td>';
                html += '<td style="text-align:right">' + (o.delivery_fee > 0 ? '$' + fmt(o.delivery_fee) : '') + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            html += '</div>';
        });

        return html;
    }

    function generate(slipType, renderer) {
        var mode = getMode();
        var data = { nonce: nonce };

        if (mode === 'zone') {
            data.action     = 'mealsdb_zone_' + slipType;
            data.zones      = $('#mealsdb-zone-select').val();
            data.start_date = $('#mealsdb-zone-start').val();
            data.end_date   = $('#mealsdb-zone-end').val();

            if (!data.zones || !data.zones.length) {
                showStatus('Please select at least one zone.', 'warning');
                return;
            }
            if (!data.start_date || !data.end_date) {
                showStatus('Please select a start and end date.', 'warning');
                return;
            }
        } else {
            data.action        = 'mealsdb_generate_' + slipType;
            data.delivery_date = getDate();

            if (!data.delivery_date) {
                showStatus('Please select a date.', 'warning');
                return;
            }
        }

        showStatus('Generating...', 'info');
        $.post(ajaxurl, data, function(res) {
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

    $('#mealsdb-gen-packing').on('click', function() { generate('packing_slip', renderPackingSlip); });
    $('#mealsdb-gen-picking').on('click', function() { generate('picking_slip', renderPickingSlip); });
    $('#mealsdb-gen-delivery').on('click', function() { generate('delivery_slip', renderDeliverySlip); });
    $('#mealsdb-gen-driver').on('click', function() { generate('driver_slips', renderDriverSlips); });
    $('#mealsdb-slip-print').on('click', function() { window.print(); });
})(jQuery);
</script>
