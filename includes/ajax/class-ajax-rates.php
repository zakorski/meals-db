<?php
/**
 * AJAX handler for Meals DB client rate retrieval.
 *
 * @package MealsDB
 */

class MealsDB_Ajax_Rates {

    /**
     * Register the AJAX action for fetching client rates.
     */
    public static function init(): void {
        add_action('wp_ajax_mealsdb_get_client_rates', [self::class, 'get_client_rates']);
    }

    /**
     * AJAX endpoint to fetch all rates for a given client.
     *
     * Returns the rates from meals_client_rates along with the client's default_rate_id.
     */
    public static function get_client_rates(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');

        $capability = MealsDB_Permissions::required_capability();
        if (!is_string($capability) || $capability === '') {
            $capability = 'manage_woocommerce';
        }
        if (!current_user_can($capability)) {
            wp_send_json([
                'success' => false,
                'message' => __('You are not allowed to perform this action.', 'meals-db'),
            ], 403);
        }

        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        $user_id = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : 0;
        if ($user_id <= 0) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid user ID.', 'meals-db'),
            ]);
        }

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            wp_send_json([
                'success' => true,
                'rates'           => [],
                'default_rate_id' => null,
            ]);
        }

        // Look up the active client_id and default_rate_id for this WP user.
        $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $sql = sprintf(
            'SELECT client_id, default_rate_id FROM `%s` WHERE wp_user_id = ? AND active = 1 LIMIT 1',
            $clients_table
        );

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            wp_send_json([
                'success' => true,
                'rates'           => [],
                'default_rate_id' => null,
            ]);
        }

        $stmt->bind_param('i', $user_id);
        if (!$stmt->execute()) {
            $stmt->close();
            wp_send_json([
                'success' => true,
                'rates'           => [],
                'default_rate_id' => null,
            ]);
        }

        $result = $stmt->get_result();
        $client_row = null;
        if (MealsDB_DB::is_mysqli_result($result)) {
            $client_row = $result->fetch_assoc();
        }
        $stmt->close();

        if (!is_array($client_row) || empty($client_row['client_id'])) {
            // Private client or no record — no rates.
            wp_send_json([
                'success'         => true,
                'rates'           => [],
                'default_rate_id' => null,
            ]);
        }

        $client_id      = (int) $client_row['client_id'];
        $default_rate_id = !empty($client_row['default_rate_id']) ? (int) $client_row['default_rate_id'] : null;

        // Fetch all rates for this client.
        $rates_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES));
        $rates_sql = sprintf(
            'SELECT rate_id, label, rate, is_default FROM `%s` WHERE client_id = ? ORDER BY is_default DESC, label ASC',
            $rates_table
        );

        $rates_stmt = $conn->prepare($rates_sql);
        if (!MealsDB_DB::is_mysqli_stmt($rates_stmt)) {
            wp_send_json([
                'success'         => true,
                'rates'           => [],
                'default_rate_id' => $default_rate_id,
            ]);
        }

        $rates_stmt->bind_param('i', $client_id);
        if (!$rates_stmt->execute()) {
            $rates_stmt->close();
            wp_send_json([
                'success'         => true,
                'rates'           => [],
                'default_rate_id' => $default_rate_id,
            ]);
        }

        $rates_result = $rates_stmt->get_result();
        $rates = [];

        if (MealsDB_DB::is_mysqli_result($rates_result)) {
            while ($row = $rates_result->fetch_assoc()) {
                $rates[] = [
                    'rate_id'    => (int) $row['rate_id'],
                    'label'      => (string) $row['label'],
                    'rate'       => number_format((float) $row['rate'], 2, '.', ''),
                    'is_default' => (int) $row['is_default'],
                ];
            }
        }

        $rates_stmt->close();

        // If no explicit default_rate_id on the client, fall back to the first is_default=1 rate.
        if ($default_rate_id === null && !empty($rates)) {
            foreach ($rates as $r) {
                if ($r['is_default'] === 1) {
                    $default_rate_id = $r['rate_id'];
                    break;
                }
            }
        }

        wp_send_json([
            'success'         => true,
            'rates'           => $rates,
            'default_rate_id' => $default_rate_id,
        ]);
    }
}
