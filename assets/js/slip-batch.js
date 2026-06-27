/**
 * Packing Slips batch UI (directive 06).
 *
 * Drives the Midland two-phase workflow over the history table rendered by
 * MealsDB_Slip_Batch_Page: generate a batch, upload the returned doc 3 scan,
 * combine (merge), and cancel. All state is server-side (the meals_slip_batches
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

        // --- Upload Doc 3: the visible button proxies to a hidden file input ---
        $('#mealsdb-slip-table').on('click', '.mealsdb-slip-upload-btn', function () {
            $(this).closest('.mealsdb-slip-upload').find('.mealsdb-slip-doc3-file').trigger('click');
        });

        $('#mealsdb-slip-table').on('change', '.mealsdb-slip-doc3-file', function () {
            var input = this;
            if (!input.files || !input.files.length) {
                return;
            }
            var $row = $(input).closest('tr');
            var batchId = $row.data('batch-id');
            var fd = new FormData();
            fd.append('action', 'mealsdb_slip_upload_doc3');
            fd.append('nonce', cfg.nonce);
            fd.append('batch_id', batchId);
            fd.append('doc3', input.files[0]);

            rowMsg($row, i18n.uploading || 'Uploading…');

            $.ajax({
                url: cfg.ajaxUrl,
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false
            }).done(function (resp) {
                if (resp && resp.success && resp.data && resp.data.valid) {
                    rowMsg($row, '');
                    // A valid scan is now stored: enable Combine, mark status.
                    $row.find('.mealsdb-slip-combine-btn').prop('disabled', false);
                    $row.find('.mealsdb-slip-status').text('doc3_uploaded');
                    notice('success', 'Doc 3 uploaded and validated.');
                } else {
                    // Invalid (e.g. page-count mismatch): keep Combine disabled.
                    var msg = (resp && resp.data && resp.data.message) || i18n.genericErr;
                    rowMsg($row, msg);
                    $row.find('.mealsdb-slip-combine-btn').prop('disabled', true);
                    notice('error', msg);
                }
            }).fail(function (xhr) {
                var msg = i18n.genericErr;
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                }
                rowMsg($row, msg);
                notice('error', msg);
            }).always(function () {
                // Reset the input so re-selecting the SAME file re-fires change.
                input.value = '';
            });
        });

        // --- Combine -> merged PDF ---
        $('#mealsdb-slip-table').on('click', '.mealsdb-slip-combine-btn', function () {
            var $btn = $(this);
            if ($btn.prop('disabled')) {
                return;
            }
            var $row = $btn.closest('tr');
            $btn.prop('disabled', true);
            rowMsg($row, i18n.combining || 'Combining…');

            post('mealsdb_slip_combine', { batch_id: $row.data('batch-id') })
                .done(function (resp) {
                    if (resp && resp.success) {
                        rowMsg($row, '');
                        $row.find('.mealsdb-slip-status').text('combined');
                        // Reveal / refresh the merged download link.
                        var $link = $row.find('.mealsdb-slip-merged-link');
                        if (resp.data && resp.data.download) {
                            $link.attr('href', resp.data.download);
                        }
                        $link.show();
                        $btn.prop('disabled', false);
                        notice('success', 'Merged document ready.');
                    } else {
                        var msg = (resp && resp.data && resp.data.message) || i18n.genericErr;
                        rowMsg($row, msg);
                        $btn.prop('disabled', false);
                        notice('error', msg);
                    }
                })
                .fail(function () {
                    rowMsg($row, i18n.genericErr);
                    $btn.prop('disabled', false);
                    notice('error', i18n.genericErr);
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
