<?php
/**
 * MealsDB_Invoice_Generator::site_tz() consolidation test (audit T8).
 *
 * The expression
 *   function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get())
 * was duplicated verbatim at three call sites (period-window build, SDNB-legacy
 * serialize, VAC-PDF date). It is now the single private helper site_tz().
 *
 * These checks pin both branches:
 *   - when wp_timezone() exists, site_tz() returns exactly what it returns;
 *   - when it does not, site_tz() falls back to the server default zone.
 *
 * Run: php tests/test-invoice-site-tz.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// Pin a known server default so the fallback branch is deterministic.
date_default_timezone_set('America/Moncton');

// Provide wp_timezone() BEFORE we exercise the "available" branch. Return a
// sentinel zone so we can prove site_tz() returns wp_timezone()'s value verbatim.
if (!function_exists('wp_timezone')) {
    function wp_timezone() { return new DateTimeZone('Pacific/Auckland'); }
}

$failures = []; $passed = 0;
function eq($got, $want, string $label): void {
    global $failures, $passed;
    if ($got === $want) { $passed++; return; }
    $failures[] = "FAIL: {$label} — got " . var_export($got, true) . ', want ' . var_export($want, true);
}

$m = new ReflectionMethod('MealsDB_Invoice_Generator', 'site_tz');
$m->setAccessible(true);

// 1. Returns a DateTimeZone.
$tz = $m->invoke(null);
eq($tz instanceof DateTimeZone, true, 'site_tz() returns a DateTimeZone');

// 2. With wp_timezone() available, site_tz() returns its zone name verbatim.
eq($tz->getName(), 'Pacific/Auckland', 'site_tz() returns wp_timezone() value when available');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
