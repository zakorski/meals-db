# Combined Spec — Midland Packing & Shipping Documents (Docs 1–4 + Merge)

Faithful reproduction of the four Midland documents, populated from the CURRENT plugin's fields.
The reference scans are the spec for APPEARANCE; the plugin is the source for CONTENT; where the old
documents had errors/ambiguity, the plugin version corrects them (noted inline).

Renders verified in development against real data (order #24738 CHK; order #27120 Magella Landry/MLD).
Companion files: doc1.pdf, doc2f.pdf (packer), merged_prod.jpg (doc3+doc4), doc3_bg300-1.jpg.
See also SCOPE-doc3-doc4-merge.md (two-phase persist+merge architecture) and SPEC-doc4-driver-block.md
(full doc 4 detail). This file is the umbrella.

## Shared geometry (ALL docs) — SETTLED
- **Letter landscape**: 11in x 8.5in = 792 x 612 pt. (Reference doc1/doc2 PDFs were saved A4 — ignore;
  production is Letter.)
- Font: Helvetica/Arial sans (dompdf default).
- Doc 2/3 content lives in the LEFT region; the RIGHT region is left blank for handwriting (doc 3) and
  the driver block (doc 4). Right-region boundary follows the REFERENCE spacing (item table ends
  ~64% across), NOT a clean 2/3.

---

## DOC 1 — Cover sheet  (BUILD FROM SCRATCH; does not exist in plugin)
Per zone. Centered vertical stack, in order:
1. **"Zone N"** — ~48pt, centered.
2. **"Delivery Date: <Weekday, Month D, YYYY>"** — ~22pt, centered.
3. (gap)
4. **"ORDERS - TAKE FROM HOLD"** — ~20pt bold, centered.
5. **"<initials | initials | ...>"** — ~20pt bold, centered (the packer initials for this zone).
6. (gap)
7. **Legend table** (centered, ~6in wide): title row "LEGEND: DELIVERY SCHEDULE FOR PACKING"
   spanning; then columns ZONE # | WEEKDAY | AREA; 6 fixed rows:
   1 / Wednesday morning / Moncton Downtown
   2 / Wednesday afternoon / Sackville / Amherst
   3 / Thursday morning / Moncton Other
   4 / Thursday morning / Sussex
   5 / Friday morning / Dieppe / Riverview
   6 / Friday afternoon / Shediac
8. **"Orders Exported <Month D, YYYY @ h:mm am/pm>"** — ~20pt bold, centered.
9. (gap)
10. **"<N> Orders"** — ~26pt bold, centered (count for this zone/export).
11. **"Page X of Y"** — ~9pt, centered footer.
Cosmetic to final-tune: "Zone N" can be a touch larger; legend WEEKDAY/AREA headers centered (ref) vs
left (current). Content/structure verified correct.

### Doc 1 — data sources (every field; no placeholders)
- **"Zone N"** = the zone selected for this export (one cover sheet per zone).
- **"Delivery Date: <Weekday, Month D, YYYY>"** = the run's delivery date, long-formatted.
- **"ORDERS - TAKE FROM HOLD"** = CONSTANT string (hardcoded label).
- **Initials line ("WFN | JBL | MSA | JFL")** = COMPUTED: for each order in this zone's batch, read the
  WooCommerce order customer note (`$wc_order->get_customer_note()`); if it MATCHES "take from hold"
  (**case-insensitive CONTAINS** — so "take from hold", "TAKE FROM HOLD - back door", etc. all count),
  include that order's client `delivery_initials`. Join the qualifying initials with " | ".
  (This is why the sample shows only 4 initials for 56 orders — only 4 had the note.) All source fields
  are already fetched per-order by the slip generator (delivery_initials + get_customer_note); doc 1 just
  aggregates them at zone level.
- **Legend table** = STATIC constant: 6 fixed rows (ZONE# / WEEKDAY / AREA) — Moncton Downtown, Sackville
  / Amherst, Moncton Other, Sussex, Dieppe / Riverview, Shediac. Same on every cover sheet. (Confirm the
  mapping matches the plugin's zone definitions.)
- **"Orders Exported <Month D, YYYY @ h:mm am/pm>"** = the datetime the export/generation was run (NOT
  the delivery date).
- **"N Orders"** = count of orders in this zone's batch.
- **"Page 1 of Y"** = cover is page 1; **Y = 1 + order count** (orders are NEVER multi-page, so one page
  per order; no multi-page accounting needed).

---

## DOC 2 — Packer slip, NO notes  (REFINE existing slip generator)
Per order, ONE page, LEFT region only, RIGHT region BLANK. Order in zone+sequence.
Header (two-column): **"Name: <initials>"** (~15pt bold) left; **"Zone N - Order #NNNNN"** (~16pt bold)
toward center/right. (Ref centers the Zone-Order line slightly more than current — minor tune.)
Then:
- **"Delivery Date: <Weekday, Month D, YYYY>"** — ~10pt bold.
- **"Order N of M"** — ~10pt bold (order's position within the zone batch). [ADDED vs current generator]
- **Items table** (~7in wide, white header, bold SKU, tight rows): columns SKU | Qty | Product |
  Category. No grey header shading.
- **Totals line**: "Total Items: I | Total Mains: M | Total Sides: S" then "Page X of Y" (global page
  number across the whole zone export). [WORDING corrected to Total Mains/Total Sides; Page X of Y ADDED]
- **"Additional Notes:"** + WooCommerce customer note (e.g. "TAKE FROM HOLD"), if present.
- **RIGHT-SIDE DIVIDER LINE — REQUIRED.** Doc 2 IS doc 3 minus the handwriting, so doc 2 must DRAW the
  horizontal divider in the right region (the packer does not add it). Measured from the real doc 3:
  a horizontal line at **y=4.50in**, **x 7.59in to 10.85in (3.25in wide)**, 2px. Render as a
  background-color bar (not border-top). This is the exact line doc 4 anchors its text to — so doc 2
  and doc 4 share one source of truth for it.
Deltas from current generator that this fixes: single 24pt initials header -> two-col header; add Order
N of M; add Page X of Y; "Mains/Sides" -> "Total Mains/Total Sides"; grey header -> white; tighten rows;
ADD the right-side divider line.

### Doc 2 measured layout coordinates (from real doc 3, Letter landscape @300dpi)
- Header "Name: <init>": left 0.31in, top ~0.38in, 15pt bold.
- Header "Zone N - Order #": ~center-right (left ~4.6in), top ~0.36in, 16pt bold.
- "Delivery Date:": left 0.31in, top ~0.76in, 10pt bold.
- "Order N of M": left 0.31in, top ~1.08in, 10pt bold.
- Items table: left 0.24in, top ~1.26in, width ~6.9in (right edge ~7.14in).
- Totals + "Page X of Y": left 0.24in, top ~4.18in.
- "Additional Notes": left 0.24in, top ~4.46in.
- Divider: left 7.59in, top 4.50in, width 3.25in, height 2px.
(Orders are NEVER multi-page — each order is exactly one page. A long item list still fits one page in
the left region; it must not push into the right region where the divider/doc 4 live.)

---

## DOC 3 — Packer slip WITH notes (returned)  (INPUT, not generated)
= doc 2 after Midland handwrites on it, scanned, one page per order, returned in the SAME order doc 2
went out. Has the item list + handwriting + the slip's own horizontal DIVIDER LINE in the right region.
Pipeline turns it into a background (below).

### Doc 3 -> background (VERIFIED)
1. Upload doc 3 PDF (one page per order).
2. Detect orientation; rotate to upright Letter landscape (sample was Letter portrait needing +90deg;
   PRODUCTION MUST DETECT, not assume).
3. Rasterize **300 DPI, faithful preserve** (Imagick; on live) -> **3300 x 2550 px** JPEG per page.

---

## DOC 4 — Driver block  (GENERATE alone; OVERLAY onto doc 3)  [detail in SPEC-doc4-driver-block.md]
Driver block ONLY (no item table), on a blank Letter-landscape page, overlaid onto the matching doc 3.
Content, top-to-bottom:
- **"Collect: $X.XX"** — KEPT; the single authoritative collect amount (corrects old sheets' multiple
  wrong prices). ONLY price on doc 4. Must reflect the real amount due (e.g. $0.00 if already paid),
  not the order total.
- **Client name** — 16pt bold.
- **Address** — street; city + postal. 12pt, line-height 1.5.
- **Phone(s)** — incl. secondary number + contact name where present.
- Inline Subtl/Tax/Total breakdown REMOVED (was the wrong-price source).
**KEY RULE: doc 4 renders NO divider line** — every doc 2/3 already has one; doc 4's text anchors to
that existing background line (drawing one yields two stacked lines).
Calibrated constants (Letter landscape, background divider at y=4.50in):
- left **7.4in**, width **3.2in**, Collect-line top **4.62in** (divider + designed spacing).
- Clears item table (table right edge ~7.14in) with safety margin; sits in the clear band below
  handwriting (handwriting spanned 1.78–4.52in; clear band 4.52–8.50in).
Overlay: render doc 4 transparent (near-white -> transparent), composite over the 300dpi doc 3
background (VAC-invoice pattern). One driver block — using the DRIVER SHEET as background was the
earlier bug that doubled it; the correct background is doc 3 (packer-with-notes), which has none.
dompdf note: for any solid bar use a background-color div with explicit width/height, NOT border-top
(bleeds full width).

---

## Workflow (two-phase) — see SCOPE-doc3-doc4-merge.md
- **Phase A (export):** generate doc 1 + doc 2 per zone; ALSO generate + SAVE each order's doc 4 payload,
  paired positionally (one page/order), keyed by zone+delivery date. One zone at a time. Doc 2s -> Midland.
- **Phase B (return):** upload that zone's doc 3 PDF; for page N, composite saved doc 4 N onto the
  background of doc 3 page N; concatenate -> finished single-sided print-ready PDF.
- Pairing is POSITIONAL (doc 4 N -> doc 3 page N). NO page-count guard / order-number stamp — collation
  is the team's responsibility (operator decision), via the page numbers already on the docs.

## Dependencies / environment
- Imagick on live (confirmed) for PDF->image rasterization.
- dompdf (vendored) for generating docs 1/2/4.
- No new PDF library (no FPDI/TCPDF) required.

## Final confirmation pass (when wired in)
Generate doc 2 from the plugin, print/handwrite/scan a test doc 3, run the merge. Confirm: divider sits
~4.50in on the plugin's OWN doc 2/3; doc 4 lands correctly; left/width/fonts hold; adjust only the single
`top` constant if the plugin's divider y differs from the sample.

## Open items — RESOLVED (operator, 2026-06-26)
- Secondary phone + contact name: data model HAS them (`client_phone_2`, `alternate_contact_name`,
  `alternate_contact_phone_1/2`). Include only when present; emit nothing otherwise.
- Collect value source: already correct — `build_driver_block()` → `MealsDB_Collection_Calculator` yields
  the real amount due (not order total). Reuse it.
- Doc 1 legend: source rows from the `mealsdb_zone_delivery_schedule` option, not hardcoded.
- Doc 1 empty initials line: render literal `NONE` when no order qualifies.
- Doc 4 address must include `delivery_postal_code` (build_driver_block currently omits it — add it).
- Remaining: doc 1/doc 2 minor cosmetic tunes (Zone size; Zone-Order centering; legend header alignment).

---

## INTERFACE — "Packing Slips" history page (modeled on the SDNB Invoice history view)
The slip workflow is two-phase and stateful (generate now, complete when doc 3 returns), so the UI is a
HISTORY TABLE of saved batches — same pattern/machinery as the invoice-draft history (finalize/
unfinalize). Reuse that pattern; only the columns and per-row actions differ.

### Row = one generated batch (zone + delivery date)
Columns: Zone | Delivery date | # orders | Generated timestamp | Status | actions.

### Per-row actions
1. **Download Doc 2** — the packer slips (item list left, blank-but-divider right). Always available.
2. **Download Doc 4** — the driver blocks STANDALONE. Always available. *Deliberate fallback:* the team
   can print doc 4 alone and do the old manual print-on-top method if ever needed. The auto-merge is an
   enhancement, never a forced replacement — this is also why strict upload validation (below) is safe.
3. **Upload Doc 3** — file picker for the returned scan PDF. Replaceable: can be re-uploaded as many
   times as wanted (e.g. a corrected scan). Each upload is logged to the audit log.
4. **Combine** — GREYED OUT until a valid doc 3 is uploaded. When active, runs the merge (composite saved
   doc 4 N onto doc 3 page N) and produces the finished print-ready PDF. Re-combinable unlimited times
   (after a replaced upload). Each combine is logged to the audit log.
5. **Cancel** — requires a CONFIRMATION POPUP, then HARD-DELETES the batch (saved doc 4 + any uploaded
   doc 3 + merged output). Logged to the audit log. They regenerate from scratch if needed later.

### Doc 3 upload validation (gates the Combine button)
Combine activates ONLY when the uploaded file passes BOTH:
- (a) it is a PDF, AND
- (b) its **page count == the batch's order count** (one page per order; orders are never multi-page).
A mismatch **BLOCKS** (Combine stays greyed) — this prevents combining incorrect/mis-scanned slips.
Blocking is acceptable precisely because the standalone Doc 4 download lets them proceed manually; the
block forces them to notice a bad scan rather than silently merging wrong data.

### Persistence / saved output
- The batch (saved doc 4 set, keyed by zone+delivery date) persists from generation until cancelled —
  same persistence approach as invoice drafts.
- The merged output PDF is SAVED ON THE ROW (like invoices), re-downloadable without re-combining.
- A replaced doc 3 / re-combine overwrites the saved merged output (latest wins); every attempt is in
  the audit log.

### Status lifecycle (visible via which actions are live)
Generated (doc2+doc4 downloadable, Combine greyed) -> Doc 3 uploaded & validated (Combine active) ->
Combined (merged PDF saved/downloadable; re-upload/re-combine still allowed) -> [Cancel hard-deletes].

### Audit log events (reuse the existing event log)
- slip_batch.generated, slip_batch.doc3_uploaded, slip_batch.combined, slip_batch.cancelled
  (names illustrative; match existing event-log conventions). Each carries zone + delivery date + counts.

### Reuse / build notes
- Table + per-row actions + cancel-with-confirm-popup + audit logging = the invoice-draft / un-finalize
  machinery; reuse it.
- Persisted batch with status = the invoice draft layer.
- The only NEW primitive is the doc 3 FILE UPLOAD + validation + merge trigger (the slip generator has
  only ever emitted PDFs, never accepted a file) — new AJAX handler + file handling + the merge engine.
