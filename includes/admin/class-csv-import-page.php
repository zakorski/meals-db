<?php
/**
 * Admin page for CSV user-client import.
 *
 * @package MealsDB
 */

class MealsDB_CSV_Import_Page {

    /**
     * Initialize the page
     */
    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 21);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    /**
     * Register menu page
     */
    public static function register_menu(): void {
        if (!MealsDB_Permissions::can_access_plugin()) {
            return;
        }

        add_submenu_page(
            'mealsdb',
            __('CSV Import (Users & Clients)', 'meals-db'),
            __('CSV Import', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb-csv-import',
            [self::class, 'render']
        );
    }

    /**
     * Enqueue assets
     */
    public static function enqueue_assets($hook): void {
        if ($hook !== 'meals-db_page_mealsdb-csv-import') {
            return;
        }

        wp_enqueue_style(
            'mealsdb-csv-import',
            MEALS_DB_PLUGIN_URL . 'assets/css/admin-csv-import.css',
            [],
            MEALS_DB_VERSION
        );

        wp_enqueue_script(
            'mealsdb-csv-import',
            MEALS_DB_PLUGIN_URL . 'assets/js/admin-csv-import.js',
            ['jquery'],
            MEALS_DB_VERSION,
            true
        );

        wp_localize_script('mealsdb-csv-import', 'mealsdbCSVImport', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mealsdb_csv_import_nonce'),
            'strings' => [
                'uploading' => __('Uploading...', 'meals-db'),
                'validating' => __('Validating CSV...', 'meals-db'),
                'importing' => __('Importing...', 'meals-db'),
                'complete' => __('Import complete!', 'meals-db'),
                'error' => __('Error', 'meals-db'),
                'confirmImport' => __('Are you sure you want to proceed with this import?', 'meals-db'),
            ],
        ]);
    }

    /**
     * Render the page
     */
    public static function render(): void {
        MealsDB_Permissions::enforce();

        ?>
        <div class="wrap mealsdb-csv-import-page">
            <h1><?php echo esc_html__('CSV Import - Users & Clients', 'meals-db'); ?></h1>

            <div class="mealsdb-import-info-box">
                <h3><?php echo esc_html__('Import Instructions', 'meals-db'); ?></h3>
                <ul>
                    <li><strong><?php echo esc_html__('WordPress Users:', 'meals-db'); ?></strong> <?php echo esc_html__('All fields will be OVERWRITTEN with CSV data', 'meals-db'); ?></li>
                    <li><strong><?php echo esc_html__('Meals DB Clients:', 'meals-db'); ?></strong> <?php echo esc_html__('New clients will be created. Existing clients will have ONLY NULL/empty fields filled (no overwriting)', 'meals-db'); ?></li>
                    <li><strong><?php echo esc_html__('CSV Format:', 'meals-db'); ?></strong> <?php echo esc_html__('144 columns with header row', 'meals-db'); ?></li>
                    <li><strong><?php echo esc_html__('File Size:', 'meals-db'); ?></strong> <?php echo esc_html__('Maximum 10MB', 'meals-db'); ?></li>
                </ul>
            </div>

            <div class="mealsdb-csv-import-container">
                <!-- Step 1: Upload -->
                <div id="mealsdb-csv-upload-section" class="mealsdb-csv-section">
                    <div class="mealsdb-csv-card">
                        <h2><?php echo esc_html__('Step 1: Upload CSV File', 'meals-db'); ?></h2>

                        <div class="mealsdb-csv-upload-area" id="mealsdb-csv-upload-area">
                            <div class="mealsdb-csv-upload-icon">📁</div>
                            <p class="mealsdb-csv-upload-text">
                                <?php echo esc_html__('Drag & drop CSV file here or click to browse', 'meals-db'); ?>
                            </p>
                            <input type="file" id="mealsdb-csv-file" accept=".csv" style="display: none;">
                            <button type="button" class="button button-primary" id="mealsdb-csv-browse-btn">
                                <?php echo esc_html__('Browse Files', 'meals-db'); ?>
                            </button>
                        </div>

                        <div id="mealsdb-csv-upload-progress" class="mealsdb-csv-upload-progress" style="display: none;">
                            <div class="mealsdb-csv-spinner"></div>
                            <p><?php echo esc_html__('Uploading and validating...', 'meals-db'); ?></p>
                        </div>

                        <div id="mealsdb-csv-upload-error" class="notice notice-error inline" style="display: none;">
                            <p></p>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Configure -->
                <div id="mealsdb-csv-config-section" class="mealsdb-csv-section" style="display: none;">
                    <div class="mealsdb-csv-card">
                        <h2><?php echo esc_html__('Step 2: Configure Import', 'meals-db'); ?></h2>

                        <div class="notice notice-success inline">
                            <p><?php echo esc_html__('CSV validated successfully!', 'meals-db'); ?></p>
                        </div>

                        <div id="mealsdb-csv-stats" class="mealsdb-csv-stats"></div>

                        <div id="mealsdb-csv-preview-container">
                            <h3><?php echo esc_html__('Preview of first 5 rows:', 'meals-db'); ?></h3>
                            <table class="wp-list-table widefat fixed striped" id="mealsdb-csv-preview-table">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('User ID', 'meals-db'); ?></th>
                                        <th><?php echo esc_html__('Name', 'meals-db'); ?></th>
                                        <th><?php echo esc_html__('Email', 'meals-db'); ?></th>
                                        <th><?php echo esc_html__('Type', 'meals-db'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="mealsdb-csv-preview-tbody"></tbody>
                            </table>
                        </div>

                        <div class="mealsdb-csv-options">
                            <h3><?php echo esc_html__('Import Options', 'meals-db'); ?></h3>
                            <label>
                                <input type="checkbox" id="mealsdb-csv-dry-run" checked>
                                <?php echo esc_html__('Dry run mode (preview only, don\'t import)', 'meals-db'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" id="mealsdb-csv-update-users" checked>
                                <?php echo esc_html__('Update WordPress users', 'meals-db'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox" id="mealsdb-csv-update-clients" checked>
                                <?php echo esc_html__('Create/update Meals DB clients', 'meals-db'); ?>
                            </label>
                        </div>

                        <div class="mealsdb-csv-actions">
                            <button type="button" class="button button-primary button-large" id="mealsdb-csv-import-btn">
                                <?php echo esc_html__('Start Import', 'meals-db'); ?>
                            </button>
                            <button type="button" class="button button-secondary" id="mealsdb-csv-cancel-btn">
                                <?php echo esc_html__('Cancel', 'meals-db'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Progress -->
                <div id="mealsdb-csv-progress-section" class="mealsdb-csv-section" style="display: none;">
                    <div class="mealsdb-csv-card">
                        <h2><?php echo esc_html__('Step 3: Importing...', 'meals-db'); ?></h2>

                        <div class="mealsdb-csv-progress-container">
                            <div class="mealsdb-csv-progress-bar-container">
                                <div class="mealsdb-csv-progress-bar" id="mealsdb-csv-progress-bar"></div>
                            </div>
                            <p class="mealsdb-csv-progress-text" id="mealsdb-csv-progress-text">0%</p>
                        </div>

                        <div id="mealsdb-csv-import-status" class="mealsdb-csv-import-status"></div>
                    </div>
                </div>

                <!-- Step 4: Results -->
                <div id="mealsdb-csv-results-section" class="mealsdb-csv-section" style="display: none;">
                    <div class="mealsdb-csv-card">
                        <h2><?php echo esc_html__('Import Complete!', 'meals-db'); ?></h2>

                        <div id="mealsdb-csv-results-summary" class="mealsdb-csv-results-summary"></div>

                        <div id="mealsdb-csv-results-errors" class="mealsdb-csv-results-errors" style="display: none;">
                            <h3><?php echo esc_html__('Errors:', 'meals-db'); ?></h3>
                            <div class="notice notice-error inline">
                                <ul id="mealsdb-csv-errors-list"></ul>
                            </div>
                        </div>

                        <div class="mealsdb-csv-results-actions">
                            <a href="#" class="button button-primary" id="mealsdb-csv-download-log-btn">
                                <?php echo esc_html__('Download Log File', 'meals-db'); ?>
                            </a>
                            <button type="button" class="button button-secondary" id="mealsdb-csv-import-another-btn">
                                <?php echo esc_html__('Import Another File', 'meals-db'); ?>
                            </button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mealsdb')); ?>" class="button">
                                <?php echo esc_html__('View Clients', 'meals-db'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
