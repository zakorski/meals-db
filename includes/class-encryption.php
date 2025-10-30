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
     * Get the AES key from the .env file.
     * 
     * @return string
     */
    private static function get_key(): string {
        $env_key = getenv('PLUGIN_AES_KEY');

        if (!$env_key || strpos($env_key, 'base64:') !== 0) {
            throw new Exception('Invalid or missing AES key in .env');
        }

        return base64_decode(substr($env_key, 7));
    }

    /**
     * Encrypt a string using AES-256-CBC.
     *
     * @param string $plaintext
     * @return string Base64-encoded IV + ciphertext
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

        // Combine IV and ciphertext and base64-encode it
        return base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt a base64-encoded IV + ciphertext string.
     *
     * @param string $encoded
     * @return string
     */
    public static function decrypt(string $encoded): string {
        $key = self::get_key();
        $data = base64_decode($encoded);

        if (strlen($data) < 17) {
            throw new Exception('Invalid encrypted payload.');
        }

        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);

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
}
