<?php
/**
 * AJAX handler for the Settings tab.
 *
 * Handles saving plugin settings and generating encryption keys.
 *
 * @package MealsDB
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MealsDB_Ajax_Settings {

    public static function init(): void {
        add_action( 'wp_ajax_mealsdb_save_settings', [ self::class, 'save_settings' ] );
        add_action( 'wp_ajax_mealsdb_generate_encryption_key', [ self::class, 'generate_key' ] );
        add_action( 'wp_ajax_mealsdb_backfill_next_dates', [ self::class, 'backfill_next_dates' ] );
        add_action( 'wp_ajax_mealsdb_preview_private_backfill', [ self::class, 'preview_private_backfill' ] );
        add_action( 'wp_ajax_mealsdb_run_private_backfill', [ self::class, 'run_private_backfill' ] );
        add_action( 'wp_ajax_mealsdb_preview_private_deactivation', [ self::class, 'preview_private_deactivation' ] );
        add_action( 'wp_ajax_mealsdb_run_private_deactivation', [ self::class, 'run_private_deactivation' ] );
        add_action( 'wp_ajax_mealsdb_enrich_private_skeletons', [ self::class, 'enrich_private_skeletons' ] );
        add_action( 'wp_ajax_mealsdb_recalculate_allocations',  [ self::class, 'recalculate_allocations' ] );
    }

    /**
     * Preview WC users who would be promoted into meals_clients by the
     * Phase S backfill. Read-only — does not modify any rows.
     */
    public static function preview_private_backfill(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'settings_modify' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please try again later.', 'meals-db' ) ], 429 );
        }

        $lookback = isset( $_POST['lookback_months'] ) ? (int) $_POST['lookback_months'] : MealsDB_Migration_Consolidated::DEFAULT_LOOKBACK_MONTHS;
        $rows = MealsDB_Migration_Consolidated::private_preview( $lookback );
        wp_send_json_success( [
            'count' => count( $rows ),
            'rows'  => $rows,
        ] );
    }

    /**
     * Promote every eligible user returned by the preview.
     */
    public static function run_private_backfill(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'settings_modify' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please try again later.', 'meals-db' ) ], 429 );
        }

        $lookback = isset( $_POST['lookback_months'] ) ? (int) $_POST['lookback_months'] : MealsDB_Migration_Consolidated::DEFAULT_LOOKBACK_MONTHS;
        $stats = MealsDB_Migration_Consolidated::private_promote_all( $lookback, false );
        wp_send_json_success( $stats );
    }

    /**
     * Preview the one-time deactivation sweep: Private meals_clients
     * with no active WC orders in the lookback window.
     */
    public static function preview_private_deactivation(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'settings_modify' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please try again later.', 'meals-db' ) ], 429 );
        }

        $lookback = isset( $_POST['lookback_months'] ) ? (int) $_POST['lookback_months'] : MealsDB_Migration_Consolidated::DEFAULT_LOOKBACK_MONTHS;
        $rows = MealsDB_Migration_Consolidated::deactivation_sweep_preview( $lookback );
        wp_send_json_success( [
            'count' => count( $rows ),
            'rows'  => $rows,
        ] );
    }

    /**
     * Execute the deactivation sweep.
     */
    public static function run_private_deactivation(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'settings_modify' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please try again later.', 'meals-db' ) ], 429 );
        }

        $lookback = isset( $_POST['lookback_months'] ) ? (int) $_POST['lookback_months'] : MealsDB_Migration_Consolidated::DEFAULT_LOOKBACK_MONTHS;
        $stats = MealsDB_Migration_Consolidated::deactivation_sweep_run( $lookback );
        wp_send_json_success( $stats );
    }

    /**
     * Refill blank columns on existing Private skeleton rows from
     * usermeta + the user's most recent qualifying WC order. Pass
     * `dry_run=1` in the POST body to count without writing.
     */
    public static function enrich_private_skeletons(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'settings_modify' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please try again later.', 'meals-db' ) ], 429 );
        }

        $dry_run = ! empty( $_POST['dry_run'] );
        $stats = MealsDB_Migration_Consolidated::enrich_existing( $dry_run );
        wp_send_json_success( $stats );
    }

    /**
     * Run the one-time backfill that populates meals_clients.next_order_date
     * and next_delivery_date from wp_usermeta + the configured frequencies.
     */
    public static function backfill_next_dates(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'settings_modify' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please try again later.', 'meals-db' ) ], 429 );
        }

        $result = MealsDB_Migration_Consolidated::drain_phase_next_dates();
        // U03-migration-14: a chunk error surfaces as ['error'=>...] alongside
        // the partial stats — report it as a failure instead of success:true.
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }
        wp_send_json_success( $result );
    }

    public static function save_settings(): void {
        check_ajax_referer( 'mealsdb_settings_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'settings_modify' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please try again later.', 'meals-db' ) ], 429 );
        }

        // Merge into existing settings so other keys (and an existing
        // encryption_key when the user didn't submit a new one) are preserved.
        $existing = get_option( 'mealsdb_settings', [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        $submitted_key = sanitize_text_field( wp_unslash( $_POST['encryption_key'] ?? '' ) );
        $settings      = $existing;

        $key_rotated = false;
        if ( $submitted_key !== '' ) {
            if ( strpos( $submitted_key, 'base64:' ) !== 0 ) {
                wp_send_json_error( [ 'message' => 'Encryption key must start with "base64:" prefix.' ] );
            }

            $decoded = base64_decode( substr( $submitted_key, 7 ), true );
            if ( $decoded === false || strlen( $decoded ) !== 32 ) {
                wp_send_json_error( [ 'message' => 'Encryption key must decode to exactly 32 bytes (256 bits).' ] );
            }

            $key_rotated = ! isset( $existing['encryption_key'] )
                || $existing['encryption_key'] !== $submitted_key;
            $settings['encryption_key'] = $submitted_key;
        }
        // Empty input is treated as "no change" — the previous behaviour
        // silently cleared the key, which would render every encrypted
        // PII column unrecoverable on the next read.

        // Shadow mode flag. Stored explicitly so MealsDB_Shadow_Mode can
        // distinguish "operator turned it off" from "never set" (the latter
        // fails safe to ON). A present, unchecked checkbox submits '0'.
        $settings[MealsDB_Shadow_Mode::SETTING_KEY] =
            empty($_POST['shadow_mode']) ? '0' : '1';

        update_option( 'mealsdb_settings', $settings, false );

        // Audit encryption-key rotation. The key value is NOT logged
        // (it's the secret); only the fact of rotation and the
        // operator who triggered it. Key rotation is one of the most
        // operationally-significant events in the plugin — without
        // this audit row a rotation would leave no forensic trail
        // (directive 16 Pass A hardening gap).
        if ( $key_rotated && class_exists( 'MealsDB_Logger' ) ) {
            MealsDB_Logger::log(
                'encryption_key_rotated',
                get_current_user_id(),
                'encryption_key',
                '(redacted)',
                '(redacted)'
            );
        }

        // Save overage product IDs if provided. Validate each against
        // wc_get_product() — previously any positive integer was
        // accepted, so a typo could point the overage flow at a
        // non-existent product (wc_create_order::add_product then
        // quietly skips, leaving the operator wondering why overage
        // orders were empty) or at an unrelated product entirely.
        $overage_mains = intval( $_POST['overage_mains'] ?? 0 );
        $overage_tax   = intval( $_POST['overage_taxable_sides'] ?? 0 );
        $overage_nt    = intval( $_POST['overage_nontax_sides'] ?? 0 );
        if ( $overage_mains > 0 || $overage_tax > 0 || $overage_nt > 0 ) {
            $verify = static function ( int $pid ): int {
                if ( $pid <= 0 ) {
                    return 0;
                }
                if ( function_exists( 'wc_get_product' ) && ! wc_get_product( $pid ) ) {
                    return 0;
                }
                return $pid;
            };
            $overage_mains = $verify( $overage_mains );
            $overage_tax   = $verify( $overage_tax );
            $overage_nt    = $verify( $overage_nt );

            if ( $overage_mains === 0 && $overage_tax === 0 && $overage_nt === 0 ) {
                wp_send_json_error( [ 'message' => 'All provided overage product IDs refer to missing WooCommerce products.' ] );
            }

            update_option( 'mealsdb_overage_product_ids', [
                'mains'         => $overage_mains,
                'taxable_sides' => $overage_tax,
                'nontax_sides'  => $overage_nt,
            ], false );
        }

        // Save zone delivery schedule if provided (Phase Q).
        if ( isset( $_POST['zone_schedule'] ) && is_array( $_POST['zone_schedule'] ) ) {
            $valid_days = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ];
            $schedule   = [];
            foreach ( $_POST['zone_schedule'] as $zone_name => $config ) {
                $zone_name = sanitize_text_field( wp_unslash( $zone_name ) );
                $day       = sanitize_text_field( wp_unslash( $config['day'] ?? '' ) );
                $label     = sanitize_text_field( wp_unslash( $config['label'] ?? '' ) );

                if ( $zone_name !== '' && in_array( $day, $valid_days, true ) ) {
                    $schedule[ $zone_name ] = [ 'day' => $day, 'label' => $label ];
                }
            }
            if ( ! empty( $schedule ) ) {
                update_option( 'mealsdb_zone_delivery_schedule', $schedule, false );
            }
        }

        // Save per-field derived-value auto-correct toggles (directive
        // ITEM1-DERIVED). A present, unchecked checkbox submits nothing, so a
        // missing key means OFF. Only the known in-scope fields are honoured —
        // an unexpected POST key can't enable correction for some other column.
        $submitted_autocorrect = isset( $_POST['derived_autocorrect'] ) && is_array( $_POST['derived_autocorrect'] )
            ? $_POST['derived_autocorrect']
            : [];
        $autocorrect = [];
        foreach ( MealsDB_Derived_Value_Check::FIELDS as $field ) {
            $autocorrect[ $field ] = empty( $submitted_autocorrect[ $field ] ) ? 0 : 1;
        }
        update_option( MealsDB_Derived_Value_Audit::AUTOCORRECT_OPTION, $autocorrect, false );

        // Force config reload on next request
        MealsDB_Config::reset();

        wp_send_json_success( [ 'message' => 'Settings saved.' ] );
    }

    public static function generate_key(): void {
        check_ajax_referer( 'mealsdb_settings_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'settings_modify' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please try again later.', 'meals-db' ) ], 429 );
        }

        $bytes = random_bytes( 32 );
        $key   = 'base64:' . base64_encode( $bytes );

        wp_send_json_success( [ 'key' => $key ] );
    }

    /**
     * Data Ops "Recalculate Allocations": rebuild every dirty client-month
     * via MealsDB_Allocation_Rebuilder. Used when an operator wants to force
     * a recalculation without waiting for an invoice to do it scoped.
     */
    public static function recalculate_allocations(): void {
        check_ajax_referer( 'mealsdb_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'meals-db' ) ], 403 );
        }
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'settings_modify' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded. Please try again later.', 'meals-db' ) ], 429 );
        }

        $stats = ( new MealsDB_Allocation_Rebuilder() )->rebuild_all_dirty();
        wp_send_json_success( $stats );
    }
}
