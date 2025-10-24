<?php
MealsDB_Permissions::enforce();

global $wpdb;

$conn = MealsDB_DB::get_connection();
$ignored = [];

if ($conn) {
    $sql = 'SELECT id, field_name, source_value, target_value, ignored_by, created_at AS ignored_at
            FROM meals_ignored_conflicts
            ORDER BY created_at DESC';

    if ($stmt = $conn->prepare($sql)) {
        if ($stmt->execute() && ($res = $stmt->get_result())) {
            while ($row = $res->fetch_assoc()) {
                $ignored[] = $row;
            }
        }
        $stmt->close();
    }
}

if (!empty($ignored) && isset($wpdb) && $wpdb instanceof wpdb) {
    $user_ids = array_unique(array_filter(array_map('intval', wp_list_pluck($ignored, 'ignored_by'))));

    if (!empty($user_ids)) {
        $placeholders = implode(', ', array_fill(0, count($user_ids), '%d'));
        $query = "SELECT ID, user_login FROM {$wpdb->users} WHERE ID IN ($placeholders)";
        $prepared = $wpdb->prepare($query, $user_ids);

        if ($prepared) {
            $user_rows = $wpdb->get_results($prepared, OBJECT_K);

            foreach ($ignored as &$item) {
                $user_id = intval($item['ignored_by'] ?? 0);
                if ($user_id && isset($user_rows[$user_id])) {
                    $item['ignored_by_user'] = $user_rows[$user_id]->user_login;
                } else {
                    $item['ignored_by_user'] = __('Unknown', 'meals-db');
                }
            }
            unset($item);
        }
    }
}

// Ensure we always have a fallback label when user data is unavailable.
foreach ($ignored as &$item) {
    if (!isset($item['ignored_by_user']) || $item['ignored_by_user'] === null) {
        $item['ignored_by_user'] = __('Unknown', 'meals-db');
    }
}
unset($item);
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
