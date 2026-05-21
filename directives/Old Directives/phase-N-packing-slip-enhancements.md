# Phase N: Packing Slip Enhancements

## Goal

Close the gaps between the current `MealsDB_Delivery_Slip_Generator` and the old `woo-order-export` packing slip system. The current plugin has basic packing/picking/delivery slips but is missing several features staff relied on.

## Current state

The plugin has three slip types working:
- **Packing slip:** One row per order, columns: Initials, Zone, Area, Items. Sorted by zone then initials.
- **Picking slip:** Product-grouped summary. Columns: Product, Type, Total Qty. Sorted by type then name.
- **Delivery slip:** Route-grouped by zone → area → stops. Columns: Initials, Address, Item Summary.

All three use `delivery_initials` (3-letter) for privacy, query by `delivery_day`, and render as HTML tables with print CSS.

## What's missing

### 1. Zone Summary Sheet

The old plugin generated a summary block per zone showing SKU/product/quantity/category totals. This is a "what goes in each delivery van" overview.

**Add to `generate_packing_slip()` return data:**

For each zone group, include a `zone_summary` array:
```php
'zone_summary' => [
    'total_orders' => 12,
    'total_mains'  => 24,
    'total_sides'  => 18,
    'side_breakdown' => [
        'Soup'    => 5,
        'Muffins' => 4,
        'Cereal'  => 3,
        'Dessert' => 6,
    ],
    'products' => [
        ['name' => 'Chicken Dinner', 'sku' => 'CD-001', 'qty' => 8, 'type' => 'meal'],
        // ...
    ],
]
```

The side breakdown uses WC product categories: Soup (43), Muffins (37), Cereal (23), Dessert (25). Map from `meals_products.product_type` where possible, with WC category fallback.

### 2. Freezer Ordering

The old plugin sorted items within each order by the `_freezer_order` product meta field. This is a numeric sort value that determines the physical stacking order in the delivery bag (frozen items on bottom, fresh on top).

**Change:** When building the items array for each packing slip entry, sort items by the WC product's `_freezer_order` post meta ASC (items without this meta go last).

**Implementation:** After collecting all unique `wc_product_id` values across orders, batch-fetch `_freezer_order` from `wp_postmeta`:

```php
$freezer_orders = [];
if (!empty($product_ids)) {
    $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta}
         WHERE meta_key = '_freezer_order' AND post_id IN ($placeholders)",
        ...$product_ids
    ), ARRAY_A);
    foreach ($rows as $r) {
        $freezer_orders[(int)$r['post_id']] = (int)$r['meta_value'];
    }
}
```

Then sort each order's items:
```php
usort($items, function ($a, $b) use ($freezer_orders) {
    $fa = $freezer_orders[$a['wc_product_id']] ?? 9999;
    $fb = $freezer_orders[$b['wc_product_id']] ?? 9999;
    return $fa - $fb;
});
```

### 3. Mains/Sides Subtotals Per Order

The old packing slip showed mains and sides counts per order entry. The current slip shows individual items but no subtotals.

**Change:** Add `mains_count` and `sides_count` to each packing slip entry. Also add category-level breakdown:

```php
$entry['mains_count'] = $meal_count;
$entry['sides_count'] = $side_count;
$entry['side_detail'] = [
    'soup'    => $soup_count,
    'muffins' => $muffin_count,
    'cereal'  => $cereal_count,
    'dessert' => $dessert_count,
];
```

### 4. "Orders with No Zone" Error Section

The old plugin collected orders where the customer had no zone assigned and listed them at the bottom as a warning section.

**Change:** In `generate_packing_slip()`, when processing orders, if a client's `delivery_area_zone` and `delivery_area_name` are both empty, add the entry to a separate `no_zone` array instead of the main entries.

Return both:
```php
return [
    'entries'  => $entries,   // normal sorted entries
    'no_zone'  => $no_zone,   // entries with no zone assignment
];
```

The UI renderer should show the no-zone orders in a separate section with a warning header.

### 5. Cover Sheet / Delivery Schedule

The old plugin had a cover sheet showing which zones are being delivered and order counts per zone.

**Add to the delivery slip return data:**

```php
'cover' => [
    ['zone' => 'Zone 1', 'area' => 'Moncton', 'order_count' => 15, 'total_items' => 42],
    ['zone' => 'Zone 2', 'area' => 'Sussex',   'order_count' => 8,  'total_items' => 22],
    // ...
],
```

The UI renders this as a simple summary table before the zone-by-zone detail.

---

## Files to modify

### `includes/services/class-delivery-slip-generator.php`

- `generate_packing_slip()` — restructure return to include `entries` + `no_zone` arrays, add zone_summary, mains/sides counts, freezer ordering
- `generate_delivery_slip()` — add cover sheet data
- Add `get_freezer_orders(array $product_ids)` private helper
- Add `categorize_items(array $items, array $product_types)` private helper for mains/sides/category breakdown

### `views/daily-slips.php`

- Update `renderPackingSlip()` JS to show mains/sides subtotals, zone summaries, and no-zone warning section
- Update `renderDeliverySlip()` JS to show cover sheet table before zone detail
- Add freezer-order sort to item display

### `includes/services/class-wc-order-query.php`

- May need a method to batch-fetch `_freezer_order` product meta, or this can be done inline with `$wpdb`

---

## Key constraints

- The `delivery_day` column must be populated for any of this to work — currently 0/558 clients have it set
- Freezer order uses WC product meta `_freezer_order` (post_meta table), not meals_products
- Side category breakdown uses the same IDs as everywhere else: Soup=43, Muffins=37, Cereal=23, Dessert=25
- The packing slip return format is changing from a flat array to a structured object — update the AJAX handler and JS renderer accordingly
- External DB via `MealsDB_DB::get_connection()`, WC meta via `$wpdb`
