/**
 * Invoice Draft review/edit UI (directive INV-DRAFT-2).
 *
 * Drives three server endpoints — generate, per-field edit, finalize — over
 * the grid rendered by MealsDB_Invoice_Draft_Page. All state is server-side
 * (the draft table); this is a thin view over it. No localStorage /
 * sessionStorage (per the artifact-storage rule, and because the draft
 * persists server-side anyway).
 *
 * @package MealsDB
 */
(function ($) {
    'use strict';

    var cfg = window.mealsdbInvoiceDraft || {};
    var i18n = cfg.i18n || {};

    function post(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = cfg.nonce;
        return $.post(cfg.ajaxUrl, data);
    }

    $(document).ready(function () {

        // --- Generate: show/hide the zone field for SDNB legacy ---
        $('#gen_pipeline').on('change', function () {
            if ($(this).val() === 'sdnb_legacy') {
                $('.mealsdb-gen-zone').show();
            } else {
                $('.mealsdb-gen-zone').hide();
            }
        }).trigger('change');

        // --- Generate: create a NEW draft, then jump to its review view ---
        $('#mealsdb-draft-generate-btn').on('click', function () {
            var $btn = $(this);
            var $msg = $('#mealsdb-draft-generate-msg');
            $btn.prop('disabled', true);
            $msg.text(i18n.saving || 'Working…');

            post('mealsdb_generate_draft', {
                pipeline: $('#gen_pipeline').val(),
                zone: $('#gen_zone').val(),
                period_start: $('#gen_start').val(),
                period_end: $('#gen_end').val()
            }).done(function (resp) {
                if (resp && resp.success && resp.data && resp.data.draft_id) {
                    window.location.href = cfg.pageUrl + '&draft_id=' + encodeURIComponent(resp.data.draft_id);
                } else {
                    $msg.text((resp && resp.data && resp.data.message) || i18n.genericErr);
                    $btn.prop('disabled', false);
                }
            }).fail(function () {
                $msg.text(i18n.genericErr);
                $btn.prop('disabled', false);
            });
        });

        // --- Edit: save a cell on change; revert + report on error ---
        $('#mealsdb-draft-grid').on('change', '.mealsdb-draft-cell', function () {
            var $cell = $(this);
            if ($cell.data('busy')) {
                return; // debounce double-submits per cell
            }
            var prior = $cell.data('prior');
            if (prior === undefined) {
                prior = '';
            }
            var newVal = $cell.val();
            if (newVal === prior) {
                return; // no change since last committed value
            }

            $cell.data('busy', true).prop('disabled', true);

            post('mealsdb_edit_draft_field', {
                draft_id: $('#mealsdb-draft-grid').data('draft-id'),
                client_id: $cell.data('client-id'),
                field: $cell.data('field'),
                new_value: newVal
            }).done(function (resp) {
                if (resp && resp.success && resp.data) {
                    var stored = (resp.data.value === null || resp.data.value === undefined)
                        ? '' : String(resp.data.value);
                    $cell.val(stored).data('prior', stored);
                    if (resp.data.changed) {
                        bumpEditCount();
                        // Drop any stale "was:" hint — the cell now reflects an edit.
                        $cell.siblings('.mealsdb-draft-was').remove();
                    }
                } else {
                    revert($cell, (resp && resp.data && resp.data.message) || i18n.genericErr);
                }
            }).fail(function (xhr) {
                var message = i18n.genericErr;
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }
                revert($cell, message);
            }).always(function () {
                $cell.data('busy', false).prop('disabled', false);
            });
        });

        // Seed each cell's "prior committed value" for revert + no-op detection.
        $('.mealsdb-draft-cell').each(function () {
            $(this).data('prior', $(this).val());
        });

        // --- Finalize (list + review) ---
        $(document).on('click', '.mealsdb-draft-finalize', function (e) {
            e.preventDefault();
            if (!window.confirm(i18n.confirmFin || 'Finalize this draft?')) {
                return;
            }
            var $el = $(this);
            $el.prop('disabled', true);

            post('mealsdb_finalize_draft', {
                draft_id: $el.data('draft-id')
            }).done(function (resp) {
                if (resp && resp.success) {
                    // Reload into the now read-only view / refreshed list.
                    window.location.reload();
                } else {
                    MealsDBNotice('error', (resp && resp.data && resp.data.message) || i18n.genericErr);
                    $el.prop('disabled', false);
                }
            }).fail(function () {
                MealsDBNotice('error', i18n.genericErr);
                $el.prop('disabled', false);
            });
        });
    });

    function revert($cell, message) {
        var prior = $cell.data('prior');
        $cell.val(prior === undefined ? '' : prior);
        // Render the validation/error message right AT the edited cell (directive
        // GUI-NOTICES) so per-field errors — e.g. the "Value must be a non-negative
        // number" case the test agent couldn't read out of a native alert — appear
        // where Janet is editing, not only at the top of the page. Reuse a single
        // adjacent holder per cell so repeated bad edits don't stack notices.
        var $holder = $cell.nextAll('.mealsdb-cell-notice').first();
        if (!$holder.length) {
            $holder = $('<div>', { 'class': 'mealsdb-cell-notice' }).insertAfter($cell);
        }
        MealsDBNotice('error', message, { $target: $holder });
    }

    function bumpEditCount() {
        var $c = $('#mealsdb-draft-edit-count');
        if ($c.length) {
            $c.text((parseInt($c.text(), 10) || 0) + 1);
        }
    }

})(jQuery);
