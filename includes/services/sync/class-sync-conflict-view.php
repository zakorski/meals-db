<?php
/**
 * Data and actions behind the A/B conflict view (directive K21 ITEM 9).
 *
 * One card per WordPress account with open (or, filtered, dismissed)
 * conflicts: WordPress and every active linked client side by side, field by
 * field. Values are RE-READ LIVE through the same readers the nightly
 * detection uses (MealsDB_Sync_Reconciler::fetch_clients / read_wp_value), so
 * what the page shows is current, and the hash it renders for each conflict is
 * computed exactly the way detection computes it. A conflict that has
 * converged since the last scan is shown as changed and is not actionable.
 *
 * Choices are applied PER ACCOUNT in one request (Rev 2 batching). This stage
 * supports only "Leave both" (dismiss) and its undo — they write the conflict
 * store, never client data. "Keep" choices are refused as not yet available;
 * ITEM 10 adds them to the same entry point.
 *
 * Every applied choice is checked against freshly read values: the request
 * carries the values_hash the page rendered, and a mismatch refuses that
 * choice as stale (Rev 2 stale-page protection) instead of acting on values
 * the operator never saw.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

final class MealsDB_Sync_Conflict_View {

    public const PER_PAGE    = 20;
    public const MAX_CHOICES = 64;

    public const ACTION_DISMISS   = 'dismiss';
    public const ACTION_UNDISMISS = 'undismiss';
    public const ACTION_KEEP      = 'keep';

    public const ACTIONS = [self::ACTION_DISMISS, self::ACTION_UNDISMISS, self::ACTION_KEEP];

    /** Derived columns shown read-only on each client (ITEM 6). */
    private const DERIVED = [
        'delivery_area_zone' => 'service_center_charged',
        'delivery_day'       => 'delivery_area_name',
    ];

    // ------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------

    /**
     * One page of account cards.
     *
     * @param string $field  '' for every field, else a two-way registry column.
     * @param string $status 'open' | 'dismissed'
     * @return array{error: ?string, field_counts: array<string,int>, status_counts: array<string,int>,
     *               total_accounts: int, page: int, pages: int, cards: array<int, array<string,mixed>>}
     */
    public static function page(string $field, string $status, int $page): array {
        global $wpdb;
        $out = [
            'error' => null, 'field_counts' => [], 'status_counts' => ['open' => 0, 'dismissed' => 0],
            'total_accounts' => 0, 'page' => 1, 'pages' => 1, 'cards' => [],
        ];

        $status = $status === MealsDB_Sync_Conflicts::STATUS_DISMISSED
            ? MealsDB_Sync_Conflicts::STATUS_DISMISSED : MealsDB_Sync_Conflicts::STATUS_OPEN;
        if ($field !== '' && !in_array($field, MealsDB_Sync_Registry::two_way_columns(), true)) {
            $field = '';
        }

        $table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::SYNC_CONFLICTS));

        $status_rows = $wpdb->get_results(
            "SELECT status, COUNT(DISTINCT wp_user_id) AS n FROM `{$table}`
              WHERE status IN ('open', 'dismissed') GROUP BY status",
            ARRAY_A
        );
        if (!is_array($status_rows)) {
            $out['error'] = __('The conflict store could not be read. If the plugin was just updated, load any admin page once so the schema upgrade can run.', 'meals-db');
            return $out;
        }
        foreach ($status_rows as $row) {
            $out['status_counts'][(string) $row['status']] = (int) $row['n'];
        }

        $field_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT field, COUNT(*) AS n FROM `{$table}` WHERE status = %s GROUP BY field",
            $status
        ), ARRAY_A);
        foreach ((array) $field_rows as $row) {
            $out['field_counts'][(string) $row['field']] = (int) $row['n'];
        }

        $where = 'status = %s';
        $args  = [$status];
        if ($field !== '') {
            $where .= ' AND field = %s';
            $args[] = $field;
        }

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT wp_user_id) FROM `{$table}` WHERE {$where}",
            ...$args
        ));
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(1, $page), $pages);

        $out['total_accounts'] = $total;
        $out['page']           = $page;
        $out['pages']          = $pages;
        if ($total === 0) {
            return $out;
        }

        // Oldest-first, so the operator works the backlog in the order it
        // appeared.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT wp_user_id FROM `{$table}` WHERE {$where}
              GROUP BY wp_user_id ORDER BY MIN(first_seen_at) ASC, wp_user_id ASC
              LIMIT %d OFFSET %d",
            ...array_merge($args, [self::PER_PAGE, ($page - 1) * self::PER_PAGE])
        ));
        $ids = array_map('intval', (array) $ids);

        $store   = new MealsDB_Sync_Conflicts($wpdb);
        $live    = $store->live_for_accounts($ids);
        $clients = MealsDB_Sync_Reconciler::fetch_clients($ids);
        if ($live === null || $clients === null) {
            $out['error'] = __('Could not load the conflicting records.', 'meals-db');
            return $out;
        }

        if (function_exists('cache_users')) {
            cache_users($ids);
        }
        foreach ($ids as $id) {
            $user = get_userdata($id);
            $out['cards'][] = self::build_card(
                $id,
                $user instanceof WP_User ? $user : null,
                $clients[$id] ?? [],
                $live[$id] ?? []
            );
        }

        return $out;
    }

    /**
     * Build one account card from live values. Pure apart from the WP reads
     * done by read_wp_value().
     *
     * @param array<int, array<string, mixed>>    $clients       Active tracked clients.
     * @param array<string, array<string, mixed>> $conflict_rows Live store rows keyed by field.
     * @return array<string, mixed>
     */
    public static function build_card(int $wp_user_id, ?WP_User $user, array $clients, array $conflict_rows): array {
        $card = [
            'wp_user_id' => $wp_user_id,
            'user_found' => $user !== null,
            'user_label' => $user !== null ? trim((string) ($user->display_name ?? '')) : '',
            'user_email' => $user !== null ? (string) ($user->user_email ?? '') : '',
            'clients'    => [],
            'conflicts'  => [],
            'others'     => [],
            'derived'    => [],
        ];

        foreach ($clients as $client) {
            $card['clients'][(int) $client['client_id']] = [
                'name'        => trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? '')),
                'client_type' => (string) ($client['client_type'] ?? ''),
            ];
        }

        foreach (MealsDB_Sync_Registry::fields() as $column => $field) {
            if ($field['direction'] !== MealsDB_Sync_Registry::TWO_WAY) {
                // ITEM 5: delivery_initials / nickname never appears.
                continue;
            }
            $wp    = $user !== null ? MealsDB_Sync_Reconciler::read_wp_value($user, $field['wp']) : '';
            $meals = MealsDB_Sync_Reconciler::meals_values_for($clients, $column);
            $eval  = MealsDB_Sync_Reconciler::evaluate_field($column, $wp, $meals);
            $row   = [
                'column' => $column,
                'label'  => MealsDB_Sync_Registry::label($column),
                'wp'     => $wp,
                'meals'  => $meals,
                'state'  => $eval['outcome'],
            ];

            if (isset($conflict_rows[$column])) {
                $stored = $conflict_rows[$column];
                $row['conflict'] = [
                    'id'            => (int) $stored['conflict_id'],
                    'status'        => (string) $stored['status'],
                    'first_seen_at' => (string) $stored['first_seen_at'],
                    // Hash of the values ON THIS PAGE, for the stale guard.
                    'hash'          => MealsDB_Sync_Conflicts::values_hash($column, $wp, $meals),
                    // Converged (or a client relinked) since the last scan.
                    'stale'         => $eval['outcome'] !== MealsDB_Sync_Reconciler::O_CONFLICT,
                ];
                $card['conflicts'][] = $row;
            } else {
                $card['others'][] = $row;
            }
        }

        foreach (self::DERIVED as $derived => $source) {
            $card['derived'][] = [
                'column' => $derived,
                'label'  => MealsDB_Sync_Registry::label($derived),
                'source' => MealsDB_Sync_Registry::label($source),
                'meals'  => MealsDB_Sync_Reconciler::meals_values_for($clients, $derived),
            ];
        }

        return $card;
    }

    // ------------------------------------------------------------------
    // Choices
    // ------------------------------------------------------------------

    /**
     * Apply an operator's choices for ONE account. Each choice:
     * ['conflict_id' => int, 'values_hash' => string, 'action' => string].
     *
     * @param array<int, array<string, mixed>> $choices
     * @return array{error: ?string, results: array<int, array{conflict_id: int, ok: bool, code: string, message: string}>}
     */
    public static function apply_choices(int $wp_user_id, array $choices, int $user_id): array {
        // Pattern 1, service layer: re-check even though the AJAX handler did.
        if (!MealsDB_Permissions::can_access_plugin()) {
            return ['error' => __('You do not have permission to resolve sync conflicts.', 'meals-db'), 'results' => []];
        }
        if ($wp_user_id <= 0 || $choices === [] || count($choices) > self::MAX_CHOICES) {
            return ['error' => __('Invalid request.', 'meals-db'), 'results' => []];
        }

        global $wpdb;
        $store = new MealsDB_Sync_Conflicts($wpdb);
        $live  = $store->live_for_accounts([$wp_user_id]);
        $all   = MealsDB_Sync_Reconciler::fetch_clients([$wp_user_id]);
        if ($live === null || $all === null) {
            return ['error' => __('Could not load the conflicting records.', 'meals-db'), 'results' => []];
        }
        $by_id = [];
        foreach ($live[$wp_user_id] ?? [] as $row) {
            $by_id[(int) $row['conflict_id']] = $row;
        }
        $clients = $all[$wp_user_id] ?? [];
        $user    = get_userdata($wp_user_id);
        $user    = $user instanceof WP_User ? $user : null;
        $now     = gmdate('Y-m-d H:i:s');

        $results = [];
        foreach ($choices as $choice) {
            $id     = (int) ($choice['conflict_id'] ?? 0);
            $hash   = (string) ($choice['values_hash'] ?? '');
            $action = (string) ($choice['action'] ?? '');
            $results[] = ['conflict_id' => $id]
                + self::apply_one($store, $by_id[$id] ?? null, $hash, $action, $user, $clients, $user_id, $now);
        }

        return ['error' => null, 'results' => $results];
    }

    /**
     * @param array<string, mixed>|null        $row
     * @param array<int, array<string, mixed>> $clients
     * @return array{ok: bool, code: string, message: string}
     */
    private static function apply_one(MealsDB_Sync_Conflicts $store, ?array $row, string $hash, string $action, ?WP_User $user, array $clients, int $user_id, string $now): array {
        if ($row === null) {
            // Closed since the page loaded, or not this account's conflict.
            return self::result(false, 'not_found', __('This conflict is no longer open. Reload the page.', 'meals-db'));
        }

        switch ($action) {
            case self::ACTION_KEEP:
                return self::result(false, 'not_available', __('Keeping a value is not available yet.', 'meals-db'));

            case self::ACTION_UNDISMISS:
                if ($row['status'] !== MealsDB_Sync_Conflicts::STATUS_DISMISSED) {
                    return self::result(false, 'not_dismissed', __('This conflict is not dismissed.', 'meals-db'));
                }
                if (!$store->undismiss($row)) {
                    return self::result(false, 'error', __('Could not update the conflict.', 'meals-db'));
                }
                self::log_choice('sync.conflict_undismissed', $row, $user_id);
                return self::result(true, 'undismissed', __('Dismissal undone.', 'meals-db'));

            case self::ACTION_DISMISS:
                $column = (string) $row['field'];
                $field  = MealsDB_Sync_Registry::get($column);
                if ($field === null || $user === null) {
                    return self::result(false, 'stale', __('These records changed since the page loaded. Reload the page.', 'meals-db'));
                }
                $wp    = MealsDB_Sync_Reconciler::read_wp_value($user, $field['wp']);
                $meals = MealsDB_Sync_Reconciler::meals_values_for($clients, $column);
                $live_hash = MealsDB_Sync_Conflicts::values_hash($column, $wp, $meals);
                if (!preg_match('/^[0-9a-f]{64}$/', $hash) || !hash_equals($live_hash, $hash)
                    || MealsDB_Sync_Reconciler::evaluate_field($column, $wp, $meals)['outcome'] !== MealsDB_Sync_Reconciler::O_CONFLICT) {
                    return self::result(false, 'stale', __('These records changed since the page loaded. Reload the page.', 'meals-db'));
                }
                if (!$store->dismiss($row, $wp, $meals, $live_hash, $user_id, $now)) {
                    return self::result(false, 'error', __('Could not update the conflict.', 'meals-db'));
                }
                self::log_choice('sync.conflict_dismissed', $row, $user_id);
                return self::result(true, 'dismissed', __('Left both values as they are.', 'meals-db'));
        }

        return self::result(false, 'invalid_action', __('Invalid request.', 'meals-db'));
    }

    /** @param array<string, mixed> $row */
    private static function log_choice(string $event, array $row, int $user_id): void {
        // Operational record of an operator decision. No values: they are
        // client PII. Dismissal changes no client record, so it belongs in
        // the event trunk, not the audit log (CLAUDE.md Pattern 6).
        MealsDB_Event_Log::record([
            'severity'    => 'info',
            'category'    => 'sync',
            'subsystem'   => 'sync_conflicts',
            'event'       => $event,
            'outcome'     => MealsDB_Event_Log::OUTCOME_SUCCEEDED,
            'message'     => sprintf('%s for WP user %d, field %s', $event, (int) $row['wp_user_id'], (string) $row['field']),
            'entity_type' => 'user',
            'entity_id'   => (int) $row['wp_user_id'],
            'user_id'     => $user_id,
            'context'     => ['conflict_id' => (int) $row['conflict_id'], 'field' => (string) $row['field']],
        ]);
    }

    /** @return array{ok: bool, code: string, message: string} */
    private static function result(bool $ok, string $code, string $message): array {
        return ['ok' => $ok, 'code' => $code, 'message' => $message];
    }
}
