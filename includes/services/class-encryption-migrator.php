<?php
/**
 * Inventory and re-encrypt legacy-format encrypted PII in meals_clients.
 *
 * Legacy payloads (pre-HMAC) are decrypted without integrity verification, which
 * is a known hazard (see H2 in the code review). This tool:
 *
 *   1. Classifies every row in every encrypted column as 'empty', 'new',
 *      'legacy', or 'plaintext'.
 *   2. Re-encrypts 'legacy' values under the current authenticated format,
 *      one client at a time inside a per-client transaction.
 *
 * Not wired to any hook on purpose. Invoke explicitly from WP-CLI, a one-off
 * admin action, or a site-health check. Only after inventory() reports zero
 * legacy rows across every environment should the legacy branch in
 * MealsDB_Encryption::decrypt() be removed.
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Encryption_Migrator {

    /**
     * Transient key for cached inventory results. Keyed separately
     * from the raw inventory() output so a future refactor can
     * introduce additional views without touching cached data.
     */
    private const INVENTORY_TRANSIENT = 'mealsdb_encryption_inventory';

    /**
     * Cached inventory of legacy payload counts across encrypted columns.
     *
     * inventory() walks the full table and classifies every row, which
     * is fine for a migration action but too expensive to run on every
     * admin page load. Use this wrapper on surface UX (admin notices,
     * dashboards) and let the cache expire after $ttl seconds.
     *
     * @param int $ttl Seconds to cache. Default 6 hours.
     * @return array<string, array<string, int>>
     */
    public static function inventory_cached(int $ttl = 21600): array {
        if (function_exists('get_transient')) {
            $cached = get_transient(self::INVENTORY_TRANSIENT);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $fresh = self::inventory();

        if (function_exists('set_transient')) {
            set_transient(self::INVENTORY_TRANSIENT, $fresh, $ttl);
        }

        return $fresh;
    }

    /**
     * Total count of rows still stored in the pre-HMAC legacy format.
     *
     * Returns 0 when the last cached inventory is clean, so callers
     * can cheaply decide whether to flag remediation.
     */
    public static function legacy_total_cached(): int {
        $total = 0;
        foreach (self::inventory_cached() as $column_report) {
            $total += (int) ($column_report['legacy'] ?? 0);
        }
        return $total;
    }

    /**
     * Drop the cached inventory — call after reencrypt_legacy() so the
     * next UX read reflects the new, lower count.
     */
    public static function invalidate_inventory_cache(): void {
        if (function_exists('delete_transient')) {
            delete_transient(self::INVENTORY_TRANSIENT);
        }
    }

    /**
     * Classify every encrypted column across meals_clients.
     *
     * Read-only. Safe to run on production at any time.
     *
     * @return array<string, array<string, int>> column => [empty|new|legacy|plaintext => count]
     */
    public static function inventory(): array {
        global $wpdb;

        $table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $columns = MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS;

        $report = [];
        foreach ($columns as $col) {
            $report[$col] = ['empty' => 0, 'new' => 0, 'legacy' => 0, 'plaintext' => 0];

            // Walk the table in 1000-row windows so a clients table with
            // tens of thousands of rows doesn't have to be materialised
            // into PHP memory all at once. Column names come from a
            // hardcoded constant — safe to interpolate.
            $batch_size = 1000;
            $offset     = 0;
            while (true) {
                $sql = $wpdb->prepare(
                    sprintf('SELECT `%s` AS v FROM `%s` ORDER BY client_id ASC LIMIT %%d OFFSET %%d', $col, $table),
                    $batch_size,
                    $offset
                );
                $rows = $wpdb->get_col($sql);
                if (!is_array($rows) || empty($rows)) {
                    break;
                }

                foreach ($rows as $raw) {
                    $kind = MealsDB_Encryption::classify_payload((string) $raw);
                    $report[$col][$kind]++;
                }

                if (count($rows) < $batch_size) {
                    break;
                }
                $offset += $batch_size;
            }
        }

        return $report;
    }

    /**
     * Re-encrypt every legacy-format value under the current authenticated format.
     *
     * Runs row-by-row with a per-row transaction so a failure on one client
     * does not affect others. The legacy decrypt branch in
     * MealsDB_Encryption::decrypt() is what makes this possible; keep it in
     * place until inventory() reports zero legacy rows on every environment.
     *
     * @param int  $batch_size Max rows per column per call. Default 200.
     * @param bool $dry_run    When true, report what would change but don't write.
     * @return array{processed:int, reencrypted:int, failed:int, columns:array<string,int>}
     */
    public static function reencrypt_legacy(int $batch_size = 200, bool $dry_run = false): array {
        global $wpdb;

        $table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $columns = MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS;

        $stats = [
            'processed'   => 0,
            'reencrypted' => 0,
            'failed'      => 0,
            'columns'     => array_fill_keys($columns, 0),
        ];

        foreach ($columns as $col) {
            // ORDER BY client_id ASC: without it, repeated calls of
            // reencrypt_legacy() can return the same rows over and over
            // until they're all converted, wasting work and obscuring
            // the "no more legacy" termination condition.
            $sql = $wpdb->prepare(
                sprintf(
                    "SELECT client_id, `%s` AS v FROM `%s` WHERE `%s` IS NOT NULL AND `%s` <> '' ORDER BY client_id ASC LIMIT %%d",
                    $col,
                    $table,
                    $col,
                    $col
                ),
                $batch_size
            );
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $stats['processed']++;

                if (MealsDB_Encryption::classify_payload((string) $row['v']) !== 'legacy') {
                    continue;
                }

                if ($dry_run) {
                    $stats['reencrypted']++;
                    $stats['columns'][$col]++;
                    continue;
                }

                $wpdb->query('START TRANSACTION');
                try {
                    $plaintext = MealsDB_Encryption::decrypt($row['v']);
                    $fresh     = MealsDB_Encryption::encrypt($plaintext);

                    $updated = $wpdb->update(
                        $table,
                        [$col => $fresh],
                        ['client_id' => (int) $row['client_id']]
                    );

                    if ($updated === false) {
                        throw new \RuntimeException($wpdb->last_error ?: 'update returned false');
                    }

                    $wpdb->query('COMMIT');
                    $stats['reencrypted']++;
                    $stats['columns'][$col]++;
                } catch (\Throwable $e) {
                    $wpdb->query('ROLLBACK');
                    $stats['failed']++;
                    error_log(sprintf(
                        '[MealsDB Encryption Migrator] client_id=%d column=%s failed: %s',
                        (int) $row['client_id'],
                        $col,
                        $e->getMessage()
                    ));
                }
            }
        }

        // Any successful re-encryption invalidates the cached legacy
        // count so the admin notice reflects the new state on the next
        // page load rather than waiting for the 6-hour TTL.
        if (!$dry_run && $stats['reencrypted'] > 0) {
            self::invalidate_inventory_cache();
        }

        return $stats;
    }
}
