<?php
/**
 * One-time backfill for the order-audit storage normalization (2026-09).
 *
 * Walks every existing audit and materialises its normalized rows
 * (meals_order_audit_rows) from the legacy encrypted payload blob, so the
 * whole history becomes queryable per-order rows and the legacy `payload`
 * column can eventually be dropped. Delegates the per-audit work (and the
 * fail-closed encryption + byte-faithful finalized handling) to
 * MealsDB_Order_Audit::backfill_rows_for(); this class is just the enumerating
 * loop + aggregate stats, with a dry-run that counts without writing.
 *
 * Idempotent: an audit that already has rows is skipped, so re-running (or
 * running after new audits self-materialised) is safe. Mirrors the plugin's
 * other one-time corrections (class-backfill-*.php).
 */
defined('ABSPATH') || exit;

class MealsDB_Backfill_Audit_Rows {

    /**
     * Backfill all audits. Dry-run by default (counts, writes nothing).
     *
     * @return array<string,int|bool> stats:
     *   audits, migrated, skipped, empty, undecodable, errors, rows, dry_run
     */
    public static function run(bool $dry_run = true): array {
        $stats = [
            'audits'      => 0,
            'migrated'    => 0,
            'skipped'     => 0,
            'empty'       => 0,
            'undecodable' => 0,
            'errors'      => 0,
            'rows'        => 0,
            'dry_run'     => $dry_run,
        ];

        try {
            global $wpdb;
            $audits = MealsDB_DB::get_table_name(MealsDB_Tables::ORDER_AUDITS);
            $ids    = $wpdb->get_col("SELECT audit_id FROM `{$audits}` ORDER BY audit_id ASC");
            if (!is_array($ids)) {
                return $stats;
            }

            foreach ($ids as $aid) {
                $stats['audits']++;
                $result = MealsDB_Order_Audit::backfill_rows_for((int) $aid, $dry_run);
                switch ($result['status'] ?? 'error') {
                    case 'migrated':
                        $stats['migrated']++;
                        $stats['rows'] += (int) ($result['rows'] ?? 0);
                        break;
                    case 'skipped_has_rows':
                        $stats['skipped']++;
                        break;
                    case 'empty':
                        $stats['empty']++;
                        break;
                    case 'undecodable':
                        $stats['undecodable']++;
                        break;
                    default:
                        $stats['errors']++;
                        break;
                }
            }
        } catch (\Throwable $e) {
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::error('[MealsDB Backfill Audit Rows] run failed: ' . $e->getMessage());
            }
            $stats['errors']++;
        }

        return $stats;
    }
}
