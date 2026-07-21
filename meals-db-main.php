<?php
/**
 * Plugin Name: Meals Database
 * Plugin URI: https://github.com/zakorski/meals-db
 * Description: Custom plugin for Meals & More database integration.
 * Version: 1.0.509
 * Author: Zak Sikorski
 * Author URI: https://zakorski.com
 * GitHub Plugin URI: zakorski/meals-db
 * Primary Branch: main
 * License: GPL-3.0-or-later
 * Requires PHP: 8.2
 * Requires at least: 7.0
 * Tested up to: 7.0
 *
 * This plugin is licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

if (!defined('MEALS_DB_PLUGIN_FILE')) {
    define('MEALS_DB_PLUGIN_FILE', __FILE__);
}

if (!defined('MEALS_DB_PLUGIN_DIR')) {
    define('MEALS_DB_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('MEALS_DB_PLUGIN_URL')) {
    define('MEALS_DB_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (!defined('MEALS_DB_VERSION')) {
    // get_plugin_data() lives in wp-admin/includes/plugin.php, which
    // isn't loaded automatically from every entry point — WP-CLI and
    // cron contexts hit this file before wp-admin is available. Load it
    // when readable, and fall back cleanly when the helper still can't
    // be loaded, otherwise activation via WP-CLI (`wp plugin activate
    // meals-db`) fatals on a missing function. (ABSPATH is already
    // guaranteed defined by the line-19 `defined('ABSPATH') || exit;`
    // guard above, so it needs no re-check here.)
    if (!function_exists('get_plugin_data') && is_readable(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (function_exists('get_plugin_data')) {
        $mealsdb_plugin_data = get_plugin_data(__FILE__, false, false);
        define('MEALS_DB_VERSION', $mealsdb_plugin_data['Version'] ?? '0.0.0');
    } else {
        // Fallback: parse the plugin header ourselves rather than
        // hard-coding "0.0.0", so downstream version comparisons in
        // mealsdb_maybe_upgrade_schema still work correctly.
        $mealsdb_header = @file_get_contents(__FILE__, false, null, 0, 8192);
        if (is_string($mealsdb_header) && preg_match('/^[\s\*]*Version:\s*(\S+)/mi', $mealsdb_header, $m)) {
            define('MEALS_DB_VERSION', $m[1]);
        } else {
            define('MEALS_DB_VERSION', '0.0.0');
        }
    }
}

// Old Autoloader - Deprecated.

require_once plugin_dir_path(__FILE__) . 'includes/class-autoloader.php';
MealsDB_Autoloader::register(MEALS_DB_PLUGIN_DIR);

// Composer autoloader for third-party dependencies (e.g. DomPDF).
// Loaded after the in-house autoloader so plugin classes always
// resolve first; the file-existence guard keeps the plugin loadable
// in a checkout that hasn't run `composer install` yet.
$mealsdb_composer_autoload = MEALS_DB_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($mealsdb_composer_autoload)) {
    require_once $mealsdb_composer_autoload;
}
unset($mealsdb_composer_autoload);

add_action('plugins_loaded', function() {
    if (class_exists('MealsDB_Config')) {
        MealsDB_Config::instance();
    }
});

// New loader for cleaner plugin interface.

require_once __DIR__ . '/includes/class-plugin.php';
MealsDB_Plugin::init();

/**
 * Initialize plugin functionality after all plugins are loaded.
 */
