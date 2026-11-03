/**
 * A/B sync conflict view (directive K21 ITEM 9) — views/sync-conflicts.php.
 *
 * Collects one card's per-field choices and applies them in ONE request per
 * account (Rev 2 batching: one rate-limit token per account, not per field).
 * Every apply goes through MealsDBConfirm, which focuses Cancel (K10 ITEM 4).
 *
 * Each choice carries the values_hash the page rendered; the server re-reads
 * both systems and refuses any choice whose values changed since the page
 * loaded (stale-page guard), so this script never sends a value — only which
 * conflict, which action, and what the operator was looking at.
 *
 * All text is inserted with .text(); nothing from the server is ever treated
 * as HTML.
 */
(function ($) {
    'use strict';

    var el = document.getElementById('mealsdb-sync-conflicts-data');
    var data = el ? JSON.parse(el.textContent || '{}') : {};
    var msg = data.messages || {};
    var ajaxUrl = data.ajaxUrl || window.ajaxurl;

    function actionLabel(action) {
        if (action === 'dismiss') { return msg.dismiss || 'Leave both'; }
        if (action === 'undismiss') { return msg.undismiss || 'Undo dismissal'; }
        return action;
    }

    function collect($card) {
        var choices = [];
        var lines = [];
        $card.find('tr.mealsdb-conflict-row').not('.is-stale').not('.is-applied').each(function () {
            var $row = $(this);
            var value = String($row.find('input[type="radio"]:checked').val() || '');
            if (value === '') {
                return;
            }
            var action = value.indexOf('keep:') === 0 ? 'keep' : value;
            choices.push({
                conflict_id: parseInt($row.data('conflict-id'), 10),
                values_hash: String($row.data('values-hash')),
                action: action
            });
            lines.push(String($row.data('field-label')) + ': ' + actionLabel(action));
        });
        return { choices: choices, lines: lines };
    }

    function setStatus($card, text, isError) {
        $card.find('.mealsdb-conflict-status')
            .text(text || '')
            .toggleClass('is-error', !!isError);
    }

    function applyResults($card, results) {
        var failures = [];
        $.each(results || [], function (_, r) {
            var $row = $card.find('tr.mealsdb-conflict-row[data-conflict-id="' + parseInt(r.conflict_id, 10) + '"]');
            if (r.ok) {
                $row.addClass('is-applied').find('input').prop('disabled', true);
            } else {
                failures.push(String($row.data('field-label') || ('#' + r.conflict_id)) + ': ' + String(r.message || ''));
            }
        });
        if (failures.length) {
            setStatus($card, (msg.partial || 'Some choices were not applied:') + ' ' + failures.join('; '), true);
        } else {
            setStatus($card, msg.done || 'Applied.', false);
        }
    }

    $(document).on('click', '.mealsdb-conflict-apply', function () {
        var $btn = $(this);
        var $card = $btn.closest('.mealsdb-conflict-card');
        var picked = collect($card);

        if (!picked.choices.length) {
            window.MealsDBConfirm.alert({ message: msg.nothingChosen || 'Choose an action first.' });
            return;
        }

        window.MealsDBConfirm.confirm({
            title: msg.confirmTitle || 'Apply choices?',
            message: (msg.confirmIntro || '') + ' ' + picked.lines.join('; '),
            confirmLabel: msg.confirmLabel || 'Apply',
            cancelLabel: msg.cancelLabel || 'Cancel'
        }).then(function (ok) {
            if (!ok) {
                return;
            }
            $btn.prop('disabled', true);
            setStatus($card, msg.applying || 'Applying…', false);

            $.post(ajaxUrl, {
                action: 'mealsdb_sync_conflicts_apply',
                nonce: data.nonce,
                wp_user_id: parseInt($card.data('wp-user-id'), 10),
                choices: JSON.stringify(picked.choices)
            }).done(function (response) {
                if (response && response.success) {
                    applyResults($card, response.data && response.data.results);
                } else {
                    var m = response && response.data && response.data.message;
                    setStatus($card, (msg.failed || 'Failed.') + (m ? ' ' + String(m) : ''), true);
                }
            }).fail(function (xhr) {
                var m = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                setStatus($card, (msg.failed || 'Failed.') + (m ? ' ' + String(m) : ''), true);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    });
}(jQuery));
