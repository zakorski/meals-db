# Phase T: Per-Order PDF Slips (Packer + Driver)

## Goal

Replace the current four screen-rendered slip types (packing, picking, delivery, driver) with two PDF-output-only slip types: a **Packer Slip** and a **Driver Slip**. Both produce one slip per WC order, combined into a single downloadable PDF that page-breaks between orders.

## Why this is changing

The current slips are HTML-rendered for screen viewing and don't match how the operators actually work. Packers want one printed page per order so they can pick that order off the slip and hand it to the driver. The existing zone-grouped slips force operators to mentally split a multi-order page when packing, and the existing driver slip output mixes order info with collection info in a layout the operators don't actually use.

Going forward there are exactly two outputs: one for packers (no financial info), one for drivers (everything the packer has, plus the collection block and customer delivery info).

---

## Scope summary

**Removing:** `generate_packing_slip`, `generate_picking_slip`, `generate_delivery_slip`, `generate_driver_slips` (all four day-mode methods AND all four zone-mode methods), their AJAX endpoints, their HTML render functions in `views/daily-slips.php`, and the four corresponding "Generate X Slip" buttons.

**Adding:** Two new PDF generators that produce per-order slips, an "All Zones" quick-select button, and DomPDF as a Composer dependency.

**Keeping:** Zone vs. delivery-day mode toggle (Phase Q), zone schedule WP option, `MealsDB_Collection_Calculator` (now used by both new slip types), all the existing client-querying helpers (`get_clients_for_delivery_date`, `get_clients_for_zones`, etc.) that the new generators will reuse.

---

## Library: DomPDF

Add via Composer:

```json
{
  "require": {
    "dompdf/dompdf": "^3.0"
  }
}
```

Run `composer install` in the plugin directory; commit `vendor/` per the existing convention. The plugin's main file should already require Composer's autoloader; if not, add:

```php
$autoload = MealsDB_Plugin::path('vendor/autoload.php');
if (file_exists($autoload)) {
    require_once $autoload;
}
```

DomPDF's HTML+CSS support is sufficient for the layout described below. No JavaScript or external network calls — DomPDF runs offline. Set its `chroot` to the plugin directory and `isHtml5ParserEnabled = true`.

### Embedded fonts

DomPDF ships with the standard PDF fonts (Helvetica, Times, Courier) which are sufficient. Do not add custom font files unless the operator specifically requests a branded look — keeps the plugin slim and avoids font-licensing concerns.

---

## Architecture

### New service class: `MealsDB_Slip_PDF_Generator`

**File:** `includes/services/class-slip-pdf-generator.php`

Entry points:

```php
class MealsDB_Slip_PDF_Generator {
    
    public function __construct(
        MealsDB_Delivery_Slip_Generator $client_query,
        MealsDB_Collection_Calculator   $calculator
    );
    
    /**
     * Generate a combined packer-slip PDF for the given delivery date.
     * One slip per WC order, page break between slips.
     * Returns the PDF binary (bytes).
     */
    public function generate_packer_slips_for_date(string $delivery_date): string;
    
    /**
     * Generate packer slips by zone selection (the Phase Q dual-mode path).
     */
    public function generate_packer_slips_by_zones(
        array $zone_names, string $start_date, string $end_date
    ): string;
    
    public function generate_driver_slips_for_date(string $delivery_date): string;
    public function generate_driver_slips_by_zones(
        array $zone_names, string $start_date, string $end_date
    ): string;
}
```

All four entry points share the same internal pipeline:

1. Resolve clients (using existing `get_clients_for_delivery_date` or `get_clients_for_zones`)
2. Resolve WC orders for those clients in the date range
3. For each order, build a `$slip_data` array containing everything needed to render
4. Render an HTML template per order
5. Concatenate templates with page breaks
6. Pass to DomPDF, return binary

The render step is the only difference between packer and driver — both pipelines build the same `$slip_data` (so the data contract is shared), but the packer template ignores the driver-only fields.

### Slip data contract

Each order produces this structure, regardless of slip type:

