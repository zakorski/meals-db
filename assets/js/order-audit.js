/**
 * Weekly Order Audit review UI (Task 7).
 *
 * Drives the MealsDB_Ajax_Order_Audit endpoints (create / confirm / edit /
 * revert / finalize / unfinalize / delete) over the grid rendered by
 * MealsDB_Order_Audit_Page. All state is server-side (the audit snapshot); this
 * is a thin view over it.
 *
 * No innerHTML with server strings: row status / labels come from small fixed
 * strings set via .text(); the note tooltip is the operator's own just-typed
 * value set via .attr('title', ...). Money/mains/sides are never recomputed
 * here — the snapshot counts are authoritative and a Δ marker (not a new total)
 * signals an edited row (see the page class for why).
 *
 * @package MealsDB
 */
(function ($) {
    'use strict';

    var cfg = window.mealsdbOrderAudit || {};
    var i18n = cfg.i18n || {};

    // POST {action, nonce, ...data}. On resp.success -> onSuccess(resp.data);
    // otherwise alert the server message (or a generic fallback).
    function post(action, data, onSuccess) {
        data = data || {};
        data.action = action;
        data.nonce = cfg.nonce;
        $.post(cfg.ajaxUrl, data).done(function (resp) {
            if (resp && resp.success) {
                onSuccess(resp.data || {});
            } else {
                window.alert((resp && resp.data && resp.data.message) || i18n.errorGeneric);
            }
        }).fail(function () {
            window.alert(i18n.errorGeneric);
        });
    }

    function auditId() {
        return $('#oa-grid').data('audit-id');
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    // Product catalogue for Add-Item, fetched once and cached. Each entry is
    // {product_id, name, sku}. On failure the Add-Item control alerts and no-ops.
    var _catalogue = null;
    function withCatalogue(cb) {
        if (_catalogue) { cb(_catalogue); return; }
        post('mealsdb_order_audit_products', {}, function (d) {
            _catalogue = (d && d.products) || [];
            cb(_catalogue);
        });
    }

    // "{resolved} of {row_count} orders resolved" — mirror the server format and
    // toggle the finalize button. resolved = confirmed + edited.
    function updateProgress(d) {
        var rowCount = parseInt(d.row_count, 10) || 0;
        var resolved = (parseInt(d.confirmed_count, 10) || 0) + (parseInt(d.edited_count, 10) || 0);
        var of = i18n.ofResolved || 'of';
        $('#oa-progress')
            .attr('data-row-count', rowCount)
            .text(resolved + ' ' + of + ' ' + rowCount + ' orders resolved');
        $('#oa-finalize').prop('disabled', !(rowCount > 0 && resolved === rowCount));
    }

    $(document).ready(function () {

        // --- Create a new audit draft, then jump to its detail view ---
        $('#oa-create').on('click', function () {
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#oa-create-msg').text('');
            post('mealsdb_order_audit_create', { week_start: $('#oa-week-start').val() }, function (d) {
                window.location.href = cfg.detailUrlBase + encodeURIComponent(d.audit_id);
            });
            // Re-enable if post() alerted an error (it does not reload on failure).
            setTimeout(function () { $btn.prop('disabled', false); }, 1500);
        });

        // --- Delete a draft audit (list page) ---
        $(document).on('click', '.oa-delete', function (e) {
            e.preventDefault();
            if (!window.confirm(i18n.confirmDelete)) {
                return;
            }
            post('mealsdb_order_audit_delete', { audit_id: $(this).data('audit-id') }, function () {
                window.location.reload();
            });
        });

        // --- Confirm <-> pending toggle ---
        $('#oa-grid').on('click', '.oa-confirm', function () {
            var $btn = $(this);
            var $row = $btn.closest('.oa-row');
            var orderId = $row.data('order-id');
            post('mealsdb_order_audit_confirm', { audit_id: auditId(), order_id: orderId }, function (d) {
                applyRowStatus($row, d.status);
                // Confirming clears any prior edit, so drop the Δ marker + editor.
                if (d.status !== 'edited') {
                    $row.find('.oa-delta').hide();
                }
                editorRow(orderId).hide();
                updateProgress(d);
            });
        });

        // --- Toggle the per-order editor row ---
        $('#oa-grid').on('click', '.oa-edit', function () {
            var orderId = $(this).closest('.oa-row').data('order-id');
            editorRow(orderId).toggle();
        });

        // --- Save an edit: collect per-item qtys + note ---
        $('#oa-grid').on('click', '.oa-editor-save', function () {
            var $editor = $(this).closest('.oa-editor-row');
            var orderId = $editor.data('order-id');
            var qtys = {};
            $editor.find('.oa-qty').each(function () {
                var key = $(this).data('item-key');
                var raw = parseInt($(this).val(), 10);
                qtys[key] = isNaN(raw) ? 0 : raw;
            });
            var added = [];
            $editor.find('.oa-added-line').each(function () {
                var pid = parseInt($(this).attr('data-product-id'), 10) || 0;
                if (pid <= 0) { return; } // an un-picked new line is skipped
                var q = parseInt($(this).find('.oa-added-qty').val(), 10);
                added.push({ product_id: pid, qty: isNaN(q) ? 1 : q });
            });
            var note = $editor.find('.oa-note').val() || '';
            post('mealsdb_order_audit_edit', {
                audit_id: auditId(),
                order_id: orderId,
                qtys: qtys,
                note: note,
                added: added
            }, function (d) {
                var $row = auditRow(orderId);
                applyRowStatus($row, 'edited');
                $row.find('.oa-delta').show();
                applyNoteIcon($row, note);
                $editor.hide();
                updateProgress(d);
            });
        });

        // --- Revert a row to pristine pending ---
        // The server is authoritative and this is a rare action; a full reload
        // is the simplest way to reset the row + its editor inputs to the
        // snapshot without re-plumbing every field client-side.
        $('#oa-grid').on('click', '.oa-editor-revert', function () {
            var orderId = $(this).closest('.oa-editor-row').data('order-id');
            post('mealsdb_order_audit_revert', { audit_id: auditId(), order_id: orderId }, function () {
                window.location.reload();
            });
        });

        // --- Cancel: just hide the editor row (no server call) ---
        $('#oa-grid').on('click', '.oa-editor-cancel', function () {
            $(this).closest('.oa-editor-row').hide();
        });

        // --- Add Item: append a product picker + qty + remove to the editor ---
        $('#oa-grid').on('click', '.oa-editor-add-item', function () {
            var $added = $(this).closest('.oa-editor-row').find('.oa-editor-added');
            withCatalogue(function (products) {
                var $line = $('<div class="oa-added-line" data-product-id="0" style="margin:3px 0;"></div>');
                var $sel = $('<select class="oa-added-select"></select>');
                $sel.append('<option value="0">' + esc(i18n.selectProduct || 'Select a product…') + '</option>');
                products.forEach(function (p) {
                    var label = p.name + (p.sku ? ' (' + p.sku + ')' : '');
                    $('<option></option>').attr('value', p.product_id).attr('data-sku', p.sku || '')
                        .text(label).appendTo($sel);
                });
                $sel.on('change', function () {
                    $line.attr('data-product-id', String(parseInt($(this).val(), 10) || 0));
                });
                var $qty = $('<input type="number" min="1" class="oa-added-qty" value="1" style="width:70px;" />');
                var $rm = $('<button type="button" class="button-link oa-added-remove">&times;</button>');
                $line.append('<span class="oa-added-label" style="color:#8a6d00;">'
                    + esc(i18n.addedLabel || 'added — not on original order') + '</span> ');
                $line.append($sel).append(' ').append($qty).append(' ').append($rm);
                $added.append($line);
            });
        });

        // --- Remove an added line (unsaved or saved; persistence is on Save) ---
        $('#oa-grid').on('click', '.oa-added-remove', function () {
            $(this).closest('.oa-added-line').remove();
        });

        // --- Finalize the whole audit ---
        $('#oa-finalize').on('click', function () {
            if (!window.confirm(i18n.confirmFinalize)) {
                return;
            }
            post('mealsdb_order_audit_finalize', { audit_id: auditId() }, function () {
                window.location.reload();
            });
        });

        // --- Unfinalize (reason required) ---
        $('#oa-unfinalize').on('click', function () {
            var reason = window.prompt(i18n.promptUnfinalize, '');
            if (reason === null || !reason.trim()) {
                return;
            }
            post('mealsdb_order_audit_unfinalize', { audit_id: auditId(), reason: reason }, function () {
                window.location.reload();
            });
        });
    });

    // --- Small DOM helpers ---

    function auditRow(orderId) {
        return $('#oa-grid .oa-row[data-order-id="' + orderId + '"]');
    }

    function editorRow(orderId) {
        return $('#oa-grid .oa-editor-row[data-order-id="' + orderId + '"]');
    }

    // Update a row's status cell text + the confirm button's pressed state/label
    // from a server-returned status ('confirmed' | 'pending' | 'edited').
    // Labels come from the wp_localize_script i18n object so translators reach
    // them; the JS never holds hardcoded English strings for these.
    function applyRowStatus($row, status) {
        var statusLabel;
        if (status === 'confirmed') {
            statusLabel = i18n.statusConfirmed || 'Confirmed';
        } else if (status === 'edited') {
            statusLabel = i18n.statusEdited || 'Edited';
        } else {
            statusLabel = i18n.statusPending || 'Pending';
            status = 'pending';
        }
        $row.find('.oa-status').text(statusLabel);

        var $confirm = $row.find('.oa-confirm');
        var isConfirmed = (status === 'confirmed');
        $confirm.attr('aria-pressed', isConfirmed ? 'true' : 'false')
            .text(isConfirmed ? (i18n.btnConfirmed || '✓ Confirmed') : (i18n.btnConfirm || '✓ Confirm'));
    }

    // Show/hide the note pencil icon in a row from the just-typed note value.
    function applyNoteIcon($row, note) {
        var $cell = $row.find('.oa-note-cell');
        var $icon = $cell.find('.dashicons-edit-page');
        if (note && note.length) {
            if (!$icon.length) {
                $icon = $('<span class="dashicons dashicons-edit-page"></span>').appendTo($cell);
            }
            $icon.attr('title', note);
        } else if ($icon.length) {
            $icon.remove();
        }
    }

})(jQuery);
