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

// -- format_with_optional_contact(): DIRECTIVE K2 ITEM 2 ----------------------
// Like format(), but preserves a trailing contact name after the number.
eq(MealsDB_Phone::format_with_optional_contact(''), '', 'contact: empty → empty');
eq(MealsDB_Phone::format_with_optional_contact('   '), '', 'contact: whitespace → empty');
eq(MealsDB_Phone::format_with_optional_contact('506-536-1236'), '(506)-536-1236', 'contact: plain dashed 10 → canonical (the shape 71 clients hold)');
eq(MealsDB_Phone::format_with_optional_contact('(506)-536-1236'), '(506)-536-1236', 'contact: already-canonical unchanged');
eq(MealsDB_Phone::format_with_optional_contact('5065361236'), '(506)-536-1236', 'contact: bare 10 digits → canonical');
eq(MealsDB_Phone::format_with_optional_contact('506-988-1777 Denise'), '(506)-988-1777 Denise', 'contact: number + trailing name PRESERVED (the 28-variant shape)');
eq(MealsDB_Phone::format_with_optional_contact('(506) 988-1777  Denise Smith'), '(506)-988-1777 Denise Smith', 'contact: multi-word name preserved, inner whitespace collapsed to one space');
eq(MealsDB_Phone::format_with_optional_contact('1-506-988-1777 Denise'), '(506)-988-1777 Denise', 'contact: leading country-code 1 dropped, name preserved');
eq(MealsDB_Phone::format_with_optional_contact('   506-536-1236   '), '(506)-536-1236', 'contact: outer whitespace trimmed');
// No valid number → returns trimmed original so validate() surfaces an error.
eq(MealsDB_Phone::format_with_optional_contact('call the front desk'), 'call the front desk', 'contact: no number → trimmed original (validate() will reject)');
eq(MealsDB_Phone::format_with_optional_contact('506-1236 Denise'), '506-1236 Denise', 'contact: too-few digits in token → original (not a phone)');

// -- is_same_number(): DIRECTIVE K19 duplicate-suppression rule ----------------
// A `true` HIDES a number from a driver / order-taker, so the rule errs towards
// "different". Equal only when the full digit string matches (after dropping a
// leading NANP 1) AND any letters match.
eq(MealsDB_Phone::is_same_number('506-858-1234', '506-858-1234'), true, 'same: identical');
eq(MealsDB_Phone::is_same_number('506-858-1234', '(506)-858-1234'), true, 'same: formatting-only difference');
eq(MealsDB_Phone::is_same_number('506-858-1234', '5068581234'), true, 'same: bare digits vs dashed');
eq(MealsDB_Phone::is_same_number('506-858-1234', '1-506-858-1234'), true, 'same: leading country-code 1 (the directive lambda got this wrong)');
eq(MealsDB_Phone::is_same_number('506-858-1234', '+1 506 858 1234'), true, 'same: +1 international form');
eq(MealsDB_Phone::is_same_number('506-988-1777 Denise', '(506) 988-1777 denise'), true, 'same: same number + same name (case-insensitive)');
eq(MealsDB_Phone::is_same_number('506-858-1234', '506-858-9999'), false, 'different: different number');
// The canonical() last-10 trap: a multi-number field must NOT collapse onto its
// last number and hide the first.
eq(MealsDB_Phone::is_same_number('506-333-4444', '506-111-2222 / 506-333-4444'), false, 'different: multi-number field is never hidden');
eq(MealsDB_Phone::is_same_number('506-858-1234', '506-858-1234 Denise'), false, 'different: second carries a contact name');
eq(MealsDB_Phone::is_same_number('506-858-1234', '506-858-1234 x12'), false, 'different: extension is information');
eq(MealsDB_Phone::is_same_number('506-858-1234', '858-1234'), false, 'different: 7-digit local (errs to printing both)');
eq(MealsDB_Phone::is_same_number('', ''), false, 'different: empty is never "the same"');
eq(MealsDB_Phone::is_same_number('', '506-858-1234'), false, 'different: empty primary never hides the secondary');
eq(MealsDB_Phone::is_same_number('n/a', 'N/A'), false, 'different: no digits is never "the same"');

// -- has_number() / dialable_or_empty(): DIRECTIVE K20 placeholder rule --------
// Fewer than 7 digits is a placeholder (Enzebra stores `0` for 157 clients).
eq(MealsDB_Phone::has_number('0'), false, 'K20-1 has_number: "0" placeholder');
eq(MealsDB_Phone::has_number(''), false, 'K20-1 has_number: empty');
eq(MealsDB_Phone::has_number('-'), false, 'K20-1 has_number: "-"');
eq(MealsDB_Phone::has_number('n/a'), false, 'K20-1 has_number: "n/a"');
eq(MealsDB_Phone::has_number('858-123'), false, 'K20-1 has_number: 6 digits is below the floor');
eq(MealsDB_Phone::has_number('204-7570 (Joyce)'), true, 'K20-2 has_number: 7-digit local with name');
eq(MealsDB_Phone::has_number('506-204-7747 or 1-506-345-0237 (sister Diane)'), true, 'K20-2 has_number: multi-number annotated value');
eq(MealsDB_Phone::has_number('+1 506 858 1234'), true, 'K20-2 has_number: 11-digit international form');
eq(MealsDB_Phone::dialable_or_empty('  0  '), '', 'K20 dialable_or_empty: placeholder -> empty');
eq(MealsDB_Phone::dialable_or_empty('  506-858-1234  '), '506-858-1234', 'K20 dialable_or_empty: real number trimmed, otherwise verbatim');

echo 'Ran ' . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) { echo $f . "\n"; }
exit(empty($failures) ? 0 : 1);
