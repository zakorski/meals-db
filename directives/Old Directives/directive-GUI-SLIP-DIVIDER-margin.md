# Directive GUI-SLIP-DIVIDER (SURGICAL, v446) — Stop the items table overlapping the divider

## HOW TO EXECUTE — READ FIRST
- ONE CSS edit in `includes/services/class-slip-pdf-generator.php`. `read` the file, find the EXACT
  verbatim FIND, apply. Do NOT regenerate the CSS block. If FIND doesn't match, STOP and report.
- **This fix was rendered and visually verified** (dompdf, the real slip CSS + reference data): the
  divider no longer clips the Category column; the table ends with a clean gap before the divider.

**Problem:** on the landscape slip the vertical column divider (`.slip-right` left border) overlapped
the right edge of the items table — clipping the "y" in "Category" and running down through the
Category cells.

**Root cause:** `.items-table { width: 100% }` is 100% of a FLOATED `.slip-left` column, and dompdf
renders the floated table slightly wider than its padded content box, so its right edge reached the
divider. (Tried `table-layout: fixed` on `width:100%` — did NOT fix it; the float width itself was
the problem.) Fix: give the table an EXPLICIT fixed width that fits inside the left column with
clearance to the divider. Verified: 6.0in table fits cleanly (divider sits at the ~6.5in mark, so
6.0in leaves a clean gap before it, and the gap visually matches the divider→delivery-info gap on
the other side).

---

## EDIT — set an explicit fixed table width
**File:** `includes/services/class-slip-pdf-generator.php`
**FIND (verbatim, 1 line):**
```
.items-table { width: 100%; border-collapse: collapse; font-size: 11pt; margin-top: 0.15in; }
```
**REPLACE WITH:**
```
.items-table { width: 6.0in; table-layout: fixed; border-collapse: collapse; font-size: 11pt; margin-top: 0.15in; }
```
**Why this works:** a fixed 6.0in width removes dompdf's float-width guesswork. The left column's
usable area is ~6.25in (65% of 10in minus 0.25in padding) and the divider is at ~6.5in, so a 6.0in
table ends with a clean ~0.5in clearance before the divider — the Category column closes properly and
the divider becomes a single clean line with symmetric space on both sides. `table-layout: fixed`
keeps the column-width percentages (sku/qty/name/cat) honored within that 6.0in.

---

## VERIFICATION
```bash
cd <plugin-root>
grep -n "width: 6.0in; table-layout: fixed" includes/services/class-slip-pdf-generator.php   # the edit
php tests/test-*.php   # green (no logic touched)
```
**Manual (visual — required):**
- Regenerate a packer AND a driver slip for an order with a full item list.
- Confirm the divider does NOT touch/clip the table; the Category column is fully visible with a clean
  right border.
- Confirm the gap between the table's right edge and the divider visually matches the gap between the
  divider and the delivery info (symmetric gutter).
- Confirm the table columns still line up and no text is clipped.
- (Optional) If on the real data the Product column ever feels cramped at 6.0in, the table width can be
  nudged up toward 6.2in max — but keep clearance to the divider (do not exceed ~6.2in). 6.0in was the
  verified-clean value.

## DO NOT
- Do not remove the divider (the reference slip has one).
- Do not revert to `width: 100%` (that's what caused the overlap).
- Do not change `@page` orientation, the Additional Notes source (pulls correctly from the WC order
  customer note — confirmed), or any slip data/structure.
- Do not change the `.slip-left` / `.slip-right` widths or paddings — the fixed table width alone
  fixes the overlap; leave the column geometry as-is.
