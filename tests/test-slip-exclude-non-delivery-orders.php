<?php
/**
 * DIRECTIVE slips-exclude-non-delivery-orders — slip generation must EXCLUDE
 * orders that carry no deliverable line item.
 *
 * The monthly contribution-reset orders (created midnight Atlantic on the 1st,
 * wc-completed, $0.00, ONE contribution line item, NO meals) were rendered as
 * full packer/driver slips — ~60-100 phantom slips a month handed to the packer
 * for a client receiving nothing.
 *
 * DENYLIST rule (Zak's decision): an order is kept on the slip if it has at
 * least one line item whose product is NOT a fee/contribution/overage product.
 * We deliberately do NOT allowlist product_type='meal' — an allowlist would
 * DROP an order whose product isn't classified in meals_products (a one-off SKU,
 * a mis-seeded product), i.e. it could hide a real delivery. The denylist only
 * ever removes an order whose line items are ENTIRELY fees/overage, which is
 * exactly the reset signature. "When unsure, keep it on the slip" is the safe
 * error for a packer.
 *
 * The filter is content-based, NOT total- or status-based:
 *   - a legitimate delivery fully covered by allowance + contribution can total
 *     $0.00 and must still print;
 *   - wc-completed is a normal status the resets merely happen to use.
 *
 * Calendar anchors (2026): 2026-05-25 Mon, 2026-06-01 Mon, 2026-06-04 Thu.
 * All orders below are created Mon 2026-05-25 so their following-week Thursday
 * occurrence is 2026-06-04 (inside the test range) — isolating the NEW deliverable
 * filter from the occurrence filter.
 *
 * Excluded (denylist) product ids resolve through the CONFIGURED ids:
 *   fees:   client_contribution 5675, delivery_fee 4122
 *   overage: mains 5056, nontax_sides 5059, taxable_sides 5180
 * Deliverable products in this test: meal 100, side 200.
 *
 * Run: php tests/test-slip-exclude-non-delivery-orders.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';

if (!class_exists('FakeOrderQueryForDeliverableFilter')) {
    class FakeOrderQueryForDeliverableFilter extends MealsDB_WC_Order_Query {
        /** @var array<int, array<string,mixed>> creation-window candidates */
        public array $rows = [];
        /** @var array<int, array<string,mixed>> rows carrying a delivery_date_override */
        public array $override_rows = [];

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
            array $exclude_statuses = []
        ): array {
            $ids = array_flip(array_map('intval', $wp_user_ids));
            $out = [];
            foreach ($this->override_rows as $row) {
                if (!isset($ids[(int) ($row['wp_user_id'] ?? 0)])) {
                    continue;
                }
                $ov = (string) ($row['delivery_date_override'] ?? '');
                if ($ov >= $start_date && $ov <= $end_date) {
                    $out[] = $row;
                }
            }
            return $out;
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

// Helper: an order row with the given line-item product ids.
$order = static function (int $id, array $product_ids, array $extra = []): array {
    $items = array_map(
        static fn(int $pid) => ['wc_product_id' => $pid, 'quantity' => 1],
        $product_ids
    );
    return array_merge([
        'order_id'         => $id,
        'wp_user_id'       => 1,
        'date_created_gmt' => '2026-05-25 09:00:00', // -> occ Thu 2026-06-04 (in range)
        'items'            => $items,
    ], $extra);
};

$clients = [
    1 => ['client_id' => 1, 'wp_user_id' => 1, 'delivery_day' => 'Thursday', 'delivery_frequency' => 1],
];

// -----------------------------------------------------------------------
// Creation-window candidates.
//   101 meal only               -> KEEP
//   102 contribution only (5675)-> DROP (reset signature)
//   103 side only (200)         -> KEEP (denylist: sides aren't excluded)
//   104 no items at all         -> DROP (nothing to pack)
//   105 contribution + meal     -> KEEP (has a deliverable)
//   106 overage only (5056)     -> DROP
// -----------------------------------------------------------------------
$fake = new FakeOrderQueryForDeliverableFilter();
$fake->rows = [
    $order(101, [100]),
    $order(102, [5675]),
    $order(103, [200]),
    $order(104, []),
    $order(105, [5675, 100]),
    $order(106, [5056]),
];

$gen = new MealsDB_Delivery_Slip_Generator($fake);
$result = $gen->get_orders_for_delivery_range($clients, '2026-05-28', '2026-06-04');

pdf_slip_assert_equal(
    [101, 103, 105],
    $order_ids($result),
    'HEADLINE: only orders with a deliverable line item survive (meal 101, side 103, mixed 105; reset 102 / empty 104 / overage 106 dropped)'
);

// Regression checks pulled out explicitly for clarity of intent.
pdf_slip_assert_true(!in_array(102, $order_ids($result), true),
    'contribution-only reset order (5675) is excluded');
pdf_slip_assert_true(!in_array(104, $order_ids($result), true),
    'order with zero line items is excluded');
pdf_slip_assert_true(!in_array(106, $order_ids($result), true),
    'overage-only order (5056) is excluded');
pdf_slip_assert_true(in_array(103, $order_ids($result), true),
    'sides-only order is KEPT (denylist does not require a main)');
pdf_slip_assert_true(in_array(105, $order_ids($result), true),
    'mixed contribution+meal order is KEPT (at least one deliverable)');

// -----------------------------------------------------------------------
// Regression: value is NOT the test. An order with a real meal item but a
// $0.00 total (allowance+contribution fully covers it) still prints. The
// filter never inspects total_amount, so passing total 0.0 must not drop it.
// -----------------------------------------------------------------------
$fake_zero = new FakeOrderQueryForDeliverableFilter();
$fake_zero->rows = [ $order(201, [100], ['total_amount' => 0.0]) ];
$gen_zero = new MealsDB_Delivery_Slip_Generator($fake_zero);
$zero_res = $gen_zero->get_orders_for_delivery_range($clients, '2026-05-28', '2026-06-04');
pdf_slip_assert_equal(
    [201],
    $order_ids($zero_res),
    'REGRESSION: $0.00 order WITH a meal line item still produces a slip (contents, not value)'
);

// -----------------------------------------------------------------------
// Override path must be filtered too: an operator-set _delivery_date on a
// RESET order must not force it onto a slip; but an override on a REAL order
// still selects it.
//   107 contribution only, override 2026-05-28 (in range) -> DROP
//   108 meal only,         override 2026-05-28 (in range) -> KEEP
// -----------------------------------------------------------------------
$fake_ov = new FakeOrderQueryForDeliverableFilter();
$fake_ov->override_rows = [
    $order(107, [5675], ['delivery_date_override' => '2026-05-28']),
    $order(108, [100],  ['delivery_date_override' => '2026-05-28']),
];
$gen_ov = new MealsDB_Delivery_Slip_Generator($fake_ov);
$ov_res = $gen_ov->get_orders_for_delivery_range($clients, '2026-05-28', '2026-06-04');
pdf_slip_assert_equal(
    [108],
    $order_ids($ov_res),
    'OVERRIDE: a _delivery_date override cannot force a contribution-only reset (107) onto a slip; a real order (108) still selected'
);

// -----------------------------------------------------------------------
// CONFIGURED ids, not seed constants: re-point the overage "mains" id via
// the option the slip layer already honours. An order whose only item is the
// RE-POINTED id must be excluded; an order whose only item is the now-unused
// SEED id (5056) becomes deliverable-looking and is KEPT — proving resolution
// reads the configured value, not the hardcoded seed.
// -----------------------------------------------------------------------
$GLOBALS['_pdf_slip_options']['mealsdb_overage_product_ids'] = ['mains' => 9999];
$fake_cfg = new FakeOrderQueryForDeliverableFilter();
$fake_cfg->rows = [
    $order(301, [9999]), // re-pointed overage main -> DROP
    $order(302, [5056]), // former seed, no longer configured -> KEEP
];
$gen_cfg = new MealsDB_Delivery_Slip_Generator($fake_cfg);
$cfg_res = $gen_cfg->get_orders_for_delivery_range($clients, '2026-05-28', '2026-06-04');
pdf_slip_assert_equal(
    [302],
    $order_ids($cfg_res),
    'CONFIGURED: re-pointed overage id (9999) excluded; stale seed (5056) treated as deliverable — resolution uses configured ids'
);
unset($GLOBALS['_pdf_slip_options']['mealsdb_overage_product_ids']);

pdf_slip_finish();
