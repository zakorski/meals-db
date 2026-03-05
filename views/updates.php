<?php
/**
 * Updates and maintenance screen.
 */

$repo_path = dirname(MEALS_DB_PLUGIN_FILE);
?>
<div id="mealsdb-updates" class="mealsdb-updates">
    <p class="description">
        <?php echo esc_html__('Use this screen to check for plugin updates from the configured Git repository and to run database maintenance that adds any new columns or indexes introduced in recent releases.', 'meals-db'); ?>
    </p>

    <table class="form-table mealsdb-updates-meta">
        <tbody>
            <tr>
                <th scope="row"><?php echo esc_html__('Plugin Directory', 'meals-db'); ?></th>
                <td><code><?php echo esc_html($repo_path); ?></code></td>
            </tr>
        </tbody>
    </table>

    <div class="mealsdb-update-actions">
        <form method="post" class="mealsdb-update-schema">
            <?php wp_nonce_field('mealsdb_update_schema', 'mealsdb_update_schema_nonce'); ?>
            <input type="hidden" name="mealsdb_action" value="update_schema">
            <button class="button button-primary"><?php echo esc_html__('Update Database Schema', 'meals-db'); ?></button>
        </form>
        <button type="button" class="button" id="mealsdb-fetch-products">
            <?php echo esc_html__('Fetch Products', 'meals-db'); ?>
        </button>
    </div>

    <div class="mealsdb-force-rebuild">
        <h2><?php echo esc_html__('Force Rebuild (External Database Only)', 'meals-db'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Drops and recreates all Meals DB external tables using the canonical schema. This action is destructive and does not affect WordPress tables.', 'meals-db'); ?>
        </p>
        <form method="post" class="mealsdb-force-rebuild-form">
            <?php wp_nonce_field('mealsdb_force_rebuild', 'mealsdb_force_rebuild_nonce'); ?>
            <input type="hidden" name="mealsdb_action" value="force_rebuild">
            <p>
                <label for="mealsdb_rebuild_confirm">
                    <?php echo esc_html__('Type REBUILD to confirm:', 'meals-db'); ?>
                </label>
                <input type="text" id="mealsdb_rebuild_confirm" name="mealsdb_rebuild_confirm" pattern="REBUILD" required placeholder="REBUILD" autocomplete="off" />
            </p>
            <p>
                <button class="button button-secondary" type="submit"><?php echo esc_html__('Force Rebuild External Schema', 'meals-db'); ?></button>
            </p>
        </form>
    </div>

    <div class="mealsdb-delete-nonadmin-users">
        <h2><?php echo esc_html__('Delete Non-Admin Users', 'meals-db'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Permanently deletes all WordPress users who are not administrators, along with their metadata. This action is destructive and cannot be undone.', 'meals-db'); ?>
        </p>
        <form method="post" class="mealsdb-delete-nonadmin-users-form">
            <?php wp_nonce_field('mealsdb_delete_nonadmin_users', 'mealsdb_delete_nonadmin_users_nonce'); ?>
            <input type="hidden" name="mealsdb_action" value="delete_nonadmin_users">
            <p>
                <label for="mealsdb_delete_confirm">
                    <?php echo esc_html__('Type DELETE to confirm:', 'meals-db'); ?>
                </label>
                <input type="text" id="mealsdb_delete_confirm" name="mealsdb_delete_confirm" pattern="DELETE" required placeholder="DELETE" autocomplete="off" />
            </p>
            <p>
                <button class="button button-secondary" type="submit"><?php echo esc_html__('Delete Non-Admin Users', 'meals-db'); ?></button>
            </p>
        </form>
    </div>

    <div class="mealsdb-db-sync">
        <h2><?php echo esc_html__('Complete DB Sync', 'meals-db'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Scans existing WordPress users tagged as SDNB, Veterans, or Extra Mural (via the customer_group usermeta) and creates corresponding records in the external Meals DB — including encrypted client data, delivery initials, and default billing rates. Skips users that already have a meals_clients record.', 'meals-db'); ?>
        </p>
        <div class="mealsdb-db-sync-actions" style="margin-top:12px;">
            <label style="margin-right:12px;">
                <input type="checkbox" id="mealsdb-sync-dry-run" checked>
                <?php echo esc_html__('Dry run (preview only)', 'meals-db'); ?>
            </label>
            <button type="button" class="button button-primary" id="mealsdb-sync-start">
                <?php echo esc_html__('Start Sync', 'meals-db'); ?>
            </button>
            <button type="button" class="button" id="mealsdb-sync-reset" style="display:none;">
                <?php echo esc_html__('Reset', 'meals-db'); ?>
            </button>
        </div>
        <div id="mealsdb-sync-progress" style="display:none; margin-top:12px;">
            <div class="mealsdb-sync-phases"></div>
            <div id="mealsdb-sync-current" style="margin-top:8px; font-size:13px; color:#666;"></div>
        </div>
        <div id="mealsdb-sync-status" class="notice" style="display:none; margin-top:12px;"></div>
        <pre id="mealsdb-sync-log" style="display:none; max-height:200px; overflow:auto; background:#f5f5f5; padding:8px; margin-top:8px; font-size:12px;"></pre>
    </div>

    <div id="mealsdb-updates-status" class="notice notice-info" style="display:none;"></div>
    <pre id="mealsdb-updates-log" class="mealsdb-updates-log" style="display:none;"></pre>
</div>

<style>
.mealsdb-sync-phase {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 0;
}
.mealsdb-sync-phase-icon {
    width: 20px;
    text-align: center;
    font-size: 14px;
}
.mealsdb-sync-phase-icon.running { color: #0073aa; }
.mealsdb-sync-phase-icon.done { color: #46b450; font-weight: bold; }
.mealsdb-sync-phase-icon.error { color: #dc3232; font-weight: bold; }
.mealsdb-sync-phase-bar-wrap {
    flex: 1;
    max-width: 300px;
    height: 12px;
    background: #e0e0e0;
    border-radius: 6px;
    overflow: hidden;
}
.mealsdb-sync-phase-bar {
    height: 100%;
    width: 0;
    background: #0073aa;
    border-radius: 6px;
    transition: width 0.3s;
}
.mealsdb-sync-phase-status {
    font-size: 12px;
    color: #666;
}
</style>

<script>
(function($) {
    'use strict';

    var nonce     = '<?php echo esc_js(wp_create_nonce('mealsdb_db_sync_nonce')); ?>';
    var running   = false;
    var phase     = 0; // 0 = clients, 1 = rates
    var offset    = 0;
    var dryRun    = true;
    var stats     = {};

    var phases = [
        { key: 'clients', label: 'Create Meals Clients' },
        { key: 'rates',   label: 'Create Client Rates' },
    ];

    // Build phase UI
    var $phases = $('.mealsdb-sync-phases');
    $.each(phases, function(i, p) {
        $phases.append(
            '<div class="mealsdb-sync-phase" id="sync-phase-' + i + '">' +
                '<span class="mealsdb-sync-phase-icon">&#9711;</span>' +
                '<span><strong>' + p.label + '</strong></span>' +
                '<div class="mealsdb-sync-phase-bar-wrap"><div class="mealsdb-sync-phase-bar"></div></div>' +
                '<span class="mealsdb-sync-phase-status"></span>' +
            '</div>'
        );
    });

    function setIcon(i, state) {
        var map = { pending: '&#9711;', running: '&#9881;', done: '&#10003;', error: '&#10007;' };
        $('#sync-phase-' + i + ' .mealsdb-sync-phase-icon')
            .html(map[state]).removeClass('pending running done error').addClass(state);
    }

    function setBar(i, pct) {
        $('#sync-phase-' + i + ' .mealsdb-sync-phase-bar').css('width', pct + '%');
    }

    function setStatus(i, text) {
        $('#sync-phase-' + i + ' .mealsdb-sync-phase-status').text(text);
    }

    function logLine(text) {
        var $log = $('#mealsdb-sync-log');
        $log.show().append(text + "\n").scrollTop($log[0].scrollHeight);
    }

    function showNotice(msg, type) {
        $('#mealsdb-sync-status').show()
            .removeClass('notice-info notice-success notice-error')
            .addClass('notice-' + type).html('<p>' + msg + '</p>');
    }

    // Start
    $('#mealsdb-sync-start').on('click', function() {
        if (running) return;
        running  = true;
        dryRun   = $('#mealsdb-sync-dry-run').is(':checked');
        phase    = 0;
        offset   = 0;
        stats    = {};

        $(this).prop('disabled', true);
        $('#mealsdb-sync-dry-run').prop('disabled', true);
        $('#mealsdb-sync-reset').hide();
        $('#mealsdb-sync-progress').show();
        $('#mealsdb-sync-status').hide();
        $('#mealsdb-sync-log').empty().hide();

        for (var i = 0; i < phases.length; i++) {
            setIcon(i, 'pending');
            setBar(i, 0);
            setStatus(i, '');
        }

        logLine(dryRun ? 'Starting dry run...' : 'Starting live sync...');
        runPhase();
    });

    function runPhase() {
        if (!running) return;
        setIcon(phase, 'running');

        // Map phase index to migration phase number (4 = clients, 5 = rates)
        var migPhase = phase === 0 ? 4 : 5;

        $.post(ajaxurl, {
            action: 'mealsdb_db_sync_phase',
            nonce:  nonce,
            phase:  migPhase,
            offset: offset,
            dry_run: dryRun ? 1 : 0,
        }, function(resp) {
            if (!resp.success) {
                setIcon(phase, 'error');
                setStatus(phase, resp.data && resp.data.message ? resp.data.message : 'Error');
                showNotice('Sync failed: ' + (resp.data && resp.data.message || 'Unknown error'), 'error');
                finish();
                return;
            }

            var data = resp.data;

            // Accumulate stats
            if (data.stats) {
                if (!stats[phase]) stats[phase] = {};
                $.each(data.stats, function(k, v) {
                    stats[phase][k] = (stats[phase][k] || 0) + v;
                });
            }

            var pct = data.total > 0 ? Math.min(100, Math.round((data.offset / data.total) * 100)) : 100;
            setBar(phase, pct);
            setStatus(phase, pct + '%');

            // Log batch info
            if (data.stats) {
                var parts = [];
                $.each(data.stats, function(k, v) { parts.push(k + '=' + v); });
                logLine(phases[phase].label + ' batch: ' + parts.join(', '));
            }

            if (data.complete) {
                setIcon(phase, 'done');
                setBar(phase, 100);

                // Log phase totals
                if (stats[phase]) {
                    var totals = [];
                    $.each(stats[phase], function(k, v) { totals.push(k + ': ' + v); });
                    logLine(phases[phase].label + ' complete — ' + totals.join(', '));
                }

                if (phase < phases.length - 1) {
                    phase++;
                    offset = 0;
                    runPhase();
                } else {
                    var msg = dryRun
                        ? 'Dry run complete. No data was written. Uncheck "Dry run" and run again to sync.'
                        : 'Sync complete. All government clients and rates have been created.';
                    showNotice(msg, 'success');
                    finish();
                }
            } else {
                offset = data.offset;
                runPhase();
            }
        }).fail(function() {
            setIcon(phase, 'error');
            showNotice('Network error during sync.', 'error');
            finish();
        });
    }

    function finish() {
        running = false;
        $('#mealsdb-sync-start').prop('disabled', false);
        $('#mealsdb-sync-dry-run').prop('disabled', false);
        $('#mealsdb-sync-reset').show();
    }

    // Reset
    $('#mealsdb-sync-reset').on('click', function() {
        $('#mealsdb-sync-progress').hide();
        $('#mealsdb-sync-status').hide();
        $('#mealsdb-sync-log').hide().empty();
        $(this).hide();
        for (var i = 0; i < phases.length; i++) {
            setIcon(i, 'pending');
            setBar(i, 0);
            setStatus(i, '');
        }
    });

})(jQuery);
</script>
