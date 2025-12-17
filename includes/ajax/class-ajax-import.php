<?php
/**
 * AJAX handlers for client import functionality.
 *
 * @package MealsDB
 */

/**
 * Handles AJAX requests for client CSV imports.
 */
class MealsDB_Ajax_Import {

    /**
     * Register the AJAX actions for import functionality.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_validate_csv', [self::class, 'validate_csv']);
        add_action('wp_ajax_mealsdb_import_clients', [self::class, 'import_clients']);
        add_action('wp_ajax_mealsdb_import_progress', [self::class, 'get_progress']);
    }

    /**
     * Validate uploaded CSV file
     */
    public static function validate_csv(): void {
        check_ajax_referer('mealsdb_import_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')]);
        }

        // Check if file was uploaded
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => __('File upload failed', 'meals-db')]);
        }

        $file = $_FILES['csv_file'];

        // Validate file type
        $allowed_types = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
        $file_type = $file['type'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($file_type, $allowed_types) && $file_extension !== 'csv') {
            wp_send_json_error(['message' => __('Invalid file type. Please upload a CSV file.', 'meals-db')]);
        }

        // Validate file size (max 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            wp_send_json_error(['message' => __('File too large. Maximum size is 5MB.', 'meals-db')]);
        }

        // Move file to secure location
        $upload_dir = wp_upload_dir();
        $import_dir = $upload_dir['basedir'] . '/mealsdb-imports/';

        if (!file_exists($import_dir)) {
            wp_mkdir_p($import_dir);
        }

        // Generate unique filename
        $filename = uniqid('import_') . '.csv';
        $file_path = $import_dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            wp_send_json_error(['message' => __('Failed to save uploaded file.', 'meals-db')]);
        }

        // Validate CSV
        $importer = new MealsDB_Client_Importer(true);
        $validation = $importer->validate_csv($file_path);

        if (!$validation['valid']) {
            // Delete the file if validation failed
            @unlink($file_path);
            wp_send_json_error(['message' => $validation['message']]);
        }

        // Store file path in session for later use
        set_transient('mealsdb_import_file_' . basename($filename, '.csv'), $file_path, HOUR_IN_SECONDS);

        wp_send_json_success([
            'file_id' => basename($filename, '.csv'),
            'total_rows' => $validation['total_rows'],
            'preview' => $validation['preview'],
            'stats' => $validation['stats'],
        ]);
    }

    /**
     * Import clients from uploaded CSV
     */
    public static function import_clients(): void {
        check_ajax_referer('mealsdb_import_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')]);
        }

        $file_id = sanitize_text_field($_POST['file_id'] ?? '');
        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] === 'true';

        if (empty($file_id)) {
            wp_send_json_error(['message' => __('No file specified', 'meals-db')]);
        }

        // Get file path from transient
        $file_path = get_transient('mealsdb_import_file_' . $file_id);
        if ($file_path === false || !file_exists($file_path)) {
            wp_send_json_error(['message' => __('File not found or expired', 'meals-db')]);
        }

        // Increase time limit for large imports
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // 5 minutes
        }

        // Run import
        $importer = new MealsDB_Client_Importer($dry_run);
        $result = $importer->import_from_csv($file_path, $dry_run);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']]);
        }

        // Delete the uploaded file after import (unless it's a dry run)
        if (!$dry_run) {
            @unlink($file_path);
            delete_transient('mealsdb_import_file_' . $file_id);
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
        check_ajax_referer('mealsdb_import_nonce', 'nonce');

        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')]);
        }

        $import_id = sanitize_text_field($_POST['import_id'] ?? '');

        if (empty($import_id)) {
            wp_send_json_error(['message' => __('No import ID specified', 'meals-db')]);
        }

        $progress = MealsDB_Client_Importer::get_progress($import_id);

        if ($progress === null) {
            wp_send_json_error(['message' => __('Import not found', 'meals-db')]);
        }

        wp_send_json_success($progress);
    }
}
