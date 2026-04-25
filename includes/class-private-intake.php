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

        $user = get_userdata($wp_user_id);
        if (!($user instanceof WP_User)) {
            return null;
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

        $data = [
            'client_type'           => 'Private',
            'active'                => 1,
            'wp_user_id'            => $wp_user_id,
            'first_name'            => $first_name,
            'last_name'             => $last_name,
            'client_phone_1'        => $phone,
            'client_email'          => $email,
            'street_name'           => $billing_address['address_1'],
            'city'                  => $billing_address['city'],
            'province'              => $billing_address['state'],
            'postal_code'           => $billing_address['postcode'],
            'delivery_street_name'  => $shipping_address['address_1'],
            'delivery_city'         => $shipping_address['city'],
            'delivery_province'     => $shipping_address['state'],
            'delivery_postal_code'  => $shipping_address['postcode'],
        ];

        // created_at is not in the meals_clients schema — the table
        // doesn't track creation timestamps (see class-schema.php).
        // The audit log entry below carries the timestamp instead.

        $client_id = MealsDB_Clients_Repository::create($data);
        if ($client_id <= 0) {
            return null;
        }

        $payload = [
            'wp_user_id' => $wp_user_id,
            'client_id'  => $client_id,
            'trigger'    => $order ? 'first_order' : 'manual',
            'order_id'   => $order ? (int) $order->get_id() : null,
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