add_action('plugins_loaded', function () {
    MealsDB_Admin_UI::init();
    MealsDB_Ajax_Sync::init();
    MealsDB_Ajax_Clients::init();
    MealsDB_Ajax_Drafts::init();
    MealsDB_Ajax_Initials::init();
    MealsDB_Ajax_Invoice_Draft::init();
    MealsDB_Ajax_Delivery_Slips::init();
    MealsDB_Ajax_Slip_Batch::init();
    MealsDB_Ajax_Reports::init();
    MealsDB_Ajax_Purchase_Orders::init();
    MealsDB_Quick_Order_Products::init();
    MealsDB_Quick_Order_Ajax::init();
    MealsDB_Staff::init();
    MealsDB_WC_Product_Tab::init();
    MealsDB_Invoice_Draft_Page::init();
    MealsDB_Slip_Batch_Page::init();
    MealsDB_Rate_Definitions_Page::init();
    MealsDB_Ajax_Rate_Definitions::init();
    MealsDB_Migration_Page::init();
    MealsDB_Ajax_Migration::init();
    MealsDB_Ajax_DB_Sync::init();
    MealsDB_Ajax_Settings::init();
    MealsDB_Product_Display_Sync::init();
    MealsDB_Sync::register_hooks();
    MealsDB_Allocation_Hooks::init();
    MealsDB_Private_Intake::init();

    // Task engine (Phase R1 + R2).
    MealsDB_Task_Type_Generic_Reminder::register();
    MealsDB_Task_Type_Call_Client::register();
    MealsDB_Task_Type_Client_Delivery::register();
    MealsDB_Task_Type_PO_Confirm_Arrival::register();
    MealsDB_Task_Type_PO_Reconcile::register();

    MealsDB_Task_Rules::register_strategy(
        'clients_due_to_reorder',
        [MealsDB_Task_Type_Call_Client::class, 'clients_due_to_reorder_strategy']
    );

    MealsDB_Task_Rules::register_strategy(
        'clients_due_for_delivery',
        [MealsDB_Task_Type_Client_Delivery::class, 'clients_due_for_delivery_strategy']
    );

    MealsDB_Ajax_Tasks::init();
    MealsDB_Task_Cron::init();
    MealsDB_PO_Task_Bridge::init();

    // Phase W — cron monitoring & hook observability.
    // Daily report runs at 04:00 (effective ~04:15 with cPanel cron's
    // :15/:45 offset); retention cron at 04:30 prunes the log tables
    // off the customer request path. Cron Status admin page exposes
    // a live operator view + "Send Test Report Now" button.
    MealsDB_Daily_Report::register_hooks();
    MealsDB_Log_Retention::register_hooks();
    MealsDB_Cron_Status_Page::init();

    // Directive ITEM1-DERIVED — nightly derived-value integrity check.
    // Runs ~03:30 (after the 03:00 allocation sync, before the 04:00 daily
    // report). Flags drifted next_*_date / delivery_day values as degraded
    // trunk events; auto-correct is per-field opt-in and OFF by default.
    MealsDB_Derived_Value_Audit::init();

    // Directive STR-LOG — central event-log trunk. The Event Log
    // dashboard (manage_options) reads meals_event_log + meals_audit_log;
    // the digest sweeps failed/degraded events at ~05:00 and emails a
    // scrubbed summary (out of the hot path — never inside record()).
    MealsDB_Event_Log_Page::init();
    MealsDB_Event_Digest::register_hooks();
});

/**
 * Run schema install/upgrade when the plugin version advances.
 *
 * WordPress only fires register_activation_hook() on explicit activation,
 * so auto-updates, manual file replacement, and fresh activation (which
 * hands the schema off to this block rather than doing the heavy work
 * inside the activation hook itself) all funnel through the same path
 * here. The installer is idempotent; this hook just decides whether
 * it's time to run it and serialises concurrent admin requests.
 */
add_action('admin_init', 'mealsdb_maybe_upgrade_schema');

