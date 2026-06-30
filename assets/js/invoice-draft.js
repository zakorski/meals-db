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

    // SDNB-legacy per-line derived cell fields (display order matches the
    // server's line columns; the line-0 Rate is an editable input, not a
    // derived cell, so it's deliberately updated only where it exists).
    var SDNB_LINE_FIELDS = ['units', 'rate', 'basic_cost_cents', 'tax_cents', 'line_total_cents'];

    // Build a secondary (index >= 1) SDNB line row: blank header continuation
    // cells + read-only line-detail cells. Line-0 is always server-rendered and
    // never built here, so its editable header inputs are never disturbed.
    function buildSdnbLineRow(cid, idx, headerCols) {
        var html = '<tr class="mealsdb-sdnb-row" data-client-id="' + cid + '" data-line-index="' + idx + '">';
        for (var i = 0; i < headerCols; i++) {
            html += '<td class="mealsdb-sdnb-cont"></td>';
        }
        html += '<td class="mealsdb-col-line-label"><span></span></td>';
        SDNB_LINE_FIELDS.forEach(function (f) {
            html += '<td class="mealsdb-derived" data-client-id="' + cid + '" data-line-index="' + idx +
                '" data-derived-field="' + f + '"><span></span></td>';
        });
        html += '</tr>';
        return $(html);
    }

    // Re-render one SDNB client's invoice-line rows from the server's per-line
    // derived payload. The line count can change (crossing the mains == sides
    // boundary), so we update existing line rows, append new ones, and drop
    // surplus ones — never touching the line-0 header row's editable inputs.
    // Money is NEVER computed here; we only place the strings the server sent.
    function rerenderSdnbLines($grid, cid, lines) {
        var sel = 'tr[data-client-id="' + cid + '"]';
        var headerCols = $grid.find('thead th').length - 6; // 6 per-line columns

        lines.forEach(function (line, idx) {
            var $row = $grid.find(sel + '[data-line-index="' + idx + '"]').first();
            if (!$row.length) {
                $row = buildSdnbLineRow(cid, idx, headerCols);
                $grid.find(sel).last().after($row);
            }
            $row.find('.mealsdb-col-line-label span').text(line.line_number || (idx + 1));
            SDNB_LINE_FIELDS.forEach(function (f) {
                var $c = $row.find('[data-derived-field="' + f + '"] span');
                if ($c.length) { $c.text(line[f]); }
            });
            var $d = $row.find('.mealsdb-derived').addClass('mealsdb-recomputed');
            setTimeout(function () { $d.removeClass('mealsdb-recomputed'); }, 600);
        });

        // Drop surplus rows, but always keep line-0 so the editable header
        // inputs survive even if the client momentarily has zero lines.
        var keep = Math.max(1, lines.length);
        $grid.find(sel).each(function () {
            if (parseInt($(this).attr('data-line-index'), 10) >= keep) { $(this).remove(); }
        });
        if (!lines.length) {
            var $row0 = $grid.find(sel + '[data-line-index="0"]').first();
            $row0.find('.mealsdb-col-line-label span').text('');
            SDNB_LINE_FIELDS.forEach(function (f) {
                $row0.find('[data-derived-field="' + f + '"] span').text('');
            });
        }
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
            var $editRow = $cell.closest('tr').addClass('mealsdb-row-saving');

            post('mealsdb_edit_draft_field', {
                draft_id: $('#mealsdb-draft-grid').data('draft-id'),
                client_id: $cell.data('client-id'),
                field: $cell.data('field'),
                new_value: newVal,
                // *_cents fields edited as dollars (SDNB contribution) flag the
                // server to convert; the value comes back as value_display.
                edit_as: $cell.data('edit-as') || ''
            }).done(function (resp) {
                if (resp && resp.success && resp.data) {
                    // Prefer value_display (the input's display form, e.g. a
                    // dollars-edited cents field) over the raw stored value.
                    var stored;
                    if (resp.data.value_display !== undefined && resp.data.value_display !== null) {
                        stored = String(resp.data.value_display);
                    } else {
                        stored = (resp.data.value === null || resp.data.value === undefined)
                            ? '' : String(resp.data.value);
                    }
                    $cell.val(stored).data('prior', stored);
                    if (resp.data.changed) {
                        bumpEditCount();
                        // Drop any stale "was:" hint — the cell now reflects an edit.
                        $cell.siblings('.mealsdb-draft-was').remove();
                    }
                    // Refresh the read-only derived cells from the SERVER
                    // recompute. Money is NEVER computed in JS — we only display
                    // the formatted strings the endpoint returned.
                    var derived = resp.data.derived;
                    if (derived && derived.lines) {
                        // SDNB-legacy: re-render this client's 1–2 line rows
                        // (the count can change). Targets by client id, not row.
                        rerenderSdnbLines($('#mealsdb-draft-grid'), String($cell.data('client-id')), derived.lines);
                    } else if (derived && typeof derived === 'object') {
                        // VAC (flat map): refresh the derived cells in this row.
                        Object.keys(derived).forEach(function (field) {
                            var $dc = $editRow.find('[data-derived-field="' + field + '"]');
                            if (!$dc.length) {
                                return;
                            }
                            var $target = $dc.find('span').length ? $dc.find('span') : $dc;
                            $target.text(derived[field]);
                            $dc.addClass('mealsdb-recomputed');
                            setTimeout(function () { $dc.removeClass('mealsdb-recomputed'); }, 600);
                        });
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
                $editRow.removeClass('mealsdb-row-saving');
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

        // --- Un-finalize (list + review), directive INV-2 ---
        // Reverses the one-way finalize lock. A REASON is required (the server
        // re-enforces non-empty); captured via window.prompt() for v1 per the
        // directive (upgradeable to a modal later — the integrity logic is
        // server-side). On success we reload into the now-editable view.
        $(document).on('click', '.mealsdb-draft-unfinalize', function (e) {
            e.preventDefault();
            if (!window.confirm(i18n.confirmUnfin || 'Un-finalize this invoice? It will become editable again.')) {
                return;
            }
            var reason = window.prompt(i18n.reasonPrompt || 'Enter a reason for un-finalizing (required):', '');
            // Cancelled prompt → abort silently. Empty/whitespace → block here too
            // (the server also rejects it, but fail fast for the operator).
            if (reason === null) {
                return;
            }
            if (!reason || !reason.trim()) {
                MealsDBNotice('error', i18n.reasonRequired || 'A reason is required to un-finalize.');
                return;
            }
            var $el = $(this);
            $el.prop('disabled', true);
            sendUnfinalize($el, reason, 0);
        });
    });

    // Post the un-finalize, handling the shared-lock confirmation round-trip
    // (PR #418). When other finalized invoices share this draft's client-month,
    // the server answers with requires_confirmation + a message naming them; we
    // confirm and re-post with cascade=1 to un-finalize them together.
    function sendUnfinalize($el, reason, cascade) {
        post('mealsdb_unfinalize_draft', {
            draft_id: $el.data('draft-id'),
            reason: reason,
            cascade: cascade
        }).done(function (resp) {
            if (resp && resp.success && resp.data && resp.data.requires_confirmation) {
                if (window.confirm(resp.data.message || i18n.confirmUnfin)) {
                    sendUnfinalize($el, reason, 1);
                } else {
                    $el.prop('disabled', false);
                }
                return;
            }
            if (resp && resp.success) {
                // Reload into the now editable view / refreshed list.
                window.location.reload();
            } else {
                MealsDBNotice('error', (resp && resp.data && resp.data.message) || i18n.genericErr);
                $el.prop('disabled', false);
            }
        }).fail(function () {
            MealsDBNotice('error', i18n.genericErr);
            $el.prop('disabled', false);
        });
    }

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
