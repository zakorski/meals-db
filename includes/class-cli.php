<?php
/**
 * WP-CLI commands for Meals DB.
 *
 * Registered only under WP-CLI (see meals-db-main.php). Provides the operator
 * tooling the admin notices reference — notably the encryption hardening run,
 * which the admin UI cannot do safely (long-running, AJAX-timeout-prone).
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_CLI {

    /**
     * Re-encrypt legacy CBC PII payloads and rebuild deterministic indexes
     * under the STR-10 authenticated split-key format, so the legacy-decrypt
     * path can be disabled (MEALSDB_DISABLE_LEGACY_DECRYPT).
     *
     * Drains the whole meals_clients table via the cursor-based
     * MealsDB_Encryption_Migrator::run_full_harden() — the same proven path the
     * inventory/admin-notice describe. WP-CLI has no request timeout, so this is
     * the safe way to run a full migration on ~890 clients.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what WOULD change without writing anything.
     *
     * [--batch-size=<n>]
     * : Rows per batch. Default 200.
     *
     * ## EXAMPLES
     *
     *     # Preview the work first.
     *     wp mealsdb reencrypt-legacy --dry-run
     *
     *     # Run the migration for real.
     *     wp mealsdb reencrypt-legacy
     *
     * @when after_wp_load
     *
     * @param array $args
     * @param array $assoc_args
     * @return void
     */
    public function reencrypt_legacy($args, $assoc_args): void {
        if (!class_exists('MealsDB_Encryption_Migrator')) {
            \WP_CLI::error('MealsDB_Encryption_Migrator is unavailable.');
        }

        $dry_run = isset($assoc_args['dry-run']);
        $batch   = isset($assoc_args['batch-size']) ? max(1, (int) $assoc_args['batch-size']) : 200;

        \WP_CLI::log(sprintf(
            'Encryption harden (%s, batch=%d) — draining meals_clients…',
            $dry_run ? 'DRY RUN' : 'LIVE',
            $batch
        ));

        $stats = MealsDB_Encryption_Migrator::run_full_harden($batch, $dry_run);

        \WP_CLI::log(sprintf(
            'processed=%d  re-encrypted=%d  re-indexed=%d  rows_changed=%d  failed=%d  batches=%d',
            (int) ($stats['processed'] ?? 0),
            (int) ($stats['reencrypted'] ?? 0),
            (int) ($stats['reindexed'] ?? 0),
            (int) ($stats['rows_changed'] ?? 0),
            (int) ($stats['failed'] ?? 0),
            (int) ($stats['batches'] ?? 0)
        ));

        if (!empty($stats['aborted'])) {
            // run_full_harden does NOT flip the v2 cutover on an aborted run.
            \WP_CLI::error('Aborted: ' . ($stats['abort_reason'] ?? 'unknown') . ' — no index-v2 cutover performed. Fix the root cause and re-run.');
        }

        if (!$dry_run) {
            // Committed change to PII storage → audit log.
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log('reencrypt_legacy', 0, 'encryption', null, sprintf(
                    'reencrypted=%d reindexed=%d rows_changed=%d index_v2=%s',
                    (int) ($stats['reencrypted'] ?? 0),
                    (int) ($stats['reindexed'] ?? 0),
                    (int) ($stats['rows_changed'] ?? 0),
                    !empty($stats['index_v2_activated']) ? 'activated' : 'unchanged'
                ));
            }
            if (!empty($stats['index_v2_activated'])) {
                \WP_CLI::log('Deterministic index v2 activated (clean, complete run).');
            }
            \WP_CLI::log('When the inventory reports zero legacy rows, set MEALSDB_DISABLE_LEGACY_DECRYPT in wp-config.php to refuse any future legacy payload.');
        }

        \WP_CLI::success($dry_run ? 'Dry run complete (nothing written).' : 'Encryption hardening complete.');
    }
}
