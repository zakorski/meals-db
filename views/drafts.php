<?php
MealsDB_Permissions::enforce();

$conn = MealsDB_DB::get_connection();
$drafts = [];
$draft_error = null;

if ($conn) {
    $res = $conn->query("SELECT id, data, created_at FROM meals_drafts ORDER BY created_at DESC");

    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $decoded = json_decode($row['data'], true);
            if (!is_array($decoded)) {
                error_log('[MealsDB] Skipping corrupted draft payload for draft ID ' . ($row['id'] ?? 'unknown') . '.');
                continue;
            }

            $row['data'] = $decoded;
            $drafts[] = $row;
        }

        $res->free();
    } elseif ($res === false) {
        $message = $conn->error ?? __('unknown error', 'meals-db');
        error_log('[MealsDB] Failed to load draft list: ' . $message);
        $draft_error = sprintf(
            /* translators: %s: database error message */
            __('Unable to load client drafts: %s', 'meals-db'),
            $message
        );
    }
} else {
    $draft_error = __('Unable to connect to the Meals DB database. Please try again later.', 'meals-db');
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
                        <td><?= esc_html(date('Y-m-d H:i', strtotime($draft['created_at']))) ?></td>
                        <td>
                            <form method="post" action="<?php echo admin_url('admin.php?page=meals-db&tab=add'); ?>">
                                <?php
                                if (!function_exists('mealsdb_render_draft_inputs')) {
                                    function mealsdb_render_draft_inputs(string $name, $value): void {
                                        if (is_array($value)) {
                                            foreach ($value as $key => $item) {
                                                $child_name = is_int($key)
                                                    ? $name . '[]'
                                                    : $name . '[' . $key . ']';
                                                mealsdb_render_draft_inputs($child_name, $item);
                                            }

                                            return;
                                        }

                                        if (!is_scalar($value)) {
                                            $value = '';
                                        }

                                        ?>
                                        <input type="hidden" name="<?= esc_attr($name) ?>" value="<?= esc_attr((string) $value) ?>" />
                                        <?php
                                    }
                                }

                                foreach ($data as $key => $value) {
                                    mealsdb_render_draft_inputs((string) $key, $value);
                                }
                                ?>
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
            nonce: '<?php echo wp_create_nonce("mealsdb_nonce"); ?>',
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
