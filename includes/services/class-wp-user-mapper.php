<?php
/**
 * Maps a WordPress user's usermeta to Meals DB client FORM fields.
 *
 * SOURCE OF TRUTH: the usermeta keys and fallbacks below mirror the migration
 * importer (MealsDB_Consolidated_Migration, includes/services/
 * class-migration-consolidated.php — see the `$meta[...]` reads around the
 * client INSERT). The migration built every client FROM a WP user's usermeta;
 * the "Pull Data" button on the Add/Edit Client form (directive GUI-F3F5-v2)
 * reuses the exact same mapping so a hand-entered client matches a migrated one
 * and the two paths cannot drift (the STR-1 "done two ways" lesson).
 *
 * Scope is deliberately identity / contact / address / service-preference ONLY:
 *   - NOT program classification (client_type / customer_group / service_centre)
 *     — the operator sets the program on the form, and a brand-new Private
 *     client has no such meta anyway.
 *   - NOT encrypted PII (diet_concerns / customer_comments) — the migration
 *     treats those specially (encrypt-then-store); out of scope for v1 Pull Data
 *     (the operator enters them). Flag if they should be included later.
 *
 * Output uses FORM-side field names (the $_POST keys the client form expects,
 * e.g. phone_primary / address_postal — see MealsDB_Client_Form::map_form_to_db)
 * and is normalised to form-valid shapes (province -> 2-letter code, postal ->
 * A1A1A1, phone -> (###)-###-####) so pulled values pass validate() cleanly.
 * Only NON-EMPTY fields are returned, so Pull Data never blanks a field the WP
 * user has no data for.
 *
 * NOTE (follow-up): a full refactor that has the migration importer ALSO call
 * this mapper for its overlapping fields would make the shared-source guarantee
 * structural rather than by-convention. The migration does far more (zone
 * derivation, initials, encryption, bulk meta fetch) so that refactor is larger
 * than this directive; for now the mapping is duplicated here with this pointer
 * to the migration as the canonical source. Keep the two in sync.
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_WP_User_Mapper {

    /**
     * Build the form-field map for a WordPress user.
     *
     * @param int $uid WordPress user ID (assumed already validated as existing).
     * @return array<string, string> Non-empty form-side field => normalised value.
     */
    public static function map_usermeta_to_client_fields(int $uid): array {
        if ($uid <= 0 || !function_exists('get_userdata')) {
            return [];
        }

        $user = get_userdata($uid);
        if (!($user instanceof WP_User) && !(is_object($user) && isset($user->ID))) {
            return [];
        }

        $get = static function (string $key) use ($uid): string {
            if (!function_exists('get_user_meta')) {
                return '';
            }
            $value = get_user_meta($uid, $key, true);
            return is_scalar($value) ? trim((string) $value) : '';
        };

        // Identity ----------------------------------------------------------
        $first = $get('billing_first_name');
        if ($first === '') {
            $first = isset($user->first_name) ? trim((string) $user->first_name) : '';
        }
        $last = $get('billing_last_name');
        if ($last === '') {
            $last = isset($user->last_name) ? trim((string) $user->last_name) : '';
        }
        if ($first === '' && $last === '' && isset($user->display_name)) {
            // Last-resort: split the display name so the operator has something
            // to confirm/edit rather than two blank name fields.
            $parts = preg_split('/\s+/', trim((string) $user->display_name), 2);
            $first = $parts[0] ?? '';
            $last  = $parts[1] ?? '';
        }

        $email = isset($user->user_email) ? trim((string) $user->user_email) : '';
        if ($email === '') {
            $email = $get('billing_email');
        }

        // Contact -----------------------------------------------------------
        $phone1 = self::normalize_phone($get('billing_phone'));
        $phone2 = $get('mealsdb_client_phone_2');
        if ($phone2 === '') {
            $phone2 = $get('billing_phone_2');
        }
        $phone2 = self::normalize_phone($phone2);

        // Billing address ---------------------------------------------------
        $b_street = $get('billing_address_1');
        $b_city   = $get('billing_city');
        $b_prov   = self::normalize_province($get('billing_state'));
        $b_postal = self::normalize_postal($get('billing_postcode'));

        // Delivery (shipping) address — fall back to billing when shipping is
        // empty, matching how the operator treats a single-address client.
        $d_street = $get('shipping_address_1');
        $d_city   = $get('shipping_city');
        $d_prov   = $get('shipping_state');
        $d_postal = $get('shipping_postcode');
        $shipping_present = ($d_street !== '' || $d_city !== '' || $d_prov !== '' || $d_postal !== '');
        if (!$shipping_present) {
            $d_street = $b_street;
            $d_city   = $b_city;
            $d_prov   = $get('billing_state');
            $d_postal = $get('billing_postcode');
        }
        $d_prov   = self::normalize_province($d_prov);
        $d_postal = self::normalize_postal($d_postal);

        // Service preferences ----------------------------------------------
        $payment   = $get('payment_method');
        $ord_freq  = $get('ordering_frequency');
        $del_freq  = $get('delivery_frequency');
        $contrib   = $get('contribution');
        $del_fee   = $get('delivery_fee');

        $fields = [
            'first_name'                   => $first,
            'last_name'                    => $last,
            'client_email'                 => $email,
            'phone_primary'                => $phone1,
            'phone_secondary'              => $phone2,
            'address_street_name'          => $b_street,
            'address_city'                 => $b_city,
            'address_province'             => $b_prov,
            'address_postal'               => $b_postal,
            'delivery_address_street_name' => $d_street,
            'delivery_address_city'        => $d_city,
            'delivery_address_province'    => $d_prov,
            'delivery_address_postal'      => $d_postal,
            'payment_method'               => $payment,
            'ordering_frequency'           => self::int_or_blank($ord_freq),
            'delivery_frequency'           => self::int_or_blank($del_freq),
            'client_contribution'          => self::float_or_blank($contrib),
            'delivery_fee'                 => self::float_or_blank($del_fee),
        ];

        // Return only populated fields so Pull Data never clears a field the
        // operator may have already filled and the WP user lacks.
        return array_filter($fields, static function ($v) {
            return $v !== '' && $v !== null;
        });
    }

    /**
     * Normalise a province input to its 2-letter code via the form's canonical
     * normaliser (single source of truth — no reimplementation here).
     */
    private static function normalize_province(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (class_exists('MealsDB_Client_Form')) {
            return MealsDB_Client_Form::to_province_code($value);
        }
        return strtoupper($value);
    }

    /**
     * Normalise a Canadian postal code to the form's A1A1A1 shape (uppercase,
     * no spaces). Leaves an unrecognisable value uppercased/stripped so the
     * operator sees validate()'s named error rather than a silent drop.
     */
    private static function normalize_postal(string $value): string {
        $stripped = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($value)));
        return substr((string) $stripped, 0, 6);
    }

    /**
     * Normalise a phone number to the (###)-###-#### shape validate() expects.
     *
     * Strips to digits, drops a leading country-code 1 on an 11-digit NANP
     * number, and only reformats when exactly 10 digits remain; otherwise the
     * trimmed original is returned so the operator can correct it on screen.
     */
    private static function normalize_phone(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $digits = preg_replace('/\D+/', '', $value);
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            return sprintf('(%s)-%s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        }
        return $value;
    }

    /**
     * Return an integer string for a non-zero numeric input, else ''.
     */
    private static function int_or_blank(string $value): string {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return '';
        }
        $int = (int) $value;
        return $int !== 0 ? (string) $int : '';
    }

    /**
     * Return a trimmed numeric string for a non-zero amount, else ''.
     */
    private static function float_or_blank(string $value): string {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return '';
        }
        return ((float) $value) !== 0.0 ? $value : '';
    }
}
