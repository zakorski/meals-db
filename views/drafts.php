<?php
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

global $wpdb;

$drafts = [];
$draft_error = null;

$per_page = 50;
$paged    = max(1, (int) ($_GET['paged'] ?? 1));
$offset   = ($paged - 1) * $per_page;

$drafts_table = MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS);
$total_drafts = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$drafts_table}`");
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, data, created_at FROM `{$drafts_table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ),
    ARRAY_A
);

if ($results === null && $wpdb->last_error) {
    error_log('[MealsDB] Failed to load draft list: ' . $wpdb->last_error);
    $draft_error = sprintf(
        /* translators: %s: database error message */
        __('Unable to load client drafts: %s', 'meals-db'),
        $wpdb->last_error
    );
} elseif (is_array($results)) {
    foreach ($results as $row) {
        $decoded = MealsDB_Client_Form::decode_draft_payload((string) ($row['data'] ?? ''));
        if (!is_array($decoded)) {
            error_log('[MealsDB] Skipping corrupted draft payload for draft ID ' . ($row['id'] ?? 'unknown') . '.');
            continue;
        }

        $row['data'] = $decoded;
        $drafts[] = $row;
    }
}
?>

<div class="wrap">
    <h2>Client Drafts</h2>

    <?php if ($draft_error): ?>
        <div class="notice notice-error">
            <p><?= esc_html($draft_error) ?></p>
        </div>
    <?php elseif (empty($drafts)): ?>
        <p>No drafts found.</p>
    <?php else: ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Draft ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Saved</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($drafts as $draft): ?>
                    <?php
                        $id = $draft['id'];
                        $data = $draft['data'];
                    ?>
                    <tr id="draft-row-<?= esc_attr($id) ?>">
                        <td><?= esc_html($id) ?></td>
                        <td><?= esc_html(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?></td>
                        <td><?= esc_html($data['client_email'] ?? '') ?></td>
                        <td><?= esc_html($data['phone_primary'] ?? '') ?></td>
                        <td><?= esc_html(mysql2date('Y-m-d H:i', $draft['created_at'])) ?></td>
                        <td>
                            <form method="post" action="<?php echo admin_url('admin.php?page=mealsdb&tab=add'); ?>">
                                <?php foreach ($data as $key => $value): ?>
                                    <?php
                                    $serialized_value = is_scalar($value)
                                        ? $value
                                        : (function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value));
                                    ?>
                                    <input type="hidden" name="<?= esc_attr($key) ?>" value="<?= esc_attr($serialized_value ?? '') ?>" />
                                <?php endforeach; ?>
                                <input type="hidden" name="draft_id" value="<?= esc_attr($id) ?>" />
                                <input type="hidden" name="resume_draft" value="1" />
                                <?php wp_nonce_field('mealsdb_nonce', 'mealsdb_nonce_field'); ?>
                                <button type="submit" class="button button-primary">Resume</button>
                            </form>
                            <button class="button delete-draft" data-id="<?= esc_attr($id) ?>">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $total_pages = (int) ceil($total_drafts / $per_page);
        if ($total_pages > 1):
            $base_url = admin_url('admin.php?page=mealsdb&tab=drafts');
        ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?= esc_html(sprintf(
                            /* translators: %s: total number of drafts */
                            _n('%s draft', '%s drafts', $total_drafts, 'meals-db'),
                            number_format_i18n($total_drafts)
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
    $('.delete-draft').on('click', function() {
        if (!confirm('Are you sure you want to delete this draft?')) return;

        const draftId = $(this).data('id');
        const row = $('#draft-row-' + draftId);

        $.post(ajaxurl, {
            action: 'mealsdb_delete_draft',
            nonce: '<?php echo esc_js(wp_create_nonce("mealsdb_nonce")); ?>',
            id: draftId
        }, function(response) {
            if (response.success) {
                row.fadeOut();
                if (response.data && response.data.message) {
                    alert(response.data.message);
                }
            } else {
                alert('Failed to delete draft.');
            }
        });
    });
});
</script>
