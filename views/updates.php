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
        <button type="button" class="button button-secondary" id="mealsdb-check-updates">
            <?php echo esc_html__('Check for Updates', 'meals-db'); ?>
        </button>
        <button type="button" class="button button-secondary" id="mealsdb-run-update" style="display:none;">
            <?php echo esc_html__('Pull Latest Changes', 'meals-db'); ?>
        </button>
        <button type="button" class="button button-primary" id="mealsdb-update-database">
            <?php echo esc_html__('Update Database', 'meals-db'); ?>
        </button>
    </div>

    <div id="mealsdb-updates-status" class="notice notice-info" style="display:none;"></div>
    <pre id="mealsdb-updates-log" class="mealsdb-updates-log" style="display:none;"></pre>
</div>
