<?php
/**
 * Phone-number normalisation — the single source of truth.
 *
 * Historically this logic was duplicated in three places with two distinct
 * intents (audit T8):
 *   - MealsDB_Sync_Compare::normalize_phone() and the inline block in
 *     MealsDB_Sync_Query — a bare-digits CANONICAL form for comparison and
 *     LIKE-matching, so "(506) 555-0100" and "5065550100" compare equal;
 *   - MealsDB_WP_User_Mapper::normalize_phone() (reused by
 *     MealsDB_Private_Intake) — a FORMATTED (###)-###-#### display form used
 *     to store form-valid values that pass MealsDB_Client_Form::validate().
 *
 * The two intents are NOT interchangeable, so this class exposes both as named
 * methods rather than collapsing them into one. `canonical()` is for
 * comparison; `format()` is for storage/display. Callers pick by intent.
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Phone {

    /**
     * Strip to digits and drop a leading NANP country-code 1 on an 11-digit
     * number. Shared prefix of both public forms.
     *
     * preg_replace() returns null on a (practically impossible) PCRE error;
     * coalesce so callers always get a string — mirrors the guarded siblings
     * this consolidates.
     */
    private static function digits_dropping_country_code(string $value): string {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    /**
     * Canonical comparison form: bare digits, at most the last 10.
     *
     *   - Strip every non-digit (parens, spaces, dashes, "+").
     *   - Drop a leading country-code 1 when the residue is exactly 11 digits.
     *   - Keep at most the last 10 digits — tolerates extension tails ("…x123")
     *     and best-effort-matches longer international numbers against their
     *     last 10.
     *
     * A residue shorter than 10 digits passes through unchanged (a partial
     * number still compares against what the user typed). Empty in → empty out.
     */
    public static function canonical(string $value): string {
        $digits = self::digits_dropping_country_code($value);
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }
        return $digits;
    }

    /**
     * Form-valid display shape (###)-###-#### — ONLY when exactly 10 digits
     * remain after dropping a country-code 1. Anything else returns the trimmed
     * ORIGINAL (not the digits) so MealsDB_Client_Form::validate() surfaces a
     * named error rather than a silent reshape of a number we can't confidently
     * canonicalise. This deliberately DIFFERS from canonical(), which would
     * truncate a >10-digit residue.
     */
    public static function format(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $digits = self::digits_dropping_country_code($value);
        if (strlen($digits) === 10) {
            return sprintf('(%s)-%s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        }
        return $value;
    }
}
