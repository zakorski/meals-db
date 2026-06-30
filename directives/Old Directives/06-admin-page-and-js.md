# 06 — Admin page: "Packing Slips" history view + JS

> **✅ COMPLETE (2026-06-26, PR #438, commit a24c74f).** `includes/admin/class-slip-batch-page.php`
> (`MealsDB_Slip_Batch_Page`, `manage_options` submenu under Meals DB) — Generate-batch control (zone select
> from schedule + delivery date) over a history table; per-row Doc 1/2/4 downloads (server-built nonce GET
> links), Upload Doc 3 (hidden file input), Combine (greyed until valid doc 3), Download merged, Cancel.
> `assets/js/slip-batch.js` drives the mutations (FormData upload, `.trigger()` not `.submit()`; node --check
> clean). No page-level unit test (matches the invoice-draft-page precedent); lint + node + cross-ref verified.

## Goal
A history table page (one row per batch) modeled on the invoice-draft history view, with per-row:
Download Doc 2, Download Doc 4, Upload Doc 3, Combine (greyed until valid), Cancel (confirm popup).

## Reference (mirror these)
- Admin page: `includes/admin/class-invoice-draft-page.php` — `PAGE_SLUG` const (line 40),
  `init()` (42) adds `admin_menu` → `register_menu()` (47) with `add_submenu_page(...)`, the asset-enqueue
  guard (~line 60, only enqueue on this page), and `wp_localize_script` of `pageUrl`/nonce (~line 81).
- View/JS: `assets/js/invoice-draft.js` (the table interactions, AJAX calls, nonce usage).
- Cancel-with-confirm + audit precedent: the un-finalize flow (invoice draft) uses a confirm + reason +
  audited AJAX — mirror the confirm-popup-then-AJAX shape (slips need confirm only, no reason).

## New file: includes/admin/class-slip-batch-page.php
- `public const PAGE_SLUG = 'mealsdb-packing-slips';`
- `init()` → `add_action('admin_menu', [self::class,'register_menu'], 22);` and enqueue assets only when
  the current hook matches PAGE_SLUG (copy the guard from invoice-draft-page ~line 60).
- `register_menu()` → `add_submenu_page` under the Meals DB menu, title "Packing Slips".
- `wp_localize_script('mealsdb-slip-batch', 'MealsDBSlipBatch', ['ajaxUrl'=>admin_url('admin-ajax.php'),
   'nonce'=>wp_create_nonce(<same NONCE_ACTION the ajax class uses>), 'pageUrl'=>...]);`
- render(): a "Generate batch" control at top (zone picker + delivery date + Generate button), then the
  history table (rendered server-side initially, refreshed via the `mealsdb_slip_list` AJAX).

## Table columns
Zone | Delivery date | # orders | Generated | Status | Actions
Actions cell per row:
- **Download Doc 2** (button → mealsdb_slip_download_doc2)
- **Download Doc 4** (button → mealsdb_slip_download_doc4)   [always enabled — the manual fallback]
- **Upload Doc 3** (file input/button → mealsdb_slip_upload_doc3)
- **Combine** (button, `disabled` by default; enabled only after a valid doc3 upload response)
- **Cancel** (button → confirm popup → mealsdb_slip_cancel)

## New file: assets/js/slip-batch.js
Mirror `invoice-draft.js` conventions (jQuery, the plugin's AJAX helper, nonce from the localized object).
- Generate: POST zone+date → on success, refresh the table.
- Upload Doc 3: POST the file (FormData) → response `{valid:true}` enables that row's Combine button;
  `{valid:false, message}` shows the reason (e.g. "Uploaded 54 pages but batch has 56 orders") and keeps
  Combine disabled. Re-upload allowed any time.
- Combine: enabled only when valid; POST → on success, offer/trigger the merged PDF download; mark row
  status "combined"; keep upload/combine available for re-runs.
- Cancel: show a **confirmation popup** ("Cancel this batch? This permanently deletes the saved driver
  sheets and any uploaded scan. This cannot be undone.") → on confirm, POST → remove the row on success.
- Doc2/Doc4 downloads: trigger the download (match how invoice-draft.js triggers PDF downloads).

## jQuery note
The slip UI historically used `.submit()` (deprecated) — in this new JS use `.trigger('submit')` and
modern event binding to avoid the JQMIGRATE warning (see views/daily-slips.php fix precedent).

## Verify
```
php -l includes/admin/class-slip-batch-page.php
# Load the page in wp-admin: table renders, Combine greyed until a valid doc3 upload, cancel shows the
# confirm popup, all buttons hit their handlers. Mismatched page count blocks Combine with a message.
```
