<?php
/**
 * AJAX endpoints for the consolidated site migration tool.
 *
 * @package MealsDB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MealsDB_Ajax_Migration {

    public static function init(): void {
        add_action( 'wp_ajax_mealsdb_migration_phase',       [ self::class, 'run_phase' ] );
        add_action( 'wp_ajax_mealsdb_migration_reset',       [ self::class, 'reset' ] );
        add_action( 'wp_ajax_mealsdb_migration_log',         [ self::class, 'get_log' ] );
        add_action( 'wp_ajax_mealsdb_backfill_allowances',   [ self::class, 'backfill_allowances' ] );
        add_action( 'wp_ajax_mealsdb_backfill_addresses',   [ self::class, 'backfill_addresses' ] );
        add_action( 'wp_ajax_mealsdb_backfill_allocation_engine', [ self::class, 'backfill_allocation_engine' ] );
        add_action( 'wp_ajax_mealsdb_consolidated_phase', [ self::class, 'run_consolidated_phase' ] );
        add_action( 'wp_ajax_mealsdb_delivery_scorecard', [ self::class, 'delivery_scorecard' ] );
    }

    public static function run_phase(): void {
        self::verify();
        set_time_limit( 300 );

        $phase   = (int) ( $_POST['phase'] ?? 0 );
        $offset  = (int) ( $_POST['offset'] ?? 0 );
        $dry_run = MealsDB_Helpers::bool_flag( $_POST['dry_run'] ?? null, true );

        // QW-1: phases 4/5 are REAL destructive writes (create_clients /
        // create_rates) — the same class of work the db-sync sibling guards.
        // verify() above intentionally has no rate limit (the chunked UI fires
        // many requests per walk), so gate fresh destructive runs here on the
        // FIRST chunk only, exactly like MealsDB_Ajax_Db_Sync::run_phase: dry
        // runs write nothing and are never limited; a real run consumes one
        // token at offset 0 and subsequent chunks pass through.
        if ( ! $dry_run && $offset === 0
            && class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'migration_destructive' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please wait before retrying.', 'meals-db' ) ], 429 );
        }

        $result = [];

        switch ( $phase ) {
            case 4:
                $result = MealsDB_Migration::create_clients( $offset, $dry_run );
                break;
            case 5:
                $result = MealsDB_Migration::create_rates( $offset, $dry_run );
                break;
            default:
                wp_send_json_error( [ 'message' => 'Invalid phase: ' . $phase ] );
        }

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        // Log phase completion
        if ( ! empty( $result['complete'] ) ) {
            $phase_names = [
                4 => 'Meals Clients',
                5 => 'Client Rates',
            ];
            $name  = $phase_names[ $phase ] ?? "Phase {$phase}";
            $mode  = $dry_run ? ' (dry run)' : '';
            $stats = isset( $result['stats'] ) ? wp_json_encode( $result['stats'] ) : '{}';
            MealsDB_Migration::append_log( "{$name}{$mode} complete. Stats: {$stats}" );
        }

        $result['phase'] = $phase;
        wp_send_json_success( $result );
    }

    /**
     * Reset progress and log.
     */
    public static function reset(): void {
        self::verify();
        self::verify_destructive();
        MealsDB_Migration::reset();
        wp_send_json_success();
    }

    /**
     * Return the migration log.
     */
    public static function get_log(): void {
        self::verify();
        wp_send_json_success( [ 'log' => MealsDB_Migration::get_log() ] );
    }

    // ── Helpers ──────────────────────────────────

    private static function verify(): void {
        check_ajax_referer( 'mealsdb_migration_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        // NOTE: No rate limit at the verify() level. The migration UI
        // in assets/js/admin-migration.js (runLoadPhase / runLoadFromDb
        // / runDataPhase) is implemented as self-recursive chunked
        // calls — a real migration of any size routinely fires
        // hundreds of requests inside the same hour. A bucket here
        // would 429 the operator partway through migration and leave
        // the database in a partial state. The one-shot destructive
        // verb reset() carries its own per-endpoint rate limit via
        // verify_destructive(); the three backfill_* endpoints use
        // inline gates; manage_options is the gate for everything
        // else.
    }

    /**
     * Stricter rate-limit gate for the one-shot destructive reset()
     * endpoint. reset() is NOT called in the recursive chunked path, so
     * migration_destructive (5/hr) is safe; verify() above is
     * deliberately unthrottled. (The three backfill_* endpoints gate
     * themselves inline rather than through this helper.)
     */
    private static function verify_destructive(): void {
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'migration_destructive' ) ) {
            wp_send_json_error( [ 'message' => __( 'This destructive migration step is rate-limited. Please wait before retrying.', 'meals-db' ) ], 429 );
        }
    }

    /**
     * Backfill allowance_mains, allowance_sides, and requisition_period
     * from legacy wp_usermeta values.
     */
    public static function backfill_allowances(): void {
        if (!check_ajax_referer('mealsdb_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            return;
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('migration_destructive')) {
            wp_send_json_error(['message' => __('Backfill is rate-limited. Please wait before retrying.', 'meals-db')], 429);
            return;
        }

        $dry_run = !empty($_POST['dry_run']);

        // Delegated to the consolidated engine (single code path). This
        // legacy single-shot endpoint loops every chunk server-side so the
        // existing settings-page button keeps working unchanged; the new
        // chunked driver uses run_consolidated_phase instead.
        $result = self::drain_consolidated_phase(3, $dry_run);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
            return;
        }

        wp_send_json_success($result);
    }

    /**
     * Backfill allocation tables from historical WooCommerce orders.
     */
    public static function backfill_allocation_engine(): void {
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
        if ( $nonce === '' || ! wp_verify_nonce( $nonce, 'mealsdb_nonce' ) ) {
            wp_send_json( [ 'success' => false, 'message' => 'Invalid request.' ] );
        }

        // Aligned with sibling backfills (backfill_allowances,
        // backfill_addresses) per directive 16 Pass A. A previous
        // version gated this endpoint with only manage_woocommerce
        // and no rate limit while the other two used manage_options
        // plus migration_destructive (5/hr). On this site only
        // administrators hold either capability, so the tightening
        // is safe.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json( [ 'success' => false, 'message' => 'Insufficient permissions.' ], 403 );
        }

        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'migration_destructive' ) ) {
            wp_send_json_error( [ 'message' => __( 'Backfill is rate-limited. Please wait before retrying.', 'meals-db' ) ], 429 );
        }

        $start_month = isset( $_REQUEST['start_month'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['start_month'] ) ) : '';
        $end_month   = isset( $_REQUEST['end_month'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['end_month'] ) ) : gmdate( 'Y-m' );
        $dry_run     = MealsDB_Helpers::bool_flag( $_REQUEST['dry_run'] ?? null, true );

        if ( ! MealsDB_Helpers::is_valid_ym( $start_month ) || ! MealsDB_Helpers::is_valid_ym( $end_month ) ) {
            wp_send_json( [ 'success' => false, 'message' => 'Invalid month format. Use YYYY-MM.' ] );
        }

        // Delegated to the consolidated engine (single code path; includes
        // the \Throwable rollback fix). This legacy endpoint drains all
        // month-chunks server-side so the existing button keeps working.
        $result = self::drain_consolidated_phase( 7, $dry_run, [
            'start_month' => $start_month,
            'end_month'   => $end_month,
        ] );

        if ( isset( $result['error'] ) ) {
            wp_send_json( [ 'success' => false, 'message' => $result['error'] ] );
        }

        wp_send_json( [ 'success' => true, 'stats' => $result ] );
    }

    /**
     * Backfill addresses, delivery_area_name (zone data), and default_rate_id
     * from legacy wp_usermeta values and meals_client_rates.
     */
    public static function backfill_addresses(): void {
        if (!check_ajax_referer('mealsdb_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.']);
            return;
        }

        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('migration_destructive')) {
            wp_send_json_error(['message' => __('Backfill is rate-limited. Please wait before retrying.', 'meals-db')], 429);
            return;
        }

        $dry_run = !empty($_POST['dry_run']);

        // Delegated to the consolidated engine (single code path).
        $result = self::drain_consolidated_phase(4, $dry_run);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
            return;
        }

        wp_send_json_success($result);
    }

    /**
     * Unified chunked entry point for the consolidated migration engine.
     *
     * The admin migration UI calls this once per chunk with:
     *   phase   (int)    1..7 — see MealsDB_Migration_Consolidated::phases()
     *   offset  (int)    cursor from the previous response
     *   dry_run (0|1)    default 1 (dry run)
     *   lookback_months  (int, phase 6 only)
     *   start_month/end_month (YYYY-MM, phase 7 only)
     *
     * Returns the standard chunk contract { stats, offset, total, complete }
     * so the existing JS phase-loop drives it the same way it drives the
     * Enzebra import phases.
     */
    public static function run_consolidated_phase(): void {
        self::verify();
        set_time_limit( 300 );

        $phase   = (int) ( $_POST['phase'] ?? 0 );
        $offset  = (int) ( $_POST['offset'] ?? 0 );
        $dry_run = MealsDB_Helpers::bool_flag( $_POST['dry_run'] ?? null, true );
        $ignore_rate_limit = MealsDB_Helpers::bool_flag( $_POST['ignore_rate_limit'] ?? null, false );

        // Rate-limit the START of a real (writing) run only.
        //
        // The consolidated pipeline is chunked at BATCH_SIZE rows per AJAX
        // call, so one phase legitimately makes dozens of back-to-back
        // requests (e.g. 5,000 clients = 50+ calls). The migration_destructive
        // bucket is 5/hour and is meant to throttle *starting* destructive
        // operations, not the internal pagination of one. Gating every chunk
        // tripped a 429 on the 6th call mid-walk.
        //
        //   - Dry runs write nothing, so they are never rate-limited.
        //   - Real runs are checked only on the first chunk of a phase
        //     (offset === 0); subsequent chunks of the same walk pass through.
        //
        // This preserves the guardrail (no more than 5 fresh writing-phase
        // starts per hour) without throttling a single run's pagination.
        //
        // A complete real run still walks all 7 phases, and each phase start
        // consumes one token, so the 5/hour bucket aborts a full run partway
        // (it died at phase 6, "promote private clients"). The operator can
        // opt out for the whole run via the "Ignore rate limit" checkbox (off
        // by default). The override is still behind verify() (nonce +
        // manage_options); we audit-log each bypassed phase start so the
        // decision to skip the limiter is traceable.
        if ( ! $dry_run && $offset === 0 ) {
            if ( $ignore_rate_limit ) {
                if ( class_exists( 'MealsDB_Logger' ) ) {
                    MealsDB_Logger::log( 'migration_rate_limit_bypassed', 0, 'phase', null, (string) $phase );
                }
            } elseif ( class_exists( 'MealsDB_Rate_Limiter' )
                && ! MealsDB_Rate_Limiter::check_rate_limit( 'migration_destructive' ) ) {
                wp_send_json_error( [ 'message' => __( 'Migration is rate-limited. Please wait before retrying, or check "Ignore rate limit" to override.', 'meals-db' ) ], 429 );
            }
        }

        $args = [];
        if ( isset( $_POST['lookback_months'] ) ) {
            $args['lookback_months'] = (int) $_POST['lookback_months'];
        }
        if ( isset( $_POST['start_month'] ) ) {
            $args['start_month'] = sanitize_text_field( wp_unslash( (string) $_POST['start_month'] ) );
        }
        if ( isset( $_POST['end_month'] ) ) {
            $args['end_month'] = sanitize_text_field( wp_unslash( (string) $_POST['end_month'] ) );
        }

        $result = MealsDB_Migration_Consolidated::run_phase( $phase, $offset, $dry_run, $args );

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        // Log on phase completion, mirroring run_phase().
        if ( ! empty( $result['complete'] ) ) {
            $phases = MealsDB_Migration_Consolidated::phases();
            $name   = $phases[ $phase ]['label'] ?? ( 'Phase ' . $phase );
            $mode   = $dry_run ? ' (dry run)' : '';
            $stats  = isset( $result['stats'] ) ? wp_json_encode( $result['stats'] ) : '{}';
            MealsDB_Migration::append_log( "{$name}{$mode} complete. Stats: {$stats}" );
        }

        $result['phase'] = $phase;
        wp_send_json_success( $result );
    }

    /**
     * Delivery-date ground-truth scorecard (DIRECTIVE
     * delivery-date-next-week-rule ITEM 2).
     *
     * The operator pastes or POSTs the operator CSV (lines:
     * `order_number,delivery_date`) extracted from the legacy July packer
     * PDFs. This endpoint resolves each order via wc_get_order(), reads its
     * stored `_delivery_date`, and returns match_rate + the nameable misses
     * so the residual can be classified (retroactive entry vs route shuffle).
     *
     * READ-ONLY — no writes to any table. Still gated behind verify()
     * (manage_options + nonce) because it reads order PII-adjacent meta and
     * runs a bulk WC order query.
     *
     * The CSV text is passed raw (wp_unslash only — sanitize_text_field would
     * strip newlines). Line-by-line validation happens inside parse_csv().
     *
     * Response keys:
     *   total       — count of orders in the ground-truth CSV that resolved
     *   matched     — exact date matches
     *   match_rate  — matched / total (0.0 when total = 0)
     *   misses_total — full count of mismatches
     *   misses_shown — count returned in this response (capped at 500)
     *   misses      — [{order, stored, actual}, …]  (first 500)
     *   unresolved  — orders in the CSV that wc_get_order() could not find
     *
     * Cap rationale: a full July run is ~1,600 orders; in the worst-case (the
     * backfill hasn't run) all of them miss. Returning 1,600 rows over AJAX is
     * fine for download but noisy for a UI table. 500 rows is enough to
     * diagnose any systematic pattern; the counts are always exact.
     */
    public static function delivery_scorecard(): void {
        self::verify(); // nonce (mealsdb_migration_nonce) + manage_options

        // Read the raw CSV without sanitize_text_field — that function collapses
        // newlines, which would reduce the entire CSV to a single unparseable line.
        $csv = isset( $_POST['csv'] ) ? wp_unslash( (string) $_POST['csv'] ) : '';

        $ground_truth = MealsDB_Delivery_Date_Scorecard::parse_csv( $csv );

        if ( empty( $ground_truth ) ) {
            wp_send_json_error( [ 'message' => __( 'No valid order,date rows found.', 'meals-db' ) ] );
            return;
        }

        $res = MealsDB_Delivery_Date_Scorecard::score( $ground_truth );

        // Cap the misses array at 500 for the response; keep all counts exact.
        $all_misses        = $res['misses'];
        $misses_total      = count( $all_misses );
        $res['misses']     = array_slice( $all_misses, 0, 500 );
        $res['misses_shown'] = count( $res['misses'] );
        $res['misses_total'] = $misses_total;

        wp_send_json_success( $res );
    }

    /**
     * Run a consolidated phase to completion server-side, accumulating
     * stats across chunks. Used by the legacy single-shot backfill
     * endpoints (backfill_allowances / backfill_addresses /
     * backfill_allocation_engine) so those buttons keep their original
     * "click once, runs the whole thing" behaviour while sharing the new
     * single implementation. The chunked UI uses run_consolidated_phase.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed> Accumulated stats, or ['error'=>...].
     */
    private static function drain_consolidated_phase( int $phase, bool $dry_run, array $args = [] ): array {
        $offset    = 0;
        $totals    = [];
        $guard     = 0;
        $max_loops = 100000; // hard stop against an unterminating phase

        do {
            $result = MealsDB_Migration_Consolidated::run_phase( $phase, $offset, $dry_run, $args );

            if ( isset( $result['error'] ) ) {
                return $result;
            }

            if ( isset( $result['stats'] ) && is_array( $result['stats'] ) ) {
                foreach ( $result['stats'] as $k => $v ) {
                    $totals[ $k ] = ( $totals[ $k ] ?? 0 ) + (int) $v;
                }
            }

            $offset = (int) ( $result['offset'] ?? ( $offset + 1 ) );
            $guard++;
        } while ( empty( $result['complete'] ) && $guard < $max_loops );

        return $totals;
    }
}