```php
$slip_data = [
    // Header (both slips show)
    'initials'        => 'WZN',                      // 3-letter, from meals_clients.delivery_initials
    'zone'            => 'Zone 4',                   // from meals_clients.delivery_area_name
    'order_number'    => '#10542',                   // WC order display number with #
    'delivery_date'   => 'Thursday, February 20, 2025',  // long-form
    
    // Items table (both slips show)
    'items' => [
        [
            'sku'          => 'CONT' | 'FEE' | '12005',  // see SKU rules below
            'qty'          => int,
            'product_name' => 'Macaroni Meat Casserole',
            'category'     => 'Main' | 'Side' | 'Fee',
            'sort_key'     => [category_rank, freezer_order, sku],
        ],
        // ...
    ],
    
    // Totals (both slips show)
    'total_items'  => 28,    // physical items only — excludes Fee category
    'total_mains'  => 28,
    'total_sides'  => 0,
    
    // Notes (both slips show, blank if none)
    'additional_notes' => 'TAKE FROM HOLD',  // from $order->get_customer_note()
    
    // Driver-only block
    'driver' => [
        'client_name' => 'Wayne Zorn',           // decrypted first + last
        'street'      => '173 Main St',
        'city'        => 'Shediac',
        'phone'       => '506-743-2285',
        'client_type' => 'Private' | 'SDNB' | 'Veteran',
        
        'breakdown' => [
            // For Private:
            ['label' => 'Products',           'amount' => 338.03],
            ['label' => 'Taxes',              'amount' => 0.00],
            ['label' => 'Delivery Fee',       'amount' => 10.00],
            // For Government:
            // ['label' => 'Delivery Fee',        'amount' => 10.00],
            // ['label' => 'Client Contribution', 'amount' => 60.00],   (only if non-zero AND first delivery of month)
        ],
        'collect_amount' => 348.03,    // calculator output, always set (0.00 if nothing to collect)
        'collect_label'  => 'Collect: $348.03',  // pre-formatted with currency
    ],
];
```

**SKU rules** (line items get rewritten before display):

- WC product ID = `mealsdb_fee_product_ids['client_contribution']` (default 5675) → SKU = `CONT`, Category = `Fee`, sort first within Fee category
- WC product ID = `mealsdb_fee_product_ids['delivery_fee']` (default 4122) → SKU = `FEE`, Category = `Fee`, sort second within Fee category
- WC product ID matches any overage product (`mealsdb_fee_product_ids['overage_main']`, etc., defaults 5056/5059/5180) → **filter out completely.** These were a workaround that's been retired; they should never appear on a slip even if a historical order has one. Defensive filter.
- Everything else → use the WC product's actual SKU. Category determined by WC category mapping: anything in the `Mains` category (35) is `Main`; anything in `Soup` (43), `Muffins` (37), `Cereal` (23), `Dessert` (25), or `Delivery Fee` (98) is `Side` (the `Side` category covers all the side-type categories per the existing system); fee products are `Fee` (handled above).

**Item sort order** within the slip:

1. Category rank: `Main` (1) → `Side` (2) → `Fee` (3)
2. Within Main and Side: ascending freezer order (`_freezer_order` product meta, default 9999 if not set)
3. Tiebreaker: ascending SKU
4. Within Fee: CONT first, then FEE

This matches what packers walk through on the warehouse floor — mains first (largest portion of the order), then sides, then the financial line items at the bottom.

**Total Items definition:**

```
total_items = sum of qty for items where category IN ('Main', 'Side')
```

Fees are excluded. The example slip showed 30 items because it counted CONT and FEE — that was wrong per your spec. Going forward, total_items is just food.

**Additional Notes:**

```php
$additional_notes = trim($order->get_customer_note());
```

If empty, the slip omits the "Additional Notes:" label entirely (no empty heading on the page). If populated, displayed as plain text below the totals row.

---

## Layout: 2-up landscape orientation

Page size: **8.5" × 11" portrait**, with the layout split as a 2/3 + 1/3 column grid:

