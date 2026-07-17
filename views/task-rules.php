<?php
/**
 * Schedule rules view — list + edit form.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

$rules_service = new MealsDB_Task_Rules();
$rules = $rules_service->list_rules();
$types = MealsDB_Task_Registry::get_all();

$base_url = admin_url('admin.php?page=mealsdb-tasks');
?>
<div id="mealsdb-task-rules" class="mealsdb-task-rules">
    <p>
        <a class="button" href="<?php echo esc_url($base_url); ?>">&larr;
            <?php esc_html_e('Back to task list', 'meals-db'); ?>
        </a>
    </p>

    <h2><?php esc_html_e('Schedule Rules', 'meals-db'); ?></h2>

    <?php if (!current_user_can('manage_options')): ?>
        <div class="notice notice-warning"><p>
            <?php esc_html_e('You can view rules but not edit them.', 'meals-db'); ?>
        </p></div>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Name', 'meals-db'); ?></th>
                <th><?php esc_html_e('Task Type', 'meals-db'); ?></th>
                <th><?php esc_html_e('Recurrence', 'meals-db'); ?></th>
                <th><?php esc_html_e('Next Run', 'meals-db'); ?></th>
                <th><?php esc_html_e('Last Run', 'meals-db'); ?></th>
                <th><?php esc_html_e('Active', 'meals-db'); ?></th>
                <th><?php esc_html_e('Actions', 'meals-db'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rules)): ?>
                <tr><td colspan="7"><em><?php esc_html_e('No schedule rules yet.', 'meals-db'); ?></em></td></tr>
            <?php else: ?>
                <?php foreach ($rules as $rule): ?>
                    <tr data-rule-id="<?php echo (int) $rule['rule_id']; ?>">
                        <td><?php echo esc_html((string) $rule['name']); ?></td>
                        <td><?php echo esc_html((string) $rule['task_type']); ?></td>
                        <td><code><?php echo esc_html((string) wp_json_encode($rule['recurrence'])); ?></code></td>
                        <td><?php echo esc_html((string) ($rule['next_run_at'] ?? '—')); ?></td>
                        <td><?php echo esc_html((string) ($rule['last_run_at'] ?? '—')); ?></td>
                        <td><?php echo ((int) $rule['is_active']) === 1 ? '✓' : '—'; ?></td>
                        <td>
                            <?php if (current_user_can('manage_options')): ?>
                                <button type="button" class="button button-small mealsdb-rule-run-now" data-rule-id="<?php echo (int) $rule['rule_id']; ?>">
                                    <?php esc_html_e('Run now', 'meals-db'); ?>
                                </button>
                                <button type="button" class="button button-small mealsdb-rule-delete" data-rule-id="<?php echo (int) $rule['rule_id']; ?>">
                                    <?php esc_html_e('Delete', 'meals-db'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if (current_user_can('manage_options')): ?>
        <h3><?php esc_html_e('Create a new rule', 'meals-db'); ?></h3>
        <form id="mealsdb-rule-form">
            <table class="form-table">
                <tbody>
                    <tr><th><label for="mealsdb-rule-name"><?php esc_html_e('Name', 'meals-db'); ?></label></th>
                        <td><input type="text" id="mealsdb-rule-name" required style="width:100%;max-width:400px;"></td></tr>
                    <tr><th><label for="mealsdb-rule-type"><?php esc_html_e('Task Type', 'meals-db'); ?></label></th>
                        <td>
                            <select id="mealsdb-rule-type" required>
                                <?php foreach ($types as $id => $def): ?>
                                    <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($def['label'] ?? $id); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                    <tr><th><label for="mealsdb-rule-spawn"><?php esc_html_e('Spawn Type', 'meals-db'); ?></label></th>
                        <td>
                            <select id="mealsdb-rule-spawn">
                                <option value="fixed">fixed</option>
                                <option value="query">query</option>
                            </select>
                        </td></tr>
                    <tr><th><label for="mealsdb-rule-recurrence"><?php esc_html_e('Recurrence (JSON)', 'meals-db'); ?></label></th>
                        <td><textarea id="mealsdb-rule-recurrence" rows="4" style="width:100%;font-family:monospace;" required>{"type":"weekly","interval":1,"days_of_week":["monday"],"time":"08:00"}</textarea></td></tr>
                    <tr><th><label for="mealsdb-rule-payload"><?php esc_html_e('Payload Template (JSON)', 'meals-db'); ?></label></th>
                        <td><textarea id="mealsdb-rule-payload" rows="4" style="width:100%;font-family:monospace;" required>{"description":"Weekly reminder"}</textarea></td></tr>
                    <tr><th><label for="mealsdb-rule-criteria"><?php esc_html_e('Query Criteria (JSON, optional)', 'meals-db'); ?></label></th>
                        <td><textarea id="mealsdb-rule-criteria" rows="3" style="width:100%;font-family:monospace;"></textarea></td></tr>
                    <tr><th><label for="mealsdb-rule-tags"><?php esc_html_e('Tags (comma-separated)', 'meals-db'); ?></label></th>
                        <td><input type="text" id="mealsdb-rule-tags" style="width:100%;max-width:400px;"></td></tr>
                    <tr><th><label for="mealsdb-rule-role"><?php esc_html_e('Assignee Role', 'meals-db'); ?></label></th>
                        <td>
                            <select id="mealsdb-rule-role">
                                <option value="">(default)</option>
                                <option value="phone">phone</option>
                                <option value="warehouse">warehouse</option>
                                <option value="admin">admin</option>
                            </select>
                        </td></tr>
                </tbody>
            </table>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Create Rule', 'meals-db'); ?></button>
            </p>
        </form>
    <?php endif; ?>

    <div id="mealsdb-rules-status" class="notice" style="display:none;margin-top:12px;"></div>
</div>

<?php
// Server data for assets/js/task-rules.js. JSON island (not esc_js) — the
// JSON_HEX_* flags neutralise <, >, &, ', " so it is safe inside <script>.
$island_data = array(
    'nonce'   => wp_create_nonce('mealsdb_nonce'),
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'i18n'    => array(
        'confirmDelete' => __('Delete this rule? Existing spawned tasks will remain.', 'meals-db'),
        'invalidJson'   => __('Invalid JSON: %s', 'meals-db'),
        'createFailed'  => __('Create failed.', 'meals-db'),
        'created'       => __('Created %d tasks.', 'meals-db'),
        'runNowFailed'  => __('Run-now failed.', 'meals-db'),
        'deleteFailed'  => __('Delete failed.', 'meals-db'),
    ),
);
?>
<script type="application/json" id="mealsdb-task-rules-data"><?php echo wp_json_encode($island_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
