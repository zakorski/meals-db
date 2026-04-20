<?php
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

global $wpdb;

$ignored = [];
$ignored_error = null;

$per_page = 50;
$paged    = max(1, (int) ($_GET['paged'] ?? 1));
$offset   = ($paged - 1) * $per_page;

$table = MealsDB_DB::get_table_name(MealsDB_Tables::IGNORED_CONFLICTS);
$total_ignored = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
$sql = $wpdb->prepare(
    "SELECT id, field_name, source_value, target_value, ignored_by, created_at AS ignored_at FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
    $per_page,
    $offset
);

$results = $wpdb->get_results($sql, ARRAY_A);

if ($results === null && $wpdb->last_error) {
    // Log the full DB error server-side for debugging, but don't echo
    // it to the admin UI — MySQL errors can include partial SQL and
    // table/column names that are useful to an attacker. Show a
    // generic message; admins can find the detail in the log.
    error_log('[MealsDB] Failed to load ignored conflicts: ' . $wpdb->last_error);
    $ignored_error = __('Unable to load ignored mismatches. Check the server error log for details.', 'meals-db');
} elseif (is_array($results)) {
    $ignored = $results;
}

if (!empty($ignored)) {
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
                        <td><?= esc_html(mysql2date('Y-m-d H:i', $item['ignored_at'])) ?></td>
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

        <?php
        $total_pages = (int) ceil($total_ignored / $per_page);
        if ($total_pages > 1):
            $base_url = admin_url('admin.php?page=mealsdb&tab=ignored');
        ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?= esc_html(sprintf(
                            /* translators: %s: total number of ignored mismatches */
                            _n('%s item', '%s items', $total_ignored, 'meals-db'),
                            number_format_i18n($total_ignored)
                        )) ?>
                    </span>
                    <span class="pagination-links">
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <?php if ($p === $paged): ?>
                                <span class="button button-primary"><?= (int) $p ?></span>
                            <?php else: ?>
                                <a class="button" href="<?= esc_url(add_query_arg('paged', $p, $base_url)) ?>"><?= (int) $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
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
            nonce: '<?php echo esc_js(wp_create_nonce("mealsdb_nonce")); ?>',
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
