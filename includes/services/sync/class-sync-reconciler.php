<?php
/**
 * K21 nightly reconcile (directive K21 ITEMS 7, 7a, 8).
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
 * Two entry points:
 *   run_detection()  — classify and record conflicts only. Writes nothing to
 *                      client data in either system (a dry run).
 *   run_reconcile()  — the nightly (MealsDB_Sync::run_nightly_sync): the same
 *                      pass 1, then pass 2 FILLS BLANKS in both directions.
 *                      It never overwrites a populated value; conflicts are
 *                      left for the operator (MealsDB_Sync_Resolver).
 *
 * Pass 2 re-reads each account fresh before writing, so a value entered
 * between the passes is not overwritten. WordPress writes go through
 * MealsDB_Sync_Resolver::write_wp_field (ITEM 11's email guards); MealsDB
 * writes through MealsDB_Sync_Mutate::update_meals_client (encrypt, fail
 * closed); every write is audit-logged; derived fields are filled only when
 * blank (ITEM 6); shadow mode suppresses and counts the WordPress writes
 * (ITEM 12). delivery_initials is never written; only nickname is pushed
 * (ITEM 5).
 *
 * No runtime breakers (operator decision 2026-11-03). The K13 mass-blank
 * breaker existed because the old sync OVERWROTE: a bad mapping could blank
 * hundreds of clients in one run. A fill-blank sync never overwrites a
 * populated value, so there is nothing for a breaker to stop; it could only
 * refuse a correct run. The guarantee is enforced before release instead, by
 * the no-overwrite invariant (tests/test-sync-invariant-k21.php, K21 Test
 * 32). A meta key absent for EVERY evaluated user is still reported
 * (sync.meta_key_missing, K13 ITEM 2) as an alarm only — it usually means a
 * mistyped registry key — but nothing is skipped.
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

    /** Job names: the apply run keeps the legacy nightly's name so the daily report's monitoring carries over. */
    public const JOB_DETECT = 'sync_reconcile_detect';
    public const JOB_APPLY  = 'wp_to_mealsdb_sync';

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
     * Classify and record conflicts only; writes nothing to client data.
     * Never throws.
     *
     * @return array<string, mixed> Run stats (also written to the event log).
     */
    public static function run_detection(): array {
        return self::run(false);
    }

    /**
     * The nightly: classify and record conflicts, then fill blanks in both
     * directions. Never throws.
     *
     * @return array<string, mixed>
     */
    public static function run_reconcile(): array {
        return self::run(true);
    }

    /** @return array<string, mixed> */
    private static function run(bool $apply): array {
        $log_id = class_exists('MealsDB_Job_Logger') ? MealsDB_Job_Logger::start($apply ? self::JOB_APPLY : self::JOB_DETECT) : 0;
        $stats  = self::empty_stats();
        $stats['mode'] = $apply ? 'apply' : 'detect';
        // Pass-1 plan: which accounts have fills, and meta-key presence.
        $plan = ['accounts' => [], 'present' => [], 'meta_fields' => []];

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
                    self::detect_account($user, $clients, $store, $live, $legacy_ignored, $now, $stats, $plan);
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

            if ($apply) {
                self::meta_key_alarm($plan, $stats);
                self::apply_pass($plan, $staff, $stats);
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
    private static function detect_account(WP_User $user, array $clients, MealsDB_Sync_Conflicts $store, array &$live, array $legacy_ignored, string $now, array &$stats, array &$plan): void {
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

            // Plan inputs for the apply pass (unused by a detection-only run).
            if ($field['wp']['type'] === 'meta') {
                $plan['meta_fields'][$column] = true;
                if (metadata_exists('user', $wp_user_id, (string) $field['wp']['key'])) {
                    $plan['present'][$column] = ($plan['present'][$column] ?? 0) + 1;
                }
            }
            if ($eval['outcome'] === self::O_FILL) {
                $plan['accounts'][$wp_user_id] = true;
            }

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
     * K21 ITEM 13: reconcile ONE account in response to a WordPress save
     * (profile_update, woocommerce_customer_save_address,
     * woocommerce_created_customer). Same registry and rules as the nightly:
     * fill a blank MealsDB value, leave equal fields alone, record a conflict
     * when both sides are populated and differ, close conflicts that have
     * converged, and fill derived fields only where blank. A WordPress
     * profile edit therefore can no longer overwrite a populated MealsDB
     * value, and never writes delivery_initials.
     *
     * Deliberately one-directional: the real-time path NEVER writes
     * WordPress. Filling WordPress blanks stays with the nightly, and a save
     * should not write back into the record being saved. Never throws.
     *
     * @return array{outcome: string, reason?: string, stats?: array<string, mixed>}
     */
    public static function reconcile_account(int $wp_user_id): array {
        if ($wp_user_id <= 0) {
            return ['outcome' => 'skipped', 'reason' => 'invalid_user'];
        }
        try {
            global $wpdb;
            $user = get_userdata($wp_user_id);
            if (!$user instanceof WP_User) {
                return ['outcome' => 'skipped', 'reason' => 'no_userdata'];
            }
            if (in_array('administrator', (array) $user->roles, true)) {
                return ['outcome' => 'skipped', 'reason' => 'staff'];
            }
            $query = new MealsDB_Sync_Query();
            $staff = $query->get_staff_wordpress_ids();
            if (!is_array($staff)) {
                // Fail CLOSED, as the nightly does.
                return ['outcome' => 'skipped', 'reason' => 'staff_lookup_failed'];
            }
            if (isset($staff[$wp_user_id])) {
                return ['outcome' => 'skipped', 'reason' => 'staff'];
            }

            $by_account = self::fetch_clients([$wp_user_id]);
            if ($by_account === null) {
                return ['outcome' => 'errored', 'reason' => 'client_query_failed'];
            }
            $clients = $by_account[$wp_user_id] ?? [];
            if ($clients === []) {
                return ['outcome' => 'skipped', 'reason' => 'not_tracked_client'];
            }

            $store    = new MealsDB_Sync_Conflicts($wpdb);
            $by_field = $store->live_for_accounts([$wp_user_id]);
            if ($by_field === null) {
                return ['outcome' => 'errored', 'reason' => 'conflict_store_unreadable'];
            }
            $live = [];
            foreach ($by_field[$wp_user_id] ?? [] as $row) {
                $live[MealsDB_Sync_Conflicts::dedup_key($wp_user_id, (string) $row['field'])] = $row;
            }
            $legacy_ignored = $query->get_ignored_conflicts();
            $legacy_ignored = is_array($legacy_ignored) ? $legacy_ignored : [];

            $stats = self::empty_stats();
            $plan  = ['accounts' => [], 'present' => [], 'meta_fields' => []];
            self::detect_account($user, $clients, $store, $live, $legacy_ignored, gmdate('Y-m-d H:i:s'), $stats, $plan);

            if (isset($plan['accounts'][$wp_user_id])) {
                $mutate = new MealsDB_Sync_Mutate();
                MealsDB_Sync::without_sync_hooks(static function () use ($user, $clients, $mutate, &$stats): void {
                    self::apply_account($user, $clients, false, $mutate, $stats, false, 'realtime');
                });
            }

            $stats['applied'] = array_filter($stats['applied']);
            $failed = $stats['store_errors'] > 0 || $stats['fill_failures'] > 0;
            return ['outcome' => $failed ? 'errored' : 'processed', 'stats' => [
                // Recorded as hook context: re-keyed so the scrubber keeps the counts.
                'applied'       => self::by_field_key($stats['applied']),
                'conflicts'     => array_filter($stats['conflicts']),
                'fill_failures' => $stats['fill_failures'],
                'store_errors'  => $stats['store_errors'],
            ]];
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Sync Reconcile] account ' . $wp_user_id . ' failed: ' . $e->getMessage());
            return ['outcome' => 'errored', 'reason' => 'exception: ' . get_class($e)];
        }
    }

    /**
     * K13 ITEM 2 alarm, kept as an alarm only: a meta key absent for EVERY
     * evaluated user usually means a mistyped registry key. Reported once per
     * field per run; the run proceeds and fills as usual (a fill into an
     * absent key creates it and overwrites nothing).
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $stats
     */
    private static function meta_key_alarm(array $plan, array $stats): void {
        if ((int) $stats['accounts_evaluated'] === 0) {
            return;
        }
        foreach (array_keys($plan['meta_fields']) as $column) {
            if (($plan['present'][$column] ?? 0) > 0) {
                continue;
            }
            $key = (string) (MealsDB_Sync_Registry::get($column)['wp']['key'] ?? '');
            MealsDB_Event_Log::record([
                'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync_reconcile',
                'event' => 'sync.meta_key_missing', 'outcome' => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message' => sprintf('%s -> %s is absent for every tracked user (check the registry key). Fills proceed.', $column, $key),
                'context' => ['field' => 'field:' . $column, 'meta_key' => $key],
            ]);
        }
    }

    /**
     * Pass 2: fill blanks for every account the plan marked. Each account is
     * re-read and re-evaluated first, so the write acts on current values.
     *
     * @param array<string, mixed> $plan
     * @param array<int, bool>     $staff
     * @param array<string, mixed> $stats
     */
    private static function apply_pass(array $plan, array $staff, array &$stats): void {
        $ids    = array_keys($plan['accounts']);
        $shadow = MealsDB_Shadow_Mode::is_enabled();
        $mutate = new MealsDB_Sync_Mutate();

        foreach (array_chunk($ids, self::ACCOUNT_BATCH) as $batch) {
            $by_account = self::fetch_clients($batch);
            if ($by_account === null) {
                $stats['fill_failures']++;
                continue;
            }
            if (function_exists('cache_users')) {
                cache_users($batch);
            }
            update_meta_cache('user', $batch);

            foreach ($by_account as $wp_user_id => $clients) {
                $user = get_userdata($wp_user_id);
                if (isset($staff[$wp_user_id]) || !$user instanceof WP_User
                    || in_array('administrator', (array) $user->roles, true)) {
                    continue;
                }
                MealsDB_Sync::without_sync_hooks(static function () use ($user, $clients, $plan, $shadow, $mutate, &$stats): void {
                    self::apply_account($user, $clients, $shadow, $mutate, $stats);
                });
            }
        }
    }

    /**
     * Fill every blank side of one account. Writes only where the fresh
     * evaluation says "fill"; a conflict or anything else is left alone.
     *
     * @param array<int, array<string, mixed>> $clients
     * @param array<string, mixed>             $stats
     * @param bool   $fill_wp      false on the real-time path (ITEM 13): WordPress
     *                             blanks are left for the nightly and counted.
     * @param string $source_label audit source: nightly | realtime
     */
    private static function apply_account(WP_User $user, array $clients, bool $shadow, MealsDB_Sync_Mutate $mutate, array &$stats, bool $fill_wp = true, string $source_label = 'nightly'): void {
        $wp_user_id = (int) $user->ID;

        foreach (MealsDB_Sync_Registry::fields() as $column => $field) {
            $wp    = self::read_wp_value($user, $field['wp']);
            $meals = self::meals_values_for($clients, $column);
            $eval  = self::evaluate_field($column, $wp, $meals);
            if ($eval['outcome'] !== self::O_FILL) {
                continue;
            }
            $cmp = $field['comparator'];

            // Source: a MealsDB value when one exists (keeps MealsDB's own
            // formatting for sibling fills), else WordPress. evaluate_field
            // only says "fill" when every non-blank value agrees and parses.
            $source = null;
            foreach ($meals as $value) {
                if (!MealsDB_Sync_Registry::is_blank($column, 'meals', (string) $value)) {
                    $source = (string) $value;
                    break;
                }
            }
            if ($source === null) {
                $source = $wp;
            }
            if (trim($source) === '') {
                // Unreachable by construction; refuse rather than blank.
                $stats['fill_failures']++;
                continue;
            }

            $tally = &$stats['applied'][$column];

            if ($eval['fill_wp']) {
                if (!$fill_wp) {
                    $tally['wp_deferred_to_nightly'] = ($tally['wp_deferred_to_nightly'] ?? 0) + 1;
                } elseif ($shadow) {
                    // ITEM 12: counted, reported once per run, never written.
                    $stats['shadow_suppressed'][$column] = ($stats['shadow_suppressed'][$column] ?? 0) + 1;
                } else {
                    $value = $field['direction'] === MealsDB_Sync_Registry::TO_WP_ONLY
                        ? trim($source)
                        : MealsDB_Sync_Resolver::value_for_wp($column, $cmp, $source);
                    $written = MealsDB_Sync_Resolver::write_wp_field($user, $field, $column, $value, 'sync_fill_wp', 'nightly', true);
                    if (is_wp_error($written)) {
                        // e.g. a user_email collision: report, move on.
                        $stats['fill_failures']++;
                        MealsDB_Event_Log::record([
                            'severity' => 'warning', 'category' => 'sync', 'subsystem' => 'sync_fill',
                            'event' => 'sync.fill_failed', 'outcome' => MealsDB_Event_Log::OUTCOME_DEGRADED,
                            'message' => sprintf('WordPress fill of %s for user %d failed: %s', $column, $wp_user_id, $written->get_error_message()),
                            'entity_type' => 'user', 'entity_id' => $wp_user_id,
                            'context' => ['field' => $column],
                        ]);
                    } else {
                        $tally['wp'] = ($tally['wp'] ?? 0) + 1;
                    }
                }
            }

            $filled = [];
            foreach ($eval['fill_clients'] as $client_id) {
                $value = MealsDB_Sync_Resolver::value_for_meals($cmp, $source);
                $old   = (string) ($meals[$client_id] ?? '');
                if ($mutate->update_meals_client($client_id, [$column => $value])) {
                    MealsDB_Logger::log('sync_fill_client', $client_id, $column, $old, $value, $source_label);
                    $tally['meals'] = ($tally['meals'] ?? 0) + 1;
                    $filled[] = $client_id;
                } else {
                    $stats['fill_failures']++;
                }
            }
            unset($tally);

            if ($filled !== [] && $field['derived'] !== []) {
                // ITEM 6: the source changed on these clients; derived values
                // are filled only where blank.
                MealsDB_Sync_Resolver::recompute_derived($mutate, $wp_user_id, $column, $field['derived'], MealsDB_Sync_Resolver::DERIVED_FILL, $filled);
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

    /**
     * Re-key a per-field map for an event-log context as 'field:<column>'.
     *
     * MealsDB_Event_Log scrubs context by KEY: any key named like a
     * sensitive column (client_phone_1, client_email, street_name, the
     * alternate-contact fields — about ten of the 27 synced fields) has its
     * value replaced with '[redacted:non-scalar]'. Our per-field values are
     * plain counts, not PII, but keyed by the bare column they were redacted,
     * hiding exactly the numbers the operator reviews. The prefix keeps the
     * scrubber's key match from firing; the scrubber still sanitises every
     * string value as usual.
     *
     * @param array<string, mixed> $per_field
     * @return array<string, mixed>
     */
    public static function by_field_key(array $per_field): array {
        $out = [];
        foreach ($per_field as $column => $value) {
            $out['field:' . $column] = $value;
        }
        return $out;
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
            // Apply pass only.
            'applied'                => [],
            'fill_failures'          => 0,
            'shadow_suppressed'      => [],
        ];
    }

    /** @param array<string, mixed> $stats */
    private static function finish_run(int $log_id, array $stats): void {
        $stats['applied'] = array_filter($stats['applied']);
        $apply    = ($stats['mode'] ?? 'detect') === 'apply';
        $degraded = $stats['store_errors'] > 0 || $stats['fill_failures'] > 0;
        $writes   = 0;
        foreach ($stats['applied'] as $per_field) {
            $writes += (int) ($per_field['wp'] ?? 0) + (int) ($per_field['meals'] ?? 0);
        }

        MealsDB_Event_Log::record([
            'severity'  => $degraded ? 'warning' : 'info',
            'category'  => 'sync',
            'subsystem' => 'sync_reconcile',
            'event'     => $apply ? 'sync.reconcile_applied' : 'sync.reconcile_detected',
            'outcome'   => $degraded ? MealsDB_Event_Log::OUTCOME_DEGRADED : MealsDB_Event_Log::OUTCOME_SUCCEEDED,
            'message'   => $apply
                ? sprintf(
                    'K21 nightly: %d accounts, %d blank values filled, %d fill failures, %d conflict rows touched, %d store errors.',
                    $stats['accounts_evaluated'], $writes, $stats['fill_failures'], array_sum($stats['conflicts']), $stats['store_errors']
                )
                : sprintf(
                    'K21 detection: %d accounts, %d conflict rows touched, %d store errors. No client data written.',
                    $stats['accounts_evaluated'], array_sum($stats['conflicts']), $stats['store_errors']
                ),
            'context'   => [
                'accounts_evaluated'     => $stats['accounts_evaluated'],
                'accounts_multi_client'  => $stats['accounts_multi_client'],
                'accounts_staff_skipped' => $stats['accounts_staff_skipped'],
                'accounts_missing_user'  => $stats['accounts_missing_user'],
                'fields'                 => self::by_field_key($stats['fields']),
                'conflicts'              => $stats['conflicts'],
                'store_errors'           => $stats['store_errors'],
                'applied'                => self::by_field_key($stats['applied']),
                'fill_failures'          => $stats['fill_failures'],
            ],
        ]);

        if ($stats['shadow_suppressed'] !== []) {
            // ITEM 12: shadow mode is reported, never silently obeyed.
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'sync',
                'subsystem' => 'sync_reconcile',
                'event'     => 'sync.shadow_suppressed',
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => sprintf('Shadow mode suppressed %d WordPress fills.', array_sum($stats['shadow_suppressed'])),
                'context'   => ['fields' => self::by_field_key($stats['shadow_suppressed'])],
            ]);
        }

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
                'context'   => ['counts' => self::by_field_key($stats['wp_inconsistent']), 'wp_user_ids' => $stats['wp_inconsistent_users']],
            ]);
        }

        if ($log_id > 0) {
            $job_stats = [
                'records_processed' => $stats['accounts_evaluated'],
                'records_updated'   => $apply ? $writes : array_sum($stats['conflicts']),
                'records_skipped'   => $stats['accounts_staff_skipped'] + $stats['accounts_missing_user'],
                'records_errored'   => $stats['store_errors'] + $stats['fill_failures'],
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
        MealsDB_Logger::error('[MealsDB Sync Reconcile] run failed: ' . $message);
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
