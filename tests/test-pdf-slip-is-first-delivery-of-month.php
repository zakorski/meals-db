<?php
/**
 * LB-4: is_first_delivery_of_month() must not over-collect the monthly
 * client contribution.
 *
 * The method decides whether the monthly client contribution is collected on a
 * given driver-slip delivery. Historically it defaulted to TRUE (collect) when
 * no allocation detail rows existed yet — and since meals_delivery_allocations
 * is only materialised after the rebuilder runs (LB-1), that over-collected the
 * contribution on every delivery early in a billing month.
 *
 * The fix consults the authoritative contribution_applied flag on
 * meals_client_allocations first, then the MIN(delivery_date) earliest-delivery
 * signal, and fails to the financially-safe direction (do not over-collect)
 * when state cannot be determined.
 *
 * This exercises the private method directly via reflection, driving the four
 * cases from the directive — including the no-allocation-rows regression guard.
 *
 * Run: php tests/test-pdf-slip-is-first-delivery-of-month.php
 */

require_once __DIR__ . '/fixtures/pdf-slip-bootstrap.php';
pdf_slip_reset_globals();

global $wpdb;

$ref = new ReflectionMethod('MealsDB_Slip_PDF_Generator', 'is_first_delivery_of_month');
$ref->setAccessible(true);
$gen = (new ReflectionClass('MealsDB_Slip_PDF_Generator'))->newInstanceWithoutConstructor();

/**
 * Point the fixture wpdb at a given (contribution_applied, earliest-delivery)
 * state. The fixture's get_var() passes the interpolated SQL to this handler,
 * so we branch on which of the method's two queries is running.
 */
$set_state = static function ($applied, $earliest) {
    global $wpdb;
    $wpdb->get_var_handler = static function ($sql, $args) use ($applied, $earliest) {
        if (strpos($sql, 'contribution_applied') !== false) {
            return $applied;
        }
        return $earliest; // MIN(delivery_date)
    };
};

$call = static function (int $client_id, string $delivery_date) use ($ref, $gen) {
    return $ref->invoke($gen, $client_id, $delivery_date);
};

$failures = 0;

// Case 1: contribution already applied this month (allocations present). The
// authoritative flag short-circuits to "do not collect", regardless of date.
$set_state(1, '2026-05-04');
if ($call(123, '2026-05-04') !== false) {
    echo "  FAIL: contribution already applied must not be collected again.\n";
    $failures++;
} else {
    echo "  PASS: contribution already applied -> not collected.\n";
}

// Case 2 (LB-4 regression guard): NO allocation rows yet AND contribution not
// applied. The old code defaulted to TRUE here and over-collected on every
// delivery before materialisation. The fix fails safe.
$set_state(0, null);
if ($call(123, '2026-05-04') !== false) {
    echo "  FAIL: no allocation rows must not default to collecting (LB-4).\n";
    $failures++;
} else {
    echo "  PASS: no allocation rows + not applied -> not collected (LB-4).\n";
}

// Case 3: allocations present, this IS the earliest delivery, contribution not
// yet applied — collect (genuine first delivery).
$set_state(0, '2026-05-04');
if ($call(123, '2026-05-04') !== true) {
    echo "  FAIL: genuine earliest delivery should collect the contribution.\n";
    $failures++;
} else {
    echo "  PASS: earliest delivery, not yet applied -> collected.\n";
}

// Case 4: allocations present, this is NOT the earliest delivery — do not collect.
$set_state(0, '2026-05-02');
if ($call(123, '2026-05-09') !== false) {
    echo "  FAIL: a later delivery must not collect the contribution.\n";
    $failures++;
} else {
    echo "  PASS: later delivery -> not collected.\n";
}

// Bad input / no-DB error directions must also fail safe (do not over-collect).
if ($call(0, '2026-05-04') !== false) {
    echo "  FAIL: invalid client_id must not default to collecting.\n";
    $failures++;
} else {
    echo "  PASS: invalid client_id -> not collected.\n";
}
if ($call(123, 'not-a-date') !== false) {
    echo "  FAIL: malformed delivery_date must not default to collecting.\n";
    $failures++;
} else {
    echo "  PASS: malformed delivery_date -> not collected.\n";
}

if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s).\n";
    exit(1);
}
echo "OK: all is_first_delivery_of_month cases passed.\n";
