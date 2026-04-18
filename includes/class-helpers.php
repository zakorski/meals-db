<?php
/**
 * Small utility helpers shared across AJAX handlers and services.
 *
 * Centralises a few patterns that were copy-pasted with subtly different
 * defaults — most notably dry-run flag parsing (the FILTER_VALIDATE_BOOLEAN
 * default-true pattern silently flips to false on unrecognised values) and
 * Y-M-D / Y-M date validation.
 */

defined('ABSPATH') || exit;

class MealsDB_Helpers {

    /**
     * Parse a $_REQUEST-style boolean flag with a safe default.
     *
     * The naive  filter_var($v, FILTER_VALIDATE_BOOLEAN)  pattern returns
     * false for any value PHP doesn't recognise as a boolean (e.g. "maybe",
     * the literal string "True!", a typo). Used with `?? true` defaults
     * for dry-run toggles that means an unrecognised value silently flips
     * destructive code on. FILTER_NULL_ON_FAILURE distinguishes the two
     * cases so we can fall back to the supplied default.
     *
     * @param mixed $value   Raw value (typically from $_POST/$_REQUEST).
     * @param bool  $default Default to apply when the value is missing or
     *                       cannot be interpreted as a boolean. Defaults
     *                       to true so write/destructive endpoints stay in
     *                       dry-run mode unless the caller explicitly opts
     *                       out.
     */
    public static function bool_flag($value, bool $default = true): bool {
        if ($value === null) {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) {
            return $default;
        }
        return (bool) $parsed;
    }

    /**
     * Strict YYYY-MM-DD validation: format check + checkdate() so values
     * like 2024-02-30 are rejected.
     */
    public static function is_valid_ymd(string $value): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Strict YYYY-MM validation.
     */
    public static function is_valid_ym(string $value): bool {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
            return false;
        }
        $month = (int) $m[2];
        return $month >= 1 && $month <= 12;
    }
}