function mealsdb_maybe_upgrade_schema(): void {
    // Only run the installer for users who could legitimately be
    // triggering a plugin upgrade. admin_init also fires on
    // admin-ajax.php and for any logged-in user who happens to hit an
    // admin-facing URL; there's no reason to run dbDelta on behalf of a
    // subscriber poking at the UI.
    if (!current_user_can('activate_plugins') && !current_user_can('manage_options')) {
        return;
    }

    $stored = get_option('mealsdb_db_version', '0.0.0');
    if (version_compare($stored, MEALS_DB_VERSION, '>=')) {
        return;
    }

    // Atomic install lock — shared with MealsDB_Updates::run_database_maintenance
    // so a manual "update database" run can't race this auto-upgrade.
    if (!mealsdb_acquire_install_lock()) {
        return;
    }

    try {
        require_once plugin_dir_path(__FILE__) . 'includes/install-schema.php';
        MealsDB_Installer::install();

        // Seed zone schedule for auto-updated installs that never
        // fired register_activation_hook. autoload='no' because this
        // is only read from the delivery-slip and report paths, not
        // every page load.
        if (false === get_option('mealsdb_zone_delivery_schedule')) {
            add_option('mealsdb_zone_delivery_schedule', [
                'Zone 1' => ['day' => 'Wednesday', 'label' => 'Wednesday morning - Moncton Downtown'],
                'Zone 2' => ['day' => 'Wednesday', 'label' => 'Wednesday afternoon - Sackville / Amherst'],
                'Zone 3' => ['day' => 'Thursday',  'label' => 'Thursday morning - Moncton Other / Sussex'],
                'Zone 4' => ['day' => 'Thursday',  'label' => 'Thursday afternoon - Shediac'],
                'Zone 5' => ['day' => 'Friday',    'label' => 'Friday - Dieppe / Riverview'],
                'Zone 6' => ['day' => 'Thursday',  'label' => 'Thursday morning - Sussex (combined with Zone 3)'],
            ], '', 'no');
        }

        // delivery_day is zone-derived (spec 2026-07-11): fresh installs get
        // nightly auto-correct ON for it. Installs with a stored option keep
        // their choice (save_settings always writes all three keys, so any
        // operator who has ever saved settings has one).
        if (false === get_option('mealsdb_derived_autocorrect')) {
            add_option('mealsdb_derived_autocorrect', [
                'next_order_date'    => 0,
                'next_delivery_date' => 0,
                'delivery_day'       => 1,
            ], '', 'no');
        }

        update_option('mealsdb_db_version', MEALS_DB_VERSION, false);
    } catch (Throwable $e) {
        // Log but don't rethrow — next admin page load retries. The
        // lock still releases via the finally below so a single
        // failed attempt doesn't block recovery.
        error_log('[MealsDB] Install/upgrade failed: ' . $e->getMessage());
    } finally {
        mealsdb_release_install_lock();
    }
}

/**
 * Acquire the atomic install lock. Returns true if THIS caller now holds it.
 *
 * The InnoDB UNIQUE index on option_name serialises concurrent inserts:
 * whichever request lands first gets rows_affected=1; every other request gets
 * 0 and is told the lock is held. A transient or a WP-API add_option() is NOT
 * safe here because add_option() uses INSERT ... ON DUPLICATE KEY UPDATE, which
 * succeeds (as an update) under contention. Includes 5-minute stale-lock
 * recovery for a prior attempt that died mid-install (PHP fatal, OOM, timeout).
 *
 * Shared by the admin_init auto-upgrade and the manual
 * MealsDB_Updates::run_database_maintenance() endpoint.
 *
 * @return bool
 */
function mealsdb_acquire_install_lock() {
    global $wpdb;

    // Stale-lock recovery. Read directly from the DB to bypass the notoptions
    // cache, which can incorrectly claim the row doesn't exist after a prior
    // request's fatal error.
    $held_at = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1",
        'mealsdb_install_lock'
    ));
    if ($held_at > 0 && (time() - $held_at) > 300) {
        delete_option('mealsdb_install_lock');
    }

    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO `{$wpdb->options}` (option_name, option_value, autoload) VALUES (%s, %s, %s)",
        'mealsdb_install_lock',
        (string) time(),
        'no'
    ));

    if ((int) $wpdb->rows_affected !== 1) {
        return false;
    }

    // Invalidate the notoptions cache so the release-path delete_option actually
    // clears the real row rather than short-circuiting on a stale "option does
    // not exist" cache entry.
    wp_cache_delete('notoptions', 'options');

    return true;
}

/**
 * Release the atomic install lock held by mealsdb_acquire_install_lock().
 *
 * @return void
 */
function mealsdb_release_install_lock() {
    delete_option('mealsdb_install_lock');
}

/**
 * Notice shown in the admin area while the schema is still pending
 * install (stored version = 0.0.0). The installer runs on admin_init,
 * so a single admin page load after activation clears this.
 */
add_action('admin_notices', function () {
    if (get_option('mealsdb_db_version', '0.0.0') !== '0.0.0') {
        return;
    }
    if (!current_user_can('activate_plugins') && !current_user_can('manage_options')) {
        return;
    }
    echo '<div class="notice notice-info"><p>';
    echo esc_html__(
        'Meals Database: finishing schema installation on the next admin page load…',
        'meals-db'
    );
    echo '</p></div>';
});

