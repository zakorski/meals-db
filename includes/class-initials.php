<?php
/**
 * Generates and validates initials codes for Meals DB clients.
 *
 * This class is now a wrapper around MealsDB_Initials_Validator for backward compatibility.
 * The new validator supports address-based duplicate checking.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Initials {

    /**
     * Words that should never be used as initials.
     *
     * @deprecated Use MealsDB_Initials_Validator::get_blocked_initials() instead.
     * @var string[]
     */
    private static $banned_words = [
        'ASS',
        'SEX',
        'TIT',
        'CUM',
        'FAG',
        'GAY',
        'GOD',
        'NIG',
        'WTF',
        'XXX',
        'KKK',
        'FUK',
    ];

    /**
     * Generate a random 3-letter uppercase code.
     *
     * Note: This method cannot perform address-based validation since no client data is provided.
     * For better generation that considers client address, use MealsDB_Initials_Validator::generate().
     */
    public static function generate(): string {
        // Delegate to new validator with empty address data
        $generated = MealsDB_Initials_Validator::generate('', '', array());

        if ($generated === false) {
            error_log('[MealsDB] Unable to generate initials.');
            return '';
        }

        return $generated;
    }

    /**
     * Validate a code against formatting, banned list, and existing records.
     *
     * Note: This method cannot perform full address-based validation since no client data is provided.
     * It will reject duplicates even if they're at the same address.
     * For full validation, use MealsDB_Initials_Validator::validate() with client address data.
     *
     * @param string $code The initials code to validate.
     * @param int|null $exclude_client_id Client ID to exclude from duplicate check (for editing).
     * @param array $client_data Optional client data including address fields for full validation.
     * @return array Validation result with 'valid' and 'message' keys.
     */
    public static function validate_code(string $code, ?int $exclude_client_id = null, array $client_data = array()): array {
        $code = strtoupper(trim($code));

        // If client_data is provided, use the new validator
        if (!empty($client_data)) {
            $result = MealsDB_Initials_Validator::validate($code, $client_data, $exclude_client_id);

            // Convert to old format
            if ($result['valid']) {
                if (!empty($result['shared'])) {
                    $sharing_names = array_map(function($client) {
                        return trim($client['first_name'] . ' ' . $client['last_name']);
                    }, $result['sharing_with']);

                    return [
                        'valid'   => true,
                        'message' => sprintf(
                            __('Initials are valid (shared with %s at same address).', 'meals-db'),
                            implode(', ', $sharing_names)
                        ),
                        'shared'  => true,
                    ];
                }

                return [
                    'valid'   => true,
                    'message' => __('Initials are available.', 'meals-db'),
                ];
            } else {
                return [
                    'valid'   => false,
                    'message' => $result['error'],
                ];
            }
        }

        // Legacy validation without address data
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            return [
                'valid'   => false,
                'message' => __('Initials must be exactly three uppercase letters.', 'meals-db'),
            ];
        }

        if (self::is_banned_word($code)) {
            return [
                'valid'   => false,
                'message' => __('These initials are not allowed.', 'meals-db'),
            ];
        }

        if (self::exists_in_db($code, $exclude_client_id)) {
            return [
                'valid'   => false,
                'message' => __('These initials are already in use.', 'meals-db'),
            ];
        }

        return [
            'valid'   => true,
            'message' => __('Initials are available.', 'meals-db'),
        ];
    }

    /**
     * Determine if a code already exists in the external database.
     *
     * Note: This method only checks for existence, not address-based sharing.
     * It will return true even if the duplicate is at the same address.
     */
    public static function exists_in_db(string $code, ?int $exclude_client_id = null): bool {
        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $sql = "SELECT client_id FROM `{$clients_table}` WHERE delivery_initials = %s";

        if ($exclude_client_id !== null) {
            $sql .= ' AND client_id != %d';
            $row = $wpdb->get_row($wpdb->prepare($sql, $code, $exclude_client_id), ARRAY_A);
        } else {
            $row = $wpdb->get_row($wpdb->prepare($sql, $code), ARRAY_A);
        }

        if ($wpdb->last_error) {
            error_log('[MealsDB] Failed to execute initials lookup query: ' . $wpdb->last_error);
            return false;
        }

        return $row !== null;
    }

    /**
     * Check whether a code is in the banned words list.
     */
    private static function is_banned_word(string $code): bool {
        return in_array($code, self::$banned_words, true);
    }
}
