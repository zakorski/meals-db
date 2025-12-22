<?php
/**
 * Admin page for importing clients from CSV.
 *
 * @package MealsDB
 */

class MealsDB_Import_Page {

    /**
     * Initialize the import page
     */
    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    /**
     * Register the import submenu page
     */
    public static function register_menu(): void {
        if (!MealsDB_Permissions::can_access_plugin()) {
            return;
        }

        add_submenu_page(
            'mealsdb',
            __('Import Clients', 'meals-db'),
            __('Import Clients', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb-import',
            [self::class, 'render']
        );
    }

    /**
     * Enqueue CSS and JavaScript
     */
    public static function enqueue_assets($hook): void {
        // Only load on import page
        if ($hook !== 'meals-db_page_mealsdb-import') {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'mealsdb-import',
            MEALS_DB_PLUGIN_URL . 'assets/css/admin-import.css',
            [],
            MEALS_DB_VERSION
        );

        // Enqueue JavaScript
        wp_enqueue_script(
            'mealsdb-import',
            MEALS_DB_PLUGIN_URL . 'assets/js/admin-import.js',
            ['jquery'],
            MEALS_DB_VERSION,
            true
        );

        // Localize script
        wp_localize_script('mealsdb-import', 'mealsdbImport', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mealsdb_import_nonce'),
            'strings' => [
                'uploading' => __('Uploading...', 'meals-db'),
                'validating' => __('Validating CSV...', 'meals-db'),
                'importing' => __('Importing clients...', 'meals-db'),
                'complete' => __('Import complete!', 'meals-db'),
                'error' => __('Error', 'meals-db'),
                'confirmImport' => __('Are you sure you want to import these clients?', 'meals-db'),
            ],
        ]);
    }

    /**
     * Render the import page
     */
    public static function render(): void {
        MealsDB_Permissions::enforce();

        ?>
        <div class="wrap mealsdb-import-page">
            <h1><?php echo esc_html__('Import Clients', 'meals-db'); ?></h1>

            <div class="mealsdb-import-container">
                <!-- Step 1: Upload CSV -->
                <div id="mealsdb-upload-section" class="mealsdb-import-section">
                    <div class="mealsdb-card">
                        <h2><?php echo esc_html__('Step 1: Upload CSV File', 'meals-db'); ?></h2>

                        <div class="mealsdb-upload-info">
                            <p><?php echo esc_html__('Upload a CSV file containing client data to import.', 'meals-db'); ?></p>
                            <ul>
                                <li><?php echo esc_html__('File must be in CSV format', 'meals-db'); ?></li>
                                <li><?php echo esc_html__('Maximum file size: 5MB', 'meals-db'); ?></li>
                                <li><?php echo esc_html__('First row should be the header (will be skipped)', 'meals-db'); ?></li>
                                <li><?php echo esc_html__('Data rows start from row 2', 'meals-db'); ?></li>
                            </ul>
                        </div>

                        <div class="mealsdb-upload-area" id="mealsdb-upload-area">
                            <div class="mealsdb-upload-icon">📁</div>
                            <p class="mealsdb-upload-text">
                                <?php echo esc_html__('Drag & drop CSV file here or click to browse', 'meals-db'); ?>
                            </p>
                            <input type="file" id="mealsdb-csv-file" accept=".csv" style="display: none;">
                            <button type="button" class="button button-primary" id="mealsdb-browse-btn">
                                <?php echo esc_html__('Browse Files', 'meals-db'); ?>
                            </button>
                        </div>

                        <div id="mealsdb-upload-progress" class="mealsdb-upload-progress" style="display: none;">
                            <div class="mealsdb-spinner"></div>
                            <p><?php echo esc_html__('Uploading and validating...', 'meals-db'); ?></p>
                        </div>

                        <div id="mealsdb-upload-error" class="notice notice-error inline" style="display: none;">
                            <p></p>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Preview -->
                <div id="mealsdb-preview-section" class="mealsdb-import-section" style="display: none;">
                    <div class="mealsdb-card">
                        <h2><?php echo esc_html__('Step 2: Preview & Confirm', 'meals-db'); ?></h2>

                        <div class="notice notice-success inline">
                            <p><?php echo esc_html__('CSV validated successfully!', 'meals-db'); ?></p>
                        </div>

                        <div id="mealsdb-stats" class="mealsdb-stats"></div>

                        <div id="mealsdb-preview-table-container">
                            <h3><?php echo esc_html__('Preview of first 5 clients:', 'meals-db'); ?></h3>
                            <table class="wp-list-table widefat fixed striped" id="mealsdb-preview-table">
                                <thead>
                                    <tr>
                                        <th><?php echo esc_html__('Name', 'meals-db'); ?></th>
                                        <th><?php echo esc_html__('Type', 'meals-db'); ?></th>
                                        <th><?php echo esc_html__('Initials', 'meals-db'); ?></th>
                                        <th><?php echo esc_html__('Email', 'meals-db'); ?></th>
                                        <th><?php echo esc_html__('Phone', 'meals-db'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="mealsdb-preview-tbody"></tbody>
                            </table>
                        </div>

                        <div class="mealsdb-import-options">
                            <label>
                                <input type="checkbox" id="mealsdb-dry-run" checked>
                                <?php echo esc_html__('Dry run (preview only, don\'t import)', 'meals-db'); ?>
                            </label>
                        </div>

                        <div class="mealsdb-import-actions">
                            <button type="button" class="button button-primary button-large" id="mealsdb-import-btn">
                                <?php echo esc_html__('Proceed with Import', 'meals-db'); ?>
                            </button>
                            <button type="button" class="button button-secondary" id="mealsdb-cancel-btn">
                                <?php echo esc_html__('Cancel', 'meals-db'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Import Progress -->
                <div id="mealsdb-import-section" class="mealsdb-import-section" style="display: none;">
                    <div class="mealsdb-card">
                        <h2><?php echo esc_html__('Step 3: Importing...', 'meals-db'); ?></h2>

                        <div class="mealsdb-progress-container">
                            <div class="mealsdb-progress-bar-container">
                                <div class="mealsdb-progress-bar" id="mealsdb-progress-bar"></div>
                            </div>
                            <p class="mealsdb-progress-text" id="mealsdb-progress-text">0%</p>
                        </div>

                        <div id="mealsdb-import-status" class="mealsdb-import-status"></div>
                    </div>
                </div>

                <!-- Step 4: Results -->
                <div id="mealsdb-results-section" class="mealsdb-import-section" style="display: none;">
                    <div class="mealsdb-card">
                        <h2><?php echo esc_html__('Import Complete!', 'meals-db'); ?></h2>

                        <div id="mealsdb-results-summary" class="mealsdb-results-summary"></div>

                        <div id="mealsdb-results-errors" class="mealsdb-results-errors" style="display: none;">
                            <h3><?php echo esc_html__('Errors:', 'meals-db'); ?></h3>
                            <div class="notice notice-error inline">
                                <ul id="mealsdb-errors-list"></ul>
                            </div>
                        </div>

                        <div class="mealsdb-results-actions">
                            <button type="button" class="button button-primary" id="mealsdb-import-another-btn">
                                <?php echo esc_html__('Import Another File', 'meals-db'); ?>
                            </button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=mealsdb')); ?>" class="button button-secondary">
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
