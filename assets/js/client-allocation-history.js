/**
 * Client allocation history widget.
 *
 * Renders the per-client allocation history table on the edit-client view and
 * lazily fetches per-month delivery details via the
 * `mealsdb_get_client_allocation_history` AJAX endpoint.
 *
 * Configured via window.mealsdbAllocationHistory:
 *   - clientId  (int)    The client whose history we render.
 *   - ajaxUrl   (string) Endpoint URL.
 *   - nonce     (string) `mealsdb_nonce` nonce value.
 *   - i18n      (object) Translated strings used by the widget.
 *
 * The script returns early when the config is missing so it remains safe to
 * load on pages where the widget is not present (e.g. via global enqueue).
 */
(function ($) {
    'use strict';

    var config = window.mealsdbAllocationHistory;
    if (!config || !config.clientId || parseInt(config.clientId, 10) <= 0) {
        return;
    }

    var clientId = parseInt(config.clientId, 10);
    var ajaxUrl = typeof config.ajaxUrl === 'string' && config.ajaxUrl
        ? config.ajaxUrl
        : (typeof window.ajaxurl === 'string' ? window.ajaxurl : '');
    var i18n = config.i18n || {};

    // Defense-in-depth HTML escape for any value flowing from the
    // server JSON response into HTML strings below. Even though
    // most fields are integers / DB-controlled month strings,
    // future schema additions could let user-controlled text in.
    // Prefer the shared MealsDBReport.esc (STR-2 consolidation); fall back to the
    // local entity-replace so escaping is never disabled. Text context only.
    function escHtml(value) {
        if (value === null || value === undefined) return '';
        if (window.MealsDBReport && typeof window.MealsDBReport.esc === 'function') {
            return window.MealsDBReport.esc(value);
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function intText(value) {
        return String(parseInt(value, 10) || 0);
    }

    $(function () {
        var nonce = config.nonce || '';
        if (!nonce) {
            if (typeof window.mealsdb !== 'undefined' && window.mealsdb.nonce) {
                nonce = window.mealsdb.nonce;
            } else {
                var $nonceField = $('#mealsdb_nonce_field');
                if ($nonceField.length) {
                    nonce = $nonceField.val();
                }
            }
        }

        $.ajax({
            url: ajaxUrl,
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'mealsdb_get_client_allocation_history',
                nonce: nonce,
                client_id: clientId
            }
        }).done(function (response) {
            if (!response || !response.success || !response.history) {
                $('#mealsdb-allocation-history-table tbody').html(
                    '<tr><td colspan="8">' + escHtml(i18n.noHistory || '') + '</td></tr>'
                );
                return;
            }

            var rows = '';
            $.each(response.history, function (i, row) {
                var status = row.is_finalized == 1 ? (i18n.statusFinalized || '') : (i18n.statusOpen || '');
                var month = escHtml(row.billing_month || '');
                rows += '<tr class="mealsdb-allocation-history-row" data-month="' + month + '" style="cursor: pointer;">' +
                    '<td>' + month + '</td>' +
                    '<td>' + intText(row.permitted_mains) + '</td>' +
                    '<td>' + intText(row.used_mains) + '</td>' +
                    '<td>' + intText(row.overage_mains) + '</td>' +
                    '<td>' + intText(row.permitted_sides) + '</td>' +
                    '<td>' + intText(row.used_sides) + '</td>' +
                    '<td>' + intText(row.overage_sides) + '</td>' +
                    '<td>' + escHtml(status) + '</td>' +
                '</tr>' +
                '<tr class="mealsdb-allocation-detail-row" data-month="' + month + '" style="display: none;">' +
                    '<td colspan="8"><em>' + escHtml(i18n.loadingDetails || '') + '</em></td>' +
                '</tr>';
            });

            if (!rows) {
                rows = '<tr><td colspan="8">' + escHtml(i18n.noHistory || '') + '</td></tr>';
            }

            $('#mealsdb-allocation-history-table tbody').html(rows);

            if (response.current_month_details && response.current_month_details.length) {
                var currentMonth = new Date().toISOString().substring(0, 7);
                var $detailRow = $('.mealsdb-allocation-detail-row[data-month="' + currentMonth + '"]');
                if ($detailRow.length) {
                    $detailRow.find('td').html(buildDetailTable(response.current_month_details));
                    $detailRow.data('loaded', true);
                }
            }
        }).fail(function () {
            $('#mealsdb-allocation-history-table tbody').html(
                '<tr><td colspan="8">' + escHtml(i18n.loadFailed || '') + '</td></tr>'
            );
        });

        $(document).on('click', '.mealsdb-allocation-history-row', function () {
            var month = $(this).data('month');
            var $detail = $('.mealsdb-allocation-detail-row[data-month="' + month + '"]');
            if ($detail.is(':visible')) {
                $detail.hide();
                return;
            }
            $detail.show();
            if ($detail.data('loaded')) return;

            $.ajax({
                url: ajaxUrl,
                method: 'GET',
                dataType: 'json',
                data: {
                    action: 'mealsdb_get_client_allocation_history',
                    nonce: nonce,
                    client_id: clientId,
                    billing_month: month
                }
            }).done(function (resp) {
                if (resp && resp.success && resp.month_details) {
                    $detail.find('td').html(buildDetailTable(resp.month_details));
                } else {
                    $detail.find('td').html('<em>' + escHtml(i18n.noDeliveryDetails || '') + '</em>');
                }
                $detail.data('loaded', true);
            });
        });

        function buildDetailTable(details) {
            var html = '<table class="widefat fixed striped" style="margin: 5px 0;">' +
                '<thead><tr>' +
                    '<th>' + escHtml(i18n.colDeliveryDate || '') + '</th>' +
                    '<th>' + escHtml(i18n.colOrderNumber || '') + '</th>' +
                    '<th>' + escHtml(i18n.colMains || '') + '</th>' +
                    '<th>' + escHtml(i18n.colSides || '') + '</th>' +
                '</tr></thead><tbody>';
            $.each(details, function (i, d) {
                html += '<tr>' +
                    '<td>' + escHtml(d.delivery_date || '') + '</td>' +
                    '<td>' + intText(d.wc_order_id) + '</td>' +
                    '<td>' + intText(d.mains_count) + '</td>' +
                    '<td>' + intText(d.sides_count) + '</td>' +
                '</tr>';
            });
            html += '</tbody></table>';
            return html;
        }
    });
})(jQuery);
