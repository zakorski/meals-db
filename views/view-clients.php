<?php
$selected_type = isset($_GET['client_type']) ? $_GET['client_type'] : '';
$search_term = isset($_GET['search']) ? $_GET['search'] : '';

if (function_exists('wp_unslash')) {
    $selected_type = wp_unslash($selected_type);
    $search_term = wp_unslash($search_term);
}

if (function_exists('sanitize_text_field')) {
    $selected_type = sanitize_text_field($selected_type);
    $search_term = sanitize_text_field($search_term);
} else {
    $selected_type = trim((string) $selected_type);
    $search_term = trim((string) $search_term);
}

$client_types = MealsDB_Clients::get_client_types();
$clients = MealsDB_Clients::get_clients($selected_type, $search_term);

$base_url = admin_url('admin.php?page=meals-db&tab=clients');
$edit_base = admin_url('admin.php?page=meals-db&tab=clients&action=edit');
?>

<div class="wrap mealsdb-view-clients">
    <h2><?php esc_html_e('View Clients', 'meals-db'); ?></h2>

    <form method="get" class="mealsdb-client-filters">
        <input type="hidden" name="page" value="meals-db" />
        <input type="hidden" name="tab" value="clients" />

        <label for="mealsdb-filter-client-type" class="screen-reader-text"><?php esc_html_e('Filter by client type', 'meals-db'); ?></label>
        <select id="mealsdb-filter-client-type" name="client_type">
            <option value=""><?php esc_html_e('All Client Types', 'meals-db'); ?></option>
            <?php foreach ($client_types as $type) : ?>
                <option value="<?php echo esc_attr($type); ?>" <?php selected($selected_type, $type); ?>><?php echo esc_html($type); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="mealsdb-client-search" class="screen-reader-text"><?php esc_html_e('Search by name', 'meals-db'); ?></label>
        <input type="search" id="mealsdb-client-search" name="search" placeholder="<?php esc_attr_e('Search by name…', 'meals-db'); ?>" value="<?php echo esc_attr($search_term); ?>" />

        <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'meals-db'); ?></button>
        <a href="<?php echo esc_url($base_url); ?>" class="button"><?php esc_html_e('Reset', 'meals-db'); ?></a>
    </form>

    <table class="widefat striped mealsdb-client-table">
        <thead>
            <tr>
                <th><?php esc_html_e('First Name', 'meals-db'); ?></th>
                <th><?php esc_html_e('Last Name', 'meals-db'); ?></th>
                <th><?php esc_html_e('Client Type', 'meals-db'); ?></th>
                <th><?php esc_html_e('Phone Number', 'meals-db'); ?></th>
                <th><?php esc_html_e('Email Address', 'meals-db'); ?></th>
                <th class="mealsdb-client-actions-column"><?php esc_html_e('Actions', 'meals-db'); ?></th>
            </tr>
        </thead>
        <tbody data-empty-message="<?php echo esc_attr__('No clients found for the selected criteria.', 'meals-db'); ?>">
            <?php if (empty($clients)) : ?>
                <tr class="mealsdb-client-empty">
                    <td colspan="6"><?php esc_html_e('No clients found for the selected criteria.', 'meals-db'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ($clients as $client) :
                    $client_id = intval($client['id'] ?? 0);
                    $edit_link = $client_id > 0 ? add_query_arg('client_id', $client_id, $edit_base) : '';
                    $is_active = isset($client['active']) ? intval($client['active']) === 1 : true;
                    $client_first_name = isset($client['first_name']) ? trim((string) $client['first_name']) : '';
                    $client_last_name = isset($client['last_name']) ? trim((string) $client['last_name']) : '';
                    $client_display_name = trim($client_first_name . ' ' . $client_last_name);
                    if ($client_display_name === '' && $client_id > 0) {
                        /* translators: %d: client ID */
                        $client_display_name = sprintf(__('Client #%d', 'meals-db'), $client_id);
                    }
                    $row_classes = ['mealsdb-client-row'];
                    if (!$is_active) {
                        $row_classes[] = 'mealsdb-client-row-inactive';
                    }
                    $row_class_attr = implode(' ', array_filter($row_classes));
                ?>
                    <tr class="<?php echo esc_attr($row_class_attr); ?>" data-client-id="<?php echo esc_attr($client_id); ?>">
                        <td><?php echo esc_html($client['first_name'] ?? ''); ?></td>
                        <td><?php echo esc_html($client['last_name'] ?? ''); ?></td>
                        <td><?php echo esc_html($client['customer_type'] ?? ''); ?></td>
                        <td><?php echo esc_html($client['phone_primary'] ?? ''); ?></td>
                        <td>
                            <?php if (!empty($client['client_email'])) : ?>
                                <a href="mailto:<?php echo esc_attr($client['client_email']); ?>"><?php echo esc_html($client['client_email']); ?></a>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e('No email on file', 'meals-db'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="mealsdb-client-actions-column">
                            <div class="mealsdb-client-actions">
                                <?php if ($edit_link) : ?>
                                    <a
                                        class="button button-secondary mealsdb-client-edit"
                                        data-client-id="<?php echo esc_attr($client_id); ?>"
                                        href="<?php echo esc_url($edit_link); ?>"
                                    ><?php esc_html_e('Edit Client', 'meals-db'); ?></a>
                                <?php endif; ?>
                                <button
                                    type="button"
                                    class="button button-secondary mealsdb-client-toggle-status"
                                    data-client-id="<?php echo esc_attr($client_id); ?>"
                                    data-active="<?php echo $is_active ? '1' : '0'; ?>"
                                    data-label-activate="<?php echo esc_attr__('Activate', 'meals-db'); ?>"
                                    data-label-deactivate="<?php echo esc_attr__('Deactivate', 'meals-db'); ?>"
                                >
                                    <?php echo $is_active ? esc_html__('Deactivate', 'meals-db') : esc_html__('Activate', 'meals-db'); ?>
                                </button>
                                <button
                                    type="button"
                                    class="button button-link-delete mealsdb-client-delete"
                                    data-client-id="<?php echo esc_attr($client_id); ?>"
                                    data-client-name="<?php echo esc_attr($client_display_name); ?>"
                                >
                                    <?php esc_html_e('Delete', 'meals-db'); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <div id="mealsdb-delete-client-modal" class="mealsdb-modal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="mealsdb-modal__backdrop" data-close="true"></div>
        <div class="mealsdb-modal__dialog" role="document">
            <h2 class="mealsdb-modal__title"><?php esc_html_e('Delete Client', 'meals-db'); ?></h2>
            <p class="mealsdb-modal__message"><?php esc_html_e('Are you sure you want to delete this client? This action cannot be undone.', 'meals-db'); ?></p>
            <p class="mealsdb-modal__client-name" data-has-name="false">
                <strong id="mealsdb-delete-client-name"></strong>
            </p>
            <div class="mealsdb-modal__actions">
                <button type="button" class="button button-secondary" id="mealsdb-delete-client-cancel" data-close="true"><?php esc_html_e('Cancel', 'meals-db'); ?></button>
                <button type="button" class="button button-secondary button-link-delete" id="mealsdb-delete-client-confirm">
                    <?php esc_html_e('Delete Client', 'meals-db'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
