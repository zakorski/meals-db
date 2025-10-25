<?php
MealsDB_Permissions::enforce();

global $wpdb;

$conn = MealsDB_DB::get_connection();
$ignored = [];
$ignored_error = null;

if ($conn) {
    $sql = 'SELECT id, field_name, source_value, target_value, ignored_by, created_at AS ignored_at
            FROM meals_ignored_conflicts
            ORDER BY created_at DESC';

    if ($stmt = $conn->prepare($sql)) {
        if ($stmt->execute()) {
            if (method_exists($stmt, 'get_result')) {
                $res = $stmt->get_result();

                if ($res instanceof mysqli_result) {
                    while ($row = $res->fetch_assoc()) {
                        $ignored[] = $row;
                    }

                    $res->free();
                }
            } elseif ($stmt->bind_result($id, $field, $source, $target, $ignored_by, $ignored_at)) {
                while ($stmt->fetch()) {
                    $ignored[] = [
                        'id' => $id,
                        'field_name' => $field,
                        'source_value' => $source,
                        'target_value' => $target,
                        'ignored_by' => $ignored_by,
                        'ignored_at' => $ignored_at,
                    ];
                }
            }
        } else {
            $message = $stmt->error ?? __('unknown error', 'meals-db');
            error_log('[MealsDB] Failed to execute ignored conflicts query: ' . $message);
            $ignored_error = sprintf(
                /* translators: %s: database error message */
                __('Unable to load ignored mismatches: %s', 'meals-db'),
                $message
            );
        }

        $stmt->close();
    } else {
        $message = $conn->error ?? __('unknown error', 'meals-db');
        error_log('[MealsDB] Failed to prepare ignored conflicts query: ' . $message);
        $ignored_error = sprintf(
            /* translators: %s: database error message */
            __('Unable to prepare ignored mismatches query: %s', 'meals-db'),
            $message
        );
    }
} else {
    $ignored_error = __('Unable to connect to the Meals DB database. Please try again later.', 'meals-db');
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

    <?php if ($ignored_error): ?>
        <div class="notice notice-error">
            <p><?= esc_html($ignored_error) ?></p>
        </div>
    <?php elseif (empty($ignored)): ?>
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
