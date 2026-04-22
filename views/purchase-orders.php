<?php
/**
 * Purchase Orders tab — read-only lifecycle view.
 *
 * POs are created and updated via the task workflow (place_po →
 * confirm_po_arrival → physical_count). This page surfaces the current
 * state of every PO plus links to the related tasks.
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

$status_filter = isset($_GET['po_status']) ? sanitize_key(wp_unslash((string) $_GET['po_status'])) : '';
$po_id = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;

$service = new MealsDB_Purchase_Orders();
$base_url = admin_url('admin.php?page=mealsdb&tab=po_admin');

if ($po_id > 0) {
    $po = $service->get($po_id);
    if ($po === null) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Purchase order not found.', 'meals-db') . '</p></div>';
        echo '<p><a class="button" href="' . esc_url($base_url) . '">&larr; ' . esc_html__('Back to list', 'meals-db') . '</a></p>';
        return;
    }

    $engine = new MealsDB_Task_Engine();
    $related_tasks = $engine->query_tasks([
        'related_entity_type' => 'po',
        'related_entity_id'   => $po_id,
        'status'              => ['pending', 'in_progress', 'deferred', 'completed', 'skipped', 'abandoned'],
    ]);
    ?>
    <div id="mealsdb-po-detail" class="mealsdb-po-detail">
        <p><a class="button" href="<?php echo esc_url($base_url); ?>">&larr; <?php esc_html_e('Back to list', 'meals-db'); ?></a></p>
        <h2><?php echo esc_html(sprintf(__('Purchase Order %s', 'meals-db'), $po['po_number'])); ?></h2>

        <table class="form-table" role="presentation">
            <tbody>
                <tr><th><?php esc_html_e('Status', 'meals-db'); ?></th>
                    <td><code><?php echo esc_html($po['status']); ?></code></td></tr>
                <tr><th><?php esc_html_e('Supplier', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['supplier'] ?? '')); ?></td></tr>
                <tr><th><?php esc_html_e('Placed Date', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['placed_date'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Expected Arrival', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['expected_arrival'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Actual Arrival', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['arrival_date'] ?? '—')); ?></td></tr>
                <tr><th><?php esc_html_e('Reconciled', 'meals-db'); ?></th>
                    <td><?php echo esc_html((string) ($po['reconciled_at'] ?? '—')); ?></td></tr>
            </tbody>
        </table>

        <h3><?php esc_html_e('Items', 'meals-db'); ?></h3>
        <?php if (empty($po['items'])): ?>
            <p><em><?php esc_html_e('No items on this PO.', 'meals-db'); ?></em></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php esc_html_e('SKU', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Product', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Qty Ordered', 'meals-db'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($po['items'] as $item): ?>
                        <tr>
                            <td><?php echo esc_html((string) ($item['sku'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($item['product_name'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($item['quantity_ordered'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3><?php esc_html_e('Related Tasks', 'meals-db'); ?></h3>
        <?php if (empty($related_tasks)): ?>
            <p><em><?php esc_html_e('No related tasks.', 'meals-db'); ?></em></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php esc_html_e('Type', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Status', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Due', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Open', 'meals-db'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($related_tasks as $task): ?>
                        <?php
                        $def = MealsDB_Task_Registry::get($task['task_type']);
                        $label = $def['label'] ?? $task['task_type'];
                        $detail_url = admin_url('admin.php?page=mealsdb&tab=tasks&action=detail&task_id=' . (int) $task['task_id']);
                        ?>
                        <tr>
                            <td><?php echo esc_html($label); ?></td>
                            <td><code><?php echo esc_html($task['status']); ?></code></td>
                            <td><?php echo esc_html($task['next_run_date']); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($detail_url); ?>"><?php esc_html_e('Open', 'meals-db'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($po['notes'])): ?>
            <h3><?php esc_html_e('Notes', 'meals-db'); ?></h3>
            <pre style="background:#f7f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html((string) $po['notes']); ?></pre>
        <?php endif; ?>
    </div>
    <?php
    return;
}

// List view.
$filters = [];
if ($status_filter !== '') {
    $filters['status'] = [$status_filter];
}
$rows = $service->query($filters);
?>
<div id="mealsdb-po-list" class="mealsdb-po-list">
    <h2><?php esc_html_e('Purchase Orders', 'meals-db'); ?></h2>

    <div style="margin-bottom:12px;">
        <label><strong><?php esc_html_e('Status:', 'meals-db'); ?></strong></label>
        <select onchange="window.location.href=this.value">
            <option value="<?php echo esc_url($base_url); ?>"><?php esc_html_e('All', 'meals-db'); ?></option>
            <?php foreach (MealsDB_Purchase_Orders::ALLOWED_STATUSES as $s): ?>
                <option value="<?php echo esc_url(add_query_arg(['po_status' => $s], $base_url)); ?>"
                    <?php selected($status_filter, $s); ?>>
                    <?php echo esc_html($s); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (empty($rows)): ?>
        <p><em><?php esc_html_e('No purchase orders yet.', 'meals-db'); ?></em></p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e('PO #', 'meals-db'); ?></th>
                <th><?php esc_html_e('Supplier', 'meals-db'); ?></th>
                <th><?php esc_html_e('Placed', 'meals-db'); ?></th>
                <th><?php esc_html_e('Expected', 'meals-db'); ?></th>
                <th><?php esc_html_e('Arrived', 'meals-db'); ?></th>
                <th><?php esc_html_e('Status', 'meals-db'); ?></th>
                <th><?php esc_html_e('Items', 'meals-db'); ?></th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($rows as $po): ?>
                    <?php $detail_url = add_query_arg(['po_id' => (int) $po['po_id']], $base_url); ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) $po['po_number']); ?></strong></td>
                        <td><?php echo esc_html((string) ($po['supplier'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($po['placed_date'] ?? '—')); ?></td>
                        <td><?php echo esc_html((string) ($po['expected_arrival'] ?? '—')); ?></td>
                        <td><?php echo esc_html((string) ($po['arrival_date'] ?? '—')); ?></td>
                        <td><code><?php echo esc_html($po['status']); ?></code></td>
                        <td><?php echo (int) count($po['items'] ?? []); ?></td>
                        <td><a class="button button-small" href="<?php echo esc_url($detail_url); ?>"><?php esc_html_e('View', 'meals-db'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
