/**
 * Data-Ops "Schema Changes" tool (audit H7, slice 4b).
 *
 * Lists the RISKY / SAFE-but-COPY column drifts the version-bump path leaves for
 * the operator, and applies one with a typed "ALTER" confirmation. All server
 * data is escaped before insertion; the ALTER SQL is generated server-side (the
 * apply request only names a table+column — see MealsDB_Ajax_Schema_Alter).
 */
(function ($) {
    'use strict';

    var cfg = window.mealsdbSchemaAlter || {};
    var msg = cfg.messages || {};
    var $root = $('#mealsdb-schema-alter-tool');
    if (!$root.length || !cfg.ajaxUrl) { return; }

    function esc(s) {
        return $('<div/>').text(s == null ? '' : String(s)).html();
    }

    function tierBadge(tier) {
        var safe = tier === 'safe';
        return '<span style="display:inline-block;padding:1px 6px;border-radius:3px;color:#fff;font-size:11px;background:'
            + (safe ? '#2271b1' : '#b32d2e') + ';">' + (safe ? 'SAFE' : 'RISKY') + '</span>';
    }

    function preflightHtml(pf) {
        if (!pf || !pf.length) { return ''; }
        var items = pf.map(function (c) {
            var style = c.blocks ? 'color:#b32d2e;font-weight:600;' : 'color:#646970;';
            return '<li style="' + style + '">' + esc(c.check) + ': ' + esc(c.count) + ' ' + esc(msg.rows || 'rows') + '</li>';
        }).join('');
        return '<ul style="margin:4px 0 4px 18px;list-style:disc;">' + items + '</ul>';
    }

    function changeHtml(ch) {
        var html = '<div class="mealsdb-schema-change" data-table="' + esc(ch.table) + '" data-column="' + esc(ch.column)
            + '" style="border:1px solid #dcdcde;border-radius:4px;padding:10px;margin:8px 0;">';
        html += '<div>' + tierBadge(ch.tier) + ' <strong>' + esc(ch.table) + '.' + esc(ch.column) + '</strong> — ' + esc(ch.reason) + '</div>';
        html += '<div style="margin:6px 0;"><code style="display:block;white-space:pre-wrap;background:#f6f7f7;padding:6px;">' + esc(ch.alter_sql) + '</code></div>';
        html += preflightHtml(ch.preflight);
        if (ch.can_apply) {
            html += '<div class="mealsdb-schema-apply-row">'
                + '<input type="text" class="mealsdb-schema-confirm" placeholder="' + esc(msg.confirmHint || 'Type ALTER to confirm') + '" autocomplete="off" style="width:220px;" /> '
                + '<button type="button" class="button button-secondary mealsdb-schema-apply-btn">' + esc(msg.apply || 'Apply change') + '</button>'
                + ' <span class="mealsdb-schema-apply-msg" style="margin-left:8px;"></span>'
                + '</div>';
        } else {
            html += '<div style="color:#b32d2e;">' + esc(msg.blockedPre || 'Blocked: rows would be lost.') + '</div>';
        }
        html += '</div>';
        return html;
    }

    function render(changes) {
        if (!changes || !changes.length) {
            $root.html('<p class="description">' + esc(msg.none || 'No pending schema changes.') + '</p>');
            return;
        }
        $root.html(changes.map(changeHtml).join(''));
    }

    function loadPreview() {
        $.post(cfg.ajaxUrl, { action: 'mealsdb_schema_alter_preview', nonce: cfg.nonce })
            .done(function (resp) {
                if (resp && resp.success && resp.data && resp.data.changes) {
                    render(resp.data.changes);
                } else {
                    $root.html('<p class="description" style="color:#b32d2e;">' + esc(msg.loadError || 'Could not load.') + '</p>');
                }
            })
            .fail(function () {
                $root.html('<p class="description" style="color:#b32d2e;">' + esc(msg.loadError || 'Could not load.') + '</p>');
            });
    }

    $root.on('click', '.mealsdb-schema-apply-btn', function () {
        var $card = $(this).closest('.mealsdb-schema-change');
        var $msg  = $card.find('.mealsdb-schema-apply-msg');
        var confirmVal = $card.find('.mealsdb-schema-confirm').val() || '';
        if (confirmVal.toUpperCase() !== 'ALTER') {
            $msg.css('color', '#b32d2e').text(msg.confirmErr || 'Type ALTER to confirm.');
            return;
        }
        var $btn = $(this).prop('disabled', true).text(msg.applying || 'Applying…');
        $.post(cfg.ajaxUrl, {
            action: 'mealsdb_schema_alter_apply',
            nonce:  cfg.nonce,
            table:  $card.data('table'),
            column: $card.data('column'),
            confirm: confirmVal
        }).done(function (resp) {
            if (resp && resp.success) {
                loadPreview(); // the applied change should drop off the list
            } else {
                var m = (resp && resp.data && resp.data.message) ? resp.data.message : (msg.loadError || 'Failed.');
                $msg.css('color', '#b32d2e').text(m);
                $btn.prop('disabled', false).text(msg.apply || 'Apply change');
            }
        }).fail(function () {
            $msg.css('color', '#b32d2e').text(msg.loadError || 'Failed.');
            $btn.prop('disabled', false).text(msg.apply || 'Apply change');
        });
    });

    loadPreview();
})(jQuery);