```
┌──────────────────────────────────────────┬─────────────────────┐
│ LEFT 2/3 — PACKER AREA                   │ RIGHT 1/3           │
│                                          │                     │
│ Name: WZN                                │  (whitespace —      │
│ Zone 4 - Order #10542                    │   reserved for      │
│ Delivery Date: Thursday, February 20...  │   packer            │
│                                          │   handwritten       │
│ ┌──────────────────────────────────┐     │   notes)            │
│ │ SKU   QTY   Product       Category│     │                     │
│ │ ...item rows...                   │     │                     │
│ └──────────────────────────────────┘     │                     │
│                                          │                     │
│ Total Items: 28 | Mains: 28 | Sides: 0   │                     │
│                                          │                     │
│ Additional Notes:                        │                     │
│ TAKE FROM HOLD                           │                     │
│                                          ├─────────────────────┤
│                                          │ DRIVER BLOCK        │
│                                          │ (driver slip only)  │
│                                          │                     │
│                                          │ Products: $338.03   │
│                                          │ Taxes:    $0.00     │
│                                          │ Delivery: $10.00    │
│                                          │ ─────────────       │
│                                          │ Collect: $348.03    │
│                                          │                     │
│                                          │ Wayne Zorn          │
│                                          │ 173 Main St         │
│                                          │ Shediac             │
│                                          │ PH: 506-743-2285    │
└──────────────────────────────────────────┴─────────────────────┘
```

For the **Packer Slip**, the driver block is omitted; the right column is entirely whitespace.

For the **Driver Slip**, the driver block sits in the bottom half of the right column, top half stays whitespace.

### CSS approach

```css
@page {
    size: letter portrait;
    margin: 0.5in;
}

.slip {
    page-break-after: always;
    width: 100%;
    height: 10in;  /* 11in - 2 × 0.5in margins */
    position: relative;
}

.slip:last-child {
    page-break-after: auto;
}

.slip-left {
    width: 65%;
    float: left;
    padding-right: 0.25in;
    box-sizing: border-box;
}

.slip-right {
    width: 35%;
    float: right;
    padding-left: 0.25in;
    box-sizing: border-box;
    border-left: 1px solid #888;
    height: 10in;
    position: relative;
}

.driver-block {
    position: absolute;
    bottom: 0;
    left: 0.25in;
    right: 0;
}
```

Use `float` rather than flex/grid because DomPDF's flex/grid support has historically been unreliable. Floats render correctly.

### Items table CSS

```css
.items-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10pt;
    margin-top: 0.15in;
}
.items-table th,
.items-table td {
    border: 1px solid #000;
    padding: 3pt 5pt;
    text-align: left;
}
.items-table th {
    background-color: #eee;
    font-weight: bold;
}
.items-table .sku-col { width: 15%; }
.items-table .qty-col { width: 10%; text-align: center; }
.items-table .name-col { width: 60%; }
.items-table .cat-col { width: 15%; }
```

### Header text styling

```css
.slip-header h1.name-line {
    font-size: 24pt;
    font-weight: bold;
    margin: 0 0 0.05in 0;
}
.slip-header .zone-order {
    font-size: 14pt;
    margin: 0 0 0.05in 0;
}
.slip-header .delivery-date {
    font-size: 11pt;
    margin: 0 0 0.15in 0;
    color: #444;
}
```

The `WZN` initials should be visually prominent — that's the primary identifier packers use to grab the right bin. 24pt bold is a deliberate choice.

### Driver block styling

```css
.driver-block {
    font-size: 10pt;
}
.driver-block .breakdown {
    margin-bottom: 0.1in;
}
.driver-block .breakdown-row {
    display: block;
    margin-bottom: 2pt;
}
.driver-block .breakdown-label {
    display: inline-block;
    width: 60%;
}
.driver-block .breakdown-amount {
    display: inline-block;
    width: 35%;
    text-align: right;
}
.driver-block .collect {
    border-top: 2px solid #000;
    padding-top: 4pt;
    font-size: 14pt;
    font-weight: bold;
    margin-bottom: 0.15in;
}
.driver-block .customer-info {
    font-size: 10pt;
    line-height: 1.4;
}
.driver-block .customer-name {
    font-weight: bold;
    font-size: 12pt;
}
```

---

## Overflow handling

