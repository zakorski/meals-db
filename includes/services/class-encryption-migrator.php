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

        return $stats;
    }
}
