(function ($) {
    'use strict';

    // ── Helpers ──────────────────────────────────

    function ajax(action, data, successCb, errorCb) {
        data.action = 'mealsdb_migration_' + action;
        data.nonce  = mealsdbMigration.nonce;
        $.post(mealsdbMigration.ajaxUrl, data, function (resp) {
            if (resp.success) {
                successCb(resp.data);
            } else {
                var msg = (resp.data && resp.data.message) ? resp.data.message : 'Unknown error';
                if (errorCb) errorCb(msg); else MealsDBNotice('error', 'Error: ' + msg);
            }
        }).fail(function (xhr) {
            var msg = 'Network error (' + xhr.status + ')';
            if (errorCb) errorCb(msg); else MealsDBNotice('error', msg);
        });
    }

    // HTML-escape via the DOM's own textContent → innerHTML round-trip
    // so we don't have to maintain an entity table. Used below for the
    // stats rendering where both keys and values come from the AJAX
    // response and could contain characters the browser would treat
    // as markup.
    // Prefer the shared MealsDBReport.esc (STR-2 consolidation); fall back to an
    // identical DOM round-trip so escaping is never disabled if report-utils
    // didn't load.
    function escHtml(s) {
        if (window.MealsDBReport && typeof window.MealsDBReport.esc === 'function') {
            return window.MealsDBReport.esc(s);
        }
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    $('#mig-log-btn').on('click', function () {
        ajax('log', {}, function (data) {
            $('#mig-log-content').text(data.log || '(empty)');
            $('#mig-log-viewer').toggle();
        });
    });

    $('#mig-reset-btn').on('click', function () {
        if (!confirm('Reset migration progress and log?')) return;
        ajax('reset', {}, function () {
            location.reload();
        });
    });

    // =================================================================
    //  Consolidated pipeline (post-import WP -> meals_* data movement)
    //
    //  Separate, self-contained runner that drives the
    //  mealsdb_consolidated_phase action through the same chunk/advance
    //  loop the import uses. Phases 1-7 are defined server-side in
    //  MealsDB_Migration_Consolidated::phases(); the labels here mirror
    //  them for the progress UI. The Enzebra import flow above is left
    //  untouched.
    // =================================================================

    var consPhaseNames = {
        1: 'Create Meals Clients',
        2: 'Create Client Rates',
        3: 'Backfill Allowances',
        4: 'Backfill Addresses',
        5: 'Backfill Next Dates',
        6: 'Promote Private Clients',
        7: 'Backfill Allocations',
        8: 'Backfill Delivery Day'
    };
    var CONS_FIRST = 1;
    var CONS_LAST  = 8;

    var consState = {
        phase: CONS_FIRST,
        offset: 0,
        dryRun: true,
        ignoreRateLimit: false,
        running: false,
        stats: {}
    };

    function consAjax(data, successCb, errorCb) {
        data.action = 'mealsdb_consolidated_phase';
        data.nonce  = mealsdbMigration.nonce;
        $.post(mealsdbMigration.ajaxUrl, data, function (resp) {
            if (resp.success) {
                successCb(resp.data);
            } else {
                var msg = (resp.data && resp.data.message) ? resp.data.message : 'Unknown error';
                if (errorCb) errorCb(msg); else MealsDBNotice('error', 'Error: ' + msg);
            }
        }).fail(function (xhr) {
            if (errorCb) errorCb('Network error (' + xhr.status + ')');
        });
    }

    function consSetStatus(phase, text) {
        $('#cons-phase-' + phase + ' .mealsdb-mig-phase-status').text(text);
    }
    function consSetIcon(phase, state) {
        var icons = { running: '\u25CC', done: '\u2713', error: '\u2717', idle: '\u25CB' };
        $('#cons-phase-' + phase + ' .mealsdb-mig-phase-icon').text(icons[state] || '\u25CB');
    }
    function consSetBar(phase, pct) {
        $('#cons-phase-' + phase + ' .mealsdb-mig-phase-bar').css('width', pct + '%');
    }
    function consStatsHtml(stats) {
        if (!stats) return '';
        // Escape keys AND values — this string is injected via .html() below, and
        // a stat value that ever carried an error string would otherwise land an
        // XSS (the sibling statsHtml() already escapes for exactly this reason).
        return Object.keys(stats).map(function (k) {
            return escHtml(k) + ': ' + escHtml(stats[k]);
        }).join(', ');
    }

    function consExtraArgs() {
        var extra = {};
        var lb = $('#cons-lookback').val();
        if (lb) extra.lookback_months = parseInt(lb, 10);
        var sm = $('#cons-start-month').val();
        var em = $('#cons-end-month').val();
        if (sm) extra.start_month = sm;
        if (em) extra.end_month = em;
        return extra;
    }

    function consRunPhase() {
        consSetIcon(consState.phase, 'running');

        var payload = $.extend({
            phase: consState.phase,
            offset: consState.offset,
            dry_run: consState.dryRun ? 1 : 0,
            ignore_rate_limit: consState.ignoreRateLimit ? 1 : 0
        }, consExtraArgs());

        consAjax(payload, function (data) {
            if (data.stats) {
                if (!consState.stats[consState.phase]) consState.stats[consState.phase] = {};
                $.each(data.stats, function (k, v) {
                    consState.stats[consState.phase][k] = (consState.stats[consState.phase][k] || 0) + v;
                });
            }

            var pct = data.total > 0 ? Math.min(100, Math.round((data.offset / data.total) * 100)) : 100;
            consSetBar(consState.phase, pct);
            consSetStatus(consState.phase, pct + '%');
            $('#cons-current-stats').html(
                '<strong>' + consPhaseNames[consState.phase] + ':</strong> ' +
                consStatsHtml(consState.stats[consState.phase])
            );

            if (data.complete) {
                consSetIcon(consState.phase, 'done');
                consSetStatus(consState.phase, 'done');
                consAdvance();
            } else {
                consState.offset = data.offset;
                consRunPhase();
            }
        }, function (msg) {
            consSetIcon(consState.phase, 'error');
            consSetStatus(consState.phase, msg);
            consState.running = false;
            $('#cons-run-btn').prop('disabled', false);
        });
    }

    function consAdvance() {
        if (consState.phase >= CONS_LAST) {
            consState.running = false;
            $('#cons-run-btn').prop('disabled', false);
            $('#cons-results').show().html(
                '<p><strong>' + (consState.dryRun
                    ? 'Dry run complete. No data was written.'
                    : 'Consolidated migration complete.') + '</strong></p>'
            );
            return;
        }
        consState.phase++;
        consState.offset = 0;
        consRunPhase();
    }

    $('#cons-run-btn').on('click', function () {
        if (consState.running) return;
        consState.dryRun = $('#cons-dry-run').is(':checked');
        consState.ignoreRateLimit = $('#cons-ignore-rate-limit').is(':checked');
        if (!consState.dryRun && !confirm('Run the consolidated migration for REAL? This writes to meals_* tables.')) {
            return;
        }
        consState.running = true;
        consState.phase = CONS_FIRST;
        consState.offset = 0;
        consState.stats = {};
        $(this).prop('disabled', true);
        $('#cons-results').hide().empty();
        for (var p = CONS_FIRST; p <= CONS_LAST; p++) {
            consSetIcon(p, 'idle');
            consSetStatus(p, '');
            consSetBar(p, 0);
        }
        consRunPhase();
    });

    // =================================================================
    //  Delivery-date backfill (ITEM 2) — phases 9 & 10 + scorecard.
    //
    //  DELIBERATE, STANDALONE operations, NOT part of the 1-8 chain
    //  above. ddbRunPhase drives a SINGLE phase to completion and STOPS
    //  — it has no advance step (contrast consAdvance), so a phase 9 run
    //  never rolls into phase 10 (or anything else). Each phase must be
    //  launched by the operator with its own button. Reuses the shared
    //  escHtml() helper defined at the top of this IIFE so all
    //  server-supplied values are escaped before .html() insertion.
    // =================================================================

    var ddbRunning = false;

    function ddbSetButtons(disabled) {
        $('#ddb-run-9, #ddb-run-10').prop('disabled', disabled);
    }

    // Render "key: n" pairs, escaping both key and value (defense in depth;
    // the JS layer is audited XSS-clean — keep it so).
    function ddbStatsHtml(stats) {
        if (!stats) return '';
        return Object.keys(stats).map(function (k) {
            return escHtml(k) + ': ' + escHtml(stats[k]);
        }).join(', ');
    }

    // Single-phase walker. Chunks through ONE phase via
    // mealsdb_consolidated_phase and stops when complete — never advances
    // to another phase.
    function ddbRunPhase(phase, opts) {
        var offset = 0;
        var acc = {};        // accumulated numeric stats across chunks
        var lastMonth = '';  // latest string stat (e.g. the month being built)

        function chunk() {
            $.post(mealsdbMigration.ajaxUrl, {
                action: 'mealsdb_consolidated_phase',
                nonce: mealsdbMigration.nonce,
                phase: phase,
                offset: offset,
                dry_run: opts.dryRun ? 1 : 0,
                start_month: opts.startMonth || '',
                end_month: opts.endMonth || ''
            }).done(function (res) {
                if (!res || !res.success) {
                    opts.$progress.text((res && res.data && res.data.message) || 'Error');
                    ddbRunning = false;
                    ddbSetButtons(false);
                    return;
                }
                var d = res.data; // {stats, offset, total, complete, phase}
                Object.keys(d.stats || {}).forEach(function (k) {
                    var v = d.stats[k];
                    if (typeof v === 'number') {
                        acc[k] = (acc[k] || 0) + v;
                    } else if (k === 'month') {
                        lastMonth = v;
                    }
                });
                var pct = d.total ? Math.min(100, Math.round((d.offset / d.total) * 100)) : 100;
                opts.$progress.html(
                    '<strong>' + escHtml(opts.label) + (opts.dryRun ? ' (dry run)' : '') + ':</strong> ' +
                    pct + '% — ' + ddbStatsHtml(acc) +
                    (lastMonth ? ' [month ' + escHtml(lastMonth) + ']' : '')
                );
                if (d.complete) {
                    opts.$progress.append(' — <em>done</em>');
                    ddbRunning = false;
                    ddbSetButtons(false);
                } else {
                    offset = d.offset;
                    chunk();
                }
            }).fail(function () {
                opts.$progress.text('Request failed.');
                ddbRunning = false;
                ddbSetButtons(false);
            });
        }

        opts.$progress.text('Starting…');
        chunk();
    }

    function ddbStart(phase, label) {
        if (ddbRunning) return;
        var dryRun = $('#ddb-dry-run').is(':checked');
        // A real (non-dry) run writes billing data — confirm first.
        if (!dryRun && !confirm('Run "' + label + '" for REAL? This writes delivery dates / allocation billing data.')) {
            return;
        }
        ddbRunning = true;
        ddbSetButtons(true);
        ddbRunPhase(phase, {
            dryRun: dryRun,
            startMonth: $('#ddb-start-month').val() || '',
            endMonth: $('#ddb-end-month').val() || '',
            label: label,
            $progress: $('#ddb-progress')
        });
    }

    $('#ddb-run-9').on('click', function () {
        ddbStart(9, 'Backfill Delivery Dates');
    });
    $('#ddb-run-10').on('click', function () {
        ddbStart(10, 'Rebuild Allocations');
    });

    $('#ddb-scorecard-run').on('click', function () {
        var csv = $('#ddb-scorecard-csv').val() || '';
        var $out = $('#ddb-scorecard-result');
        $out.text('Scoring…');
        $.post(mealsdbMigration.ajaxUrl, {
            action: 'mealsdb_delivery_scorecard',
            nonce: mealsdbMigration.nonce,
            csv: csv
        }).done(function (res) {
            if (!res || !res.success) {
                $out.text((res && res.data && res.data.message) || 'Error');
                return;
            }
            var d = res.data;
            var rate = (d.match_rate * 100).toFixed(1);
            var html = '<p><strong>' + escHtml(d.matched) + ' / ' + escHtml(d.total) +
                       ' matched (' + escHtml(rate) + '%)</strong>' +
                       (d.unresolved ? ' — ' + escHtml(d.unresolved) + ' order#s did not resolve' : '') + '</p>';
            if (d.misses && d.misses.length) {
                html += '<table class="widefat striped"><thead><tr><th>Order</th><th>Stored</th><th>Actual</th></tr></thead><tbody>';
                d.misses.forEach(function (m) {
                    html += '<tr><td>' + escHtml(m.order) + '</td><td>' + escHtml(m.stored) +
                            '</td><td>' + escHtml(m.actual) + '</td></tr>';
                });
                html += '</tbody></table>';
                if (d.misses_total > d.misses_shown) {
                    html += '<p>Showing first ' + escHtml(d.misses_shown) + ' of ' +
                            escHtml(d.misses_total) + ' misses.</p>';
                }
            }
            $out.html(html);
        }).fail(function () {
            $out.text('Request failed.');
        });
    });

})(jQuery);
