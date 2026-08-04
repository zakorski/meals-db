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
 * Calendar anchors (2026):
 *   2026-05-24 Sun  2026-05-25 Mon  2026-05-28 Thu  2026-05-29 Fri
 *   2026-06-04 Thu (one week out)   2026-06-11 Thu (two weeks out)
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
// T-1 HEADLINE: order created Monday for a Thursday-delivery client maps to
// THAT Thursday's slip, not the creation (Monday) date.
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    '2026-05-28',
    $occ('2026-05-25 09:00:00', $thursday_weekly),
    'T-1: Monday order for Thursday client => Thursday occurrence (not creation day)'
);

// Integration: prove it through get_orders_for_delivery_date — the order
// shows on the Thursday slip and is ABSENT from the Monday (creation) slip.
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

$on_thursday = $slip_gen->get_orders_for_delivery_date($clients, '2026-05-28');
pdf_slip_assert_equal(1, count($on_thursday), 'T-1: order appears on Thursday delivery slip');

$on_monday = $slip_gen->get_orders_for_delivery_date($clients, '2026-05-25');
pdf_slip_assert_equal(0, count($on_monday), 'T-1: order does NOT appear on creation-day (Monday) slip');

// -----------------------------------------------------------------------
// T-2: order created ON the delivery day still rides that day's slip.
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    '2026-05-28',
    $occ('2026-05-28 11:00:00', $thursday_weekly),
    'T-2: Thursday order for Thursday client => same Thursday'
);

// -----------------------------------------------------------------------
// T-3: frequency respected — a biweekly client's late order rolls a FULL
// fortnight forward, not to the intervening weekly delivery day.
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    '2026-06-11',
    $occ('2026-05-29', $thursday_biweekly),
    'T-3: biweekly Friday order => fortnight out (2026-06-11)'
);
// Same order on a weekly cadence rolls only one week — proves frequency,
// not just "next weekday", drives the roll-forward.
pdf_slip_assert_equal(
    '2026-06-04',
    $occ('2026-05-29', $thursday_weekly),
    'T-3: weekly Friday order => one week out (2026-06-04), distinct from biweekly'
);

// -----------------------------------------------------------------------
// T-4: occurrence reads the stored delivery_day; blank delivery_day is
// handled gracefully (null — order falls out, no fatal).
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    '2026-05-28',
    $occ('2026-05-25', ['delivery_day' => 'Thursday']), // frequency omitted => weekly default
    'T-4: occurrence computed from stored delivery_day (frequency defaults to weekly)'
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
// T-6: cutoff boundary — created on the delivery day belongs to THAT day;
// the day after rolls forward.
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    '2026-05-28',
    $occ('2026-05-28 06:00:00', $thursday_weekly),
    'T-6: morning-of delivery day => that occurrence'
);
pdf_slip_assert_equal(
    '2026-06-04',
    $occ('2026-05-29', $thursday_weekly),
    'T-6: day after delivery day => rolls to next occurrence'
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
