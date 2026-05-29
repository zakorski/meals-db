<?php
/**
 * Helpers for building safe CSV output.
 *
 * Centralises:
 *   1. Formula-injection neutralisation for cells starting with =, +, -, @,
 *      tab, or carriage return (CWE-1236 / OWASP "CSV Injection").
 *   2. RFC-4180-compatible quoting for cells that contain commas, quotes,
 *      or newlines.
 *
 * Use ::row() to build a complete row from an array of raw values, or
 * ::cell() if you need a single sanitized field.
 */

defined('ABSPATH') || exit;

class MealsDB_CSV {

    /**
     * Characters that, when leading a cell, can be interpreted as a
     * spreadsheet formula by Excel/Numbers/Sheets.
     */
    private const FORMULA_TRIGGERS = [ '=', '+', '-', '@', "\t", "\r" ];

    /**
     * Matches a value that is a well-formed plain number, optionally signed.
     * Optional leading - or +, digits, an optional single decimal part, and
     * NOTHING else (anchored). "-10.24" / "1024" / "+5" qualify; "-2+3",
     * "-=1", "-1-1", "1,024" do not.
     *
     * Used by cell() to exempt genuine numeric values (esp. negative money)
     * from the leading-char formula guard.
     */
    private const NUMERIC_VALUE = '/^[-+]?\d+(\.\d+)?$/';

    /**
     * Sanitize and quote a single CSV field.
     *
     * @param mixed $value Raw cell value (scalars are stringified).
     */
    public static function cell($value): string {
        if ($value === null || $value === false) {
            return '';
        }

        $string = is_scalar($value) ? (string) $value : '';

        // QW-3: a well-formed number (incl. negative money like -10.24) is NOT a
        // formula-injection vector — a spreadsheet renders it as the number it
        // is. Exempt it from the leading-char guard so negative amounts aren't
        // corrupted into text ('-10.24) in a government-bound numeric column.
        // MealsDB_Money::format / number_format emit a leading '-' for
        // negatives, which previously tripped the guard (audit MAJ-3). A leading
        // '-' (or '+', '@', etc.) in a NON-numeric string is still neutralised:
        // "-2+3" typed into a name field is a real injection vector and stays
        // quoted. cell_strict() is intentionally NOT changed — it strips for the
        // handful of exports that must never contain a formula at any cost.
        $is_plain_number = ($string !== '') && preg_match(self::NUMERIC_VALUE, $string) === 1;

        if (!$is_plain_number
            && $string !== ''
            && in_array($string[0], self::FORMULA_TRIGGERS, true)) {
            // Prepend a single quote so spreadsheet apps treat the value as
            // text. The quote is stripped on display in Excel/Numbers but
            // remains in the underlying data — acceptable trade for safety.
            $string = "'" . $string;
        }

        // Quote when needed — comma, double-quote, CR, or LF.
        if (strpbrk($string, ",\"\r\n") !== false) {
            $string = '"' . str_replace('"', '""', $string) . '"';
        }

        return $string;
    }

    /**
     * Build a single CSV row line from an array of raw cell values.
     *
     * @param array<int, mixed> $cells
     */
    public static function row(array $cells): string {
        return implode(',', array_map([self::class, 'cell'], $cells));
    }

    /**
     * Strict-mode cell: drop formula-trigger leading characters
     * instead of prepending a single quote.
     *
     * The default cell() helper neutralises =, +, -, @, tab, and CR
     * by prepending a single quote — safe in every current Excel /
     * Numbers / Sheets build, but some older or less-common
     * spreadsheet engines treat the leading quote as literal data
     * and still evaluate the formula. For the handful of high-risk
     * exports (government invoice body, customer PII surfaces),
     * callers can opt into strict mode which strips leading trigger
     * characters entirely. Data loss in exchange for absolute
     * confidence the cell will never be interpreted as a formula.
     *
     * Quoting for commas / quotes / newlines behaves the same way
     * as cell() so the output stays RFC-4180 valid.
     */
    public static function cell_strict($value): string {
        if ($value === null || $value === false) {
            return '';
        }

        $string = is_scalar($value) ? (string) $value : '';

        // Strip every leading trigger until we land on a safe char.
        while ($string !== '' && in_array($string[0], self::FORMULA_TRIGGERS, true)) {
            $string = substr($string, 1);
        }

        if (strpbrk($string, ",\"\r\n") !== false) {
            $string = '"' . str_replace('"', '""', $string) . '"';
        }

        return $string;
    }

    /**
     * Strict-mode row: routes every cell through cell_strict().
     *
     * @param array<int, mixed> $cells
     */
    public static function row_strict(array $cells): string {
        return implode(',', array_map([self::class, 'cell_strict'], $cells));
    }
}
