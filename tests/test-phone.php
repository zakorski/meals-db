<?php
/**
 * MealsDB_Phone consolidation tests (audit T8 — phone normalisation).
 *
 * Before this class, phone normalisation existed in THREE places with TWO
 * distinct semantics:
 *   - a "canonical" bare-digits form (strip non-digits, drop a leading NANP
 *     country-code 1 on an 11-digit number, keep the last 10) used for
 *     COMPARISON/matching — MealsDB_Sync_Compare::normalize_phone() and the
 *     inline block in MealsDB_Sync_Query;
 *   - a "format" display form ((###)-###-#### only when exactly 10 digits
 *     remain, else the trimmed original) used to store form-valid values —
 *     MealsDB_WP_User_Mapper::normalize_phone() → MealsDB_Private_Intake.
 *
 * MealsDB_Phone::canonical() and ::format() are the single source of truth.
 * These cases pin BOTH semantics — including where they deliberately DIVERGE
 * (a >10-digit input: canonical truncates to the last 10; format leaves the
 * original untouched so validate() surfaces a named error rather than silently
 * reshaping a number we can't confidently canonicalise).
 *
 * Run: php tests/test-phone.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function eq($got, $want, string $label): void {
    global $failures, $passed;
    if ($got === $want) { $passed++; return; }
    $failures[] = "FAIL: {$label} — got " . var_export($got, true) . ', want ' . var_export($want, true);
}

// -- canonical(): comparison form (bare digits, drop-1-if-11, last-10) --------
eq(MealsDB_Phone::canonical(''), '', 'canonical: empty → empty');
eq(MealsDB_Phone::canonical('   '), '', 'canonical: whitespace → empty');
eq(MealsDB_Phone::canonical('5065550100'), '5065550100', 'canonical: bare 10 digits unchanged');
eq(MealsDB_Phone::canonical('(506) 555-0100'), '5065550100', 'canonical: strips formatting');
eq(MealsDB_Phone::canonical('+1 506 555 0100'), '5065550100', 'canonical: drops leading country-code 1 (11 digits)');
eq(MealsDB_Phone::canonical('1-506-555-0100'), '5065550100', 'canonical: dashed leading-1 form');
eq(MealsDB_Phone::canonical('15065550100'), '5065550100', 'canonical: 11-digit leading 1 dropped');
eq(MealsDB_Phone::canonical('25065550100'), '5065550100', 'canonical: 11-digit leading non-1 keeps last 10');
eq(MealsDB_Phone::canonical('5065550100x123'), '5550100123', 'canonical: extension tail folded into last-10');
eq(MealsDB_Phone::canonical('5065550'), '5065550', 'canonical: short (7-digit) residue passes through');

// -- format(): display form ((###)-###-#### only for exactly 10) --------------
eq(MealsDB_Phone::format(''), '', 'format: empty → empty');
eq(MealsDB_Phone::format('   '), '', 'format: whitespace → empty');
eq(MealsDB_Phone::format('5065550100'), '(506)-555-0100', 'format: 10 digits formatted');
eq(MealsDB_Phone::format('(506) 555-0100'), '(506)-555-0100', 'format: pre-formatted 10 normalised');
eq(MealsDB_Phone::format('+1 (506) 555-0100'), '(506)-555-0100', 'format: 11-digit leading 1 dropped then formatted');
eq(MealsDB_Phone::format('15065550100'), '(506)-555-0100', 'format: 11-digit leading 1 dropped then formatted');
eq(MealsDB_Phone::format('506555'), '506555', 'format: short residue returns trimmed original');
eq(MealsDB_Phone::format('  506555  '), '506555', 'format: short residue returns TRIMMED original');
// Divergence: >10 digits — canonical truncates, format leaves it for validate().
eq(MealsDB_Phone::format('5065550100x123'), '5065550100x123', 'format: >10 digits returns original (NOT truncated) — diverges from canonical');
eq(MealsDB_Phone::format('25065550100'), '25065550100', 'format: 11-digit leading non-1 returns original (not exactly 10)');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
