<?php
/**
 * Nightly derived-value integrity pass (directive ITEM1-DERIVED).
 *
 * Scans active clients, recomputes their derived fields via
 * MealsDB_Derived_Value_Check (which uses the CANONICAL calculator), and for
 * each value that has drifted out of sync with its inputs:
 *
 *   - ALWAYS: emits a `degraded` event on the STR-LOG operational trunk
 *     (category='integrity', subsystem='derived_values') so a human can judge
 *     it. Flagging is always safe — it never overwrites anything.
 *
 *   - IF auto-correct is enabled for that field (per-field setting, default
 *     OFF): writes the recomputed value via the repository update path AND
 *     records the correction on the AUDIT log (a committed change to a client
 *     record -> audit log, per the STR-LOG boundary). Default flag-only because
 *     next_order_date / next_delivery_date are directly operator-editable, so a
 *     divergent stored value MIGHT be a deliberate manual override — blindly
 *     stomping it is the exact failure mode the blank-fill-only delivery_day
 *     backfill was built to avoid.
 *
 * RE-RUN SAFETY (MAJ-7 lesson): the pass is idempotent. Flag-only is naturally
 * idempotent; auto-correct converges — once a value is corrected it matches on
 * the next run and is a no-op. A best-effort transient lock (mirroring the task
 * spawn's Layer-2 guard) stops two overlapping passes doing redundant work.
 *
 * BOUNDED WORK: clients are scanned in batches with an overall per-run cap,
 * mirroring the retention prune's bounded-pass discipline. Only the handful of
 * non-encrypted columns the check needs are selected — no PII is decrypted.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Derived_Value_Audit {

    /** Cron hook. ~03:30 UTC — after the 03:00 allocation sync, before the
     *  04:00 daily report. */
    public const HOOK = 'mealsdb_derived_value_audit';

    /** Per-field auto-correct toggles live here. Default: every field OFF. */
    public const AUTOCORRECT_OPTION = 'mealsdb_derived_autocorrect';

    /** Best-effort overlap guard (MAJ-7 Layer-2 pattern). */
    private const LOCK_KEY = 'mealsdb_derived_audit_running';

    /** Rows per SELECT page. */
    private const BATCH_SIZE = 200;

    /** Hard cap on clients examined per run, so a runaway client count can't
     *  wedge the cron tick. 890 active clients today fit comfortably in one
     *  nightly pass; if the base ever grows past this the surplus is simply
     *  picked up the following night (the check is order-independent). */
    private const MAX_CLIENTS_PER_RUN = 5000;

    /**
     * Register the cron hook + schedule.
     */
    public static function init(): void {
        add_action(self::HOOK, [self::class, 'run']);

        if (function_exists('wp_next_scheduled') && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(strtotime('tomorrow 03:30:00'), 'daily', self::HOOK);
        }
    }

    /**
     * Resolve the per-field auto-correct toggles, normalised to 0/1 with every
     * in-scope field present (default OFF). A stored value for a field NOT in
     * MealsDB_Derived_Value_Check::FIELDS is ignored.
     *
     * @return array<string,int>
     */
    public static function autocorrect_settings(): array {
        $stored = function_exists('get_option') ? get_option(self::AUTOCORRECT_OPTION, []) : [];
        if (!is_array($stored)) {
            $stored = [];
        }
        $out = [];
        foreach (MealsDB_Derived_Value_Check::FIELDS as $field) {
            $out[$field] = empty($stored[$field]) ? 0 : 1;
        }
        return $out;
    }

    /**
     * The nightly pass. Flags drift (always) and auto-corrects (per-field
     * opt-in). Never throws — it's a cron handler.
     */
    public static function run(): void {
        // Best-effort overlap guard. A manual re-trigger while the scheduled
        // pass runs would otherwise duplicate the (idempotent) work; surface an
        // unexpected overlap as a degraded trunk event instead.
        if (function_exists('get_transient') && get_transient(self::LOCK_KEY)) {
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'warning',
                    'category'  => 'integrity',
                    'subsystem' => 'derived_values',
                    'event'     => 'audit.overlap_skipped',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'   => 'Derived-value audit skipped: another pass is already running.',
                ]);
            }
            return;
        }
        // 15-minute TTL safety valve: a crashed pass can't wedge the lock
        // forever (the finally below clears it on the normal path).
        if (function_exists('set_transient') && defined('MINUTE_IN_SECONDS')) {
            set_transient(self::LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS);
        }

        $log_id = class_exists('MealsDB_Job_Logger')
            ? MealsDB_Job_Logger::start('derived_value_audit')
            : 0;

        $checked     = 0;
        $mismatches  = 0;
        $corrections = 0;

        try {
            $autocorrect   = self::autocorrect_settings();
            global $wpdb;
            $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

            $offset = 0;
            while ($checked < self::MAX_CLIENTS_PER_RUN) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT client_id, wp_user_id, ordering_frequency, delivery_frequency,
                            delivery_day, delivery_area_name, next_order_date,
                            next_delivery_date, last_delivery_date
                     FROM `{$clients_table}`
                     WHERE active = 1
                     ORDER BY client_id ASC
                     LIMIT %d OFFSET %d",
                    self::BATCH_SIZE,
                    $offset
                ), ARRAY_A);

                if (!is_array($rows) || empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $checked++;

                    // Inject the order-event base date (last_order_date is WP
                    // usermeta, not a column) so the check stays pure.
                    $row['last_order_date'] = self::read_user_meta_date(
                        (int) ($row['wp_user_id'] ?? 0),
                        'last_order_date'
                    );

                    foreach (MealsDB_Derived_Value_Check::check_client($row) as $m) {
                        $mismatches++;
                        self::flag_drift($row, $m);

                        if (!empty($autocorrect[$m['field']])
                            && self::apply_correction($wpdb, $clients_table, (int) ($row['client_id'] ?? 0), $m)) {
                            $corrections++;
                        }
                    }
                }

                if (count($rows) < self::BATCH_SIZE) {
                    break; // last (partial) page
                }
                $offset += self::BATCH_SIZE;
            }

            if ($log_id > 0) {
                MealsDB_Job_Logger::finish($log_id, [
                    'records_processed'   => $checked,
                    'records_updated'     => $corrections,
                    'clients_checked'     => $checked,
                    'mismatches_found'    => $mismatches,
                    'corrections_applied' => $corrections,
                ]);
            }
        } catch (\Throwable $e) {
            if ($log_id > 0) {
                MealsDB_Job_Logger::fail($log_id, $e->getMessage(), [
                    'records_processed' => $checked,
                    'mismatches_found'  => $mismatches,
                ]);
            }
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::error('[MealsDB Derived Value Audit] failed: ' . $e->getMessage());
            }
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'error',
                    'category'  => 'integrity',
                    'subsystem' => 'derived_values',
                    'event'     => 'audit.failed',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'   => $e->getMessage(),
                ]);
            }
            // Swallow — a cron handler must not propagate (Pattern 7).
        } finally {
            if (function_exists('delete_transient')) {
                delete_transient(self::LOCK_KEY);
            }
        }
    }

    /**
     * Emit the `degraded` trunk event for one mismatch. The checked fields
     * (dates, day names) are not PII, but the message/context still route
     * through the trunk's scrubber as usual.
     *
     * @param array<string,mixed> $row
     * @param array{field:string,stored:string,expected:string,reason:string} $m
     */
    private static function flag_drift(array $row, array $m): void {
        if (!class_exists('MealsDB_Event_Log')) {
            return;
        }
        MealsDB_Event_Log::record([
            'severity'    => 'warning',
            'category'    => 'integrity',
            'subsystem'   => 'derived_values',
            'event'       => 'client.' . $m['field'] . '.drift',
            'outcome'     => MealsDB_Event_Log::OUTCOME_DEGRADED,
            'message'     => sprintf(
                'Derived %s drift: stored "%s", expected "%s" (%s).',
                $m['field'],
                $m['stored'],
                $m['expected'],
                $m['reason']
            ),
            'entity_type' => 'client',
            'entity_id'   => (int) ($row['client_id'] ?? 0),
            'context'     => [
                'field'    => $m['field'],
                'stored'   => $m['stored'],
                'expected' => $m['expected'],
            ],
        ]);
    }

    /**
     * Write the recomputed value for one field (auto-correct path) and record
     * the change on the audit log. Returns true when a row was updated.
     *
     * @param array{field:string,stored:string,expected:string,reason:string} $m
     */
    private static function apply_correction($wpdb, string $clients_table, int $client_id, array $m): bool {
        if ($client_id <= 0) {
            return false;
        }
        $result = $wpdb->update(
            $clients_table,
            [$m['field'] => $m['expected']],
            ['client_id' => $client_id],
            ['%s'],
            ['%d']
        );
        if ($result === false) {
            return false;
        }
        // Committed change to a client record -> audit log (STR-LOG boundary).
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log(
                'derived_value_corrected',
                $client_id,
                $m['field'],
                $m['stored'],
                $m['expected']
            );
        }
        return true;
    }

    /**
     * Read a Y-m-d usermeta date, or null when absent/malformed. Mirrors
     * MealsDB_Migration_Consolidated::read_meta_date so both the backfill and
     * the audit resolve the order base the same way.
     */
    private static function read_user_meta_date(int $wp_user_id, string $meta_key): ?string {
        if ($wp_user_id <= 0 || !function_exists('get_user_meta')) {
            return null;
        }
        $raw = get_user_meta($wp_user_id, $meta_key, true);
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        return $raw;
    }
}
