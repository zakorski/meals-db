<?php
/**
 * Generates and validates initials codes for Meals DB clients.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Initials {

    /**
     * Words that should never be used as initials.
     *
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
        'JES',
        'NIG',
        'WTF',
        'XXX',
        'KKK',
    ];

    /**
     * Generate a random 3-letter uppercase code.
     */
    public static function generate(): string {
        do {
            $code = '';

            for ($i = 0; $i < 3; $i++) {
                try {
                    $code .= chr(random_int(65, 90));
                } catch (Exception $e) {
                    error_log('[MealsDB] Unable to generate initials: ' . $e->getMessage());
                    return '';
                }
            }

            if (self::is_banned_word($code)) {
                continue;
            }

            if (self::exists_in_db($code)) {
                continue;
            }

            return $code;
        } while (true);
    }

    /**
     * Validate a code against formatting, banned list, and existing records.
     */
    public static function validate_code(string $code, ?int $exclude_client_id = null): array {
        $code = strtoupper(trim($code));

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
     */
    public static function exists_in_db(string $code, ?int $exclude_client_id = null): bool {
        $connection = MealsDB_DB::get_connection();

        if (!($connection instanceof mysqli)) {
            error_log('[MealsDB] Unable to obtain database connection for initials lookup.');
            return false;
        }

        $sql = 'SELECT client_id FROM meals_clients WHERE initials_delivery = ?';

        if ($exclude_client_id !== null) {
            $sql .= ' AND client_id != ?';
        }

        $statement = $connection->prepare($sql);

        if (!($statement instanceof mysqli_stmt)) {
            error_log('[MealsDB] Failed to prepare initials lookup query.');
            return false;
        }

        $bind_result = ($exclude_client_id !== null)
            ? $statement->bind_param('si', $code, $exclude_client_id)
            : $statement->bind_param('s', $code);

        if (!$bind_result) {
            error_log('[MealsDB] Failed to bind parameters for initials lookup.');
            $statement->close();
            return false;
        }

        if (!$statement->execute()) {
            error_log('[MealsDB] Failed to execute initials lookup query: ' . $statement->error);
            $statement->close();
            return false;
        }

        $statement->store_result();
        $exists = $statement->num_rows > 0;
        $statement->free_result();
        $statement->close();

        return $exists;
    }

    /**
     * Check whether a code is in the banned words list.
     */
    private static function is_banned_word(string $code): bool {
        return in_array($code, self::$banned_words, true);
    }
}
