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

    <div id="mealsdb-updates-status" class="notice notice-info" style="display:none;"></div>
    <pre id="mealsdb-updates-log" class="mealsdb-updates-log" style="display:none;"></pre>
</div>
