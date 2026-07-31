# Weekly Order Audit — design spec

**Date:** 2026-07-30
**Status:** approved pending user review
**Operator decisions baked in:** record-keeping only (no allocation/billing effect); week picker
defaulting to the last completed Monday–Sunday week; grid rows show summary counts only.

## Purpose

Once a week, a Meals and More staff member takes the week's delivery paperwork and confirms,
order by order, that what is on paper is what each client actually received (items get damaged
or forgotten). This feature gives them an interface for that job: pull the week's delivered
orders into a draft, confirm or correct each one, and save the finished audit as a locked,
auditable record.

**Explicit non-goal (v1):** an edited quantity does NOT flow into the allocation ledger,
billing, or the WC order. The finalized audit is a standalone record of discrepancies;
corrections remain a manual follow-up. A later phase may add push-to-ledger.

## Architecture

New dedicated service + table, deliberately copying the `MealsDB_Invoice_Draft` pattern
(encrypted snapshot payload, draft → finalize → unfinalize lifecycle) but NOT reusing its
table or code: invoice-draft `finalize()` freezes allocation billing months (LB-3) and
serializes government CSVs — both wrong for an audit.

Components:

| Unit | File | Responsibility |
|---|---|---|
| `MealsDB_Order_Audit` | `includes/services/class-order-audit.php` | Persistence + lifecycle. No HTML, no AJAX. |
| `MealsDB_Ajax_Order_Audit` | `includes/ajax/class-ajax-order-audit.php` | Nonce/capability/rate-limit-gated endpoints. |
| Admin page | `includes/admin/class-order-audit-page.php` + `assets/js/order-audit.js` | Week picker, audit list, review grid. |
| Schema | `MealsDB_Schema` / `MealsDB_Tables::ORDER_AUDITS` | New table `meals_order_audits`. |

## Data model

Table `meals_order_audits` (additions-only schema change; bump `MEALS_DB_VERSION`):

- `audit_id` BIGINT UNSIGNED AUTO_INCREMENT PK
- `week_start` DATE NOT NULL, `week_end` DATE NOT NULL — Monday and Sunday of the audited week
- UNIQUE KEY on `week_start` — one audit per week; creating where one exists surfaces the
  existing audit instead of erroring
- `status` ENUM('draft','finalized') NOT NULL DEFAULT 'draft'
- `payload` LONGTEXT NOT NULL — encrypted via `MealsDB_Encryption::encode_payload()` /
  `decode_payload()` (client names are PII). **Fail CLOSED** (QW-2): if encoding fails the
  audit is not created/saved and a degraded event is recorded.
- `row_count`, `confirmed_count`, `edited_count` INT UNSIGNED — denormalized for the list view
- `created_by`, `created_at`, `finalized_by`, `finalized_at`, `unfinalized_at`,
  `unfinalize_reason` — UTC timestamps (`gmdate`), user ids
- No foreign keys (matches the rest of the schema — STR-1 is deliberate)

Payload shape (`schema` version key for forward migration, mirroring invoice drafts):

```
{
  schema: 1,
  generated: { <order_id>: row, ... },   // immutable snapshot at pull time
  current:   { <order_id>: row, ... }    // working copy the auditor mutates
}
```

Snapshot row (one per order):

- Identity: `order_id`, `wp_user_id`, `client_id`, `client_name`, `delivery_date`
  (the resolved delivery occurrence), `zone`
- Contents: `items` — list of `{item_key, product_name, qty}` line items (drives the editor);
  `mains_count`, `sides_count` — summary integers using the same product classification the
  packing slips use (grid shows only these)
- Audit state: `audit_status` ∈ `pending | confirmed | edited` (starts `pending`),
  `edited_items` — `{item_key: new_qty}` map (only for `edited` rows), `note` (≤ 500 chars,
  like PO reconcile notes), `audited_by`, `audited_at`

## Week selection ("what went out that week")

Reuses the packing-slip selection so the audit list matches the paperwork by construction:

1. Fetch all active clients with `wp_user_id > 0` (any client type), keyed by `wp_user_id`,
   carrying `delivery_day` + `delivery_frequency`.
2. `MealsDB_Delivery_Slip_Generator::get_orders_for_delivery_range($clients, $week_start,
   $week_end)` — the shared delivery-basis occurrence filter (MAJ-6 / GUI-SLIP-RANGE) that
   both slip paths use. An order placed ahead of its delivery day lands in the week it was
   delivered, not the week it was created.
