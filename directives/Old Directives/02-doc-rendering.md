# 02 — Document rendering: doc 1, doc 2 (with divider), doc 4 (driver-block-only)

> **✅ COMPLETE (2026-06-26, PR #438, commit aad69c8).** Renderers added to
> `includes/services/class-slip-pdf-generator.php`, **additive** (the live daily-slip path is left
> byte-identical) and reusing its data pipeline: `generate_doc1_cover_sheet` (legend from
> `mealsdb_zone_delivery_schedule`, `NONE` initials), `generate_doc2_packer_by_zones` (divider, Order N of M,
> Page X of Y, white header, Total Mains/Total Sides), `generate_doc4_driver_blocks` + static
> `driver_block_inner_html` (shared with unit 03; skips empty fields), `build_batch_data`. `build_driver_block`
> extended with postal / secondary phone / alternate contact. `DOC2_DIVIDER_*` / `DOC4_BLOCK_*` constants =
> one geometry source of truth. **Visual calibration is live-only** (no mbstring/dompdf in CLI). Tests:
> `tests/test-slip-midland-render.php` (25 checks — fragment/legend/zone-number logic).

## Goal
Produce three renderers, all Letter landscape via dompdf, faithful to the reference scans, populated
from current plugin fields. Calibrated coordinates are GIVEN below (measured from real references).

## Reference (study before editing)
- Existing slip generator: `includes/services/class-slip-pdf-generator.php`. Key methods:
  - `generate_packer_slips_by_zones()` (line 63) — produces doc 2 today.
  - `generate_driver_slips_by_zones()` (line 81) — produces doc 4 today (as full slip).
  - `build_single_slip()` (164), `build_items()` (208), `compute_totals()` (295),
    `build_driver_block()` (316), `fetch_customer_note()` (549, returns `get_customer_note()`),
    `resolve_order_display_number()` (566), `format_long_date()` (620),
    `render_slip_html()` (660), `render_driver_block_html()` (743), `slip_css()` (775),
    `render_with_dompdf()` (824).
- Per-order data already available in the slip array (see build_single_slip ~line 181): `initials`
  (= client `delivery_initials`), `zone` (= `delivery_area_name`), `order_number`, `delivery_date`,
  `items`, `total_items/mains/sides`, `additional_notes` (= customer note).
- VAC overlay precedent (background-image + absolute fields in dompdf):
  `includes/services/class-invoice-generator.php` serialize_vac_pdf_from_csv (~line 1414) and
  `assets/images/vac-blue-cross-form.jpg`.

## ====== DOC 2 — packer slip (REFINE) ======
Refine the packer-slip HTML/CSS so it matches the reference AND draws the right-side divider.
Doc 2 = doc 3 minus handwriting, so doc 2 MUST render the divider line that doc 4 later anchors to.

LEFT region only; RIGHT region blank EXCEPT the divider. Measured layout (Letter landscape, in inches
from page top-left; page = 11 x 8.5in):
- "Name: <initials>"            left 0.31, top 0.38, 15pt bold
- "Zone N - Order #NNNNN"       left ~4.6, top 0.36, 16pt bold
- "Delivery Date: <long date>"  left 0.31, top 0.76, 10pt bold
- "Order N of M"                left 0.31, top 1.08, 10pt bold  [N = position in zone batch; M = zone order count]
- Items table                  left 0.24, top 1.26, width 6.9in; white header (NO grey), bold SKU,
                               tight rows (1pt 5pt padding); columns SKU|Qty|Product|Category
- Totals line                  left 0.24, top 4.18:  "Total Items: I | Total Mains: M | Total Sides: S"
                               then "Page X of Y" (~1.0in further right). [X = global page across the
                               whole zone export = order's sequence + 1 (cover is page 1); Y = 1 + order_count]
- "Additional Notes:" + note   left 0.24, top 4.46, 10pt (only if customer note present)
- **DIVIDER LINE**             left 7.59, top 4.50, width 3.25in, height 2px, solid black.
                               Render as a `background-color:#000` div with explicit width+height —
                               NOT `border-top` (border-top bleeds full-width in dompdf).

"Order N of M": current generator does NOT track per-zone order position. Compute it: when building a
zone's slips, the orders are already iterated in sequence — N is the 1-based index, M is the count.
Thread these into the slip array and the template.

## ====== DOC 1 — cover sheet (NEW) ======
Add a method e.g. `generate_cover_sheet(string $zone_name, string $delivery_date, array $batch_meta): string`
returning PDF bytes. Centered vertical stack (Letter landscape, centered text):
1. "Zone N"  ~48pt
2. "Delivery Date: <Weekday, Month D, YYYY>"  ~22pt
3. (gap)
4. "ORDERS - TAKE FROM HOLD"  ~20pt bold   [CONSTANT string]
5. initials line  ~20pt bold   [SEE DATA SOURCE below]
6. (gap)
7. legend table (~6in wide, centered): title row "LEGEND: DELIVERY SCHEDULE FOR PACKING" spanning;
   then ZONE # | WEEKDAY | AREA. **BUILD THE ROWS DYNAMICALLY from the existing
   `mealsdb_zone_delivery_schedule` option — do NOT hardcode them** (operator confirmed the zone data is
   configured in the plugin). See "Doc 1 legend — DATA SOURCE" below for the exact mapping. The 6 rows
   below are the EXPECTED current values (use them only to sanity-check the option, not as literals):
   1 / Wednesday morning / Moncton Downtown
   2 / Wednesday afternoon / Sackville / Amherst
   3 / Thursday morning / Moncton Other
   4 / Thursday morning / Sussex
   5 / Friday morning / Dieppe / Riverview
   6 / Friday afternoon / Shediac
8. "Orders Exported <Month D, YYYY @ h:mm am/pm>"  ~20pt bold   [generation timestamp = now()]
9. (gap)
10. "<N> Orders"  ~26pt bold   [N = order_count for this zone]
11. "Page 1 of Y"  ~9pt footer  [Y = 1 + order_count]

### Doc 1 initials line — DATA SOURCE (critical)
The initials line lists the initials of orders in this zone whose WooCommerce customer note matches
"take from hold". Logic:
```
for each order in this zone's batch:
    note = wc_order->get_customer_note()   // already fetched via fetch_customer_note()
    if stripos(note, 'take from hold') !== false:   // CASE-INSENSITIVE CONTAINS
        add client delivery_initials to the list
join the qualifying initials with ' | '
```
Use case-insensitive CONTAINS (not exact match) so "TAKE FROM HOLD", "take from hold - back door", etc.
all qualify. **If NO order in the zone qualifies, render the literal string `NONE` on the initials line**
(operator decision — not an empty line), so the packer sees an explicit "none" rather than a blank.

### Doc 1 legend — DATA SOURCE (resolved)
The legend rows are NOT hardcoded — they come from the `mealsdb_zone_delivery_schedule` option (the same
option `views/settings.php` edits and `class-ajax-delivery-slips.php` reads). Shape:
```php
get_option('mealsdb_zone_delivery_schedule', []) =>
    [ '<zone_name>' => ['day' => 'Wednesday', 'label' => 'Wednesday morning'], ... ]
```
Mapping to the legend's ZONE # | WEEKDAY | AREA columns:
- **AREA**    = the array KEY (`$zone_name`, e.g. "Moncton Downtown", "Sackville / Amherst").
- **WEEKDAY** = `$config['label']` if non-empty (it carries the "morning/afternoon" granularity the legend
                shows), else fall back to `$config['day']`.
- **ZONE #**  = the zone's ordinal. The option is keyed by NAME, not number — derive the number from the
                client zone code (`delivery_area_zone` / `service_name_zone`) for that zone, or from the
                option's insertion order if no numeric code is configured. Match the existing zone-numbering
                the slip generator already uses; do not invent a new numbering.
Iterate the option to emit one row per configured zone (don't assume exactly 6). If the option is empty,
log a degraded event and render an empty legend body rather than fabricating rows.

## ====== DOC 4 — driver block ONLY (RESHAPE) ======
Today `generate_driver_slips_by_zones` renders a FULL slip (item table + driver). For the merge, doc 4
must be the DRIVER BLOCK ALONE on a blank Letter-landscape page (no item table, transparent elsewhere),
positioned to overlay onto doc 3's right region. Add a method that renders ONE order's driver block to
a single-page PDF (or render the block to HTML for compositing — see unit 03 for how it's composited).

Content (top-to-bottom), NO divider drawn (doc 3 background already has it):
- "Collect: $X.XX"  16pt bold   [RESOLVED — already correct in the codebase. `build_driver_block()`
                                 (line 316) computes `collect_amount` via `MealsDB_Collection_Calculator`
                                 (::for_government / ::for_private), which already yields the real amount
                                 DUE (e.g. $0.00 if prepaid), NOT get_total(). REUSE that value verbatim;
                                 do not recompute. Doc 4 keeps ONLY this price (drop the breakdown).]
- client name       16pt bold   [`first_name` + `last_name`, already assembled in build_driver_block]
- address           12pt (street; city + postal), line-height 1.5
                                 [street = `delivery_street_name`; city = `delivery_city`;
                                  postal = `delivery_postal_code`. NOTE: build_driver_block currently
                                  returns city but NOT postal — ADD `delivery_postal_code` to its return
                                  array and to the doc4 payload.]
- phone(s)          incl. secondary number + contact name where present
                                 [RESOLVED — fields exist; INCLUDE ONLY WHEN NON-EMPTY (operator: "only if
                                  they're there"). Primary = `client_phone_1` (already returned). Secondary
                                  phone = `client_phone_2`. Secondary contact = `alternate_contact_name`
                                  with `alternate_contact_phone_1` / `alternate_contact_phone_2`. Render a
                                  contact like "(Gail) 536-1126" ONLY if the name/number is present; emit
                                  nothing for blank fields — never a stray "()" or dangling label. Extend
                                  build_driver_block's return array + the doc4 payload to carry these.]
   (REMOVED: the old inline Subtl/Tax/Total breakdown.)

Calibrated placement (Letter landscape; doc 3 background divider is at y=4.50in):
- block left 7.4in, width 3.2in
- Collect line top 4.62in  (= divider 4.50in + designed spacing; text sits just below the divider)
This lands in the clear band below the handwriting (handwriting occupies ~1.78–4.52in; clear below 4.52in)
and clears the item table (table right edge ~7.14in) with margin.

### Secondary phone + contact name — RESOLVED
The `meals_clients` schema carries all of it: `client_phone_2`, `alternate_contact_name`,
`alternate_contact_phone_1`, `alternate_contact_phone_2` (and `alternate_contact_email`, unused here).
Per the operator: some clients have these, many don't — **include each ONLY when non-empty**, emit nothing
otherwise. No fabrication, no degradation note needed; the fields are real.

## Verify
```
php -l includes/services/class-slip-pdf-generator.php
php tests/test-*.php
# Visual: render each doc to PDF on a test site and eyeball vs the reference scans.
```
See SPEC-doc4-driver-block.md and SPEC-midland-packing-documents-COMBINED.md for the measured rationale.
