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

})(jQuery);
