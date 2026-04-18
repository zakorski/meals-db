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
     * Sanitize and quote a single CSV field.
     *
     * @param mixed $value Raw cell value (scalars are stringified).
     */
    public static function cell($value): string {
        if ($value === null || $value === false) {
            return '';
        }

        $string = is_scalar($value) ? (string) $value : '';

        if ($string !== '' && in_array($string[0], self::FORMULA_TRIGGERS, true)) {
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
}
