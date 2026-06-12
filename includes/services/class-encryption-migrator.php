<?php
/**
 * Inventory and re-encrypt legacy-format encrypted PII in meals_clients.
 *
 * Legacy payloads (pre-HMAC) are decrypted without integrity verification, which
 * is a known hazard (see H2 in the code review). This tool:
 *
 *   1. Classifies every row in every encrypted column as 'empty', 'new',
 *      'legacy', or 'plaintext'.
 *   2. Re-encrypts 'legacy' (pre-HMAC) values under the current authenticated
 *      format, one client at a time inside a per-client transaction.
 *
 * STR-10a/b hardening pass — run_full_harden() / harden_encryption():
 * a SUPERSET migration that, in one walk over the clients table, (a) re-encrypts
 * every PII blob under the split cipher/MAC subkeys (STR-10b) — including values
 * still MAC'd under the old shared master key — and (b) recomputes every
 * deterministic `*_index` column with the keyed v2 HMAC (STR-10a), then flips
 * the index format live. Prefer run_full_harden() for the STR-10 cutover;
 * reencrypt_legacy() remains for the narrower pre-HMAC-only case.
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
                    $value = (string) $raw;
                    $kind  = MealsDB_Encryption::classify_payload($value);
                    // classify_payload() is purely structural and calls anything
                    // >= 49 bytes 'new'. A long pre-HMAC legacy value (IV +
                    // multi-block ciphertext, e.g. a lengthy diet_concerns /
                    // customer_comments) also clears 49 bytes, so confirm a 'new'
                    // verdict against an actual HMAC. If it doesn't authenticate,
                    // it's really legacy (or corrupt) and MUST be counted as such
                    // — otherwise the admin notice would report "0 legacy" and
                    // invite disabling the legacy read path, locking these rows
                    // out (the very thing run_full_harden exists to prevent).
                    if ($kind === 'new' && !MealsDB_Encryption::is_authenticated_payload($value)) {
                        $kind = 'legacy';
                    }
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
     * Default threshold for the reencrypt_legacy circuit breaker.
     *
     * When cumulative per-row failures cross this count inside a single
     * call, the batch aborts. Protects against the "wrong key" and
     * "corrupted ciphertext" failure modes: without a threshold the
     * migrator would plough through every legacy row in the table and
     * emit one error_log line per, filling logs and hiding the root
     * cause. 50 is low enough that a real bulk corruption is caught
     * fast, high enough that isolated one-row accidents don't abort
     * an otherwise-healthy run.
     */
    private const FAILURE_THRESHOLD = 50;

    /**
     * Re-encrypt every legacy-format value under the current authenticated format.
     *
     * Runs row-by-row with a per-row transaction so a failure on one client
     * does not affect others. The legacy decrypt branch in
     * MealsDB_Encryption::decrypt() is what makes this possible; keep it in
     * place until inventory() reports zero legacy rows on every environment.
     *
     * Circuit-breaker: once $stats['failed'] reaches the supplied
     * $failure_threshold (default 50) the run aborts. The stats array
     * then carries an 'aborted' => true flag and 'abort_reason'
     * describing why, so the caller can surface it in the UI without
     * mistaking the early return for a clean finish.
     *
     * @param int  $batch_size        Max rows per column per call. Default 200.
     * @param bool $dry_run           When true, report what would change but don't write.
     * @param int  $failure_threshold Abort after this many cumulative per-row failures. 0 = no threshold.
     * @return array{processed:int, reencrypted:int, failed:int, aborted:bool, abort_reason:?string, columns:array<string,int>}
     */
    public static function reencrypt_legacy(int $batch_size = 200, bool $dry_run = false, int $failure_threshold = self::FAILURE_THRESHOLD): array {
        global $wpdb;

        $table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $columns = MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS;

        $stats = [
            'processed'    => 0,
            'reencrypted'  => 0,
            'failed'       => 0,
            'aborted'      => false,
            'abort_reason' => null,
            'columns'      => array_fill_keys($columns, 0),
        ];

        foreach ($columns as $col) {
            // Keyset pagination by client_id. The previous single LIMIT-batch
            // read (no cursor) re-read the same first $batch_size rows on every
            // call — converted rows still satisfy `IS NOT NULL AND <> ''` — so
            // rows past position $batch_size were permanently unreachable.
            // Advancing a `client_id > $after` window drains the whole column
            // regardless of conversion state, terminating on a short batch.
            $after = 0;
            do {
                $sql = $wpdb->prepare(
                    sprintf(
                        "SELECT client_id, `%s` AS v FROM `%s` WHERE client_id > %%d AND `%s` IS NOT NULL AND `%s` <> '' ORDER BY client_id ASC LIMIT %%d",
                        $col,
                        $table,
                        $col,
                        $col
                    ),
                    $after,
                    $batch_size
                );
                $rows = $wpdb->get_results($sql, ARRAY_A);
                if (!is_array($rows) || empty($rows)) {
                    break;
                }
                $batch_count = count($rows);

                foreach ($rows as $row) {
                    // Advance the cursor on EVERY row (converted or not) so the
                    // next batch never re-reads a row we've already processed.
                    $after = (int) $row['client_id'];
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

                        if ($failure_threshold > 0 && $stats['failed'] >= $failure_threshold) {
                            $stats['aborted']      = true;
                            $stats['abort_reason'] = sprintf(
                                'Aborted after %d failures (threshold=%d). Check the error log for the root cause before re-running.',
                                $stats['failed'],
                                $failure_threshold
                            );
                            error_log('[MealsDB Encryption Migrator] ' . $stats['abort_reason']);
                            break; // exit the row loop; the aborted flag stops the rest
                        }
                    }
                }
            } while (!$stats['aborted'] && $batch_count === $batch_size);

            if ($stats['aborted']) {
                break;
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

    /**
     * Convert ONE batch of client rows to the STR-10a/b hardened format.
     *
     * For each row (in client_id order) this single pass does BOTH crypto-format
     * migrations in one walk over the data, as directive STR-10 requires:
     *   1. STR-10b — re-encrypt every encrypted PII column under the split
     *      cipher/MAC subkeys (skipping values already in split-key format).
     *   2. STR-10a — recompute every deterministic `*_index` column with the
     *      keyed v2 HMAC, derived from the decrypted (or, for the plaintext
     *      delivery_initials source, raw) source value.
     * Both happen inside one per-row transaction, so a row is never left with a
     * re-encrypted blob but a stale index (or vice versa).
     *
     * Cursor-based: processes rows with `client_id > $after_client_id`, ordered
     * ascending, up to $batch_size. Returns `last_client_id` (feed it back to
     * page) and `done` (true when fewer than $batch_size rows remained, i.e. the
     * tail was reached). Use run_full_harden() to drive the whole table.
     *
     * Idempotent: a re-run sees split-key blobs and v2 indexes, computes
     * identical values, writes nothing (`rows_changed` stays 0). Circuit-broken
     * on the same FAILURE_THRESHOLD discipline as reencrypt_legacy().
     *
     * @param int  $batch_size        Max rows this call. Default 200.
     * @param bool $dry_run           Report what would change without writing.
     * @param int  $failure_threshold Abort after this many per-row failures. 0 = none.
     * @param int  $after_client_id   Process rows with client_id greater than this.
     * @return array{processed:int, reencrypted:int, reindexed:int, rows_changed:int, failed:int, aborted:bool, abort_reason:?string, last_client_id:int, done:bool}
     */
    public static function harden_encryption(
        int $batch_size = 200,
        bool $dry_run = false,
        int $failure_threshold = self::FAILURE_THRESHOLD,
        int $after_client_id = 0
    ): array {
        global $wpdb;

        $table       = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $encrypted   = MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS;
        $index_map   = class_exists('MealsDB_Client_Form')
            ? MealsDB_Client_Form::deterministic_index_map()
            : [];

        $stats = [
            'processed'      => 0,
            'reencrypted'    => 0,
            'reindexed'      => 0,
            'rows_changed'   => 0,
            'failed'         => 0,
            'aborted'        => false,
            'abort_reason'   => null,
            'last_client_id' => $after_client_id,
            'done'           => true,
        ];

        // Column projection: client_id, every encrypted blob, every index
        // source column, and the index columns themselves (to diff against).
        // All names come from hardcoded constants/maps — safe to interpolate.
        $select_cols = array_values(array_unique(array_merge(
            ['client_id'],
            $encrypted,
            array_keys($index_map),
            array_values($index_map)
        )));
        $col_sql = implode(', ', array_map(static function ($c) {
            return '`' . $c . '`';
        }, $select_cols));

        $sql = $wpdb->prepare(
            sprintf(
                'SELECT %s FROM `%s` WHERE client_id > %%d ORDER BY client_id ASC LIMIT %%d',
                $col_sql,
                $table
            ),
            $after_client_id,
            $batch_size
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || empty($rows)) {
            return $stats; // done === true (no more rows past the cursor)
        }

        $seen = 0;
        foreach ($rows as $row) {
            $seen++;
            $stats['processed']++;
            $client_id = (int) ($row['client_id'] ?? 0);
            $stats['last_client_id'] = $client_id;

            $update      = [];
            $local_reenc = 0;
            $local_reidx = 0;

            try {
                // Decrypt every encrypted column once; reuse for re-encryption
                // AND for index recomputation so we never decrypt twice.
                $plain = [];
                foreach ($encrypted as $col) {
                    $val = (string) ($row[$col] ?? '');
                    if ($val === '') {
                        continue;
                    }
                    // decrypt() reads legacy pre-HMAC, legacy shared-key, and
                    // split-key formats; throws on genuine corruption.
                    $plain[$col] = MealsDB_Encryption::decrypt($val);

                    // Re-encrypt only if not already split-key (idempotency).
                    if (!MealsDB_Encryption::is_split_key_payload($val)) {
                        $update[$col] = MealsDB_Encryption::encrypt($plain[$col]);
                        $local_reenc++;
                    }
                }

                // Recompute each `*_index` to v2 from its source value.
                foreach ($index_map as $source => $index_col) {
                    if (in_array($source, $encrypted, true)) {
                        $new_index = isset($plain[$source])
                            ? MealsDB_Encryption::create_index_v2($plain[$source])
                            : null; // empty source → cleared index
                    } else {
                        // Plaintext source (delivery_initials): hash the raw value.
                        $raw = (string) ($row[$source] ?? '');
                        $new_index = $raw === ''
                            ? null
                            : MealsDB_Encryption::create_index_v2($raw);
                    }

                    $current = $row[$index_col] ?? null;
                    if ($current === '') {
                        $current = null;
                    }
                    if ($new_index !== $current) {
                        $update[$index_col] = $new_index;
                        if ($new_index !== null) {
                            $local_reidx++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                error_log(sprintf(
                    '[MealsDB Encryption Migrator] harden client_id=%d failed: %s',
                    $client_id,
                    $e->getMessage()
                ));
                if ($failure_threshold > 0 && $stats['failed'] >= $failure_threshold) {
                    $stats['aborted']      = true;
                    $stats['abort_reason'] = sprintf(
                        'Aborted after %d failures (threshold=%d). Check the error log for the root cause before re-running.',
                        $stats['failed'],
                        $failure_threshold
                    );
                    error_log('[MealsDB Encryption Migrator] ' . $stats['abort_reason']);
                    $stats['done'] = false; // run interrupted; not a clean tail
                    return $stats;
                }
                continue;
            }

            if (empty($update)) {
                continue; // already hardened — idempotent no-op
            }

            if ($dry_run) {
                $stats['rows_changed']++;
                $stats['reencrypted'] += $local_reenc;
                $stats['reindexed']   += $local_reidx;
                continue;
            }

            $wpdb->query('START TRANSACTION');
            try {
                $updated = $wpdb->update($table, $update, ['client_id' => $client_id]);
                if ($updated === false) {
                    throw new \RuntimeException($wpdb->last_error ?: 'update returned false');
                }
                $wpdb->query('COMMIT');
                $stats['rows_changed']++;
                $stats['reencrypted'] += $local_reenc;
                $stats['reindexed']   += $local_reidx;
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');
                $stats['failed']++;
                error_log(sprintf(
                    '[MealsDB Encryption Migrator] harden write client_id=%d failed: %s',
                    $client_id,
                    $e->getMessage()
                ));
                if ($failure_threshold > 0 && $stats['failed'] >= $failure_threshold) {
                    $stats['aborted']      = true;
                    $stats['abort_reason'] = sprintf(
                        'Aborted after %d failures (threshold=%d). Check the error log for the root cause before re-running.',
                        $stats['failed'],
                        $failure_threshold
                    );
                    error_log('[MealsDB Encryption Migrator] ' . $stats['abort_reason']);
                    $stats['done'] = false;
                    return $stats;
                }
            }
        }

        // Fewer rows than asked → we reached the tail of the table.
        $stats['done'] = ($seen < $batch_size);
        return $stats;
    }

    /**
     * Drive harden_encryption() across the whole clients table and, on a clean
     * run, flip the keyed-index format live (STR-10a step 3).
     *
     * This is the single entry point the data-ops button / WP-CLI command should
     * call. It pages through every row (so it satisfies "batched" without making
     * the caller manage the cursor), accumulates stats, and only activates the
     * v2 index format when the ENTIRE walk completed with zero failures and no
     * abort — i.e. every existing `*_index` is now v2, so flipping lookups to v2
     * can't miss a not-yet-migrated row. A dry run never flips.
     *
     * On a FAILED/aborted run the flag is left at v1 (correct), but note that
     * any rows processed before the failure already had their index overwritten
     * to v2 in place. Because lookups still compute v1 until the flag flips,
     * those rows are temporarily unmatchable by exact search — a degraded, not
     * destructive, state (dedup may miss; nothing is lost). Resolution is to fix
     * the failing rows and re-run: already-hardened rows are idempotent no-ops,
     * the remaining rows convert, and the run then flips cleanly. This is why
     * the directive scopes the pass to the low/no-data pre-launch window.
     *
     * @param int  $batch_size        Rows per internal batch. Default 200.
     * @param bool $dry_run           Report only; never write or flip the flag.
     * @param int  $failure_threshold Abort after this many per-row failures.
     * @return array{processed:int, reencrypted:int, reindexed:int, rows_changed:int, failed:int, aborted:bool, abort_reason:?string, batches:int, index_v2_activated:bool}
     */
    public static function run_full_harden(
        int $batch_size = 200,
        bool $dry_run = false,
        int $failure_threshold = self::FAILURE_THRESHOLD
    ): array {
        $totals = [
            'processed'          => 0,
            'reencrypted'        => 0,
            'reindexed'          => 0,
            'rows_changed'       => 0,
            'failed'             => 0,
            'aborted'            => false,
            'abort_reason'       => null,
            'batches'            => 0,
            'index_v2_activated' => false,
        ];

        $after = 0;
        do {
            $batch = self::harden_encryption($batch_size, $dry_run, $failure_threshold, $after);
            $totals['batches']++;
            $totals['processed']    += $batch['processed'];
            $totals['reencrypted']  += $batch['reencrypted'];
            $totals['reindexed']    += $batch['reindexed'];
            $totals['rows_changed'] += $batch['rows_changed'];
            $totals['failed']       += $batch['failed'];

            if ($batch['aborted']) {
                $totals['aborted']      = true;
                $totals['abort_reason'] = $batch['abort_reason'];
                break;
            }

            $after = $batch['last_client_id'];
        } while (!$batch['done']);

        // Flip lookups to v2 ONLY on a clean, complete, real run. After this the
        // form/importer write v2 indexes and queries compute v2 — all existing
        // rows are already v2, so nothing misses.
        if (!$dry_run && !$totals['aborted'] && $totals['failed'] === 0) {
            MealsDB_Encryption::activate_index_v2();
            $totals['index_v2_activated'] = true;
        }

        if (!$dry_run) {
            self::invalidate_inventory_cache();
        }

        return $totals;
    }
}