/**
 * Security notice: encryption key is still stored in wp_options.
 *
 * The original implementation read the AES-256 key from
 * mealsdb_settings.encryption_key. That works but any compromise of
 * the MySQL data directory (backup tarball, replica dump, SQL-injection
 * exfil) reveals the key alongside the ciphertext it protects. The
 * remediated path reads from the MEALS_DB_KEY constant (wp-config.php)
 * or the MEALS_DB_ENCRYPTION_KEY environment variable; when it's still
 * coming from the database, flag it loudly so the operator migrates.
 */
add_action('admin_notices', function () {
    if (!class_exists('MealsDB_Encryption')) {
        return;
    }
    if (MealsDB_Encryption::key_source() !== 'option') {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    $opts = get_option('mealsdb_settings', []);
    $key  = is_array($opts) && !empty($opts['encryption_key']) ? (string) $opts['encryption_key'] : '';

    // Only reveal the LIVE key on the plugin's own Settings screen
    // (admin.php?page=mealsdb-settings) — the one place the operator can
    // already see it (the encryption_key field in views/settings.php). This
    // admin_notices hook fires on EVERY wp-admin page; echoing the secret into
    // the DOM of unrelated pages (dashboard, posts, plugins, …) needlessly
    // widened its exposure to browser extensions with page-read access, support
    // screenshots/screen shares, and page caches. Everywhere else we render the
    // placeholder line only; the migration guidance itself is unchanged.
    $on_settings_screen = false;
    if (isset($_GET['page'])) {
        $current_page = sanitize_key(wp_unslash((string) $_GET['page']));
        // PR 3: Settings moved from ?page=mealsdb&tab=settings to its own
        // mealsdb-settings page — key the live-key reveal to the new slug.
        $on_settings_screen = ($current_page === 'mealsdb-settings');
    }

    echo '<div class="notice notice-warning"><p><strong>';
    echo esc_html__('Meals Database: encryption key is stored in wp_options.', 'meals-db');
    echo '</strong></p><p>';
    echo esc_html__(
        'Move it to wp-config.php so a database dump can\'t reveal the key. Add this line above the "/* That\'s all, stop editing! */" marker:',
        'meals-db'
    );
    echo '</p><p><code>';
    if ($on_settings_screen && $key !== '') {
        echo "define('MEALS_DB_KEY', '" . esc_html($key) . "');";
    } else {
        echo "define('MEALS_DB_KEY', 'base64:YOUR_KEY_HERE');";
    }
    echo '</code></p><p>';
    echo esc_html__(
        'After adding the constant, reload this page to confirm the notice is gone, then delete the "encryption_key" entry from the Meals DB settings row in wp_options.',
        'meals-db'
    );
    echo '</p></div>';
});

/**
 * Security notice: the pre-HMAC legacy CBC decryption branch is still
 * live (MEALSDB_DISABLE_LEGACY_DECRYPT constant not set).
 *
 * That branch runs AES-CBC decryption without integrity verification —
 * the textbook Vaudenay padding-oracle setup. It exists so installs
 * with pre-HMAC ciphertext in meals_clients can still decrypt during
 * the migration window, but once the encryption migrator reports zero
 * legacy rows the operator should flip the constant and ensure all
 * decrypts go through the authenticated path.
 *
 * Surface two variants of the notice:
 *   - If legacy payloads exist: instruct the operator to run the
 *     migrator, with a count of outstanding rows.
 *   - If zero legacy payloads: instruct the operator to set the
 *     constant and close the attack surface entirely.
 */
add_action('admin_notices', function () {
    if (!class_exists('MealsDB_Encryption') || !class_exists('MealsDB_Encryption_Migrator')) {
        return;
    }
    if (MealsDB_Encryption::legacy_decrypt_disabled()) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    // Inventory walks meals_clients so is cached for 6h by default.
    // A plugin reader who just wants to check their admin pages pays
    // the scan once per reading window.
    $legacy_total = MealsDB_Encryption_Migrator::legacy_total_cached();

    if ($legacy_total > 0) {
        echo '<div class="notice notice-warning"><p><strong>';
        echo esc_html(sprintf(
            /* translators: %d: number of legacy encrypted rows */
            _n(
                'Meals Database: %d row is still in the legacy CBC format.',
                'Meals Database: %d rows are still in the legacy CBC format.',
                $legacy_total,
                'meals-db'
            ),
            $legacy_total
        ));
        echo '</strong></p><p>';
        echo esc_html__(
            'The legacy format decrypts without integrity verification, which is susceptible to padding-oracle attacks. Run "wp mealsdb reencrypt-legacy" (WP-CLI; use --dry-run first to preview) to re-encrypt these rows under the authenticated format.',
            'meals-db'
        );
        echo '</p><p>';
        echo esc_html__(
            'Once the migrator reports zero legacy rows, add this line to wp-config.php to disable the legacy path entirely:',
            'meals-db'
        );
        echo '</p><p><code>';
        echo "define('MEALSDB_DISABLE_LEGACY_DECRYPT', true);";
        echo '</code></p></div>';
        return;
    }

    // No legacy payloads left — nudge the operator to close the branch.
    echo '<div class="notice notice-info is-dismissible"><p><strong>';
    echo esc_html__('Meals Database: legacy decryption can be disabled.', 'meals-db');
    echo '</strong></p><p>';
    echo esc_html__(
        'The encryption inventory is clean — no legacy CBC payloads remain. Add this line to wp-config.php so the plugin refuses any future legacy payload that might slip in via backup restore or migration:',
        'meals-db'
    );
    echo '</p><p><code>';
    echo "define('MEALSDB_DISABLE_LEGACY_DECRYPT', true);";
    echo '</code></p></div>';
});

/**
 * Runtime warning when WooCommerce has been deactivated while Meals DB
 * is still active. Activation refused this up-front, but an operator
 * may deactivate WC later. The plugin will keep loading (so the
 * operator can still reach settings pages to fix things) but flag the
 * problem loudly — every order-aware code path assumes WC is present.
 */
add_action('admin_notices', function () {
    if (class_exists('WooCommerce')) {
        return;
    }
    if (!current_user_can('activate_plugins')) {
        return;
    }
    echo '<div class="notice notice-error"><p><strong>';
    echo esc_html__('Meals Database: WooCommerce is not active.', 'meals-db');
    echo '</strong></p><p>';
    echo esc_html__(
        'Meals DB depends on WooCommerce for orders, products, and capability checks. Reactivate WooCommerce or deactivate Meals DB to restore normal behaviour — several admin pages will fatal on load until one or the other happens.',
        'meals-db'
    );
    echo '</p></div>';
});

/**
 * Previous-uninstall drop-failure notice.
 *
 * If uninstall.php couldn't DROP one or more of the plugin tables
 * (permissions, foreign-key checks, table still in use), it writes
 * the failed table names into a 30-day site transient. Surface that
 * as an admin notice on the next activation so the operator knows
 * orphaned tables are still around and can clean them up manually.
 * Dismissable via the transient delete button in the notice.
 */
add_action('admin_notices', function () {
    $failed = get_transient('mealsdb_uninstall_drop_failures');
    if (!is_array($failed) || empty($failed)) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_GET['mealsdb_ack_drop_failures']) && check_admin_referer('mealsdb_ack_drop_failures')) {
        delete_transient('mealsdb_uninstall_drop_failures');
        return;
    }

    echo '<div class="notice notice-warning"><p><strong>';
    echo esc_html__('Meals Database: tables left behind from a previous uninstall.', 'meals-db');
    echo '</strong></p><p>';
    echo esc_html(sprintf(
        _n(
            'The uninstaller could not drop %d table last time this plugin was removed:',
            'The uninstaller could not drop %d tables last time this plugin was removed:',
            count($failed),
            'meals-db'
        ),
        count($failed)
    ));
    echo '</p><ul style="list-style:disc;padding-left:24px;"><li>';
    echo implode('</li><li>', array_map('esc_html', $failed));
    echo '</li></ul><p>';
    echo esc_html__('Drop them manually with phpMyAdmin or the mysql CLI once you\'ve confirmed the data is no longer needed.', 'meals-db');
    $ack_url = wp_nonce_url(add_query_arg('mealsdb_ack_drop_failures', '1'), 'mealsdb_ack_drop_failures');
    echo ' <a href="' . esc_url($ack_url) . '">' . esc_html__('Dismiss', 'meals-db') . '</a>';
    echo '</p></div>';
});

