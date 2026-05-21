# Phase Q: Dual-Mode Slip Generation — Zone Selection + Delivery Day

## Goal

Add zone-based order selection alongside the existing delivery_day approach for all slip types (packing, picking, delivery, driver). The old system never had a per-client delivery day — staff selected zones and a date range, and orders were grouped by zone. The new system should support both modes so staff can use the familiar zone workflow immediately while the delivery_day path remains available.

## How the old system worked

The old `woo-order-export` plugin had this workflow:

1. Staff picked a **start date**, **end date**, and **one or more zones** from a multi-select (Zone 0 through Zone 6)
2. The plugin fetched ALL WC orders in that date range
3. Orders were grouped by `shipping_address_2` (which contained "Zone 1", "Zone 3", etc.)
4. The delivery schedule was a hardcoded mapping:

```php
$zone_delivery_schedule = [
    'Zone 1' => 'Wednesday morning - Moncton Downtown',
    'Zone 2' => 'Wednesday afternoon - Sackville / Amherst',
    'Zone 3' => 'Thursday morning - Moncton Other / Sussex',
    'Zone 4' => 'Thursday afternoon - Shediac',
    'Zone 5' => 'Friday - Dieppe / Riverview',
    'Zone 6' => 'Thursday morning - Sussex (combined with Zone 3)',
];
```

There was no `delivery_day` field on the client record. The day was implicit from the zone.

## How the new system currently works

The new plugin queries `meals_clients WHERE LOWER(delivery_day) = ?` using the day-of-week derived from the selected date. Since 0/558 clients have `delivery_day` populated, all slip generation currently returns empty results.

## Design: Support both modes

### Mode A: Zone + Date Range (replicates old workflow)

Staff selects:
- Start date
- End date
- One or more zones (multi-select)

The system fetches WC orders in the date range, joins to meals_clients by wp_user_id, filters by selected zones (`delivery_area_name`). This is how staff have always worked and will produce results immediately with existing data.

### Mode B: Delivery Day (current approach)

Staff selects a single delivery date. The system derives the day-of-week and queries clients where `delivery_day` matches. This requires `delivery_day` to be populated.

### Auto-populate delivery_day from zone mapping

Store the zone-to-day mapping as a WP option:

```php
add_option('mealsdb_zone_delivery_schedule', [
    'Zone 1' => ['day' => 'Wednesday', 'label' => 'Wednesday morning - Moncton Downtown'],
    'Zone 2' => ['day' => 'Wednesday', 'label' => 'Wednesday afternoon - Sackville / Amherst'],
    'Zone 3' => ['day' => 'Thursday',  'label' => 'Thursday morning - Moncton Other / Sussex'],
    'Zone 4' => ['day' => 'Thursday',  'label' => 'Thursday afternoon - Shediac'],
    'Zone 5' => ['day' => 'Friday',    'label' => 'Friday - Dieppe / Riverview'],
    'Zone 6' => ['day' => 'Thursday',  'label' => 'Thursday morning - Sussex (combined with Zone 3)'],
]);
```

Add a backfill button on the Settings tab: "Populate delivery_day from zone schedule." This sets `delivery_day` for each client based on their `delivery_area_name` and the mapping above. After running this, Mode B starts working too.

---

## Implementation

### 1. New methods on `MealsDB_Delivery_Slip_Generator`

#### `get_clients_for_zones(array $zone_names): array`

```php
/**
 * Get clients in the specified zones (for zone-based mode).
 *
 * @param array $zone_names Zone names to include (e.g. ['Zone 1', 'Zone 3'])
 * @return array<int, array<string, mixed>> Keyed by wp_user_id.
 */
public function get_clients_for_zones(array $zone_names): array
```

Queries `meals_clients WHERE active = 1 AND wp_user_id > 0 AND delivery_area_name IN (...)`. Returns the same format as `get_clients_for_delivery_date()`.

#### `get_clients_for_zones_driver(array $zone_names): array`

