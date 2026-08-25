<?php
/**
 * Quick Order manual delivery-date override — the create_order path's
 * apply step (DIRECTIVE-manual-delivery-date-override.md, Section A.3).
 *
 * A valid posted delivery_date is written to the order's _delivery_date
 * meta (the slip generator's most-authoritative source and, post
 * Section D, its selection key). Blank/garbage input writes NOTHING —
 * the order falls through to the computed occurrence, unchanged
 * behaviour. The override is one-time-only: it touches ONLY the order
 * meta, never the client's next_order_date / next_delivery_date cadence
 * (persist_next_dates does not receive it — see the Must-NOT-change
 * list in the directive).
 *
 * Run: php tests/test-quick-order-delivery-date-override.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('add_action')) { function add_action(...$a) {} }

$failures = []; $passed = 0;
function qodd_check(string $label, $actual, $expected): void {
    global $failures, $passed;
    if ($actual === $expected) { $passed++; return; }
    $failures[] = sprintf("%s:\n  expected %s\n  got      %s", $label, var_export($expected, true), var_export($actual, true));
}

// Minimal order double: records every meta write so the test can assert
// exactly what the apply step touches (and what it must NOT).
class QoddFakeOrder {
    public array $meta_writes = [];
    public function update_meta_data($key, $value): void {
        $this->meta_writes[] = [$key, $value];
    }
}

if (!method_exists('MealsDB_Quick_Order_Ajax', 'apply_delivery_date_override')) {
    fwrite(STDERR, "FAIL: MealsDB_Quick_Order_Ajax::apply_delivery_date_override does not exist\n");
    exit(1);
}

// Valid date, default src ('manual') -> two meta writes: _delivery_date + _delivery_date_src='manual'.
$order = new QoddFakeOrder();
$applied = MealsDB_Quick_Order_Ajax::apply_delivery_date_override($order, '2026-07-25');
qodd_check('valid: returns sanitized date', $applied, '2026-07-25');
qodd_check('valid: exactly two meta writes (date + src)', $order->meta_writes, [
    ['_delivery_date',     '2026-07-25'],
    ['_delivery_date_src', 'manual'],
]);

// Explicit src='manual' -> same result as default.
$order = new QoddFakeOrder();
MealsDB_Quick_Order_Ajax::apply_delivery_date_override($order, '2026-07-25', 'manual');
qodd_check('explicit manual: writes _delivery_date_src=manual', $order->meta_writes, [
    ['_delivery_date',     '2026-07-25'],
    ['_delivery_date_src', 'manual'],
]);

// src='auto' -> _delivery_date_src written as 'auto'.
$order = new QoddFakeOrder();
MealsDB_Quick_Order_Ajax::apply_delivery_date_override($order, '2026-07-25', 'auto');
qodd_check('auto: writes _delivery_date_src=auto', $order->meta_writes, [
    ['_delivery_date',     '2026-07-25'],
    ['_delivery_date_src', 'auto'],
]);

// Unknown/garbage src values coerce to 'manual' (not 'auto').
$order = new QoddFakeOrder();
MealsDB_Quick_Order_Ajax::apply_delivery_date_override($order, '2026-07-25', 'system');
qodd_check('unknown src coerces to manual', $order->meta_writes, [
    ['_delivery_date',     '2026-07-25'],
    ['_delivery_date_src', 'manual'],
]);

// Blank -> no write, no override (order rides the computed occurrence).
$order = new QoddFakeOrder();
qodd_check('blank: returns empty', MealsDB_Quick_Order_Ajax::apply_delivery_date_override($order, ''), '');
qodd_check('blank: no meta writes', $order->meta_writes, []);

// Garbage / malformed / impossible dates -> rejected, no write.
foreach (['not-a-date', '25/07/2026', '2026-02-30', '2026-07-25 10:00:00', null, 42] as $bad) {
    $order = new QoddFakeOrder();
    qodd_check('rejects ' . var_export($bad, true), MealsDB_Quick_Order_Ajax::apply_delivery_date_override($order, $bad), '');
    qodd_check('no write for ' . var_export($bad, true), $order->meta_writes, []);
}

// Whitespace tolerance (a pasted value with stray spaces still applies).
$order = new QoddFakeOrder();
$ws_result = MealsDB_Quick_Order_Ajax::apply_delivery_date_override($order, '  2026-07-25  ');
qodd_check('trims whitespace: returns date', $ws_result, '2026-07-25');
qodd_check('trims whitespace: writes both keys', $order->meta_writes, [
    ['_delivery_date',     '2026-07-25'],
    ['_delivery_date_src', 'manual'],
]);

// One-time-only semantics, structurally: persist_next_dates() must not
// have grown a delivery-override parameter. Its contract stays
// (client_id, wp_user_id, order_date-ish) — the override never feeds
// the cadence anchor.
$ref = new ReflectionMethod('MealsDB_Quick_Order_Ajax', 'persist_next_dates');
foreach ($ref->getParameters() as $p) {
    qodd_check(
        'persist_next_dates param "' . $p->getName() . '" is not a delivery override',
        stripos($p->getName(), 'override') === false && stripos($p->getName(), 'delivery_date') === false,
        true
    );
}

if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("%d passed\n", $passed));