If an order has so many items that the items table exceeds the available height in the left column, the table flows onto a second page. The second page renders WITHOUT the header (no Name/Zone/Order# repeated — DomPDF's `thead` repetition handles continuation), and the driver block STILL appears bottom-right on every page (so the driver doesn't have to flip pages to find delivery info).

Implementation approach: render each slip's HTML inside its own `<div class="slip">` container. Use DomPDF's natural overflow behavior — it'll automatically break tables across pages and render `<thead>` on each page. The driver block uses `position: fixed` so it renders on every page of the slip:

```css
.driver-block {
    position: fixed;
    bottom: 0.5in;
    right: 0.5in;
    width: 30%;
}
```

Actually, DomPDF treats `position: fixed` as fixed-on-every-page within a document, which would put the driver block on EVERY page including subsequent orders' pages. That's not what we want. Better approach:

Use DomPDF's `running` element pattern, OR simpler — just accept that for the rare order that overflows, the driver block appears on the LAST page only, and add a small "(continued)" indicator to subsequent pages of the same slip. Most orders won't overflow.

The directive tells the developer: try `position: fixed` scoped to the slip first, and if DomPDF treats it as document-wide, fall back to "last-page only" behavior with a `(continued from previous page)` indicator on overflow pages.

---

## SQL query: per-order data fetch

The existing `get_orders_for_date()` and `get_orders_for_range()` methods already return WC orders with line items. The new pipeline needs additional per-order data:

- Order's customer note (`wc_orders.customer_note`)
- Order line item totals: `subtotal`, `subtotal_tax`, `total_tax`, `total`
- Per-line-item: product_id, qty, subtotal — for SKU and category resolution

This is already available from the WC order objects fetched by the existing query. Just expand the array shape returned to include these fields. No new queries needed.

For the **driver block**, additional client fields are needed beyond what the packer query returns:

- `first_name`, `last_name` (encrypted, must decrypt)
- `client_phone_1`
- `delivery_street_name`, `delivery_city`
- `payment_method`
- `client_contribution`
- `client_type`

The existing `get_clients_for_driver_slips` method already fetches all of these with decryption. Reuse it for the driver-slip pipeline; the packer pipeline can use the lighter `get_clients_for_delivery_date` query to skip decryption cost.

---

## "First delivery of month" determination

For government clients, the `Client Contribution` line on the driver slip only appears if (a) it's non-zero AND (b) this is the first delivery of the calendar month for this client.

Implementation: query `meals_delivery_allocations` for this client's delivery dates in the current month. If the current `delivery_date` is the earliest one with `coverage_start <= delivery_date <= coverage_end` for this billing month, this is the first delivery.

```php
private function is_first_delivery_of_month(int $client_id, string $delivery_date): bool {
    $billing_month = substr($delivery_date, 0, 7);
    $alloc_table = MealsDB_DB::get_table_name(MealsDB_Tables::DELIVERY_ALLOCATIONS);
    
    $earliest = $this->wpdb->get_var($this->wpdb->prepare(
        "SELECT MIN(delivery_date) FROM {$alloc_table}
         WHERE client_id = %d AND billing_month = %s",
        $client_id, $billing_month
    ));
    
    return $earliest === $delivery_date;
}
```

This relies on the allocation engine having already processed the current month's deliveries. Since the engine runs on every order placement (Phase J/K) and via nightly cron, this should always be current by the time slips are generated.

For private clients, this check doesn't apply — they don't have client_contribution on the slip.

---

## All Zones quick-select

In `views/daily-slips.php`, add an "All Zones" button next to the zone multi-select:

```html
<button type="button" id="mealsdb-select-all-zones" class="button button-small">
    <?php esc_html_e('All Zones', 'meals-db'); ?>
</button>
```

JavaScript:

```javascript
$('#mealsdb-select-all-zones').on('click', function() {
    $('#mealsdb-zone-select option').prop('selected', true);
});
```

No "Clear Zones" button needed — operators can deselect with cmd/ctrl-click in the existing multi-select.

---

## UI changes to `views/daily-slips.php`

**Remove:**
- Buttons: `mealsdb-gen-packing`, `mealsdb-gen-picking`, `mealsdb-gen-delivery`, `mealsdb-gen-driver`
- Print button (`mealsdb-slip-print`)
- All HTML rendering JS (`renderPackingSlip`, `renderPickingSlip`, `renderDeliverySlip`, `renderDriverSlips`)
- The `#mealsdb-slip-output` div and its print styles

**Add:**
- Two new buttons:
  ```html
  <button type="button" class="button button-primary" id="mealsdb-gen-packer-pdf">
      <?php esc_html_e('Generate Packer Slips PDF', 'meals-db'); ?>
  </button>
  <button type="button" class="button button-primary" id="mealsdb-gen-driver-pdf">
      <?php esc_html_e('Generate Driver Slips PDF', 'meals-db'); ?>
  </button>
  ```
- "All Zones" quick-select button described above
- A status area showing "Generating..." while the PDF is being built

**JavaScript flow:**

```javascript
function generatePdf(actionName) {
    var mode = $('input[name="slip-mode"]:checked').val();
    var data = { 
        action: actionName, 
        nonce: mealsdbNonce 
    };
    
    if (mode === 'zone') {
        data.zones = $('#mealsdb-zone-select').val();
        data.start_date = $('#mealsdb-zone-start').val();
        data.end_date = $('#mealsdb-zone-end').val();
    } else {
        data.delivery_date = $('#mealsdb-slip-date').val();
    }
    
    // POST and trigger download
    var form = $('<form>', {
        method: 'POST',
        action: ajaxurl,
        target: '_blank'
    });
    $.each(data, function(k, v) {
        if (Array.isArray(v)) {
            v.forEach(function(item) {
                form.append($('<input>', {type:'hidden', name: k+'[]', value: item}));
            });
        } else {
            form.append($('<input>', {type:'hidden', name: k, value: v}));
        }
    });
    form.appendTo('body').submit().remove();
}

$('#mealsdb-gen-packer-pdf').on('click', function() {
    generatePdf(mode === 'zone' ? 'mealsdb_zone_packer_pdf' : 'mealsdb_packer_pdf');
});
$('#mealsdb-gen-driver-pdf').on('click', function() {
    generatePdf(mode === 'zone' ? 'mealsdb_zone_driver_pdf' : 'mealsdb_driver_pdf');
});
```

The PDF download uses a form-POST-to-iframe approach so the browser triggers a file download rather than opening the binary in-tab.

---

## AJAX endpoints

**File:** `includes/ajax/class-ajax-delivery-slips.php`

**Remove:**
- `mealsdb_generate_packing_slip`
- `mealsdb_generate_picking_slip`
- `mealsdb_generate_delivery_slip`
- `mealsdb_generate_driver_slips`
- `mealsdb_zone_packing_slip`
- `mealsdb_zone_picking_slip`
- `mealsdb_zone_delivery_slip`
- `mealsdb_zone_driver_slips`

**Keep:**
- `mealsdb_backfill_delivery_day` (still useful for ops)

**Add:**
```php
add_action('wp_ajax_mealsdb_packer_pdf',      [self::class, 'packer_pdf']);
add_action('wp_ajax_mealsdb_driver_pdf',      [self::class, 'driver_pdf']);
add_action('wp_ajax_mealsdb_zone_packer_pdf', [self::class, 'zone_packer_pdf']);
add_action('wp_ajax_mealsdb_zone_driver_pdf', [self::class, 'zone_driver_pdf']);
```

Each handler:
1. Verifies nonce and capability
2. Reads input (delivery_date OR zone+date range)
3. Calls the corresponding `MealsDB_Slip_PDF_Generator` method
4. Sends headers and binary:
   ```php
   header('Content-Type: application/pdf');
   header('Content-Disposition: attachment; filename="packer-slips-' . $delivery_date . '.pdf"');
   header('Content-Length: ' . strlen($pdf));
   echo $pdf;
   exit;
   ```

Filename pattern:
- `packer-slips-2025-02-20.pdf` for date mode
- `packer-slips-2025-02-20-to-2025-02-20-zones-1-3.pdf` for zone mode (joined by `-`)
- Driver versions: `driver-slips-…`

---

## Removing the deprecated slip code

After the new generators ship and operators confirm they work end-to-end:

**File deletions:**
- The four `generate_*` methods AND their `_by_zones` counterparts in `class-delivery-slip-generator.php` (or replace the file with a slimmer version that only has the helper methods retained for the new pipeline)
- AJAX handler methods for the eight removed endpoints

**Method retention:** The class still owns `get_clients_for_delivery_date`, `get_clients_for_zones`, `get_clients_for_driver_slips`, `get_clients_for_zones_driver`, `get_orders_for_date`, `get_orders_for_range`, `get_freezer_orders`, `categorize_items`, `resolve_product_types` — these are the helper queries that the new pipeline calls.

Consider renaming `MealsDB_Delivery_Slip_Generator` to `MealsDB_Slip_Data_Provider` since it's no longer generating slips, just providing client and order data. Optional cleanup; skip if it adds friction.

---

## Tests

- `test-pdf-slip-data-contract.php` — `$slip_data` shape is correct for various order configurations (private cash, private prepaid, govt, govt with first-of-month contribution, mixed line items)
- `test-pdf-slip-overage-products-filtered.php` — overage products in line items are excluded
- `test-pdf-slip-fee-skus-rewritten.php` — product 5675 → CONT, 4122 → FEE
- `test-pdf-slip-item-sort-order.php` — Mains→Sides→Fees, freezer order within categories
- `test-pdf-slip-total-items-excludes-fees.php` — total_items count
- `test-pdf-slip-collection-private-cash.php` — Products + Taxes + Delivery = Collect
- `test-pdf-slip-collection-private-prepaid.php` — Collect = $0.00 if prepaid
- `test-pdf-slip-collection-govt-no-contribution.php` — Just Delivery Fee in breakdown
- `test-pdf-slip-collection-govt-first-of-month.php` — Contribution included if first delivery
- `test-pdf-slip-collection-govt-not-first-of-month.php` — Contribution NOT included
- `test-pdf-slip-additional-notes.php` — empty note → label hidden; populated → label shown
- `test-pdf-slip-binary-output.php` — PDF binary starts with `%PDF-` magic bytes (smoke test that DomPDF actually rendered)

The PDF binary output test doesn't validate visual layout — it just confirms the pipeline produced a syntactically valid PDF. Visual fidelity is verified manually with example outputs.

---

## Manual verification checklist

After implementation, generate slips for a known historical day (e.g., Feb 20 2025 from the example) and verify:

- Each WC order produces exactly one slip (or more pages only if overflow occurs)
- Page break between orders works (no two orders on one page)
- Initials show at 24pt bold, prominent
- Items table sorted: Mains by freezer order → Sides by freezer order → CONT → FEE
- Total Items count matches sum of Main+Side qtys (NOT including fees)
- Additional Notes shows the WC customer note when present, hidden when blank
- Packer slip has empty right column
- Driver slip has driver block in bottom-right of right column, top of right column empty
- Driver block shows correct breakdown for client type (private vs. govt)
- Collect amount matches `MealsDB_Collection_Calculator` output exactly
- Government clients on first delivery of month show Contribution line; later deliveries don't
- Customer name decrypted correctly
- Address shows street + city only (no postal code, no province)
- Phone formatted as `PH: <phone>`
- Multiple pages when items exceed left column — driver block on last page (acceptable) or every page (preferred)

---

## Key constraints

- DomPDF, not TCPDF or mPDF
- One PDF per generation request, not one per order; orders combined with page breaks
- Existing slip generators removed entirely after new ones are verified
- Fee products use special SKU labels (CONT, FEE); overage products filtered out
- Total Items excludes fees; Total Mains and Total Sides count only their respective categories
- 2/3 + 1/3 column layout, NOT 50/50; right column reserved for packer notes (top) and driver info (bottom, driver slip only)
- Customer name decrypted from `meals_clients.first_name`/`last_name` via `MealsDB_Encryption::decrypt()`
- Collection logic delegated to `MealsDB_Collection_Calculator` — never duplicate it inline
- "First delivery of month" determined by querying `meals_delivery_allocations`, not by ad-hoc date math
- All AJAX handlers verify nonce + capability before generating PDFs
- Generated filenames are deterministic and human-readable for operator filing
