<?php
/**
 * Private Customer Intake — auto-promote WC users into meals_clients
 * on their first active-status order.
 *
 * Listens on woocommerce_order_status_changed. When an order transitions
 * from an inactive / draft status into an active one, and the order
 * belongs to a logged-in WC user who doesn't yet have a meals_clients
 * row, a skeleton Private record is inserted. Operational fields
 * (delivery_day, zone, fee) stay blank until an admin fills them in via
 * the client form.
 *
 * Guarded against:
 *   - guest orders (customer_id = 0)
 *   - intra-active status moves (e.g. processing -> completed) so the
 *     log doesn't churn on every normal WC state change
 *   - existing meals_clients rows (idempotent via maybe_promote)
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Private_Intake {

    /** Statuses where the business considers the order "real". */
    private const ACTIVE_STATUSES = ['pending', 'processing', 'on-hold', 'completed', 'paid'];

    /** Statuses that are drafts / failures / cancellations — promotion is skipped. */
    private const INACTIVE_STATUSES = [
        'pending-payment',
        'failed',
        'cancelled',
        'refunded',
        'trash',
        'draft',
        'auto-draft',
        'checkout-draft',
    ];

    public static function init(): void {
        add_action('woocommerce_order_status_changed', [self::class, 'on_order_status_changed'], 10, 4);
    }

    /**
     * WC fires this with four arguments: order id, from status, to status, order object.
     * The order argument is WC_Order on modern WC but we re-fetch defensively.
     *
     * @param int    $order_id
     * @param string $from
     * @param string $to
     * @param mixed  $order    Expected WC_Order.
     */
    public static function on_order_status_changed(int $order_id, string $from, string $to, $order = null): void {
        if (!in_array($to, self::ACTIVE_STATUSES, true)) {
            return;
        }
        // Ignore intra-active transitions — the promotion already ran
        // when the order first entered an active state.
        if (in_array($from, self::ACTIVE_STATUSES, true)) {
            return;
        }

        if (!($order instanceof WC_Order)) {
            if (!function_exists('wc_get_order')) {
                return;
            }
            $order = wc_get_order($order_id);
            if (!($order instanceof WC_Order)) {
                return;
            }
        }

        $wp_user_id = (int) $order->get_customer_id();
        if ($wp_user_id <= 0) {
            // Guest order — nothing to promote. meals_clients requires
            // a wp_user_id for the sync / allocation relationships, so
            // guest intake is a separate feature.
            return;
        }

        self::maybe_promote($wp_user_id, $order);
    }

    /**
     * Create a Private meals_clients record for this WC user if one
     * doesn't already exist. Safe to call repeatedly.
     *
     * @return int|null The client_id (existing or newly created), or
     *                  null when the user can't be loaded.
     */
    public static function maybe_promote(int $wp_user_id, ?WC_Order $order = null): ?int {
        if ($wp_user_id <= 0) {
            return null;
        }

        $existing = MealsDB_Clients_Repository::get_by_wp_user_id($wp_user_id);
        if (is_array($existing) && !empty($existing['client_id'])) {
            return (int) $existing['client_id'];
        }

        $payload = self::build_field_payload($wp_user_id, $order);
        if (empty($payload)) {
            // FOLLOW-UP DIRECTIVE A: a promotion that resolved a user but built no
            // payload produces no record — surface it rather than returning null
            // silently, so a government user who ordered but was not created is
            // diagnosable (this is one of the candidate causes named in the
            // directive). error_log breadcrumb only: this is a genuine gap, not
            // the hot already-exists path (which returns above without logging).
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::error(sprintf(
                    '[MealsDB Promote] empty payload — no record created for user=%d (build_field_payload returned nothing).',
                    $wp_user_id
                ));
            }
            return null;
        }

        // created_at is not in the meals_clients schema — the table
        // doesn't track creation timestamps (see class-schema.php).
        // The audit log entry below carries the timestamp instead.

        // Directive 7 (ITEM 1): resolve client_type from the user's customer_group
        // through the SAME shared mapper phase 1 uses — promotion no longer
        // hard-codes 'Private'. A recognised government group maps to SDNB /
        // Veteran; anything else defaults to Private (the client is placing an
        // order and needs a record — promotion cannot skip the way phase 1 does).
        // A NON-'private' fallback (blank or unrecognised group) is recorded so a
        // client that lands as Private by default is visible, not silently
        // mistyped. This is the defect that put 13 SDNB clients on no invoice.
        $group_raw = function_exists('get_user_meta')
            ? (string) get_user_meta($wp_user_id, 'customer_group', true)
            : '';
        $mapped_type = class_exists('MealsDB_Migration_Consolidated')
            ? MealsDB_Migration_Consolidated::customer_group_to_client_type($group_raw)
            : null;
        $client_type = $mapped_type ?? 'Private';

        if ($mapped_type === null
            && strtolower(trim($group_raw)) !== 'private'
            && class_exists('MealsDB_Event_Log')
        ) {
            MealsDB_Event_Log::record([
                'severity'  => 'warning',
                'category'  => 'sync',
                'subsystem' => 'private_intake',
                'event'     => 'promote.customer_group_fallback',
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => sprintf(
                    'Promotion defaulted user %d to Private — customer_group "%s" is blank or unrecognised.',
                    $wp_user_id,
                    $group_raw
                ),
                'context'   => ['wp_user_id' => $wp_user_id, 'customer_group' => $group_raw],
            ]);
        }

        // Directive 7 (ITEM 3) — divergence audit between the two creation paths.
        // Phase 1 (run_phase_create_clients) sources the full government field set
        // from legacy usermeta; the promotion payload (build_field_payload) was
        // written for PRIVATE clients and deliberately does NOT set the
        // government-only fields. Now that promotion can create SDNB/Veteran
        // records, those omissions matter. Fields phase 1 sets that promotion
        // does NOT: allowance_mains, allowance_sides, delivery_area_zone (M/S
        // service centre), service_center_charged, service_id, requisition_id(+
        // index), requisition_period, units, client_contribution, vet_health_card
        // (+index), service_name_zone, service_commence_date,
        // expected_termination_date, open_date, individual_id(+index).
        // client_type (fixed here) and delivery_initials(+index) (generated below)
        // were the two the directive named; the rest are left to the existing
        // enrichment pipeline (phase 6 / backfill-enrich) rather than duplicating
        // phase 1's extraction here — a deliberate, DOCUMENTED difference. A
        // government promotion emits an event so the enrichment need is visible.
        if ($mapped_type !== null && class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'info',
                'category'  => 'sync',
                'subsystem' => 'private_intake',
                'event'     => 'promote.government_needs_enrichment',
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => sprintf(
                    'Promoted user %d as %s — allowances/zone/billing identifiers are not set by promotion; run enrichment.',
                    $wp_user_id,
                    $client_type
                ),
                'context'   => ['wp_user_id' => $wp_user_id, 'client_type' => $client_type],
            ]);
        }

        // client_type is authoritative — it overrides any value from $payload.
        $data = array_merge([
            'active'      => 1,
            'wp_user_id'  => $wp_user_id,
        ], $payload, [
            'client_type' => $client_type,
        ]);

        // Directive 7 (ITEM 2): generate delivery_initials + its index the way
        // phase 1 does. A promoted client with a blank code prints a blank
        // "Name:" line on the packer slip (how this defect was first spotted).
        // Use the SHARED generator (name-based patterns → unique random, with the
        // app-level uniqueness check) rather than a second routine; only generate
        // when the payload did not already carry a valid 3-letter code. NB: the
        // schema index on delivery_initials is a plain INDEX, not a DB UNIQUE
        // constraint — uniqueness is enforced by the validator, so writing the
        // index here cannot raise a duplicate-key error.
        $initials = strtoupper(trim((string) ($data['delivery_initials'] ?? '')));
        if ($initials === '' || !preg_match('/^[A-Z]{3}$/', $initials)) {
            $generated = class_exists('MealsDB_Initials_Validator')
                ? MealsDB_Initials_Validator::generate(
                    (string) ($data['first_name'] ?? ''),
                    (string) ($data['last_name'] ?? ''),
                    []
                )
                : false;
            $initials = ($generated === false || $generated === null) ? '' : (string) $generated;
        }
        if ($initials !== '') {
            $data['delivery_initials'] = $initials;
            $data['delivery_initials_index'] = class_exists('MealsDB_Encryption')
                ? MealsDB_Encryption::create_index($initials)
                : null;
        }

        $client_id = MealsDB_Clients_Repository::create($data);
        if ($client_id <= 0) {
            // FOLLOW-UP DIRECTIVE A: a failed promotion INSERT used to return null
            // silently — the exact silent-insert shape that cost a full debugging
            // session on 2026-08-31 (a varchar column rejecting free text). Make
            // it loud and named: the repository records the offending column
            // (last_failed_column), so surface it with the user, resolved type and
            // group here. This is what a government-user order producing NO client
            // record will now show, instead of nothing.
            $failed_column = class_exists('MealsDB_Clients_Repository')
                ? MealsDB_Clients_Repository::last_failed_column()
                : null;
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'error',
                    'category'  => 'sync',
                    'subsystem' => 'private_intake',
                    'event'     => 'promote.insert_failed',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'   => sprintf(
                        'Promotion INSERT failed for user %d (type %s, customer_group "%s")%s — no client record created.',
                        $wp_user_id,
                        $client_type,
                        $group_raw,
                        $failed_column !== null ? ' — offending column: ' . $failed_column : ''
                    ),
                    'context'   => [
                        'wp_user_id'     => $wp_user_id,
                        'client_type'    => $client_type,
                        'customer_group' => $group_raw,
                        'failed_column'  => $failed_column,
                    ],
                ]);
            }
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::error(sprintf(
                    '[MealsDB Promote] INSERT failed user=%d type=%s group="%s" column=%s',
                    $wp_user_id,
                    $client_type,
                    $group_raw,
                    $failed_column ?? 'unknown'
                ));
            }
            return null;
        }

        $payload = [
            'wp_user_id'  => $wp_user_id,
            'client_id'   => $client_id,
            // Directive 7: record the RESOLVED type (may be SDNB/Veteran now, not
            // just Private) so the audit trail shows what a promotion produced.
            'client_type' => $client_type,
            'trigger'     => $order ? 'first_order' : 'manual',
            'order_id'    => $order ? (int) $order->get_id() : null,
        ];
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($payload)
            : json_encode($payload);

        MealsDB_Logger::log(
            'private_client_promoted',
            $client_id,
            'intake',
            null,
            $encoded === false ? null : $encoded,
            'mealsdb'
        );

        return $client_id;
    }

    /**
     * Resolve every meals_clients column the promotion path knows how
     * to populate from WC + WP usermeta + the triggering order.
     *
     * Used by maybe_promote() to assemble the create payload, and by
     * MealsDB_Migration_Consolidated::enrich_existing() to refill
     * blank columns on rows that were created before the field map
     * was wired up. Returns an empty array when the WP user can't be
     * loaded (caller treats that as "skip").
     *
     * @return array<string, mixed> Column → value map. Always-present
     *                              keys cover identity + address;
     *                              service / notes / numeric fields
     *                              only appear when usermeta supplies
     *                              a non-empty value.
     */
    public static function build_field_payload(int $wp_user_id, ?WC_Order $order = null): array {
        $user = get_userdata($wp_user_id);
        if (!($user instanceof WP_User)) {
            return [];
        }

        // Identity: prefer WC billing fields, fall back to the WP user
        // profile, then to the triggering order's billing address.
        $first_name = self::first_non_empty([
            (string) get_user_meta($wp_user_id, 'billing_first_name', true),
            (string) ($user->first_name ?? ''),
            $order ? (string) $order->get_billing_first_name() : '',
        ]);
        $last_name = self::first_non_empty([
            (string) get_user_meta($wp_user_id, 'billing_last_name', true),
            (string) ($user->last_name ?? ''),
            $order ? (string) $order->get_billing_last_name() : '',
        ]);
        $phone = self::first_non_empty([
            (string) get_user_meta($wp_user_id, 'billing_phone', true),
            $order ? (string) $order->get_billing_phone() : '',
        ]);
        $email = (string) ($user->user_email ?? '');

        // Address: order-on-file wins over usermeta. A first-time
        // customer may have placed the triggering order before WC ever
        // wrote billing_/shipping_ usermeta on the WP profile, so the
        // order itself is the most reliable source.
        $billing_address  = self::resolve_address($wp_user_id, $order, 'billing');
        $shipping_address = self::resolve_address($wp_user_id, $order, 'shipping');

        // shipping_address_2 stores the delivery zone (e.g., "Zone 3")
        // — see directives/wp-custom-user-fields-map.md. Order takes
        // priority for the same first-order reason as the address fields.
        $delivery_area_name = self::first_non_empty([
            $order ? (string) $order->get_shipping_address_2() : '',
            (string) get_user_meta($wp_user_id, 'shipping_address_2', true),
        ]);

        // U09-clients-repo-10: normalise phone / province / postal into the
        // form-valid shapes ((###)-###-####, 2-letter code, A1A1A1) using the
        // SAME helpers the Pull-Data mapper uses, so an intake-created row is
        // not later rejected by MealsDB_Client_Form::validate() (which enforces
        // those exact shapes). Uses the shared MealsDB_WP_User_Mapper
        // normalizers — no reimplementation.
        $normalize = class_exists('MealsDB_WP_User_Mapper');
        $payload = [
            'first_name'            => $first_name,
            'last_name'             => $last_name,
            'client_phone_1'        => $normalize ? MealsDB_WP_User_Mapper::normalize_phone($phone) : $phone,
            'client_email'          => $email,
            'street_name'           => $billing_address['address_1'],
            'city'                  => $billing_address['city'],
            'province'              => $normalize ? MealsDB_WP_User_Mapper::normalize_province($billing_address['state']) : $billing_address['state'],
            'postal_code'           => $normalize ? MealsDB_WP_User_Mapper::normalize_postal($billing_address['postcode']) : $billing_address['postcode'],
            'delivery_street_name'  => $shipping_address['address_1'],
            'delivery_city'         => $shipping_address['city'],
            'delivery_province'     => $normalize ? MealsDB_WP_User_Mapper::normalize_province($shipping_address['state']) : $shipping_address['state'],
            'delivery_postal_code'  => $normalize ? MealsDB_WP_User_Mapper::normalize_postal($shipping_address['postcode']) : $shipping_address['postcode'],
            'delivery_area_name'    => $delivery_area_name,
        ];

        // Service / ordering / notes fields are usermeta-only. The
        // legacy Enzebra "Custom User Fields" plugin populated these on
        // every WP user; mapping per directives/wp-custom-user-fields-map.md.
        // Empty meta keys leave the corresponding column at its default.
        $string_meta_to_column = [
            'payment_method'           => 'payment_method',
            'ordering_contact_method'  => 'ordering_contact_method',
            'freeze_capacity'          => 'freezer_capacity',
            'customer_comments'        => 'customer_comments',
            'dietary_needs'            => 'diet_concerns',
        ];
        foreach ($string_meta_to_column as $meta_key => $column) {
            $value = trim((string) get_user_meta($wp_user_id, $meta_key, true));
            if ($value !== '') {
                $payload[$column] = $value;
            }
        }

        // Numeric meta — only insert when the meta value is non-empty
        // and numeric, so blank rows stay NULL instead of coercing to 0.
        $int_meta_to_column = [
            'ordering_frequency' => 'ordering_frequency',
            'delivery_frequency' => 'delivery_frequency',
        ];
        foreach ($int_meta_to_column as $meta_key => $column) {
            $value = trim((string) get_user_meta($wp_user_id, $meta_key, true));
            if ($value !== '' && is_numeric($value)) {
                $payload[$column] = (int) $value;
            }
        }

        $delivery_fee_raw = trim((string) get_user_meta($wp_user_id, 'delivery_fee', true));
        if ($delivery_fee_raw !== '' && is_numeric($delivery_fee_raw)) {
            $payload['delivery_fee'] = number_format((float) $delivery_fee_raw, 2, '.', '');
        }

        // Bag initials live in WordPress's `nickname` user meta —
        // labelled "Nickname (required)" in the legacy Enzebra custom
        // user fields UI. The schema column is VARCHAR(3) and the
        // canonical validator (MealsDB_Initials::validate_code) accepts
        // /^[A-Z]{3}$/ after uppercasing, so anything that isn't
        // exactly three letters is treated as unfilled rather than
        // truncated. The deterministic-hash shadow column is left
        // alone — class-client-form.php notes it's only a defensive
        // shadow; uniqueness lookups read the plaintext column.
        $nickname = strtoupper(trim((string) get_user_meta($wp_user_id, 'nickname', true)));
        if (preg_match('/^[A-Z]{3}$/', $nickname) === 1) {
            // U09: enforce bag-initials uniqueness before copying a legacy
            // nickname across — the way the client form already does. The schema
            // index on delivery_initials is a plain INDEX, NOT UNIQUE, so nothing
            // at the DB level stops two clients getting the same bag label, which
            // is exactly the collision the initials subsystem exists to prevent.
            // exists_in_db() fails CLOSED (a lookup error is treated as "taken"),
            // so a transient DB error can't smuggle a duplicate through. On a
            // clash we leave delivery_initials blank: these are skeleton rows an
            // admin completes anyway, and a blank is safer than a shared label.
            // (When MealsDB_Initials isn't loaded — e.g. a stripped test harness —
            // we can't check, so we preserve the legacy copy-across behaviour.)
            $initials_taken = class_exists('MealsDB_Initials')
                && MealsDB_Initials::exists_in_db($nickname);
            if ($initials_taken) {
                if (class_exists('MealsDB_Event_Log')) {
                    MealsDB_Event_Log::record([
                        'severity'  => 'warning',
                        'category'  => 'intake',
                        'subsystem' => 'private_intake',
                        'event'     => 'delivery_initials.collision',
                        'outcome'   => 'degraded',
                        'message'   => sprintf(
                            'Skipped copying legacy nickname "%s" into delivery_initials for WP user %d — the code is already assigned to another client. Left blank for an admin to resolve.',
                            $nickname,
                            $wp_user_id
                        ),
                        'context'   => ['wp_user_id' => $wp_user_id, 'initials' => $nickname],
                    ]);
                }
            } else {
                $payload['delivery_initials'] = $nickname;
            }
        }

        return $payload;
    }

    /**
     * Resolve a billing or shipping address for the user, preferring
     * the triggering order over WP usermeta.
     *
     * @param string $type Either 'billing' or 'shipping'.
     * @return array{address_1:string, city:string, state:string, postcode:string}
     */
    private static function resolve_address(int $wp_user_id, ?WC_Order $order, string $type): array {
        $meta_prefix = $type === 'shipping' ? 'shipping_' : 'billing_';

        $from_order = ['address_1' => '', 'city' => '', 'state' => '', 'postcode' => ''];
        if ($order instanceof WC_Order) {
            $getter_prefix = 'get_' . $type . '_';
            // postcode getter is named *_postcode on WC_Order regardless of type.
            $from_order['address_1'] = (string) $order->{$getter_prefix . 'address_1'}();
            $from_order['city']      = (string) $order->{$getter_prefix . 'city'}();
            $from_order['state']     = (string) $order->{$getter_prefix . 'state'}();
            $from_order['postcode']  = (string) $order->{$getter_prefix . 'postcode'}();
        }

        return [
            'address_1' => self::first_non_empty([
                $from_order['address_1'],
                (string) get_user_meta($wp_user_id, $meta_prefix . 'address_1', true),
            ]),
            'city' => self::first_non_empty([
                $from_order['city'],
                (string) get_user_meta($wp_user_id, $meta_prefix . 'city', true),
            ]),
            'state' => self::first_non_empty([
                $from_order['state'],
                (string) get_user_meta($wp_user_id, $meta_prefix . 'state', true),
            ]),
            'postcode' => self::first_non_empty([
                $from_order['postcode'],
                (string) get_user_meta($wp_user_id, $meta_prefix . 'postcode', true),
            ]),
        ];
    }

    /**
     * Return the first non-empty trimmed string from the list, or ''.
     *
     * @param string[] $candidates
     */
    private static function first_non_empty(array $candidates): string {
        foreach ($candidates as $candidate) {
            $trimmed = trim((string) $candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }
        return '';
    }

    /**
     * Statuses considered "active" (order is real). Exposed for tests.
     *
     * @return string[]
     */
    public static function active_statuses(): array {
        return self::ACTIVE_STATUSES;
    }

    /**
     * Statuses considered "inactive" (draft / failed / cancelled). Exposed for tests.
     *
     * @return string[]
     */
    public static function inactive_statuses(): array {
        return self::INACTIVE_STATUSES;
    }
}
