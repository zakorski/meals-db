# 04 — MealsDB_Slip_Batch service (persistence layer)

> **✅ COMPLETE (2026-06-26, PR #438, commit 9219832).**
> `includes/services/class-slip-batch.php` — create/get/list_batches/store_doc3/store_merged/cancel
> (+ public `storage_dir()` for the merge engine). doc4_payload encrypted via `encode_payload` (QW-2
> fail-closed); files under `uploads/mealsdb-slips/{doc3,merged,tmp}/` with deny `.htaccess` + `index.html`,
> 0600, random doc3 names. Tests: `tests/test-slip-batch.php` (31 checks — real Encryption + on-disk lifecycle).

## Goal
A service class that owns the slip-batch lifecycle, mirroring `MealsDB_Invoice_Draft`. CRUD over the
`meals_slip_batches` table, encryption of the doc4 payload, file storage for doc3/merged PDFs.

## Reference (mirror this class closely)
`includes/services/class-invoice-draft.php`:
- `create()` (~line 47): builds payload, encrypts, `$wpdb->insert(...)`, returns id. Note the encryption
  helper it uses for `payload`, and that it records to the Event Log on failure.
- `load()` (~line 123): fetch by id, decrypt, return null if missing/undecryptable.
- the list/query method (~line 179): builds the history rows.
- `MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS)` for the table name.
Use the SAME encryption helper for `doc4_payload` (it contains client PII).

## New file: includes/services/class-slip-batch.php
Class `MealsDB_Slip_Batch` with static methods:

### create(string $zone_name, string $delivery_date, array $doc4_payloads): int
- `order_count = count($doc4_payloads)`.
- Encrypt the JSON-encoded `$doc4_payloads` (same helper as invoice draft payload).
- `$wpdb->insert` into SLIP_BATCHES: zone_name, delivery_date, order_count, doc4_payload(encrypted),
  status='generated', created_by=get_current_user_id(), created_at/updated_at=now.
- Return batch_id. On failure, `MealsDB_Event_Log::record(...)` and return 0 (mirror draft create()).

### get(int $batch_id): ?array
- Fetch row; decrypt doc4_payload to array; return assoc array incl. status/paths/counts. Null if missing.

### list_batches(array $filters = []): array
- Return history rows for the table view: batch_id, zone_name, delivery_date, order_count, status,
  created_at, has_doc3 (doc3_path not null), has_merged (merged_path not null). Newest first.

### store_doc3(int $batch_id, string $tmp_pdf_path, int $page_count): bool
- Move the uploaded PDF into `wp_upload_dir()['basedir'].'/mealsdb-slips/doc3/'` (create dir + index.html
  guard + .htaccess deny; these files are PII-bearing once merged). Save doc3_path, doc3_page_count.
- Set status='doc3_uploaded', updated_at=now. (Replaceable: overwrite prior doc3_path; delete old file.)

### store_merged(int $batch_id, string $pdf_bytes): string
- Write bytes to `.../mealsdb-slips/merged/batch-<id>.pdf` (overwrite on re-combine). Save merged_path,
  status='combined', updated_at=now. Return the path. (Same storage approach as invoice output — confirm
  how invoices persist their generated PDF and mirror it.)

### cancel(int $batch_id): bool
- HARD DELETE: delete the row AND remove doc3/merged files from disk. (Operator decision: hard delete;
  they regenerate if needed.) Return success. (Audit logging happens in the AJAX layer, unit 05.)

## Storage location (important)
All slip PDFs/backgrounds contain decrypted client PII once rendered. Store under
`wp_upload_dir()['basedir'].'/mealsdb-slips/'` with directory protection (index.html + .htaccess deny),
NEVER under a publicly-served path. Confirm how invoice PDFs are stored and match that exactly.

## Verify
```
php -l includes/services/class-slip-batch.php
php tests/test-*.php
```
