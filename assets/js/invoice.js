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

    });

})(jQuery);

// CSS for rotation animation
if (typeof document !== 'undefined') {
    var style = document.createElement('style');
    style.textContent = '@keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(359deg); } }';
    document.head.appendChild(style);
}
