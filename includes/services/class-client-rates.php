<?php
/**
 * Per-client default rate creation (directive K22).
 *
 * WHY THIS EXISTS. Before K22 the only code that inserted a
 * meals_client_rates row was the migration's rate phase, which runs only when
 * an operator runs it. A client created through the MealsDB form or
 * private-intake promotion therefore had no rate row, and
 * MealsDB_WC_Order_Query::resolve_rate_for_order() fell back to the PROGRAM
 * rate — for SDNB the primary (main + side) tier, which is the wrong tier for
 * a main-only client. The client's correct rate was sitting in the legacy
 * `basic_cost` usermeta the whole time.
 *
 * This class is the ONE implementation of "give a client a default rate from
 * basic_cost". The migration rate phase and the nightly sync both call
 * ensure_default_for_client(); neither carries its own copy.
 *
 * TWO RULES IT ENFORCES (K22 ITEM 3):
 *   1. It never writes a $0.00 rate. The migration used to write a $0.00
 *      'Standard' row when basic_cost was missing, purely so the client left
 *      the "unrated" set and its fixed-offset cursor could advance. That
 *      converted "no rate" (bills at the program fallback — wrong for some)
 *      into "bills at zero" (wrong for all, and invisible). A client with no
 *      usable basic_cost now STAYS unrated and is surfaced instead.
 *   2. It never writes an implausible rate. basic_cost is free-text legacy
 *      usermeta; production holds a 23455.00 on a test client. Anything
 *      outside MIN_RATE_CENTS..MAX_RATE_CENTS is rejected and logged.
 *
 * FILL-BLANK ONLY (consistent with K21): a client that already has an
 * is_default = 1 row is never touched — including the pre-K22 $0.00 rows,
 * whose removal is an operator data decision, not this code's.
 *
 * AUTHORISATION: this class has no capability check of its own because its
 * main caller is cron (no user). Interactive callers gate before reaching it
 * (the migration phase via guard_phase(); the dashboard via
 * MealsDB_Permissions::enforce()).
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Client_Rates {

    /**
     * Plausible per-meal rate window, in integer cents (inclusive). Current
     * and approved rates run 9.50–17.30; the window is deliberately generous
     * on both sides so a legitimate future rate is not rejected, while a
     * keying error (1140 for 11.40, 23455) is.
     */
    public const MIN_RATE_CENTS = 100;   // $1.00
    public const MAX_RATE_CENTS = 5000;  // $50.00

    /** Legacy usermeta key holding the client's contracted per-meal rate. */
    public const BASIC_COST_META_KEY = 'basic_cost';

    public const DEFAULT_LABEL = 'Standard';

    public const STATUS_CREATED            = 'created';
    public const STATUS_ALREADY_RATED      = 'already_rated';
    public const STATUS_NO_BASIC_COST      = 'no_basic_cost';
    public const STATUS_INVALID_BASIC_COST = 'invalid_basic_cost';
    public const STATUS_SKIPPED            = 'skipped';
    /** A DB read/write failed. Not a business outcome — the client is still unrated. */
    public const STATUS_ERROR              = 'error';

    /**
     * Client types whose rate reaches an invoice. Private clients are
     * excluded from the nightly pass and the dashboard panel: their orders
     * are priced from WooCommerce products and their rate never reaches a
     * price or an invoice.
     */
    private const BILLED_TYPES_SQL = "c.client_type IN ('SDNB', 'Veteran')";

    private const NIGHTLY_BATCH = 200;

    /** Cap on client IDs carried in one event's context (16KB context limit). */
    private const EVENT_ID_CAP = 500;

    /**
     * Classify a raw basic_cost usermeta value. Pure — no I/O.
     *
     * Accepts only a plain non-negative decimal ("11.40", "11.4", "12"). No
     * sign, no exponent, no currency symbol, no thousands separator: a value
     * we have to guess at is a value we must not bill a government agency on.
     *
     * @param mixed $raw Value as read from usermeta.
     * @return array{status: string, rate: ?string} status is 'ok',
     *         STATUS_NO_BASIC_COST or STATUS_INVALID_BASIC_COST; rate is a
     *         2-decimal string ("11.40") only when status is 'ok'.
     */
    public static function evaluate_basic_cost($raw): array {
        if ($raw === null || $raw === false) {
            return ['status' => self::STATUS_NO_BASIC_COST, 'rate' => null];
        }
        if (!is_scalar($raw)) {
            return ['status' => self::STATUS_INVALID_BASIC_COST, 'rate' => null];
        }

        $value = trim((string) $raw);
        if ($value === '') {
            return ['status' => self::STATUS_NO_BASIC_COST, 'rate' => null];
        }
        // The length bound keeps an absurd digit string out of the float
        // conversion below; anything that long is far outside the window.
        if (strlen($value) > 12 || !preg_match('/^\d+(\.\d+)?$/', $value)) {
            return ['status' => self::STATUS_INVALID_BASIC_COST, 'rate' => null];
        }

        // Integer cents via MealsDB_Money (half-up) — no float comparisons on
        // the range edges.
        $cents = MealsDB_Money::to_cents($value);
        if ($cents === 0) {
            // "0", "0.00": the legacy system's way of saying "not set".
            return ['status' => self::STATUS_NO_BASIC_COST, 'rate' => null];
        }
        if ($cents < self::MIN_RATE_CENTS || $cents > self::MAX_RATE_CENTS) {
            return ['status' => self::STATUS_INVALID_BASIC_COST, 'rate' => null];
        }

        return ['status' => 'ok', 'rate' => MealsDB_Money::format($cents)];
    }

    /**
     * Give a client a default rate row from its WordPress user's basic_cost,
     * if (and only if) it has none.
     *
     * Statuses:
     *   created            — row inserted (or, on a dry run, WOULD be).
     *   already_rated      — client has an is_default = 1 row; untouched.
     *   no_basic_cost      — basic_cost missing, empty or zero (or the client
     *                        has no linked WordPress user to read it from);
     *                        nothing written, client stays unrated.
     *   invalid_basic_cost — basic_cost unparseable or outside the plausible
     *                        window; nothing written.
     *   skipped            — no such client.
     *   error              — a DB read or the INSERT failed; nothing written.
     *
     * @param bool $dry_run Classify only: no write, no event, no audit row.
     * @return array{status: string, client_id: int, rate?: string, rate_id?: int, reason?: string}
     */
    public static function ensure_default_for_client(int $client_id, bool $dry_run = false): array {
        global $wpdb;

        $result = ['status' => self::STATUS_SKIPPED, 'client_id' => $client_id];
        if ($client_id <= 0) {
            $result['reason'] = 'invalid_client_id';
            return $result;
        }

        $clients_table = self::table(MealsDB_Tables::CLIENTS);
        $rates_table   = self::table(MealsDB_Tables::CLIENT_RATES);

        $client = $wpdb->get_row($wpdb->prepare(
            "SELECT client_id, wp_user_id FROM `{$clients_table}` WHERE client_id = %d LIMIT 1",
            $client_id
        ), ARRAY_A);
        if (!is_array($client)) {
            if ((string) $wpdb->last_error !== '') {
                return self::db_error($result, 'client lookup', $dry_run);
            }
            $result['reason'] = 'client_not_found';
            return $result;
        }

        // Fill-blank: any default row at all — whatever its amount — means
        // hands off. A failed read must NOT be mistaken for "no row": that
        // would insert a second default beside the real one.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT rate_id FROM `{$rates_table}` WHERE client_id = %d AND is_default = 1 LIMIT 1",
            $client_id
        ));
        if ($existing !== null) {
            $result['status']  = self::STATUS_ALREADY_RATED;
            $result['rate_id'] = (int) $existing;
            return $result;
        }
        if ((string) $wpdb->last_error !== '') {
            return self::db_error($result, 'default-rate lookup', $dry_run);
        }

        $wp_user_id = (int) ($client['wp_user_id'] ?? 0);
        $raw        = $wp_user_id > 0 ? get_user_meta($wp_user_id, self::BASIC_COST_META_KEY, true) : null;
        $evaluated  = self::evaluate_basic_cost($raw);

        if ($evaluated['status'] !== 'ok') {
            $result['status'] = $evaluated['status'];
            if (!$dry_run) {
                self::record_unrated($client_id, $wp_user_id, $evaluated['status'], $raw);
            }
            return $result;
        }

        $rate           = (string) $evaluated['rate'];
        $result['rate'] = $rate;
        if ($dry_run) {
            $result['status'] = self::STATUS_CREATED;
            return $result;
        }

        $inserted = $wpdb->insert(
            $rates_table,
            [
                'client_id'      => $client_id,
                'label'          => self::DEFAULT_LABEL,
                // Decimal STRING, not %f: the value was validated as cents and
                // goes into a DECIMAL(10,2) column without a float round trip.
                'rate'           => $rate,
                'is_default'     => 1,
                // Business "effective today" date — deliberately the site-local
                // calendar day (current_time), NOT gmdate, so a late-evening
                // run doesn't stamp a rate as effective tomorrow (UTC).
                'effective_date' => current_time('Y-m-d'),
            ],
            ['%d', '%s', '%s', '%d', '%s']
        );
        if ($inserted === false) {
            return self::db_error($result, 'rate INSERT', false);
        }

        $rate_id           = (int) $wpdb->insert_id;
        $result['status']  = self::STATUS_CREATED;
        $result['rate_id'] = $rate_id;

        $linked = $wpdb->update(
            $clients_table,
            ['default_rate_id' => $rate_id],
            ['client_id' => $client_id],
            ['%d'],
            ['%d']
        );
        if ($linked === false) {
            // The is_default = 1 row exists, and that is what billing reads
            // (resolve_rate_for_order), so the client IS rated. Only the
            // denormalised pointer is stale — say so rather than hide it.
            MealsDB_Logger::error(sprintf('[MealsDB Rates] default_rate_id link failed for client_id=%d rate_id=%d: %s', $client_id, $rate_id, (string) $wpdb->last_error));
            MealsDB_Event_Log::record([
                'severity' => 'error', 'category' => 'billing', 'subsystem' => 'client_rates',
                'event' => 'rate.default_link_failed', 'outcome' => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message' => sprintf('Rate row %d created for client %d but default_rate_id was not updated.', $rate_id, $client_id),
                'context' => ['client_id' => $client_id, 'rate_id' => $rate_id],
            ]);
        }

        // A committed change to a data record -> the audit log (Pattern 6).
        MealsDB_Logger::log('rate_default_created', $client_id, 'default_rate', null, $rate, 'basic_cost');

        return $result;
    }

    /**
     * Nightly pass (K22 ITEM 2): every active SDNB / Veteran client with no
     * default rate row gets one from basic_cost, if basic_cost is usable.
     *
     * Covers every client-creation path — form, private-intake promotion,
     * any future one — without hooking each. Staff and test accounts are
     * excluded exactly as K21 excludes them (meals_staff links and
     * administrators); an unreadable staff list fails the pass CLOSED.
     *
     * Runs in shadow mode too: it writes only meals_* tables.
     *
     * Never throws. Emits ONE rates.created summary event per run.
     *
     * @return array<string, mixed> Run stats.
     */
    public static function run_nightly_pass(): array {
        $stats = [
            'created' => 0, 'no_basic_cost' => 0, 'invalid_basic_cost' => 0,
            'already_rated' => 0, 'skipped' => 0, 'staff_skipped' => 0, 'errors' => 0,
            'client_ids' => [], 'aborted' => false,
        ];

        try {
            $staff = self::staff_ids();
            if ($staff === null) {
                return self::abort_pass($stats, 'Staff account lookup failed; rate pass skipped.');
            }

            $cursor = 0;
            while (true) {
                $rows = self::fetch_unrated_billed($cursor, self::NIGHTLY_BATCH);
                if ($rows === null) {
                    return self::abort_pass($stats, 'Unrated-client query failed.');
                }
                if ($rows === []) {
                    break;
                }
                // Cursor on client_id, never an offset: clients that stay
                // unrated (no / invalid basic_cost) remain in the result set,
                // so an offset would either loop on them or skip past others.
                $cursor = (int) end($rows)['client_id'];

                self::prime_user_caches($rows);

                foreach ($rows as $row) {
                    if (self::is_excluded_account((int) $row['wp_user_id'], $staff)) {
                        $stats['staff_skipped']++;
                        continue;
                    }
                    $outcome = self::ensure_default_for_client((int) $row['client_id']);
                    switch ($outcome['status']) {
                        case self::STATUS_CREATED:
                            $stats['created']++;
                            $stats['client_ids'][] = (int) $row['client_id'];
                            break;
                        case self::STATUS_ERROR:
                            $stats['errors']++;
                            break;
                        default:
                            $stats[$outcome['status']] = ($stats[$outcome['status']] ?? 0) + 1;
                    }
                }

                if (count($rows) < self::NIGHTLY_BATCH) {
                    break;
                }
            }

            $unrated = $stats['no_basic_cost'] + $stats['invalid_basic_cost'];
            MealsDB_Event_Log::record([
                'severity'  => $stats['errors'] > 0 ? 'error' : 'info',
                'category'  => 'billing',
                'subsystem' => 'client_rates',
                'event'     => 'rates.created',
                // Clients left unrated carry their own per-client degraded
                // events; this summary is degraded only when a write failed.
                'outcome'   => $stats['errors'] > 0 ? MealsDB_Event_Log::OUTCOME_DEGRADED : MealsDB_Event_Log::OUTCOME_SUCCEEDED,
                'message'   => sprintf(
                    'Nightly rate pass: %d default rate(s) created, %d client(s) left unrated, %d error(s).',
                    $stats['created'], $unrated, $stats['errors']
                ),
                'context'   => [
                    'count'              => $stats['created'],
                    'client_ids'         => array_slice($stats['client_ids'], 0, self::EVENT_ID_CAP),
                    'no_basic_cost'      => $stats['no_basic_cost'],
                    'invalid_basic_cost' => $stats['invalid_basic_cost'],
                    'staff_skipped'      => $stats['staff_skipped'],
                    'errors'             => $stats['errors'],
                ],
            ]);
        } catch (\Throwable $e) {
            return self::abort_pass($stats, $e->getMessage());
        }

        return $stats;
    }

    /**
     * Dashboard data (K22 ITEM 4): active SDNB / Veteran clients with no
     * default rate, each with the reason. Same population and exclusions as
     * the nightly pass, so the panel is exactly "what tonight's run will not
     * fix" plus anything it has not reached yet (reason 'pending').
     *
     * Read-only.
     *
     * @return array<int, array{client_id: int, name: string, client_type: string, reason: string}>|null
     *         null when the list could not be built (staff list or client
     *         query unreadable) — the view says so instead of showing "none".
     */
    public static function get_unrated_billed_clients(int $limit = 200): ?array {
        try {
            $staff = self::staff_ids();
            if ($staff === null) {
                return null;
            }
            $rows = self::fetch_unrated_billed(0, max(1, $limit));
            if ($rows === null) {
                return null;
            }
            self::prime_user_caches($rows);

            $out = [];
            foreach ($rows as $row) {
                $wp_user_id = (int) $row['wp_user_id'];
                if (self::is_excluded_account($wp_user_id, $staff)) {
                    continue;
                }
                $raw       = $wp_user_id > 0 ? get_user_meta($wp_user_id, self::BASIC_COST_META_KEY, true) : null;
                $evaluated = self::evaluate_basic_cost($raw);
                $out[] = [
                    'client_id'   => (int) $row['client_id'],
                    'name'        => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
                    'client_type' => (string) ($row['client_type'] ?? ''),
                    // 'ok' = a usable basic_cost exists; the nightly pass will
                    // create the row on its next run.
                    'reason'      => $evaluated['status'] === 'ok' ? 'pending' : $evaluated['status'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Rates] unrated-client list failed: ' . $e->getMessage());
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * One page of active SDNB / Veteran clients with no is_default = 1 rate
     * row, ordered by client_id, strictly after $after_client_id.
     *
     * @return array<int, array<string, mixed>>|null null on query failure.
     */
    private static function fetch_unrated_billed(int $after_client_id, int $limit): ?array {
        global $wpdb;

        $clients_table = self::table(MealsDB_Tables::CLIENTS);
        $rates_table   = self::table(MealsDB_Tables::CLIENT_RATES);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.client_id, c.wp_user_id, c.client_type, c.first_name, c.last_name
               FROM `{$clients_table}` c
               LEFT JOIN `{$rates_table}` r ON r.client_id = c.client_id AND r.is_default = 1
              WHERE r.rate_id IS NULL AND c.active = 1 AND " . self::BILLED_TYPES_SQL . " AND c.client_id > %d
              ORDER BY c.client_id ASC LIMIT %d",
            $after_client_id,
            $limit
        ), ARRAY_A);

        return is_array($rows) ? $rows : null;
    }

    /**
     * meals_staff-linked WordPress IDs — the K21 exclusion list.
     *
     * @return array<int, bool>|null null when the list is unreadable.
     */
    private static function staff_ids(): ?array {
        $staff = (new MealsDB_Sync_Query())->get_staff_wordpress_ids();
        return is_array($staff) ? $staff : null;
    }

    /**
     * K21's staff/test-account rule: a meals_staff link or the administrator
     * role. (wp_user_id 1, the site admin, is linked to test client 1.)
     *
     * @param array<int, bool> $staff
     */
    private static function is_excluded_account(int $wp_user_id, array $staff): bool {
        if ($wp_user_id <= 0) {
            return false;
        }
        if (isset($staff[$wp_user_id])) {
            return true;
        }
        $user = get_userdata($wp_user_id);
        return is_object($user) && in_array('administrator', (array) ($user->roles ?? []), true);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function prime_user_caches(array $rows): void {
        $ids = array_values(array_filter(array_map(static function ($r): int {
            return (int) ($r['wp_user_id'] ?? 0);
        }, $rows)));
        if ($ids === []) {
            return;
        }
        if (function_exists('cache_users')) {
            cache_users($ids);
        }
        if (function_exists('update_meta_cache')) {
            update_meta_cache('user', $ids);
        }
    }

    /**
     * Per-client "left unrated" event. Degraded on purpose: the client will
     * bill at the program fallback rate until someone fixes basic_cost or
     * adds a rate, and that should reach the dashboard and the daily digest
     * every night until it is fixed.
     *
     * @param mixed $raw
     */
    private static function record_unrated(int $client_id, int $wp_user_id, string $status, $raw): void {
        $invalid = $status === self::STATUS_INVALID_BASIC_COST;
        $context = ['client_id' => $client_id, 'wp_user_id' => $wp_user_id];
        if ($invalid) {
            // The rejected value is a number someone mistyped, not PII; it is
            // what the operator needs to see to fix it. Bounded in case the
            // meta holds something long.
            $context['basic_cost'] = is_scalar($raw) ? substr((string) $raw, 0, 40) : gettype($raw);
        }

        MealsDB_Event_Log::record([
            'severity'  => 'warning',
            'category'  => 'billing',
            'subsystem' => 'client_rates',
            'event'     => $invalid ? 'rate.invalid_basic_cost' : 'rate.unrated_no_basic_cost',
            'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
            'message'   => $invalid
                ? sprintf('Client %d left unrated: basic_cost is not a plausible rate (%s–%s).', $client_id, MealsDB_Money::format(self::MIN_RATE_CENTS), MealsDB_Money::format(self::MAX_RATE_CENTS))
                : sprintf('Client %d left unrated: no basic_cost.', $client_id),
            'context'   => $context,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function db_error(array $result, string $what, bool $quiet): array {
        global $wpdb;

        $error = (string) $wpdb->last_error;
        $result['status'] = self::STATUS_ERROR;
        $result['reason'] = $what . ' failed';

        if (!$quiet) {
            MealsDB_Logger::error(sprintf('[MealsDB Rates] %s failed for client_id=%d: %s', $what, (int) $result['client_id'], $error !== '' ? $error : 'unknown'));
            MealsDB_Event_Log::record([
                'severity' => 'error', 'category' => 'billing', 'subsystem' => 'client_rates',
                'event' => 'rate.create_failed', 'outcome' => MealsDB_Event_Log::OUTCOME_FAILED,
                'message' => sprintf('%s failed for client %d.', $what, (int) $result['client_id']),
                'context' => ['client_id' => (int) $result['client_id'], 'db_error' => $error],
            ]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private static function abort_pass(array $stats, string $message): array {
        $stats['aborted'] = true;
        MealsDB_Logger::error('[MealsDB Rates] nightly rate pass aborted: ' . $message);
        MealsDB_Event_Log::record([
            'severity' => 'error', 'category' => 'billing', 'subsystem' => 'client_rates',
            'event' => 'rates.nightly_failed', 'outcome' => MealsDB_Event_Log::OUTCOME_FAILED,
            'message' => $message,
            // Rows created before the abort are real; keep them on the record.
            'context' => ['count' => $stats['created'], 'client_ids' => array_slice($stats['client_ids'], 0, self::EVENT_ID_CAP)],
        ]);
        return $stats;
    }

    private static function table(string $name): string {
        return str_replace('`', '``', MealsDB_DB::get_table_name($name));
    }
}
