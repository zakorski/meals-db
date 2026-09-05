/**
 * Packing Slips batch UI (directive 06).
 *
 * Drives the history table rendered by MealsDB_Slip_Batch_Page: generate a
 * batch and cancel one. All state is server-side (the meals_slip_batches
 * table); this is a thin view over it. Downloads are plain GET links rendered
 * server-side, so this file only handles the mutating actions.
 *
 * jQuery note: uses modern event binding + .trigger('submit') (never the
 * deprecated .submit()), per the JQMIGRATE fix precedent.
 *
 * @package MealsDB
 */
(function ($) {
    'use strict';

    var cfg = window.mealsdbSlipBatch || {};
    var i18n = cfg.i18n || {};

    function notice(type, message, opts) {
        if (typeof window.MealsDBNotice === 'function') {
            window.MealsDBNotice(type, message, opts || {});
        } else if (type === 'error') {
            window.alert(message);
        }
    }

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = cfg.nonce;
        return $.post(cfg.ajaxUrl, data);
    }

    function rowMsg($row, text) {
        $row.find('.mealsdb-slip-row-msg').text(text || '');
    }

    $(document).ready(function () {

        // --- Generate a batch -> reload to show the new row ---
        $('#mealsdb-slip-generate-btn').on('click', function () {
            var zone = $('#mealsdb-slip-zone').val();
            var date = $('#mealsdb-slip-date').val();
            var $msg = $('#mealsdb-slip-generate-msg');

            if (!zone || !date) {
                $msg.text(i18n.pickZone || 'Choose a zone and delivery date first.');
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true);
            $msg.text(i18n.working || 'Working…');

            post('mealsdb_slip_generate_batch', { zone: zone, delivery_date: date })
                .done(function (resp) {
                    if (resp && resp.success) {
                        window.location.reload();
                    } else {
                        $msg.text((resp && resp.data && resp.data.message) || i18n.genericErr);
                        $btn.prop('disabled', false);
                    }
                })
                .fail(function () {
                    $msg.text(i18n.genericErr);
                    $btn.prop('disabled', false);
                });
        });

        // --- Directive 4: Generate Weekend Orders ---
        // Creates the weekend follow-up batch for this original batch. On success
        // the page reloads to show the three button pairs. When no weekend orders
        // qualify, the button STAYS enabled and shows an in-place message (no
        // empty PDF, no batch row created).
        $('#mealsdb-slip-table').on('click', '.mealsdb-slip-weekend-btn', function () {
            var $btn = $(this);
            var $row = $btn.closest('tr');
            $btn.prop('disabled', true);
            rowMsg($row, i18n.working || 'Working…');

            post('mealsdb_slip_generate_weekend', { batch_id: $row.data('batch-id') })
                .done(function (resp) {
                    if (resp && resp.success) {
                        if (resp.data && resp.data.no_weekend) {
                            rowMsg($row, (resp.data && resp.data.message) || 'No weekend orders for this week');
                            $btn.prop('disabled', false);
                            return;
                        }
                        window.location.reload();
                    } else {
                        rowMsg($row, (resp && resp.data && resp.data.message) || i18n.genericErr);
                        $btn.prop('disabled', false);
                    }
                })
                .fail(function () {
                    rowMsg($row, i18n.genericErr);
                    $btn.prop('disabled', false);
                });
        });

        // --- Cancel: confirm popup -> hard delete -> drop the row ---
        $('#mealsdb-slip-table').on('click', '.mealsdb-slip-cancel-btn', function () {
            if (!window.confirm(i18n.confirmCancel || 'Cancel this batch? This cannot be undone.')) {
                return;
            }
            var $btn = $(this);
            var $row = $btn.closest('tr');
            $btn.prop('disabled', true);
            rowMsg($row, i18n.working || 'Working…');

            post('mealsdb_slip_cancel', { batch_id: $row.data('batch-id') })
                .done(function (resp) {
                    if (resp && resp.success) {
                        $row.remove();
                    } else {
                        rowMsg($row, (resp && resp.data && resp.data.message) || i18n.genericErr);
                        $btn.prop('disabled', false);
                        notice('error', (resp && resp.data && resp.data.message) || i18n.genericErr);
                    }
                })
                .fail(function () {
                    rowMsg($row, i18n.genericErr);
                    $btn.prop('disabled', false);
                    notice('error', i18n.genericErr);
                });
        });
    });

})(jQuery);
