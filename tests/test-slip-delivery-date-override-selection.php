<?php
/**
 * Override-aware slip selection — an operator-set _delivery_date meta
 * decides WHICH slip an order lands on, not just the printed header
 * (DIRECTIVE-manual-delivery-date-override.md, Section D).
 *
 * Rule 9 (meta wins, occurrence otherwise): an order with a well-formed
 * override belongs to THAT date — included iff the override is in range,
 * excluded when overridden OUT even if its computed occurrence is in
 * range. Orders without the meta keep the existing occurrence filter
 * byte-identically (MAJ-6 / GUI-SLIP-RANGE regression guard).
 *
 * Rule 10 (candidate window): an override can move delivery arbitrarily
 * far from creation, so overridden orders are fetched by a meta query,
 * not the creation-date pre-filter window.
 *
 * Rule 11 (client inclusion): the owner of an overridden order joins the
 * client set even when the slip date is not their delivery_day weekday
 * (always true for the Saturday edge case).
 *
 * Calendar anchors (2026):
 *   05-04 Mon  05-07 Thu  05-15 Fri  05-21 Thu  05-25 Mon  05-28 Thu
 *   05-29 Fri  05-30 Sat  06-01 Mon  06-04 Thu  06-10 Wed
 *
 * Run: php tests/test-slip-delivery-date-override-selection.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';

// Fake order query: canned creation-window candidates (like the real
// creation-date pre-filter) PLUS canned _delivery_date overrides served
// through the Section D meta-query methods.
if (!class_exists('FakeOrderQueryWithOverrides')) {
    class FakeOrderQueryWithOverrides extends MealsDB_WC_Order_Query {
        public array $rows = [];
        /** @var array<int, string> order_id => Y-m-d override */
        public array $overrides = [];

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

        public function get_orders_with_items_for_users_by_delivery_date(
            array $wp_user_ids,
            string $start_date,
            string $end_date,
            array $exclude_statuses = ['wc-cancelled', 'wc-on-hold', 'wc-draft', 'draft', 'wc-trash', 'trash']
        ): array {
            $ids = array_flip(array_map('intval', $wp_user_ids));
            $out = [];
            foreach ($this->rows as $row) {
                $oid = (int) ($row['order_id'] ?? 0);
                if (!isset($ids[(int) ($row['wp_user_id'] ?? 0)]) || !isset($this->overrides[$oid])) {
                    continue;
                }
                $ovr = $this->overrides[$oid];
                if ($ovr >= $start_date && $ovr <= $end_date) {
                    $row['delivery_date_override'] = $ovr;
                    $out[] = $row;
                }
            }
            return $out;
        }

        public function get_delivery_date_overrides(array $order_ids): array {
            $order_ids = array_map('intval', $order_ids);
            return array_intersect_key($this->overrides, array_flip($order_ids));
        }

        /** @var int[] canned owner uids for the client-inclusion query */
        public array $override_owner_uids = [];

        public function get_user_ids_with_delivery_date_override(string $start_date, string $end_date): array {
            return $this->override_owner_uids;
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

// O101 created 05-25 -> occ 05-28 (IN),  no override.
// O102 created 06-01 -> occ 06-04 (IN),  no override.
// O103 created 05-21 -> occ 05-21 (OUT), no override.
// O104 created 05-15 -> biweekly occ 05-28 (IN), no override.
// O105 created 05-04 -> occ 05-07 (OUT) AND created before the widened
//      candidate window (05-14 for range start 05-28, max_freq 2) —
//      override 05-29 pulls it IN. Reaches selection only via the meta
//      query (rule 10).
// O106 created 05-25 -> occ 05-28 (IN) but override 06-10 pushes it OUT
//      (rule 9 exclusion).
// O107 created 06-01 -> occ 06-04 (IN) but override 05-30 (a SATURDAY)
//      moves it within the range to a different day.
$rows = [
    ['order_id' => 101, 'wp_user_id' => 1, 'date_created_gmt' => '2026-05-25 09:00:00', 'items' => []],
    ['order_id' => 102, 'wp_user_id' => 1, 'date_created_gmt' => '2026-06-01 09:00:00', 'items' => []],
    ['order_id' => 103, 'wp_user_id' => 1, 'date_created_gmt' => '2026-05-21 09:00:00', 'items' => []],
    ['order_id' => 104, 'wp_user_id' => 2, 'date_created_gmt' => '2026-05-15 09:00:00', 'items' => []],
    ['order_id' => 105, 'wp_user_id' => 1, 'date_created_gmt' => '2026-05-04 09:00:00', 'items' => []],
    ['order_id' => 106, 'wp_user_id' => 1, 'date_created_gmt' => '2026-05-25 10:00:00', 'items' => []],
    ['order_id' => 107, 'wp_user_id' => 1, 'date_created_gmt' => '2026-06-01 10:00:00', 'items' => []],
];
$overrides = [
    105 => '2026-05-29',
    106 => '2026-06-10',
    107 => '2026-05-30',
];

$fake = new FakeOrderQueryWithOverrides();
$fake->rows = $rows;
$fake->overrides = $overrides;
$gen = new MealsDB_Delivery_Slip_Generator($fake);

// -----------------------------------------------------------------------
// T-1 (rules 9+10): range [05-28, 06-04] = the three untouched in-range
// orders + the two overridden-IN orders; overridden-OUT O106 absent.
// -----------------------------------------------------------------------
$in_range = $gen->get_orders_for_delivery_range($clients, '2026-05-28', '2026-06-04');
pdf_slip_assert_equal(
    [101, 102, 104, 105, 107],
    $order_ids($in_range),
    'T-1: overrides pull O105 in (from outside the creation window) and push O106 out'
);

// The selection basis carried on each order matches the override where
// one exists, so the slip prints the date the order was selected on.
$occ_by_id = [];
foreach ($in_range as $o) {
    $occ_by_id[(int) $o['order_id']] = (string) ($o['delivery_occurrence'] ?? '');
}
pdf_slip_assert_equal('2026-05-29', $occ_by_id[105], 'T-1: O105 selection basis = its override');
pdf_slip_assert_equal('2026-05-30', $occ_by_id[107], 'T-1: O107 selection basis = its override');
pdf_slip_assert_equal('2026-05-28', $occ_by_id[101], 'T-1: non-overridden O101 keeps computed occurrence');

// -----------------------------------------------------------------------
// T-2 (rule 9, exactly-one-slip): each overridden order appears on its
// override date's slip and ONLY there.
// -----------------------------------------------------------------------
pdf_slip_assert_equal(
    [101, 104],
    $order_ids($gen->get_orders_for_delivery_date($clients, '2026-05-28')),
    'T-2: 05-28 slip = untouched occurrence orders only (O106 overridden off it, O107 not yet on it)'
);
pdf_slip_assert_equal(
    [105],
    $order_ids($gen->get_orders_for_delivery_date($clients, '2026-05-29')),
    'T-2: 05-29 slip = O105 via override'
);
pdf_slip_assert_equal(
    [107],
    $order_ids($gen->get_orders_for_delivery_date($clients, '2026-05-30')),
    'T-2: Saturday 05-30 slip = O107 via override'
);
pdf_slip_assert_equal(
    [102],
    $order_ids($gen->get_orders_for_delivery_date($clients, '2026-06-04')),
    'T-2: 06-04 slip no longer contains O107 (it moved to 05-30)'
);
// O106 didn't vanish — it moved: a slip FOR its override date 06-10
// contains exactly O106 (and nothing else; no occurrence lands there).
pdf_slip_assert_equal(
    [106],
    $order_ids($gen->get_orders_for_delivery_date($clients, '2026-06-10')),
    'T-2: O106 lands on its override date 06-10 and only there'
);

// -----------------------------------------------------------------------
// T-3 (regression, case e): with no overrides at all, selection is
// byte-identical to the pre-change occurrence behaviour.
// -----------------------------------------------------------------------
$fake_clean = new FakeOrderQueryWithOverrides();
$fake_clean->rows = array_slice($rows, 0, 4); // O101..O104, the original scenario
$fake_clean->overrides = [];
$gen_clean = new MealsDB_Delivery_Slip_Generator($fake_clean);
pdf_slip_assert_equal(
    [101, 102, 104],
    $order_ids($gen_clean->get_orders_for_delivery_range($clients, '2026-05-28', '2026-06-04')),
    'T-3: no overrides -> identical to the occurrence-only result'
);
pdf_slip_assert_equal(
    [101, 104],
    $order_ids($gen_clean->get_orders_for_delivery_date($clients, '2026-05-28')),
    'T-3: single-date path unchanged without overrides'
);

// -----------------------------------------------------------------------
// T-4 (rule 11): get_clients_for_delivery_date() additionally includes
// the owners of overridden orders regardless of delivery_day weekday.
// 2026-05-30 is a Saturday — NO client matches by weekday, so uid 55
// (a Wednesday client with an overridden order) must arrive via the
// override-owner clause.
// -----------------------------------------------------------------------
global $wpdb;
$wpdb->get_results_handler = function ($query, $args) {
    if (stripos($query, 'wp_user_id IN') !== false) {
        return [[
            'client_id' => 9, 'wp_user_id' => 55, 'delivery_initials' => 'ABC',
            'delivery_area_zone' => 'Z1', 'delivery_area_name' => 'Zone 1',
            'delivery_city' => 'Moncton', 'delivery_street_name' => 'Main',
            'client_type' => 'Private', 'delivery_fee' => 10.0, 'payment_method' => 'cash',
            'delivery_day' => 'wednesday', 'delivery_frequency' => 1,
        ]];
    }
    return []; // weekday-only query: Saturday matches no client
};

$fake_owner = new FakeOrderQueryWithOverrides();
$fake_owner->override_owner_uids = [55];
$gen_owner = new MealsDB_Delivery_Slip_Generator($fake_owner);
$sat_clients = $gen_owner->get_clients_for_delivery_date('2026-05-30');
pdf_slip_assert_true(
    isset($sat_clients[55]),
    'T-4: Saturday slip client set includes the override owner despite delivery_day=wednesday'
);

// With no override owners the query must NOT grow an IN clause (the
// handler above returns [] for it, so a leaked clause would also fail).
$fake_no_owner = new FakeOrderQueryWithOverrides();
$fake_no_owner->override_owner_uids = [];
$gen_no_owner = new MealsDB_Delivery_Slip_Generator($fake_no_owner);
$plain_clients = $gen_no_owner->get_clients_for_delivery_date('2026-05-30');
pdf_slip_assert_equal([], $plain_clients, 'T-4: no override owners -> plain weekday query, no clients on a Saturday');
pdf_slip_assert_true(
    stripos((string) $wpdb->_last_query, 'wp_user_id IN') === false,
    'T-4: no override owners -> the SQL has no wp_user_id IN clause'
);

pdf_slip_finish();
