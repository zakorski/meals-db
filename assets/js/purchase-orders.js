/**
 * Purchase Orders tab — draft workflow list + detail interactivity.
 *
 * Reads config from the JSON island #mealsdb-po-admin-data. Four concerns:
 *   1. List/detail lifecycle buttons (approve / accept / un-accept /
 *      un-approve / receive / cancel / complete-reconcile) with confirms and
 *      the un-approve / un-accept reason prompt (mirrors invoice un-finalize UX).
 *   2. Draft mode: +/- case steppers, debounced per-row saves, "was:" hints,
 *      live totals.
 *   3. Coverage warnings, recomputed on every click from the row's
 *      generation-time snapshot (data-adjusted-weekly / data-stock /
 *      data-case-size): yellow ! below the 9-week target, red ! below the
 *      7-week floor. Warnings never block saving — they inform.
 *   4. Detail-page CSV export, built from the live grid rows (so stepper
 *      edits are reflected) through Report.csvRow/exportCsv (Pattern 14).
 */
(function ($) {
    'use strict';

    var _el = document.getElementById('mealsdb-po-admin-data');
    if (!_el) { return; }
    var cfg  = JSON.parse(_el.textContent || '{}');
    var i18n = cfg.i18n || {};

    function t(key, fallback) {
        return (i18n[key] != null) ? i18n[key] : fallback;
    }
    function esc(str) {
        var d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }
    function msg(text, isError) {
        $('#mealsdb-po-action-msg')
            .text(text || '')
            .css('color', isError ? '#b32d2e' : '#2271b1');
    }

    // ------------------------------------------------------------------
    // Coverage math — same formula as MealsDB_Purchase_Orders::coverage_weeks.
    // Not money, so client-side math is fine; thresholds come from the island.
    // ------------------------------------------------------------------
    function coverage($row, cases) {
        var weekly = parseFloat($row.data('adjusted-weekly')) || 0;
        if (weekly <= 0) { return null; }
        var units = (parseInt($row.data('stock'), 10) || 0)
                  + cases * (parseInt($row.data('case-size'), 10) || 1);
        return Math.round((units / weekly) * 10) / 10;
    }

    function renderCoverage($row, cases) {
        var $cell = $row.find('.mealsdb-po-coverage');
        var wks = coverage($row, cases);
        if (wks === null) {
            $cell.html('&mdash;').attr('data-coverage', '');
            return;
        }
        var badge = '';
        if (wks < (cfg.floorWeeks || 7)) {
            badge = '<span class="mealsdb-po-flag mealsdb-po-crit" title="'
                + esc(t('belowFloor', 'Below 7-week safety floor (%s wks)').replace('%s', wks.toFixed(1)))
                + '">!</span>';
        } else if (wks < (cfg.targetWeeks || 9)) {
            badge = '<span class="mealsdb-po-flag mealsdb-po-warn" title="'
                + esc(t('belowTarget', 'Below 9-week coverage target (%s wks)').replace('%s', wks.toFixed(1)))
                + '">!</span>';
        }
        $cell.attr('data-coverage', wks).html(esc(wks.toFixed(1)) + ' ' + badge);
    }

    function refreshTotals() {
        var cases = 0, units = 0;
        $('#mealsdb-po-grid tbody tr').each(function () {
            var $row = $(this);
            var c = parseInt($row.find('.mealsdb-po-cases').attr('data-cases'), 10);
            if (isNaN(c)) { c = parseInt($row.data('ordered-cases'), 10) || 0; }
            cases += c;
            units += c * (parseInt($row.data('case-size'), 10) || 1);
        });
        $('#mealsdb-po-total-cases').text(cases);
        $('#mealsdb-po-total-units').text(units);
        var palletSize = parseInt(cfg.palletSize, 10) || 0;
        if (palletSize > 0) {
            $('#mealsdb-po-total-pallets').text('(' + (cases / palletSize).toFixed(2) + ' pal)');
        }
    }

    // ------------------------------------------------------------------
    // Steppers (draft + reconcile modes share the click/debounce plumbing;
    // the posted action differs by mode).
    // ------------------------------------------------------------------
    var mode = cfg.mode || 'list';
    var saveTimers = {}; // sku -> timeout id

    function currentCases($row) {
        return parseInt($row.find('.mealsdb-po-cases').attr('data-cases'), 10) || 0;
    }
    function setCases($row, cases) {
        $row.find('.mealsdb-po-cases').attr('data-cases', cases).text(cases);
        $row.find('.mealsdb-po-orderqty').text(cases * (parseInt($row.data('case-size'), 10) || 1));
        renderCoverage($row, cases);
        refreshTotals();
        if (mode === 'draft') {
            var gen = parseInt($row.data('generated-cases'), 10) || 0;
            var $was = $row.find('.mealsdb-po-was');
            if (cases !== gen) {
                if (!$was.length) {
                    $was = $('<div class="mealsdb-po-was"></div>').appendTo($row.find('.mealsdb-po-ordered'));
                }
                $was.text(t('was', 'was: %s').replace('%s', gen));
            } else {
                $was.remove();
            }
        }
        if (mode === 'reconcile') {
            var ordered = parseInt($row.data('ordered-cases'), 10) || 0;
            $row.find('.mealsdb-po-note').toggle(cases !== ordered);
        }
    }

    function queueSave($row) {
        var sku = String($row.data('sku'));
        if (saveTimers[sku]) { window.clearTimeout(saveTimers[sku]); }
        saveTimers[sku] = window.setTimeout(function () {
            delete saveTimers[sku];
            saveRow($row);
        }, 600);
    }

    function saveRow($row) {
        var sku   = String($row.data('sku'));
        var cases = currentCases($row);
        var data  = { nonce: cfg.nonce, po_id: cfg.poId, sku: sku };
        if (mode === 'draft') {
            data.action = 'mealsdb_po_edit_cases';
            data.cases  = cases;
        } else {
            data.action         = 'mealsdb_po_reconcile_edit';
            data.received_cases = cases;
            data.note           = String($row.find('.mealsdb-po-note').val() || '');
        }
        $row.addClass('mealsdb-po-saving');
        $.post(cfg.ajaxUrl, data, function (res) {
            $row.removeClass('mealsdb-po-saving');
            if (!res || !res.success) {
                msg((res && res.data && res.data.message) || t('requestFailed', 'Request failed.'), true);
                return;
            }
            msg('');
            if (res.data && res.data.changed) {
                var $count = $('#mealsdb-po-edit-count');
                if ($count.length) { $count.text((parseInt($count.text(), 10) || 0) + 1); }
            }
            $row.find('.mealsdb-po-coverage').addClass('mealsdb-po-recomputed');
            window.setTimeout(function () {
                $row.find('.mealsdb-po-coverage').removeClass('mealsdb-po-recomputed');
            }, 600);
        }).fail(function () {
            $row.removeClass('mealsdb-po-saving');
            msg(t('requestFailed', 'Request failed.'), true);
        });
    }

    $(document).on('click', '.mealsdb-po-step', function () {
        var $row  = $(this).closest('tr');
        var cases = Math.max(0, currentCases($row) + parseInt($(this).data('step'), 10));
        setCases($row, cases);
        queueSave($row);
    });

    // A typed reconcile note also needs persisting (the count may be
    // unchanged-but-annotated mid-session; the service stores both).
    $(document).on('change', '.mealsdb-po-note', function () {
        queueSave($(this).closest('tr'));
    });

    // ------------------------------------------------------------------
    // Lifecycle actions
    // ------------------------------------------------------------------
    var ACTION_MAP = {
        approve:   { action: 'mealsdb_po_approve',       confirm: t('confirmApprove', 'Approve this purchase order?') },
        accept:    { action: 'mealsdb_po_mark_accepted', confirm: t('confirmAccept', 'Mark accepted? Quantities will be added to inventory.') },
        receive:   { action: 'mealsdb_po_mark_received', confirm: t('confirmReceive', 'Mark received? Stock was already committed at Accept.') },
        cancel:    { action: 'mealsdb_po_cancel',        confirm: t('confirmCancel', 'Cancel this draft purchase order?') },
        unapprove: { action: 'mealsdb_po_unapprove',     confirm: null },
        unaccept:  { action: 'mealsdb_po_unaccept',      confirm: null }
    };

    $(document).on('click', '.mealsdb-po-action', function () {
        var $btn = $(this);
        var kind = String($btn.data('po-action'));
        var map  = ACTION_MAP[kind];
        if (!map) { return; }

        var data = { nonce: cfg.nonce, po_id: parseInt($btn.data('po-id'), 10), action: map.action };
        if (kind === 'approve') {
            var $arrival = $('#mealsdb-po-expected-arrival');
            if ($arrival.length) {
                // Detail page: date input + the normal confirm dialog.
                if (!window.confirm(map.confirm)) { return; }
                data.expected_arrival = String($arrival.val() || '');
            } else {
                // List page: one prefilled prompt doubles as the confirm —
                // Cancel aborts the approval entirely.
                var dflt = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10);
                var picked = window.prompt(t('promptExpectedArrival', 'Expected arrival date (YYYY-MM-DD) — OK approves:'), dflt);
                if (picked === null) { return; }
                data.expected_arrival = picked;
            }
        } else if (kind === 'unapprove' || kind === 'unaccept') {
            var promptTxt = (kind === 'unaccept')
                ? t('promptUnaccept', 'Enter a reason for un-accepting (required):')
                : t('promptUnapprove', 'Enter a reason for un-approving (required):');
            var reason = window.prompt(promptTxt);
            if (reason === null) { return; }
            if (!reason.replace(/\s/g, '').length) {
                msg(t('reasonRequired', 'A reason is required.'), true);
                return;
            }
            data.reason = reason;
        } else if (!window.confirm(map.confirm)) {
            return;
        }

        $btn.prop('disabled', true);
        msg(t('saving', 'Saving…'), false);
        $.post(cfg.ajaxUrl, data, function (res) {
            if (res && res.success) {
                window.location.reload();
                return;
            }
            $btn.prop('disabled', false);
            msg((res && res.data && res.data.message) || t('requestFailed', 'Request failed.'), true);
        }).fail(function () {
            $btn.prop('disabled', false);
            msg(t('requestFailed', 'Request failed.'), true);
        });
    });

    $(document).on('click', '#mealsdb-po-complete-reconcile', function () {
        var $btn = $(this);
        // Client-side pre-check: every adjusted row needs a note. The server
        // re-validates authoritatively; this just saves a round-trip.
        var missing = false;
        $('#mealsdb-po-grid tbody tr').each(function () {
            var $row = $(this);
            var ordered = parseInt($row.data('ordered-cases'), 10) || 0;
            if (currentCases($row) !== ordered && !String($row.find('.mealsdb-po-note').val() || '').replace(/\s/g, '').length) {
                $row.addClass('mealsdb-po-note-missing');
                missing = true;
            } else {
                $row.removeClass('mealsdb-po-note-missing');
            }
        });
        if (missing) {
            msg(t('noteRequired', 'A note is required for adjusted rows.'), true);
            return;
        }
        if (!window.confirm(t('confirmComplete', 'Complete reconciliation?'))) { return; }

        $btn.prop('disabled', true);
        msg(t('saving', 'Saving…'), false);
        $.post(cfg.ajaxUrl, {
            nonce: cfg.nonce, po_id: cfg.poId, action: 'mealsdb_po_complete_reconcile'
        }, function (res) {
            if (res && res.success) {
                window.location.href = cfg.baseUrl + '&po_id=' + cfg.poId;
                return;
            }
            $btn.prop('disabled', false);
            // Highlight server-reported offenders (authoritative).
            if (res && res.data && res.data.data && res.data.data.skus) {
                $.each(res.data.data.skus, function (_, sku) {
                    $('#mealsdb-po-grid tbody tr').filter(function () {
                        return String($(this).data('sku')) === String(sku);
                    }).addClass('mealsdb-po-note-missing');
                });
            }
            msg((res && res.data && res.data.message) || t('requestFailed', 'Request failed.'), true);
        }).fail(function () {
            $btn.prop('disabled', false);
            msg(t('requestFailed', 'Request failed.'), true);
        });
    });
    // ------------------------------------------------------------------
    // Export CSV (detail view). Values come from the row's data-* snapshot
    // attributes plus the live case count — NOT the formatted cell text —
    // so locale thousands-separators never leak into the CSV. Every cell
    // routes through Report.csvRow (formula-injection guard, Pattern 14);
    // if report-utils failed to load we refuse rather than emit unguarded
    // cells. In reconcile mode "Cases" is the received count (what the
    // grid shows).
    // ------------------------------------------------------------------
    var R = window.MealsDBReport || {};

    $(document).on('click', '#mealsdb-po-export-csv', function () {
        if (!R.csvRow || !R.exportCsv) {
            msg(t('requestFailed', 'Request failed.'), true);
            return;
        }
        var csv = R.csvRow(['SKU', 'Product', 'Adj/Wk', 'Stock', 'Case size', 'Cases', 'Order qty', 'Coverage (wks)', 'Forecast note']);
        $('#mealsdb-po-grid tbody tr').each(function () {
            var $row     = $(this);
            var caseSize = parseInt($row.data('case-size'), 10) || 1;
            var cases    = parseInt($row.find('.mealsdb-po-cases').attr('data-cases'), 10);
            if (isNaN(cases)) { // locked mode renders plain text, no stepper span
                cases = parseInt($row.data('ordered-cases'), 10) || 0;
            }
            csv += R.csvRow([
                String($row.attr('data-sku') || ''),
                String($row.find('td').eq(1).text()).trim(),
                String(parseFloat($row.data('adjusted-weekly')) || 0),
                String(parseInt($row.data('stock'), 10) || 0),
                String(caseSize),
                String(cases),
                String(cases * caseSize),
                String($row.find('.mealsdb-po-coverage').attr('data-coverage') || ''),
                String($row.find('td').last().text()).trim()
            ]);
        });
        var slug = String(cfg.poNumber || cfg.poId || 'draft').replace(/[^\w.-]+/g, '-');
        R.exportCsv(csv, 'po-' + slug + '-' + new Date().toISOString().slice(0, 10) + '.csv');
    });

    // ------------------------------------------------------------------
    // Generate draft PO — merged from the retired Purchase Order tab
    // (purchase-order.js, deleted in the same change). The server
    // REGENERATES the forecast rows and pallet-optimizes them; the browser
    // never supplies row data. On success, open the new draft's detail page.
    // ------------------------------------------------------------------
    $('#mealsdb-po-generate').on('click', function () {
        // Disabled while in flight: a double-click must not create two drafts.
        var $btn = $(this).prop('disabled', true);
        msg(t('generating', 'Generating…'), false);
        $.post(cfg.ajaxUrl, {
            action: 'mealsdb_po_save_draft',
            nonce: cfg.nonce || ''
        }, function (res) {
            if (res && res.success && res.data && res.data.po_id) {
                window.location.href = (cfg.baseUrl || '') + '&po_id=' + parseInt(res.data.po_id, 10);
                return;
            }
            $btn.prop('disabled', false);
            msg((res && res.data && res.data.message) || t('draftSaveFailed', 'Could not save the draft purchase order.'), true);
        }).fail(function () {
            $btn.prop('disabled', false);
            msg(t('requestFailed', 'Request failed.'), true);
        });
    });
})(jQuery);
