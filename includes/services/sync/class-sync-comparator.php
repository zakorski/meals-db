<?php
/**
 * Per-comparator equality and blankness for the K21 sync (directive K21
 * ITEMS 2 + 3).
 *
 * Every synced field names one comparator in MealsDB_Sync_Registry. The
 * comparator answers three questions about raw values, from either system:
 *
 *   is_blank()  — is there nothing here? (a blank side may be FILLED)
 *   parses()    — is this a well-formed value? (only these may be a FILL SOURCE)
 *   equals()    — do two values mean the same thing?
 *
 * Normalisation exists because formatting differences would otherwise flood
 * the conflict queue: the 2026-10-30 production overlap check found 99%+
 * agreement on every address/contact field ONLY after normalisation.
 *
 * THE SAFETY RULE: a non-blank value that fails its comparator's parse
 * (unparseable date, non-integer frequency, unrecognised customer_group) is
 * NOT blank. It is never filled over, never used to fill the other side, and
 * compares by its normalised raw text, so it surfaces as a conflict. Under
 * K21's strict fill-blank rule, a garbage value that read as "blank" would be
 * silently overwritten — exactly what the directive forbids.
 *
 * Pure: no $wpdb, no WordPress state. Stored values are never changed here.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

final class MealsDB_Sync_Comparator {

    public const TEXT        = 'text';
    public const EMAIL       = 'email';
    public const PHONE       = 'phone';
    public const POSTAL      = 'postal';
    public const CODE        = 'code';
    public const INTEGER     = 'integer';
    public const DATE        = 'date';
    public const CLIENT_TYPE = 'client_type';

    public const ALL = [
        self::TEXT, self::EMAIL, self::PHONE, self::POSTAL,
        self::CODE, self::INTEGER, self::DATE, self::CLIENT_TYPE,
    ];

    /**
     * meals_clients.client_type ENUM values — the three classes ITEM 3
     * compares by. A MealsDB value is one of these; a WordPress
     * customer_group is mapped onto one by
     * MealsDB_Migration_Consolidated::customer_group_class().
     */
    public const CLIENT_TYPE_CLASSES = ['SDNB', 'Veteran', 'Private'];

    /**
     * K23: is this client_type / customer_group value Extra Mural?
     *
     * Extra Mural is a recognised class with NO MealsDB client_type (see
     * MealsDB_Migration_Consolidated::CLASS_EXTRA_MURAL). It differs from
     * every MealsDB class, so it is always a conflict, and it is never
     * writable: it cannot go into the client_type ENUM, and overwriting it in
     * WordPress would destroy the only record that the client is Extra Mural.
     * The reconciler, the resolver and the conflict view all ask this one
     * question.
     */
    public static function is_extra_mural(?string $value): bool {
        return self::client_type_class($value) === MealsDB_Migration_Consolidated::CLASS_EXTRA_MURAL;
    }

    /**
     * Blank: nothing here. A blank side may be filled from the other.
     *
     * Placeholders count as blank where the source systems use them:
     *   - phone: fewer than 7 digits (K20 — the 157 shipping_phone = '0').
     *   - integer: '0' (the migration already treats a '0' frequency as unset).
     *   - date: '0' / '0000-00-00' (Enzebra's '0' placeholder; the migration
     *     treats commence_date / service_termination_date '0' as unset).
     */
    public static function is_blank(string $comparator, ?string $value): bool {
        self::assert_known($comparator);
        $trimmed = self::trim((string) $value);

        switch ($comparator) {
            case self::PHONE:
                return !MealsDB_Phone::has_number($trimmed);
            case self::INTEGER:
                return $trimmed === '' || preg_match('/^0+$/', $trimmed) === 1;
            case self::DATE:
                return $trimmed === '' || $trimmed === '0'
                    || preg_match('/^0000-00-00(?:[ T]00:00(?::00)?)?$/', $trimmed) === 1;
            default:
                return $trimmed === '';
        }
    }

    /**
     * Well-formed and non-blank: the only values allowed to FILL a blank on
     * the other side. A non-blank value that does not parse is a conflict.
     */
    public static function parses(string $comparator, ?string $value): bool {
        if (self::is_blank($comparator, $value)) {
            return false;
        }
        $trimmed = self::trim((string) $value);

        switch ($comparator) {
            case self::INTEGER:
                return preg_match('/^\d+$/', $trimmed) === 1;
            case self::DATE:
                return self::parse_date($trimmed) !== null;
            case self::CLIENT_TYPE:
                // K23: Extra Mural is recognised but is NOT a value the sync
                // may write anywhere, so it does not "parse" in the sense
                // that matters here — it is never a fill source (a fill would
                // put 'ExtraMural' into the client_type ENUM) and never a
                // resolvable choice.
                $class = self::client_type_class($trimmed);
                return $class !== null && $class !== MealsDB_Migration_Consolidated::CLASS_EXTRA_MURAL;
            default:
                return true;
        }
    }

    /**
     * Non-blank but unparseable. ITEM 3 requires an unrecognised
     * customer_group to be logged (once per run) so a new Enzebra group
     * surfaces; the caller uses this to find them.
     */
    public static function is_unrecognised(string $comparator, ?string $value): bool {
        if ($comparator === self::CLIENT_TYPE && self::is_extra_mural($value)) {
            // K23: a KNOWN group (it just has no MealsDB type). Reporting it
            // nightly as a "new Enzebra group" would bury a real one.
            return false;
        }
        return !self::is_blank($comparator, $value) && !self::parses($comparator, $value);
    }

    /**
     * Do two raw values mean the same thing?
     *
     * Two blanks are equal; a blank never equals a non-blank (that pair is a
     * fill, not an equality). Two parseable values compare by the
     * comparator's rule. If either side is unparseable, both compare by
     * normalised raw text — equal only if they are the same text, so a
     * garbage value never silently matches a real one.
     */
    public static function equals(string $comparator, ?string $a, ?string $b): bool {
        $blank_a = self::is_blank($comparator, $a);
        $blank_b = self::is_blank($comparator, $b);
        if ($blank_a || $blank_b) {
            return $blank_a && $blank_b;
        }

        $a = (string) $a;
        $b = (string) $b;

        if (!self::parses($comparator, $a) || !self::parses($comparator, $b)) {
            return self::normalize_text($a) === self::normalize_text($b);
        }

        switch ($comparator) {
            case self::PHONE:
                // K20's rule: same full digit string (leading NANP '1'
                // dropped) AND same letters, so "506-988-1777 Denise" is
                // not the same as a bare "5069881777".
                return MealsDB_Phone::is_same_number($a, $b);
            case self::EMAIL:
                return self::lower(self::trim($a)) === self::lower(self::trim($b));
            case self::POSTAL:
                return self::normalize_postal($a) === self::normalize_postal($b);
            case self::CODE:
                return strtoupper(self::trim($a)) === strtoupper(self::trim($b));
            case self::INTEGER:
                return ltrim(self::trim($a), '0') === ltrim(self::trim($b), '0');
            case self::DATE:
                return self::parse_date(self::trim($a)) === self::parse_date(self::trim($b));
            case self::CLIENT_TYPE:
                return self::client_type_class($a) === self::client_type_class($b);
            case self::TEXT:
            default:
                return self::normalize_text($a) === self::normalize_text($b);
        }
    }

    /**
     * ITEM 3: the class ('SDNB' | 'Veteran' | 'Private', or K23's
     * 'ExtraMural' — a class with no MealsDB client_type) of a client_type or
     * customer_group value, or null when blank or unrecognised.
     *
     * Accepts both vocabularies so the per-account reconcile (ITEM 7a) can
     * compare a WordPress value against several MealsDB values with one
     * function: a MealsDB ENUM value matches CLIENT_TYPE_CLASSES
     * case-insensitively; anything else goes through the single
     * customer_group mapping (Directive 7) — never a second copy of it.
     */
    public static function client_type_class(?string $value): ?string {
        $trimmed = self::trim((string) $value);
        if ($trimmed === '') {
            return null;
        }
        foreach (self::CLIENT_TYPE_CLASSES as $class) {
            if (strcasecmp($trimmed, $class) === 0) {
                return $class;
            }
        }
        return MealsDB_Migration_Consolidated::customer_group_class($trimmed);
    }

    /**
     * Strict ISO date: YYYY-M(M)-D(D), optionally followed by a time
     * (MySQL DATETIME shape), and a real calendar date. Returns canonical
     * 'Y-m-d' or null. '202409-02-03' (a known production value) is null.
     */
    public static function parse_date(string $value): ?string {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value, $m) !== 1) {
            return null;
        }
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if (!checkdate($mo, $d, $y)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** Text: lowercase, trimmed, internal whitespace (incl. NBSP) collapsed. */
    public static function normalize_text(string $value): string {
        $collapsed = preg_replace('/[\s\x{00A0}]+/u', ' ', $value);
        if ($collapsed === null) {
            // Invalid UTF-8: fall back to ASCII whitespace only rather than
            // treating the value as empty.
            $collapsed = (string) preg_replace('/\s+/', ' ', $value);
        }
        return self::lower(trim($collapsed));
    }

    /** Postal: uppercase with every space removed ("e1c 4b5" === "E1C4B5"). */
    private static function normalize_postal(string $value): string {
        $stripped = preg_replace('/[\s\x{00A0}]+/u', '', $value);
        if ($stripped === null) {
            $stripped = (string) preg_replace('/\s+/', '', $value);
        }
        return strtoupper($stripped);
    }

    /** trim() plus NBSP, which copy-pasted addresses carry. */
    private static function trim(string $value): string {
        $trimmed = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value);
        return $trimmed === null ? trim($value) : $trimmed;
    }

    private static function lower(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * A misspelt comparator name in the registry would otherwise fall
     * through to TEXT silently. Fail loudly instead.
     */
    private static function assert_known(string $comparator): void {
        if (!in_array($comparator, self::ALL, true)) {
            throw new InvalidArgumentException('Unknown sync comparator: ' . $comparator);
        }
    }
}
