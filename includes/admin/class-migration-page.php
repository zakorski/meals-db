<?php
/**
 * Admin page for the consolidated site migration tool.
 *
 * @package MealsDB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MealsDB_Migration_Page {

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menu' ], 22 );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
    }

    public static function register_menu(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Parent is toggle-dependent (advanced-tools visibility): 'mealsdb'
        // when shown, '' (registered but menu-less) when hidden.
        $parent = class_exists('MealsDB_Advanced_Tools')
            ? MealsDB_Advanced_Tools::menu_parent()
            : 'mealsdb';

        add_submenu_page(
            $parent,
            __( 'Site Migration', 'meals-db' ),
            __( 'Migration', 'meals-db' ),
            'manage_options',
            'mealsdb-migration',
            [ self::class, 'render' ]
        );
    }

    public static function enqueue_assets( $hook ): void {
        // The hook suffix depends on the advanced-tools toggle: visible
        // pages get 'meals-db_page_{slug}', hidden ones 'admin_page_{slug}'.
        if ( ! in_array( $hook, [ 'meals-db_page_mealsdb-migration', 'admin_page_mealsdb-migration' ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'mealsdb-migration',
            MEALS_DB_PLUGIN_URL . 'assets/css/admin-migration.css',
            [],
            MEALS_DB_VERSION
        );

        // Shared on-page notice helper (directive GUI-NOTICES) — supplies
        // window.MealsDBNotice for the migration driver's informational alerts.
        $notice_handle = MealsDB_Admin_UI::register_notice_script();

        wp_enqueue_script(
            'mealsdb-migration',
            MEALS_DB_PLUGIN_URL . 'assets/js/admin-migration.js',
            [ 'jquery', $notice_handle ],
            MEALS_DB_VERSION,
            true
        );

        // wp_max_upload_size() correctly parses unit suffixes (10M, 2G);
        // (int) ini_get(...) silently truncated "100M" to 100 here.
        $max_bytes      = function_exists( 'wp_max_upload_size' ) ? wp_max_upload_size() : 0;
        $migration_data = [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'mealsdb_migration_nonce' ),
            'maxUploadMb'  => $max_bytes > 0 ? (int) round( $max_bytes / ( 1024 * 1024 ) ) : 0,
        ];
        wp_add_inline_script(
            'mealsdb-migration',
            'window.mealsdbMigration = ' . wp_json_encode( $migration_data ) . ';',
            'before'
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'meals-db' ) );
        }

        // Refuse to render the migration UI over plain HTTP. The page
        // collects source-DB credentials (host/name/user/password) and
        // submits them via AJAX; on a non-TLS connection those credentials
        // travel in the clear and end up in the browser history / DOM.
        // Operators who genuinely need to run this on an HTTP-only host
        // can opt in via the MEALSDB_MIGRATION_ALLOW_HTTP constant.
        $allow_http = defined( 'MEALSDB_MIGRATION_ALLOW_HTTP' ) && MEALSDB_MIGRATION_ALLOW_HTTP;
        if ( ! is_ssl() && ! $allow_http ) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Site Migration', 'meals-db' ); ?></h1>
                <div class="notice notice-error">
                    <p>
                        <strong><?php esc_html_e( 'HTTPS required.', 'meals-db' ); ?></strong>
                        <?php esc_html_e( 'The migration tool collects database credentials and refuses to load over a plain HTTP connection. Re-open this page over HTTPS, or set MEALSDB_MIGRATION_ALLOW_HTTP to true in wp-config.php to override (not recommended).', 'meals-db' ); ?>
                    </p>
                </div>
            </div>
            <?php
            return;
        }

        ?>
        <div class="wrap mealsdb-migration-page">
            <h1><?php esc_html_e( 'Site Migration', 'meals-db' ); ?></h1>

            <div class="mealsdb-mig-info notice notice-info inline">
                <p><strong><?php esc_html_e( 'Migration tool.', 'meals-db' ); ?></strong>
                <?php esc_html_e( 'Builds government client records in Meals DB from data already in this WordPress / WooCommerce site. Use the Consolidated Migration below.', 'meals-db' ); ?></p>
            </div>

            <!-- Step 2: Progress -->
            <div class="mealsdb-mig-card" id="mig-step-progress" style="display:none;">
                <h2><?php esc_html_e( 'Progress', 'meals-db' ); ?></h2>

                <div class="mealsdb-mig-phases">
                    <?php
                    $phases = [
                        4 => __( 'Create Meals Clients', 'meals-db' ),
                        5 => __( 'Create Client Rates', 'meals-db' ),
                    ];
                    foreach ( $phases as $num => $label ) :
                        ?>
                        <div class="mealsdb-mig-phase" id="mig-phase-<?php echo $num; ?>">
                            <span class="mealsdb-mig-phase-icon">&#9711;</span>
                            <span class="mealsdb-mig-phase-label"><?php echo esc_html( $label ); ?></span>
                            <span class="mealsdb-mig-phase-status"></span>
                            <div class="mealsdb-mig-phase-bar-wrap">
                                <div class="mealsdb-mig-phase-bar"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="mig-current-stats" class="mealsdb-mig-stats"></div>
            </div>

            <!-- Step 3: Results -->
            <div class="mealsdb-mig-card" id="mig-step-results" style="display:none;">
                <h2><?php esc_html_e( 'Migration Complete', 'meals-db' ); ?></h2>
                <div id="mig-results-summary"></div>
                <br>
                <button type="button" class="button" id="mig-log-btn">
                    <?php esc_html_e( 'View Log', 'meals-db' ); ?>
                </button>
                <button type="button" class="button" id="mig-reset-btn">
                    <?php esc_html_e( 'Reset', 'meals-db' ); ?>
                </button>
            </div>

            <!-- Log viewer -->
            <div class="mealsdb-mig-card" id="mig-log-viewer" style="display:none;">
                <h3><?php esc_html_e( 'Migration Log', 'meals-db' ); ?></h3>
                <pre id="mig-log-content"></pre>
            </div>

            <!-- Consolidated post-import pipeline -->
            <div class="mealsdb-mig-card" id="cons-card">
                <h2><?php esc_html_e( 'Consolidated Migration (WP &rarr; Meals DB)', 'meals-db' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Runs every WP/WooCommerce -> meals_* data-movement step in dependency order: create clients, create rates, backfill allowances, addresses, next dates, promote private clients, and backfill allocations. Run AFTER the import above (or standalone during integration). Dry run is on by default.', 'meals-db' ); ?>
                </p>

                <p>
                    <label><input type="checkbox" id="cons-dry-run" checked> <?php esc_html_e( 'Dry run (no writes)', 'meals-db' ); ?></label>
                    &nbsp;
                    <label><input type="checkbox" id="cons-ignore-rate-limit"> <?php esc_html_e( 'Ignore rate limit (a full real run starts all 8 phases and exceeds the 5/hour cap)', 'meals-db' ); ?></label>
                </p>
                <p>
                    <label><?php esc_html_e( 'Private lookback (months):', 'meals-db' ); ?>
                        <input type="number" id="cons-lookback" value="24" min="1" style="width:6em;">
                    </label>
                    &nbsp;
                    <label><?php esc_html_e( 'Allocations start (YYYY-MM):', 'meals-db' ); ?>
                        <input type="text" id="cons-start-month" placeholder="optional" style="width:8em;">
                    </label>
                    &nbsp;
                    <label><?php esc_html_e( 'end (YYYY-MM):', 'meals-db' ); ?>
                        <input type="text" id="cons-end-month" placeholder="this month" style="width:8em;">
                    </label>
                </p>

                <div class="mealsdb-mig-phases">
                    <?php
                    $cons_phases = [
                        1 => __( 'Create Meals Clients', 'meals-db' ),
                        2 => __( 'Create Client Rates', 'meals-db' ),
                        3 => __( 'Backfill Allowances', 'meals-db' ),
                        4 => __( 'Backfill Addresses', 'meals-db' ),
                        5 => __( 'Backfill Next Dates', 'meals-db' ),
                        6 => __( 'Promote Private Clients', 'meals-db' ),
                        7 => __( 'Backfill Allocations', 'meals-db' ),
                        8 => __( 'Backfill Delivery Day', 'meals-db' ),
                    ];
                    foreach ( $cons_phases as $num => $label ) :
                        ?>
                        <div class="mealsdb-mig-phase" id="cons-phase-<?php echo (int) $num; ?>">
                            <span class="mealsdb-mig-phase-icon">&#9711;</span>
                            <span class="mealsdb-mig-phase-label"><?php echo esc_html( $label ); ?></span>
                            <span class="mealsdb-mig-phase-status"></span>
                            <div class="mealsdb-mig-phase-bar-wrap">
                                <div class="mealsdb-mig-phase-bar"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="cons-current-stats" class="mealsdb-mig-stats"></div>
                <p>
                    <button type="button" class="button button-primary" id="cons-run-btn">
                        <?php esc_html_e( 'Run Consolidated Migration', 'meals-db' ); ?>
                    </button>
                </p>
                <div id="cons-results" style="display:none;"></div>
            </div>
        </div>
        <?php
    }
}
