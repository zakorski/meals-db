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
        <tbody>
            <?php if (empty($clients)) : ?>
                <tr>
                    <td colspan="6"><?php esc_html_e('No clients found for the selected criteria.', 'meals-db'); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ($clients as $client) :
                    $client_id = intval($client['id'] ?? 0);
                    $edit_link = $client_id > 0 ? add_query_arg('client_id', $client_id, $edit_base) : '';
                ?>
                    <tr>
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
                            <?php if ($edit_link) : ?>
                                <a class="button button-secondary" href="<?php echo esc_url($edit_link); ?>"><?php esc_html_e('Edit Client', 'meals-db'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
