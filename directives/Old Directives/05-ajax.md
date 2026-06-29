# 05 — AJAX handlers

> **✅ COMPLETE (2026-06-26, PR #438, commit aea6080).** `includes/ajax/class-ajax-slip-batch.php`
> (`MealsDB_Ajax_Slip_Batch`) — guard stack mirrored from invoice-draft (nonce → `manage_options` → rate
> limit). JSON mutations: generate_batch, upload_doc3 (PDF sniff + size cap + `validate_doc3` gate), combine,
> cancel, list. GET file streams: download_doc1/doc2/doc4/merged (nonce-in-URL, `wp_die` on failure).
> Committed changes audited via `MealsDB_Logger::log` (STR-LOG boundary — invoice-draft's actual pattern;
> directive 07's Event_Log note superseded). Tests: `tests/test-ajax-slip-batch.php` (24 checks).

## Goal
A new AJAX class exposing: generate batch, download doc2, download doc4, upload doc3, combine, cancel.
Mirror the structure, guard stack, and nonce handling of `class-ajax-invoice-draft.php`.

## Reference (mirror exactly)
- `includes/ajax/class-ajax-invoice-draft.php`:
  - `init()` (~line 53): `add_action('wp_ajax_mealsdb_<action>', [__CLASS__,'<handler>'])`.
  - `guard(string $rate_bucket)` (the private method): nonce via `check_ajax_referer(self::NONCE_ACTION,
    'nonce', false)` → `current_user_can('manage_options')` → rate limiter. Returns bool, emits JSON error
    on fail. REUSE this exact stack. `manage_options` is required (doc4 = decrypted PII).
- Existing slip AJAX (the generation entry today): `includes/ajax/class-ajax-delivery-slips.php`
  (`zone_packer_pdf` line 67, `zone_driver_pdf` line 87, `make_pdf_generator()` line 289).
- Event log: `MealsDB_Event_Log::record([...])` (see unit 07 for shape).

## New file: includes/ajax/class-ajax-slip-batch.php
Class `MealsDB_Ajax_Slip_Batch`, `init()` registers:
- `wp_ajax_mealsdb_slip_generate_batch` → generate_batch
- `wp_ajax_mealsdb_slip_download_doc2`  → download_doc2
- `wp_ajax_mealsdb_slip_download_doc4`  → download_doc4
- `wp_ajax_mealsdb_slip_upload_doc3`    → upload_doc3
- `wp_ajax_mealsdb_slip_combine`        → combine
- `wp_ajax_mealsdb_slip_cancel`         → cancel
- `wp_ajax_mealsdb_slip_list`           → list (for the history table refresh)

Every handler starts with `if (!self::guard('settings_modify')) return;` (use an appropriate rate bucket;
match what invoice-draft uses).

### generate_batch(): zone + delivery_date in.
1. Build the zone's orders via the slip generator's client/order query (reuse
   `MealsDB_Slip_PDF_Generator` paths). For each order build the doc4 payload (name/address/phone(s)/
   collect_amount/order_number) IN ORDER.
2. `MealsDB_Slip_Batch::create($zone, $date, $doc4_payloads)`.
3. Audit: `slip_batch.generated` (zone, date, order_count).
4. `wp_send_json_success(['batch_id'=>..., 'order_count'=>...])`.
(Doc 1 + doc 2 are produced on demand via the download handlers below, OR generated here and their
bytes cached — simplest: generate doc1/doc2 on download to avoid storing more than needed.)

### download_doc2 / download_doc4: batch_id in → stream the PDF.
- doc2: render the zone's packer slips (doc 2 with divider) → `wp_send_json` with a download, OR stream
  bytes with proper headers (match how zone_packer_pdf currently returns the PDF in
  class-ajax-delivery-slips.php — mirror that response mechanism).
- doc4: render the saved doc4 payloads as the standalone driver-block pages (the OLD-WAY fallback).

### upload_doc3: batch_id + uploaded file in.
1. Validate the upload: it's a PDF; save to a temp path.
2. Page count via `MealsDB_Slip_Merge::validate_doc3($tmp, $batch['order_count'])`.
3. If invalid (not PDF, or page_count !== order_count): `wp_send_json_error(['message'=>reason,
   'valid'=>false])` and do NOT store. (Front-end keeps Combine disabled.)
4. If valid: `MealsDB_Slip_Batch::store_doc3(...)`; audit `slip_batch.doc3_uploaded` (zone, date,
   page_count). `wp_send_json_success(['valid'=>true])` (front-end enables Combine).
- Replaceable: a new upload overwrites the previous (store_doc3 handles file replacement).

### combine: batch_id in.
1. Load batch; require status doc3_uploaded (doc3_path present). Else error.
2. `MealsDB_Slip_Merge` rasterize + composite + concatenate → merged PDF bytes.
3. `MealsDB_Slip_Batch::store_merged($id, $bytes)`.
4. Audit `slip_batch.combined`. `wp_send_json_success(['download'=>...])` (or stream).
- Re-combinable: allowed any number of times; overwrites merged_path.

### cancel: batch_id in.
1. Confirmation is a FRONT-END popup (unit 06); server just executes.
2. `MealsDB_Slip_Batch::cancel($id)` (hard delete + files).
3. Audit `slip_batch.cancelled` (zone, date). `wp_send_json_success()`.

## File-upload security
- Restrict to PDF (check MIME + extension; never trust the name alone).
- Use the WP uploads API; store under the protected mealsdb-slips dir (unit 04). Enforce a size cap.
- Same `manage_options` gate as every other handler.

## Verify
```
php -l includes/ajax/class-ajax-slip-batch.php
php tests/test-*.php
```
