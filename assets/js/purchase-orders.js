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
        var $el = $('#mealsdb-po-action-msg')
            .text(text || '')
            .css('color', isError ? '#b32d2e' : '#2271b1');
        // v561 ITEM 3a: a single action-message element sits at the top of the
        // list view, far from a row-level action, so errors read as "nothing
        // happened". Bring it into view on error (only scrolls if off-screen).
        if (isError && text && $el.length && $el[0].scrollIntoView) {
            $el[0].scrollIntoView({ block: 'nearest' });
        }
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

    var savePending = {};    // sku -> $row: the latest debounced row awaiting save
    var saveInFlight = false;

    function queueSave($row) {
        var sku = String($row.data('sku'));
        if (saveTimers[sku]) { window.clearTimeout(saveTimers[sku]); }
        saveTimers[sku] = window.setTimeout(function () {
            delete saveTimers[sku];
            savePending[sku] = $row; // keep only the latest value for this sku
            flushSaves();
        }, 600);
    }

    // Serialize saves: only one is in flight at a time so edits to DIFFERENT rows
    // of the same PO can't race on the shared payload (last-write-wins would drop
    // an edit — v560 ITEM 5). Each queued save reads the payload only AFTER the
    // prior write has committed.
    function flushSaves() {
        if (saveInFlight) { return; }
        var skus = Object.keys(savePending);
        if (!skus.length) { return; }
        var sku = skus[0];
        var $row = savePending[sku];
        delete savePending[sku];
        saveRow($row);
    }

    // v561 ITEM 3b: run `cb` only after every pending/in-flight row save has
    // committed. complete_reconcile validates the SERVER-stored note, so a note
    // still sitting in a debounce timer would be seen as missing (the first
    // Complete click was a no-op). Fire pending debounces now, then wait.
    function drainSavesThen(cb) {
        Object.keys(saveTimers).forEach(function (sku) {
            window.clearTimeout(saveTimers[sku]);
            delete saveTimers[sku];
            savePending[sku] = $('#mealsdb-po-grid tbody tr').filter(function () {
                return String($(this).data('sku')) === sku;
            });
        });
        flushSaves();
        var waited = 0;
        (function wait() {
            if (!saveInFlight && !Object.keys(savePending).length) { cb(); return; }
            if (waited > 8000) { cb(); return; } // safety valve — don't hang forever
            waited += 60;
            window.setTimeout(wait, 60);
        })();
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
        saveInFlight = true;
        $row.addClass('mealsdb-po-saving');
        $.post(cfg.ajaxUrl, data, function (res) {
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
            msg(t('requestFailed', 'Request failed.'), true);
        }).always(function () {
            $row.removeClass('mealsdb-po-saving');
            saveInFlight = false;
            flushSaves(); // run the next queued save, if any
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

        // The POST — run only after the branch's in-page dialog resolves.
        function submit() {
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
        }

        if (kind === 'approve') {
            var $arrival = $('#mealsdb-po-expected-arrival');
            if ($arrival.length) {
                // Detail page: date input + a plain confirm.
                window.MealsDBConfirm.confirm({
                    title: 'Approve PO',
                    message: map.confirm,
                    confirmLabel: 'Approve'
                }).then(function (ok) {
                    if (!ok) { return; }
                    data.expected_arrival = String($arrival.val() || '');
                    submit();
                });
            } else {
                // List page: the prompt doubles as confirm. Blank = the server's
                // computed default (a VALID, distinct outcome); cancel = abort.
                // An invalid typo is rejected with a message rather than accepted
                // (v560 ITEM 4); the operator re-clicks Approve to retry.
                var isValidYmd = function (s) {
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) { return false; }
                    var d = new Date(s + 'T00:00:00Z');
                    return !isNaN(d.getTime()) && d.toISOString().slice(0, 10) === s;
                };
                window.MealsDBConfirm.prompt({
                    title: 'Approve PO',
                    message: t('promptExpectedArrival', 'Expected arrival date (YYYY-MM-DD), or leave blank for the computed default — OK approves:'),
                    confirmLabel: 'Approve',
                    placeholder: 'YYYY-MM-DD'
                }).then(function (picked) {
                    if (picked === null) { return; } // cancel aborts the approval
                    picked = picked.replace(/^\s+|\s+$/g, '');
                    if (picked !== '' && !isValidYmd(picked)) {
                        msg(t('invalidDate', 'Enter a date as YYYY-MM-DD, or leave blank for the default.'), true);
                        return;
                    }
                    data.expected_arrival = picked; // '' → server default
                    submit();
                });
            }
        } else if (kind === 'unapprove' || kind === 'unaccept') {
            var promptTxt = (kind === 'unaccept')
                ? t('promptUnaccept', 'Enter a reason for un-accepting (required):')
                : t('promptUnapprove', 'Enter a reason for un-approving (required):');
            // The modal's `required` support replaces the native re-prompt loop:
            // it refuses to submit empty and shows the error inline (v560 ITEM 3).
            window.MealsDBConfirm.prompt({
                title: (kind === 'unaccept') ? 'Un-accept PO' : 'Un-approve PO',
                message: promptTxt,
                required: true,
                requiredMessage: t('reasonRequired', 'A reason is required.'),
                destructive: true
            }).then(function (reason) {
                if (reason === null || !reason.trim()) { return; }
                data.reason = reason.replace(/^\s+|\s+$/g, '');
                submit();
            });
        } else {
            window.MealsDBConfirm.confirm({
                title: 'Please confirm',
                message: map.confirm
            }).then(function (ok) {
                if (ok) { submit(); }
            });
        }
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
        window.MealsDBConfirm.confirm({
            title: 'Complete reconciliation',
            message: t('confirmComplete', 'Complete reconciliation?'),
            confirmLabel: 'Complete'
        }).then(function (ok) {
            if (!ok) { return; }

            $btn.prop('disabled', true);
            msg(t('saving', 'Saving…'), false);
            // Flush any debounced note/count saves BEFORE completing, so the server
            // validates the notes the operator actually typed (v561 ITEM 3b).
            drainSavesThen(function () {
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
