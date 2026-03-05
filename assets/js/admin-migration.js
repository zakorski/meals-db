(function ($) {
    'use strict';

    var state = {
        filePath: '',
        sourcePrefix: '',
        dryRun: true,
        phase: -1,        // -1 = not started, 0-5 = active phase
        phaseOffset: 0,
        byteOffset: 0,
        running: false,
        phaseStats: {},   // accumulated stats per phase
    };

    var phaseNames = [
        'Load Source Tables',
        'Migrate Users',
        'Migrate Products',
        'Migrate Orders',
        'Create Meals Clients',
        'Create Client Rates',
    ];

    // ── Helpers ──────────────────────────────────

    function ajax(action, data, successCb, errorCb) {
        data.action = 'mealsdb_migration_' + action;
        data.nonce  = mealsdbMigration.nonce;
        $.post(mealsdbMigration.ajaxUrl, data, function (resp) {
            if (resp.success) {
                successCb(resp.data);
            } else {
                var msg = (resp.data && resp.data.message) ? resp.data.message : 'Unknown error';
                if (errorCb) errorCb(msg); else alert('Error: ' + msg);
            }
        }).fail(function (xhr) {
            var msg = 'Network error (' + xhr.status + ')';
            if (errorCb) errorCb(msg); else alert(msg);
        });
    }

    function setPhaseIcon(phase, icon) {
        // icon: 'pending' | 'running' | 'done' | 'error'
        var $el = $('#mig-phase-' + phase + ' .mealsdb-mig-phase-icon');
        var map = { pending: '&#9711;', running: '&#9881;', done: '&#10003;', error: '&#10007;' };
        $el.html(map[icon] || map.pending)
           .removeClass('pending running done error').addClass(icon);
    }

    function setPhaseStatus(phase, text) {
        $('#mig-phase-' + phase + ' .mealsdb-mig-phase-status').text(text);
    }

    function setPhaseBar(phase, pct) {
        $('#mig-phase-' + phase + ' .mealsdb-mig-phase-bar').css('width', pct + '%');
    }

    function statsHtml(stats) {
        if (!stats || typeof stats !== 'object') return '';
        var parts = [];
        $.each(stats, function (k, v) {
            parts.push('<strong>' + k + ':</strong> ' + v);
        });
        return parts.join(' &nbsp;|&nbsp; ');
    }

    // ── Detect Prefix ────────────────────────────

    $('#mig-detect-btn').on('click', function () {
        var path = $('#mig-file-path').val().trim();
        if (!path) { alert('Enter the SQL dump path.'); return; }

        $(this).prop('disabled', true).text('Detecting...');

        ajax('detect', { file_path: path }, function (data) {
            state.filePath     = path;
            state.sourcePrefix = data.prefix;

            $('#mig-prefix-value').text(data.prefix);
            $('#mig-file-size').text('(' + data.file_mb + ' MB)');
            $('#mig-prefix-row').show();
            $('#mig-options').show();
            $('#mig-detect-btn').prop('disabled', false).text('Detect Prefix');
        }, function (msg) {
            alert(msg);
            $('#mig-detect-btn').prop('disabled', false).text('Detect Prefix');
        });
    });

    // ── Start Migration ──────────────────────────

    $('#mig-start-btn').on('click', function () {
        if (state.running) return;

        state.dryRun     = $('#mig-dry-run').is(':checked');
        state.phase      = 0;
        state.phaseOffset = 0;
        state.byteOffset = 0;
        state.running    = true;
        state.phaseStats = {};

        $('#mig-step-setup').find('input, button').prop('disabled', true);
        $('#mig-step-progress').show();
        $('#mig-step-results').hide();
        $('#mig-log-viewer').hide();

        // Reset all phase indicators
        for (var i = 0; i <= 5; i++) {
            setPhaseIcon(i, 'pending');
            setPhaseStatus(i, '');
            setPhaseBar(i, 0);
        }

        runCurrentPhase();
    });

    // ── Phase Runner ─────────────────────────────

    function runCurrentPhase() {
        if (!state.running) return;

        setPhaseIcon(state.phase, 'running');

        if (state.phase === 0) {
            runLoadPhase();
        } else {
            runDataPhase();
        }
    }

    function runLoadPhase() {
        ajax('load', {
            file_path:     state.filePath,
            source_prefix: state.sourcePrefix,
            byte_offset:   state.byteOffset,
        }, function (data) {
            state.byteOffset = data.byte_offset;
            setPhaseBar(0, data.percent);
            setPhaseStatus(0, data.percent + '% (' + data.statements + ' stmts this chunk)');

            if (data.complete) {
                setPhaseIcon(0, 'done');
                advancePhase();
            } else {
                runLoadPhase(); // next chunk
            }
        }, function (msg) {
            setPhaseIcon(0, 'error');
            setPhaseStatus(0, msg);
            state.running = false;
            enableSetup();
        });
    }

    function runDataPhase() {
        ajax('phase', {
            phase:         state.phase,
            offset:        state.phaseOffset,
            dry_run:       state.dryRun ? 1 : 0,
            source_prefix: state.sourcePrefix,
        }, function (data) {
            // Accumulate stats
            if (data.stats) {
                if (!state.phaseStats[state.phase]) {
                    state.phaseStats[state.phase] = {};
                }
                $.each(data.stats, function (k, v) {
                    state.phaseStats[state.phase][k] = (state.phaseStats[state.phase][k] || 0) + v;
                });
            }

            var pct = data.total > 0 ? Math.min(100, Math.round((data.offset / data.total) * 100)) : 100;
            setPhaseBar(state.phase, pct);
            setPhaseStatus(state.phase, pct + '%');
            $('#mig-current-stats').html(
                '<strong>' + phaseNames[state.phase] + ':</strong> ' +
                statsHtml(state.phaseStats[state.phase])
            );

            if (data.complete) {
                setPhaseIcon(state.phase, 'done');
                advancePhase();
            } else {
                state.phaseOffset = data.offset;
                runDataPhase(); // next batch
            }
        }, function (msg) {
            setPhaseIcon(state.phase, 'error');
            setPhaseStatus(state.phase, msg);
            state.running = false;
            enableSetup();
        });
    }

    function advancePhase() {
        if (state.phase >= 5) {
            // All done
            state.running = false;
            showResults();
            return;
        }

        state.phase++;
        state.phaseOffset = 0;
        runCurrentPhase();
    }

    // ── Results ──────────────────────────────────

    function showResults() {
        var html = '<table class="widefat striped"><thead><tr><th>Phase</th><th>Stats</th></tr></thead><tbody>';
        for (var i = 0; i <= 5; i++) {
            html += '<tr><td>' + phaseNames[i] + '</td><td>' +
                    (state.phaseStats[i] ? statsHtml(state.phaseStats[i]) : 'OK') +
                    '</td></tr>';
        }
        html += '</tbody></table>';
        html += '<p>' + (state.dryRun
            ? '<strong>Dry run complete.</strong> No data was written. Uncheck "Dry run" and run again to perform the actual migration.'
            : '<strong>Migration complete.</strong> All data has been imported.') + '</p>';

        $('#mig-results-summary').html(html);
        $('#mig-step-results').show();
        enableSetup();
    }

    function enableSetup() {
        $('#mig-step-setup').find('input, button').prop('disabled', false);
    }

    // ── Cleanup / Log / Reset ────────────────────

    $('#mig-cleanup-btn').on('click', function () {
        if (!confirm('Drop all source tables (' + state.sourcePrefix + '*)? This cannot be undone.')) return;
        ajax('cleanup', { source_prefix: state.sourcePrefix }, function (data) {
            alert('Dropped ' + data.dropped + ' source tables.');
        });
    });

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

})(jQuery);
