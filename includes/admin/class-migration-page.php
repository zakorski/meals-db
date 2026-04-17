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

        $migration_data = [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'mealsdb_migration_nonce' ),
            'maxUploadMb'  => min(
                (int) ini_get( 'upload_max_filesize' ),
                (int) ini_get( 'post_max_size' )
            ),
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

        ?>
        <div class="wrap mealsdb-migration-page">
            <h1><?php esc_html_e( 'Site Migration', 'meals-db' ); ?></h1>

            <div class="mealsdb-mig-info notice notice-warning inline">
                <p><strong><?php esc_html_e( 'One-time migration tool.', 'meals-db' ); ?></strong>
                <?php esc_html_e( 'Imports users, products, and orders from the legacy site into this WordPress site, then creates government client records in the Meals DB external database.', 'meals-db' ); ?></p>
            </div>

            <!-- Step 1: Source selection -->
            <div class="mealsdb-mig-card" id="mig-step-setup">
                <h2><?php esc_html_e( 'Step 1: Data Source', 'meals-db' ); ?></h2>

                <div class="mealsdb-mig-source-tabs">
                    <button type="button" class="button mig-tab active" data-tab="db">
                        <?php esc_html_e( 'Database Connection', 'meals-db' ); ?>
                    </button>
                    <button type="button" class="button mig-tab" data-tab="upload">
                        <?php esc_html_e( 'Upload SQL File', 'meals-db' ); ?>
                    </button>
                    <button type="button" class="button mig-tab" data-tab="filepath">
                        <?php esc_html_e( 'Server File Path', 'meals-db' ); ?>
                    </button>
                </div>

                <!-- Database connection tab -->
                <div class="mealsdb-mig-tab-content" id="mig-tab-db">
                    <p class="description"><?php esc_html_e( 'Connect directly to the legacy database on the same MySQL server. This is the recommended option — no file upload needed.', 'meals-db' ); ?></p>
                    <table class="form-table">
                        <tr>
                            <th><label for="mig-db-host"><?php esc_html_e( 'Host', 'meals-db' ); ?></label></th>
                            <td>
                                <input type="text" id="mig-db-host" class="regular-text" value="localhost" placeholder="localhost">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="mig-db-name"><?php esc_html_e( 'Database Name', 'meals-db' ); ?></label></th>
                            <td>
                                <input type="text" id="mig-db-name" class="regular-text" placeholder="mealsandmore_wp">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="mig-db-user"><?php esc_html_e( 'Username', 'meals-db' ); ?></label></th>
                            <td>
                                <input type="text" id="mig-db-user" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="mig-db-pass"><?php esc_html_e( 'Password', 'meals-db' ); ?></label></th>
                            <td>
                                <input type="password" id="mig-db-pass" class="regular-text">
                            </td>
                        </tr>
                    </table>
                    <button type="button" class="button" id="mig-test-db-btn">
                        <?php esc_html_e( 'Test Connection & Detect Prefix', 'meals-db' ); ?>
                    </button>
                </div>

                <!-- Upload tab -->
                <div class="mealsdb-mig-tab-content" id="mig-tab-upload" style="display:none;">
                    <p class="description">
                        <?php
                        printf(
                            esc_html__( 'Upload the SQL dump file directly. Current max upload size: %s MB. For larger files, use the Database Connection option instead.', 'meals-db' ),
                            esc_html( min( (int) ini_get( 'upload_max_filesize' ), (int) ini_get( 'post_max_size' ) ) )
                        );
                        ?>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th><label for="mig-file-upload"><?php esc_html_e( 'SQL File', 'meals-db' ); ?></label></th>
                            <td>
                                <input type="file" id="mig-file-upload" accept=".sql,.sql.gz">
                                <button type="button" class="button" id="mig-upload-btn">
                                    <?php esc_html_e( 'Upload & Detect Prefix', 'meals-db' ); ?>
                                </button>
                                <div id="mig-upload-progress" style="display:none;">
                                    <span id="mig-upload-status"><?php esc_html_e( 'Uploading...', 'meals-db' ); ?></span>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Server file path tab -->
                <div class="mealsdb-mig-tab-content" id="mig-tab-filepath" style="display:none;">
                    <p class="description"><?php esc_html_e( 'Enter the full server path to a SQL dump file already uploaded via FTP or cPanel File Manager.', 'meals-db' ); ?></p>
                    <table class="form-table">
                        <tr>
                            <th><label for="mig-file-path"><?php esc_html_e( 'SQL dump path', 'meals-db' ); ?></label></th>
                            <td>
                                <input type="text" id="mig-file-path" class="regular-text" placeholder="/home/username/mealsandmore.sql">
                                <button type="button" class="button" id="mig-detect-btn">
                                    <?php esc_html_e( 'Detect Prefix', 'meals-db' ); ?>
                                </button>
                                <p class="description"><?php esc_html_e( 'Absolute path to the .sql file on this server.', 'meals-db' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Prefix result (shared by all tabs) -->
                <div id="mig-prefix-result" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Detected prefix', 'meals-db' ); ?></th>
                            <td>
                                <code id="mig-prefix-value"></code>
                                <span id="mig-source-info"></span>
                            </td>
                        </tr>
                    </table>

                    <div class="mealsdb-mig-options">
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
