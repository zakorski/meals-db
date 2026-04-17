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
     * (emails, phone numbers, encrypted-payload-shaped strings) with
     * fingerprints so server error logs (often world-readable on shared
     * hosts) don't leak ciphertext, names, or contact details when
     * callers helpfully pass through $wpdb->last_error or exception
     * messages that include the offending row's content.
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

        // Replace email-looking substrings with a fingerprint.
        $message = preg_replace_callback(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            static function ($m) {
                return '[email:' . substr(hash('sha256', strtolower($m[0])), 0, 8) . ']';
            },
            $message
        ) ?? $message;

        // Replace base64-looking blobs >= 32 chars with a length-tagged stub.
        $message = preg_replace_callback(
            '#[A-Za-z0-9+/]{32,}={0,2}#',
            static function ($m) {
                return '[blob:' . strlen($m[0]) . 'B]';
            },
            $message
        ) ?? $message;

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
    private static function redact_value(string $field, ?string $value): ?string {
        if ($value === null || $value === '') {
            return $value;
        }

        $field_key = strtolower($field);
        if (!in_array($field_key, self::SENSITIVE_FIELDS, true)) {
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

        $sql = $wpdb->prepare(
            "INSERT INTO `{$table}` (user_id, action, target_id, field_changed, old_value, new_value, source) VALUES (%d, %s, %d, %s, %s, %s, %s)",
            $user_id,
            $action,
            $target_id,
            $field,
            $old,
            $new,
            $source
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