Same as above but includes the full PII fields (first_name, last_name, phone, delivery_fee, payment_method, client_type) needed for driver slips, with decryption. Returns the same format as `get_clients_for_driver_slips()`.

#### `get_orders_for_range(array $wp_user_ids, string $start_date, string $end_date): array`

Like `get_orders_for_date()` but accepts a date range instead of a single date. Delegates to `MealsDB_WC_Order_Query::get_orders_with_items_for_users()` with the start/end range.

#### Update all four generate methods

Each generate method (`generate_packing_slip`, `generate_picking_slip`, `generate_delivery_slip`, `generate_driver_slips`) should accept an optional `$mode_params` array:

```php
/**
 * @param string|null $delivery_date  Single date for day-based mode (existing)
 * @param array|null  $zone_params    ['zones' => [...], 'start_date' => '...', 'end_date' => '...']
 */
```

**Recommended approach:** Add a second entry point for each slip type rather than overloading the existing methods. For example:

```php
public function generate_packing_slip_by_zones(
    array $zone_names,
    string $start_date,
    string $end_date
): array
```

This keeps the existing delivery_day methods unchanged and adds parallel zone-based methods. The internal logic (freezer ordering, zone summaries, categorization, etc.) is shared — only the client/order fetching differs.

**Alternatively**, refactor the shared logic into a private method that both paths call:

```php
private function build_packing_slip(array $clients, array $orders): array
// Both generate_packing_slip() and generate_packing_slip_by_zones() call this
```

This is cleaner but requires more refactoring.

### 2. New AJAX endpoints

**File:** `includes/ajax/class-ajax-delivery-slips.php`

Add four new actions for zone-based mode:

```php
add_action('wp_ajax_mealsdb_zone_packing_slip',  [self::class, 'zone_packing_slip']);
add_action('wp_ajax_mealsdb_zone_picking_slip',  [self::class, 'zone_picking_slip']);
add_action('wp_ajax_mealsdb_zone_delivery_slip', [self::class, 'zone_delivery_slip']);
add_action('wp_ajax_mealsdb_zone_driver_slips',  [self::class, 'zone_driver_slips']);
```

Each handler:
- Reads `$_POST['zones']` (array of zone names)
- Reads `$_POST['start_date']` and `$_POST['end_date']`
- Calls the zone-based generator method
- Returns via `wp_send_json()`

Add a helper to parse zone params:

```php
private static function get_zone_params(): array {
    $zones = isset($_REQUEST['zones']) ? array_map('sanitize_text_field', (array) $_REQUEST['zones']) : [];
    $start = isset($_REQUEST['start_date']) ? sanitize_text_field($_REQUEST['start_date']) : '';
    $end   = isset($_REQUEST['end_date']) ? sanitize_text_field($_REQUEST['end_date']) : '';
    
    if (empty($zones) || empty($start) || empty($end)) {
        wp_send_json(['success' => false, 'message' => 'Zones and date range are required.']);
    }
    
    return ['zones' => $zones, 'start_date' => $start, 'end_date' => $end];
}
```

### 3. UI changes

**File:** `views/daily-slips.php`

Add a mode toggle and zone selection controls:

