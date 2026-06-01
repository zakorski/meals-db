<?php
/**
 * Generates and validates initials codes for Meals DB clients.
 *
 * This class is now a wrapper around MealsDB_Initials_Validator for backward compatibility.
 * Delivery initials are globally unique (no same-address sharing exception).
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Initials {

    /**
     * Accessor for the banned-initials list. Delegates to the canonical
     * list on MealsDB_Initials_Validator so the two classes can't drift
     * apart — the hand-copied $banned_words array here was a known
     * source of "added to one, forgot the other" bugs.
     *
     * @return string[]
     */
    private static function banned_words(): array {
        if (class_exists('MealsDB_Initials_Validator')
            && method_exists('MealsDB_Initials_Validator', 'get_blocked_initials')) {
            $list = MealsDB_Initials_Validator::get_blocked_initials();
            if (is_array($list)) {
                return array_values(array_filter(array_map('strtoupper', $list)));
            }
        }
        return [];
    }

    /**
     * Generate a random 3-letter uppercase code.
     *
     * Delegates to the validator's generator, which guarantees global uniqueness.
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
     * Delivery initials are globally unique; any duplicate is rejected.
     *
     * @param string $code The initials code to validate.
     * @param int|null $exclude_client_id Client ID to exclude from duplicate check (for editing).
     * @param array $client_data Unused (kept for signature compatibility).
     * @return array Validation result with 'valid' and 'message' keys.
     */
    public static function validate_code(string $code, ?int $exclude_client_id = null, array $client_data = array()): array {
        $code = strtoupper(trim($code));

        // If client_data is provided, defer to the validator. (The validator
        // ignores the address fields now — initials are globally unique — but
        // it remains the single source of truth for the uniqueness rule.)
        if (!empty($client_data)) {
            $result = MealsDB_Initials_Validator::validate($code, $client_data, $exclude_client_id);

            if (!empty($result['valid'])) {
                return [
                    'valid'   => true,
                    'message' => __('Initials are available.', 'meals-db'),
                ];
            }

            return [
                'valid'   => false,
                'message' => $result['error'] ?? __('These initials are already in use.', 'meals-db'),
            ];
        }

        // Validation without client data: same checks, run directly here.
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
        return in_array($code, self::banned_words(), true);
    }
}
