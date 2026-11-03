<?php
/**
 * The single field registry for the MealsDB <-> WordPress sync (directive
 * K21 ITEM 1).
 *
 * This is the ONLY place a synced field is defined: its meals_clients column,
 * its WordPress source, direction, comparator, blank test and derived
 * dependants. The sync, the comparison, the resolution endpoint and the
 * conflict view all read it. Two maps disagreeing is how K13 happened — the
 * nightly sync mapped columns to `mealsdb_*` keys the migration never used and
 * blanked 992 clients. Do NOT define a field mapping anywhere else; add or
 * change it here.
 *
 * Entry shape (keyed by meals_clients column):
 *   column     string  meals_clients column (also the registry key).
 *   wp         array   WordPress source:
 *                        type        'core' (WP_User property) | 'meta' (usermeta key)
 *                        key         property name / meta key the value is READ from
 *                        also_write  (optional) extra meta keys written with the
 *                                    same value on a fill (ITEM 4)
 *                        consistency (optional) a meta key that should equal `key`;
 *                                    a mismatch is a WordPress-internal
 *                                    inconsistency, logged, never a conflict (ITEM 4)
 *   direction  string  self::TWO_WAY | self::TO_WP_ONLY
 *   comparator string  a MealsDB_Sync_Comparator constant
 *   wp_blank   string  (optional) override for the WordPress side's blank test
 *   derived    array   meals_clients columns computed from this one (ITEM 6)
 *   k13        bool    TRANSITIONAL: field is in the pre-K21 WP -> MealsDB
 *                      nightly (the 17 MealsDB_Sync::get_wp_authoritative_fields()).
 *                      Kept so the current nightly behaves exactly as before
 *                      until ITEM 7 replaces it; delete the flag then.
 *
 * Deliberately NOT in the registry (K21 "Excluded"): billing quantities,
 * use_legacy_billing, every encrypted column, computed dates, and the
 * delivery_day usermeta key. Tests assert the encrypted columns stay out.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

final class MealsDB_Sync_Registry {

    public const TWO_WAY    = 'two_way';
    public const TO_WP_ONLY = 'to_wp_only';

    /** wp_blank override: anything other than exactly 3 characters (ITEM 5). */
    public const BLANK_NOT_THREE_CHARS = 'not_three_chars';

    /** @var array<string, array<string, mixed>>|null */
    private static $fields = null;

    /**
     * Every synced field, keyed by meals_clients column, in review order.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array {
        if (self::$fields !== null) {
            return self::$fields;
        }

        $C = 'MealsDB_Sync_Comparator';
        $rows = [
            // column, wp type, wp key, comparator, k13, extras
            ['first_name',                'core', 'first_name',                $C::TEXT,        true],
            ['last_name',                 'core', 'last_name',                 $C::TEXT,        true],
            ['client_email',              'core', 'user_email',                $C::EMAIL,       true],
            ['client_phone_1',            'meta', 'billing_phone',             $C::PHONE,       true],
            // K19 ITEM 1: was `billing_phone_2`, a key with 0 usermeta rows, so
            // the column could never populate. WP/Enzebra's second phone is the
            // delivery phone `shipping_phone` (1,015 non-empty; differs from
            // billing_phone for 193 clients). Stored verbatim even when it
            // equals client_phone_1 — the column mirrors the WP field; hiding
            // the duplicate is a display concern (MealsDB_Phone::is_same_number).
            ['client_phone_2',            'meta', 'shipping_phone',            $C::PHONE,       true],
            ['street_name',               'meta', 'billing_address_1',         $C::TEXT,        true],
            ['city',                      'meta', 'billing_city',              $C::TEXT,        true],
            ['province',                  'meta', 'billing_state',             $C::CODE,        true],
            ['postal_code',               'meta', 'billing_postcode',          $C::POSTAL,      true],
            ['delivery_street_name',      'meta', 'shipping_address_1',        $C::TEXT,        true],
            ['delivery_city',             'meta', 'shipping_city',             $C::TEXT,        true],
            ['delivery_province',         'meta', 'shipping_state',            $C::CODE,        true],
            ['delivery_postal_code',      'meta', 'shipping_postcode',         $C::POSTAL,      true],
            // K13 ITEM 1: street_name / delivery_street_name (above) and these
            // four were mapped to `mealsdb_*` keys that have NEVER existed on
            // this install (0 usermeta rows, verified 2026-09-14).
            // get_user_meta() returned '' and the nightly sync wrote that ''
            // over the column, blanking all 992 clients. Each now points at the
            // key the migration actually reads (class-migration-consolidated
            // .php) — the only path that populated these columns — so sync and
            // migration agree. K13 ITEM 2 makes a still-absent key harmless.
            ['alternate_contact_name',    'meta', 'alternate_contact_name',    $C::TEXT,        true],
            ['alternate_contact_phone_1', 'meta', 'alternate_contact_phone_1', $C::PHONE,       true],
            ['alternate_contact_phone_2', 'meta', 'alternate_contact_phone_2', $C::PHONE,       true],
            ['alternate_contact_email',   'meta', 'alternate_contact_email',   $C::EMAIL,       true],
            // ITEM 3: compared by class; lossy toward MealsDB.
            ['client_type',               'meta', 'customer_group',            $C::CLIENT_TYPE, false],
            // ITEM 4: the zone lives in address_2. shipping is read and wins;
            // a fill writes both keys so they stay in step.
            ['delivery_area_name',        'meta', 'shipping_address_2',        $C::TEXT,        false, [
                'also_write'  => ['billing_address_2'],
                'consistency' => 'billing_address_2',
                'derived'     => ['delivery_day'],
            ]],
            // British spelling on the WP side is the real Enzebra key.
            ['service_center_charged',    'meta', 'service_centre_charged',    $C::TEXT,        false, [
                'derived'     => ['delivery_area_zone'],
            ]],
            ['payment_method',            'meta', 'payment_method',            $C::TEXT,        false],
            // Stored as INT by the migration ((int) cast).
            ['delivery_frequency',        'meta', 'delivery_frequency',        $C::INTEGER,     false],
            ['ordering_frequency',        'meta', 'ordering_frequency',        $C::INTEGER,     false],
            ['service_id',                'meta', 'service_id',                $C::TEXT,        false],
            ['service_commence_date',     'meta', 'commence_date',             $C::DATE,        false],
            ['expected_termination_date', 'meta', 'service_termination_date',  $C::DATE,        false],
            // ITEM 5: one-way. MealsDB's delivery_initials is immutable; the
            // sync only ever pushes it to `nickname`, and only over a
            // nickname that is not exactly 3 characters.
            ['delivery_initials',         'meta', 'nickname',                  $C::TEXT,        false, [
                'direction'   => self::TO_WP_ONLY,
                'wp_blank'    => self::BLANK_NOT_THREE_CHARS,
            ]],
        ];

        $fields = [];
        foreach ($rows as $row) {
            [$column, $type, $key, $comparator, $k13] = $row;
            $extra = $row[5] ?? [];

            $wp = ['type' => $type, 'key' => $key];
            foreach (['also_write', 'consistency'] as $opt) {
                if (isset($extra[$opt])) {
                    $wp[$opt] = $extra[$opt];
                }
            }

            $fields[$column] = [
                'column'     => $column,
                'wp'         => $wp,
                'direction'  => $extra['direction'] ?? self::TWO_WAY,
                'comparator' => $comparator,
                'wp_blank'   => $extra['wp_blank'] ?? null,
                'derived'    => $extra['derived'] ?? [],
                'k13'        => $k13,
            ];
        }

        return self::$fields = $fields;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $column): ?array {
        return self::fields()[$column] ?? null;
    }

    public static function has(string $column): bool {
        return isset(self::fields()[$column]);
    }

    /** @return string[] Columns synced in both directions (the 26). */
    public static function two_way_columns(): array {
        return array_keys(array_filter(self::fields(), static function (array $f): bool {
            return $f['direction'] === self::TWO_WAY;
        }));
    }

    /**
     * TRANSITIONAL (removed by ITEM 7): the 17 columns the pre-K21 nightly
     * syncs WP -> MealsDB, in their historical order. Backs
     * MealsDB_Sync::get_wp_authoritative_fields() so this refactor changes no
     * sync behaviour.
     *
     * @return string[]
     */
    public static function k13_columns(): array {
        return array_keys(array_filter(self::fields(), static function (array $f): bool {
            return $f['k13'] === true;
        }));
    }

    /**
     * Read descriptor in the historical MealsDB_Sync::get_field_to_wp_meta_map()
     * shape — exactly ['type' => ..., 'key' => ...] — for existing callers.
     *
     * @return array{type: string, key: string}|null
     */
    public static function wp_read_descriptor(string $column): ?array {
        $field = self::get($column);
        if ($field === null) {
            return null;
        }
        return ['type' => $field['wp']['type'], 'key' => $field['wp']['key']];
    }

    /**
     * Blank test for one side of a field. The MealsDB side always uses the
     * comparator's rule; the WordPress side uses the entry's wp_blank
     * override when set (ITEM 5's nickname rule).
     *
     * @param string $side 'meals' | 'wp'
     */
    public static function is_blank(string $column, string $side, ?string $value): bool {
        $field = self::get($column);
        if ($field === null) {
            throw new InvalidArgumentException('Not a synced field: ' . $column);
        }
        if ($side === 'wp' && $field['wp_blank'] === self::BLANK_NOT_THREE_CHARS) {
            $trimmed = trim((string) $value);
            $length  = function_exists('mb_strlen') ? mb_strlen($trimmed, 'UTF-8') : strlen($trimmed);
            return $length !== 3;
        }
        return MealsDB_Sync_Comparator::is_blank($field['comparator'], $value);
    }
}
