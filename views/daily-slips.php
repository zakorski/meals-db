<?php
/**
 * Daily Slips admin view — Phase T per-order PDF generation.
 *
 * Two PDF outputs per generation request:
 *   - Packer slips (no financial info, right column reserved for notes)
 *   - Driver slips (packer slip + collection breakdown + customer info)
 *
 * Slip-mode toggle (zone + date range vs. single delivery day) is
 * preserved from Phase Q.
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

$zone_schedule = get_option('mealsdb_zone_delivery_schedule', []);
?>
<div id="mealsdb-daily-slips" class="mealsdb-daily-slips">
    <p class="description">
        <?php echo esc_html__('Generate per-order packer and driver PDF slips. Use zone mode for the familiar zone + date range workflow, or delivery day mode to select by day-of-week.', 'meals-db'); ?>
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
            <button type="button" id="mealsdb-select-all-zones" class="button button-small" style="margin-left:6px;">
                <?php esc_html_e('All Zones', 'meals-db'); ?>
            </button>
        </div>

        <!-- Day mode controls (hidden by default) -->
        <div id="mealsdb-day-controls" style="display:none; margin-bottom:10px;">
            <label for="mealsdb-slip-date"><?php echo esc_html__('Delivery Date:', 'meals-db'); ?></label>
            <input type="date" id="mealsdb-slip-date" value="<?php echo esc_attr(date('Y-m-d')); ?>" />
        </div>

        <button type="button" class="button button-primary" id="mealsdb-gen-packer-pdf">
            <?php echo esc_html__('Generate Packer Slips PDF', 'meals-db'); ?>
        </button>
        <button type="button" class="button button-primary" id="mealsdb-gen-driver-pdf">
            <?php echo esc_html__('Generate Driver Slips PDF', 'meals-db'); ?>
        </button>
    </div>

    <div id="mealsdb-slip-status" class="notice" style="display:none;"></div>
</div>

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

    // All Zones quick-select.
    $('#mealsdb-select-all-zones').on('click', function() {
        $('#mealsdb-zone-select option').prop('selected', true);
    });

    function getMode() {
        return $('input[name="slip-mode"]:checked').val();
    }

    function showStatus(msg, type) {
        $('#mealsdb-slip-status').show()
            .removeClass('notice-info notice-success notice-error notice-warning')
            .addClass('notice-' + type).empty().append($('<p>').text(msg)); // .text() — no HTML injection
    }

    // Submit a hidden form so the browser handles the binary PDF
    // download instead of trying to parse it as JSON in-tab.
    function submitDownloadForm(action, fields) {
        var $form = $('<form>', {
            method: 'POST',
            action: ajaxurl,
            target: '_self'
        });
        $form.append($('<input>', { type: 'hidden', name: 'action', value: action }));
        $form.append($('<input>', { type: 'hidden', name: 'nonce',  value: nonce }));
        $.each(fields, function(name, value) {
            if (Array.isArray(value)) {
                value.forEach(function(item) {
                    $form.append($('<input>', { type: 'hidden', name: name + '[]', value: item }));
                });
            } else {
                $form.append($('<input>', { type: 'hidden', name: name, value: value }));
            }
        });
        // .trigger('submit') rather than the deprecated .submit() shorthand
        // (JQMIGRATE warns on jQuery.fn.submit() as an event-binding alias).
        $form.appendTo('body').trigger('submit').remove();
    }

    function buildRequestForMode(kind) {
        var mode = getMode();
        var fields = {};
        var action;

        if (mode === 'zone') {
            var zones = $('#mealsdb-zone-select').val();
            var start = $('#mealsdb-zone-start').val();
            var end   = $('#mealsdb-zone-end').val();
            if (!zones || !zones.length) {
                showStatus('Please select at least one zone.', 'warning');
                return null;
            }
            if (!start || !end) {
                showStatus('Please select a start and end date.', 'warning');
                return null;
            }
            action = (kind === 'packer') ? 'mealsdb_zone_packer_pdf' : 'mealsdb_zone_driver_pdf';
            fields.zones      = zones;
            fields.start_date = start;
            fields.end_date   = end;
        } else {
            var date = $('#mealsdb-slip-date').val();
            if (!date) {
                showStatus('Please select a date.', 'warning');
                return null;
            }
            action = (kind === 'packer') ? 'mealsdb_packer_pdf' : 'mealsdb_driver_pdf';
            fields.delivery_date = date;
        }

        return { action: action, fields: fields };
    }

    function generate(kind) {
        var req = buildRequestForMode(kind);
        if (!req) return;

        showStatus('Generating PDF — your download will start shortly.', 'info');
        submitDownloadForm(req.action, req.fields);
    }

    $('#mealsdb-gen-packer-pdf').on('click', function() { generate('packer'); });
    $('#mealsdb-gen-driver-pdf').on('click', function() { generate('driver'); });
})(jQuery);
</script>