3. Note: the slip PDF's `resolve_delivery_date()` honors a manual `_delivery_date` order meta
   override with highest priority (see DIRECTIVE-manual-delivery-date-override). The audit
   stores the same resolved date per order; if/when that directive lands, the audit inherits
   the override through the shared code path — no audit-side change expected.

## Workflow

**Create.** Admin page (submenu under Meals DB, near the invoice pages; baseline
`MealsDB_Permissions` capability — `manage_woocommerce`, same as slips and invoice drafts).
Week picker defaults to the most recent completed Mon–Sun week; any past week selectable.
"Create audit draft" pulls the week's orders and snapshots them. Zero-order weeks create a
valid empty draft (finalizable immediately), mirroring invoice-draft semantics.

**Review grid.** One order per row: client name, delivery date, order #, mains count, sides
count, status badge (`pending` / ✓ `confirmed` / ✎ `edited` + note indicator), then two
controls:

- **Confirm** button (✓): marks the row `confirmed` — "received exactly as per the paperwork."
  Clicking again reverts to `pending` (misclick recovery).
- **Pencil** (`dashicons-edit`): opens an inline editor listing each line item with its
  snapshot quantity in an editable field, plus a note textarea. Saving sets the row to
  `edited` and stores `edited_items` + `note`. The editor offers "Revert to pending" to
  discard an edit. Grid displays edited rows with old→new quantity deltas on the summary
  counts.

Each confirm/edit/revert is a single AJAX call, defense-in-depth gated (Pattern 1): view-layer
`MealsDB_Permissions::enforce()`, handler nonce (`mealsdb_order_audit` context — a distinct
destructive-ish action category) + capability + rate limit, service-layer capability re-check.
New rate-limit bucket `order_audit_edit` at **1000/hour, fail-closed** — an auditor confirms
~300+ rows in one sitting (16k orders/yr ≈ 300/wk), so the invoice-draft bucket (300/hr) is
too small; same one-user-many-small-writes rationale as `invoice_draft_edit` / `po_draft_edit`.

**Finalize.** Enabled only when no row is `pending` (`confirmed + edited == row_count`,
enforced server-side, not just in JS). Stamps `finalized_by/at`, flips status, grid becomes
read-only. No output artifact is produced (nothing to serialize — the record IS the artifact).

**Unfinalize.** Mirrors the invoice-draft unfinish flow: requires a typed reason (≤ 500
chars), audit-logged, flips back to `draft` with all row states intact. No cascade concept
needed (nothing downstream consumes the audit).

**Delete.** A `draft` (never a finalized audit) can be deleted after a confirmation prompt, so
a bad pull can be redone — the UNIQUE week constraint otherwise blocks regeneration.

## Logging (STR-LOG boundary)

- **Audit log** (`MealsDB_Logger::log`): lifecycle events (created, finalized, unfinalized
  with reason, draft deleted) and each **edit** (order id, item deltas summary, note
  presence) — edits are the discrepancies, the thing the audit exists to record.
- **Payload only** for confirms: `audited_by`/`audited_at` on the row. ~300 confirms/week
  would bloat the append-only audit log for zero investigative value; the confirm attestation
  is preserved inside the (encrypted, finalized) payload.
- **Operational trunk** (`MealsDB_Event_Log`): failures/degraded outcomes (encryption
  failure, order pull returning errors), per the established pattern.

## Error handling

All service methods catch `\Throwable`, log, and return sentinels (0 / null / false /
`WP_Error`) — never propagate (Pattern 7). AJAX returns `wp_send_json_error` with
translatable messages. Output escaping at emit time (`esc_html`/`esc_attr`); grid JS reuses
the shared escaper conventions. UTC everywhere.

## Tests (standalone `php tests/test-*.php` style, written test-first)

- `test-order-audit.php` — service: create snapshots generated==current; unique-week returns
  existing; confirm/edit/revert transitions update counts; finalize rejects while any row
  `pending`; finalize stamps and locks (edits refused on finalized); unfinalize requires
  reason and restores editability; delete refused on finalized; encryption fail → create
  returns 0 + degraded event; payload round-trips encrypted (no plaintext client names in the
  stored column).
- `test-ajax-order-audit.php` — endpoint gating: nonce/capability/rate-limit rejection paths,
  server-side finalize gate.

## Out of scope (v1)

- Pushing corrections into allocations/billing/WC orders.
- CSV/PDF export of the audit.
- Multi-week or partial-week audits; per-driver filtering.
