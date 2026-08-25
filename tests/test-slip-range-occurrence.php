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

// DIRECTIVE delivery-date-next-week-rule: occurrence = client's delivery weekday
// in the calendar week FOLLOWING the order date (frequency ignored).
// Following-Monday computation: next Monday = order_date + (8 - ISO_dow); Thu = Mon + 3.
//
// O1: created Mon 05-25 (ISO=1) -> next Mon 06-01 -> Thu 06-04 (IN range)
// O2: created Mon 06-01 (ISO=1) -> next Mon 06-08 -> Thu 06-11 (OUT — after range end)
// O3: created Thu 05-21 (ISO=4) -> next Mon 05-25 -> Thu 05-28 (IN range)
// O4: created Fri 05-15 (ISO=5, biweekly — frequency IGNORED) -> next Mon 05-18
//     -> Thu 05-21 (OUT — before range start).
//     Under the old rule O4 biweekly-rolled to 05-28 (IN); the new rule ignores
//     frequency so O4 lands on 05-21 and falls out of the range.
// Candidate window: start_date - 14 days = 05-28 - 14 = 05-14.
//     All four orders are in [05-14, 06-04]; the occurrence filter below narrows them.
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
// T-1 HEADLINE: a range slip for [05-28, 06-04] contains ONLY orders whose
// delivery occurrence is in range.
//   IN:  O1 (occ 06-04), O3 (occ 05-28).
//   OUT: O2 (occ 06-11 — after range end), O4 (occ 05-21 — before range start).
// O3 is now IN (under the old rule it was OUT because it was considered
// "same-week as creation"; under the new rule 05-21 Thu -> next week 05-28).
// O4 is now OUT (under the old rule biweekly rolled it to 05-28; the new rule
// ignores frequency so it stays on 05-21).
// -----------------------------------------------------------------------
$in_range = $gen->get_orders_for_delivery_range($clients, '2026-05-28', '2026-06-04');
pdf_slip_assert_equal(
    [101, 103],
    $order_ids($in_range),
    'T-1: range slip keeps only in-range delivery occurrences (O1@06-04, O3@05-28 IN; O2@06-11, O4@05-21 OUT)'
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
// T-2 single-date path: exactly the occurrence==D orders.
// D = 05-28: O3 occ=05-28 IN. O1 occ=06-04, O2 occ=06-11, O4 occ=05-21 all OUT.
// (Under the old rule O1 and O4 were both on 05-28; under the new rule O1
// moved to 06-04 and O4 to 05-21.)
// -----------------------------------------------------------------------
$single = $gen->get_orders_for_delivery_date($clients, '2026-05-28');
pdf_slip_assert_equal(
    [103],
    $order_ids($single),
    'T-2: single-date slip returns exactly occurrence==D orders (D=05-28: only O3)'
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
// T-4 frequency invariant: under the new rule, delivery_frequency is NOT
// read when computing occurrence. A biweekly client and a weekly client with
// the same delivery_day and the same order date get the SAME following-week
// occurrence. O4 (biweekly, created Fri 05-15) now gets occurrence 05-21
// (next Mon from Fri = 05-18, +Thu = 05-21) — the SAME as a hypothetical
// weekly client created on 05-15. The biweekly roll-forward to 05-28 no
// longer happens. O4 therefore falls OUTSIDE the range [05-28, 06-04].
// -----------------------------------------------------------------------
$weekly_client_same_day   = ['client_id' => 3, 'wp_user_id' => 3, 'delivery_day' => 'Thursday', 'delivery_frequency' => 1];
$biweekly_client_same_day = ['client_id' => 2, 'wp_user_id' => 2, 'delivery_day' => 'Thursday', 'delivery_frequency' => 2];
pdf_slip_assert_equal(
    MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order('2026-05-15 09:00:00', $weekly_client_same_day),
    MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order('2026-05-15 09:00:00', $biweekly_client_same_day),
    'T-4: frequency ignored — weekly and biweekly Thursday clients get identical following-week occurrence'
);
pdf_slip_assert_equal(
    '2026-05-21',
    MealsDB_Delivery_Slip_Generator::delivery_occurrence_for_order('2026-05-15 09:00:00', $clients[2]),
    'T-4: Fri 05-15 order -> next Mon 05-18 -> Thu 05-21 (not 05-28; frequency no longer rolls forward)'
);
pdf_slip_assert_true(
    !in_array(104, $order_ids($in_range), true),
    'T-4: O4 (occ 05-21) is outside range [05-28, 06-04] — frequency-roll no longer brings it in'
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
