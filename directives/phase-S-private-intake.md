# Phase S: Private Customer Intake & Packing Slip Enhancement

## Goal

Bring private customers into the plugin's `meals_clients` tracking as first-class records, with automatic promotion on first real order. Enhance the existing packing slip to include pricing information for private customer entries — their packing slip becomes a mini-invoice they can review against the cash collected by the delivery driver.

This phase is independent of R1/R2 and can ship on its own timeline.

---

## Context

Private customers currently exist inconsistently in the plugin:

- The driver slip generator already calculates cash collection for them (cash + delivery_fee, or just delivery_fee for non-cash)
- The private customer sales report (Phase L) queries them from `meals_clients`
- BUT: the sync system filters most operations to `client_type IN ('SDNB', 'Veteran')`
- AND: not every WC private customer has a `meals_clients` record — there's no systematic intake

The new packing slip feature requires reliable tracking: if a private customer receives a delivery but doesn't have a `meals_clients` record, the slip can't carry their initials, delivery zone, or collection amount.

Rather than mass-importing every WC user into `meals_clients` (which would include dormant accounts, bots, and abandoned signups), this phase adds an automatic **promotion trigger** on first real order placement, plus a filtered historical backfill for existing active customers.

---

## Part A: Promotion trigger

### New hook handler

**File:** `includes/class-private-intake.php`

Register on WooCommerce order status transitions. When an order moves **into** an active status (from any non-active status), check whether a `meals_clients` record exists for the customer; if not, create a skeleton one.

```php
class MealsDB_Private_Intake {
    
    private const ACTIVE_STATUSES   = ['pending', 'processing', 'on-hold', 'completed', 'paid'];
    private const INACTIVE_STATUSES = ['pending-payment', 'failed', 'cancelled', 'refunded', 'trash', 'draft', 'auto-draft', 'checkout-draft'];
    
    public static function init(): void {
        add_action('woocommerce_order_status_changed', [self::class, 'on_order_status_changed'], 10, 4);
    }
    
    public static function on_order_status_changed(int $order_id, string $from, string $to, $order): void {
        // Only promote on transitions FROM inactive TO active
        if (!in_array($to, self::ACTIVE_STATUSES, true)) {
            return;
        }
        if (in_array($from, self::ACTIVE_STATUSES, true)) {
            return; // Already active, ignore intra-active transitions
        }
        
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) return;
        }
        
        $wp_user_id = $order->get_customer_id();
        if ($wp_user_id <= 0) {
            // Guest order — no user to promote
            return;
        }
        
        self::maybe_promote($wp_user_id, $order);
    }
    
    /**
     * Create a meals_clients record for this WC user if one doesn't exist,
     * populated with basic identity from the user's WC profile.
     * 
     * Safe to call repeatedly — no-ops if the record already exists.
     */
    public static function maybe_promote(int $wp_user_id, ?WC_Order $order = null): ?int {
        // Check if client already exists
        $existing = MealsDB_Clients_Repository::get_by_wp_user_id($wp_user_id);
        if ($existing) {
            return (int) $existing['client_id'];
        }
        
        $user = get_userdata($wp_user_id);
        if (!$user) return null;
        
        // Pull identity from WC profile; fall back to the order's billing address
        $first_name = trim(get_user_meta($wp_user_id, 'billing_first_name', true) 
                         ?: $user->first_name 
                         ?: ($order ? $order->get_billing_first_name() : ''));
        $last_name  = trim(get_user_meta($wp_user_id, 'billing_last_name', true) 
                         ?: $user->last_name 
                         ?: ($order ? $order->get_billing_last_name() : ''));
        $phone      = trim(get_user_meta($wp_user_id, 'billing_phone', true) 
                         ?: ($order ? $order->get_billing_phone() : ''));
        $email      = $user->user_email;
        
        // Create skeleton record — operational fields (delivery_day, zone, etc.) stay blank
        // until an admin fills them in via the client form
        $client_id = MealsDB_Clients_Repository::create([
            'client_type'   => 'Private',
            'active'        => 1,
            'wp_user_id'    => $wp_user_id,
            'first_name'    => $first_name,    // encrypted by repository
            'last_name'     => $last_name,     // encrypted by repository
            'client_phone_1' => $phone,
            'client_email'  => $email,
            'created_at'    => current_time('mysql'),
        ]);
        
        if ($client_id) {
            MealsDB_Logger::log('private_client_promoted', [
                'wp_user_id' => $wp_user_id,
                'client_id'  => $client_id,
                'trigger'    => $order ? 'first_order' : 'manual',
                'order_id'   => $order ? $order->get_id() : null,
            ]);
        }
        
        return $client_id;
    }
}
```

