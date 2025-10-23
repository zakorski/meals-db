<?php
MealsDB_Permissions::enforce();

$conn = MealsDB_DB::get_connection();
$ignored = [];

if ($conn) {
    $res = $conn->query("
        SELECT ig.*, u.user_login AS ignored_by_user
        FROM meals_ignored_conflicts ig
        LEFT JOIN {$wpdb->users} u ON u.ID = ig.ignored_by
        ORDER BY ig.ignored_at DESC
    ");
    while ($row = $res->fetch_assoc()) {
        $ignored[] = $row;
    }
}
?>

<div class="wrap">
    <h2>Ignored Conflicts</h2>

    <?php if (empty($ignored)): ?>
        <p>No ignored mismatches found.</p>
    <?php else: ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Field</th>
                    <th>Meals DB Value</th>
                    <th>WooCommerce Value</th>
                    <th>Ignored By</th>
                    <th>Date Ignored</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ignored as $item): ?>
                    <tr id="ignore-row-<?= esc_attr($item['id']) ?>">
                        <td><?= esc_html($item['field_name']) ?></td>
                        <td><?= esc_html($item['source_value']) ?></td>
                        <td><?= esc_html($item['target_value']) ?></td>
                        <td><?= esc_html($item['ignored_by_user'] ?? 'Unknown') ?></td>
                        <td><?= esc_html(date('Y-m-d H:i', strtotime($item['ignored_at']))) ?></td>
                        <td>
                            <button class="button unignore-btn" data-id="<?= esc_attr($item['id']) ?>"
                                    data-field="<?= esc_attr($item['field_name']) ?>"
                                    data-source="<?= esc_attr($item['source_value']) ?>"
                                    data-target="<?= esc_attr($item['target_value']) ?>">
                                Unignore
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    $('.unignore-btn').on('click', function() {
        const $btn = $(this);
        const rowId = $btn.data('id');
        const field = $btn.data('field');
        const source = $btn.data('source');
        const target = $btn.data('target');

        $.post(ajaxurl, {
            action: 'mealsdb_toggle_ignore',
            nonce: '<?php echo wp_create_nonce("mealsdb_nonce"); ?>',
            field: field,
            source: source,
            target: target,
            ignored: false
        }, function(response) {
            if (response.success) {
                $('#ignore-row-' + rowId).fadeOut();
            } else {
                alert('Failed to unignore.');
            }
        });
    });
});
</script>
