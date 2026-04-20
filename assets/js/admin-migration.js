(function ($) {
    'use strict';

    var state = {
        sourceMode: 'db',  // 'db', 'upload', or 'filepath'
        filePath: '',
        sourcePrefix: '',
        // Opaque token returned by the server's test_db endpoint. The
        // actual host/name/user/password are held in a per-user transient
        // server-side and resolved from this token, so the password never
        // lives in JS memory or gets re-transmitted over AJAX.
        credsToken: '',
        dryRun: true,
        phase: -1,
        phaseOffset: 0,
        byteOffset: 0,
        tableIndex: 0,
        running: false,
        phaseStats: {},
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

    function ajaxUpload(formData, successCb, errorCb) {
        formData.append('action', 'mealsdb_migration_upload');
        formData.append('nonce', mealsdbMigration.nonce);
        $.ajax({
            url: mealsdbMigration.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (resp) {
                if (resp.success) {
                    successCb(resp.data);
                } else {
                    var msg = (resp.data && resp.data.message) ? resp.data.message : 'Upload failed';
                    if (errorCb) errorCb(msg); else alert('Error: ' + msg);
                }
            },
            error: function (xhr) {
                var msg = 'Upload failed (' + xhr.status + ')';
                if (errorCb) errorCb(msg); else alert(msg);
            },
        });
    }

    function setPhaseIcon(phase, icon) {
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

    // HTML-escape via the DOM's own textContent → innerHTML round-trip
    // so we don't have to maintain an entity table. Used below for the
    // stats rendering where both keys and values come from the AJAX
    // response and could contain characters the browser would treat
    // as markup.
    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function statsHtml(stats) {
        if (!stats || typeof stats !== 'object') return '';
        var parts = [];
        $.each(stats, function (k, v) {
            // Keys are server-defined today (e.g. "processed", "skipped")
            // but the values come back from the migration service's
            // aggregated state. Escape both so a migration tool that
            // ever reports a free-form key or an error string as a
            // "value" doesn't land an XSS here.
            parts.push('<strong>' + escHtml(k) + ':</strong> ' + escHtml(v));
        });
        return parts.join(' &nbsp;|&nbsp; ');
    }

    // ── Tab Switching ─────────────────────────────

    $('.mig-tab').on('click', function () {
        var tab = $(this).data('tab');
        $('.mig-tab').removeClass('active');
        $(this).addClass('active');
        $('.mealsdb-mig-tab-content').hide();
        $('#mig-tab-' + tab).show();
        state.sourceMode = tab;
        $('#mig-prefix-result').hide();
    });

    // ── Database Connection Test ──────────────────

    $('#mig-test-db-btn').on('click', function () {
        var host = $('#mig-db-host').val().trim();
        var name = $('#mig-db-name').val().trim();
        var user = $('#mig-db-user').val().trim();
        var pass = $('#mig-db-pass').val();

        if (!name || !user) {
            alert('Database name and username are required.');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Testing...');

        ajax('test_db', {
            db_host: host || 'localhost',
            db_name: name,
            db_user: user,
            db_pass: pass,
        }, function (data) {
            state.sourcePrefix = data.prefix;
            state.credsToken   = data.creds_token || '';

            // Clear the password field so the value isn't sitting in the
            // DOM after a successful test. The server already has it.
            $('#mig-db-pass').val('');
            pass = '';

            $('#mig-prefix-value').text(data.prefix);
            $('#mig-source-info').text('(' + data.tables + ' tables in ' + data.db_name + ')');
            $('#mig-prefix-result').show();
            $btn.prop('disabled', false).text('Test Connection & Detect Prefix');
        }, function (msg) {
            alert(msg);
            $btn.prop('disabled', false).text('Test Connection & Detect Prefix');
        });
    });

    // ── File Upload ───────────────────────────────

    $('#mig-upload-btn').on('click', function () {
        var fileInput = $('#mig-file-upload')[0];
        if (!fileInput.files || !fileInput.files[0]) {
            alert('Select a SQL file first.');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#mig-upload-progress').show();
        $('#mig-upload-status').text('Uploading...');

        var formData = new FormData();
        formData.append('sql_file', fileInput.files[0]);

        ajaxUpload(formData, function (data) {
            state.sourcePrefix = data.prefix;
            state.filePath     = data.file_path;
            state.sourceMode   = 'upload';

            $('#mig-prefix-value').text(data.prefix);
            $('#mig-source-info').text('(' + data.file_mb + ' MB uploaded)');
            $('#mig-prefix-result').show();
            $('#mig-upload-progress').hide();
            $btn.prop('disabled', false);
        }, function (msg) {
            alert(msg);
            $('#mig-upload-progress').hide();
            $btn.prop('disabled', false);
        });
    });

    // ── Server File Path ─────────────────────────

    $('#mig-detect-btn').on('click', function () {
        var path = $('#mig-file-path').val().trim();
        if (!path) {
            alert('Enter the SQL dump path.');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Detecting...');

        ajax('detect', { file_path: path }, function (data) {
            state.sourcePrefix = data.prefix;
            state.filePath     = path;

            $('#mig-prefix-value').text(data.prefix);
            $('#mig-source-info').text('(' + data.file_mb + ' MB)');
            $('#mig-prefix-result').show();
            $btn.prop('disabled', false).text('Detect Prefix');
        }, function (msg) {
            alert(msg);
            $btn.prop('disabled', false).text('Detect Prefix');
        });
    });

    // ── Start Migration ──────────────────────────

    $('#mig-start-btn').on('click', function () {
        if (state.running) return;
        if (!state.sourcePrefix) {
            alert('Detect the source prefix first.');
            return;
        }

        state.dryRun      = $('#mig-dry-run').is(':checked');
        state.phase       = 0;
        state.phaseOffset = 0;
        state.byteOffset  = 0;
        state.tableIndex  = 0;
        state.running     = true;
        state.phaseStats  = {};

        $('#mig-step-setup').find('input, button').prop('disabled', true);
        $('#mig-step-progress').show();
        $('#mig-step-results').hide();
        $('#mig-log-viewer').hide();

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
            if (state.sourceMode === 'db') {
                runLoadFromDb();
            } else { // 'upload' or 'filepath' — both use file-based loading
                runLoadPhase();
            }
        } else {
            runDataPhase();
        }
    }

    // Phase 0: file mode
    function runLoadPhase() {
        ajax('load', {
            file_path:     state.filePath,
            source_prefix: state.sourcePrefix,
            byte_offset:   state.byteOffset,
            dry_run:       state.dryRun ? 1 : 0,
        }, function (data) {
            state.byteOffset = data.byte_offset;
            setPhaseBar(0, data.percent);
            setPhaseStatus(0, data.percent + '% (' + data.statements + ' stmts this chunk)');

            if (data.complete) {
                setPhaseIcon(0, 'done');
                advancePhase();
            } else {
                runLoadPhase();
            }
        }, function (msg) {
            setPhaseIcon(0, 'error');
            setPhaseStatus(0, msg);
            state.running = false;
            enableSetup();
        });
    }

    // Phase 0: database mode
    function runLoadFromDb() {
        if (!state.credsToken) {
            setPhaseIcon(0, 'error');
            setPhaseStatus(0, 'Connection token expired. Re-run "Test Connection".');
            state.running = false;
            enableSetup();
            return;
        }
        ajax('load_from_db', {
            creds_token:   state.credsToken,
            source_prefix: state.sourcePrefix,
            table_index:   state.tableIndex,
            dry_run:       state.dryRun ? 1 : 0,
        }, function (data) {
            state.tableIndex = data.table_index;
            setPhaseBar(0, data.percent);
            var status = data.table
                ? data.percent + '% — ' + data.table + ' (' + data.rows + ' rows)'
                : data.percent + '%';
            setPhaseStatus(0, status);

            if (data.complete) {
                state.phaseStats[0] = { tables_copied: data.tables_copied };
                setPhaseIcon(0, 'done');
                advancePhase();
            } else {
                runLoadFromDb();
            }
        }, function (msg) {
            setPhaseIcon(0, 'error');
            setPhaseStatus(0, msg);
            state.running = false;
            enableSetup();
        });
    }

    // Phases 1-5
    function runDataPhase() {
        ajax('phase', {
            phase:         state.phase,
            offset:        state.phaseOffset,
            dry_run:       state.dryRun ? 1 : 0,
            source_prefix: state.sourcePrefix,
        }, function (data) {
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
                runDataPhase();
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
