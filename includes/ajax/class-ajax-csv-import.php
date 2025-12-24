<?php
/**
 * AJAX handlers for CSV user-client import.
 *
 * @package MealsDB
 */

class MealsDB_Ajax_CSV_Import {

    /**
     * Register AJAX actions
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_csv_validate', [self::class, 'validate_csv']);
        add_action('wp_ajax_mealsdb_csv_import', [self::class, 'import_csv']);
        add_action('wp_ajax_mealsdb_csv_progress', [self::class, 'get_progress']);
        add_action('wp_ajax_mealsdb_csv_download_log', [self::class, 'download_log']);
    }

    /**
     * Validate CSV file
     */
    public static function validate_csv(): void {
        check_ajax_referer('mealsdb_csv_import_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')]);
        }

        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('File upload failed', 'meals-db')]);
        }

        $file = $_FILES['csv_file'];

        // Validate file type
        $allowed_types = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file['type'], $allowed_types) && $file_extension !== 'csv') {
            wp_send_json_error(['message' => __('Invalid file type. Please upload a CSV file.', 'meals-db')]);
        }

        // Validate file size (max 10MB)
        if ($file['size'] > 10 * 1024 * 1024) {
            wp_send_json_error(['message' => __('File too large. Maximum size is 10MB.', 'meals-db')]);
        }

        // Move to secure location
        $upload_dir = wp_upload_dir();
        $import_dir = $upload_dir['basedir'] . '/mealsdb-csv-imports/';

        if (!file_exists($import_dir)) {
            wp_mkdir_p($import_dir);
            file_put_contents($import_dir . '.htaccess', "deny from all\n");
        }

        $filename = uniqid('csv_import_') . '.csv';
        $file_path = $import_dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            wp_send_json_error(['message' => __('Failed to save uploaded file.', 'meals-db')]);
        }

        // Validate CSV
        $importer = new MealsDB_CSV_User_Client_Importer(true);
        $validation = $importer->validate_csv($file_path);

        if (!$validation['valid']) {
            @unlink($file_path);
            wp_send_json_error(['message' => $validation['message']]);
        }

        // Store file path
        set_transient('mealsdb_csv_import_file_' . basename($filename, '.csv'), $file_path, HOUR_IN_SECONDS);

        wp_send_json_success([
            'file_id' => basename($filename, '.csv'),
            'total_rows' => $validation['total_rows'],
            'preview' => $validation['preview'],
        ]);
    }

    /**
     * Import CSV file
     */
    public static function import_csv(): void {
        check_ajax_referer('mealsdb_csv_import_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')]);
        }

        $file_id = sanitize_text_field($_POST['file_id'] ?? '');
        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] === 'true';
        $update_users = !isset($_POST['update_users']) || $_POST['update_users'] === 'true';
        $update_clients = !isset($_POST['update_clients']) || $_POST['update_clients'] === 'true';

        if (empty($file_id)) {
            wp_send_json_error(['message' => __('No file specified', 'meals-db')]);
        }

        $file_path = get_transient('mealsdb_csv_import_file_' . $file_id);
        if ($file_path === false || !file_exists($file_path)) {
            wp_send_json_error(['message' => __('File not found or expired', 'meals-db')]);
        }

        // Increase time limit
        if (function_exists('set_time_limit')) {
            @set_time_limit(600); // 10 minutes
        }

        // Run import
        $importer = new MealsDB_CSV_User_Client_Importer($dry_run, $update_users, $update_clients);
        $import_id = $importer->get_import_id();

        set_transient('mealsdb_csv_file_to_import_' . $file_id, $import_id, HOUR_IN_SECONDS);

        $result = $importer->import_from_csv($file_path);

        if (!$result['success']) {
            $error_data = ['message' => $result['message']];
            if (isset($result['import_id'])) {
                $error_data['import_id'] = $result['import_id'];
            }
            wp_send_json_error($error_data);
        }

        // Delete file after successful import (unless dry run)
        if (!$dry_run) {
            @unlink($file_path);
            delete_transient('mealsdb_csv_import_file_' . $file_id);
        }

        wp_send_json_success([
            'import_id' => $result['import_id'],
            'stats' => $result['stats'],
            'errors' => $result['errors'],
            'dry_run' => $result['dry_run'],
        ]);
    }

    /**
     * Get import progress
     */
    public static function get_progress(): void {
        check_ajax_referer('mealsdb_csv_import_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')]);
        }

        $import_id = sanitize_text_field($_POST['import_id'] ?? '');

        if (empty($import_id)) {
            wp_send_json_error(['message' => __('No import ID specified', 'meals-db')]);
        }

        $progress = MealsDB_CSV_User_Client_Importer::get_progress($import_id);

        if ($progress === null) {
            wp_send_json_error(['message' => __('Import not found', 'meals-db')]);
        }

        wp_send_json_success($progress);
    }

    /**
     * Download log file
     */
    public static function download_log(): void {
        check_ajax_referer('mealsdb_csv_import_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_die(__('Unauthorized', 'meals-db'), 403);
        }

        $import_id = sanitize_text_field($_GET['import_id'] ?? '');

        if (empty($import_id)) {
            wp_die(__('No import ID specified', 'meals-db'), 400);
        }

        $log_file = MealsDB_CSV_User_Client_Importer::get_log_file($import_id);

        if (!$log_file || !file_exists($log_file)) {
            wp_die(__('Log file not found', 'meals-db'), 404);
        }

        // Set headers
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="csv-import-log-' . date('Y-m-d-H-i-s') . '.txt"');
        header('Content-Length: ' . filesize($log_file));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($log_file);
        exit;
    }
}