Register in `meals-db-main.php` alongside the other hook registrations:

```php
MealsDB_Private_Intake::init();
```

### Why status change rather than `woocommerce_new_order`

`woocommerce_new_order` fires when an order is created in **any** status, including `pending-payment` and `checkout-draft` — exactly the states bots leave orders in when checkout fails or is abandoned. Waiting for a transition into an active status means only real orders trigger promotion.

### Guest orders

Guest orders (customer_id = 0) do not trigger promotion. The `meals_clients` table requires a `wp_user_id` for its sync relationship to work. If the business ever wants to track guest customers, that's a separate feature.

### Existing customers placing a new order

If a customer has a WC account from before this phase ships but hasn't placed an order since the phase was deployed, their next active-status order will trigger promotion. The backfill (Part B) handles customers who have already placed orders historically.

---

## Part B: Historical backfill

### Backfill service

**File:** `includes/services/class-backfill-private-clients.php`

Walks existing WC users and promotes those matching the criteria:
- Has at least one WC order in an active status
- Order is within the last 24 months
- Has a shipping address filled in (i.e., was actually getting delivery, not a pickup signup)
- Does not already have a `meals_clients` record

```php
class MealsDB_Backfill_Private_Clients {
    
    /**
     * Identify WC users eligible for promotion but without an existing meals_clients record.
     * Does NOT modify data — use this for the preview / dry-run.
     */
    public static function preview(int $lookback_months = 24): array {
        global $wpdb;
        
        $cutoff_date = gmdate('Y-m-d H:i:s', strtotime("-{$lookback_months} months"));
        $orders_table = $wpdb->prefix . 'wc_orders';
        $addresses_table = $wpdb->prefix . 'wc_order_addresses';
        $active_statuses = "('wc-pending','wc-processing','wc-on-hold','wc-completed','wc-paid')";
        
        // Find users with qualifying orders
        $sql = "
            SELECT DISTINCT o.customer_id
            FROM {$orders_table} o
            INNER JOIN {$addresses_table} a 
                ON a.order_id = o.id AND a.address_type = 'shipping'
            WHERE o.customer_id > 0
              AND o.status IN {$active_statuses}
              AND o.type = 'shop_order'
              AND o.date_created_gmt >= %s
              AND TRIM(COALESCE(a.address_1, '')) <> ''
        ";
        
        $qualifying_user_ids = $wpdb->get_col($wpdb->prepare($sql, $cutoff_date));
        
        // Exclude those who already have a meals_clients record
        $existing_user_ids = MealsDB_Clients_Repository::get_all_wp_user_ids();
        $to_promote = array_diff($qualifying_user_ids, $existing_user_ids);
        
        // Return preview rows for the admin UI
        return array_map(function($uid) {
            $u = get_userdata($uid);
            if (!$u) return null;
            return [
                'wp_user_id' => $uid,
                'email'      => $u->user_email,
                'name'       => trim($u->first_name . ' ' . $u->last_name) ?: $u->display_name,
                'order_count' => wc_get_customer_order_count($uid),
            ];
        }, $to_promote);
    }
    
    /**
     * Promote all eligible users. Wrap in a transaction-per-user; errors on individual
     * users do not abort the batch.
     */
    public static function run(int $lookback_months = 24, bool $dry_run = false): array {
        $preview_rows = self::preview($lookback_months);
        
        $stats = ['eligible' => count($preview_rows), 'promoted' => 0, 'errors' => 0, 'skipped' => 0];
        
        if ($dry_run) {
            return $stats;
        }
        
        foreach ($preview_rows as $row) {
            if (!$row || empty($row['wp_user_id'])) {
                $stats['skipped']++;
                continue;
            }
            try {
                $client_id = MealsDB_Private_Intake::maybe_promote((int) $row['wp_user_id']);
                if ($client_id) {
                    $stats['promoted']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                error_log('[MealsDB Backfill] Failed to promote user ' . $row['wp_user_id'] . ': ' . $e->getMessage());
            }
        }
        
        return $stats;
    }
}
```

### Admin UI

Add a "Private Customer Backfill" panel to `views/settings.php`:

- Input: lookback months (default 24)
- "Preview" button — shows count and table of eligible users without modifying data
- "Run Backfill" button — promotes all eligible users, shows stats

Wire through a new AJAX endpoint in `includes/ajax/class-ajax-settings.php`.

### Deactivation sweep

One-time cleanup: add a button that identifies existing `meals_clients` records with `client_type = 'Private'` whose associated WC user has **no** active orders in the lookback window, and offers to deactivate them (sets `active = 0`, does not delete). This catches any pre-existing private records created before Phase S that were spam or stale.

