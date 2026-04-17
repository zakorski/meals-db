<?php
/**
 * Handles AES-256-CBC encryption/decryption for Meals DB client data.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Encryption {

    /**
     * Canonical list of meals_clients DB columns that are stored encrypted.
     *
     * These are the actual database column names. The form-side name for
     * `customer_comments` is `client_comments`; map_form_to_db() rewrites it
     * before persistence, so encryption operates on the DB-side name here.
     */
    public const ENCRYPTED_CLIENT_COLUMNS = [
        'individual_id',
        'requisition_id',
        'vet_health_card',
        'diet_concerns',
        'customer_comments',
    ];

    /**
     * Get the AES key from environment variable or wp-config.php constants.
     *
     * @return string
     */
    private static function get_key(): string {
        // 1. WordPress options (Settings tab)
        $key_b64 = '';
        if (function_exists('get_option')) {
            $opts = get_option('mealsdb_settings', []);
            if (is_array($opts) && !empty($opts['encryption_key'])) {
                $key_b64 = $opts['encryption_key'];
            }
        }

        // 2. Environment variable
        if (!$key_b64) {
            $key_b64 = getenv('MEALS_DB_ENCRYPTION_KEY');
        }

        // 3. wp-config.php constant (backward compatibility)
        if (!$key_b64 && defined('MEALS_DB_KEY')) {
            $key_b64 = MEALS_DB_KEY;
        }

        if (!$key_b64) {
            throw new Exception('Missing Meals DB encryption key configuration.');
        }

        if (strpos($key_b64, 'base64:') !== 0) {
            throw new Exception('Invalid Meals DB encryption key format. Expected base64: prefix.');
        }

        $key = base64_decode(substr($key_b64, 7));

        // Verify key length (256 bits = 32 bytes)
        if (strlen($key) !== 32) {
            throw new Exception('Encryption key must be 256 bits (32 bytes).');
        }

        return $key;
    }

    /**
     * Encrypt a string using AES-256-CBC with HMAC authentication.
     *
     * @param string $plaintext
     * @return string Base64-encoded HMAC + IV + ciphertext
     */
    public static function encrypt(string $plaintext): string {
        $key = self::get_key();
        $iv = openssl_random_pseudo_bytes(16); // 128-bit IV

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            throw new Exception('Encryption failed.');
        }

        // Calculate HMAC for authentication (encrypt-then-MAC)
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

        // Format: HMAC (32 bytes) + IV (16 bytes) + Ciphertext
        return base64_encode($hmac . $iv . $ciphertext);
    }

    /**
     * Decrypt a base64-encoded HMAC + IV + ciphertext string.
     *
     * @param string $encoded
     * @return string
     */
    public static function decrypt(string $encoded): string {
        $key = self::get_key();
        $data = base64_decode($encoded);

        if ($data === false) {
            throw new Exception('Invalid base64 encoding.');
        }

        // Check if this is new format with HMAC (min 49 bytes: HMAC(32) + IV(16) + ciphertext(1+))
        if (strlen($data) >= 49) {
            // New format with HMAC
            $hmac = substr($data, 0, 32);
            $iv = substr($data, 32, 16);
            $ciphertext = substr($data, 48);

            // Verify HMAC before decryption (prevents padding oracle attacks)
            $expected_hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
            if (!hash_equals($expected_hmac, $hmac)) {
                throw new Exception('Data integrity check failed.');
            }
        } elseif (strlen($data) >= 17) {
            // Legacy format without HMAC (backward compatibility)
            // TODO: Remove this after migrating all encrypted data
            $iv = substr($data, 0, 16);
            $ciphertext = substr($data, 16);
        } else {
            throw new Exception('Invalid encrypted payload.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plaintext === false) {
            throw new Exception('Decryption failed.');
        }

        return $plaintext;
    }

    /**
     * Decrypt a value that may be in new, legacy, or plaintext form.
     *
     * Returns the original input unchanged when decryption fails (e.g. the
     * value was never encrypted, or is in a format we no longer support).
     * This keeps read paths resilient during the legacy-to-new migration
     * window and across the historical inconsistencies where some columns
     * were decrypted even though the migration stored them as plaintext.
     *
     * Use this on every read path. Only use decrypt() directly when a
     * decryption failure should be a hard error (e.g. the migrator itself).
     *
     * @param string $value
     * @return string
     */
    public static function safe_decrypt(string $value): string {
        if ($value === '') {
            return '';
        }
        try {
            return self::decrypt($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    /**
     * Classify the encrypted payload format of a stored value.
     *
     * @param string $value Raw column value.
     * @return string One of 'empty', 'new' (HMAC+IV+CT), 'legacy' (IV+CT only),
     *                or 'plaintext' (not a valid encrypted payload).
     */
    public static function classify_payload(string $value): string {
        if ($value === '') {
            return 'empty';
        }
        $raw = base64_decode($value, true);
        if ($raw === false) {
            return 'plaintext';
        }
        $len = strlen($raw);
        if ($len >= 49) {
            return 'new';
        }
        if ($len >= 17) {
            return 'legacy';
        }
        return 'plaintext';
    }

    /**
     * Encrypt the canonical PII columns in a row keyed by DB column name.
     *
     * Idempotent: values that are already in `new` (HMAC) format are left
     * alone, so this is safe to call on payloads that may have come back
     * round-trip from another encrypt-aware code path. Empty/null values
     * are also left alone (NULL is preserved so the column gets cleared).
     *
     * @param array<string, mixed> $row    Row keyed by DB column name.
     * @param string[]|null         $fields Optional override of which columns
     *                                      to encrypt. Defaults to the canonical
     *                                      ENCRYPTED_CLIENT_COLUMNS list.
     *
     * @return array<string, mixed> Row with the listed columns encrypted.
     */
    public static function encrypt_columns(array $row, ?array $fields = null): array {
        $columns = $fields ?? self::ENCRYPTED_CLIENT_COLUMNS;

        foreach ($columns as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];

            if ($value === null || $value === '') {
                continue;
            }

            if (!is_string($value)) {
                $value = (string) $value;
            }

            // Don't double-encrypt rows that already carry an HMAC payload
            // (e.g. in case a caller hands us a row read straight from the DB).
            if (self::classify_payload($value) === 'new') {
                continue;
            }

            try {
                $row[$column] = self::encrypt($value);
            } catch (\Throwable $e) {
                error_log('[MealsDB Encryption] Failed to encrypt column ' . $column . ': ' . $e->getMessage());
                // Bail rather than silently storing plaintext.
                throw $e;
            }
        }

        return $row;
    }

    /**
     * Create a searchable index (SHA-256 hash) for encrypted fields.
     * This allows searching encrypted data without decrypting it.
     *
     * @param string $plaintext The value to create an index for
     * @return string SHA-256 hash for searching
     */
    public static function create_index(string $plaintext): string {
        return hash('sha256', strtolower(trim($plaintext)));
    }

    /**
     * Get singleton instance for backward compatibility.
     *
     * @return MealsDB_Encryption
     */
    public static function get_instance(): MealsDB_Encryption {
        static $instance = null;
        if ($instance === null) {
            $instance = new self();
        }
        return $instance;
    }

    /**
     * Instance method wrapper for encrypt.
     *
     * @param string $plaintext
     * @return string
     */
    public function encrypt_value(string $plaintext): string {
        return self::encrypt($plaintext);
    }

    /**
     * Instance method wrapper for decrypt.
     *
     * @param string $encoded
     * @return string
     */
    public function decrypt_value(string $encoded): string {
        return self::decrypt($encoded);
    }

    /**
     * Instance method wrapper for create_index.
     *
     * @param string $plaintext
     * @return string
     */
    public function create_search_index(string $plaintext): string {
        return self::create_index($plaintext);
    }
}
