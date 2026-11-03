<?php
/**
 * K21 reconcile — DETECTION ONLY (directive K21 ITEMS 7a + 8, first stage).
 *
 * For every linked WordPress account and its active tracked MealsDB
 * client(s), evaluate each registry field and classify it:
 *
 *   equal    — every non-blank value agrees and nothing is blank
 *   blank    — every side is blank
 *   fill     — the non-blank values agree (and parse) and some side is blank:
 *              the sync WILL fill it once ITEM 7 lands
 *   conflict — two non-blank values differ, or the only non-blank value is
 *              unparseable and something is blank (an unparseable value is
 *              never a fill source)
 *   keep     — to_wp_only field whose WordPress value is populated (a
 *              3-character nickname is never touched, never a conflict)
 *   skip     — to_wp_only field whose linked clients disagree
 *
 * THIS STAGE WRITES NOTHING TO CLIENT DATA, in either system. Its only writes
 * are to meals_sync_conflicts, plus one summary event per run with per-field
 * counts — the numbers the operator reviews before any K21 write path is
 * switched on. The legacy WP -> MealsDB nightly (WP wins on the 17 k13 fields)
 * still runs after this, unchanged, so a conflict detected on one of those
 * fields is overwritten by it and closes as auto_resolved on the next run.
 * That is accurate: the legacy nightly resolved it in WordPress's favour.
 *
 * Accounts are reconciled as a SET (K21 ITEM 7a): WordPress plus every active
 * linked client. Staff accounts (meals_staff links and administrators) are
 * excluded — notably wp_user_id 1, the site admin, linked to test client 1.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

final class MealsDB_Sync_Reconciler {

    public const O_EQUAL    = 'equal';
    public const O_BLANK    = 'blank';
    public const O_FILL     = 'fill';
    public const O_CONFLICT = 'conflict';
    public const O_KEEP     = 'keep';
    public const O_SKIP     = 'skip';

    /** Accounts per batch. Two SELECTs + two cache primes per batch. */
    private const ACCOUNT_BATCH = 200;

    /** Caps on per-run diagnostic lists written into event context. */
    private const MAX_LISTED = 50;

    private const TRACKED_TYPES_SQL = "client_type IN ('SDNB', 'Veteran', 'Private')";

    // ------------------------------------------------------------------
    // Pure evaluation
    // ------------------------------------------------------------------

    /**
     * Classify one field for one account. Pure.
     *
     * @param string                         $column       Registry column.
     * @param string                         $wp_value     Raw WordPress value ('' when absent:
     *                                                     for MealsDB -> WP an absent key is blank).
     * @param array<int, string|null>        $meals_values client_id => raw value, every active linked client.
     * @return array{outcome: string, fill_wp: bool, fill_clients: int[], unrecognised: string[]}
     */
    public static function evaluate_field(string $column, string $wp_value, array $meals_values): array {
        $field = MealsDB_Sync_Registry::get($column);
        if ($field === null) {
            throw new InvalidArgumentException('Not a synced field: ' . $column);
        }
        $result = ['outcome' => self::O_EQUAL, 'fill_wp' => false, 'fill_clients' => [], 'unrecognised' => []];

        if ($field['direction'] === MealsDB_Sync_Registry::TO_WP_ONLY) {
            return self::evaluate_to_wp_only($column, $wp_value, $meals_values, $result);
        }

        $cmp = $field['comparator'];

        $nonblank      = [];
        $blank_clients = [];
        $wp_blank      = MealsDB_Sync_Registry::is_blank($column, 'wp', $wp_value);
        if (!$wp_blank) {
            $nonblank[] = $wp_value;
        }
        foreach ($meals_values as $client_id => $value) {
            if (MealsDB_Sync_Registry::is_blank($column, 'meals', (string) $value)) {
                $blank_clients[] = (int) $client_id;
            } else {
                $nonblank[] = (string) $value;
            }
        }

        foreach ($nonblank as $value) {
            if (MealsDB_Sync_Comparator::is_unrecognised($cmp, $value)) {
                $result['unrecognised'][] = $value;
            }
        }

        if ($nonblank === []) {
            $result['outcome'] = self::O_BLANK;
            return $result;
        }

        $reference = $nonblank[0];
        foreach ($nonblank as $value) {
            if (!MealsDB_Sync_Comparator::equals($cmp, $reference, $value)) {
                $result['outcome'] = self::O_CONFLICT;
                return $result;
            }
        }

        if (!$wp_blank && $blank_clients === []) {
            $result['outcome'] = self::O_EQUAL;
            return $result;
        }

        // Some side is blank and every non-blank value agrees. Only a
        // well-formed value may fill (K21 ITEM 2): agreed-upon garbage such as
        // commence_date '202409-02-03' facing a blank is a conflict.
        if (!MealsDB_Sync_Comparator::parses($cmp, $reference)) {
            $result['outcome'] = self::O_CONFLICT;
            return $result;
        }

        $result['outcome']      = self::O_FILL;
        $result['fill_wp']      = $wp_blank;
        $result['fill_clients'] = $blank_clients;
        return $result;
    }

    /**
     * ITEM 5: delivery_initials -> nickname. Never a conflict: an existing
     * 3-character nickname is left alone even when it differs.
     *
     * @param array<int, string|null> $meals_values
     * @param array<string, mixed>    $result
     * @return array{outcome: string, fill_wp: bool, fill_clients: int[], unrecognised: string[]}
     */
    private static function evaluate_to_wp_only(string $column, string $wp_value, array $meals_values, array $result): array {
        $initials = [];
        foreach ($meals_values as $value) {
            if (!MealsDB_Sync_Registry::is_blank($column, 'meals', (string) $value)) {
                $initials[] = trim((string) $value);
            }
        }
        if ($initials === []) {
            $result['outcome'] = self::O_BLANK;
            return $result;
        }
        if (count(array_unique(array_map('strtoupper', $initials))) > 1) {
            // Linked clients carry different initials: nothing to push.
            $result['outcome'] = self::O_SKIP;
            return $result;
        }
        if (MealsDB_Sync_Registry::is_blank($column, 'wp', $wp_value)) {
            $result['outcome'] = self::O_FILL;
            $result['fill_wp'] = true;
            return $result;
        }
        $result['outcome'] = self::O_KEEP;
        return $result;
    }

    // ------------------------------------------------------------------
    // Detection run
    // ------------------------------------------------------------------

    /**
     * Walk every tracked account, evaluate every registry field, and record
     * conflicts. Writes nothing to meals_clients or WordPress. Never throws.
     *
     * @return array<string, mixed> Run stats (also written to the event log).
     */
    public static function run_detection(): array {
        $log_id = class_exists('MealsDB_Job_Logger') ? MealsDB_Job_Logger::start('sync_reconcile_detect') : 0;
        $stats  = self::empty_stats();

        try {
            global $wpdb;
            $now   = gmdate('Y-m-d H:i:s');
            $store = new MealsDB_Sync_Conflicts($wpdb);

            $live = $store->load_live();
            if ($live === null) {
                // Can't read the store (table missing before the schema
                // upgrade, or a DB error). Recording without knowing what is
                // already open would duplicate rows — abort.
                return self::fail_run($log_id, $stats, 'Conflict store unreadable; detection skipped.');
            }

            $query = new MealsDB_Sync_Query();
            // Fail CLOSED: without the staff list we cannot keep staff
            // accounts (wp_user_id 1 among them) out of the sync.
            $staff = $query->get_staff_wordpress_ids();
            if (!is_array($staff)) {
                return self::fail_run($log_id, $stats, 'Staff account lookup failed; detection skipped.');
            }
            $legacy_ignored = $query->get_ignored_conflicts();
            $legacy_ignored = is_array($legacy_ignored) ? $legacy_ignored : [];

            $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));

            $evaluated = [];
            $cursor    = 0;

            while (true) {
                $account_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT wp_user_id FROM `{$clients_table}`
                      WHERE " . self::TRACKED_TYPES_SQL . " AND active = 1 AND wp_user_id > %d
                      ORDER BY wp_user_id ASC LIMIT %d",
                    $cursor,
                    self::ACCOUNT_BATCH
                ));
                if (!is_array($account_ids)) {
                    return self::fail_run($log_id, $stats, 'Account query failed: ' . (string) $wpdb->last_error);
                }
                if ($account_ids === []) {
                    break;
                }
                $account_ids = array_map('intval', $account_ids);
                $cursor      = max($account_ids);

                $by_account = self::fetch_clients($account_ids);
                if ($by_account === null) {
                    return self::fail_run($log_id, $stats, 'Client query failed: ' . (string) $wpdb->last_error);
                }

                if (function_exists('cache_users')) {
                    cache_users($account_ids);
                }
                update_meta_cache('user', $account_ids);

                foreach ($by_account as $wp_user_id => $clients) {
                    if (isset($staff[$wp_user_id])) {
                        $stats['accounts_staff_skipped']++;
                        continue;
                    }
                    $user = get_userdata($wp_user_id);
                    if (!$user instanceof WP_User) {
                        $stats['accounts_missing_user']++;
                        continue;
                    }
                    if (in_array('administrator', (array) $user->roles, true)) {
                        $stats['accounts_staff_skipped']++;
                        continue;
                    }

                    $evaluated[$wp_user_id] = true;
                    self::detect_account($user, $clients, $store, $live, $legacy_ignored, $now, $stats);
                }

                if (count($account_ids) < self::ACCOUNT_BATCH) {
                    break;
                }
            }

            // Sweep: a live row for an account this run did not evaluate
            // (unlinked, deactivated, deleted, now staff) describes records
            // that are no longer a tracked pair.
            foreach ($live as $row) {
                if (!isset($evaluated[(int) $row['wp_user_id']])) {
                    if ($store->close($row, MealsDB_Sync_Conflicts::STATUS_SUPERSEDED, 'relinked', $now)) {
                        $stats['conflicts']['superseded']++;
                    } else {
                        $stats['store_errors']++;
                    }
                }
            }

            self::finish_run($log_id, $stats);
        } catch (\Throwable $e) {
            return self::fail_run($log_id, $stats, $e->getMessage());
        }

        return $stats;
    }

    /**
     * Evaluate one account and reconcile its conflict rows. Entries of $live
     * that this account accounts for are removed, so the caller's sweep only
     * sees rows for accounts that were not evaluated.
     *
     * @param array<int, array<string, mixed>>    $clients
     * @param array<string, array<string, mixed>> $live
     * @param array<string, bool>                 $legacy_ignored
     * @param array<string, mixed>                $stats
     */
    private static function detect_account(WP_User $user, array $clients, MealsDB_Sync_Conflicts $store, array &$live, array $legacy_ignored, string $now, array &$stats): void {
        $wp_user_id = (int) $user->ID;
        $stats['accounts_evaluated']++;
        if (count($clients) > 1) {
            $stats['accounts_multi_client']++;
        }

        foreach (MealsDB_Sync_Registry::fields() as $column => $field) {
            $wp_value = self::read_wp_value($user, $field['wp']);

            $meals_values = self::meals_values_for($clients, $column);

            if (isset($field['wp']['consistency'])) {
                $other = self::read_wp_value($user, ['type' => 'meta', 'key' => $field['wp']['consistency']]);
                if (!MealsDB_Sync_Comparator::equals($field['comparator'], $wp_value, $other)) {
                    // ITEM 4: WordPress-internal inconsistency. Compare on the
                    // read key only; log, never a MealsDB conflict.
                    $stats['wp_inconsistent'][$column] = ($stats['wp_inconsistent'][$column] ?? 0) + 1;
                    if (count($stats['wp_inconsistent_users']) < self::MAX_LISTED) {
                        $stats['wp_inconsistent_users'][] = $wp_user_id;
                    }
                }
            }

            $eval = self::evaluate_field($column, $wp_value, $meals_values);

            foreach ($eval['unrecognised'] as $value) {
                $key = $column . ': ' . $value;
                if (isset($stats['unrecognised'][$key]) || count($stats['unrecognised']) < self::MAX_LISTED) {
                    $stats['unrecognised'][$key] = ($stats['unrecognised'][$key] ?? 0) + 1;
                }
            }

            $tally = &$stats['fields'][$column];
            $tally[$eval['outcome']] = ($tally[$eval['outcome']] ?? 0) + 1;
            if ($eval['fill_wp']) {
                $tally['fill_wp'] = ($tally['fill_wp'] ?? 0) + 1;
            }
            if ($eval['fill_clients'] !== []) {
                $tally['fill_meals'] = ($tally['fill_meals'] ?? 0) + count($eval['fill_clients']);
            }
            unset($tally);

            $key      = MealsDB_Sync_Conflicts::dedup_key($wp_user_id, $column);
            $existing = $live[$key] ?? null;
            unset($live[$key]);

            if ($eval['outcome'] === self::O_CONFLICT) {
                $ignored = self::legacy_ignored($column, $wp_value, $meals_values, $legacy_ignored);
                $r = $store->record($existing, $wp_user_id, $column, $wp_value, $meals_values, $now, $ignored);
                if ($r === MealsDB_Sync_Conflicts::R_ERROR) {
                    $stats['store_errors']++;
                } else {
                    $stats['conflicts'][$r] = ($stats['conflicts'][$r] ?? 0) + 1;
                }
            } elseif ($existing !== null) {
                // No longer a conflict. If the linked clients changed, the
                // row described different records: superseded (relinked), not
                // auto_resolved — the values did not converge, the set did.
                $relinked = !MealsDB_Sync_Conflicts::same_client_set($existing, $meals_values);
                $status   = $relinked ? MealsDB_Sync_Conflicts::STATUS_SUPERSEDED : MealsDB_Sync_Conflicts::STATUS_AUTO_RESOLVED;
                if ($store->close($existing, $status, $relinked ? 'relinked' : 'auto', $now)) {
                    $stats['conflicts'][$relinked ? 'superseded' : 'auto_resolved']++;
                } else {
                    $stats['store_errors']++;
                }
            }
        }
    }

    /**
     * Does a legacy meals_ignored_conflicts rule cover this conflict? Those
     * rules are value-pair keys built by the old dashboard for a single
     * MealsDB value vs the WP value, under the dashboard's field names
     * (client_phone_1 was 'phone_primary'). Only a conflict with exactly one
     * distinct MealsDB value can match.
     *
     * @param array<int, string|null> $meals_values
     * @param array<string, bool>     $legacy_ignored
     */
    private static function legacy_ignored(string $column, string $wp_value, array $meals_values, array $legacy_ignored): bool {
        if ($legacy_ignored === []) {
            return false;
        }
        $distinct = array_values(array_unique(array_map('strval', $meals_values)));
        if (count($distinct) !== 1) {
            return false;
        }
        $names = $column === 'client_phone_1' ? ['phone_primary', 'client_phone_1'] : [$column];
        $source = MealsDB_Sync::sanitize_ignore_value($distinct[0]);
        $target = MealsDB_Sync::sanitize_ignore_value($wp_value);
        foreach ($names as $name) {
            $key = MealsDB_Sync::build_ignore_key(MealsDB_Sync::sanitize_ignore_value($name), $source, $target);
            if (isset($legacy_ignored[$key])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Active tracked clients for the given accounts, grouped by wp_user_id
     * and ordered by client_id. The ONE client read both the nightly
     * detection and the conflict view use, so the values a page renders
     * (and hashes for ITEM 10's stale-page guard) are read exactly the way
     * detection read them. Includes the derived columns for display.
     * Returns null on a query error.
     *
     * @param int[] $account_ids
     * @return array<int, array<int, array<string, mixed>>>|null
     */
    public static function fetch_clients(array $account_ids): ?array {
        global $wpdb;
        $account_ids = array_values(array_filter(array_map('intval', $account_ids), static fn (int $id): bool => $id > 0));
        if ($account_ids === []) {
            return [];
        }
        $table   = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
        $columns = array_unique(array_merge(
            ['client_id', 'wp_user_id'],
            array_keys(MealsDB_Sync_Registry::fields()),
            ['delivery_area_zone', 'delivery_day']
        ));
        $select = implode(', ', array_map(static function (string $c): string {
            return '`' . str_replace('`', '``', $c) . '`';
        }, $columns));
        $placeholders = implode(', ', array_fill(0, count($account_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$select} FROM `{$table}`
              WHERE wp_user_id IN ({$placeholders}) AND " . self::TRACKED_TYPES_SQL . " AND active = 1
              ORDER BY wp_user_id ASC, client_id ASC",
            ...$account_ids
        ), ARRAY_A);
        if (!is_array($rows)) {
            return null;
        }
        $by_account = [];
        foreach ($rows as $row) {
            $by_account[(int) $row['wp_user_id']][] = $row;
        }
        return $by_account;
    }

    /**
     * client_id => raw value of one column across an account's clients.
     *
     * @param array<int, array<string, mixed>> $clients
     * @return array<int, string>
     */
    public static function meals_values_for(array $clients, string $column): array {
        $values = [];
        foreach ($clients as $client) {
            $values[(int) $client['client_id']] = isset($client[$column]) ? (string) $client[$column] : '';
        }
        return $values;
    }

    /**
     * Raw WordPress value for a registry descriptor ('' when absent).
     *
     * @param array{type: string, key: string} $descriptor
     */
    public static function read_wp_value(WP_User $user, array $descriptor): string {
        if ($descriptor['type'] === 'core') {
            $key = $descriptor['key'];
            return isset($user->$key) ? (string) $user->$key : '';
        }
        $value = get_user_meta((int) $user->ID, $descriptor['key'], true);
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return array<string, mixed> */
    private static function empty_stats(): array {
        $fields = [];
        foreach (array_keys(MealsDB_Sync_Registry::fields()) as $column) {
            $fields[$column] = [];
        }
        return [
            'accounts_evaluated'     => 0,
            'accounts_multi_client'  => 0,
            'accounts_staff_skipped' => 0,
            'accounts_missing_user'  => 0,
            'fields'                 => $fields,
            'conflicts'              => ['auto_resolved' => 0, 'superseded' => 0],
            'store_errors'           => 0,
            'unrecognised'           => [],
            'wp_inconsistent'        => [],
            'wp_inconsistent_users'  => [],
        ];
    }

    /** @param array<string, mixed> $stats */
    private static function finish_run(int $log_id, array $stats): void {
        $degraded = $stats['store_errors'] > 0;

        MealsDB_Event_Log::record([
            'severity'  => $degraded ? 'warning' : 'info',
            'category'  => 'sync',
            'subsystem' => 'sync_reconcile',
            'event'     => 'sync.reconcile_detected',
            'outcome'   => $degraded ? MealsDB_Event_Log::OUTCOME_DEGRADED : MealsDB_Event_Log::OUTCOME_SUCCEEDED,
            'message'   => sprintf(
                'K21 detection: %d accounts, %d conflict rows touched, %d store errors. No client data written.',
                $stats['accounts_evaluated'],
                array_sum($stats['conflicts']),
                $stats['store_errors']
            ),
            'context'   => [
                'accounts_evaluated'     => $stats['accounts_evaluated'],
                'accounts_multi_client'  => $stats['accounts_multi_client'],
                'accounts_staff_skipped' => $stats['accounts_staff_skipped'],
                'accounts_missing_user'  => $stats['accounts_missing_user'],
                'fields'                 => $stats['fields'],
                'conflicts'              => $stats['conflicts'],
                'store_errors'           => $stats['store_errors'],
            ],
        ]);

        if ($stats['unrecognised'] !== []) {
            // ITEM 3: a new Enzebra group must surface, not silently default.
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'sync',
                'subsystem' => 'sync_reconcile',
                'event'     => 'sync.value_unrecognised',
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => sprintf('%d unrecognised value(s) in a parsed field; each is held as a conflict, never overwritten.', count($stats['unrecognised'])),
                'context'   => ['values' => $stats['unrecognised']],
            ]);
        }

        if ($stats['wp_inconsistent'] !== []) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'sync',
                'subsystem' => 'sync_reconcile',
                'event'     => 'sync.wp_internal_inconsistency',
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => 'WordPress shipping_address_2 and billing_address_2 disagree; compared on shipping only.',
                'context'   => ['counts' => $stats['wp_inconsistent'], 'wp_user_ids' => $stats['wp_inconsistent_users']],
            ]);
        }

        if ($log_id > 0) {
            $job_stats = [
                'records_processed' => $stats['accounts_evaluated'],
                'records_updated'   => array_sum($stats['conflicts']),
                'records_skipped'   => $stats['accounts_staff_skipped'] + $stats['accounts_missing_user'],
                'records_errored'   => $stats['store_errors'],
            ];
            if ($degraded) {
                MealsDB_Event_Log::finish_job($log_id, $job_stats, MealsDB_Event_Log::OUTCOME_DEGRADED);
            } else {
                MealsDB_Job_Logger::finish($log_id, $job_stats);
            }
        }
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private static function fail_run(int $log_id, array $stats, string $message): array {
        MealsDB_Logger::error('[MealsDB Sync Reconcile] detection failed: ' . $message);
        MealsDB_Event_Log::record([
            'severity'  => 'error',
            'category'  => 'sync',
            'subsystem' => 'sync_reconcile',
            'event'     => 'sync.reconcile_failed',
            'outcome'   => MealsDB_Event_Log::OUTCOME_FAILED,
            'message'   => $message,
        ]);
        if ($log_id > 0 && class_exists('MealsDB_Job_Logger')) {
            MealsDB_Job_Logger::fail($log_id, $message, ['records_processed' => $stats['accounts_evaluated']]);
        }
        $stats['failed'] = $message;
        return $stats;
    }
}
