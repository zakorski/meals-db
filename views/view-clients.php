<?php
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

// Phase S: the client list could suddenly explode with Private customers
// once the promotion trigger starts populating meals_clients. Admins
// usually want the government-client view, so default to SDNB+Veteran
// and surface a preset selector that persists in the URL.
$type_preset = isset($_GET['type_preset']) ? (string) $_GET['type_preset'] : 'government';
if (function_exists('wp_unslash')) {
    $type_preset = wp_unslash($type_preset);
}
if (function_exists('sanitize_text_field')) {
    $type_preset = sanitize_text_field($type_preset);
}

$valid_presets = ['government', 'all', 'sdnb', 'veteran', 'private'];
if (!in_array($type_preset, $valid_presets, true)) {
    $type_preset = 'government';
}

$preset_map = [
    'all'        => null,
    'government' => ['SDNB', 'Veteran'],
    'sdnb'       => 'SDNB',
    'veteran'    => 'Veteran',
    'private'    => 'Private',
];
$client_type_filter = $preset_map[$type_preset];

$search_term = isset($_GET['search']) ? $_GET['search'] : '';
if (function_exists('wp_unslash')) {
    $search_term = wp_unslash($search_term);
}
if (function_exists('sanitize_text_field')) {
    $search_term = sanitize_text_field($search_term);
} else {
    $search_term = trim((string) $search_term);
}

$per_page    = 100;
$paged       = max(1, (int) ($_GET['paged'] ?? 1));
$offset      = ($paged - 1) * $per_page;
$total       = MealsDB_Clients::count_clients($client_type_filter, $search_term);
$total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;
if ($paged > $total_pages) {
    $paged  = $total_pages;
    $offset = ($paged - 1) * $per_page;
}

$clients = MealsDB_Clients::get_clients($client_type_filter, $search_term, false, $per_page, $offset);

$base_url = admin_url('admin.php?page=mealsdb-clients&tab=list');
$edit_base = admin_url('admin.php?page=mealsdb-clients&tab=list&action=edit');

$preset_labels = [
    'government' => __('SDNB + Veteran (default)', 'meals-db'),
    'all'        => __('All Client Types', 'meals-db'),
    'sdnb'       => __('SDNB only', 'meals-db'),
    'veteran'    => __('Veteran only', 'meals-db'),
    'private'    => __('Private only', 'meals-db'),
];

$badge_styles = [
    'SDNB'    => 'background:#2271b1;color:#fff;',
    'Veteran' => 'background:#8a6e00;color:#fff;',
    'Private' => 'background:#50575e;color:#fff;',
];
?>

<div class="wrap mealsdb-view-clients">
    <h2><?php esc_html_e('View Clients', 'meals-db'); ?></h2>

    <form method="get" class="mealsdb-client-filters">
        <input type="hidden" name="page" value="mealsdb-clients" />
        <input type="hidden" name="tab" value="list" />

        <label for="mealsdb-filter-client-type" class="screen-reader-text"><?php esc_html_e('Filter by client type', 'meals-db'); ?></label>
        <select id="mealsdb-filter-client-type" name="type_preset">
            <?php foreach ($preset_labels as $preset_key => $preset_label) : ?>
                <option value="<?php echo esc_attr($preset_key); ?>" <?php selected($type_preset, $preset_key); ?>><?php echo esc_html($preset_label); ?></option>
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
                <th><?php esc_html_e('Social Worker', 'meals-db'); ?></th>
                <th><?php esc_html_e('Social Worker Email', 'meals-db'); ?></th>
                <th><?php esc_html_e('Phone Number', 'meals-db'); ?></th>
                <th><?php esc_html_e('Email Address', 'meals-db'); ?></th>
                <th class="mealsdb-client-actions-column"><?php esc_html_e('Actions', 'meals-db'); ?></th>
            </tr>
        </thead>
        <tbody data-empty-message="<?php echo esc_attr__('No clients found for the selected criteria.', 'meals-db'); ?>">
            <?php if (empty($clients)) : ?>
                <tr class="mealsdb-client-empty">
                    <td colspan="8"><?php esc_html_e('No clients found for the selected criteria.', 'meals-db'); ?></td>
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
                        <td>
                            <?php
                            $row_type = (string) ($client['client_type'] ?? '');
                            $badge_style = $badge_styles[$row_type] ?? 'background:#ddd;color:#333;';
                            if ($row_type !== '') :
                            ?>
                                <span class="mealsdb-client-type-badge" style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;<?php echo esc_attr($badge_style); ?>">
                                    <?php echo esc_html($row_type); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($client['assigned_worker_name'] ?? '—'); ?></td>
                        <td>
                            <?php if (!empty($client['assigned_worker_email'])) : ?>
                                <a href="mailto:<?php echo esc_attr($client['assigned_worker_email']); ?>"><?php echo esc_html($client['assigned_worker_email']); ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
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
                                    class="button button-secondary mealsdb-delete-client"
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

    <?php if ($total_pages > 1) :
        $pagination_args = [
            'page' => 'mealsdb',
            'tab'  => 'clients',
        ];
        if ($type_preset !== '' && $type_preset !== 'government') {
            $pagination_args['type_preset'] = $type_preset;
        }
        if ($search_term !== '') {
            $pagination_args['search'] = $search_term;
        }
        $pagination_base = admin_url('admin.php');
        $paginate_links = paginate_links([
            'base'      => add_query_arg(array_merge($pagination_args, ['paged' => '%#%']), $pagination_base),
            'format'    => '',
            'current'   => $paged,
            'total'     => $total_pages,
            'prev_text' => __('&laquo; Prev', 'meals-db'),
            'next_text' => __('Next &raquo;', 'meals-db'),
        ]);
    ?>
        <div class="tablenav"><div class="tablenav-pages">
            <span class="displaying-num">
                <?php echo esc_html(sprintf(_n('%d client', '%d clients', $total, 'meals-db'), number_format_i18n($total))); ?>
            </span>
            <?php echo $paginate_links; // paginate_links output is HTML; WP escapes internally. ?>
        </div></div>
    <?php endif; ?>

    <div id="mealsdb-delete-client-modal" class="mealsdb-modal" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="mealsdb-modal__backdrop" data-close="true"></div>
        <div class="mealsdb-modal__dialog" role="document">
            <h2 class="mealsdb-modal__title"><?php esc_html_e('Delete Client', 'meals-db'); ?></h2>
            <p class="mealsdb-modal__message"><?php esc_html_e('Are you sure you want to delete this client? This action cannot be undone.', 'meals-db'); ?></p>
            <p class="mealsdb-modal__client-name" data-has-name="false">
                <strong id="mealsdb-delete-client-name"></strong>
            </p>
            <p class="mealsdb-modal__warning">
                <?php esc_html_e('Type "YES" in the box below to confirm.', 'meals-db'); ?>
            </p>
            <label for="mealsdb-delete-client-confirmation" class="screen-reader-text"><?php esc_html_e('Confirm deletion by typing YES', 'meals-db'); ?></label>
            <input
                type="text"
                id="mealsdb-delete-client-confirmation"
                class="mealsdb-modal__confirmation-input"
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                placeholder="<?php esc_attr_e('Type YES to confirm', 'meals-db'); ?>"
            />
            <div class="mealsdb-modal__actions">
                <button type="button" class="button button-secondary" id="mealsdb-delete-client-cancel" data-close="true"><?php esc_html_e('Cancel', 'meals-db'); ?></button>
                <button type="button" class="button button-secondary" id="mealsdb-delete-client-confirm" disabled>
                    <?php esc_html_e('Delete Client', 'meals-db'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
