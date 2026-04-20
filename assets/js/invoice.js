/**
 * Invoice Generation JavaScript
 *
 * Handles the invoice generation form and AJAX requests
 *
 * @package MealsDB
 * @since 1.0.249
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Initialize date pickers
        $('.datepicker').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            maxDate: 0 // Today
        });

        // Set default dates (first and last day of previous month)
        var today = new Date();
        var firstDay = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        var lastDay = new Date(today.getFullYear(), today.getMonth(), 0);

        $('#start_date').val(formatDate(firstDay));
        $('#end_date').val(formatDate(lastDay));

        // Show/hide zone field based on invoice type
        $('#invoice_type').on('change', function() {
            var invoiceType = $(this).val();

            if (invoiceType === 'sdnb_legacy') {
                $('#zone_row').show();
                $('#zone').prop('required', true);
                $('#weeks_row').show();
            } else {
                $('#zone_row').hide();
                $('#zone').prop('required', false);
                $('#weeks_row').hide();
            }
        });

        // Handle form submission
        $('#mealsdb-invoice-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn = $('#generate_invoice_btn');
            var $message = $('#invoice_message');

            // Get form data
            var invoiceType = $('#invoice_type').val();
            var zone = $('#zone').val();
            var startDate = $('#start_date').val();
            var endDate = $('#end_date').val();

            // Validate
            if (!invoiceType) {
                showMessage('error', 'Please select an invoice type.');
                return;
            }

            if (invoiceType === 'sdnb_legacy' && !zone) {
                showMessage('error', 'Please select a zone for SDNB legacy invoices.');
                return;
            }

            if (!startDate || !endDate) {
                showMessage('error', 'Please select start and end dates.');
                return;
            }

            // Disable button and show loading
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update-alt" style="vertical-align: middle; animation: rotation 2s infinite linear;"></span> Generating...');

            // Create form for download
            var downloadForm = $('<form>', {
                method: 'POST',
                action: mealsdbInvoice.ajaxUrl
            });

            downloadForm.append($('<input>', {
                type: 'hidden',
                name: 'action',
                value: 'mealsdb_generate_invoice'
            }));

            downloadForm.append($('<input>', {
                type: 'hidden',
                name: 'nonce',
                value: mealsdbInvoice.nonce
            }));

            downloadForm.append($('<input>', {
                type: 'hidden',
                name: 'invoice_type',
                value: invoiceType
            }));

            downloadForm.append($('<input>', {
                type: 'hidden',
                name: 'zone',
                value: zone
            }));

            downloadForm.append($('<input>', {
                type: 'hidden',
                name: 'start_date',
                value: startDate
            }));

            downloadForm.append($('<input>', {
                type: 'hidden',
                name: 'end_date',
                value: endDate
            }));

            downloadForm.append($('<input>', {
                type: 'hidden',
                name: 'weeks_in_month',
                value: $('#weeks_in_month').val() || '4'
            }));

            // Submit in new window/tab to trigger download
            downloadForm.appendTo('body').submit().remove();

            // Re-enable button after delay
            setTimeout(function() {
                $btn.prop('disabled', false);
                $btn.html('<span class="dashicons dashicons-download" style="vertical-align: middle;"></span> Generate Invoice');
                showMessage('success', 'Invoice download started. If the download doesn\'t start, please check your popup blocker settings.');
            }, 2000);
        });

        /**
         * Show message to user.
         *
         * `message` may originate from server-supplied error text or
         * concatenated client names (see showOveragesMessage callers),
         * so route through jQuery's text-setting API rather than the
         * previous `.html('<p>' + message + '</p>')` which would let
         * any < or & in the payload execute as HTML.
         */
        function showMessage(type, message) {
            var $message = $('#invoice_message');
            $message
                .removeClass('notice-success notice-error notice-warning')
                .addClass('notice notice-' + type)
                .empty()
                .append($('<p>').text(message == null ? '' : String(message)))
                .slideDown();

            setTimeout(function() {
                $message.slideUp();
            }, 5000);
        }

        /**
         * Format date as YYYY-MM-DD
         */
        function formatDate(date) {
            var year = date.getFullYear();
            var month = String(date.getMonth() + 1).padStart(2, '0');
            var day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }

        // --- Overages section ---

        // Set default dates for overages fields too.
        $('#overage_start_date').val(formatDate(firstDay));
        $('#overage_end_date').val(formatDate(lastDay));

        // Show/hide SDNB-specific fields.
        $('#overage_client_type').on('change', function() {
            if ($(this).val() === 'SDNB') {
                $('#overage_zone_row').show();
                $('#overage_weeks_row').show();
            } else {
                $('#overage_zone_row').hide();
                $('#overage_weeks_row').hide();
            }
        });

        var currentOverages = [];

        // Preview overages.
        $('#preview_overages_btn').on('click', function() {
            var clientType = $('#overage_client_type').val();
            var startDate  = $('#overage_start_date').val();
            var endDate    = $('#overage_end_date').val();

            if (!clientType) {
                showOveragesMessage('error', 'Please select a client type.');
                return;
            }
            if (!startDate || !endDate) {
                showOveragesMessage('error', 'Please select start and end dates.');
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Loading...');

            $.post(mealsdbInvoice.ajaxUrl, {
                action: 'mealsdb_preview_overages',
                nonce: mealsdbInvoice.nonce,
                client_type: clientType,
                start_date: startDate,
                end_date: endDate,
                zone: $('#overage_zone').val() || '',
                weeks_in_month: $('#overage_weeks_in_month').val() || '4'
            }, function(resp) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span> Preview Overages');

                if (resp.success) {
                    currentOverages = resp.data.overages;
                    $('#overages_count').text(resp.data.count);

                    var $tbody = $('#overages_table tbody');
                    $tbody.empty();

                    if (resp.data.count === 0) {
                        $tbody.append('<tr><td colspan="4">No clients with overages found.</td></tr>');
                        $('#create_overage_orders_btn').hide();
                    } else {
                        $.each(resp.data.overages, function(i, row) {
                            var name = row.name || ((row.last_name || '') + ', ' + (row.first_name || ''));
                            $tbody.append(
                                '<tr>' +
                                '<td>' + $('<span>').text(name).html() + '</td>' +
                                '<td>' + row.bnm_mains + '</td>' +
                                '<td>' + row.overage_tax_sides + '</td>' +
                                '<td>' + row.overage_nontax_sides + '</td>' +
                                '</tr>'
                            );
                        });
                        $('#create_overage_orders_btn').show();
                    }

                    $('#overages_preview').show();
                } else {
                    showOveragesMessage('error', resp.data.message || 'Failed to load overages.');
                }
            }).fail(function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span> Preview Overages');
                showOveragesMessage('error', 'Request failed.');
            });
        });

        // Create overage orders.
        $('#create_overage_orders_btn').on('click', function() {
            if (currentOverages.length === 0) {
                showOveragesMessage('error', 'No overages to process.');
                return;
            }

            if (!confirm('Create overage orders for ' + currentOverages.length + ' client(s)?')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).text('Creating orders...');

            $.post(mealsdbInvoice.ajaxUrl, {
                action: 'mealsdb_create_overage_orders',
                nonce: mealsdbInvoice.nonce,
                invoice_date: $('#overage_invoice_date').val() || '',
                overages: JSON.stringify(currentOverages)
            }, function(resp) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-cart" style="vertical-align: middle;"></span> Create Overage Orders');

                if (resp.success) {
                    var msg = resp.data.created + ' order(s) created.';
                    if (resp.data.skipped_count > 0) {
                        msg += ' ' + resp.data.skipped_count + ' skipped: ' + resp.data.skipped.join(', ');
                    }
                    showOveragesMessage('success', msg);
                } else {
                    showOveragesMessage('error', resp.data.message || 'Failed to create orders.');
                }
            }).fail(function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-cart" style="vertical-align: middle;"></span> Create Overage Orders');
                showOveragesMessage('error', 'Request failed.');
            });
        });

        function showOveragesMessage(type, message) {
            // Callers include paths that concatenate server-supplied
            // error text (resp.data.message) and user-controlled client
            // names (resp.data.skipped.join(', ')) into the message.
            // Render through jQuery's text setter so none of that can
            // execute as HTML.
            var $msg = $('#overages_message');
            $msg.removeClass('notice-success notice-error notice-warning')
                .addClass('notice notice-' + type)
                .empty()
                .append($('<p>').text(message == null ? '' : String(message)))
                .slideDown();

            setTimeout(function() { $msg.slideUp(); }, 8000);
        }
    });

})(jQuery);

// CSS for rotation animation
if (typeof document !== 'undefined') {
    var style = document.createElement('style');
    style.textContent = '@keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(359deg); } }';
    document.head.appendChild(style);
}