```html
<!-- Mode toggle -->
<div style="margin-bottom:12px;">
    <label>
        <input type="radio" name="slip-mode" value="zone" checked /> 
        By Zone + Date Range
    </label>
    <label style="margin-left:16px;">
        <input type="radio" name="slip-mode" value="day" /> 
        By Delivery Day
    </label>
</div>

<!-- Zone mode controls (shown by default) -->
<div id="mealsdb-zone-controls">
    <label>Start Date:</label>
    <input type="date" id="mealsdb-zone-start" value="<?php echo date('Y-m-d'); ?>" />
    
    <label>End Date:</label>
    <input type="date" id="mealsdb-zone-end" value="<?php echo date('Y-m-d'); ?>" />
    
    <label>Zones:</label>
    <select id="mealsdb-zone-select" multiple style="min-width:200px; height:auto;">
        <option value="Zone 1">Zone 1 - Moncton Downtown</option>
        <option value="Zone 2">Zone 2 - Sackville / Amherst</option>
        <option value="Zone 3">Zone 3 - Moncton Other / Sussex</option>
        <option value="Zone 4">Zone 4 - Shediac</option>
        <option value="Zone 5">Zone 5 - Dieppe / Riverview</option>
        <option value="Zone 6">Zone 6 - Sussex</option>
    </select>
</div>

<!-- Day mode controls (hidden by default) -->
<div id="mealsdb-day-controls" style="display:none;">
    <label>Delivery Date:</label>
    <input type="date" id="mealsdb-slip-date" value="<?php echo date('Y-m-d'); ?>" />
</div>
```

The zone list should be populated from the WP option `mealsdb_zone_delivery_schedule` via PHP rather than hardcoded.

JavaScript: Toggle visibility on radio change. When generating, check the mode and call the appropriate AJAX action (zone-based or day-based).

```javascript
$('input[name="slip-mode"]').on('change', function() {
    if ($(this).val() === 'zone') {
        $('#mealsdb-zone-controls').show();
        $('#mealsdb-day-controls').hide();
    } else {
        $('#mealsdb-zone-controls').hide();
        $('#mealsdb-day-controls').show();
    }
});

function generate(slipType, renderer) {
    var mode = $('input[name="slip-mode"]:checked').val();
    var data = { nonce: nonce };
    
    if (mode === 'zone') {
        data.action = 'mealsdb_zone_' + slipType;
        data.zones = $('#mealsdb-zone-select').val();
        data.start_date = $('#mealsdb-zone-start').val();
        data.end_date = $('#mealsdb-zone-end').val();
    } else {
        data.action = 'mealsdb_generate_' + slipType;
        data.delivery_date = $('#mealsdb-slip-date').val();
    }
    
    // ... rest of AJAX call
}
```

### 4. Backfill delivery_day from zone schedule

**File:** `includes/services/class-backfill-addresses.php` (or a new backfill class)

Add a method or button handler:

```php
public static function backfill_delivery_day(): array {
    $schedule = get_option('mealsdb_zone_delivery_schedule', []);
    $conn = MealsDB_DB::get_connection();
    $clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
    
    $stats = ['updated' => 0, 'skipped' => 0, 'no_zone' => 0];
    
    // For each zone in the schedule, update all clients in that zone
    foreach ($schedule as $zone_name => $config) {
        $day = strtolower($config['day']); // 'wednesday', 'thursday', 'friday'
        
        $sql = "UPDATE `{$clients_table}` 
                SET delivery_day = ? 
                WHERE delivery_area_name = ? 
                  AND (delivery_day IS NULL OR delivery_day = '')
                  AND active = 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $day, $zone_name);
        $stmt->execute();
        $stats['updated'] += $stmt->affected_rows;
        $stmt->close();
    }
    
    return $stats;
}
```

Wire to an AJAX endpoint and add a button on the Settings tab.

### 5. Zone schedule on Settings tab

Add a section to `views/settings.php` showing the zone-to-day mapping, editable. This lets staff adjust the schedule if zones change days.

---

## Key constraints

- Zone names in meals_clients.delivery_area_name (e.g. "Zone 1", "Zone 3")
- Zone codes in meals_clients.delivery_area_zone (e.g. "M", "S")
- The old system used `shipping_address_2` — the new system uses `delivery_area_name` (backfilled from `billing_address_2` in Phase H)
- The zone-based mode queries orders by date range + client zone. The delivery_day mode queries clients by day-of-week + orders for that date.
- Zone list should come from WP option, not hardcoded
- The four generate methods' return formats should be identical regardless of which mode was used — the renderers don't need to change
- External DB via `MealsDB_DB::get_connection()`, WC orders via `$wpdb` HPOS tables
