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
     * Derive a labelled 256-bit subkey from the master key (STR-10b).
     *
     * Encrypt-then-MAC with a SINGLE shared key isn't catastrophic, but best
     * practice is distinct keys per cryptographic construction so a weakness in
     * one (the CBC cipher) can't interact with another (the SHA-256 MAC) or with
     * the deterministic search index. We derive three independent subkeys from
     * the configured master via labelled HMAC (an HKDF-expand step with a fixed,
     * distinct info label per use):
     *
     *   - 'cipher' → AES-256-CBC key            (encrypt/decrypt)
     *   - 'mac'    → HMAC-SHA256 authentication  (encrypt-then-MAC tag)
     *   - 'index'  → keyed search-index HMAC     (create_index_v2)
     *
     * The labels are versioned ('…/v1') so a future rotation that changes the
     * derivation can coexist with v1 values during a migration window. The
     * master key SOURCING (constant > env > option) is unchanged — this only
     * governs how subkeys are expanded from whatever master is configured.
     *
     * @param string $label One of 'cipher', 'mac', 'index'.
     * @return string 32 raw bytes.
     */
    private static function derive_subkey(string $label): string {
        return hash_hmac('sha256', 'meals-db/' . $label . '/v1', self::get_key(), true);
    }

    /** AES-256-CBC subkey (distinct from the MAC subkey — STR-10b). */
    private static function cipher_key(): string {
        return self::derive_subkey('cipher');
    }

    /** HMAC-SHA256 authentication subkey (distinct from the cipher subkey). */
    private static function mac_key(): string {
        return self::derive_subkey('mac');
    }

    /** Keyed deterministic search-index subkey (STR-10a). */
    private static function index_key(): string {
        return self::derive_subkey('index');
    }

    /**
     * Encrypt a string using AES-256-CBC with HMAC authentication.
     *
     * @param string $plaintext
     * @return string Base64-encoded HMAC + IV + ciphertext
     */
    public static function encrypt(string $plaintext): string {
        // STR-10b: cipher and MAC use distinct derived subkeys, not one shared
        // master key. Reads tolerate the old shared-key format (see decrypt());
        // the migrator rewrites legacy values to this split-key format.
        $cipher_key = self::cipher_key();
        $mac_key    = self::mac_key();
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
            $cipher_key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            throw new Exception('Encryption failed.');
        }

        // Calculate HMAC for authentication (encrypt-then-MAC) under the
        // dedicated MAC subkey.
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $mac_key, true);

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
        $master     = self::get_key();
        $cipher_key = self::cipher_key();
        $mac_key    = self::mac_key();
        $data = base64_decode($encoded);

        if ($data === false) {
            throw new Exception('Invalid base64 encoding.');
        }

        $len = strlen($data);

        // Authenticated (new) format: HMAC(32) + IV(16) + ciphertext. We try
        // this whenever the length COULD fit it (>= 49). But length alone is
        // ambiguous: a pre-HMAC legacy value (IV + ciphertext, no HMAC) whose
        // plaintext spans several AES blocks is ALSO >= 49 bytes — e.g. a long
        // diet_concerns / customer_comments. So a failed HMAC here does NOT
        // prove corruption; the bytes may be a long legacy value wearing the
        // same length. On HMAC miss we therefore FALL THROUGH to the legacy
        // interpretation over the same bytes instead of throwing. (Previously
        // this threw 'Data integrity check failed.' and made run_full_harden()
        // unable to decrypt/migrate those rows, blocking the v2 flip.)
        if ($len >= 49) {
            $hmac = substr($data, 0, 32);
            $iv = substr($data, 32, 16);
            $ciphertext = substr($data, 48);

            // STR-10b transition: prefer the split-key MAC (current format). A
            // value still authenticated under the OLD shared master key verifies
            // only in the fallback branch — decrypt it with the master key and
            // let the migrator rewrite it to split-key format. Constant-time
            // comparisons; no oracle is leaked because we never decrypt on a MAC
            // miss (we only reinterpret as the unauthenticated legacy layout,
            // which carries its own documented padding-oracle caveat below).
            $split_hmac = hash_hmac('sha256', $iv . $ciphertext, $mac_key, true);
            if (hash_equals($split_hmac, $hmac)) {
                return self::cbc_decrypt($ciphertext, $cipher_key, $iv);
            }

            $legacy_hmac = hash_hmac('sha256', $iv . $ciphertext, $master, true);
            if (hash_equals($legacy_hmac, $hmac)) {
                self::log_shared_key_decrypt_use();
                return self::cbc_decrypt($ciphertext, $master, $iv);
            }

            // Neither authenticated interpretation verified. If legacy reads are
            // administratively disabled we cannot reinterpret these bytes, so
            // this really is corrupt — preserve the original error.
            if (self::legacy_decrypt_disabled()) {
                throw new Exception('Data integrity check failed.');
            }
            // else: fall through to the legacy IV+ciphertext attempt below.
        }

        // Legacy format without HMAC: IV(16) + ciphertext(rest), encrypted under
        // the master key directly (pre-dates subkey derivation). Decryption here
        // proceeds without integrity verification — the classic Vaudenay
        // padding-oracle setup against AES-CBC — so refuse this branch once the
        // operator has confirmed via run_full_harden() that no legacy payloads
        // remain. Reached for SHORT legacy values (17..48 bytes) AND for LONG
        // legacy values that failed the authenticated checks above.
        if ($len >= 17) {
            if (self::legacy_decrypt_disabled()) {
                throw new Exception('Legacy encrypted payload rejected: legacy decryption is disabled.');
            }

            $iv = substr($data, 0, 16);
            $ciphertext = substr($data, 16);
            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-256-cbc',
                $master,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($plaintext === false) {
                // Every interpretation is now exhausted (the >= 49 authenticated
                // HMAC checks above, and this legacy layout) — the value is
                // genuinely unreadable.
                throw new Exception('Data integrity check failed.');
            }

            self::log_legacy_decrypt_use();
            return $plaintext;
        }

        throw new Exception('Invalid encrypted payload.');
    }

    /**
     * AES-256-CBC raw decrypt, throwing on failure. Shared by the authenticated
     * decrypt branches (split-key and shared-key) so the openssl call and its
     * error handling live in one place.
     *
     * @param string $ciphertext Raw ciphertext bytes.
     * @param string $key        Raw 32-byte key.
     * @param string $iv         Raw 16-byte IV.
     * @return string
     */
    private static function cbc_decrypt(string $ciphertext, string $key, string $iv): string {
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
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
     * Whether a stored value is already in the STR-10b split-key format.
     *
     * Key-aware (unlike classify_payload, which is purely structural): a value
     * is "split-key" only if it is new-format AND its HMAC verifies under the
     * dedicated MAC subkey. A value still authenticated under the old shared
     * master key returns false here, which is exactly how the migrator's harden
     * pass identifies what still needs converting — and how it stays idempotent
     * (a second pass sees split-key values and skips them).
     *
     * @param string $value Raw column value.
     * @return bool
     */
    public static function is_split_key_payload(string $value): bool {
        if ($value === '') {
            return false;
        }
        $data = base64_decode($value, true);
        if ($data === false || strlen($data) < 49) {
            return false;
        }
        $hmac = substr($data, 0, 32);
        $iv = substr($data, 32, 16);
        $ciphertext = substr($data, 48);
        try {
            $expected = hash_hmac('sha256', $iv . $ciphertext, self::mac_key(), true);
        } catch (\Throwable $e) {
            return false;
        }
        return hash_equals($expected, $hmac);
    }

    /**
     * Whether a stored value is authenticated under EITHER the split MAC subkey
     * (STR-10b current format) or the legacy shared master key.
     *
     * Key-aware companion to is_split_key_payload(). Used to disambiguate the
     * length-overlap that classify_payload() (purely structural) cannot resolve:
     * a long pre-HMAC legacy value (IV + multi-block ciphertext) is >= 49 bytes
     * and so looks structurally 'new', but no HMAC over it will verify. Anything
     * that does NOT authenticate here, despite being long enough, is really a
     * legacy or corrupt value — which is exactly what inventory() must count as
     * legacy so the operator is not told it's safe to disable the legacy read
     * path while such rows still exist (that would lock them out).
     *
     * Fail-safe: if the key is unavailable (so no MAC can be computed) this
     * returns false, biasing toward "treat as legacy" rather than risking a
     * premature legacy-disable.
     *
     * @param string $value Raw column value.
     * @return bool
     */
    public static function is_authenticated_payload(string $value): bool {
        if ($value === '') {
            return false;
        }
        $data = base64_decode($value, true);
        if ($data === false || strlen($data) < 49) {
            return false;
        }
        $hmac = substr($data, 0, 32);
        $iv = substr($data, 32, 16);
        $ciphertext = substr($data, 48);
        try {
            $split = hash_hmac('sha256', $iv . $ciphertext, self::mac_key(), true);
            if (hash_equals($split, $hmac)) {
                return true;
            }
            $shared = hash_hmac('sha256', $iv . $ciphertext, self::get_key(), true);
            return hash_equals($shared, $hmac);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Log (once per request) that a value still MAC'd under the old shared
     * master key was read. Surfaces remaining pre-STR-10b values so the
     * operator knows to run the harden pass; mirrors log_legacy_decrypt_use().
     */
    private static function log_shared_key_decrypt_use(): void {
        static $logged = false;
        if ($logged) {
            return;
        }
        $logged = true;
        error_log('[MealsDB Encryption] WARNING: value authenticated under the legacy shared master key (pre-STR-10b). Run the encryption harden pass (MealsDB_Encryption_Migrator::run_full_harden) to convert to split cipher/MAC keys.');
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
     * Normalise an index input so equal logical values hash identically.
     * Shared by both index versions — case-folded, trimmed.
     */
    private static function normalize_index_input(string $plaintext): string {
        return strtolower(trim($plaintext));
    }

    /**
     * Legacy (v1) search index: unsalted SHA-256 of the normalised plaintext.
     *
     * Retained for the migration window only — government IDs have low entropy
     * and well-known formats, so a leaked DB exposes these v1 hashes to offline
     * dictionary attack (audit STR-10a). New writes use create_index_v2 once the
     * harden pass has flipped index_format_is_v2(). Do not call directly except
     * to compare against not-yet-migrated rows.
     *
     * @param string $plaintext
     * @return string 64-char lowercase hex.
     */
    public static function create_index_v1(string $plaintext): string {
        return hash('sha256', self::normalize_index_input($plaintext));
    }

    /**
     * Keyed (v2) search index: HMAC-SHA256 under the derived index subkey
     * (STR-10a). A DB leak alone no longer permits an offline dictionary attack
     * because the attacker also needs the master key (which lives in wp-config,
     * not the database). Output is 64-char hex — same width as v1, so it fits
     * the existing CHAR(64) `*_index` columns without a schema change.
     *
     * @param string $plaintext
     * @return string 64-char lowercase hex.
     */
    public static function create_index_v2(string $plaintext): string {
        return hash_hmac('sha256', self::normalize_index_input($plaintext), self::index_key());
    }

    /**
     * Create a searchable index for encrypted fields, in whichever format is
     * currently active. This allows exact-match search over encrypted data
     * without decrypting it.
     *
     * The version is gated by index_format_is_v2() so the WRITE format and the
     * LOOKUP format always agree: the flag flips to v2 only after the migrator
     * has recomputed every existing `*_index` column (STR-10a sequencing). Both
     * the client form (deterministic_hash) and the consolidated importer route
     * through here, so they move in lockstep.
     *
     * @param string $plaintext The value to create an index for
     * @return string 64-char lowercase hex
     */
    public static function create_index(string $plaintext): string {
        return self::index_format_is_v2()
            ? self::create_index_v2($plaintext)
            : self::create_index_v1($plaintext);
    }

    /**
     * Whether keyed (v2) HMAC indexes are the active read/write format.
     *
     * Defaults to FALSE so existing v1 indexes keep matching until the migrator
     * has recomputed them all. Flip via activate_index_v2() (the harden pass
     * does this automatically on a clean full run) or pin with the
     * MEALSDB_INDEX_HMAC_ACTIVE constant in wp-config.php.
     */
    public static function index_format_is_v2(): bool {
        if (defined('MEALSDB_INDEX_HMAC_ACTIVE')) {
            return (bool) MEALSDB_INDEX_HMAC_ACTIVE;
        }
        if (function_exists('get_option')) {
            return (bool) get_option('mealsdb_index_hmac_active', false);
        }
        return false;
    }

    /**
     * Mark keyed (v2) indexes as the active format. Call ONLY after every
     * existing `*_index` column has been recomputed to v2, or exact-match
     * lookups will miss not-yet-migrated rows. The MEALSDB_INDEX_HMAC_ACTIVE
     * constant, if defined, overrides this option either way.
     */
    public static function activate_index_v2(): void {
        if (function_exists('update_option')) {
            // autoload=false: read only on encrypted-search paths, not every page.
            update_option('mealsdb_index_hmac_active', true, false);
        }
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
