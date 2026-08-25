<?php
/**
 * Consolidated Migration & Backfill Engine.
 *
 * Single entry point for every tool that copies data from the WordPress /
 * WooCommerce tables into the plugin's meals_* tables. Replaces the five
 * standalone backfill classes and absorbs the two meals_*-writing phases
 * (create_clients, create_rates) that used to live in class-migration.php.
 *
 * WHY THIS EXISTS
 * ---------------
 * Before consolidation the data-movement logic was spread across eight
 * files with inconsistent entry-point signatures (some took $dry_run, some
 * a lookback window, some a month range, some nothing) and three of them
 * carried bugs. Driving a live cutover from that surface meant remembering
 * the right order, the right arguments, and the right AJAX action for each.
 * This class gives the admin UI ONE phased pipeline with ONE return
 * contract, run in dependency order, dry-run by default.
 *
 * SCOPE — what this DOES and does NOT own
 * ---------------------------------------
 *   IN:  create_clients, create_rates, allowances, addresses, next-dates,
 *        private-client promotion + enrichment, allocation backfill.
 *   OUT: the Enzebra SQL import (detect_prefix / load_source /
 *        migrate_users / migrate_products / migrate_orders / cleanup) stays
 *        in class-migration.php — it copies source->WP, not WP->meals_*.
 *   OUT: MealsDB_Private_Intake is a LIVE hook (fires on every order via
 *        woocommerce_order_status_changed) and is the canonical promotion
 *        primitive. The private-client phase here CALLS into it rather than
 *        duplicating it, exactly as the old backfill did.
 *
 * THE CHUNKING CONTRACT
 * ---------------------
 * Every run_phase_* method takes ($offset, $dry_run) and returns:
 *   [
 *     'stats'    => array<string,int>,   // accumulated by the JS driver
 *     'offset'   => int,                 // pass back on the next call
 *     'total'    => int,                 // for the progress bar
 *     'complete' => bool,                // true => advance to next phase
 *   ]
 * or [ 'error' => string ] on a hard failure. This matches the contract the
 * existing assets/js/admin-migration.js driver already speaks for phases
 * 1-5, so the UI change is only a longer phase list.
 *
 * DRY RUN
 * -------
 * $dry_run defaults to true everywhere. A dry run reads and counts exactly
 * as a live run would, logs intended writes, and writes nothing. The admin
 * UI sends dry_run=0 only after the operator unchecks the box.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Migration_Consolidated {

    /**
     * Rows processed per chunk. Matches the legacy migration BATCH_SIZE so
     * progress math and per-request timing are unchanged from phases 4/5.
     */
    const BATCH_SIZE = 100;

    /**
     * Default lookback for the private-client phase (months). Ported from
     * MealsDB_Backfill_Private_Clients::DEFAULT_LOOKBACK_MONTHS.
     */
    const DEFAULT_LOOKBACK_MONTHS = 24;

    /**
     * WC order statuses that count as "active"/eligible for the promotion,
     * deactivation-sweep, and recent-order enrichment queries. Hoisted to one
     * place (U03-migration-5): the identical list previously appeared verbatim
     * in private_preview, deactivation_sweep_preview, and
     * recent_orders_for_users — three edit points for one business rule, so
     * drift here would silently desync who gets promoted vs deactivated.
     *
     * @var string[]
     */
    private const ACTIVE_ORDER_STATUSES = ['wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-paid'];

    /**
     * Legacy Enzebra service cadence (lowercased) -> normalised period label.
     * Hoisted to one place (U03-migration-5): the same map was declared inline
     * in phase 1 (create_clients) and phase 3 (allowances). Lookups are
     * order-independent, so a single source cannot drift.
     *
     * @var array<string,string>
     */
    private const PERIOD_MAP = [
        'day' => 'Day', 'week' => 'Week', 'weekly' => 'Week',
        'month' => 'Month', 'monthly' => 'Month', 'daily' => 'Day',
    ];

    /**
     * customer_group (lowercased) -> meals_clients.client_type. Ported
     * verbatim from class-migration.php so create_clients keeps mapping the
     * legacy Enzebra groups identically.
     *
     * @var array<string,string>
     */
    private static $type_map = [
        'sdnb'        => 'SDNB',
        'sdnb rural'  => 'SDNB',
        'extra mural' => 'SDNB',
        'veterans'    => 'Veteran',
    ];

    /**
     * Ordered phase registry. The key is the integer phase number the UI /
     * AJAX layer passes; 'method' is the handler; 'label' is shown in the
     * progress list. Order encodes the dependency chain:
     *
     *   1 clients      -> rows must exist before anything enriches them
     *   2 rates        -> addresses links default_rate_id, so rates first
     *   3 allowances   -> needs client rows (independent of rates)
     *   4 addresses    -> needs rates (default_rate_id linkage)
     *   5 next_dates   -> needs ordering/delivery frequency on the client row
     *   6 private      -> WC-order driven; independent of the SDNB chain
     *   7 allocations  -> needs active gov clients + their orders; runs last
     *
     * @return array<int,array{method:string,label:string}>
     */
    public static function phases(): array {
        return [
            1 => ['method' => 'run_phase_create_clients', 'label' => 'Create Meals Clients'],
            2 => ['method' => 'run_phase_create_rates',   'label' => 'Create Client Rates'],
            3 => ['method' => 'run_phase_allowances',     'label' => 'Backfill Allowances'],
            4 => ['method' => 'run_phase_addresses',      'label' => 'Backfill Addresses'],
            5 => ['method' => 'run_phase_next_dates',     'label' => 'Backfill Next Dates'],
            6 => ['method' => 'run_phase_private_clients','label' => 'Promote Private Clients'],
            7 => ['method' => 'run_phase_allocations',    'label' => 'Backfill Allocations'],
            8 => ['method' => 'run_phase_delivery_day',   'label' => 'Backfill Delivery Day'],
            9 => ['method' => 'run_phase_delivery_dates', 'label' => 'Backfill Delivery Dates'],
        ];
    }

    /**
     * Service-layer authorization gate for the destructive migration phases
     * (audit-2026-08 H12). Every run_phase_* worker calls this first.
     *
     * These public statics DELETE/INSERT/UPDATE live client + allocation data
     * and are reachable by any non-AJAX caller (a future WP-CLI command, REST
     * endpoint, or import script) — the exact "a caller reaches the service
     * without going through the view layer" scenario the MealsDB_Client_Form
     * guard was written to defend against. Today only the (already-gated) AJAX
     * controllers call them, but this fails CLOSED so the capability check
     * can't be bypassed by reaching a worker directly.
     *
     * The migration_destructive RATE limit deliberately stays at the AJAX layer
     * (MealsDB_Ajax_Migration / MealsDB_Ajax_Db_Sync), which owns the chunked
     * walk and charges it first-chunk-only. Duplicating it here would double-
     * charge the 5/hr bucket and 429 a legitimate multi-phase migration mid-run;
     * a direct non-AJAX caller is still capability-gated to administrators.
     *
     * @return array{error:string}|null Error payload to return, or null when allowed.
     */
    private static function guard_phase(): ?array {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            return ['error' => 'You do not have permission to run migration phases.'];
        }
        return null;
    }

    /**
     * Dispatch a phase by number. Single funnel so the AJAX controller never
     * needs a switch — it forwards (phase, offset, dry_run[, extra]).
     *
     * @param array<string,mixed> $args Phase-specific extras (e.g.
     *                                   lookback_months, start_month).
     * @return array<string,mixed>
     */
    public static function run_phase(int $phase, int $offset = 0, bool $dry_run = true, array $args = []): array {
        $phases = self::phases();
        if (!isset($phases[$phase])) {
            return ['error' => 'Invalid phase: ' . $phase];
        }

        $method = $phases[$phase]['method'];
        return self::$method($offset, $dry_run, $args);
    }

    // =====================================================================
    //  PHASE 1 — Create Meals Clients   (from class-migration.php Phase 4)
    // =====================================================================

    /**
     * Create meals_clients rows from legacy WP usermeta for government /
     * Extra Mural users. Idempotent: skips users that already have a row.
     *
     * Logic ported verbatim from MealsDB_Migration::create_clients — the
     * 60-column INSERT and its 60-element format array were audit-verified
     * aligned, so they are reproduced unchanged. The only structural change
     * is that this lives in the consolidated engine and the old method
     * delegates here.
     *
     * @param array<string,mixed> $args Unused.
     */
    public static function run_phase_create_clients(int $offset = 0, bool $dry_run = true, array $args = []): array {
        if (($blocked = self::guard_phase()) !== null) { return $blocked; }
        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        // Verify encryption is available before processing PII.
        if (!$dry_run) {
            try {
                MealsDB_Encryption::encrypt('migration-key-check');
            } catch (\Throwable $e) {
                return ['error' => 'Encryption key is not configured. Set it in Settings -> Meals DB or in the .env file before running this phase. (' . $e->getMessage() . ')'];
            }
        }

        $gov_groups   = ['sdnb', 'SDNB', 'sdnb rural', 'veterans', 'Extra Mural', 'extra mural'];
        $placeholders = implode(',', array_fill(0, count($gov_groups), '%s'));

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
             WHERE meta_key = 'customer_group' AND meta_value IN ({$placeholders})",
            $gov_groups
        ));

        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = 'customer_group' AND meta_value IN ({$placeholders})
             ORDER BY user_id ASC LIMIT %d OFFSET %d",
            array_merge($gov_groups, [self::BATCH_SIZE, $offset])
        ));

        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($user_ids as $uid) {
            $uid = (int) $uid;

            $meta = [];
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d",
                $uid
            ), ARRAY_A);
            foreach ($rows as $r) {
                $meta[$r['meta_key']] = $r['meta_value'];
            }

            $user = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->users} WHERE ID = %d",
                $uid
            ), ARRAY_A);

            if (!$user) {
                $stats['errors']++;
                continue;
            }

            // Idempotency: skip users that already have a client row.
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT client_id FROM `{$clients_table}` WHERE wp_user_id = %d LIMIT 1",
                $uid
            ));
            if ($existing) {
                $stats['skipped']++;
                continue;
            }

            $group = strtolower(trim($meta['customer_group'] ?? ''));
            if (!isset(self::$type_map[$group])) {
                error_log(sprintf(
                    '[MealsDB Consolidated] Skipped user %d: unrecognized customer_group "%s".',
                    $uid,
                    $meta['customer_group'] ?? ''
                ));
                $stats['errors']++;
                continue;
            }
            $client_type = self::$type_map[$group];

            // WP persists first_name usermeta as an empty string for users who
            // never set one (the KEY exists), so the old `?? $display_name`
            // never fell through — the client row got first_name='' and lost
            // its first-initial patterns. Treat empty as absent so the intended
            // display_name fallback actually fires (U03-migration-12), matching
            // the private_preview pattern.
            $first = trim((string) ($meta['first_name'] ?? ''));
            if ($first === '') {
                $first = (string) ($user['display_name'] ?? '');
            }
            $last  = $meta['last_name']  ?? '';

            // Encrypt sensitive fields + build deterministic index sidecars.
            // create_clients can't use the repository's static create()
            // because that auto-encrypts but does NOT generate the *_index
            // hash columns; the raw insert here sets both.
            $individual_id        = null;
            $individual_id_index  = null;
            $requisition_id       = null;
            $requisition_id_index = null;
            $vet_health_card       = null;
            $vet_health_card_index = null;

            try {
                if (!empty($meta['individual_id'])) {
                    $individual_id       = MealsDB_Encryption::encrypt($meta['individual_id']);
                    $individual_id_index = MealsDB_Encryption::create_index($meta['individual_id']);
                }
                if (!empty($meta['requisition_id'])) {
                    $requisition_id       = MealsDB_Encryption::encrypt($meta['requisition_id']);
                    $requisition_id_index = MealsDB_Encryption::create_index($meta['requisition_id']);
                }
                if ($client_type === 'Veteran' && !empty($meta['vat_number'])) {
                    $vet_health_card       = MealsDB_Encryption::encrypt($meta['vat_number']);
                    $vet_health_card_index = MealsDB_Encryption::create_index($meta['vat_number']);
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                self::log(sprintf('Encryption failed for user %d: %s', $uid, $e->getMessage()));
                continue;
            }

            $notes = '';
            if ($group === 'extra mural') {
                $notes = 'Migrated from Extra Mural program.';
            }
            $service_name_zone = null;
            if ($group === 'sdnb rural') {
                $service_name_zone = 'rural';
            }

            $zone_map = ['Moncton' => 'M', 'Sussex' => 'S', 'veterans' => null];
            $sc_raw   = $meta['service_centre_charged'] ?? '';
            $zone     = $zone_map[$sc_raw] ?? null;

            $delivery_area_name = $meta['billing_address_2'] ?? null;

            $open_date = null;
            if (!empty($meta['commence_date']) && $meta['commence_date'] !== '0') {
                $open_date = $meta['commence_date'];
            }

            $mains = $meta['mains'] ?? '';
            $sides = $meta['sides'] ?? '';
            if ($mains !== '' || $sides !== '') {
                $meal_note = "Mains: {$mains}, Sides: {$sides}";
                $notes     = $notes ? $notes . ' ' . $meal_note : $meal_note;
            }
            $allowance_mains_val = ($mains !== '' && $mains !== '0') ? (int) $mains : null;
            $allowance_sides_val = ($sides !== '' && $sides !== '0') ? (int) $sides : null;

            // STR-8 / H1: surface SILENT zone/area mis-derivation. Both fields
            // come from hardcoded string maps whose miss-case is a quiet null
            // (NOT a loud skip like an unrecognized customer_group). A null
            // delivery_area_zone flows into is_rural_zone() (billing rate) and
            // the delivery routing; a null delivery_area_name leaves the whole
            // area->day->next_delivery_date cascade with no input. Emit the
            // same greppable `degraded` trunk event the diet/comments drop now
            // does, naming the user and the unmatched source value, so a
            // migrated-but-wrong client is visible instead of silently billed
            // at the wrong rate. Veterans intentionally map to a null zone
            // (zone_map['veterans'] => null), so they are excluded.
            //
            // U03-migration-11: emit these BEFORE the dry-run short-circuit so a
            // dry run surfaces mis-derived clients BEFORE anything is committed
            // (the header promises a dry run reads/counts as a live run would;
            // this info is most valuable pre-commit). Tagged dry_run so the
            // trunk rows stay distinguishable. Phase 1 has no transaction, so
            // writing during a dry run does not violate a rollback boundary.
            if (class_exists('MealsDB_Event_Log')) {
                if ($client_type !== 'Veteran' && $zone === null) {
                    MealsDB_Event_Log::record([
                        'severity'    => 'warning',
                        'category'    => 'migration',
                        'subsystem'   => 'migration_consolidated',
                        'event'       => 'derive_zone.unmatched',
                        'outcome'     => MealsDB_Event_Log::OUTCOME_DEGRADED,
                        'message'     => sprintf(
                            'service_centre_charged "%s" did not match zone_map; delivery_area_zone left NULL (wrong rate/route risk).',
                            (string) $sc_raw
                        ),
                        'entity_type' => 'user',
                        'entity_id'   => (int) $uid,
                        'context'     => ['service_centre_charged' => (string) $sc_raw, 'client_type' => $client_type, 'dry_run' => $dry_run],
                    ]);
                }
                if ($delivery_area_name === null || $delivery_area_name === '') {
                    MealsDB_Event_Log::record([
                        'severity'    => 'warning',
                        'category'    => 'migration',
                        'subsystem'   => 'migration_consolidated',
                        'event'       => 'derive_area.missing',
                        'outcome'     => MealsDB_Event_Log::OUTCOME_DEGRADED,
                        'message'     => 'billing_address_2 (delivery_area_name) was empty; no delivery area -> no derivable delivery day.',
                        'entity_type' => 'user',
                        'entity_id'   => (int) $uid,
                        'context'     => ['dry_run' => $dry_run],
                    ]);
                }
            }

            if ($dry_run) {
                $stats['created']++;
                continue;
            }

            $initials = MealsDB_Initials_Validator::generate($first, $last, []);
            if ($initials === false) {
                $initials = '';
            }
            $initials_index = $initials !== '' ? MealsDB_Encryption::create_index($initials) : null;

            $email      = $user['user_email'] ?? null;
            $phone1     = !empty($meta['billing_phone']) ? $meta['billing_phone'] : null;
            $phone2     = $meta['mealsdb_client_phone_2'] ?? $meta['billing_phone_2'] ?? null;
            $payment    = $meta['payment_method'] ?? null;
            $service_id = $meta['service_id'] ?? null;
            $req_period = $meta['service'] ?? null;
            $period_map = self::PERIOD_MAP;
            $req_period = isset($period_map[strtolower(trim($req_period ?? ''))]) ? $period_map[strtolower(trim($req_period))] : $req_period;
            $units      = !empty($meta['requisition_units']) ? (int) $meta['requisition_units'] : null;
            $contrib    = !empty($meta['contribution']) ? (float) $meta['contribution'] : null;
            $del_freq   = !empty($meta['delivery_frequency']) ? (int) $meta['delivery_frequency'] : null;
            $ord_freq   = !empty($meta['ordering_frequency']) ? (int) $meta['ordering_frequency'] : null;
            $freeze_cap = $meta['freeze_capacity'] ?? null;
            $del_fee    = !empty($meta['delivery_fee']) ? (float) $meta['delivery_fee'] : null;
            $commence   = $open_date;
            $term_date  = !empty($meta['service_termination_date']) && $meta['service_termination_date'] !== '0'
                ? $meta['service_termination_date'] : null;
            $notes_final = $notes !== '' ? $notes : null;

            $diet     = null;
            $comments = null;
            try {
                $raw_diet = (!empty($meta['dietary_needs']) && $meta['dietary_needs'] !== '0') ? $meta['dietary_needs'] : null;
                if ($raw_diet !== null) {
                    $diet = MealsDB_Encryption::encrypt($raw_diet);
                }
                $raw_comments = !empty($meta['customer_comments']) ? $meta['customer_comments'] : null;
                if ($raw_comments !== null) {
                    $comments = MealsDB_Encryption::encrypt($raw_comments);
                }
            } catch (\Throwable $e) {
                // Non-fatal — store null rather than blocking the insert.
                self::log(sprintf('Could not encrypt diet/comments for user %d: %s', $uid, $e->getMessage()));
                // STR-LOG: this is the silent-data-loss shape — we pressed
                // on and dropped the value. Surface it as degraded.
                if (class_exists('MealsDB_Event_Log')) {
                    MealsDB_Event_Log::record([
                        'severity'    => 'warning',
                        'category'    => 'migration',
                        'subsystem'   => 'migration_consolidated',
                        'event'       => 'encrypt_diet_comments.dropped',
                        'outcome'     => MealsDB_Event_Log::OUTCOME_DEGRADED,
                        'message'     => $e->getMessage(),
                        'entity_type' => 'user',
                        'entity_id'   => (int) $uid,
                    ]);
                }
            }

            $street_name = $meta['billing_address_1'] ?? null;
            $city        = $meta['billing_city'] ?? null;
            $province    = $meta['billing_state'] ?? null;
            $postal_code = $meta['billing_postcode'] ?? null;

            $del_street_name = $meta['shipping_address_1'] ?? null;
            $del_city        = $meta['shipping_city'] ?? null;
            $del_province    = $meta['shipping_state'] ?? null;
            $del_postal_code = $meta['shipping_postcode'] ?? null;

            $alt_name   = $meta['mealsdb_alternate_contact_name']    ?? $meta['alternate_contact_name'] ?? null;
            $alt_phone1 = $meta['mealsdb_alternate_contact_phone_1'] ?? $meta['alternate_contact_phone_1'] ?? null;
            $alt_phone2 = $meta['mealsdb_alternate_contact_phone_2'] ?? $meta['alternate_contact_phone_2'] ?? null;
            $alt_email  = $meta['mealsdb_alternate_contact_email']   ?? $meta['alternate_contact_email'] ?? null;

            $gender          = $meta['gender'] ?? null;
            $birth_date      = !empty($meta['date_of_birth']) && $meta['date_of_birth'] !== '0' ? $meta['date_of_birth'] : (!empty($meta['birth_date']) && $meta['birth_date'] !== '0' ? $meta['birth_date'] : null);
            $worker_name     = $meta['social_worker_name'] ?? $meta['assigned_worker_name'] ?? null;
            $worker_email    = $meta['social_worker_email'] ?? $meta['assigned_worker_email'] ?? null;
            $vendor_number   = $meta['vendor_number'] ?? $meta['billing_vat_number'] ?? null;
            $meal_type       = $meta['meal_type'] ?? null;
            $delivery_day    = $meta['delivery_day'] ?? null;
            $do_not_call     = !empty($meta['do_not_call_client_phone']) ? 1 : 0;
            $ordering_method = $meta['ordering_contact_method'] ?? null;
            $required_start  = !empty($meta['required_start_date']) && $meta['required_start_date'] !== '0' ? $meta['required_start_date'] : null;

            $insert_result = $wpdb->insert(
                $clients_table,
                [
                    'wp_user_id'                => $uid,
                    'client_type'               => $client_type,
                    'first_name'                => $first,
                    'last_name'                 => $last,
                    'client_email'              => $email,
                    'active'                    => 1,
                    'client_phone_1'            => $phone1,
                    'client_phone_2'            => $phone2,
                    'payment_method'            => $payment,
                    'open_date'                 => $open_date,
                    'individual_id'             => $individual_id,
                    'individual_id_index'       => $individual_id_index,
                    'service_id'                => $service_id,
                    'requisition_id'            => $requisition_id,
                    'requisition_id_index'      => $requisition_id_index,
                    'requisition_period'        => $req_period,
                    'units'                     => $units,
                    'client_contribution'       => $contrib,
                    'allowance_mains'           => $allowance_mains_val,
                    'allowance_sides'           => $allowance_sides_val,
                    'vet_health_card'           => $vet_health_card,
                    'vet_health_card_index'     => $vet_health_card_index,
                    'service_center_charged'    => $sc_raw,
                    'delivery_area_zone'        => $zone,
                    'delivery_area_name'        => $delivery_area_name,
                    'service_name_zone'         => $service_name_zone,
                    'delivery_frequency'        => $del_freq,
                    'ordering_frequency'        => $ord_freq,
                    'freezer_capacity'          => $freeze_cap,
                    'delivery_fee'              => $del_fee,
                    'diet_concerns'             => $diet,
                    'customer_comments'         => $comments,
                    'service_commence_date'     => $commence,
                    'expected_termination_date' => $term_date,
                    'notes_to_service_provider' => $notes_final,
                    'delivery_initials'         => $initials,
                    'delivery_initials_index'   => $initials_index,
                    'street_name'               => $street_name,
                    'city'                      => $city,
                    'province'                  => $province,
                    'postal_code'               => $postal_code,
                    'delivery_street_name'      => $del_street_name,
                    'delivery_city'             => $del_city,
                    'delivery_province'         => $del_province,
                    'delivery_postal_code'      => $del_postal_code,
                    'alternate_contact_name'    => $alt_name,
                    'alternate_contact_phone_1' => $alt_phone1,
                    'alternate_contact_phone_2' => $alt_phone2,
                    'alternate_contact_email'   => $alt_email,
                    'gender'                    => $gender,
                    'birth_date'                => $birth_date,
                    'assigned_worker_name'      => $worker_name,
                    'assigned_worker_email'     => $worker_email,
                    'vendor_number'             => $vendor_number,
                    'meal_type'                 => $meal_type,
                    'delivery_day'              => $delivery_day,
                    'do_not_call_client_phone'  => $do_not_call,
                    'ordering_contact_method'   => $ordering_method,
                    'required_start_date'       => $required_start,
                    'use_legacy_billing'        => 1,
                ],
                [
                    '%d', '%s', '%s', '%s', '%s', '%d',
                    '%s', '%s', '%s',
                    '%s', '%s', '%s',
                    '%s', '%s', '%s',
                    '%s', '%d', '%f',
                    '%d', '%d',
                    '%s', '%s',
                    '%s', '%s', '%s', '%s',
                    '%d', '%d', '%s',
                    '%f', '%s', '%s',
                    '%s', '%s',
                    '%s',
                    '%s', '%s',
                    '%s', '%s', '%s', '%s',
                    '%s',
                    '%s', '%s', '%s',
                    '%s', '%s',
                    '%s', '%s',
                    '%s', '%s', '%s', '%s',
                    '%s', '%s', '%s',
                    '%d', '%s', '%s',
                    '%d',
                ]
            );

            if ($insert_result !== false) {
                $stats['created']++;
            } else {
                $stats['errors']++;
            }
        }

        return [
            'stats'    => $stats,
            'offset'   => $offset + self::BATCH_SIZE,
            'total'    => $total,
            'complete' => count($user_ids) < self::BATCH_SIZE,
        ];
    }

    // =====================================================================
    //  PHASE 2 — Create Client Rates   (from class-migration.php Phase 5)
    // =====================================================================

    /**
     * Create a default 'Standard' rate row per client from the legacy
     * basic_cost usermeta value, and link meals_clients.default_rate_id.
     *
     * PAGINATION FIX (was a low-severity bug in the original):
     * The original walked LIMIT/OFFSET over "clients WITHOUT a rate", but
     * each batch INSERTs rates and so SHRINKS that set — advancing the
     * OFFSET against a shrinking result could step over clients. Because
     * the predicate (no rate yet) is self-clearing, the correct cursor is
     * a FIXED OFFSET 0: rows we just gave a rate fall out of the next
     * query on their own. We therefore ignore the incoming $offset for row
     * selection and drive 'complete' off "no remaining unrated clients".
     * The $offset is still echoed back (incremented) so the JS progress
     * math and the generic driver keep working unchanged.
     *
     * @param array<string,mixed> $args Unused.
     */
    public static function run_phase_create_rates(int $offset = 0, bool $dry_run = true, array $args = []): array {
        if (($blocked = self::guard_phase()) !== null) { return $blocked; }
        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $rates_table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);

        // Total unrated clients up front (for the progress bar). This is the
        // count BEFORE this chunk runs; it shrinks as the phase progresses,
        // which is exactly what makes a moving OFFSET unsafe — hence the
        // fixed-offset cursor below.
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$clients_table}` c
             LEFT JOIN `{$rates_table}` r ON r.client_id = c.client_id
             WHERE r.rate_id IS NULL"
        );

        // Fixed OFFSET 0 — see the method docblock. Always pull the next
        // BATCH_SIZE clients that STILL have no rate.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.client_id, c.wp_user_id
             FROM `{$clients_table}` c
             LEFT JOIN `{$rates_table}` r ON r.client_id = c.client_id
             WHERE r.rate_id IS NULL
             ORDER BY c.client_id ASC
             LIMIT %d",
            self::BATCH_SIZE
        ), ARRAY_A);

        if (!is_array($rows)) {
            $rows = [];
        }

        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        // On a DRY RUN nothing is inserted, so the unrated set never shrinks
        // and a fixed-offset cursor would loop forever. Dry runs therefore
        // page with the incoming $offset and report the full projected count
        // in one logical pass.
        if ($dry_run) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT c.client_id, c.wp_user_id
                 FROM `{$clients_table}` c
                 LEFT JOIN `{$rates_table}` r ON r.client_id = c.client_id
                 WHERE r.rate_id IS NULL
                 ORDER BY c.client_id ASC
                 LIMIT %d OFFSET %d",
                self::BATCH_SIZE,
                $offset
            ), ARRAY_A);
            if (!is_array($rows)) {
                $rows = [];
            }
            foreach ($rows as $row) {
                $basic_cost = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'basic_cost' LIMIT 1",
                    (int) $row['wp_user_id']
                ));
                $rate = !empty($basic_cost) ? (float) $basic_cost : 0.00;
                if ($rate <= 0) {
                    $stats['skipped']++;
                } else {
                    $stats['created']++;
                }
            }
            return [
                'stats'    => $stats,
                'offset'   => $offset + self::BATCH_SIZE,
                'total'    => $total,
                'complete' => count($rows) < self::BATCH_SIZE,
            ];
        }

        foreach ($rows as $row) {
            $client_id  = (int) $row['client_id'];
            $wp_user_id = (int) $row['wp_user_id'];

            $basic_cost = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'basic_cost' LIMIT 1",
                $wp_user_id
            ));

            $rate = !empty($basic_cost) ? (float) $basic_cost : 0.00;

            // A client with no usable basic_cost would never get a rate, so
            // it would stay in the unrated set forever and the fixed-offset
            // cursor would never make progress. Write a $0.00 'Standard' rate
            // so the row clears the predicate; the operator can correct the
            // amount later. Skipped-count still records that we defaulted it.
            if ($rate <= 0) {
                $stats['skipped']++;
            }

            $insert_result = $wpdb->insert(
                $rates_table,
                [
                    'client_id'      => $client_id,
                    'label'          => 'Standard',
                    'rate'           => $rate,
                    'is_default'     => 1,
                    // Business "effective today" date — deliberately the site-local
                    // calendar day (current_time), NOT gmdate, so a late-evening
                    // migration doesn't stamp a rate as effective tomorrow (UTC).
                    'effective_date' => current_time('Y-m-d'),
                ],
                ['%d', '%s', '%f', '%d', '%s']
            );

            if ($insert_result !== false) {
                $rate_id = (int) $wpdb->insert_id;
                $wpdb->update(
                    $clients_table,
                    ['default_rate_id' => $rate_id],
                    ['client_id' => $client_id],
                    ['%d'],
                    ['%d']
                );
                if ($rate > 0) {
                    $stats['created']++;
                }
            } else {
                $stats['errors']++;
                // Insert failed: the row is still unrated. Bail out of the
                // phase rather than spin forever on the same client.
                self::log(sprintf(
                    'create_rates INSERT failed for client_id=%d: %s',
                    $client_id,
                    $wpdb->last_error ?: 'unknown'
                ));
                return [
                    'stats'    => $stats,
                    'offset'   => $offset + self::BATCH_SIZE,
                    'total'    => $total,
                    'complete' => true,
                    'error'    => 'create_rates INSERT failed for client_id=' . $client_id . '; phase halted.',
                ];
            }
        }

        // Complete when no unrated clients remain. On a live run the set
        // shrinks every chunk, so this terminates.
        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$clients_table}` c
             LEFT JOIN `{$rates_table}` r ON r.client_id = c.client_id
             WHERE r.rate_id IS NULL"
        );

        return [
            'stats'    => $stats,
            'offset'   => $offset + self::BATCH_SIZE,
            'total'    => $total,
            'complete' => ($remaining === 0) || empty($rows),
        ];
    }

    // =====================================================================
    //  PHASE 3 — Backfill Allowances   (from class-backfill-allowances.php)
    // =====================================================================

    /**
     * Populate allowance_mains / allowance_sides / requisition_period on
     * meals_clients from legacy wp_usermeta (mains / sides / service).
     *
     * CLOBBER FIX (was the HIGH-severity data-loss bug):
     * The original always wrote all three columns in one UPDATE, so a
     * client whose usermeta supplied only ONE of the three had the other
     * two overwritten — allowance_sides forced to 0, requisition_period to
     * '' — destroying existing values. This version builds a dynamic SET
     * list and writes ONLY the columns that have a real legacy value, the
     * same pattern backfill-addresses and enrich_existing already use. A
     * column with no usermeta value is left exactly as it is.
     *
     * @param array<string,mixed> $args Unused.
     */
    public static function run_phase_allowances(int $offset = 0, bool $dry_run = true, array $args = []): array {
        if (($blocked = self::guard_phase()) !== null) { return $blocked; }
        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$clients_table}` WHERE wp_user_id > 0"
        );

        $clients = $wpdb->get_results($wpdb->prepare(
            "SELECT client_id, wp_user_id
             FROM `{$clients_table}`
             WHERE wp_user_id > 0
             ORDER BY client_id ASC
             LIMIT %d OFFSET %d",
            self::BATCH_SIZE,
            $offset
        ), ARRAY_A);

        if (!is_array($clients)) {
            return ['error' => 'Failed to query meals_clients.'];
        }

        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        // Bulk-fetch usermeta for this chunk's users.
        $meta_by_user = [];
        $user_ids = array_filter(array_map(static function ($c) { return (int) ($c['wp_user_id'] ?? 0); }, $clients));
        if (!empty($user_ids)) {
            $user_ids     = array_values(array_unique($user_ids));
            $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $args2        = array_merge($user_ids, ['mains', 'sides', 'service']);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
                 WHERE user_id IN ({$placeholders}) AND meta_key IN (%s, %s, %s)",
                $args2
            ), ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $meta_by_user[(int) $r['user_id']][$r['meta_key']] = $r['meta_value'];
                }
            }
        }

        $period_map = self::PERIOD_MAP;

        foreach ($clients as $client) {
            $wp_user_id = (int) $client['wp_user_id'];
            $client_id  = (int) $client['client_id'];
            $meta       = $meta_by_user[$wp_user_id] ?? [];

            $old_mains   = isset($meta['mains']) && $meta['mains'] !== '' ? (int) $meta['mains'] : null;
            $old_sides   = isset($meta['sides']) && $meta['sides'] !== '' ? (int) $meta['sides'] : null;
            $old_service = isset($meta['service']) && $meta['service'] !== '' ? strtolower(trim($meta['service'])) : null;
            $normalized_period = ($old_service !== null && isset($period_map[$old_service])) ? $period_map[$old_service] : null;

            // Build the SET list from ONLY the fields that have a legacy
            // value. This is the fix: no null-bound column is ever written,
            // so existing allowance_sides / requisition_period survive when
            // the legacy data only carried one of the three.
            $set_clauses = [];
            $bind        = [];
            $changes     = [];

            if ($old_mains !== null) {
                $set_clauses[] = 'allowance_mains = %d';
                $bind[]        = $old_mains;
                $changes[]     = 'mains=' . $old_mains;
            }
            if ($old_sides !== null) {
                $set_clauses[] = 'allowance_sides = %d';
                $bind[]        = $old_sides;
                $changes[]     = 'sides=' . $old_sides;
            }
            if ($normalized_period !== null) {
                $set_clauses[] = 'requisition_period = %s';
                $bind[]        = $normalized_period;
                $changes[]     = 'period=' . $normalized_period;
            }

            if (empty($set_clauses)) {
                $stats['skipped']++;
                continue;
            }

            if ($dry_run) {
                $stats['updated']++;
                error_log(sprintf(
                    '[MealsDB Consolidated] DRY RUN allowances: client_id=%d wp_user_id=%d -> %s',
                    $client_id, $wp_user_id, implode(', ', $changes)
                ));
                continue;
            }

            $bind[] = $client_id;
            $sql = $wpdb->prepare(
                "UPDATE `{$clients_table}` SET " . implode(', ', $set_clauses) . " WHERE client_id = %d",
                ...$bind
            );

            if ($wpdb->query($sql) !== false) {
                $stats['updated']++;
            } else {
                $stats['errors']++;
                error_log(sprintf(
                    '[MealsDB Consolidated] ERROR allowances client_id=%d: %s',
                    $client_id, $wpdb->last_error ?: 'unknown'
                ));
            }
        }

        return [
            'stats'    => $stats,
            'offset'   => $offset + self::BATCH_SIZE,
            'total'    => $total,
            'complete' => count($clients) < self::BATCH_SIZE,
        ];
    }

    // =====================================================================
    //  PHASE 4 — Backfill Addresses   (from class-backfill-addresses.php)
    // =====================================================================

    /**
     * Fix delivery_area_name (zone), street addresses, and default_rate_id
     * linkage from legacy usermeta. Already-good pattern (dynamic SET, no
     * cross-column clobber) ported as-is.
     *
     * BEHAVIOUR NOTE (intentional, see audit): items "street_name" and
     * "delivery_street_name" overwrite when the usermeta value DIFFERS from
     * the stored value (not only when blank), treating WP usermeta as the
     * canonical address source. Re-running therefore re-asserts the WP
     * address over a manual edit. This is the documented policy; the other
     * items fill-only.
     *
     * @param array<string,mixed> $args Unused.
     */
    public static function run_phase_addresses(int $offset = 0, bool $dry_run = true, array $args = []): array {
        if (($blocked = self::guard_phase()) !== null) { return $blocked; }
        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $rates_table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_RATES);

        $core_columns       = ['client_id', 'wp_user_id', 'delivery_area_name', 'street_name', 'delivery_street_name', 'default_rate_id'];
        $optional_columns   = ['apartment_number', 'delivery_apartment_number'];
        $available_optional = self::filter_existing_columns($wpdb, $clients_table, $optional_columns);
        $select_columns     = array_merge($core_columns, $available_optional);
        $select_sql         = implode(', ', $select_columns);

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$clients_table}` WHERE wp_user_id > 0"
        );

        $clients = $wpdb->get_results($wpdb->prepare(
            "SELECT {$select_sql}
             FROM `{$clients_table}`
             WHERE wp_user_id > 0
             ORDER BY client_id ASC
             LIMIT %d OFFSET %d",
            self::BATCH_SIZE,
            $offset
        ), ARRAY_A);

        if (!is_array($clients)) {
            return ['error' => 'Failed to query meals_clients.'];
        }

        $has_apartment_col          = in_array('apartment_number', $available_optional, true);
        $has_delivery_apartment_col = in_array('delivery_apartment_number', $available_optional, true);

        $stats = ['zones_fixed' => 0, 'addresses_fixed' => 0, 'rates_linked' => 0, 'skipped' => 0, 'errors' => 0];

        // Build usermeta lookup for this chunk.
        $wp_user_ids = [];
        foreach ($clients as $c) {
            $uid = (int) $c['wp_user_id'];
            if ($uid > 0) {
                $wp_user_ids[] = $uid;
            }
        }
        $meta_lookup = [];
        if (!empty($wp_user_ids)) {
            $placeholders  = implode(',', array_fill(0, count($wp_user_ids), '%d'));
            $meta_keys     = ['billing_address_1', 'billing_address_2', 'shipping_address_1'];
            $meta_keys_sql = implode(',', array_map(function ($k) use ($wpdb) {
                return $wpdb->prepare('%s', $k);
            }, $meta_keys));
            $meta_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
                 WHERE user_id IN ($placeholders) AND meta_key IN ($meta_keys_sql)",
                ...$wp_user_ids
            ), ARRAY_A);
            if (is_array($meta_rows)) {
                foreach ($meta_rows as $mr) {
                    $meta_lookup[(int) $mr['user_id']][$mr['meta_key']] = $mr['meta_value'];
                }
            }
        }

        foreach ($clients as $client) {
            $client_id  = (int) $client['client_id'];
            $wp_user_id = (int) $client['wp_user_id'];
            $meta       = $meta_lookup[$wp_user_id] ?? [];

            $set_clauses = [];
            $bind_values = [];
            $changes     = [];

            $billing_address_2 = $meta['billing_address_2'] ?? '';
            if (empty($client['delivery_area_name']) && $billing_address_2 !== '') {
                $set_clauses[] = 'delivery_area_name = %s';
                $bind_values[] = $billing_address_2;
                $changes[]     = 'delivery_area_name';
            }

            if ($has_apartment_col && !empty($client['apartment_number']) && strpos($client['apartment_number'], 'Zone') === 0) {
                $set_clauses[] = 'apartment_number = NULL';
                $changes[]     = 'apartment_number';
            }

            if ($has_delivery_apartment_col && !empty($client['delivery_apartment_number']) && strpos($client['delivery_apartment_number'], 'Zone') === 0) {
                $set_clauses[] = 'delivery_apartment_number = NULL';
                $changes[]     = 'delivery_apartment_number';
            }

            $billing_address_1 = $meta['billing_address_1'] ?? '';
            if ($billing_address_1 !== '' && (empty($client['street_name']) || $client['street_name'] !== $billing_address_1)) {
                $set_clauses[] = 'street_name = %s';
                $bind_values[] = $billing_address_1;
                $changes[]     = 'street_name';
            }

            $shipping_address_1 = $meta['shipping_address_1'] ?? '';
            if ($shipping_address_1 !== '' && ($client['delivery_street_name'] ?? '') !== $shipping_address_1) {
                $set_clauses[] = 'delivery_street_name = %s';
                $bind_values[] = $shipping_address_1;
                $changes[]     = 'delivery_street_name';
            }

            if (empty($client['default_rate_id'])) {
                $rate_row = $wpdb->get_row($wpdb->prepare(
                    "SELECT rate_id FROM `{$rates_table}` WHERE client_id = %d AND is_default = 1 LIMIT 1",
                    $client_id
                ), ARRAY_A);
                if (is_array($rate_row) && isset($rate_row['rate_id'])) {
                    $rate_id       = (int) $rate_row['rate_id'];
                    $set_clauses[] = 'default_rate_id = %d';
                    $bind_values[] = $rate_id;
                    $changes[]     = 'default_rate_id';
                }
            }

            if (empty($set_clauses)) {
                $stats['skipped']++;
                continue;
            }

            // $changes holds column NAMES only (never values) — the values are
            // client home addresses and must not reach the dry-run error_log
            // below. Per-field stats are tallied per column name, but on the
            // LIVE path ONLY AFTER the UPDATE actually succeeds (U03-migration-10):
            // pre-counting meant a failed UPDATE still reported N zones/addresses
            // "fixed" that were never written (while also bumping errors), so the
            // operator-facing stats over-claimed. Dry runs count what WOULD change.
            if ($dry_run) {
                self::tally_address_changes($changes, $stats);
                // Log column NAMES only, never the values: these rows carry
                // vulnerable clients' home addresses (street_name /
                // delivery_street_name / delivery_area_name). Emitting the values
                // would land them in the raw PHP error log — often more broadly
                // readable/retained on shared cPanel hosting than the DB row
                // itself, and outside the plugin's scrubbed logging paths (audit
                // U03-migration-9). Mirrors enrich_existing's names-only dry-run log.
                error_log(sprintf(
                    '[MealsDB Consolidated] DRY RUN addresses: client_id=%d wp_user_id=%d -> %s',
                    $client_id, $wp_user_id, implode(', ', $changes)
                ));
                continue;
            }

            $bind_values[] = $client_id;
            $update_sql = $wpdb->prepare(
                "UPDATE `{$clients_table}` SET " . implode(', ', $set_clauses) . " WHERE client_id = %d",
                ...$bind_values
            );

            if ($wpdb->query($update_sql) === false) {
                $stats['errors']++;
                error_log(sprintf(
                    '[MealsDB Consolidated] ERROR addresses client_id=%d: %s',
                    $client_id, $wpdb->last_error ?: 'unknown'
                ));
            } else {
                self::tally_address_changes($changes, $stats);
            }
        }

        return [
            'stats'    => $stats,
            'offset'   => $offset + self::BATCH_SIZE,
            'total'    => $total,
            'complete' => count($clients) < self::BATCH_SIZE,
        ];
    }

    /**
     * Tally the per-field address-fix stats from a list of changed column
     * NAMES. Extracted so the dry-run path and the live-success path share
     * one counter, and the live path can defer counting until the UPDATE
     * actually lands (U03-migration-10). $changes carries column names only.
     */
    private static function tally_address_changes(array $changes, array &$stats): void {
        foreach ($changes as $change) {
            if ($change === 'delivery_area_name') {
                $stats['zones_fixed']++;
            } elseif ($change === 'street_name' || $change === 'delivery_street_name') {
                $stats['addresses_fixed']++;
            } elseif ($change === 'default_rate_id') {
                $stats['rates_linked']++;
            }
        }
    }

    // =====================================================================
    //  PHASE 5 — Backfill Next Dates   (from class-backfill-next-dates.php)
    // =====================================================================

    /**
     * Compute next_order_date / next_delivery_date from last_*_date usermeta
     * plus the client's frequency. Fill-only (never overwrites an existing
     * date). Ported as-is, now chunked.
     *
     * @param array<string,mixed> $args Unused.
     */
    public static function run_phase_next_dates(int $offset = 0, bool $dry_run = true, array $args = []): array {
        if (($blocked = self::guard_phase()) !== null) { return $blocked; }
        global $wpdb;

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$clients_table}` WHERE wp_user_id > 0 AND active = 1"
        );

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT client_id, wp_user_id, ordering_frequency, delivery_frequency,
                    delivery_day, delivery_area_name, next_order_date, next_delivery_date
             FROM `{$clients_table}`
             WHERE wp_user_id > 0 AND active = 1
             ORDER BY client_id ASC
             LIMIT %d OFFSET %d",
            self::BATCH_SIZE,
            $offset
        ), ARRAY_A);

        if (!is_array($rows)) {
            return ['error' => 'Failed to query meals_clients.'];
        }

        $stats = ['processed' => 0, 'order_updated' => 0, 'delivery_updated' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($rows as $row) {
            $stats['processed']++;
            $client_id  = (int) $row['client_id'];
            $wp_user_id = (int) $row['wp_user_id'];

            $patch = [];

            $delivery_day = $row['delivery_day'] ?? null;

            if (empty($row['next_order_date'])) {
                $last_order = self::read_meta_date($wp_user_id, 'last_order_date');
                if ($last_order !== null) {
                    // ordering_frequency is a WEEK multiplier; snap to delivery day.
                    $next_order = MealsDB_Date_Calculator::next_date(
                        $last_order, (int) ($row['ordering_frequency'] ?? 0), $delivery_day
                    );
                    if ($next_order !== null) {
                        $patch['next_order_date'] = $next_order;
                    }
                }
            }

            if (empty($row['next_delivery_date'])) {
                $last_delivery = self::read_meta_date($wp_user_id, 'last_delivery_date');
                // Anchor the no-history fallback on the order date the delivery
                // pairs with: the freshly-computed next_order_date if we just
                // set it, else the stored one, else today (UTC). ITEM 2 fix.
                $anchor = $patch['next_order_date']
                    ?? (!empty($row['next_order_date']) ? (string) $row['next_order_date'] : gmdate('Y-m-d'));
                $next_delivery = self::resolve_backfill_delivery_date($row, $last_delivery, $anchor);
                if (!empty($next_delivery)) {
                    $patch['next_delivery_date'] = $next_delivery;
                }
            }

            if (empty($patch)) {
                $stats['skipped']++;
                continue;
            }

            if ($dry_run) {
                if (isset($patch['next_order_date'])) {
                    $stats['order_updated']++;
                }
                if (isset($patch['next_delivery_date'])) {
                    $stats['delivery_updated']++;
                }
                continue;
            }

            // Explicit %s format for each patched column (the original
            // relied on wpdb's default; we make it explicit).
            $formats = array_fill(0, count($patch), '%s');
            $result  = $wpdb->update($clients_table, $patch, ['client_id' => $client_id], $formats, ['%d']);
            if ($result !== false) {
                if (isset($patch['next_order_date'])) {
                    $stats['order_updated']++;
                }
                if (isset($patch['next_delivery_date'])) {
                    $stats['delivery_updated']++;
                }
            } else {
                $stats['errors']++;
            }
        }

        return [
            'stats'    => $stats,
            'offset'   => $offset + self::BATCH_SIZE,
            'total'    => $total,
            'complete' => count($rows) < self::BATCH_SIZE,
        ];
    }

    /**
     * Resolve the next_delivery_date to store during Backfill Next Dates.
     *
     * Two paths, chosen by whether the client has delivery HISTORY:
     *   - $last_delivery present -> project a full cycle from it via the
     *     canonical MealsDB_Date_Calculator::next_date (unchanged historic
     *     behaviour; snaps to the stored delivery_day when set).
     *   - $last_delivery absent  -> the ITEM 2 fix. Previously this branch
     *     did nothing, so history-less clients got ZERO delivery dates
     *     ("7 order dates, 0 delivery dates updated"). Now we mirror the
     *     get_next_dates endpoint EXACTLY by delegating to its own public
     *     derivation, MealsDB_Quick_Order_Ajax::resolve_delivery_prefill
     *     (stored delivery_day -> zone-schedule fallback -> slip-occurrence
     *     semantics), anchored on the order date. Reusing that method — not
     *     re-deriving here — guarantees the backfilled date is byte-identical
     *     to what the operator sees prefilled in Quick Order.
     *
     * Returns null when nothing can be derived, so the caller leaves the
     * column untouched (never overwrites a non-empty stored value — the
     * caller only calls this when next_delivery_date is empty).
     *
     * @param array<string,mixed> $row           Client row (delivery_day,
     *                                            delivery_area_name,
     *                                            delivery_frequency,
     *                                            next_delivery_date).
     * @param string|null         $last_delivery Y-m-d from last_delivery_date
     *                                            usermeta, or null if absent.
     * @param string              $anchor        Y-m-d order date to anchor the
     *                                            history-less fallback on.
     * @return string|null Y-m-d, or null if nothing is derivable.
     */
    public static function resolve_backfill_delivery_date(array $row, ?string $last_delivery, string $anchor): ?string {
        if ($last_delivery !== null) {
            return MealsDB_Date_Calculator::next_date(
                $last_delivery,
                (int) ($row['delivery_frequency'] ?? 0),
                $row['delivery_day'] ?? null
            );
        }

        // No delivery history to project from. Mirror the QO prefill / the
        // get_next_dates endpoint. resolve_delivery_prefill returns
        // next_delivery_date already normalised to null when unresolvable.
        $prefill = MealsDB_Quick_Order_Ajax::resolve_delivery_prefill(
            $row,
            substr($anchor, 0, 10)
        );
        return $prefill['next_delivery_date'];
    }

    // =====================================================================
    //  PHASE 6 — Promote Private Clients
    //            (from class-backfill-private-clients.php; calls into the
    //             LIVE MealsDB_Private_Intake primitive — not duplicated)
    // =====================================================================

    /**
     * Promote WC users with active orders in the lookback window into
     * Private meals_clients, then enrich any blank columns on existing
     * Private rows. Promotion + field mapping are delegated to
     * MealsDB_Private_Intake (the same code path the live order hook uses).
     *
     * Chunking model: private_preview() is a self-clearing set on LIVE runs
     * (promoting a user removes them from the next call's result), so — like
     * run_phase_create_rates — the cursor advances only past users we could
     * NOT promote, not by a flat BATCH_SIZE, otherwise a shrinking list under
     * a moving offset silently skips users. Dry runs promote nobody, so they
     * page with the incoming $offset. enrich_existing runs in the SAME phase
     * after promotion, only on the final chunk.
     *
     * @param array<string,mixed> $args { lookback_months?: int }
     */
    public static function run_phase_private_clients(int $offset = 0, bool $dry_run = true, array $args = []): array {
        if (($blocked = self::guard_phase()) !== null) { return $blocked; }
        $lookback = isset($args['lookback_months']) ? max(1, (int) $args['lookback_months']) : self::DEFAULT_LOOKBACK_MONTHS;

        $eligible = self::private_preview($lookback);
        $total    = count($eligible);

        $stats = ['eligible' => 0, 'promoted' => 0, 'enriched' => 0, 'skipped' => 0, 'errors' => 0];

        $slice = array_slice($eligible, $offset, self::BATCH_SIZE);
        $stats['eligible'] = count($slice);

        foreach ($slice as $row) {
            $uid = isset($row['wp_user_id']) ? (int) $row['wp_user_id'] : 0;
            if ($uid <= 0) {
                $stats['skipped']++;
                continue;
            }

            if ($dry_run) {
                $stats['promoted']++;
                continue;
            }

            try {
                $order    = null;
                $order_id = isset($row['recent_order_id']) ? (int) $row['recent_order_id'] : 0;
                if ($order_id > 0 && function_exists('wc_get_order')) {
                    $maybe_order = wc_get_order($order_id);
                    if ($maybe_order instanceof WC_Order) {
                        $order = $maybe_order;
                    }
                }
                // Delegate to the live promotion primitive (kept on purpose).
                $client_id = MealsDB_Private_Intake::maybe_promote($uid, $order);
                if ($client_id) {
                    $stats['promoted']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('[MealsDB Consolidated] Failed to promote user ' . $uid . ': ' . $e->getMessage());
            }
        }

        if ($dry_run) {
            // A dry run promotes nobody, so private_preview() never shrinks:
            // page with the incoming $offset exactly as the generic driver
            // expects, and finish when the cursor passes the fixed total.
            $next_offset = $offset + self::BATCH_SIZE;
            $complete    = $next_offset >= $total;
        } else {
            // PAGINATION FIX (was a high-severity bug): a LIVE promotion
            // removes that user from private_preview() on the next call
            // (get_all_wp_user_ids() then includes them), so the eligible list
            // shrinks by 'promoted' every chunk. The original advanced $offset
            // by BATCH_SIZE against that shrinking list, stepping over ~one
            // batch of users per chunk and ending early against the shrunken
            // $total — e.g. 250 eligible yielded only ~150 promoted, silently.
            // This mirrors the phase-2 self-clearing-set fix: advance the
            // cursor ONLY past the users we could not promote (skipped/errors);
            // successfully promoted users fall out of the list on their own, so
            // the next chunk's fixed window pulls fresh users while
            // unpromotable ones can't wedge the cursor. Done when the cursor
            // passes the shrinking tail. Every eligible user is attempted once.
            $stuck       = $stats['skipped'] + $stats['errors'];
            $next_offset = $offset + $stuck;
            $complete    = $next_offset >= $total;
        }

        // On the final chunk, run the enrichment pass over existing Private
        // rows (fill-only, per-field nullity, delegates field mapping to
        // Private_Intake::build_field_payload). enrich_existing is a single
        // pass; fold its stats into this phase. (Computed AFTER the cursor
        // decision above so enrich's skipped-count can't perturb $stuck.)
        if ($complete) {
            $enrich = self::enrich_existing($dry_run);
            $stats['enriched'] += (int) ($enrich['enriched'] ?? 0);
            $stats['skipped']  += (int) ($enrich['skipped'] ?? 0);
            $stats['errors']   += (int) ($enrich['errors'] ?? 0);
        }

        return [
            'stats'    => $stats,
            'offset'   => $next_offset,
            'total'    => max($total, 1),
            'complete' => $complete,
        ];
    }

    // =====================================================================
    //  PHASE 7 — Backfill Allocations
    //            (from class-backfill-allocations-engine.php)
    // =====================================================================

    /**
     * Backfill allocation SUMMARIES for active SDNB/Veteran clients from
     * historical WC orders, and mark each touched client-month dirty so the
     * rebuilder materialises the delivery-allocation DETAIL later.
     *
     * Under the current (mark-dirty) engine this phase does NOT write
     * meals_delivery_allocations itself: allocate_order() only records
     * (client_id, billing_month) in meals_client_month_dirty, and
     * recalculate_month_totals() upserts the meals_client_allocations summary
     * from whatever detail already exists. The detail rows are filled in later
     * by MealsDB_Allocation_Rebuilder (nightly rebuild_all_dirty, invoice
     * generation, or the manual "Recalculate Allocations" Data Ops action).
     * That is why this phase reports client_months_marked_dirty rather than a
     * count of created rows (allocations_created stays 0 — nothing is created
     * here; the key is kept only because the Data Ops view reads it).
     *
     * THROWABLE FIX (was a low-severity bug): the original caught only
     * \Exception, so a PHP Error type (e.g. TypeError) thrown inside the
     * allocation loop would escape the ROLLBACK and leave a half-written
     * month. This version catches \Throwable.
     *
     * Chunking model: this phase is driven by MONTH, not row offset. The
     * incoming $offset is reinterpreted as a month index into the computed
     * month range; each call processes exactly one month (all clients) so a
     * single AJAX request stays bounded. 'total' is the month count.
     *
     * @param array<string,mixed> $args { start_month?: string YYYY-MM,
     *                                     end_month?: string YYYY-MM }
     */
    public static function run_phase_allocations(int $offset = 0, bool $dry_run = true, array $args = []): array {
        if (($blocked = self::guard_phase()) !== null) { return $blocked; }
        global $wpdb;

        $end_month   = isset($args['end_month']) && self::is_ym((string) $args['end_month'])
            ? (string) $args['end_month']
            : gmdate('Y-m');
        // Default lookback start: 24 months before end, matching the
        // private-client window, unless the caller supplies one.
        if (isset($args['start_month']) && self::is_ym((string) $args['start_month'])) {
            $start_month = (string) $args['start_month'];
        } else {
            $start_dt    = (new \DateTime($end_month . '-01'))->modify('-23 months');
            $start_month = $start_dt->format('Y-m');
        }

        if ($start_month > $end_month) {
            return ['error' => 'Start month must be before or equal to end month.'];
        }

        $months      = self::build_month_range($start_month, $end_month);
        $total_months = count($months);

        if ($offset >= $total_months) {
            return [
                'stats'    => ['months_processed' => 0, 'clients_processed' => 0, 'orders_processed' => 0, 'allocations_created' => 0, 'client_months_marked_dirty' => 0],
                'offset'   => $offset + 1,
                'total'    => $total_months,
                'complete' => true,
            ];
        }

        $billing_month = $months[$offset];

        $clients_table        = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $allocations_table    = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        // NOTE: the DELIVERY_ALLOCATIONS table name is no longer resolved here.
        // The old before/after row-count queries against it were removed —
        // allocate_order() writes no delivery rows during this phase, so those
        // COUNTs were structurally always equal (see the loop below).

        $clients = $wpdb->get_results($wpdb->prepare(
            "SELECT client_id, wp_user_id FROM {$clients_table}
             WHERE active = 1 AND client_type IN (%s, %s)",
            'SDNB',
            'Veteran'
        ), ARRAY_A);

        if (!is_array($clients) || empty($clients)) {
            // No clients to process — still a valid (empty) month.
            return [
                'stats'    => ['months_processed' => 0, 'clients_processed' => 0, 'orders_processed' => 0, 'allocations_created' => 0, 'client_months_marked_dirty' => 0],
                'offset'   => $offset + 1,
                'total'    => $total_months,
                'complete' => ($offset + 1) >= $total_months,
            ];
        }

        $engine        = new MealsDB_Allocation_Engine();
        $order_query   = new MealsDB_WC_Order_Query($wpdb);
        $finalized_set = self::get_finalized_months($wpdb, $allocations_table);

        $stats = ['months_processed' => 0, 'clients_processed' => 0, 'orders_processed' => 0, 'allocations_created' => 0, 'client_months_marked_dirty' => 0];

        $year  = (int) substr($billing_month, 0, 4);
        $month = (int) substr($billing_month, 5, 2);
        $days_in_month = (int) (new \DateTime(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $month_start   = sprintf('%04d-%02d-01', $year, $month);
        $month_end     = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

        $prior_dt          = (new \DateTime($month_start))->modify('-1 month');
        $prior_month_start = $prior_dt->format('Y-m-01');

        // Per-month transaction on live runs (dry runs roll back).
        $wpdb->query('START TRANSACTION');

        $clients_in_month = 0;
        // Per-month dedup map. The original walked all months in one call
        // with a single cross-month map; chunking by month resets it each
        // call. That's safe because allocate_order() is now mark-dirty-only:
        // it records (client_id, billing_month) in meals_client_month_dirty and
        // writes NO delivery_allocations rows. (The old comment here described
        // the deleted synchronous engine that DELETE+INSERTed rows keyed by
        // wc_order_id — that behaviour no longer exists.) Marking the same
        // client-month dirty more than once is idempotent — the rebuilder later
        // recomputes it once from scratch — so re-touching a spillover order in
        // an adjacent month chunk is a harmless no-op, not a double-count. The
        // map just avoids redundant mark-dirty calls within this chunk.
        $allocated_orders = [];

        try {
            foreach ($clients as $client) {
                $client_id  = (int) $client['client_id'];
                $wp_user_id = (int) $client['wp_user_id'];

                $client_month_key = $client_id . ':' . $billing_month;
                if (isset($finalized_set[$client_month_key])) {
                    continue;
                }

                // calculate_permitted_for_month() is intentionally NOT called
                // here. It is pure (the engine writes nothing) and
                // recalculate_month_totals() below recomputes the permitted
                // values internally, so an explicit call was dead work.
                $orders = $order_query->get_orders_for_users([$wp_user_id], $prior_month_start, $month_end);

                $client_marked_dirty = false;
                if (is_array($orders)) {
                    foreach ($orders as $order) {
                        $order_id = (int) $order['order_id'];
                        if (isset($allocated_orders[$order_id])) {
                            continue;
                        }
                        $engine->allocate_order($order_id);
                        $allocated_orders[$order_id] = true;
                        $client_marked_dirty = true;
                        $stats['orders_processed']++;

                        // NOTE: cleanup_finalized_spillover was removed here
                        // (was a high-severity bug). It was written for the old
                        // synchronous allocate_order that WROTE delivery rows
                        // during this phase; it deleted any row that landed in a
                        // finalized month. allocate_order is now mark-dirty-only
                        // (writes zero delivery_allocations rows), so every row
                        // that helper could match was PRE-EXISTING, submitted
                        // invoice detail — deleting it corrupted finalized
                        // months (summary kept its counts, detail vanished),
                        // breaking the LB-3 immutability invariant. The post-LB-3
                        // rebuilder already refuses to write into finalized
                        // months, so the guard is enforced where rows are
                        // actually written; no per-order cleanup is needed here.
                    }
                }

                $engine->recalculate_month_totals($client_id, $billing_month);

                // Truthful stat. This phase creates ZERO meals_delivery_
                // allocations rows (allocate_order is mark-dirty-only), so the
                // old before/after COUNT diff was structurally always 0 — two
                // wasted queries per client per month for a number that could
                // never move. Count the client-months we actually queued for
                // the rebuilder instead. allocations_created stays 0 (kept only
                // for the Data Ops display, which reads that key).
                if ($client_marked_dirty) {
                    $stats['client_months_marked_dirty']++;
                }
                $clients_in_month++;
            }

            if ($clients_in_month > 0) {
                $stats['months_processed']    = 1;
                $stats['clients_processed']  += $clients_in_month;
            }

            if ($dry_run) {
                $wpdb->query('ROLLBACK');
            } else {
                $wpdb->query('COMMIT');
            }
        } catch (\Throwable $e) {
            // \Throwable, not \Exception — a TypeError from allocate_order
            // must still trigger the rollback (the fix).
            $wpdb->query('ROLLBACK');
            return ['error' => 'Exception during allocation month ' . $billing_month . ': ' . $e->getMessage()];
        }

        return [
            'stats'    => $stats,
            'offset'   => $offset + 1,
            'total'    => $total_months,
            'complete' => ($offset + 1) >= $total_months,
        ];
    }

    // =====================================================================
    //  PHASE 8 — Backfill Delivery Day
    // =====================================================================

    /**
     * Populate delivery_day on client rows from the zone delivery schedule,
     * for any active client whose delivery_day is currently blank. This runs
     * as the final consolidated step so clients just created by phases 1-7
     * immediately get a delivery day filled in.
     *
     * Blank-fill only: clients that already have a delivery_day are never
     * touched, so it is idempotent and safe to re-run. Logic mirrors the
     * zone→day fill also performed by MealsDB_Zone_Day::resync_all() (the
     * Settings-page resync); this migration phase remains the blank-fill
     * variant for initial import.
     *
     * Single bulk pass — not chunked — so it completes in one call.
     *
     * @param array<string,mixed> $args Unused.
     * @return array<string,mixed>
     */
    /**
     * Per-order backfill decision (pure; unit-tested). No DB access.
     *
     * @return array{0:string,1:?string} action in {'write','skip_human','blank'}
     *   and the value: Y-m-d for 'write', the preserved date for 'skip_human',
     *   null for 'blank'.
     */
    public static function decide_backfill_write(
        string $order_date_ymd,
        string $delivery_day,
        string $existing_delivery_date,
        string $src_marker
    ): array {
        // Human-set: has a _delivery_date the system did not write ('auto').
        $has_existing = preg_match('/^\d{4}-\d{2}-\d{2}$/', $existing_delivery_date) === 1;
        if ($has_existing && $src_marker !== 'auto') {
            return ['skip_human', $existing_delivery_date];
        }
        $computed = MealsDB_Date_Calculator::next_week_delivery_date($order_date_ymd, $delivery_day);
        if ($computed === null) {
            return ['blank', null]; // blank means blank
        }
        return ['write', $computed];
    }

    public static function run_phase_delivery_day(int $offset = 0, bool $dry_run = true, array $args = []): array {
        if (($blocked = self::guard_phase()) !== null) { return $blocked; }
        global $wpdb;

        $schedule = get_option('mealsdb_zone_delivery_schedule', []);
        if (empty($schedule) || !is_array($schedule)) {
            // Nothing to do (no schedule configured) — succeed as a no-op so
            // the consolidated run still reports phase 8 complete.
            return [
                'stats'    => ['updated' => 0, 'note' => 'No zone delivery schedule configured.'],
                'offset'   => 1,
                'total'    => 1,
                'complete' => true,
            ];
        }

        $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $updated = 0;
        $would   = 0;

        foreach ($schedule as $zone_name => $config) {
            if (empty($config['day'])) {
                continue;
            }
            $day = strtolower((string) $config['day']);

            if ($dry_run) {
                $would += (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$clients_table}`
                     WHERE delivery_area_name = %s
                       AND (delivery_day IS NULL OR delivery_day = '')
                       AND active = 1",
                    $zone_name
                ));
                continue;
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE `{$clients_table}`
                 SET delivery_day = %s
                 WHERE delivery_area_name = %s
                   AND (delivery_day IS NULL OR delivery_day = '')
                   AND active = 1",
                $day,
                $zone_name
            ));
            $updated += (int) $wpdb->rows_affected;
        }

        return [
            'stats'    => $dry_run
                ? ['would_update' => $would]
                : ['updated' => $updated],
            'offset'   => 1,
            'total'    => 1,
            'complete' => true,
        ];
    }

    /**
     * PHASE 9 — Backfill Delivery Dates (repeatable).
     *
     * DIRECTIVE delivery-date-next-week-rule ITEM 2. Recompute every linked
     * order's delivery date under the following-week rule
     * (MealsDB_Date_Calculator::next_week_delivery_date), write it to the
     * order's `_delivery_date` meta tagged `_delivery_date_src='auto'`, and
     * mark the affected client-months dirty so a later allocation rebuild
     * propagates the billing-month change.
     *
     * PROPAGATION — must be a FULL-RANGE rebuild, not dirty-only. This phase
     * marks the NEW month dirty (always) and the OLD month dirty only when the
     * order already carried a stored `_delivery_date`. A LEGACY order with no
     * stored date has its current billing month derived downstream by
     * calculate_delivery_schedule() — a schedule-derived month this phase does
     * not know and cannot dirty. A dirty-ONLY pass (rebuild_all_dirty over just
     * these marks) would therefore leave that stale legacy month behind and
     * risk double-counting. Propagation MUST run the full-range allocation
     * rebuild over the affected window (every non-finalized client-month), which
     * re-sums each month from the now-authoritative `_delivery_date` override
     * and so corrects the untracked legacy month too. See the delivery-date
     * backfill runbook / the ITEM 2 rebuild step.
     *
     * PROVENANCE, NOT DESTRUCTION. A human-set date — a `_delivery_date` with
     * NO `auto` marker (either an explicit 'manual' marker or a legacy date
     * written before markers existed) — is SKIPPED and preserved. Only dates
     * the system itself wrote ('auto'), and orders with no date at all, are
     * (re)written. That is why this phase is safe to re-run: it converges
     * every auto date onto the current rule and never clobbers an operator
     * override. The pure decision lives in decide_backfill_write() (unit-
     * tested); this method only does the I/O around it.
     *
     * FINALIZED-MONTH GUARD. Changing a delivery date can move an order's
     * meals from one billing month to another. If EITHER the old month (from
     * the order's current stored date) or the new month (from the computed
     * date) is a finalized client-month, the date is NOT changed — a finalized
     * month is a submitted invoice and must stay byte-identical (Pattern LB-3).
     * Such orders are counted in `finalized_conflicts` and logged degraded.
     *
     * DIRTY-MARKING. We mark BOTH the old and the new client-month dirty
     * explicitly via MealsDB_Allocation_Rebuilder::mark_dirty($client_id, $ym).
     * mark_order_months_dirty() is NOT used: it derives its months from
     * existing delivery_allocations rows for the order, which are keyed on the
     * OLD delivery date's billing month — so it would cover the old month at
     * best and never the NEW month this phase creates. The explicit two-call
     * approach covers both boundaries deterministically. mark_dirty() is
     * idempotent (unique key + ON DUPLICATE KEY UPDATE), so re-runs are cheap.
     *
     * ENUMERATION / CHUNKING. All non-trash orders linked to a meals_client
     * via the wp_user_id <-> wc_orders.customer_id join, ordered by order id
     * (deterministic), paged by LIMIT/OFFSET at BATCH_SIZE * 2 rows/chunk.
     * The join covers legacy orders too (mealsdb_client_id meta only exists on
     * QO orders); customer_id is HPOS-native. If a customer_id maps to MULTIPLE
     * active clients (a known rare-but-legitimate case — e.g. an SDNB recipient
     * who is also a Veteran), the join yields one row per client, so the order
     * is processed once per client. That is conservative: each client-month is
     * marked dirty and the same `_delivery_date` (a per-order value) is written
     * idempotently. The delivery_day may differ per client; the LAST-processed
     * client for that order wins the written date — deterministic under the
     * `ORDER BY o.id, c.client_id` sort. This is noted as a known data risk;
     * the per-order date meta cannot itself distinguish two clients.
     *
     * DRY RUN. Computes decisions and accumulates stats but writes nothing and
     * marks nothing dirty, so the operator previews counts before committing.
     *
     * @param array<string,mixed> $args Unused.
     * @return array<string,mixed>
     */
    public static function run_phase_delivery_dates(int $offset = 0, bool $dry_run = true, array $args = []): array {
        if (($blocked = self::guard_phase()) !== null) { return $blocked; }
        global $wpdb;

        $stats = [
            'orders_scanned'      => 0,
            'written'             => 0,
            'unchanged'           => 0,
            'skipped_human'       => 0,
            'left_blank'          => 0,
            'finalized_conflicts' => 0,
            'errors'              => 0,
        ];

        $clients_table     = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $allocations_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);
        $orders_table      = $wpdb->prefix . 'wc_orders';

        // Deterministic set: every non-trash order linked to a meals_client via
        // customer_id <-> wp_user_id. One row per (order, client) so the rare
        // multi-client-per-user case is handled explicitly (see docblock).
        $join = "FROM `{$orders_table}` o
                 INNER JOIN `{$clients_table}` c ON c.wp_user_id = o.customer_id
                 WHERE o.customer_id > 0 AND o.status != 'trash'";

        $total = (int) $wpdb->get_var("SELECT COUNT(*) {$join}");

        if ($total === 0 || $offset >= $total) {
            return [
                'stats'    => $stats,
                'offset'   => $offset,
                'total'    => $total,
                'complete' => true,
            ];
        }

        $limit = self::BATCH_SIZE * 2; // 200 rows/chunk.
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT o.id AS order_id, o.date_created_gmt AS order_date,
                    c.client_id, c.delivery_day, c.delivery_area_name
             {$join}
             ORDER BY o.id ASC, c.client_id ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);

        $rows        = is_array($rows) ? $rows : [];
        $page_count  = count($rows);

        // Finalized (client_id:YYYY-MM) set — reused across the whole chunk.
        $finalized_set = self::get_finalized_months($wpdb, $allocations_table);

        // The rebuilder marks dirty; only instantiate it on a live run.
        $rebuilder = $dry_run ? null : new MealsDB_Allocation_Rebuilder();

        foreach ($rows as $row) {
            $stats['orders_scanned']++;
            try {
                $order_id  = (int) $row['order_id'];
                $client_id = (int) $row['client_id'];

                // Order date -> Y-m-d (date_created_gmt is 'Y-m-d H:i:s' UTC).
                $order_date_ymd = substr((string) $row['order_date'], 0, 10);

                // Effective delivery day: client's own (already lowercase) else
                // the zone fallback. Null/blank -> decide_backfill_write() blanks.
                $delivery_day = trim((string) ($row['delivery_day'] ?? ''));
                if ($delivery_day === '') {
                    $zone_day     = MealsDB_Zone_Day::day_for_zone((string) ($row['delivery_area_name'] ?? ''));
                    $delivery_day = is_string($zone_day) ? $zone_day : '';
                }

                // Read current meta HPOS-correctly.
                $wc_order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
                if (!$wc_order) {
                    // Linked in wc_orders but not loadable as a WC order — count
                    // as an error and move on rather than aborting the chunk.
                    $stats['errors']++;
                    continue;
                }
                $existing = (string) $wc_order->get_meta('_delivery_date', true);
                $src      = (string) $wc_order->get_meta('_delivery_date_src', true);

                $decision = self::decide_backfill_write($order_date_ymd, $delivery_day, $existing, $src);
                [$action, $value] = $decision;

                if ($action === 'skip_human') {
                    $stats['skipped_human']++;
                    continue;
                }
                if ($action === 'blank') {
                    $stats['left_blank']++;
                    continue;
                }

                // action === 'write'.
                $existing_valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $existing) === 1;
                if ($existing_valid && $existing === $value) {
                    // Already correct — nothing changes. Still an 'auto' date
                    // (or would become one), but no billing month moves, so we
                    // do NOT mark dirty. Counted separately from real writes.
                    $stats['unchanged']++;
                    continue;
                }

                // Old/new billing months (YYYY-MM). Old is null when there is no
                // valid stored date yet (a fresh write moves no existing meals).
                $old_month = $existing_valid ? substr($existing, 0, 7) : null;
                $new_month = substr((string) $value, 0, 7);

                // Finalized guard: never move meals into or out of a submitted
                // (finalized) client-month.
                $old_finalized = ($old_month !== null) && isset($finalized_set[$client_id . ':' . $old_month]);
                $new_finalized = isset($finalized_set[$client_id . ':' . $new_month]);
                if ($old_finalized || $new_finalized) {
                    $stats['finalized_conflicts']++;
                    if (class_exists('MealsDB_Event_Log')) {
                        MealsDB_Event_Log::record([
                            'severity'  => 'warning',
                            'category'  => 'migration',
                            'subsystem' => 'delivery_date_backfill',
                            'event'     => 'delivery_date.finalized_conflict',
                            'outcome'   => 'degraded',
                            'message'   => sprintf(
                                'Skipped delivery-date backfill for order %d (client %d): finalized month blocks the change (old=%s new=%s).',
                                $order_id,
                                $client_id,
                                $old_month ?? '-',
                                $new_month
                            ),
                            'context'   => [
                                'order_id'      => $order_id,
                                'client_id'     => $client_id,
                                'old_month'     => $old_month,
                                'new_month'     => $new_month,
                                'old_finalized' => $old_finalized,
                                'new_finalized' => $new_finalized,
                            ],
                        ]);
                    }
                    continue;
                }

                if ($dry_run) {
                    // Would write; count it but touch nothing.
                    $stats['written']++;
                    continue;
                }

                // Live write: set the date + provenance marker, then mark both
                // affected client-months dirty for the rebuilder.
                $wc_order->update_meta_data('_delivery_date', $value);
                $wc_order->update_meta_data('_delivery_date_src', 'auto');
                $wc_order->save();

                if ($old_month !== null && $old_month !== $new_month) {
                    $rebuilder->mark_dirty($client_id, $old_month);
                }
                $rebuilder->mark_dirty($client_id, $new_month);

                $stats['written']++;
            } catch (\Throwable $e) {
                // One bad order must never abort the chunk (Pattern 7).
                $stats['errors']++;
                if (class_exists('MealsDB_Logger')) {
                    MealsDB_Logger::error('[MealsDB Delivery-Date Backfill] order failed: ' . $e->getMessage());
                }
            }
        }

        return [
            'stats'    => $stats,
            'offset'   => $offset + $page_count,
            'total'    => $total,
            'complete' => ($offset + $page_count) >= $total,
        ];
    }

    // =====================================================================
    //  Ported private helpers
    // =====================================================================

    /**
     * Read a YYYY-MM-DD usermeta date and return it validated, or null.
     * Computation of the next date is delegated to MealsDB_Date_Calculator.
     */
    private static function read_meta_date(int $wp_user_id, string $meta_key): ?string {
        if ($wp_user_id <= 0 || !function_exists('get_user_meta')) {
            return null;
        }
        $raw = get_user_meta($wp_user_id, $meta_key, true);
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }
        return $raw;
    }

    /**
     * Return the subset of $columns that exist on $table. Ported from
     * MealsDB_Backfill_Addresses so the addresses phase stays compatible
     * with legacy schemas (apartment_number columns) and fresh installs.
     *
     * @param wpdb     $wpdb
     * @param string[] $columns
     * @return string[]
     */
    private static function filter_existing_columns($wpdb, string $table, array $columns): array {
        if (empty($columns)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($columns), '%s'));
        $args = array_merge([$table], $columns);
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME IN ({$placeholders})",
            $args
        ));
        return is_array($rows) ? array_values(array_intersect($columns, array_map('strval', $rows))) : [];
    }

    /**
     * Build inclusive YYYY-MM month range, chronological. Ported from
     * MealsDB_Backfill_Allocations_Engine::build_month_range.
     *
     * @return string[]
     */
    private static function build_month_range(string $start, string $end): array {
        $months  = [];
        $cursor  = new \DateTime($start . '-01');
        $stop    = new \DateTime($end . '-01');
        while ($cursor <= $stop) {
            $months[] = $cursor->format('Y-m');
            $cursor->modify('+1 month');
        }
        return $months;
    }

    /**
     * Map of "client_id:YYYY-MM" => true for finalized allocation months.
     * Ported from MealsDB_Backfill_Allocations_Engine::get_finalized_months.
     *
     * @param wpdb $wpdb
     * @return array<string,bool>
     */
    private static function get_finalized_months($wpdb, string $allocations_table): array {
        // Column is is_finalized (TINYINT) on meals_client_allocations.
        $rows = $wpdb->get_results(
            "SELECT client_id, billing_month FROM {$allocations_table} WHERE is_finalized = 1",
            ARRAY_A
        );
        $set = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $set[$r['client_id'] . ':' . $r['billing_month']] = true;
            }
        }
        return $set;
    }

    /**
     * Run the next-dates phase to completion in one call, returning a
     * single accumulated stats array. Retained for the settings-page
     * single-shot button (the old MealsDB_Backfill_Next_Dates::run()
     * entry point); the chunked admin pipeline uses run_phase_next_dates.
     *
     * @return array{processed:int,order_updated:int,delivery_updated:int,skipped:int,errors:int}
     */
    public static function drain_phase_next_dates(): array {
        $offset = 0;
        $totals = ['processed' => 0, 'order_updated' => 0, 'delivery_updated' => 0, 'skipped' => 0, 'errors' => 0];
        $guard  = 0;

        do {
            $result = self::run_phase_next_dates($offset, false);
            if (isset($result['error'])) {
                // Shape-consistent return (U03-migration-14): keep the declared
                // stats keys (accumulated so far) AND surface the error, so the
                // AJAX caller can detect the failure. Returning the raw
                // ['error'=>...] chunk array made backfill_next_dates report a
                // failed run to the UI as success.
                return array_merge($totals, ['error' => (string) $result['error']]);
            }
            $stats = $result['stats'] ?? [];
            foreach (['processed', 'order_updated', 'delivery_updated', 'skipped', 'errors'] as $k) {
                $totals[$k] += (int) ($stats[$k] ?? 0);
            }
            $offset = (int) ($result['offset'] ?? ($offset + self::BATCH_SIZE));
            $guard++;
        } while (empty($result['complete']) && $guard < 100000);

        return $totals;
    }

    /**
     * YYYY-MM validator.
     */
    private static function is_ym(string $value): bool {
        return (bool) preg_match('/^\d{4}-\d{2}$/', $value);
    }

    /**
     * Append to the shared migration log so the consolidated phases and the
     * Enzebra import write to one operator-visible log.
     */
    private static function log(string $message): void {
        if (class_exists('MealsDB_Migration') && method_exists('MealsDB_Migration', 'append_log')) {
            MealsDB_Migration::append_log('[Consolidated] ' . $message);
        } else {
            error_log('[MealsDB Consolidated] ' . $message);
        }
    }

    // =====================================================================
    //  Absorbed private-client logic
    //  (was MealsDB_Backfill_Private_Clients — fully moved here)
    //
    //  Promotion itself still delegates to the LIVE MealsDB_Private_Intake
    //  primitive (maybe_promote / build_field_payload); only the batch
    //  orchestration, eligibility query, enrichment, and the deactivation
    //  sweep moved. The deactivation sweep is not WP->meals data movement,
    //  but it lived in the same class and the operator drives it from the
    //  same settings screen, so it moves here intact rather than orphaning.
    // =====================================================================

    /**
     * Identify WC users eligible for promotion: a shipping address on file
     * and at least one active-status order within the lookback window, who
     * don't already have a meals_clients row. Read-only.
     *
     * Public so the settings page (preview button) and phase 6 share one
     * eligibility definition.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function private_preview(int $lookback_months = self::DEFAULT_LOOKBACK_MONTHS): array {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return [];
        }

        $lookback_months = max(1, $lookback_months);
        $cutoff_date     = gmdate('Y-m-d H:i:s', strtotime("-{$lookback_months} months"));

        $orders_table    = $wpdb->prefix . 'wc_orders';
        $addresses_table = $wpdb->prefix . 'wc_order_addresses';

        if (!self::table_exists($orders_table) || !self::table_exists($addresses_table)) {
            return [];
        }

        // wc- prefixed statuses: HPOS stores them prefixed in wc_orders.status.
        $active_statuses = self::ACTIVE_ORDER_STATUSES;
        $placeholders    = implode(',', array_fill(0, count($active_statuses), '%s'));

        $sql = "
            SELECT o.customer_id, MAX(o.id) AS recent_order_id
            FROM `{$orders_table}` o
            INNER JOIN `{$addresses_table}` a
                ON a.order_id = o.id AND a.address_type = 'shipping'
            WHERE o.customer_id > 0
              AND o.status IN ({$placeholders})
              AND o.type = 'shop_order'
              AND o.date_created_gmt >= %s
              AND TRIM(COALESCE(a.address_1, '')) <> ''
            GROUP BY o.customer_id
            ORDER BY o.customer_id ASC
        ";

        $params   = $active_statuses;
        $params[] = $cutoff_date;
        $prepared = $wpdb->prepare($sql, $params);

        $qualifying = $wpdb->get_results($prepared, ARRAY_A);
        if (!is_array($qualifying) || empty($qualifying)) {
            return [];
        }

        $recent_order_by_uid = [];
        foreach ($qualifying as $row) {
            $uid = (int) ($row['customer_id'] ?? 0);
            if ($uid > 0) {
                $recent_order_by_uid[$uid] = (int) ($row['recent_order_id'] ?? 0);
            }
        }

        $existing_user_ids = MealsDB_Clients_Repository::get_all_wp_user_ids();
        $existing_set      = array_flip($existing_user_ids);

        $rows = [];
        foreach (array_keys($recent_order_by_uid) as $uid) {
            if (isset($existing_set[$uid])) {
                continue;
            }
            $u = get_userdata($uid);
            if (!($u instanceof WP_User)) {
                continue;
            }
            $display_name = trim(((string) ($u->first_name ?? '')) . ' ' . ((string) ($u->last_name ?? '')));
            if ($display_name === '') {
                $display_name = (string) ($u->display_name ?? '');
            }
            $rows[] = [
                'wp_user_id'      => $uid,
                'email'           => (string) ($u->user_email ?? ''),
                'name'            => $display_name,
                'order_count'     => function_exists('wc_get_customer_order_count')
                    ? (int) wc_get_customer_order_count($uid)
                    : 0,
                'recent_order_id' => $recent_order_by_uid[$uid],
            ];
        }

        return $rows;
    }

    /**
     * Promote all eligible users in one pass (non-chunked). Retained as a
     * public convenience for the settings-page "run" button and the test
     * suite; the chunked admin pipeline uses run_phase_private_clients.
     *
     * @return array{eligible:int,promoted:int,errors:int,skipped:int}
     */
    public static function private_promote_all(int $lookback_months = self::DEFAULT_LOOKBACK_MONTHS, bool $dry_run = false): array {
        $preview_rows = self::private_preview($lookback_months);

        $stats = [
            'eligible' => count($preview_rows),
            'promoted' => 0,
            'errors'   => 0,
            'skipped'  => 0,
        ];

        if ($dry_run) {
            return $stats;
        }

        foreach ($preview_rows as $row) {
            $uid = isset($row['wp_user_id']) ? (int) $row['wp_user_id'] : 0;
            if ($uid <= 0) {
                $stats['skipped']++;
                continue;
            }
            try {
                $order    = null;
                $order_id = isset($row['recent_order_id']) ? (int) $row['recent_order_id'] : 0;
                if ($order_id > 0 && function_exists('wc_get_order')) {
                    $maybe_order = wc_get_order($order_id);
                    if ($maybe_order instanceof WC_Order) {
                        $order = $maybe_order;
                    }
                }
                $client_id = MealsDB_Private_Intake::maybe_promote($uid, $order);
                if ($client_id) {
                    $stats['promoted']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('[MealsDB Consolidated] Failed to promote user ' . $uid . ': ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Refill blank columns on existing Private rows from usermeta + the
     * user's most recent qualifying order. Fill-only, per-field nullity;
     * delegates field mapping to Private_Intake::build_field_payload and
     * encrypts before update_client (which does not auto-encrypt).
     *
     * @return array{scanned:int,enriched:int,skipped:int,errors:int}
     */
    public static function enrich_existing(bool $dry_run = false): array {
        global $wpdb;

        $stats = ['scanned' => 0, 'enriched' => 0, 'skipped' => 0, 'errors' => 0];
        if (!($wpdb instanceof wpdb)) {
            return $stats;
        }

        $clients_table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $escaped_clients = str_replace('`', '``', $clients_table);

        $rows = $wpdb->get_results(
            "SELECT * FROM `{$escaped_clients}`
             WHERE client_type = 'Private' AND wp_user_id > 0",
            ARRAY_A
        );
        if (!is_array($rows) || empty($rows)) {
            return $stats;
        }

        $stats['scanned'] = count($rows);

        $uids = [];
        foreach ($rows as $row) {
            $uid = (int) ($row['wp_user_id'] ?? 0);
            if ($uid > 0) {
                $uids[] = $uid;
            }
        }
        $recent_order_by_uid = self::recent_orders_for_users($uids);

        $repo = new MealsDB_Clients_Repository();

        foreach ($rows as $row) {
            $client_id  = (int) ($row['client_id'] ?? 0);
            $wp_user_id = (int) ($row['wp_user_id'] ?? 0);
            if ($client_id <= 0 || $wp_user_id <= 0) {
                $stats['skipped']++;
                continue;
            }

            try {
                $order    = null;
                $order_id = $recent_order_by_uid[$wp_user_id] ?? 0;
                if ($order_id > 0 && function_exists('wc_get_order')) {
                    $maybe = wc_get_order($order_id);
                    if ($maybe instanceof WC_Order) {
                        $order = $maybe;
                    }
                }

                $payload = MealsDB_Private_Intake::build_field_payload($wp_user_id, $order);
                if (empty($payload)) {
                    $stats['skipped']++;
                    continue;
                }

                $updates = [];
                foreach ($payload as $column => $value) {
                    if (self::is_blank($value)) {
                        continue;
                    }
                    if (!array_key_exists($column, $row) || !self::is_blank($row[$column])) {
                        continue;
                    }
                    $updates[$column] = $value;
                }

                if (empty($updates)) {
                    $stats['skipped']++;
                    continue;
                }

                if ($dry_run) {
                    $stats['enriched']++;
                    error_log(sprintf(
                        '[MealsDB Consolidated] DRY RUN enrich client_id=%d -> %s',
                        $client_id, implode(',', array_keys($updates))
                    ));
                    continue;
                }

                $encrypted = MealsDB_Encryption::encrypt_columns($updates);
                if (!$repo->update_client($client_id, $encrypted)) {
                    $stats['errors']++;
                    continue;
                }

                $stats['enriched']++;

                $log_payload = [
                    'client_id'  => $client_id,
                    'wp_user_id' => $wp_user_id,
                    'columns'    => array_keys($updates),
                ];
                $encoded = function_exists('wp_json_encode')
                    ? wp_json_encode($log_payload)
                    : json_encode($log_payload);
                MealsDB_Logger::log(
                    'private_client_enriched',
                    $client_id,
                    'intake',
                    null,
                    $encoded === false ? null : $encoded,
                    'mealsdb'
                );
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('[MealsDB Consolidated] enrich_existing failed for client ' . $client_id . ': ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Preview the deactivation sweep: active Private rows whose WC user has
     * NO active order in the lookback window. (Not WP->meals movement, but
     * moved here with the rest of the private-client tooling.)
     *
     * @return array<int,array<string,mixed>>
     */
    public static function deactivation_sweep_preview(int $lookback_months = self::DEFAULT_LOOKBACK_MONTHS): array {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return [];
        }

        $lookback_months = max(1, $lookback_months);
        $cutoff_date     = gmdate('Y-m-d H:i:s', strtotime("-{$lookback_months} months"));

        $clients_table   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $escaped_clients = str_replace('`', '``', $clients_table);

        $orders_table = $wpdb->prefix . 'wc_orders';
        if (!self::table_exists($orders_table)) {
            return [];
        }

        $private_rows = $wpdb->get_results(
            "SELECT client_id, wp_user_id, first_name, last_name
             FROM `{$escaped_clients}`
             WHERE client_type = 'Private' AND active = 1 AND wp_user_id > 0",
            ARRAY_A
        );

        if (!is_array($private_rows) || empty($private_rows)) {
            return [];
        }

        $active_statuses     = self::ACTIVE_ORDER_STATUSES;
        $status_placeholders = implode(',', array_fill(0, count($active_statuses), '%s'));

        $stale = [];
        foreach ($private_rows as $row) {
            $uid = (int) $row['wp_user_id'];
            if ($uid <= 0) {
                continue;
            }

            $params   = $active_statuses;
            $params[] = $uid;
            $params[] = $cutoff_date;

            $recent = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$orders_table}`
                 WHERE status IN ({$status_placeholders})
                   AND type = 'shop_order'
                   AND customer_id = %d
                   AND date_created_gmt >= %s",
                $params
            ));

            if ($recent === 0) {
                $stale[] = [
                    'client_id'  => (int) $row['client_id'],
                    'wp_user_id' => $uid,
                    'name'       => trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? ''))),
                ];
            }
        }

        return $stale;
    }

    /**
     * Execute the deactivation sweep identified by the preview.
     *
     * @return array{candidates:int,deactivated:int,errors:int}
     */
    public static function deactivation_sweep_run(int $lookback_months = self::DEFAULT_LOOKBACK_MONTHS): array {
        $candidates = self::deactivation_sweep_preview($lookback_months);
        $stats = ['candidates' => count($candidates), 'deactivated' => 0, 'errors' => 0];

        foreach ($candidates as $row) {
            $client_id = (int) ($row['client_id'] ?? 0);
            if ($client_id <= 0) {
                $stats['errors']++;
                continue;
            }
            try {
                if (MealsDB_Clients::deactivate_client($client_id)) {
                    $stats['deactivated']++;
                } else {
                    $stats['errors']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                error_log('[MealsDB Consolidated] Deactivation sweep failed for client ' . $client_id . ': ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Most recent qualifying WC order id per user (mirrors private_preview
     * eligibility so the enrichment address fallback pulls from a real
     * fulfilled order).
     *
     * @param int[] $user_ids
     * @return array<int,int> wp_user_id => order_id
     */
    private static function recent_orders_for_users(array $user_ids): array {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return [];
        }
        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if (empty($user_ids)) {
            return [];
        }

        $orders_table    = $wpdb->prefix . 'wc_orders';
        $addresses_table = $wpdb->prefix . 'wc_order_addresses';
        if (!self::table_exists($orders_table) || !self::table_exists($addresses_table)) {
            return [];
        }

        $active_statuses = self::ACTIVE_ORDER_STATUSES;
        $status_ph       = implode(',', array_fill(0, count($active_statuses), '%s'));
        $user_ph         = implode(',', array_fill(0, count($user_ids), '%d'));

        $sql = "
            SELECT o.customer_id, MAX(o.id) AS recent_order_id
            FROM `{$orders_table}` o
            INNER JOIN `{$addresses_table}` a
                ON a.order_id = o.id AND a.address_type = 'shipping'
            WHERE o.customer_id IN ({$user_ph})
              AND o.status IN ({$status_ph})
              AND o.type = 'shop_order'
              AND TRIM(COALESCE(a.address_1, '')) <> ''
            GROUP BY o.customer_id
        ";

        $params   = array_merge($user_ids, $active_statuses);
        $prepared = $wpdb->prepare($sql, $params);
        $results  = $wpdb->get_results($prepared, ARRAY_A);

        $map = [];
        if (is_array($results)) {
            foreach ($results as $r) {
                $map[(int) $r['customer_id']] = (int) $r['recent_order_id'];
            }
        }
        return $map;
    }

    /**
     * "Blank" for enrichment: null / empty / whitespace string. Numeric
     * zero is NOT blank (an admin-set $0.00 is intentional).
     */
    private static function is_blank($value): bool {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        return false;
    }

    /**
     * Information-schema existence check for a table.
     */
    private static function table_exists(string $table_name): bool {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) {
            return false;
        }
        $row = $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s LIMIT 1',
            $table_name
        ));
        return $row !== null;
    }
}
