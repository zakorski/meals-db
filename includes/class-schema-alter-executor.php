<?php
/**
 * Schema ALTER executor — guarded application of a column-drift plan
 * (audit H7, slice 2).
 *
 * Given a Schema_Sync column mismatch, it classifies + plans the change
 * (MealsDB_Schema_Alter_Planner) and applies it under the operator decisions in
 * audit-2026-08/SCOPE-schema-sync-alter.md:
 *
 *   - SAFE changes apply automatically.
 *   - RISKY changes require explicit confirmation (the preview tool passes
 *     $confirmed_risky = true) AND must pass a read-only PRE-FLIGHT that BLOCKS
 *     the apply if it would lose data (NULL rows before a NOT NULL, over-length
 *     rows before a narrow, rows holding a to-be-removed ENUM value).
 *   - Apply prefers MySQL-8 online DDL (ALGORITHM=INPLACE, LOCK=NONE). If the
 *     server rejects INPLACE, it falls back to a plain (possibly locking, COPY)
 *     ALTER under WP MAINTENANCE MODE (decision 3), which it engages and clears.
 *   - A DDL failure returns status 'error' and audit-logs it — it never reports
 *     success (so a caller must not mark the schema version current).
 *
 * The maintenance hooks are `protected` so a test can observe them without
 * touching the filesystem.
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Schema_Alter_Executor {

    /** @var wpdb|mixed */
    protected $wpdb;

    public function __construct($wpdb = null) {
        $this->wpdb = $wpdb ?? ($GLOBALS['wpdb'] ?? null);
    }

    /**
     * Classify, (for RISKY) confirm + pre-flight, then apply one column change.
     *
     * @param array{table:string,column:string,expected:string,actual:array} $mismatch
     * @param bool $confirmed_risky The operator confirmed a RISKY change via the tool.
     * @return array{status:string, plan?:array, blockers?:array, error?:string}
     *         status: applied | needs_confirmation | blocked | error
     */
    public function run(array $mismatch, bool $confirmed_risky = false): array {
        $plan = MealsDB_Schema_Alter_Planner::plan($mismatch);

        if ($plan['tier'] === MealsDB_Schema_Alter_Planner::TIER_RISKY) {
            if (!$confirmed_risky) {
                return ['status' => 'needs_confirmation', 'plan' => $plan];
            }
            $blockers = $this->run_preflight($plan);
            if (!empty($blockers)) {
                return ['status' => 'blocked', 'blockers' => $blockers, 'plan' => $plan];
            }
        }

        return $this->apply($plan);
    }

    /**
     * Run each pre-flight probe; a probe that finds rows is a blocker.
     *
     * @return array<int,array{check:string,count:int,sql:string}>
     */
    protected function run_preflight(array $plan): array {
        $blockers = [];
        foreach ($plan['preflight'] as $check) {
            if (empty($check['sql'])) {
                continue;
            }
            $count = (int) $this->wpdb->get_var($check['sql']);
            if ($count > 0) {
                $blockers[] = ['check' => (string) $check['check'], 'count' => $count, 'sql' => (string) $check['sql']];
            }
        }
        return $blockers;
    }

    /**
     * Apply the ALTER: online DDL first, else a maintenance-mode plain ALTER.
     */
    protected function apply(array $plan): array {
        $ok = $this->wpdb->query($plan['alter_online']) !== false;

        if (!$ok) {
            // INPLACE/LOCK=NONE rejected (or otherwise failed) — retry the plain
            // form under maintenance mode, since it may rebuild + lock the table.
            $this->engage_maintenance();
            try {
                $ok = $this->wpdb->query($plan['alter_plain']) !== false;
            } finally {
                $this->clear_maintenance();
            }
        }

        if (!$ok) {
            $err = (string) ($this->wpdb->last_error ?? '');
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::error(sprintf(
                    '[MealsDB Schema Alter] ALTER failed on %s.%s: %s',
                    $plan['table'], $plan['column'], $err
                ));
            }
            return ['status' => 'error', 'error' => $err, 'plan' => $plan];
        }

        // A committed change to the data model -> audit log.
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log(
                'schema_column_altered',
                0,
                $plan['table'] . '.' . $plan['column'],
                null,
                (string) $plan['alter_plain'],
                'mealsdb'
            );
        }
        return ['status' => 'applied', 'plan' => $plan];
    }

    /**
     * Engage WP maintenance mode (the `.maintenance` drop-in) while a locking
     * ALTER runs. Best-effort + guarded so a non-WP/test context is a no-op the
     * subclass can observe.
     */
    protected function engage_maintenance(): void {
        if (defined('ABSPATH') && is_writable(ABSPATH)) {
            @file_put_contents(ABSPATH . '.maintenance', '<?php $upgrading = ' . time() . ';');
        }
    }

    protected function clear_maintenance(): void {
        if (defined('ABSPATH') && file_exists(ABSPATH . '.maintenance')) {
            @unlink(ABSPATH . '.maintenance');
        }
    }
}
