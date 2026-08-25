<?php
/**
 * MAJ-6 — delivery slips select orders by DELIVERY date, not CREATION date.
 *
 * Pins the single occurrence/cutoff rule
 * (MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order) and the
 * delivery-basis order query (get_orders_for_delivery_date), and guards that
 * the creation-date query (MealsDB_WC_Order_Query::get_orders_for_users) is
 * left untouched for the reporting/reconciliation path.
 *
 * DIRECTIVE delivery-date-next-week-rule: occurrence = the client's delivery
 * weekday in the calendar week FOLLOWING the order date (Monday-based ISO
 * weeks). Frequency has no effect — both weekly and biweekly clients with the
 * same order date and delivery_day produce the same occurrence.
 *
 * Calendar anchors (2026):
 *   2026-05-25 Mon  2026-05-28 Thu  2026-05-29 Fri
 *   2026-06-01 Mon (next week's Monday after 2026-05-25..05-29)
 *   2026-06-04 Thu (next week's Thursday — the expected occurrence for all
 *                   Thursday-delivery orders created 2026-05-25..05-29)
 *
 * Run: php tests/test-slips-delivery-date.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';

// A delivery-basis fake: returns canned candidate orders filtered to the
// requested creation-date window, so we can exercise get_orders_for_delivery_date
// without a real wpdb.
if (!class_exists('FakeOrderQueryForDelivery')) {
    class FakeOrderQueryForDelivery extends MealsDB_WC_Order_Query {
        public array $rows = [];

        public function __construct() {
            // Skip parent constructor — no real wpdb wiring needed.
        }

        public function get_orders_with_items_for_users(
            array $wp_user_ids,
            string $start_date,
            string $end_date,
            array $exclude_statuses = ['wc-cancelled', 'wc-on-hold', 'wc-draft', 'draft', 'wc-trash', 'trash']
        ): array {
            $out = [];
            foreach ($this->rows as $row) {
                $day = substr((string) ($row['date_created_gmt'] ?? ''), 0, 10);
                if ($day >= $start_date && $day <= $end_date) {
                    $out[] = $row;
                }
            }
            return $out;
        }

        // Override-aware selection (Section D) queries these too; this
        // scenario has no _delivery_date overrides, so they return empty
        // (occurrence-only behaviour, which is what this test pins).
        public function get_orders_with_items_for_users_by_delivery_date(
            array $wp_user_ids,
            string $start_date,
            string $end_date,
            array $exclude_statuses = []
        ): array {
            return [];
        }

        public function get_delivery_date_overrides(array $order_ids): array {
            return [];
        }

        public function get_user_ids_with_delivery_date_override(string $start_date, string $end_date): array {
            return [];
        }
    }
}

$occ = static function (string $created, array $client): ?string {
    return MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order($created, $client);
};

$thursday_weekly   = ['delivery_day' => 'Thursday', 'delivery_frequency' => 1];
$thursday_biweekly = ['delivery_day' => 'Thursday', 'delivery_frequency' => 2];

// -----------------------------------------------------------------------
// T-1 HEADLINE: order created Monday maps to next week's Thursday slip.
// 2026-05-25 Mon: next Monday = 2026-06-01; +3 days = 2026-06-04 Thu.
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    '2026-06-04',
    $occ('2026-05-25 09:00:00', $thursday_weekly),
    'T-1: Monday order for Thursday client => next week\'s Thursday (2026-06-04)'
);

// Integration: prove it through get_orders_for_delivery_date — the order
// shows on the following-Thursday slip and is ABSENT from the Monday
// (creation) slip and the same-week Thursday slip.
$clients = [
    1 => [
        'client_id'          => 1,
        'wp_user_id'         => 1,
        'delivery_day'       => 'Thursday',
        'delivery_frequency' => 1,
    ],
];
$order_row = [
    'order_id'         => 999,
    'wp_user_id'       => 1,
    'date_created_gmt' => '2026-05-25 09:00:00', // Monday
    'items'            => [],
];

$fake_oq   = new FakeOrderQueryForDelivery();
$fake_oq->rows = [$order_row];
$slip_gen  = new MealsDB_Delivery_Slip_Generator($fake_oq);

$on_next_thursday = $slip_gen->get_orders_for_delivery_date($clients, '2026-06-04');
pdf_slip_assert_equal(1, count($on_next_thursday), 'T-1: order appears on next-week Thursday delivery slip (2026-06-04)');

$on_monday = $slip_gen->get_orders_for_delivery_date($clients, '2026-05-25');
pdf_slip_assert_equal(0, count($on_monday), 'T-1: order does NOT appear on creation-day (Monday) slip');

$on_same_week_thursday = $slip_gen->get_orders_for_delivery_date($clients, '2026-05-28');
pdf_slip_assert_equal(0, count($on_same_week_thursday), 'T-1: order is ABSENT from same-week Thursday (2026-05-28) slip — occurrence is 2026-06-04, not 2026-05-28');

// -----------------------------------------------------------------------
// T-2: order created ON the delivery day maps to NEXT week's Thursday.
// 2026-05-28 Thu (ISO N=4): next Monday = 2026-06-01; +3 = 2026-06-04 Thu.
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    '2026-06-04',
    $occ('2026-05-28 11:00:00', $thursday_weekly),
    'T-2: Thursday order for Thursday client => next week\'s Thursday (2026-06-04)'
);

// -----------------------------------------------------------------------
// T-3: frequency does NOT change the result — weekly and biweekly clients
// with the same order date + delivery_day produce the same occurrence.
// 2026-05-29 Fri (ISO N=5): next Monday = 2026-06-01; +3 = 2026-06-04 Thu.
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    '2026-06-04',
    $occ('2026-05-29', $thursday_biweekly),
    'T-3: biweekly Friday order => next week\'s Thursday (2026-06-04)'
);
// Same order on weekly cadence — same result, proving frequency has no effect.
pdf_slip_assert_equal(
    '2026-06-04',
    $occ('2026-05-29', $thursday_weekly),
    'T-3: weekly Friday order => next week\'s Thursday (2026-06-04), identical to biweekly'
);

// -----------------------------------------------------------------------
// T-4: occurrence reads the stored delivery_day; blank delivery_day is
// handled gracefully (null — order falls out, no fatal).
// 2026-05-25 Mon => next Thursday = 2026-06-04.
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    '2026-06-04',
    $occ('2026-05-25', ['delivery_day' => 'Thursday']), // frequency omitted — irrelevant
    'T-4: occurrence computed from stored delivery_day (frequency key irrelevant)'
);
pdf_slip_assert_equal(
    null,
    $occ('2026-05-25', ['delivery_day' => '', 'delivery_frequency' => 1]),
    'T-4: blank delivery_day => null (no fatal)'
);
pdf_slip_assert_equal(
    null,
    $occ('2026-05-25', ['delivery_frequency' => 1]), // no delivery_day key at all
    'T-4: missing delivery_day key => null'
);

// -----------------------------------------------------------------------
// T-6: both morning-of and day-after produce NEXT week's Thursday — there
// is no same-week cutoff under the new rule.
// 2026-05-28 Thu => next Thursday 2026-06-04.
// 2026-05-29 Fri => next Thursday 2026-06-04 (same next-Monday anchor).
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    '2026-06-04',
    $occ('2026-05-28 06:00:00', $thursday_weekly),
    'T-6: morning-of delivery day => next week\'s Thursday (2026-06-04)'
);
pdf_slip_assert_equal(
    '2026-06-04',
    $occ('2026-05-29', $thursday_weekly),
    'T-6: day after delivery day => next week\'s Thursday (2026-06-04)'
);

// -----------------------------------------------------------------------
// T-7: guard — the creation-date query is unchanged and still filters
// date_created_gmt (reports/reconciliation path must not be repurposed).
// -----------------------------------------------------------------------
global $wpdb;
$query = new MealsDB_WC_Order_Query($wpdb);
$query->get_orders_for_users([1], '2026-05-01', '2026-05-31');
pdf_slip_assert_true(
    strpos((string) $wpdb->_last_query, 'date_created_gmt') !== false,
    'T-7: get_orders_for_users still filters on date_created_gmt'
);

pdf_slip_finish();