/**
 * Check minimum PHP and WordPress versions before allowing activation.
 */
register_activation_hook(__FILE__, 'meals_db_check_requirements');

function meals_db_check_requirements() {
    global $wp_version;

    // Match the real deployment floor (PHP 8.2 / WP 7.0). The plugin USES 8.x
    // language features (typed properties, match, \Throwable in cron handlers),
    // so refuse cleanly at activation rather than fataling at runtime on an
    // interpreter too old to parse the code. Keep in sync with the plugin
    // header above and composer.json (STR-9).
    $required_php_version = '8.2';
    $required_wp_version = '7.0';

    if (version_compare(PHP_VERSION, $required_php_version, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                esc_html__(
                    'Meals DB requires PHP version %1$s or higher. Your current version is: %2$s.',
                    'meals-db'
                ),
                $required_php_version,
                PHP_VERSION
            ),
            esc_html__('Plugin Activation Error', 'meals-db'),
            ['back_link' => true]
        );
    }

    if (version_compare($wp_version, $required_wp_version, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                esc_html__(
                    'Meals DB requires WordPress version %1$s or higher. Your current version is: %2$s.',
                    'meals-db'
                ),
                $required_wp_version,
                $wp_version
            ),
            esc_html__('Plugin Activation Error', 'meals-db'),
            ['back_link' => true]
        );
    }

    // WooCommerce is a hard dependency — the plugin's capability gate
    // (MealsDB_Permissions::required_capability() defaults to
    // 'manage_woocommerce') assumes it's present, and most of the
    // reporting / order code calls wc_get_order() / wc_get_product()
    // directly. Activating without WooCommerce would silently admit
    // users through the capability check (any administrator satisfies
    // 'manage_woocommerce' in WP core even when the mapped meta-cap
    // is missing) and then fatal on the first AJAX request that hits
    // a WC helper. Refuse activation up-front with a clear message.
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__(
                'Meals DB requires WooCommerce to be installed and active. Please activate WooCommerce first, then try activating Meals DB again.',
                'meals-db'
            ),
            esc_html__('Plugin Activation Error', 'meals-db'),
            ['back_link' => true]
        );
    }

    // Schema installation and zone-schedule seeding run on the next
    // admin page load via mealsdb_maybe_upgrade_schema(), which is
    // atomic, idempotent, and also handles auto-updates and manual
    // file replacement where register_activation_hook() never fires.
    // Keeping heavy dbDelta work out of the activation request avoids
    // activation-time PHP timeouts that would otherwise leave the
    // plugin deactivated with a half-built schema.
}

