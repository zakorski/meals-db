# 01 — Schema: meals_slip_batches table

> **✅ COMPLETE (2026-06-26, branch `feature/midland-packing-documents`, PR #438, commit 796c16f).**
> `SLIP_BATCHES` constant added to `includes/class-tables.php` (+ in `all()`); schema block added to
> `includes/class-schema.php` mirroring `INVOICE_DRAFTS` (array `primary_key`, 3 indexes). Generates valid
> CREATE TABLE SQL. Version bumped 1.0.470→1.0.471 (unit 07) so schema-sync creates it on upgrade.

## Goal
Add a new table that persists a generated slip batch: its identity (zone + delivery date), the saved
doc 4 payloads (one per order, in positional order), the uploaded doc 3 (path/blob ref), the merged
output, and its state. Mirrors the INVOICE_DRAFTS table pattern.

## Reference (study before editing)
- Table constants: `includes/class-tables.php` (e.g. `INVOICE_DRAFTS = 'meals_invoice_drafts'`, line 47).
- Schema definition shape: `includes/class-schema.php`, the `INVOICE_DRAFTS` block (~line 691).
- Table name resolver: `MealsDB_DB::get_table_name(MealsDB_Tables::X)`.
- Installer runs schema on activation: `MealsDB_Installer::install()` (see install-schema).

## Edit 1 — add the table constant
**File:** `includes/class-tables.php`
Add, alongside the other `public const` lines (after `PURCHASE_ORDERS` or near `INVOICE_DRAFTS`):
```php
    public const SLIP_BATCHES = 'meals_slip_batches';
```

## Edit 2 — add the schema definition
**File:** `includes/class-schema.php`
Add a new entry mirroring the `INVOICE_DRAFTS` block. Columns:
```php
MealsDB_Tables::SLIP_BATCHES => [
    'table'   => MealsDB_Tables::SLIP_BATCHES,
    'engine'  => 'InnoDB',
    'columns' => [
        'batch_id'       => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        // Identity: one batch per zone + delivery date.
        'zone_name'      => 'VARCHAR(100) NOT NULL',
        'delivery_date'  => 'DATE NOT NULL',
        'order_count'    => 'INT UNSIGNED NOT NULL DEFAULT 0',
        // Saved doc 4 payloads: JSON array, one element per order, in the SAME
        // positional order doc 2 was generated. Each element = the driver-block
        // data for that order (name, address, phone(s), collect_amount, order_number).
        // Encrypted (contains client PII) — use the same encryption helper the
        // invoice draft payload uses (see class-invoice-draft.php create()).
        'doc4_payload'   => 'LONGTEXT NOT NULL',
        // Uploaded doc 3: store the rasterized-background reference or the raw
        // PDF bytes path. Implementation: store the uploaded PDF in WP uploads
        // (wp_upload_dir) under a mealsdb-slips/ subdir; keep the path here.
        'doc3_path'      => 'TEXT NULL',
        'doc3_page_count'=> 'INT UNSIGNED NULL',
        // Merged output: path to the saved finished PDF (like invoices save output).
        'merged_path'    => 'TEXT NULL',
        'status'         => "ENUM('generated','doc3_uploaded','combined') NOT NULL DEFAULT 'generated'",
        'created_by'     => 'BIGINT UNSIGNED NULL',
        'created_at'     => 'DATETIME NOT NULL',
        'updated_at'     => 'DATETIME NOT NULL',
    ],
    'primary_key' => 'batch_id',
    // Match the index/charset declarations used by INVOICE_DRAFTS in this file.
],
```
IMPORTANT: copy the exact `primary_key`, `charset`/`collate`, and index declaration style from the
`INVOICE_DRAFTS` entry in this same file — do not guess the surrounding keys; match siblings verbatim.

## Edit 3 — confirm activation creates it
The installer iterates the schema registry on activation. Confirm `meals_slip_batches` is created by
re-activating the plugin (or however STR-11 schema-sync runs). No code change if the installer already
loops all registered tables — just verify the new entry is picked up.

## Notes
- `doc4_payload` and `doc3_path`/`merged_path`: payloads with PII are ENCRYPTED; file paths are not PII
  but the FILES (rendered PDFs with names/addresses) must live in a non-public location — store under
  `wp_upload_dir()['basedir'] . '/mealsdb-slips/'` with a `.htaccess deny` or an index guard, NOT under
  a web-served path. (Invoice outputs: check how they're stored and mirror that exactly.)

## Verify
```
php -l includes/class-tables.php
php -l includes/class-schema.php
# re-activate plugin on a test site; confirm: SHOW TABLES LIKE '%slip_batches%'
php tests/test-*.php   # suite still green
```
