<?php
/**
 * AJAX handler for the Historical Order Import utility.
 *
 * @package MealsDB
 */

class MealsDB_Ajax_Historical_Import {

    /**
     * Register AJAX actions.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_historical_import_start',  [self::class, 'start']);
        add_action('wp_ajax_mealsdb_historical_import_batch',  [self::class, 'batch']);
        add_action('wp_ajax_mealsdb_historical_import_reset',  [self::class, 'reset']);
        add_action('wp_ajax_mealsdb_historical_import_status', [self::class, 'status']);
    }

    /**
     * Start the import: count orders and save initial progress.
     */
    public static function start(): void {
        self::verify_request();

        $dry_run = !empty($_POST['dry_run']);

        // Check if an import is already in progress.
        $progress = MealsDB_Historical_Import::get_progress();
        if ($progress['offset'] > 0 && !$progress['complete']) {
            wp_send_json([
                'success' => false,
                'message' => __('An import is already in progress. Reset first to start over.', 'meals-db'),
            ]);
        }

        $clients  = MealsDB_Historical_Import::get_government_clients();
        $user_ids = array_keys($clients);
        $total    = MealsDB_Historical_Import::get_total_order_count($user_ids);

        MealsDB_Historical_Import::save_progress(0, $total, false);

        wp_send_json([
            'success' => true,
            'total'   => $total,
            'dry_run' => $dry_run,
        ]);
    }

    /**
     * Process a single batch and return stats.
     */
    public static function batch(): void {
        self::verify_request();

        $dry_run  = !empty($_POST['dry_run']);
        $progress = MealsDB_Historical_Import::get_progress();

        if ($progress['complete']) {
            wp_send_json([
                'success'  => true,
                'complete' => true,
                'percent'  => 100,
            ]);
        }

        $clients = MealsDB_Historical_Import::get_government_clients();
        $stats   = MealsDB_Historical_Import::process_batch($progress['offset'], $dry_run, $clients);

        $new_offset = $progress['offset'] + MealsDB_Historical_Import::BATCH_SIZE;
        $total      = $progress['total'];
        $complete   = $new_offset >= $total;

        MealsDB_Historical_Import::save_progress($new_offset, $total, $complete);

        $percent = $total > 0 ? min(100, round($new_offset / $total * 100)) : 100;

        wp_send_json(array_merge($stats, [
            'success'  => true,
            'complete' => $complete,
            'percent'  => $percent,
            'offset'   => $new_offset,
            'total'    => $total,
            'dry_run'  => $dry_run,
        ]));
    }

    /**
     * Reset import progress.
     */
    public static function reset(): void {
        self::verify_request();

        MealsDB_Historical_Import::reset_progress();

        wp_send_json(['success' => true]);
    }

    /**
     * Return current progress.
     */
    public static function status(): void {
        self::verify_request();

        wp_send_json(array_merge(
            ['success' => true],
            MealsDB_Historical_Import::get_progress()
        ));
    }

    /**
     * Common request verification: nonce + capability.
     */
    private static function verify_request(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json([
                'success' => false,
                'message' => __('You are not allowed to perform this action.', 'meals-db'),
            ], 403);
        }
    }
}
