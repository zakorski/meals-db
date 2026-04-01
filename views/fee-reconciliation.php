<?php
/**
 * Fee Reconciliation admin view.
 *
 * Two sub-sections: Contribution Checker and Delivery Fee Checker.
 *
 * @package MealsDB
 */
?>
<div id="mealsdb-fee-reconciliation">

    <h2><?php esc_html_e('Contribution Checker', 'meals-db'); ?></h2>
    <p class="description">
        <?php esc_html_e('Compares expected client contributions (from meals_clients) against actual payments via WooCommerce product for a date range.', 'meals-db'); ?>
    </p>
    <div class="mealsdb-fee-controls" style="margin-bottom:16px; display:flex; gap:10px; align-items:flex-end;">
        <div>
            <label for="contrib-start"><?php esc_html_e('Start Date', 'meals-db'); ?></label><br>
            <input type="date" id="contrib-start" class="regular-text" />
        </div>
        <div>
            <label for="contrib-end"><?php esc_html_e('End Date', 'meals-db'); ?></label><br>
            <input type="date" id="contrib-end" class="regular-text" />
        </div>
        <div>
            <button type="button" class="button button-primary" id="contrib-run"><?php esc_html_e('Run Report', 'meals-db'); ?></button>
            <button type="button" class="button" id="contrib-export" style="display:none;"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
        </div>
    </div>
    <div id="contrib-status" class="notice" style="display:none;"></div>
    <div id="contrib-output" style="display:none;"></div>

    <hr style="margin:24px 0;" />

    <h2><?php esc_html_e('Delivery Fee Checker', 'meals-db'); ?></h2>
    <p class="description">
        <?php esc_html_e('Compares expected delivery fees (num_orders x fee rate) against actual payments via WooCommerce product for a date range.', 'meals-db'); ?>
    </p>
    <div class="mealsdb-fee-controls" style="margin-bottom:16px; display:flex; gap:10px; align-items:flex-end;">
        <div>
            <label for="delfee-start"><?php esc_html_e('Start Date', 'meals-db'); ?></label><br>
            <input type="date" id="delfee-start" class="regular-text" />
        </div>
        <div>
            <label for="delfee-end"><?php esc_html_e('End Date', 'meals-db'); ?></label><br>
            <input type="date" id="delfee-end" class="regular-text" />
        </div>
        <div>
            <button type="button" class="button button-primary" id="delfee-run"><?php esc_html_e('Run Report', 'meals-db'); ?></button>
            <button type="button" class="button" id="delfee-export" style="display:none;"><?php esc_html_e('Export CSV', 'meals-db'); ?></button>
        </div>
    </div>
    <div id="delfee-status" class="notice" style="display:none;"></div>
    <div id="delfee-output" style="display:none;"></div>

</div>