---

## Part C: Packing slip enhancement

### Packing slip payload — new pricing fields

**File:** `includes/services/class-delivery-slip-generator.php`

The existing `generate_packing_slip()` method produces entries with `initials`, `zone`, `area_name`, `items`, `mains_count`, `sides_count`, `side_detail`. For private customers, enrich each entry with pricing data pulled from the WC order.

Add to each entry (private customers only; SDNB/Veteran entries leave these as `null`):

```php
'pricing' => [
    'items' => [
        [
            'wc_product_id' => int,
            'name'          => string,
            'quantity'      => int,
            'unit_price'    => float,  // subtotal / quantity
            'line_total'    => float,  // subtotal for this line
        ],
        // ... one per line item
    ],
    'subtotal'            => float,
    'tax'                 => float,
    'delivery_fee'        => float,
    'grand_total'         => float,
    'payment_method'      => string,   // 'cash', 'stripe', 'bank', etc.
    'collection_amount'   => float|null,  // what the driver should collect; null if pre-paid
    'is_prepaid'          => bool,
],
```

The entry still carries `items` (with freezer ordering applied) for the warehouse-side packing logic. The `pricing.items` array is a parallel list for the customer-facing invoice section, in display order (match WC's line item order, or sort by SKU — pick one and be consistent).

**Unit price calculation:** use `$item->get_subtotal() / $item->get_quantity()` with `MealsDB_Money` for rounding safety. `$item->get_subtotal()` is the pre-tax subtotal in WC.

**Tax breakdown:** use `$item->get_subtotal_tax()` for per-line tax if the template wants to show tax per line. For a simpler invoice, just show tax as a single line using `$wc_order->get_total_tax()`.

**Collection amount logic** (same as driver slip, kept consistent):
- If `payment_method === 'cash'`: `collection_amount = grand_total + delivery_fee`
- If `payment_method !== 'cash'` and `delivery_fee > 0`: `collection_amount = delivery_fee`
- Otherwise: `collection_amount = null` (prepaid, no collection needed) and `is_prepaid = true`

### Shared collection calculator

To guarantee the driver slip and packing slip always show the same number, extract the collection logic into a helper:

**File:** `includes/services/class-collection-calculator.php`

```php
class MealsDB_Collection_Calculator {
    /**
     * Compute what the driver collects for a private customer delivery.
     * 
     * @return array{collect: ?float, is_prepaid: bool}
     */
    public static function for_private(float $total, float $delivery_fee, string $payment_method): array {
        if ($payment_method === 'cash') {
            return ['collect' => $total + $delivery_fee, 'is_prepaid' => false];
        }
        if ($delivery_fee > 0) {
            return ['collect' => $delivery_fee, 'is_prepaid' => false];
        }
        return ['collect' => null, 'is_prepaid' => true];
    }
    
    /**
     * Compute collection for government clients (contribution + delivery fee on first delivery of month).
     * Existing logic lifted from generate_driver_slips().
     */
    public static function for_government(
        float $delivery_fee, 
        float $client_contribution, 
        bool $is_first_delivery_of_month
    ): array {
        $contribution_due = $is_first_delivery_of_month && $client_contribution > 0 
            ? $client_contribution 
            : 0.0;
        return [
            'collect' => $delivery_fee + $contribution_due,
            'contribution_due' => $contribution_due,
            'is_prepaid' => false,
        ];
    }
}
```

Update both `generate_driver_slips()` and `generate_packing_slip()` to use this helper. Neither should hand-roll the collection calculation anymore.

### Template changes

**File:** `views/daily-slips.php` (or wherever the slip HTML is rendered on screen/print)

The current packing slip template shows each entry as:
```
[Zone 1] RJH  (3 mains, 2 sides)
  3x Chicken Parmesan
  1x Garden Salad
  1x Rice Pudding
```

For private customer entries (detected by `$entry['pricing'] !== null`), append a pricing block:

```
[Zone 1] RJH  (3 mains, 2 sides)
  3x Chicken Parmesan          $8.50  $25.50
  1x Garden Salad              $4.48  $4.48
  1x Rice Pudding              $3.25  $3.25
  ─────────────────────────────────────────
  Subtotal:                          $33.23
  HST (15%):                          $4.98
  Delivery Fee:                      $10.00
  ─────────────────────────────────────────
  TOTAL:                             $48.21
  Payment: Cash  |  Collect: $48.21
```

The exact PDF layout will be designed later against your example slips. For now, the HTML view just needs to show the data correctly — the data contract is what's being specified here.

Add a CSS class `.mealsdb-private-pricing` around the pricing block so the PDF renderer can style it distinctly when that work happens.

### Zone-based mode

The zone-based slip generation (`generate_packing_slip_by_zones()`) needs the same pricing enhancement. Same code path — it calls the same per-entry build logic, just with a different client/order fetch.

---

## Part D: Sync system — include private clients

### Expand the sync query

**File:** `includes/services/sync/class-sync-query.php`

Line 121 currently filters `WHERE client_type IN ('SDNB', 'Veteran')`. Change to include `'Private'` as well:

```sql
WHERE client_type IN ('SDNB', 'Veteran', 'Private')
```

**File:** `includes/class-sync.php`

Lines 277 and 514 have similar filters. Update to include Private.

### Keep allocation engine government-only

**File:** `includes/services/class-allocation-engine.php` line 955  
**File:** `includes/services/class-backfill-allocations-engine.php` line 53

These should **remain** filtered to `client_type IN ('SDNB', 'Veteran')`. Private customers don't have monthly allowances; running them through the allocation engine would create meaningless zero-filled allocation rows.

Add a defensive early-return in `MealsDB_Allocation_Engine::allocate_order()`:

```php
// Skip allocation for private customers — they have no allowances
if (($client['client_type'] ?? '') === 'Private') {
    return;
}
```

This guards against the case where an order from a private customer somehow triggers the allocation hooks (which are registered on every WC order).

### Allocation hook filtering

**File:** `includes/class-allocation-hooks.php`

The hooks currently check for `mealsdb_client_user_id` meta on the order. That works fine — private customers won't have this meta set unless the order was placed via QuickOrder. If it IS set for a private customer (via QuickOrder for delivery), the allocation engine's internal guard (above) catches it.

---

## Part E: QuickOrder — private customer support verified

QuickOrder already handles private customers:
- No rate selector shown (rates are government-specific)
- Standard WC pricing applied via product lookup
- `last_order_date` / `last_call_date` updated

After Phase R2 adds `next_order_date` / `next_delivery_date` to the client record, those work naturally for private customers too — any private customer on a delivery rhythm gets the same scheduling as government clients. No Phase S changes needed to QuickOrder.

---

## Part F: Client list UI — default filter on client_type

**File:** `views/view-clients.php`

With private customers now populating `meals_clients`, the client list page could suddenly show thousands of rows when admins expect to see only government clients. Add a prominent client type filter:

- Default selected: `SDNB + Veteran` (the government clients admins usually look at)
- Options: All, SDNB only, Veteran only, Private only, SDNB + Veteran (default)
- Filter state persists in URL query string so it survives page reloads

Also add a visual indicator on each row (colored badge) showing client type, so nobody confuses a private customer's row with a government client's row.

---

## Part G: Tests

- `test-private-intake-promotion.php` — status transitions trigger promotion correctly
- `test-private-intake-idempotent.php` — `maybe_promote` does nothing if record exists
- `test-private-intake-guest-orders-skipped.php` — customer_id=0 does not promote
- `test-private-intake-inactive-statuses-skipped.php` — pending-payment → failed does not promote
- `test-backfill-private-clients-criteria.php` — preview returns correct eligible users
- `test-backfill-private-clients-dry-run.php` — dry run doesn't modify data
- `test-collection-calculator.php` — both `for_private` and `for_government` paths
- `test-packing-slip-private-pricing.php` — packing slip entries for private customers include pricing; SDNB/Veteran entries do not
- `test-allocation-engine-skips-private.php` — allocate_order early-returns for private customers

---

## Part H: What is NOT in Phase S

- PDF layout of the enhanced packing slip (separate phase after example slips are provided)
- Automated invoice/receipt emailing to private customers
- Payment collection automation (Stripe terminal, SumUp, etc.)
- Loyalty / frequent-customer tracking for private
- Any customer-facing portal
- Guest order support
- Bulk private customer operations (deletion, merge, etc.) beyond the one-time deactivation sweep

---

## Key constraints

- Private customer promotion is automatic on first active-status order — no admin action required for net-new customers
- Historical backfill is opt-in (admin clicks Run) with a preview step to avoid surprises
- The allocation engine and invoice generator remain government-client-only; private customers are not "invoiced" in the government sense — their packing slip IS the invoice
- `MealsDB_Collection_Calculator` is the single source of truth for driver/packing slip collection amounts
- PII encryption applies the same way for private customers as for government clients
- All sync/backfill operations log to `meals_audit_log` for traceability
- `delivery_day IS NOT NULL` is the implicit "on delivery route" indicator — no new column added
- The client form already handles Private client type (Phase G work); no UI changes needed for manual private client creation
