<?php
/**
 * GUI-SLIP-RANGE — the by-zone/date-range slip path filters by computed
 * DELIVERY OCCURRENCE within [start, end], not by order CREATION date.
 *
 * MAJ-6 fixed the single-date path (get_orders_for_delivery_date) but left the
 * range path (get_orders_for_range) on the raw creation-date query, so a
 * range slip pulled every order CREATED in the window and printed scattered
 * delivery dates. The fix routes BOTH paths through one occurrence filter
 * (get_orders_for_delivery_range); single-date is the degenerate range [D, D].
 *
 * Calendar anchors (2026):
 *   2026-05-15 Fri  2026-05-21 Thu  2026-05-25 Mon  2026-05-28 Thu
 *   2026-06-01 Mon  2026-06-04 Thu
 *
 * Run: php tests/test-slip-range-occurrence.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';

// Creation-window fake: returns canned candidate orders whose creation date
// falls in the requested window, exactly like the real
// get_orders_with_items_for_users creation-date pre-filter. This lets us
// exercise the occurrence filter (which runs in PHP on top of the window)
// without a real wpdb.
if (!class_exists('FakeOrderQueryForRange')) {
    class FakeOrderQueryForRange extends MealsDB_WC_Order_Query {
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
            $ids = array_flip(array_map('intval', $wp_user_ids));
            $out = [];
            foreach ($this->rows as $row) {
                if (!isset($ids[(int) ($row['wp_user_id'] ?? 0)])) {
                    continue;
                }
                $day = substr((string) ($row['date_created_gmt'] ?? ''), 0, 10);
                if ($day >= $start_date && $day <= $end_date) {
                    $out[] = $row;
                }
            }
            return $out;
        }
    }
}

$order_ids = static function (array $orders): array {
    $ids = array_map(static fn($o) => (int) $o['order_id'], $orders);
    sort($ids);
    return $ids;
};

// client 1: weekly Thursday; client 2: biweekly Thursday.
$clients = [
    1 => ['client_id' => 1, 'wp_user_id' => 1, 'delivery_day' => 'Thursday', 'delivery_frequency' => 1],
    2 => ['client_id' => 2, 'wp_user_id' => 2, 'delivery_day' => 'Thursday', 'delivery_frequency' => 2],
];

// O1: created Mon 05-25 -> occ Thu 05-28 (IN range)
// O2: created Mon 06-01 -> occ Thu 06-04 (IN range)
// O3: created Thu 05-21 -> occ Thu 05-21 (OUT — before range start; the
//     "November-occurrence" analog. Lands inside the candidate creation
//     window but is dropped by the occurrence filter.)
// O4: created Fri 05-15 -> biweekly roll-forward -> occ Thu 05-28 (IN range,
//     order-ahead: created well before start, delivered in-range).
$rows = [
    ['order_id' => 101, 'wp_user_id' => 1, 'date_created_gmt' => '2026-05-25 09:00:00', 'items' => []],
    ['order_id' => 102, 'wp_user_id' => 1, 'date_created_gmt' => '2026-06-01 09:00:00', 'items' => []],
    ['order_id' => 103, 'wp_user_id' => 1, 'date_created_gmt' => '2026-05-21 09:00:00', 'items' => []],
    ['order_id' => 104, 'wp_user_id' => 2, 'date_created_gmt' => '2026-05-15 09:00:00', 'items' => []],
];

$fake = new FakeOrderQueryForRange();
$fake->rows = $rows;
$gen = new MealsDB_Delivery_Slip_Generator($fake);

// -----------------------------------------------------------------------
// T-1 HEADLINE (the bug): a range slip for [05-28, 06-04] contains ONLY
// orders whose delivery occurrence is in range. O3 (occ 05-21) must be
// absent even though its CREATION date sits inside the candidate window.
// -----------------------------------------------------------------------
$in_range = $gen->get_orders_for_delivery_range($clients, '2026-05-28', '2026-06-04');
pdf_slip_assert_equal(
    [101, 102, 104],
    $order_ids($in_range),
    'T-1: range slip keeps only in-range delivery occurrences (O3 @ 05-21 dropped)'
);
$has_out_of_range = false;
foreach ($in_range as $o) {
    $occ = MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order(
        (string) $o['date_created_gmt'],
        $clients[(int) $o['wp_user_id']]
    );
    if ($occ < '2026-05-28' || $occ > '2026-06-04') {
        $has_out_of_range = true;
    }
}
pdf_slip_assert_true(!$has_out_of_range, 'T-1: no order on the slip delivers outside [05-28, 06-04]');

// -----------------------------------------------------------------------
// T-2 single-date path unchanged: still exactly the occurrence-==-D orders.
// D = 05-28 => O1 (05-28) + O4 (05-28). O2 (06-04) and O3 (05-21) excluded.
// -----------------------------------------------------------------------
$single = $gen->get_orders_for_delivery_date($clients, '2026-05-28');
pdf_slip_assert_equal(
    [101, 104],
    $order_ids($single),
    'T-2: single-date slip returns exactly occurrence==D orders'
);

// -----------------------------------------------------------------------
// T-3 shared logic: single-date [D] and the range [D, D] must agree, so the
// two paths can never drift again.
// -----------------------------------------------------------------------
$range_dd = $gen->get_orders_for_delivery_range($clients, '2026-05-28', '2026-05-28');
pdf_slip_assert_equal(
    $order_ids($single),
    $order_ids($range_dd),
    'T-3: single-date == range [D, D] (shared occurrence filter)'
);

// -----------------------------------------------------------------------
// T-4 order-ahead within range: O4 was created Fri 05-15 — well before the
// 05-28 range start — but its biweekly roll-forward delivers it Thu 05-28,
// in range. The widened candidate window (start - max_freq*7) must reach back
// far enough to include it.
// -----------------------------------------------------------------------
pdf_slip_assert_true(
    in_array(104, $order_ids($in_range), true),
    'T-4: order-ahead order created before start but delivered in-range IS included'
);
pdf_slip_assert_equal(
    '2026-05-28',
    MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order('2026-05-15 09:00:00', $clients[2]),
    'T-4: biweekly 05-15 order rolls forward to 05-28 occurrence'
);

// -----------------------------------------------------------------------
// T-5 creation-date query intact: get_orders_with_items_for_users (creation
// basis) is unchanged for report/reconciliation callers — it still filters
// on date_created_gmt and ignores delivery occurrence. The range path
// get_orders_for_range delegates straight to it.
// -----------------------------------------------------------------------
global $wpdb;
$query = new MealsDB_WC_Order_Query($wpdb);
$query->get_orders_with_items_for_users([1], '2026-05-01', '2026-05-31');
pdf_slip_assert_true(
    strpos((string) $wpdb->_last_query, 'date_created_gmt') !== false,
    'T-5: get_orders_with_items_for_users still filters on date_created_gmt'
);

pdf_slip_finish();
