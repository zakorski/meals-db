<?php
/**
 * GUI-SLIP-RANGE follow-up — the slip header prints the DELIVERY date, not the
 * order's creation date, for an order-ahead order.
 *
 * get_orders_for_delivery_range() carries the computed occurrence onto each
 * matched order as $order['delivery_occurrence']. resolve_delivery_date() must
 * prefer it over date_created_gmt, otherwise an order created 2026-05-15 but
 * delivered 2026-05-28 (correctly filtered onto the slip) still prints May 15
 * — and a content check of the range slip would show out-of-range dates even
 * after the filter fix. An explicit _delivery_date order meta still wins.
 *
 * Run: php tests/test-pdf-slip-delivery-occurrence-date.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

$GLOBALS['_pdf_slip_terms'] = [101 => [35]]; // 101 = main

$client  = [
    'client_id'          => 7,
    'wp_user_id'         => 42,
    'delivery_initials'  => 'WZN',
    'delivery_area_name' => 'Zone 4',
    'client_type'        => 'Private',
    'delivery_fee'       => 10.00,
    'payment_method'     => 'cash',
];
$clients = [42 => $client];

$base_order = [
    'order_id'         => 10543,
    'wp_user_id'       => 42,
    'date_created_gmt' => '2026-05-15 09:00:00', // Friday — order-ahead creation
    'items'            => [
        ['wc_product_id' => 101, 'quantity' => 14, 'order_item_name' => 'Beef Stew'],
    ],
];

// -----------------------------------------------------------------------
// T-1: occurrence carried -> slip prints the delivery (occurrence) date.
// -----------------------------------------------------------------------
$order = $base_order;
$order['delivery_occurrence'] = '2026-05-28'; // Thursday — the in-range delivery
$slips = pdf_slip_build_slips([$order], $clients, false);
pdf_slip_assert_equal(
    'Thursday, May 28, 2026',
    $slips[0]['delivery_date'],
    'T-1: slip prints the computed delivery occurrence, not the 2026-05-15 creation date'
);

// -----------------------------------------------------------------------
// T-2: no occurrence carried -> falls back to creation date (unchanged for
// any creation-basis caller that does not set the field).
// -----------------------------------------------------------------------
$slips_fallback = pdf_slip_build_slips([$base_order], $clients, false);
pdf_slip_assert_equal(
    'Friday, May 15, 2026',
    $slips_fallback[0]['delivery_date'],
    'T-2: without an occurrence, header falls back to creation date'
);

// -----------------------------------------------------------------------
// T-3: explicit _delivery_date order meta still wins over the computed
// occurrence (order-time capture is more authoritative than the B1 compute).
// -----------------------------------------------------------------------
$wc_order = new WC_Order();
$wc_order->id   = 10543;
$wc_order->meta = ['_delivery_date' => '2026-05-29']; // Friday — explicit capture
$GLOBALS['_pdf_slip_wc_orders'][10543] = $wc_order;

$order_meta = $base_order;
$order_meta['delivery_occurrence'] = '2026-05-28';
$slips_meta = pdf_slip_build_slips([$order_meta], $clients, false);
pdf_slip_assert_equal(
    'Friday, May 29, 2026',
    $slips_meta[0]['delivery_date'],
    'T-3: explicit _delivery_date meta wins over the computed occurrence'
);

pdf_slip_finish();
