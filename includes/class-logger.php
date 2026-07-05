<?php
/**
 * Audit logger for Meals DB plugin actions.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Logger {

    /**
     * Log an error message to the error log with a Meals DB prefix.
     *
     * Replaces long base64-looking blobs and obvious PII fragments
     * (emails, NANP phone numbers, 9+-digit government-ID runs — bare or
     * 3-3-3 separator-grouped like a "123-456-789" SIN — and
     * encrypted-payload-shaped strings) with fingerprints so server error
     * logs (often world-readable on shared hosts) don't leak ciphertext,
     * names, or contact details when callers helpfully pass through
     * $wpdb->last_error or exception messages that include the offending
     * row's content. (Names in free text are NOT detectable here — keep
     * names out of log message strings; pass them via redacted fields.)
     */
    public static function error(string $message): void {
        error_log('[MealsDB] ' . self::sanitize_for_log($message));
    }

    /**
     * Best-effort scrub of a free-text log message. Capped at 2 KB.
     */
    public static function sanitize_for_log(string $message): string {
        if ($message === '') {
            return '';
        }

        // Replace email-looking substrings with a fingerprint. If
        // preg_replace_callback returns null (regex error — shouldn't
        // happen but the safer fallback is documented explicitly here),
        // fall through to a conservative "[REDACTED]" rather than
        // letting the original unscrubbed message reach the log.
        $step = preg_replace_callback(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            static function ($m) {
                return '[email:' . substr(hash('sha256', strtolower($m[0])), 0, 8) . ']';
            },
            $message
        );
        if ($step === null || preg_last_error() !== PREG_NO_ERROR) {
            return '[REDACTED — log scrubber regex failed]';
        }
        $message = $step;

        // Replace NANP-style phone numbers (10 digits, optional +1 and
        // -/./space separators or parens) with a fingerprint. Digit boundaries
        // prevent matching inside a longer number. The docblock promised this;
        // previously only emails + blobs were scrubbed, so a "506-555-1234" in
        // a caught exception reached error_log AND the failure-digest email.
        $step = preg_replace_callback(
            '/(?<!\d)(?:\+?1[-. ]?)?\(?\d{3}\)?[-. ]?\d{3}[-. ]?\d{4}(?!\d)/',
            static function ($m) {
                $digits = preg_replace('/\D/', '', $m[0]);
                return '[phone:' . substr(hash('sha256', (string) $digits), 0, 8) . ']';
            },
            $message
        );
        if ($step === null || preg_last_error() !== PREG_NO_ERROR) {
            return '[REDACTED — log scrubber regex failed]';
        }
        $message = $step;

        // Replace separator-grouped 9-digit government IDs (SIN / health-card in
        // the common 3-3-3 form: "123-456-789", "123 456 789", "123.456.789")
        // with a fingerprint. The bare-run pass below requires an UNBROKEN run of
        // >= 9 digits, so a hyphen/space/dot-grouped ID slipped every pass and
        // reached world-readable logs + the failure-digest email verbatim. Run
        // this BEFORE the bare-run pass and fingerprint the digits-only form
        // (strip separators first, like the phone pass) so the same ID hashes
        // identically however it was formatted — and matches the bare-run
        // fingerprint for the same digits. Phones (10-digit 3-3-4) were already
        // fingerprinted above and can't match this 3-3-3 shape.
        $step = preg_replace_callback(
            '/(?<!\d)\d{3}[-. ]\d{3}[-. ]\d{3}(?!\d)/',
            static function ($m) {
                $digits = preg_replace('/\D/', '', $m[0]);
                return '[id:' . substr(hash('sha256', (string) $digits), 0, 8) . ']';
            },
            $message
        );
        if ($step === null || preg_last_error() !== PREG_NO_ERROR) {
            return '[REDACTED — log scrubber regex failed]';
        }
        $message = $step;

        // Replace bare runs of >= 9 digits (government IDs — individual_id /
        // vet_health_card / SIN — which are 9+ digits) with a fingerprint. Runs
        // this short are not base64-blob-shaped, so the blob pass below would
        // miss them. Short numbers (order ids, counts) are left intact. Phones
        // were already fingerprinted above, so this only catches non-phone IDs.
        $step = preg_replace_callback(
            '/(?<!\d)\d{9,}(?!\d)/',
            static function ($m) {
                return '[id:' . substr(hash('sha256', $m[0]), 0, 8) . ']';
            },
            $message
        );
        if ($step === null || preg_last_error() !== PREG_NO_ERROR) {
            return '[REDACTED — log scrubber regex failed]';
        }
        $message = $step;

        // Replace base64-looking blobs >= 32 chars with a length-tagged stub.
        $step = preg_replace_callback(
            '#[A-Za-z0-9+/]{32,}={0,2}#',
            static function ($m) {
                return '[blob:' . strlen($m[0]) . 'B]';
            },
            $message
        );
        if ($step === null || preg_last_error() !== PREG_NO_ERROR) {
            return '[REDACTED — log scrubber regex failed]';
        }
        $message = $step;

        if (strlen($message) > 2048) {
            $message = substr($message, 0, 2045) . '...';
        }

        return $message;
    }

    /**
     * Fields whose raw value must never appear in the audit log. The
     * logger replaces the raw value with a short SHA-256 fingerprint so a
     * reviewer can still see "did the value change" without seeing the
     * value itself.
     */
    private const SENSITIVE_FIELDS = [
        // Canonical encrypted PII columns.
        'individual_id',
        'individual_id_index',
        'requisition_id',
        'requisition_id_index',
        'vet_health_card',
        'vet_health_card_index',
        'diet_concerns',
        'customer_comments',
        'client_comments',
        // Standard PII not encrypted but still sensitive.
        'client_email',
        'user_email',
        'phone_primary',
        'phone_secondary',
        'client_phone_1',
        'client_phone_2',
        'alternate_contact_name',
        'alternate_contact_email',
        'alternate_contact_phone_1',
        'alternate_contact_phone_2',
        'alt_contact_name',
        'alt_contact_email',
        'alt_contact_phone_primary',
        'alt_contact_phone_secondary',
        'birth_date',
        'street_name',
        'address_street_name',
        'delivery_street_name',
        'delivery_address_street_name',
        'postal_code',
        'address_postal',
        'delivery_postal_code',
        'delivery_address_postal',
    ];

    /**
     * Replace the raw value of a sensitive field with a short
     * non-reversible fingerprint suitable for diff-style audit review.
     */
    /**
     * Produce the audit fingerprint of a single value (same shape redact_value
     * emits for a sensitive field). Public so callers that log a COMPOSITE blob
     * — e.g. the delete_client snapshot, whose 'record' field name bypasses the
     * field-keyed redaction — can fingerprint the sensitive members BEFORE
     * encoding, instead of writing PII (a client_email) to the append-only,
     * long-retention audit log in cleartext.
     *
     * @param string|null $value
     * @return string|null '[redacted:sha256=…]' for a non-empty value; the input
     *                     unchanged for null / empty.
     */
    public static function fingerprint_value(?string $value): ?string {
        if ($value === null || $value === '') {
            return $value;
        }
        return '[redacted:sha256=' . substr(hash('sha256', $value), 0, 12) . ']';
    }

    private static function redact_value(string $field, ?string $value): ?string {
        if ($value === null || $value === '') {
            return $value;
        }

        $field_key = strtolower($field);
        if (!in_array($field_key, self::SENSITIVE_FIELDS, true)) {
            // The invoice-draft per-field audit (INV-DRAFT-2) names the
            // changed field as "<client_id>:<field>" so one draft (target_id)
            // can carry per-client, per-field edit rows. That composite would
            // slip past the exact-match check above and write a government ID
            // (individual_id / vet_health_card / requisition_id) or an address
            // to the audit log in CLEARTEXT. Extract the field portion of a
            // "<digits>:<field>" key and re-check it against the sensitive
            // list. No existing (non-composite) caller passes a colon-bearing
            // field name, so this is inert for every other call site.
            if (preg_match('/^\d+:(.+)$/', $field_key, $m)
                && in_array($m[1], self::SENSITIVE_FIELDS, true)) {
                $fingerprint = substr(hash('sha256', $value), 0, 12);
                return '[redacted:sha256=' . $fingerprint . ']';
            }
            return $value;
        }

        $fingerprint = substr(hash('sha256', $value), 0, 12);
        return '[redacted:sha256=' . $fingerprint . ']';
    }

    /**
     * Logs a change or action to the audit trail.
     *
     * Sensitive fields (encrypted PII, emails, phone numbers, addresses,
     * birth dates) are replaced with a SHA-256 fingerprint before
     * insert. A reviewer can still see "old != new" without the
     * underlying value being persisted in plaintext to the audit log,
     * which would otherwise be a parallel exposure path.
     *
     * @param string $action Action name (e.g. sync_override)
     * @param int $target_id ID of the client affected
     * @param string $field Field that was changed
     * @param string|null $old Previous value
     * @param string|null $new New value
     * @param string $source Source of change (woo, mealsdb, etc.)
     */
    public static function log(string $action, int $target_id, string $field, ?string $old, ?string $new, string $source = 'mealsdb') {
        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::AUDIT_LOG);
        $user_id = get_current_user_id();

        $old = self::redact_value($field, $old);
        $new = self::redact_value($field, $new);

        // Write created_at explicitly in UTC (Pattern 11). The column otherwise
        // falls to MySQL DEFAULT CURRENT_TIMESTAMP, which evaluates in the DB
        // server's timezone — skewing the audit trail's ordering against the
        // gmdate-stamped operational trunk on a non-UTC host.
        $sql = $wpdb->prepare(
            "INSERT INTO `{$table}` (user_id, action, target_id, field_changed, old_value, new_value, source, created_at) VALUES (%d, %s, %d, %s, %s, %s, %s, %s)",
            $user_id,
            $action,
            $target_id,
            $field,
            $old,
            $new,
            $source,
            gmdate('Y-m-d H:i:s')
        );

        $result = $wpdb->query($sql);
        if ($result === false) {
            error_log('[MealsDB Logger] Insert failed: ' . $wpdb->last_error);
        }
    }

    /**
     * Optional helper: get recent logs (for display/export).
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent_logs(int $limit = 50): array {
        // Audit rows include fingerprints of PII changes. Even with the
        // redaction in log(), a reader who can correlate many
        // [redacted:sha256=abcdef] fingerprints against known-value
        // candidates can still rainbow-match them. Gate the read to
        // manage_options — matching the ONLY consumer, the Event Log page
        // (class-event-log-page.php), which deliberately requires
        // manage_options because "operational logs can name clients and
        // orders, so keep the audience tight" (CLAUDE.md calls the audit
        // log "sensitive to read"). The prior baseline (can_access_plugin
        // → manage_woocommerce) was one tier weaker than its own consumer,
        // so a future baseline-capability caller (REST route, report widget)
        // hitting this method directly could enumerate 200 audit rows below
        // the tier the operator chose for the same data. Keep the
        // function_exists guard so CLI/test contexts (no capability API)
        // aren't blocked.
        if (function_exists('current_user_can')
            && !current_user_can('manage_options')) {
            return [];
        }

        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::AUDIT_LOG);
        $sql = $wpdb->prepare(
            "SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d",
            $limit
        );

        $results = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($results)) {
            error_log('[MealsDB Logger] Failed to execute recent logs query: ' . ($wpdb->last_error ?: 'unknown error'));
            return [];
        }

        return $results;
    }
}