<script>
(function($) {
    'use strict';

    var nonce = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';
    var editUrl = '<?php echo esc_js(admin_url('admin.php?page=mealsdb&tab=clients&action=edit&id=')); ?>';
    var contribCsvData = '';
    var delfeeCsvData = '';

    function showStatus(selector, msg, type) {
        $(selector).show()
            .removeClass('notice-info notice-success notice-error')
            .addClass('notice-' + type)
            .html('<p>' + msg + '</p>');
    }

    function esc(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function fmt(val) {
        return parseFloat(val).toFixed(2);
    }

    function exportCsv(csvString, filename) {
        var blob = new Blob([csvString], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // ── Contribution Checker ──

    function buildContribTable(data) {
        var rows = data.rows || [];
        var summary = data.summary || {};
        var html = '<table class="wp-list-table widefat striped"><thead><tr>' +
            '<th>First Name</th><th>Last Name</th><th>Client Type</th>' +
            '<th style="text-align:right;">Expected</th><th style="text-align:right;">Actual Paid</th>' +
            '<th style="text-align:right;">Difference</th></tr></thead><tbody>';

        var csv = 'First Name,Last Name,Client Type,Expected,Actual Paid,Difference\n';

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var diff = parseFloat(r.difference);
            var style = diff !== 0 ? ' style="background:#fff3cd;"' : '';
            var link = '<a href="' + editUrl + r.client_id + '">' + esc(r.first_name) + '</a>';
            html += '<tr' + style + '><td>' + link + '</td><td>' + esc(r.last_name) + '</td>' +
                '<td>' + esc(r.client_type) + '</td>' +
                '<td style="text-align:right;">$' + fmt(r.expected) + '</td>' +
                '<td style="text-align:right;">$' + fmt(r.actual_paid) + '</td>' +
                '<td style="text-align:right;">$' + fmt(r.difference) + '</td></tr>';
            csv += '"' + r.first_name + '","' + r.last_name + '","' + r.client_type + '",' +
                fmt(r.expected) + ',' + fmt(r.actual_paid) + ',' + fmt(r.difference) + '\n';
        }

        html += '</tbody><tfoot><tr><th colspan="3"><strong>TOTAL</strong></th>' +
            '<th style="text-align:right;"><strong>$' + fmt(summary.total_expected) + '</strong></th>' +
            '<th style="text-align:right;"><strong>$' + fmt(summary.total_paid) + '</strong></th>' +
            '<th style="text-align:right;"><strong>$' + fmt(summary.total_difference) + '</strong></th>' +
            '</tr></tfoot></table>';

        csv += '"TOTAL","","","' + fmt(summary.total_expected) + '","' + fmt(summary.total_paid) + '","' + fmt(summary.total_difference) + '"\n';

        contribCsvData = csv;
        return html;
    }

    $('#contrib-run').on('click', function() {
        var start = $('#contrib-start').val();
        var end = $('#contrib-end').val();
        if (!start || !end) {
            showStatus('#contrib-status', 'Please select both start and end dates.', 'error');
            return;
        }

        showStatus('#contrib-status', 'Running report...', 'info');
        $('#contrib-output').hide().empty();
        $('#contrib-export').hide();

        $.post(ajaxurl, {
            action: 'mealsdb_contribution_reconciliation',
            nonce: nonce,
            start_date: start,
            end_date: end
        }, function(resp) {
            if (resp.success) {
                $('#contrib-status').hide();
                $('#contrib-output').show().html(buildContribTable(resp.data));
                $('#contrib-export').show();
            } else {
                showStatus('#contrib-status', resp.data.message || 'Report failed.', 'error');
            }
        }).fail(function() {
            showStatus('#contrib-status', 'Request failed.', 'error');
        });
    });

    $('#contrib-export').on('click', function() {
        if (contribCsvData) {
            exportCsv(contribCsvData, 'contribution-reconciliation-' + $('#contrib-start').val() + '-' + $('#contrib-end').val() + '.csv');
        }
    });

    // ── Delivery Fee Checker ──

    function buildDelfeeTable(data) {
        var rows = data.rows || [];
        var summary = data.summary || {};
        var html = '<table class="wp-list-table widefat striped"><thead><tr>' +
            '<th>First Name</th><th>Last Name</th><th>Client Type</th>' +
            '<th style="text-align:right;">Fee Rate</th><th style="text-align:right;"># Orders</th>' +
            '<th style="text-align:right;">Total Owed</th><th style="text-align:right;">Actual Paid</th>' +
            '<th style="text-align:right;">Difference</th></tr></thead><tbody>';

        var csv = 'First Name,Last Name,Client Type,Fee Rate,# Orders,Total Owed,Actual Paid,Difference\n';

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var diff = parseFloat(r.difference);
            var style = diff !== 0 ? ' style="background:#fff3cd;"' : '';
            var link = '<a href="' + editUrl + r.client_id + '">' + esc(r.first_name) + '</a>';
            html += '<tr' + style + '><td>' + link + '</td><td>' + esc(r.last_name) + '</td>' +
                '<td>' + esc(r.client_type) + '</td>' +
                '<td style="text-align:right;">$' + fmt(r.delivery_fee) + '</td>' +
                '<td style="text-align:right;">' + r.num_orders + '</td>' +
                '<td style="text-align:right;">$' + fmt(r.total_owed) + '</td>' +
                '<td style="text-align:right;">$' + fmt(r.actual_paid) + '</td>' +
                '<td style="text-align:right;">$' + fmt(r.difference) + '</td></tr>';
            csv += '"' + r.first_name + '","' + r.last_name + '","' + r.client_type + '",' +
                fmt(r.delivery_fee) + ',' + r.num_orders + ',' + fmt(r.total_owed) + ',' +
                fmt(r.actual_paid) + ',' + fmt(r.difference) + '\n';
        }

        html += '</tbody><tfoot><tr><th colspan="5"><strong>TOTAL</strong></th>' +
            '<th style="text-align:right;"><strong>$' + fmt(summary.total_owed) + '</strong></th>' +
            '<th style="text-align:right;"><strong>$' + fmt(summary.total_paid) + '</strong></th>' +
            '<th style="text-align:right;"><strong>$' + fmt(summary.total_difference) + '</strong></th>' +
            '</tr></tfoot></table>';

        csv += '"TOTAL","","","","","' + fmt(summary.total_owed) + '","' + fmt(summary.total_paid) + '","' + fmt(summary.total_difference) + '"\n';

        delfeeCsvData = csv;
        return html;
    }

    $('#delfee-run').on('click', function() {
        var start = $('#delfee-start').val();
        var end = $('#delfee-end').val();
        if (!start || !end) {
            showStatus('#delfee-status', 'Please select both start and end dates.', 'error');
            return;
        }

        showStatus('#delfee-status', 'Running report...', 'info');
        $('#delfee-output').hide().empty();
        $('#delfee-export').hide();

        $.post(ajaxurl, {
            action: 'mealsdb_delivery_fee_reconciliation',
            nonce: nonce,
            start_date: start,
            end_date: end
        }, function(resp) {
            if (resp.success) {
                $('#delfee-status').hide();
                $('#delfee-output').show().html(buildDelfeeTable(resp.data));
                $('#delfee-export').show();
            } else {
                showStatus('#delfee-status', resp.data.message || 'Report failed.', 'error');
            }
        }).fail(function() {
            showStatus('#delfee-status', 'Request failed.', 'error');
        });
    });

    $('#delfee-export').on('click', function() {
        if (delfeeCsvData) {
            exportCsv(delfeeCsvData, 'delivery-fee-reconciliation-' + $('#delfee-start').val() + '-' + $('#delfee-end').val() + '.csv');
        }
    });

})(jQuery);
</script>
