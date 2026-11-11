<?php
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

$sync_error        = null;
$mismatches        = [];
$compare_requested = false;
$success           = null;
$errors            = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['mealsdb_action'] ?? '';

    if (function_exists('wp_unslash')) {
        $action = wp_unslash($action);
    }

    if (function_exists('sanitize_text_field')) {
        $action = sanitize_text_field($action);
    }

    if ($action === 'link_client_to_user') {
        if (function_exists('check_admin_referer')) {
            check_admin_referer('mealsdb_nonce', 'mealsdb_nonce_field');
        }

        $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : 0;
        $user_id   = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

        $result = MealsDB_Sync::link_client_to_user($client_id, $user_id);

        if (is_wp_error($result)) {
            $errors[] = $result->get_error_message();
        } else {
            $success = __('Meals DB client linked to WooCommerce customer.', 'meals-db');
        }

        $compare_requested = true;
        $mismatches        = MealsDB_Sync::get_mismatches();

        if (is_wp_error($mismatches)) {
            $sync_error = $mismatches;
            $mismatches = [];
        }
    } elseif ($action === 'compare_databases') {
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

    <?php include MealsDB_Plugin::path('views/partials/dashboard-tasks-widget.php'); ?>

    <?php include MealsDB_Plugin::path('views/partials/dashboard-unrated-clients.php'); ?>

    <?php include MealsDB_Plugin::path('views/partials/dashboard-extra-mural-clients.php'); ?>

    <p class="description">
        <?php esc_html_e('Click "Compare Databases" to scan for differences between Meals DB and WordPress users.', 'meals-db'); ?>
    </p>

    <?php
    // Ignored Conflicts lives under this Sync tab now (spec 2026-07-16 §3) —
    // unignoring is part of the same reconciliation job. Count is cheap
    // (indexed COUNT on a small table).
    global $wpdb;
    $mealsdb_ignored_table = MealsDB_DB::get_table_name(MealsDB_Tables::IGNORED_CONFLICTS);
    $mealsdb_ignored_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$mealsdb_ignored_table}`");
    if ($mealsdb_ignored_count > 0) : ?>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=mealsdb-clients&tab=sync&view=ignored')); ?>">
                <?php echo esc_html(sprintf(
                    /* translators: %d: number of ignored sync mismatches */
                    __('View ignored mismatches (%d)', 'meals-db'),
                    $mealsdb_ignored_count
                )); ?>
            </a>
        </p>
    <?php endif; ?>

    <?php
    // K21 ITEM 9: the nightly conflict scan's queue. Accounts, not rows — one
    // card per account on the conflicts view. Silent if the table is not
    // there yet (schema upgrade pending).
    $mealsdb_conflict_table    = MealsDB_DB::get_table_name(MealsDB_Tables::SYNC_CONFLICTS);
    $mealsdb_conflict_accounts = $wpdb->get_var("SELECT COUNT(DISTINCT wp_user_id) FROM `{$mealsdb_conflict_table}` WHERE status = 'open'");
    if ($mealsdb_conflict_accounts !== null) : ?>
        <p>
            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=mealsdb-clients&tab=sync&view=conflicts')); ?>">
                <?php echo esc_html(sprintf(
                    /* translators: %d: number of WordPress accounts with open sync conflicts */
                    __('Review sync conflicts (%d accounts)', 'meals-db'),
                    (int) $mealsdb_conflict_accounts
                )); ?>
            </a>
        </p>
    <?php endif; ?>

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
            <?php esc_html_e('These records are not linked: a WordPress user with no Meals DB client, or a Meals DB client with no WordPress user. Link them below, or ignore a row when that is expected. Field differences between LINKED records are resolved under "Review sync conflicts".', 'meals-db'); ?>
        </p>
        <?php
        // $success and $errors are unconditionally initialized at the top of
        // this view (lines 9-10), so status-notice.php always sees them set.
        include __DIR__ . '/partials/status-notice.php';
        ?>

        <form method="post" id="mealsdb-client-form">
            <?php wp_nonce_field('mealsdb_nonce', 'mealsdb_nonce_field'); ?>

            <div class="mealsdb-sync-toolbar">
                <label>
                    <input type="checkbox" id="mealsdb-show-only-diffs" checked="checked" />
                    <?php esc_html_e('Show only unresolved mismatches', 'meals-db'); ?>
                </label>
            </div>

            <?php foreach ($mismatches as $index => $mismatch) :
                $fields      = $mismatch['fields'] ?? [];
                $woo_user_id = isset($mismatch['woo_user_id']) ? (int) $mismatch['woo_user_id'] : 0;
                $client_id   = isset($mismatch['client_id']) ? (int) $mismatch['client_id'] : 0;
                $notice_text = isset($mismatch['notice']) ? $mismatch['notice'] : '';
                $client_data = is_array($mismatch['meals_client'] ?? null) ? $mismatch['meals_client'] : [];
                $user_data   = is_array($mismatch['wp_user'] ?? null) ? $mismatch['wp_user'] : [];
                $suggestions = is_array($mismatch['suggested_matches'] ?? null) ? $mismatch['suggested_matches'] : [];

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
                                        <span class="mealsdb-a" data-value="<?php echo esc_attr($meals_val); ?>">
                                            <?php echo $meals_val !== '' ? esc_html($meals_val) : esc_html__('(empty)', 'meals-db'); ?>
                                        </span>
                                    </td>
                                    <td class="column-woo">
                                        <span class="mealsdb-b" data-value="<?php echo esc_attr($woo_val); ?>">
                                            <?php echo $woo_val !== '' ? esc_html($woo_val) : esc_html__('(empty)', 'meals-db'); ?>
                                        </span>
                                    </td>
                                    <td class="column-actions">
                                        <label class="mealsdb-ignore-option">
                                            <input type="checkbox" class="mealsdb-ignore-toggle" />
                                            <?php esc_html_e('Ignore mismatch', 'meals-db'); ?>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php
                    if (($mismatch['type'] ?? '') === 'meals_only' && !empty($client_data) && is_array($client_data)) {
                        MealsDB_Admin_UI::render_unlinked_client_matches($client_data);
                    }
                    ?>

                    <?php if (!empty($suggestions)) : ?>
                        <div class="mealsdb-suggested-matches">
                            <h4><?php esc_html_e('Suggested matches', 'meals-db'); ?></h4>
                            <?php foreach ($suggestions as $suggestion) :
                                $suggested_name  = trim(($suggestion['first_name'] ?? '') . ' ' . ($suggestion['last_name'] ?? ''));
                                $suggested_name  = $suggested_name !== '' ? $suggested_name : sprintf(__('User #%d', 'meals-db'), intval($suggestion['user_id'] ?? 0));
                                $suggested_email = $suggestion['email'] ?? '';
                                $suggested_phone = $suggestion['billing_phone'] ?? '';
                            ?>
                                <div class="mealsdb-suggested-match">
                                    <p>
                                        <strong><?php echo esc_html($suggested_name); ?></strong><br />
                                        <?php if (!empty($suggested_email)) : ?>
                                            <span><?php echo esc_html($suggested_email); ?></span><br />
                                        <?php endif; ?>
                                        <?php if (!empty($suggested_phone)) : ?>
                                            <span><?php echo esc_html($suggested_phone); ?></span><br />
                                        <?php endif; ?>
                                    </p>
                                    <form method="post" class="mealsdb-link-form">
                                        <?php wp_nonce_field('mealsdb_nonce', 'mealsdb_nonce_field'); ?>
                                        <input type="hidden" name="mealsdb_action" value="link_client_to_user" />
                                        <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>" />
                                        <input type="hidden" name="user_id" value="<?php echo esc_attr((int) ($suggestion['user_id'] ?? 0)); ?>" />
                                        <button type="submit" class="button button-secondary">
                                            <?php esc_html_e('Link to this WooCommerce customer', 'meals-db'); ?>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </form>
    <?php endif; ?>
</div>
