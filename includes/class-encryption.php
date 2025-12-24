<?php
/**
 * Handles AES-256-CBC encryption/decryption for Meals DB client data.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Encryption {

    /**
     * Get the AES key from environment variable or wp-config.php constants.
     *
     * @return string
     */
    private static function get_key(): string {
        // Prefer environment variable over wp-config.php constant
        $key_b64 = getenv('MEALS_DB_ENCRYPTION_KEY');

        // Fall back to wp-config.php constant for backward compatibility
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
