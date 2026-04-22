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

$base_url = admin_url('admin.php?page=mealsdb&tab=tasks');

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
                    <td><?php echo esc_html(sprintf('%s #%d', $task['related_entity_type'], (int) $task['related_entity_id'])); ?></td></tr>
            <?php endif; ?>
            <?php if ((int) $task['deferral_count'] > 0): ?>
                <tr><th><?php esc_html_e('Deferral count', 'meals-db'); ?></th>
                    <td><?php echo (int) $task['deferral_count']; ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($task['completed_at'])): ?>
                <tr><th><?php esc_html_e('Completed', 'meals-db'); ?></th>
                    <td><?php echo esc_html($task['completed_at']); ?>
                    <?php if ((int) $task['completed_by'] > 0): ?>
                        (<?php echo esc_html((string) get_user_by('id', (int) $task['completed_by'])->display_name ?? ''); ?>)
                    <?php endif; ?>
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

<?php if ($definition !== null && !in_array($task['status'], MealsDB_Task_Engine::TERMINAL_STATUSES, true)): ?>
<script>
(function($) {
    'use strict';
    var nonce   = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';
    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var taskId  = <?php echo (int) $task['task_id']; ?>;
    var backUrl = <?php echo wp_json_encode($base_url); ?>;
    var schema  = <?php echo wp_json_encode($form_schema); ?>;
    var payload = <?php echo wp_json_encode($task['payload']); ?>;

    if (window.MealsDBTaskForm) {
        window.MealsDBTaskForm.render('#mealsdb-task-form', schema, payload);
    }

    function setStatus(msg, type) {
        var $s = $('#mealsdb-task-detail-status');
        $s.removeClass('notice-success notice-error notice-warning').addClass('notice-' + (type || 'info'));
        $s.html('<p>' + msg + '</p>').show();
    }

    $('#mealsdb-task-complete').on('click', function() {
        if (!window.MealsDBTaskForm) return;
        var data = window.MealsDBTaskForm.collect('#mealsdb-task-form', schema);
        $.post(ajaxUrl, {
            action: 'mealsdb_tasks_complete',
            nonce: nonce,
            task_id: taskId,
            form_data: JSON.stringify(data)
        }).done(function(resp) {
            if (resp && resp.success) {
                window.location.href = backUrl;
            } else {
                setStatus((resp && resp.data && resp.data.message) || 'Failed to complete.', 'error');
            }
        }).fail(function(xhr) {
            setStatus((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Failed to complete.', 'error');
        });
    });

    function defer(newDate) {
        $.post(ajaxUrl, {
            action: 'mealsdb_tasks_defer',
            nonce: nonce,
            task_id: taskId,
            new_date: newDate || ''
        }).done(function(resp) {
            if (resp && resp.success) {
                window.location.href = backUrl;
            } else {
                setStatus((resp && resp.data && resp.data.message) || 'Failed to defer.', 'error');
            }
        }).fail(function() {
            setStatus('Failed to defer.', 'error');
        });
    }

    $('#mealsdb-task-defer-1').on('click', function() { defer(''); });
    $('#mealsdb-task-defer-custom').on('click', function() {
        var d = $('#mealsdb-task-defer-date').val();
        if (!d) { setStatus('Pick a date first.', 'warning'); return; }
        defer(d);
    });

    $('#mealsdb-task-skip').on('click', function() {
        if (!confirm('<?php echo esc_js(__('Skip this task?', 'meals-db')); ?>')) return;
        $.post(ajaxUrl, {
            action: 'mealsdb_tasks_skip',
            nonce: nonce,
            task_id: taskId
        }).done(function(resp) {
            if (resp && resp.success) {
                window.location.href = backUrl;
            } else {
                setStatus((resp && resp.data && resp.data.message) || 'Failed to skip.', 'error');
            }
        }).fail(function() {
            setStatus('Failed to skip.', 'error');
        });
    });
})(jQuery);
</script>
<?php endif; ?>
