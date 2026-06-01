# Directive GUI-SLIP-LANDSCAPE (SURGICAL, v438) — Slips portrait → landscape

## HOW TO EXECUTE — READ FIRST
- This is **2 required edits + optional layout tuning**, all in
  `includes/services/class-slip-pdf-generator.php`. Do the 2 required edits exactly; the tuning is
  judgment, described separately.
- For each required edit: `read` the file, find the EXACT verbatim FIND, apply. Do NOT regenerate the
  CSS block or any method. If a FIND doesn't match verbatim, STOP and report.
- Orientation is set in TWO places that MUST agree — a CSS `@page` rule AND a PHP `setPaper` call. If
  only one is changed they conflict and the slip may stay portrait. BOTH required edits are mandatory.

**Why:** packing/delivery slips render PORTRAIT but the established format (the old-system slips
drivers/packers use) is LANDSCAPE — confirmed by the operator and a reference slip. All slip
*content* is already correct (verified in prior testing); only orientation + layout proportions are
wrong. This is presentation only — no data, no query, no logic changes.

---

## EDIT 1 (REQUIRED) — flip the CSS @page to landscape
**File:** `includes/services/class-slip-pdf-generator.php`
**FIND (verbatim, 1 line):**
```
@page { size: letter portrait; margin: 0.5in; }
```
**REPLACE WITH:**
```
@page { size: letter landscape; margin: 0.5in; }
```

---

## EDIT 2 (REQUIRED) — flip the PHP setPaper to landscape (must match EDIT 1)
**File:** `includes/services/class-slip-pdf-generator.php`
**FIND (verbatim, 1 line):**
```
        $dompdf->setPaper('letter', 'portrait');
```
**REPLACE WITH:**
```
        $dompdf->setPaper('letter', 'landscape');
```

**After EDITS 1+2 the slip renders landscape.** That alone satisfies "make it landscape." EDIT 3 is
layout polish so it reads like the reference rather than a portrait layout stretched onto a wide page.

---

## EDIT 3 (LAYOUT TUNING — judgment, match the reference)
A landscape letter page is ~10in usable wide (vs ~7.5in portrait). The existing CSS was sized for the
narrow page, so on landscape the items table stretches thin and the driver block (absolute-positioned)
may sit oddly. Adjust to mirror the reference layout (items table on the LEFT occupying ~60–65% width;
driver/collection block grouped to the right/lower area). These are the CSS rules to consider (all in
the same `<<<CSS` block as EDIT 1). Tune values to match the reference; exact numbers are guidance, not
mandatory:

- `.items-table { width: 100%; ... }` — change `width: 100%` to a fixed table-group width (e.g.
  `width: 6.5in;`) so the table doesn't span the full 10in. Optionally set column widths via
  `<col>`/`th` so Product is the widest column (SKU ~1.2in, Qty ~0.6in, Product ~4in, Category ~1in).
- `.driver-block { position: absolute; bottom: 0; left: 0.25in; right: 0; ... }` — on a wide page,
  verify it lands where the reference puts the Collect/Delivery-Fee/name/address/phone group. If it
  looks stranded, position it to the lower-right (e.g. `left: auto; right: 0.25in; width: 3.5in;`) so
  it sits beside/under the table like the reference.
- Fonts (`.name-line` 24pt, `.zone-order` 14pt, `.items-table` 10pt, table cells 8pt): these were
  tuned for portrait width and may read small/sparse on landscape. Bump the table/body up a point or
  two if needed for readability. Keep `.name-line` (the big initials) prominent — it's the
  at-a-glance picker cue.

**Tuning is iterative and visual** — make a reasonable first pass; final fit is verified by generating
a slip and comparing to the reference (see verification). Do NOT change WHAT renders (no fields added
or removed), only sizing/positioning.

---

## SCOPE NOTES
- This applies to BOTH slip types: packer slips (`render_pdf(..., false)`) and driver slips
  (`render_pdf(..., true)`) share `render_with_dompdf`, so the orientation change hits both. Confirm
  both render landscape.
- The multi-page continuation behavior (repeated table header, "continued" cue on long orders) must
  keep working in landscape — don't break it while tuning the table.

---

## VERIFICATION
```bash
cd <plugin-root>
grep -n "@page" includes/services/class-slip-pdf-generator.php        # expect: landscape
grep -n "setPaper" includes/services/class-slip-pdf-generator.php     # expect: 'landscape'
grep -c "portrait" includes/services/class-slip-pdf-generator.php     # expect: 0
php tests/test-*.php   # expect green (no logic touched)
```
**Manual (the real check — PDF layout isn't unit-testable):**
- Generate a PACKER slip and a DRIVER slip for a December-2025 date with real orders.
- Confirm orientation is LANDSCAPE (page wider than tall).
- Confirm all elements still present + readable: initials, zone/order, delivery date,
  SKU/Qty/Product/Category table (incl. CONT and FEE rows), Total Items|Mains|Sides, Additional Notes,
  and (driver slip) the Collect/Delivery-Fee/name/address/phone block.
- Confirm a MULTI-page order still shows the continuation header/cue.
- Compare side-by-side to the reference slip — grouping should read like the reference, not a
  stretched portrait.

## DO NOT
- Do not change what data appears on slips (quantities, totals, occurrence/zone filtering all stay).
- Do not add or remove fields/columns from the slip.
- Do not regenerate the CSS block or any method — edit only the named lines (EDITS 1-2) and tune only
  the named CSS rules (EDIT 3).
- Leave EDITS 1 and 2 BOTH applied — one without the other can revert to portrait.
