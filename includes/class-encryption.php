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
     * Resolve the encryption key and record which source supplied it.
     *
     * Priority order (most secure first):
     *   1. MEALS_DB_KEY constant — defined in wp-config.php or a
     *      config-local PHP file outside the document root. Not
     *      readable from a SQL dump or a leaked wp_options backup.
     *   2. MEALS_DB_ENCRYPTION_KEY environment variable — for
     *      containerised / 12-factor deployments where wp-config is
     *      baked into the image and secrets come from the orchestrator.
     *   3. mealsdb_settings.encryption_key option — the original
     *      storage. Deprecated: any compromise of the MySQL data
     *      directory (backup tarball, replica dump, SQL-injection
     *      exfil) leaks every encrypted PII column. Callers that use
     *      this path get a one-time error_log warning and an admin
     *      notice so operators migrate to the constant.
     *
     * @return array{key: string, source: string}
     */
    private static function resolve_key_material(): array {
        $key_b64 = '';
        $source  = 'missing';

        if (defined('MEALS_DB_KEY') && is_string(MEALS_DB_KEY) && MEALS_DB_KEY !== '') {
            $key_b64 = MEALS_DB_KEY;
            $source  = 'constant';
        }

        if ($key_b64 === '') {
            $env = getenv('MEALS_DB_ENCRYPTION_KEY');
            if (is_string($env) && $env !== '') {
                $key_b64 = $env;
                $source  = 'env';
            }
        }

        if ($key_b64 === '' && function_exists('get_option')) {
            $opts = get_option('mealsdb_settings', []);
            if (is_array($opts) && !empty($opts['encryption_key'])) {
                $key_b64 = (string) $opts['encryption_key'];
                $source  = 'option';
                self::warn_once_on_db_key();
            }
        }

        return ['key' => $key_b64, 'source' => $source];
    }

    /**
     * Where the currently-active encryption key lives.
     *
     * Exposed so the admin-notices hook in meals-db-main.php (and any
     * future diagnostics tool) can surface a "migrate your key out of
     * the database" banner without triggering the actual decryption
     * work.
     *
     * @return string One of 'constant', 'env', 'option', 'missing'.
     */
    public static function key_source(): string {
        try {
            return self::resolve_key_material()['source'];
        } catch (\Throwable $e) {
            return 'missing';
        }
    }

    /**
     * Emit a single error_log warning per request when the key is
     * still being read from wp_options. Kept lightweight — the full
     * remediation UX lives in the admin notice.
     */
    private static function warn_once_on_db_key(): void {
        static $warned = false;
        if ($warned) {
            return;
        }
        $warned = true;
        error_log('[MealsDB Encryption] WARNING: encryption key sourced from wp_options (mealsdb_settings.encryption_key). Move it to the MEALS_DB_KEY constant in wp-config.php so a database dump does not leak the key.');
    }

    /**
     * Get the AES key from the highest-priority available source.
     *
     * @return string
     */
    private static function get_key(): string {
        $resolved = self::resolve_key_material();
        $key_b64  = $resolved['key'];

        if ($key_b64 === '') {
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
        // random_bytes() draws from /dev/urandom (or BCryptGenRandom on
        // Windows) and throws on entropy failure. openssl_random_
        // pseudo_bytes() is a legacy wrapper that can silently fall
        // back to a weaker source when OpenSSL's entropy pool is
        // unseeded — surfacing the exception here is preferable to
        // producing a cryptographically-dubious IV. Both calls return
        // exactly 16 raw bytes for our 128-bit AES-CBC IV.
        try {
            $iv = random_bytes(16);
        } catch (\Throwable $e) {
            throw new Exception('IV generation failed: ' . $e->getMessage());
        }

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
            // Legacy format without HMAC. Decryption proceeds without
            // integrity verification, which is the classic Vaudenay
            // padding-oracle setup against AES-CBC. Refuse this branch
            // entirely once the operator has confirmed via the encryption
            // migrator that no legacy payloads remain in the database.
            if (self::legacy_decrypt_disabled()) {
                throw new Exception('Legacy encrypted payload rejected: legacy decryption is disabled.');
            }

            self::log_legacy_decrypt_use();
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
     * Whether the legacy (pre-HMAC) decrypt branch is administratively
     * disabled. Set MEALSDB_DISABLE_LEGACY_DECRYPT in wp-config.php or
     * the mealsdb_legacy_decrypt_disabled option to refuse legacy
     * payloads — do this once the migrator's inventory() reports zero
     * legacy rows in every column.
     */
    public static function legacy_decrypt_disabled(): bool {
        if (defined('MEALSDB_DISABLE_LEGACY_DECRYPT') && MEALSDB_DISABLE_LEGACY_DECRYPT) {
            return true;
        }
        if (function_exists('get_option')) {
            return (bool) get_option('mealsdb_legacy_decrypt_disabled', false);
        }
        return false;
    }

    /**
     * Log (once per request) that a legacy payload was decrypted, so the
     * operator can monitor when the inventory really is clean.
     */
    private static function log_legacy_decrypt_use(): void {
        static $logged = false;
        if ($logged) {
            return;
        }
        $logged = true;
        error_log('[MealsDB Encryption] WARNING: legacy CBC payload decrypted without HMAC. Run the encryption migrator and set MEALSDB_DISABLE_LEGACY_DECRYPT.');
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
     * Encode an array as an encrypted, at-rest-safe payload (QW-2 discipline:
     * fail CLOSED — never persist PII as plaintext). Returns false on failure.
     *
     * Shared by client-form drafts (MealsDB_Client_Form::encode_draft_payload)
     * and invoice drafts (MealsDB_Invoice_Draft). This was promoted here from
     * the private client-form helper so the two callers share ONE implementation
     * rather than re-creating the dual-maintenance trap (mirrors the STR-LOG
     * facade approach). Losing an unsaved draft beats writing government IDs in
     * cleartext — so a failure to encrypt is a refusal to persist, not a fallback.
     *
     * @param array $data
     * @return string|false
     */
    public static function encode_payload(array $data) {
        $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
        if (!is_string($json)) {
            return false;
        }
        try {
            // self:: — we ARE the encryption class now.
            return self::encrypt($json);
        } catch (\Throwable $e) {
            error_log('[MealsDB] Payload not saved: encryption failed (' . $e->getMessage()
                . '); refusing plaintext fallback.');
            return false;
        }
    }

    /**
     * Decode a payload written by encode_payload(), tolerating legacy plaintext
     * JSON (client-form drafts written before encryption existed). Returns null
     * if neither path yields an array.
     *
     * The READ path deliberately still accepts legacy plaintext — only WRITING
     * cleartext is forbidden (QW-2). The cheap shape check (first non-whitespace
     * char is { or [) avoids invoking the encryption layer — and its
     * legacy-decrypt warning — on every read of a plaintext-era draft.
     *
     * @param string $stored
     * @return array|null
     */
    public static function decode_payload(string $stored): ?array {
        if ($stored === '') {
            return null;
        }

        $trimmed = ltrim($stored);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $decrypted = self::safe_decrypt($stored);
        if (is_string($decrypted) && $decrypted !== '' && $decrypted !== $stored) {
            $decoded = json_decode($decrypted, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
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
