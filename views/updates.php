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

    <div class="mealsdb-historical-import">
        <h2><?php echo esc_html__('Historical Order Import', 'meals-db'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Tags existing WooCommerce orders with Meals DB client identifiers for SDNB and Veteran clients. Run once after initial setup.', 'meals-db'); ?>
        </p>
        <div class="mealsdb-historical-import-actions">
            <button type="button" class="button" id="mealsdb-historical-dry-run">
                <?php echo esc_html__('Start Dry Run', 'meals-db'); ?>
            </button>
            <button type="button" class="button button-primary" id="mealsdb-historical-run" disabled>
                <?php echo esc_html__('Run Import', 'meals-db'); ?>
            </button>
            <button type="button" class="button" id="mealsdb-historical-reset">
                <?php echo esc_html__('Reset', 'meals-db'); ?>
            </button>
        </div>
        <div id="mealsdb-historical-progress" style="display:none; margin-top:12px;">
            <div style="background:#e0e0e0; border-radius:3px; overflow:hidden; height:24px; max-width:500px;">
                <div id="mealsdb-historical-bar" style="background:#0073aa; height:100%; width:0%; transition:width .3s;"></div>
            </div>
            <p id="mealsdb-historical-percent" style="margin:4px 0;">0%</p>
        </div>
        <div id="mealsdb-historical-status" class="notice" style="display:none; margin-top:12px;"></div>
        <pre id="mealsdb-historical-log" style="display:none; max-height:200px; overflow:auto; background:#f5f5f5; padding:8px; margin-top:8px; font-size:12px;"></pre>
    </div>

    <div id="mealsdb-updates-status" class="notice notice-info" style="display:none;"></div>
    <pre id="mealsdb-updates-log" class="mealsdb-updates-log" style="display:none;"></pre>
</div>

<script>
(function($) {
    'use strict';

    var nonce   = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';
    var $dryBtn = $('#mealsdb-historical-dry-run');
    var $runBtn = $('#mealsdb-historical-run');
    var $rstBtn = $('#mealsdb-historical-reset');
    var $prog   = $('#mealsdb-historical-progress');
    var $bar    = $('#mealsdb-historical-bar');
    var $pct    = $('#mealsdb-historical-percent');
    var $status = $('#mealsdb-historical-status');
    var $log    = $('#mealsdb-historical-log');

    function setButtons(running) {
        $dryBtn.prop('disabled', running);
        $runBtn.prop('disabled', running);
        $rstBtn.prop('disabled', running);
    }

    function logLine(text) {
        $log.show().append(text + "\n").scrollTop($log[0].scrollHeight);
    }

    function showStatus(msg, type) {
        $status.show().removeClass('notice-info notice-success notice-error')
            .addClass('notice-' + type).html('<p>' + msg + '</p>');
    }

    function startImport(dryRun) {
        setButtons(true);
        $prog.show();
        $bar.css('width', '0%');
        $pct.text('0%');
        $log.empty().show();

        logLine(dryRun ? 'Starting dry run...' : 'Starting live import...');

        $.post(ajaxurl, { action: 'mealsdb_historical_import_start', nonce: nonce, dry_run: dryRun ? 1 : 0 }, function(res) {
            if (!res.success) {
                showStatus(res.message || 'Start failed.', 'error');
                setButtons(false);
                return;
            }
            logLine('Total orders: ' + res.total);
            processBatch(dryRun);
        }).fail(function() {
            showStatus('Request failed.', 'error');
            setButtons(false);
        });
    }

    function processBatch(dryRun) {
        $.post(ajaxurl, { action: 'mealsdb_historical_import_batch', nonce: nonce, dry_run: dryRun ? 1 : 0 }, function(res) {
            if (!res.success) {
                showStatus(res.message || 'Batch failed.', 'error');
                setButtons(false);
                return;
            }

            $bar.css('width', res.percent + '%');
            $pct.text(res.percent + '%');

            var line = 'Batch: processed=' + (res.processed || 0)
                + ' tagged=' + (res.tagged || 0)
                + ' already=' + (res.already_tagged || 0)
                + ' skipped=' + (res.skipped || 0)
                + ' errors=' + (res.errors || 0);
            logLine(line);

            if (res.complete) {
                var label = dryRun ? 'Dry run complete.' : 'Import complete.';
                showStatus(label, 'success');
                setButtons(false);
                if (dryRun) {
                    $runBtn.prop('disabled', false);
                }
                return;
            }

            processBatch(dryRun);
        }).fail(function() {
            showStatus('Request failed.', 'error');
            setButtons(false);
        });
    }

    $dryBtn.on('click', function() { startImport(true); });
    $runBtn.on('click', function() { startImport(false); });
    $rstBtn.on('click', function() {
        $.post(ajaxurl, { action: 'mealsdb_historical_import_reset', nonce: nonce }, function(res) {
            if (res.success) {
                showStatus('Progress reset.', 'info');
                $prog.hide();
                $log.hide().empty();
                $runBtn.prop('disabled', true);
            }
        });
    });
})(jQuery);
</script>
