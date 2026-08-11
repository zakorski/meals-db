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
     * @param bool $online_only     When true (the auto-apply/version-bump path),
     *                              only attempt online DDL — never fall back to a
     *                              maintenance-mode COPY on a page load. A SAFE
     *                              change MySQL can't do INPLACE is DEFERRED to
     *                              the tool instead.
     * @return array{status:string, plan?:array, blockers?:array, error?:string}
     *         status: applied | needs_confirmation | blocked | deferred_online_unsupported | not_applied | error
     */
    public function run(array $mismatch, bool $confirmed_risky = false, bool $online_only = false): array {
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

        return $this->apply($plan, $online_only);
    }

    /**
     * Auto-apply every SAFE column mismatch that online DDL can perform, and
     * leave RISKY / online-unsupported ones for the operator tool. This is what
     * the version-bump path calls — it never COPYs or locks on a page load.
     *
     * Non-column mismatches (e.g. a PRIMARY KEY drift, whose 'actual' is a
     * string not an INFORMATION_SCHEMA row) are passed through untouched.
     *
     * @param array<int,array<string,mixed>> $mismatches Schema_Sync column_mismatches.
     * @return array{altered:array,remaining:array,errors:array}
     */
    public function apply_safe_batch(array $mismatches): array {
        $altered = [];
        $remaining = [];
        $errors = [];

        foreach ($mismatches as $mm) {
            if (!is_array($mm['actual'] ?? null) || ($mm['column'] ?? '') === 'PRIMARY KEY') {
                $remaining[] = $mm; // not a column MODIFY we plan
                continue;
            }
            $class = MealsDB_Schema_Alter_Planner::classify((string) ($mm['expected'] ?? ''), $mm['actual']);
            if ($class['tier'] !== MealsDB_Schema_Alter_Planner::TIER_SAFE) {
                $mm['risk'] = $class['tier']; // RISKY -> operator tool
                $remaining[] = $mm;
                continue;
            }

            $outcome = $this->run($mm, false, true); // SAFE, online-only
            $status  = (string) ($outcome['status'] ?? '');
            if ($status === 'applied') {
                $altered[] = ['table' => $mm['table'], 'column' => $mm['column'], 'reason' => $class['reason']];
            } elseif ($status === 'deferred_online_unsupported') {
                // SAFE but needs a COPY — defer to the tool (maintenance window),
                // NOT an error.
                $mm['risk'] = 'safe_needs_maintenance';
                $remaining[] = $mm;
            } else {
                // 'error' or the new 'not_applied' (ALTER ran but the column
                // still doesn't match) -- either way it did NOT apply, so it
                // stays in $errors and the schema version is not marked current.
                $errors[] = [
                    'table'  => $mm['table'],
                    'column' => $mm['column'],
                    'error'  => 'SAFE ALTER failed: ' . (string) ($outcome['error'] ?? $outcome['reason'] ?? ''),
                ];
                $remaining[] = $mm;
            }
        }

        return ['altered' => $altered, 'remaining' => $remaining, 'errors' => $errors];
    }

    /**
     * Operator-facing preview for ONE column change: classify + plan + run the
     * live pre-flight probes. `can_apply` is false when a probe finds rows that
     * would be lost, so the tool can disable the confirm control (H7 slice 4).
     *
     * @param array{table:string,column:string,expected:string,actual:array} $mismatch
     * @return array{table:string,column:string,tier:string,reason:string,alter_sql:string,preflight:array,can_apply:bool}
     */
    public function preview(array $mismatch): array {
        $plan = MealsDB_Schema_Alter_Planner::plan($mismatch);

        $preflight = [];
        $can_apply = true;
        foreach ($plan['preflight'] as $check) {
            $count  = empty($check['sql']) ? 0 : (int) $this->wpdb->get_var($check['sql']);
            $blocks = $count > 0;
            if ($blocks) {
                $can_apply = false;
            }
            $preflight[] = ['check' => (string) $check['check'], 'count' => $count, 'blocks' => $blocks];
        }

        return [
            'table'     => $plan['table'],
            'column'    => $plan['column'],
            'tier'      => $plan['tier'],
            'reason'    => $plan['reason'],
            'alter_sql' => $plan['alter_plain'],
            'preflight' => $preflight,
            'can_apply' => $can_apply,
        ];
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
    protected function apply(array $plan, bool $online_only = false): array {
        $ok = $this->wpdb->query($plan['alter_online']) !== false;

        if (!$ok) {
            if ($online_only) {
                // Auto-apply path: never COPY/lock on a page load. The change is
                // still SAFE, just not INPLACE-able — defer it to the tool, where
                // it runs under a maintenance window.
                return ['status' => 'deferred_online_unsupported', 'plan' => $plan];
            }
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

        // A query() that returns 0 (a no-op / silently-unconverted result) is
        // still !== false, so $ok is NOT proof the column now matches the
        // canonical definition. Re-read the live column and verify before
        // declaring success -- a conversion that did not actually run (e.g. a
        // LONGTEXT that stayed LONGTEXT instead of becoming JSON) is then
        // reported honestly as 'not_applied' rather than a false 'applied'.
        $definition = (string) ($plan['definition'] ?? '');
        if ($definition !== '') {
            $actual = MealsDB_Schema_Sync::fetch_existing_column($this->wpdb, (string) $plan['table'], (string) $plan['column']);
            if ($actual === null || !MealsDB_Schema_Sync::column_matches($definition, $actual)) {
                return [
                    'status' => 'not_applied',
                    'plan'   => $plan,
                    'reason' => 'ALTER ran but the column still does not match the canonical definition',
                ];
            }
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
