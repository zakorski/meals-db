<?php
MealsDB_Permissions::enforce();

$sync_error        = null;
$mismatches        = [];
$compare_requested = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['mealsdb_action'] ?? '';

    if (function_exists('wp_unslash')) {
        $action = wp_unslash($action);
    }

    if (function_exists('sanitize_text_field')) {
        $action = sanitize_text_field($action);
    }

    if ($action === 'compare_databases') {
        $compare_requested = true;

        if (function_exists('check_admin_referer')) {
            check_admin_referer('mealsdb_compare_databases', 'mealsdb_compare_nonce');
        }

        $mismatches = MealsDB_Sync::get_mismatches();

        if (is_wp_error($mismatches)) {
            $sync_error = $mismatches;
            $mismatches = [];
        }
    }
}

$field_labels = [
    'first_name'        => 'First Name',
    'last_name'         => 'Last Name',
    'client_email'      => 'Email Address',
    'phone_primary'     => 'Primary Phone',
    'address_postal'    => 'Postal Code',
    'wordpress_user_id' => 'WordPress User ID',
];
?>

<div class="wrap mealsdb-sync-dashboard">
    <h2><?php esc_html_e('Sync Dashboard', 'meals-db'); ?></h2>

    <p class="description">
        <?php esc_html_e('Click "Compare Databases" to scan for differences between Meals DB and WordPress users.', 'meals-db'); ?>
    </p>

    <form method="post" class="mealsdb-compare-form">
        <?php wp_nonce_field('mealsdb_compare_databases', 'mealsdb_compare_nonce'); ?>
        <input type="hidden" name="mealsdb_action" value="compare_databases" />
        <button type="submit" class="button button-primary">
            <?php esc_html_e('Compare Databases', 'meals-db'); ?>
        </button>
    </form>

    <?php if ($sync_error instanceof WP_Error) : ?>
        <div class="notice notice-error">
            <p><?php echo esc_html($sync_error->get_error_message()); ?></p>
        </div>
    <?php elseif ($compare_requested && empty($mismatches)) : ?>
        <div class="notice notice-success">
            <p><?php esc_html_e('All client records are currently aligned between Meals DB and WooCommerce. No mismatches were found.', 'meals-db'); ?></p>
        </div>
    <?php elseif (!empty($mismatches)) : ?>
        <p class="description">
            <?php esc_html_e('Review each field below, choose which value to keep, and sync the selected data to WooCommerce. You can also ignore a mismatch when the difference is expected.', 'meals-db'); ?>
        </p>
        <?php
        $success = $success ?? null;
        $errors  = $errors ?? [];
        include __DIR__ . '/partials/status-notice.php';
        ?>

        <form method="post" id="mealsdb-client-form">
            <?php wp_nonce_field('mealsdb_nonce', 'mealsdb_nonce_field'); ?>

            <div class="mealsdb-sync-toolbar">
                <label>
                    <input type="checkbox" id="mealsdb-show-only-diffs" checked="checked" />
                    <?php esc_html_e('Show only unresolved mismatches', 'meals-db'); ?>
                </label>
                <button type="button" class="button button-primary" id="mealsdb-sync-all"><?php esc_html_e('Sync selected rows', 'meals-db'); ?></button>
            </div>

            <?php foreach ($mismatches as $index => $mismatch) :
                $fields      = $mismatch['fields'] ?? [];
                $woo_user_id = isset($mismatch['woo_user_id']) ? (int) $mismatch['woo_user_id'] : 0;
                $client_id   = isset($mismatch['client_id']) ? (int) $mismatch['client_id'] : 0;
                $allow_sync  = isset($mismatch['allow_sync']) ? (bool) $mismatch['allow_sync'] : true;
                $notice_text = isset($mismatch['notice']) ? $mismatch['notice'] : '';
                $client_data = is_array($mismatch['meals_client'] ?? null) ? $mismatch['meals_client'] : [];
                $user_data   = is_array($mismatch['wp_user'] ?? null) ? $mismatch['wp_user'] : [];

                $meals_first = $client_data['first_name'] ?? ($fields['first_name']['meals_db'] ?? '');
                $meals_last  = $client_data['last_name'] ?? ($fields['last_name']['meals_db'] ?? '');
                $woo_first   = $user_data['first_name'] ?? ($fields['first_name']['woocommerce'] ?? '');
                $woo_last    = $user_data['last_name'] ?? ($fields['last_name']['woocommerce'] ?? '');
                $display_name = trim($meals_first . ' ' . $meals_last);

                if ($display_name === '') {
                    $display_name = trim($woo_first . ' ' . $woo_last);
                }

                if ($display_name === '') {
                    /* translators: %d: Meals DB client id */
                    $display_name = sprintf(__('Client #%d', 'meals-db'), $client_id);
                }

                $display_email = $client_data['client_email'] ?? ($user_data['email'] ?? ($fields['client_email']['meals_db'] ?? ($fields['client_email']['woocommerce'] ?? '')));
            ?>
                <div class="mealsdb-client-block">
                    <h3>
                        <?php echo esc_html($display_name); ?>
                        <small>
                            <?php
                            printf(
                                /* translators: 1: Meals DB ID, 2: WooCommerce user ID */
                                esc_html__('Meals DB ID %1$d • WooCommerce User %2$d', 'meals-db'),
                                $client_id,
                                $woo_user_id
                            );
                            ?>
                            <?php if (!empty($display_email)) : ?>
                                &ndash; <?php echo esc_html($display_email); ?>
                            <?php endif; ?>
                        </small>
                    </h3>

                    <?php if (!empty($notice_text)) : ?>
                        <p class="description"><?php echo esc_html($notice_text); ?></p>
                    <?php endif; ?>

                    <table class="widefat fixed striped mealsdb-mismatch-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Field', 'meals-db'); ?></th>
                                <th><?php esc_html_e('Meals DB value', 'meals-db'); ?></th>
                                <th><?php esc_html_e('WooCommerce value', 'meals-db'); ?></th>
                                <th><?php esc_html_e('Actions', 'meals-db'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fields as $field_key => $values) :
                                $row_id     = 'mealsdb-mismatch-' . $index . '-' . sanitize_key($field_key);
                                $label      = $field_labels[$field_key] ?? ucwords(str_replace('_', ' ', $field_key));
                                $meals_val  = $values['meals_db'] ?? '';
                                $woo_val    = $values['woocommerce'] ?? '';
                                $radio_name = $row_id . '-choice';
                            ?>
                                <tr
                                    class="mealsdb-mismatch-row"
                                    data-field="<?php echo esc_attr($field_key); ?>"
                                    data-woo="<?php echo esc_attr($woo_user_id); ?>"
                                    data-client="<?php echo esc_attr($client_id); ?>"
                                >
                                    <td class="column-field">
                                        <strong><?php echo esc_html($label); ?></strong>
                                    </td>
                                    <td class="column-meals">
                                        <label>
                                            <input type="radio" name="<?php echo esc_attr($radio_name); ?>" value="meals_db" checked="checked" />
                                            <span class="mealsdb-a" data-value="<?php echo esc_attr($meals_val); ?>">
                                                <?php echo $meals_val !== '' ? esc_html($meals_val) : esc_html__('(empty)', 'meals-db'); ?>
                                            </span>
                                        </label>
                                    </td>
                                    <td class="column-woo">
                                        <label>
                                            <input type="radio" name="<?php echo esc_attr($radio_name); ?>" value="woocommerce" />
                                            <span class="mealsdb-b" data-value="<?php echo esc_attr($woo_val); ?>">
                                                <?php echo $woo_val !== '' ? esc_html($woo_val) : esc_html__('(empty)', 'meals-db'); ?>
                                            </span>
                                        </label>
                                    </td>
                                    <td class="column-actions">
                                        <?php if ($allow_sync) : ?>
                                            <button type="button" class="button button-secondary sync-field">
                                                <?php esc_html_e('Sync selected value', 'meals-db'); ?>
                                            </button>
                                        <?php else : ?>
                                            <span class="description">
                                                <?php esc_html_e('Sync is unavailable for this conflict.', 'meals-db'); ?>
                                            </span>
                                        <?php endif; ?>
                                        <label class="mealsdb-ignore-option">
                                            <input type="checkbox" class="mealsdb-ignore-toggle" />
                                            <?php esc_html_e('Ignore mismatch', 'meals-db'); ?>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </form>
    <?php endif; ?>
</div>
