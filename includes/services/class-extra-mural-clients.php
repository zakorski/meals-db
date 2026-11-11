<?php
/**
 * Active MealsDB clients whose WordPress customer_group is Extra Mural
 * (directive K23 ITEM 5).
 *
 * WHY: Extra Mural is billed separately, under Extra Mural — not to SDNB
 * (confirmed 2026-11-03) — and MealsDB has no client_type for it. Before K23
 * these users were imported / promoted as SDNB (or landed as Private), so a
 * handful of existing clients carry a client_type that bills them to the
 * wrong payer. K23 deliberately does NOT change any stored client_type: the
 * correct type is an operator decision. This list is what makes that
 * decision visible (Sync Dashboard panel).
 *
 * Read-only. "What is Extra Mural" is decided by the one shared mapper
 * (MealsDB_Migration_Consolidated::is_extra_mural_group), never re-spelled
 * here.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

final class MealsDB_Extra_Mural_Clients {

    /**
     * Active clients (ANY client_type — these sit as SDNB or Private today)
     * linked to a WordPress user whose customer_group is Extra Mural.
     *
     * last_delivery is the meals_clients.last_delivery_date column
     * (mark_delivered writes it); when that is empty it falls back to the
     * legacy last_delivery_date usermeta, because the clients this panel
     * exists for last had deliveries before the plugin recorded them. '' when
     * neither holds a date.
     *
     * @return array<int, array{client_id: int, wp_user_id: int, name: string, client_type: string, customer_group: string, last_delivery: string}>|null
     *         null when the list could not be built — the view says so
     *         instead of showing "none".
     */
    public static function get_active_clients(): ?array {
        try {
            global $wpdb;

            $user_ids = MealsDB_Migration_Consolidated::extra_mural_user_ids();
            if ($user_ids === null) {
                return null;
            }
            if ($user_ids === []) {
                return [];
            }

            $table        = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
            $placeholders = implode(', ', array_fill(0, count($user_ids), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT client_id, wp_user_id, client_type, first_name, last_name, last_delivery_date
                   FROM `{$table}`
                  WHERE active = 1 AND wp_user_id IN ({$placeholders})
                  ORDER BY client_id ASC",
                ...$user_ids
            ), ARRAY_A);
            if (!is_array($rows)) {
                return null;
            }

            $out = [];
            foreach ($rows as $row) {
                $wp_user_id = (int) $row['wp_user_id'];
                $out[] = [
                    'client_id'      => (int) $row['client_id'],
                    'wp_user_id'     => $wp_user_id,
                    'name'           => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
                    'client_type'    => (string) ($row['client_type'] ?? ''),
                    'customer_group' => self::meta($wp_user_id, 'customer_group'),
                    'last_delivery'  => self::last_delivery((string) ($row['last_delivery_date'] ?? ''), $wp_user_id),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Extra Mural] client list failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Canonical Y-m-d from the column, else the legacy usermeta, else ''. */
    private static function last_delivery(string $column_value, int $wp_user_id): string {
        foreach ([$column_value, self::meta($wp_user_id, 'last_delivery_date')] as $candidate) {
            // Strict: '0', '0000-00-00' and malformed legacy values read as "none".
            $parsed = MealsDB_Sync_Comparator::parse_date(trim($candidate));
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return '';
    }

    private static function meta(int $wp_user_id, string $key): string {
        if ($wp_user_id <= 0 || !function_exists('get_user_meta')) {
            return '';
        }
        $value = get_user_meta($wp_user_id, $key, true);
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
