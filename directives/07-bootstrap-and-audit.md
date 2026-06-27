# 07 — Bootstrap wiring + audit events + final integration

> **✅ COMPLETE (2026-06-26, PR #438, commit 539e157).** `meals-db-main.php`: `MealsDB_Ajax_Slip_Batch::init()`
> + `MealsDB_Slip_Batch_Page::init()` wired (no explicit requires — the convention autoloader resolves the new
> `class-slip-*.php` files). Version bumped 1.0.470→1.0.471 so `mealsdb_maybe_upgrade_schema` creates the table.
> `uninstall.php` recursively removes `uploads/mealsdb-slips/` (PII hygiene). Integration verified: all 5
> new/extended classes autoload with expected methods; full suite green except the 2 pre-existing
> dompdf/mbstring env failures; 4 new slip test files pass (93 checks total).
> **NOTE:** the audit events use the AUDIT log (`MealsDB_Logger::log`), not `Event_Log::record` — that is
> invoice-draft's actual pattern and the correct STR-LOG home for committed record changes (see unit 05).
> **The end-to-end checklist below is LIVE-ONLY** (needs Imagick + dompdf + mbstring) — see PR #438 Test Plan.

## Goal
Register the new classes' init() calls, define the audit events, and run the final integration checklist.

## Reference
- Bootstrap init calls: `meals-db-main.php` (e.g. `MealsDB_Ajax_Invoice_Draft::init();` ~line 95, and the
  block of `::init()` calls around it). Admin pages init likewise.
- Event log: `includes/class-event-log.php` — `MealsDB_Event_Log::record(array $e): int` (~line 80).

## Edit — wire init() calls
**File:** `meals-db-main.php`
Alongside the existing `::init()` calls (near `MealsDB_Ajax_Invoice_Draft::init();`):
```php
MealsDB_Ajax_Slip_Batch::init();
MealsDB_Slip_Batch_Page::init();
```
Ensure the new class files are loaded by the plugin's autoloader/require list the same way the invoice
draft classes are (find where `class-ajax-invoice-draft.php` / `class-invoice-draft-page.php` /
`class-invoice-draft.php` are required and add the new files: `class-slip-batch.php`,
`class-slip-merge.php`, `class-ajax-slip-batch.php`, `class-slip-batch-page.php`).

## Audit events — exact shape
`MealsDB_Event_Log::record()` takes an assoc array. Fields it reads (from class-event-log.php ~line 80+):
`category` (<=50 chars), `event` (<=150), `severity` (one of the SEVERITIES set), `outcome` (one of
OUTCOMES or defaults), `occurred_at` (optional, defaults now). Match how invoice-draft un-finalize calls
record() for the precise field set (it's the closest precedent — find its record() call and copy the shape).

Emit:
- `slip_batch.generated`     — category 'slip_batch', event 'generated',     context: zone, delivery_date, order_count
- `slip_batch.doc3_uploaded` — category 'slip_batch', event 'doc3_uploaded', context: zone, delivery_date, page_count
- `slip_batch.combined`      — category 'slip_batch', event 'combined',      context: zone, delivery_date
- `slip_batch.cancelled`     — category 'slip_batch', event 'cancelled',     context: zone, delivery_date
(Use whatever the record() array actually supports for carrying context — match the invoice events.)

## Final integration checklist
1. `php -l` on every new/edited file (units 01–07).
2. Re-activate plugin on a test site → confirm `meals_slip_batches` table exists.
3. Full suite: `php tests/test-*.php` — green.
4. End-to-end on a test site:
   a. Generate a zone batch → row appears, status 'generated'.
   b. Download Doc 1 (cover): zone, date, TAKE-FROM-HOLD initials line correct, legend, count, page 1 of Y.
   c. Download Doc 2: header, Order N of M, Page X of Y, totals wording, table, divider line present, right blank.
   d. Download Doc 4 (standalone fallback): driver blocks render.
   e. Upload a doc 3 with WRONG page count → Combine stays disabled, message shown.
   f. Upload a doc 3 with correct page count → Combine enables.
   g. Combine → merged PDF: each driver block in the right region below the divider, one per page, matched
      to the order, no doubling, no collision.
   h. Re-upload a different doc 3, re-combine → output updates; both attempts in the Event Log.
   i. Cancel → confirm popup → row gone, files deleted, event logged.
5. Confirm Event Log shows generated/doc3_uploaded/combined/cancelled entries.

## Open items — RESOLVED by operator (2026-06-26); no longer blocking
- **Secondary phone + contact name:** fields exist (`client_phone_2`, `alternate_contact_name`,
  `alternate_contact_phone_1/2`). Include each ONLY when non-empty; emit nothing otherwise (unit 02).
- **Collect amount source:** already correct — `build_driver_block()` computes it via
  `MealsDB_Collection_Calculator`; reuse that value, do not recompute (unit 02).
- **Doc 1 legend mapping:** build dynamically from the `mealsdb_zone_delivery_schedule` option
  (AREA = zone-name key, WEEKDAY = label||day, ZONE # = existing zone numbering) — NOT hardcoded (unit 02).
- **Doc 1 empty initials line:** when no order has a "take from hold" note, render the literal `NONE`
  (unit 02).

## Remaining cosmetic-only follow-ups (do not block build)
- Minor doc 1/doc 2 cosmetics (Zone size, Zone-Order centering, legend header alignment).
- Add `delivery_postal_code` to build_driver_block's return + doc4 payload (it currently omits postal) —
  tracked in unit 02's Doc 4 content list.
