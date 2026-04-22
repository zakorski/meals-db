<?php
/**
 * Dashboard task widget — counts of today's tasks by assignee role plus
 * an overdue indicator. Included from views/dashboard.php.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

if (!class_exists('MealsDB_Task_Engine')) {
    return;
}

try {
    $today = (new DateTimeImmutable('now', MealsDB_Task_Rules::site_timezone()))->format('Y-m-d');
} catch (Throwable $e) {
    $today = gmdate('Y-m-d');
}

$engine = new MealsDB_Task_Engine();

$today_tasks = $engine->query_tasks([
    'status'                => ['pending', 'in_progress', 'deferred'],
    'next_run_date_before'  => $today,
    'limit'                 => 500,
]);

$by_role = [];
$overdue = 0;
foreach ($today_tasks as $t) {
    $role = $t['assignee_role'] ?: '(unassigned)';
    if (!isset($by_role[$role])) {
        $by_role[$role] = 0;
    }
    $by_role[$role]++;
    if ($t['next_run_date'] < $today) {
        $overdue++;
    }
}

$tasks_url = admin_url('admin.php?page=mealsdb&tab=tasks');
?>
<div class="mealsdb-dashboard-tasks" style="margin:16px 0; padding:12px 16px; background:#fff; border:1px solid #ccd0d4; border-left:4px solid #2271b1;">
    <h3 style="margin:0 0 8px;"><?php esc_html_e('Today\'s Tasks', 'meals-db'); ?></h3>
    <?php if (empty($today_tasks)): ?>
        <p style="margin:0;"><em><?php esc_html_e('Nothing due today. ', 'meals-db'); ?></em>
            <a href="<?php echo esc_url($tasks_url); ?>"><?php esc_html_e('Open tasks tab', 'meals-db'); ?></a></p>
    <?php else: ?>
        <?php if ($overdue > 0): ?>
            <p style="margin:0 0 8px; color:#d63638; font-weight:600;">
                <?php echo esc_html(sprintf(
                    /* translators: %d: count of overdue tasks */
                    _n('%d task is overdue.', '%d tasks are overdue.', $overdue, 'meals-db'),
                    $overdue
                )); ?>
            </p>
        <?php endif; ?>
        <ul style="margin:0; list-style:disc; padding-left:20px;">
            <?php foreach ($by_role as $role => $count): ?>
                <?php
                $role_url = add_query_arg([
                    'view' => 'today',
                    'role' => $role === '(unassigned)' ? 'all' : $role,
                ], $tasks_url);
                ?>
                <li>
                    <strong><?php echo esc_html(ucfirst((string) $role)); ?></strong>:
                    <?php echo (int) $count; ?>
                    <a href="<?php echo esc_url($role_url); ?>" style="margin-left:8px;"><?php esc_html_e('open', 'meals-db'); ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
