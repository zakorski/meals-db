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

        add_submenu_page(
            'mealsdb',
            __( 'Site Migration', 'meals-db' ),
            __( 'Migration', 'meals-db' ),
            'manage_options',
            'mealsdb-migration',
            [ self::class, 'render' ]
        );
    }

    public static function enqueue_assets( $hook ): void {
        if ( $hook !== 'meals-db_page_mealsdb-migration' ) {
            return;
        }

        wp_enqueue_style(
            'mealsdb-migration',
            MEALS_DB_PLUGIN_URL . 'assets/css/admin-migration.css',
            [],
            MEALS_DB_VERSION
        );

        wp_enqueue_script(
            'mealsdb-migration',
            MEALS_DB_PLUGIN_URL . 'assets/js/admin-migration.js',
            [ 'jquery' ],
            MEALS_DB_VERSION,
            true
        );

        wp_localize_script( 'mealsdb-migration', 'mealsdbMigration', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'mealsdb_migration_nonce' ),
        ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'meals-db' ) );
        }

        ?>
        <div class="wrap mealsdb-migration-page">
            <h1><?php esc_html_e( 'Site Migration', 'meals-db' ); ?></h1>

            <div class="mealsdb-mig-info notice notice-warning inline">
                <p><strong><?php esc_html_e( 'One-time migration tool.', 'meals-db' ); ?></strong>
                <?php esc_html_e( 'Imports users, products, and orders from a legacy SQL dump into this WordPress site, then creates government client records in the Meals DB external database.', 'meals-db' ); ?></p>
                <p><?php esc_html_e( 'Place the SQL dump file on the server (e.g. via SFTP) and enter the absolute path below.', 'meals-db' ); ?></p>
            </div>

            <!-- Step 1: File path + prefix detection -->
            <div class="mealsdb-mig-card" id="mig-step-setup">
                <h2><?php esc_html_e( 'Step 1: Source File', 'meals-db' ); ?></h2>

                <table class="form-table">
                    <tr>
                        <th><label for="mig-file-path"><?php esc_html_e( 'SQL dump path', 'meals-db' ); ?></label></th>
                        <td>
                            <input type="text" id="mig-file-path" class="regular-text" placeholder="/var/www/html/mealsand_wp_ba74f.sql">
                            <button type="button" class="button" id="mig-detect-btn"><?php esc_html_e( 'Detect Prefix', 'meals-db' ); ?></button>
                            <p class="description"><?php esc_html_e( 'Absolute path to the .sql file on this server.', 'meals-db' ); ?></p>
                        </td>
                    </tr>
                    <tr id="mig-prefix-row" style="display:none;">
                        <th><?php esc_html_e( 'Detected prefix', 'meals-db' ); ?></th>
                        <td>
                            <code id="mig-prefix-value"></code>
                            <span id="mig-file-size"></span>
                        </td>
                    </tr>
                </table>

                <div class="mealsdb-mig-options" id="mig-options" style="display:none;">
                    <h3><?php esc_html_e( 'Options', 'meals-db' ); ?></h3>
                    <label>
                        <input type="checkbox" id="mig-dry-run" checked>
                        <?php esc_html_e( 'Dry run (preview only, no writes)', 'meals-db' ); ?>
                    </label>
                    <br><br>
                    <button type="button" class="button button-primary button-hero" id="mig-start-btn">
                        <?php esc_html_e( 'Start Migration', 'meals-db' ); ?>
                    </button>
                </div>
            </div>

            <!-- Step 2: Progress -->
            <div class="mealsdb-mig-card" id="mig-step-progress" style="display:none;">
                <h2><?php esc_html_e( 'Step 2: Progress', 'meals-db' ); ?></h2>

                <div class="mealsdb-mig-phases">
                    <?php
                    $phases = [
                        0 => __( 'Load Source Tables', 'meals-db' ),
                        1 => __( 'Migrate Users', 'meals-db' ),
                        2 => __( 'Migrate Products', 'meals-db' ),
                        3 => __( 'Migrate Orders', 'meals-db' ),
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
                <button type="button" class="button" id="mig-cleanup-btn">
                    <?php esc_html_e( 'Cleanup Source Tables', 'meals-db' ); ?>
                </button>
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
        </div>
        <?php
    }
}
