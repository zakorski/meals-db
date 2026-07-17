<?php
/**
 * Task detail view — single-task page with form-driven completion flow.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

$task_id = isset($_GET['task_id']) ? (int) $_GET['task_id'] : 0;
$engine = new MealsDB_Task_Engine();
$task = $engine->get_task($task_id);

$base_url = admin_url('admin.php?page=mealsdb-tasks');

if ($task === null) {
    echo '<div class="notice notice-error"><p>' . esc_html__('Task not found.', 'meals-db') . '</p></div>';
    echo '<p><a class="button" href="' . esc_url($base_url) . '">' . esc_html__('Back to list', 'meals-db') . '</a></p>';
    return;
}

$definition = MealsDB_Task_Registry::get($task['task_type']);
$type_label = $definition['label'] ?? $task['task_type'];
$form_schema = is_array($definition['form_schema'] ?? null) ? $definition['form_schema'] : [];
?>
<div id="mealsdb-task-detail" class="mealsdb-task-detail">
    <p>
        <a class="button" href="<?php echo esc_url($base_url); ?>">&larr;
            <?php esc_html_e('Back to task list', 'meals-db'); ?>
        </a>
    </p>

    <h2><?php echo esc_html($type_label); ?> #<?php echo (int) $task['task_id']; ?></h2>

    <table class="form-table" role="presentation">
        <tbody>
            <tr><th><?php esc_html_e('Status', 'meals-db'); ?></th>
                <td><code><?php echo esc_html($task['status']); ?></code></td></tr>
            <tr><th><?php esc_html_e('Due Date', 'meals-db'); ?></th>
                <td><?php echo esc_html($task['next_run_date']); ?></td></tr>
            <tr><th><?php esc_html_e('Urgency', 'meals-db'); ?></th>
                <td><?php echo esc_html($task['urgency']); ?></td></tr>
            <tr><th><?php esc_html_e('Assignee Role', 'meals-db'); ?></th>
                <td><?php echo esc_html((string) $task['assignee_role']); ?></td></tr>
            <?php if (!empty($task['related_entity_type'])): ?>
                <tr><th><?php esc_html_e('Related', 'meals-db'); ?></th>
                    <td><?php
                    $mealsdb_rel_label = sprintf('%s #%d', $task['related_entity_type'], (int) $task['related_entity_id']);
                    if ($task['related_entity_type'] === 'po' && (int) $task['related_entity_id'] > 0) {
                        // Deep link to the PO workflow page (task-integration §6).
                        $mealsdb_po_url = admin_url('admin.php?page=mealsdb-purchase-orders&po_id=' . (int) $task['related_entity_id']);
                        echo '<a href="' . esc_url($mealsdb_po_url) . '">' . esc_html($mealsdb_rel_label) . '</a>';
                    } else {
                        echo esc_html($mealsdb_rel_label);
                    }
                    ?></td></tr>
            <?php endif; ?>
            <?php if ((int) $task['deferral_count'] > 0): ?>
                <tr><th><?php esc_html_e('Deferral count', 'meals-db'); ?></th>
                    <td><?php echo (int) $task['deferral_count']; ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($task['completed_at'])): ?>
                <tr><th><?php esc_html_e('Completed', 'meals-db'); ?></th>
                    <td><?php echo esc_html($task['completed_at']); ?>
                    <?php
                    // get_user_by() returns WP_User|false; a since-deleted
                    // completer yields false, so guard before reading
                    // ->display_name (avoids a PHP 8 "read property on bool"
                    // warning). No user => render nothing rather than empty ().
                    if ((int) $task['completed_by'] > 0):
                        $completed_by_user = get_user_by('id', (int) $task['completed_by']);
                        if ($completed_by_user):
                    ?>
                        (<?php echo esc_html((string) $completed_by_user->display_name); ?>)
                    <?php endif; endif; ?>
                    </td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($definition === null): ?>
        <div class="notice notice-warning"><p>
            <?php echo esc_html(sprintf(
                /* translators: %s: task_type slug */
                __('No task type registered for "%s". Complete/defer/skip disabled.', 'meals-db'),
                $task['task_type']
            )); ?>
        </p></div>
    <?php elseif (!in_array($task['status'], MealsDB_Task_Engine::TERMINAL_STATUSES, true)): ?>
        <h3><?php esc_html_e('Form', 'meals-db'); ?></h3>
        <div id="mealsdb-task-form" data-task-id="<?php echo (int) $task['task_id']; ?>"></div>

        <p>
            <button type="button" class="button button-primary" id="mealsdb-task-complete">
                <?php esc_html_e('Complete', 'meals-db'); ?>
            </button>
            <button type="button" class="button" id="mealsdb-task-defer-1">
                <?php esc_html_e('Defer to tomorrow', 'meals-db'); ?>
            </button>
            <input type="date" id="mealsdb-task-defer-date" style="margin-left:8px;">
            <button type="button" class="button" id="mealsdb-task-defer-custom">
                <?php esc_html_e('Defer to date…', 'meals-db'); ?>
            </button>
            <button type="button" class="button button-secondary" id="mealsdb-task-skip">
                <?php esc_html_e('Skip', 'meals-db'); ?>
            </button>
        </p>
    <?php else: ?>
        <h3><?php esc_html_e('Form Submission', 'meals-db'); ?></h3>
        <pre style="background:#f7f7f7;padding:12px;border:1px solid #ddd;"><?php
            echo esc_html(wp_json_encode($task['payload'], JSON_PRETTY_PRINT));
        ?></pre>
    <?php endif; ?>

    <div id="mealsdb-task-detail-status" class="notice" style="display:none;margin-top:12px;"></div>
</div>

<?php if ($definition !== null && !in_array($task['status'], MealsDB_Task_Engine::TERMINAL_STATUSES, true)):
    // Server data for assets/js/task-detail.js. Structured arrays (formSchema,
    // payload) are emitted as real JSON — do NOT stringify twice. The JSON_HEX_*
    // flags keep this safe inside a <script> tag; this is JSON data, not JS
    // source, so esc_js is deliberately NOT used here.
    $island_data = [
        'nonce'      => wp_create_nonce('mealsdb_nonce'),
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'taskId'     => (int) $task['task_id'],
        'baseUrl'    => $base_url,
        'formSchema' => $form_schema,
        'payload'    => $task['payload'],
        'i18n'       => [
            'failComplete' => __('Failed to complete.', 'meals-db'),
            'failDefer'    => __('Failed to defer.', 'meals-db'),
            'pickDate'     => __('Pick a date first.', 'meals-db'),
            'skipConfirm'  => __('Skip this task?', 'meals-db'),
            'failSkip'     => __('Failed to skip.', 'meals-db'),
        ],
    ];
?>
<script type="application/json" id="mealsdb-task-detail-data"><?php echo wp_json_encode($island_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php endif; ?>
