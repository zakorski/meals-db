<?php
/**
 * Read-only integrity checker for client-profile DERIVED values (directive
 * ITEM1-DERIVED).
 *
 * Some client fields are not entered by hand — they are COMPUTED from other
 * fields and kept fresh by EVENTS, not by the passage of time:
 *
 *   - next_order_date    <- last_order_date + ordering_frequency + delivery_day
 *                           (recomputed by MealsDB_Client_Dates::advance_on_order
 *                            on every order)
 *   - next_delivery_date <- last_delivery_date + delivery_frequency + delivery_day
 *                           (recomputed by MealsDB_Client_Dates::mark_delivered
 *                            on "Mark as Delivered")
 *   - delivery_day       <- zone delivery schedule (delivery_area_name -> day)
 *
 * Because they are event-driven, they DRIFT from their inputs in two ways
 * (both confirmed in code):
 *   1. INPUT drift — the operator edits delivery_frequency / ordering_frequency
 *      / delivery_day in the client form, but the save path does NOT recompute
 *      the next_* dates. They stay computed from the OLD inputs until the next
 *      order/delivery event fires.
 *   2. DIRECT edit — next_order_date / next_delivery_date are themselves
 *      editable fields in the client form, with no check that the typed value
 *      is consistent with frequency + delivery_day.
 *
 * This class DETECTS that drift. It is PURE: it computes the expected value
 * and compares it to the stored value, and never writes. The nightly audit
 * job (MealsDB_Derived_Value_Audit) decides what to do with the mismatches
 * (flag by default; optionally auto-correct per-field).
 *
 * CRITICAL REUSE RULE
 * -------------------
 * expected_next_* MUST recompute via MealsDB_Date_Calculator::next_date() with
 * the SAME inputs the event handlers use — NOT a reimplementation of the
 * snapping logic. The whole point is to catch divergence from the canonical
 * computation; a second copy of the snap logic could itself drift and produce
 * false mismatches.
 *
 * "CAN'T COMPUTE" != MISMATCH
 * ---------------------------
 * A client with no frequency, no base date, or a blank delivery_day yields a
 * null expected value -> SKIP, not flag. Only a COMPUTABLE expected value that
 * differs from a NON-EMPTY stored value is a mismatch. (An incomplete profile
 * is a different concern from drift, and is out of scope here — we don't drown
 * the operator in "this client has no schedule yet" noise.)
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Derived_Value_Check {

    /** The in-scope derived fields, in check order. */
    public const FIELDS = ['next_order_date', 'next_delivery_date', 'delivery_day'];

    /**
     * Check one client's derived fields against their recomputed values.
     *
     * Pure: no DB writes. The caller supplies everything needed in $client:
     *   - next_order_date, next_delivery_date, delivery_day  (stored values)
     *   - ordering_frequency, delivery_frequency             (week multipliers)
     *   - last_order_date    (the order-event base; usermeta, injected by the
     *                         audit job — see expected_next_order)
     *   - last_delivery_date (the delivery-event base; the meals_clients COLUMN
     *                         that mark_delivered writes — see
     *                         expected_next_delivery)
     *   - delivery_area_name (for the zone -> day lookup)
     *
     * @param array<string,mixed> $client
     * @return array<int,array{field:string,stored:string,expected:string,reason:string}>
     *         One entry per mismatched field. Empty when in sync (or when no
     *         expected value can be computed).
     */
    public static function check_client(array $client): array {
        $mismatches = [];

        $stored_order = self::trimmed($client['next_order_date'] ?? null);
        if ($stored_order !== '') {
            $expected = self::expected_next_order($client);
            if ($expected !== null && $expected !== $stored_order) {
                $mismatches[] = [
                    'field'    => 'next_order_date',
                    'stored'   => $stored_order,
                    'expected' => $expected,
                    'reason'   => 'recomputed from last_order_date + ordering_frequency + delivery_day',
                ];
            }
        }

        $stored_delivery = self::trimmed($client['next_delivery_date'] ?? null);
        if ($stored_delivery !== '') {
            $expected = self::expected_next_delivery($client);
            if ($expected !== null && $expected !== $stored_delivery) {
                $mismatches[] = [
                    'field'    => 'next_delivery_date',
                    'stored'   => $stored_delivery,
                    'expected' => $expected,
                    'reason'   => 'recomputed from last_delivery_date + delivery_frequency + delivery_day',
                ];
            }
        }

        // delivery_day compares case-insensitively: the calculator and the
        // backfill both lower-case day names, so 'Thursday' and 'thursday'
        // are NOT drift.
        $stored_day = strtolower(self::trimmed($client['delivery_day'] ?? null));
        if ($stored_day !== '') {
            $expected = self::expected_delivery_day($client);
            if ($expected !== null && $expected !== $stored_day) {
                $mismatches[] = [
                    'field'    => 'delivery_day',
                    'stored'   => $stored_day,
                    'expected' => $expected,
                    'reason'   => 'zone delivery schedule maps this area to a different day',
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Expected next_order_date: last_order_date + ordering_frequency, snapped
     * to delivery_day, via the canonical calculator. Returns null when the
     * inputs are insufficient (no base date or no frequency) — "can't compute"
     * is not a mismatch.
     *
     * The base date is last_order_date, which the order-event path
     * (advance_on_order) writes to WP usermeta. The audit job injects it into
     * $client['last_order_date'] so this method stays pure (no usermeta read).
     */
    private static function expected_next_order(array $client): ?string {
        $base = self::trimmed($client['last_order_date'] ?? null);
        if ($base === '' || !class_exists('MealsDB_Date_Calculator')) {
            return null;
        }
        return MealsDB_Date_Calculator::next_date(
            $base,
            (int) ($client['ordering_frequency'] ?? 0),
            self::nullable($client['delivery_day'] ?? null)
        );
    }

    /**
     * Expected next_delivery_date: last_delivery_date + delivery_frequency,
     * snapped to delivery_day, via the canonical calculator.
     *
     * NOTE ON THE BASE DATE: the live delivery-event path
     * (MealsDB_Client_Dates::mark_delivered) uses the delivered date as the
     * base AND writes it to the meals_clients COLUMN `last_delivery_date`. So
     * after a delivery event the column equals the base, and re-deriving from
     * the column reproduces exactly what mark_delivered stored — no false
     * positives. (The directive's pseudo-code annotated this base as
     * "usermeta", but that contradicts mark_delivered, which writes the
     * COLUMN; only the one-time migration backfill reads a legacy usermeta
     * `last_delivery_date`. Using the column is what makes the check faithful
     * to the live event path — see the note in the implementation summary.)
     */
    private static function expected_next_delivery(array $client): ?string {
        $base = self::trimmed($client['last_delivery_date'] ?? null);
        if ($base === '' || !class_exists('MealsDB_Date_Calculator')) {
            return null;
        }
        return MealsDB_Date_Calculator::next_date(
            $base,
            (int) ($client['delivery_frequency'] ?? 0),
            self::nullable($client['delivery_day'] ?? null)
        );
    }

    /**
     * Expected delivery_day: the day the zone delivery schedule assigns to the
     * client's delivery_area_name. Mirrors the blank-fill backfill
     * (MealsDB_Migration_Consolidated::run_phase_delivery_day), which keys the
     * `mealsdb_zone_delivery_schedule` option by delivery_area_name and reads
     * config['day']. Lower-cased to match the stored-value comparison.
     *
     * Returns null (skip) when there is no schedule, the area is blank, the
     * area isn't in the schedule, or its config has no day — none of those are
     * drift.
     */
    private static function expected_delivery_day(array $client): ?string {
        $area = self::trimmed($client['delivery_area_name'] ?? null);
        if ($area === '') {
            return null;
        }
        $schedule = function_exists('get_option')
            ? get_option('mealsdb_zone_delivery_schedule', [])
            : [];
        if (!is_array($schedule) || empty($schedule[$area]) || !is_array($schedule[$area])) {
            return null;
        }
        $day = isset($schedule[$area]['day']) ? trim((string) $schedule[$area]['day']) : '';
        return $day === '' ? null : strtolower($day);
    }

    /** Coerce a possibly-null value to a trimmed string. */
    private static function trimmed($value): string {
        if ($value === null) {
            return '';
        }
        return trim((string) $value);
    }

    /** Trimmed string, or null when empty — for the calculator's day arg. */
    private static function nullable($value): ?string {
        $trimmed = self::trimmed($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