/**
 * Clean up scheduled events on plugin deactivation.
 *
 * Every cron hook the plugin schedules MUST appear here, otherwise
 * WordPress will keep firing it after deactivation against
 * undefined callbacks (PHP fatal: "Call to undefined function" on
 * each cron tick). Today the plugin schedules:
 *
 *   - mealsdb_nightly_allocation_sync (class-allocation-hooks.php)
 *   - mealsdb_nightly_sync            (class-sync.php)
 *   - mealsdb_nightly_task_sync       (class-task-cron.php)
 *   - mealsdb_daily_report            (class-daily-report.php)
 *   - mealsdb_log_retention           (class-log-retention.php)
 *   - mealsdb_event_digest            (class-event-digest.php)
 *   - mealsdb_derived_value_audit     (class-derived-value-audit.php)
 *
 * The original handler only cleared the first one; the second was
 * orphaned and would re-fire daily on a deactivated install.
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('mealsdb_nightly_allocation_sync');
    wp_clear_scheduled_hook('mealsdb_nightly_sync');
    wp_clear_scheduled_hook('mealsdb_nightly_task_sync');
    wp_clear_scheduled_hook('mealsdb_daily_report');
    wp_clear_scheduled_hook('mealsdb_log_retention');
    wp_clear_scheduled_hook('mealsdb_event_digest');
    wp_clear_scheduled_hook('mealsdb_derived_value_audit');
});

// Register the plugin update checker against the GitHub repository.
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';

$updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/zakorski/meals-db/',
    __FILE__,
    'meals-db-main'
);

$updateChecker->setBranch('main');
$updateChecker->getVcsApi()->enableReleaseAssets();

// WP-CLI tooling. Registered only under WP-CLI so the class (which calls
// \WP_CLI) never loads in a web request. `wp mealsdb reencrypt-legacy` drives
// the encryption-harden migration the admin notice references.
if (defined('WP_CLI') && WP_CLI && class_exists('MealsDB_CLI')) {
    \WP_CLI::add_command('mealsdb reencrypt-legacy', ['MealsDB_CLI', 'reencrypt_legacy']);
}
