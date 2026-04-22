<?php
/**
 * Task list view — top-level "Tasks" tab.
 *
 * Top controls: date-view toggle, role/type/tag filters, bulk actions,
 * link to Schedule Rules. Grouped by assignee_role when role is "all".
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

$types = MealsDB_Task_Registry::get_all();
$view  = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : 'today';
$role  = isset($_GET['role']) ? sanitize_key(wp_unslash((string) $_GET['role'])) : 'all';
$type_filter = isset($_GET['task_type']) ? sanitize_key(wp_unslash((string) $_GET['task_type'])) : '';
$include_completed = !empty($_GET['include_completed']);
$include_skipped   = !empty($_GET['include_skipped']);

$filters = [
    'status' => array_merge(
        ['pending', 'in_progress', 'deferred'],
        $include_completed ? ['completed'] : [],
        $include_skipped ? ['skipped'] : []
    ),
];

try {
    $today = (new DateTimeImmutable('now', MealsDB_Task_Rules::site_timezone()))->format('Y-m-d');
} catch (Throwable $e) {
    $today = gmdate('Y-m-d');
}
if ($view === 'today') {
    $filters['next_run_date_before'] = $today;
} elseif ($view === 'week') {
    try {
        $week_end = (new DateTimeImmutable('now', MealsDB_Task_Rules::site_timezone()))
            ->modify('+7 days')->format('Y-m-d');
    } catch (Throwable $e) {
        $week_end = gmdate('Y-m-d', strtotime('+7 days'));
    }
    $filters['next_run_date_before'] = $week_end;
}
if ($role !== 'all') {
    $filters['assignee_role'] = $role;
}
if ($type_filter !== '') {
    $filters['task_type'] = $type_filter;
}

$engine = new MealsDB_Task_Engine();
$tasks = $engine->query_tasks($filters);

// Group by assignee_role when showing "all".
$grouped = [];
if ($role === 'all') {
    foreach ($tasks as $t) {
        $key = $t['assignee_role'] ?: '(unassigned)';
        $grouped[$key][] = $t;
    }
    ksort($grouped);
} else {
    $grouped[$role] = $tasks;
}

$base_url = admin_url('admin.php?page=mealsdb&tab=tasks');
?>
<div id="mealsdb-tasks-list" class="mealsdb-tasks-list">
    <div style="margin-bottom:16px; display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label><strong><?php esc_html_e('Date view:', 'meals-db'); ?></strong></label><br>
            <?php foreach (['today' => __('Today', 'meals-db'), 'week' => __('This Week', 'meals-db'), 'all' => __('All Open', 'meals-db')] as $key => $label): ?>
                <a class="button <?php echo $view === $key ? 'button-primary' : ''; ?>"
                   href="<?php echo esc_url(add_query_arg(['view' => $key, 'role' => $role, 'task_type' => $type_filter], $base_url)); ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div>
            <label for="mealsdb-task-role"><strong><?php esc_html_e('Assignee:', 'meals-db'); ?></strong></label><br>
            <select id="mealsdb-task-role" onchange="window.location.href=this.value">
                <?php foreach (['all' => __('All roles', 'meals-db'), 'phone' => __('Phone', 'meals-db'), 'warehouse' => __('Warehouse', 'meals-db'), 'admin' => __('Admin', 'meals-db')] as $key => $label): ?>
                    <option value="<?php echo esc_url(add_query_arg(['view' => $view, 'role' => $key, 'task_type' => $type_filter], $base_url)); ?>"
                        <?php selected($role, $key); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="mealsdb-task-type"><strong><?php esc_html_e('Type:', 'meals-db'); ?></strong></label><br>
            <select id="mealsdb-task-type" onchange="window.location.href=this.value">
                <option value="<?php echo esc_url(add_query_arg(['view' => $view, 'role' => $role, 'task_type' => ''], $base_url)); ?>">
                    <?php esc_html_e('All types', 'meals-db'); ?>
                </option>
                <?php foreach ($types as $type_id => $def): ?>
                    <option value="<?php echo esc_url(add_query_arg(['view' => $view, 'role' => $role, 'task_type' => $type_id], $base_url)); ?>"
                        <?php selected($type_filter, $type_id); ?>>
                        <?php echo esc_html($def['label'] ?? $type_id); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <a class="button" href="<?php echo esc_url(add_query_arg(['action' => 'rules'], $base_url)); ?>">
                <?php esc_html_e('Schedule Rules', 'meals-db'); ?>
            </a>
        </div>
        <div style="margin-left:auto;">
            <button type="button" class="button" id="mealsdb-tasks-bulk-skip" disabled>
                <?php esc_html_e('Skip Selected', 'meals-db'); ?>
            </button>
            <button type="button" class="button" id="mealsdb-tasks-bulk-defer" disabled>
                <?php esc_html_e('Defer Selected +1d', 'meals-db'); ?>
            </button>
        </div>
    </div>

    <div id="mealsdb-tasks-status" class="notice" style="display:none;"></div>

    <?php if (empty($tasks)): ?>
        <p><em><?php esc_html_e('No tasks match the current filters.', 'meals-db'); ?></em></p>
    <?php else: ?>
        <?php foreach ($grouped as $group_key => $group_tasks): ?>
            <h3><?php echo esc_html(ucfirst($group_key)); ?>
                <small>(<?php echo (int) count($group_tasks); ?>)</small></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" class="mealsdb-select-all"></th>
                        <th style="width:40px;"><?php esc_html_e('Urg', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Type', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Related', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Due', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Status', 'meals-db'); ?></th>
                        <th><?php esc_html_e('Actions', 'meals-db'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($group_tasks as $task): ?>
                        <?php
                        $type_def = MealsDB_Task_Registry::get($task['task_type']);
                        $type_label = $type_def['label'] ?? $task['task_type'];
                        $urgency_color = ['routine' => '#ccc', 'follow_up' => '#f39c12', 'escalated' => '#e74c3c'][$task['urgency']] ?? '#ccc';
                        $date_color = '';
                        if ($task['next_run_date'] < $today) {
                            $date_color = 'color:#e74c3c;font-weight:bold;';
                        } elseif ($task['next_run_date'] === $today) {
                            $date_color = 'color:#b8860b;font-weight:bold;';
                        }
                        $detail_url = add_query_arg(['action' => 'detail', 'task_id' => (int) $task['task_id']], $base_url);
                        ?>
                        <tr data-task-id="<?php echo (int) $task['task_id']; ?>">
                            <td><input type="checkbox" class="mealsdb-task-select" value="<?php echo (int) $task['task_id']; ?>"></td>
                            <td><span title="<?php echo esc_attr($task['urgency']); ?>" style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr($urgency_color); ?>;"></span></td>
                            <td><?php echo esc_html($type_label); ?></td>
                            <td>
                                <?php
                                $related_label = '';
                                if (!empty($task['related_entity_type']) && !empty($task['related_entity_id'])) {
                                    $related_label = sprintf('%s #%d', $task['related_entity_type'], $task['related_entity_id']);
                                } elseif (!empty($task['payload']['description'])) {
                                    $related_label = wp_trim_words((string) $task['payload']['description'], 6);
                                }
                                echo esc_html($related_label);
                                ?>
                            </td>
                            <td style="<?php echo esc_attr($date_color); ?>">
                                <?php echo esc_html($task['next_run_date']); ?>
                                <?php if ((int) $task['deferral_count'] > 0): ?>
                                    <small>(<?php echo (int) $task['deferral_count']; ?>x deferred)</small>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html($task['status']); ?></code></td>
                            <td>
                                <a class="button button-small button-primary" href="<?php echo esc_url($detail_url); ?>">
                                    <?php esc_html_e('Open', 'meals-db'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
(function($) {
    'use strict';

    var nonce = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';
    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

    function collectSelected() {
        return $('.mealsdb-task-select:checked').map(function() { return this.value; }).get();
    }

    function refreshBulkState() {
        var any = collectSelected().length > 0;
        $('#mealsdb-tasks-bulk-skip,#mealsdb-tasks-bulk-defer').prop('disabled', !any);
    }

    $('.mealsdb-select-all').on('change', function() {
        var checked = this.checked;
        $(this).closest('table').find('.mealsdb-task-select').prop('checked', checked);
        refreshBulkState();
    });

    $(document).on('change', '.mealsdb-task-select', refreshBulkState);

    $('#mealsdb-tasks-bulk-skip').on('click', function() {
        var ids = collectSelected();
        if (!ids.length) return;
        if (!confirm('<?php echo esc_js(__('Skip selected tasks?', 'meals-db')); ?>')) return;
        $.post(ajaxUrl, {
            action: 'mealsdb_tasks_bulk_skip',
            nonce: nonce,
            task_ids: JSON.stringify(ids)
        }).done(function(resp) {
            window.location.reload();
        }).fail(function() {
            alert('<?php echo esc_js(__('Bulk skip failed.', 'meals-db')); ?>');
        });
    });

    $('#mealsdb-tasks-bulk-defer').on('click', function() {
        var ids = collectSelected();
        if (!ids.length) return;
        $.post(ajaxUrl, {
            action: 'mealsdb_tasks_bulk_defer',
            nonce: nonce,
            task_ids: JSON.stringify(ids)
        }).done(function(resp) {
            window.location.reload();
        }).fail(function() {
            alert('<?php echo esc_js(__('Bulk defer failed.', 'meals-db')); ?>');
        });
    });
})(jQuery);
</script>
