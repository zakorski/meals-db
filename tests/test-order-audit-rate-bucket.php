<?php
/**
 * order_audit_edit rate bucket exists, sized for a full week's confirms
 * (~300+ rows in one sitting), and fails CLOSED (it gates writes).
 * Run with: php tests/test-order-audit-rate-bucket.php
 *
 * Constant names verified against class-rate-limiter.php:
 *   - DEFAULT_LIMITS   (private const, keyed by bucket name => int quota)
 *   - MUTATING_ACTIONS (private const, keyed by bucket name => true for fail-closed)
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$rc = new ReflectionClass('MealsDB_Rate_Limiter');
$limits          = $rc->getConstant('DEFAULT_LIMITS');
$mutating_actions = $rc->getConstant('MUTATING_ACTIONS');

$ok  = isset($limits['order_audit_edit']) && $limits['order_audit_edit'] === 1000;
$ok2 = isset($mutating_actions['order_audit_edit']) && $mutating_actions['order_audit_edit'] === true;

echo ($ok  ? 'PASS' : 'FAIL') . ": order_audit_edit limit is 1000\n";
echo ($ok2 ? 'PASS' : 'FAIL') . ": order_audit_edit is fail-closed (in MUTATING_ACTIONS)\n";
exit(($ok && $ok2) ? 0 : 1);
