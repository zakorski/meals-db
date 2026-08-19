# DIRECTIVE — Weekly order audit: Add Item, SKU column, sort by last name

**Baseline:** v1.0.553.
**Source:** stakeholder call 2026-08-14 (Janet), clarified by Zak same day.
**Severity:** feature. **Explicitly NOT inventory-touching** — see the boundary note below.

Files: `includes/admin/class-order-audit-page.php`, `includes/services/class-order-audit.php`,
`assets/js/order-audit.js`, plus the audit AJAX handler.

### Boundary — read first

This is the **weekly order audit** (client orders), NOT the Apetito purchase-order audit. An item added
here represents **something that was shipped to a client unexpectedly**.

**Nothing in this directive may touch inventory.** (Zak, 2026-08-14.) Do not call
`apply_inventory_bump()`, do not adjust stock, do not hook anything in `MealsDB_Purchase_Orders`. A
separate audit report will consume this data later; recording the fact is the whole job here.

================================================================================
## ITEM 1 — "Add Item" in the row editor
================================================================================

### Where it goes

Each audit row (`class-order-audit-page.php` ~line 297) has a hidden `.oa-editor-row` beneath it
(~line 332) containing one number input per snapshot item, a note textarea, and a button row:
**Save** (`.oa-editor-save`), **Revert to pending** (`.oa-editor-revert`), **Cancel** (`.oa-editor-cancel`).

Add an **Add Item** button (`.oa-editor-add-item`) to that same `<p>` button row. Clicking it appends a new
line to `.oa-editor-items` containing:
- a **searchable product dropdown**, and
- a quantity input (`min="1"`, default 1), and
- a small remove control for an added line that hasn't been saved yet.

### The product dropdown

**Reuse the existing endpoint — do not write a new product search.**
`wp_ajax_mealsdb_qo_search_products` (`class-quick-order-ajax.php` line 35) already does typeahead against
the cached QO product list, and `wp_ajax_mealsdb_qo_get_all_products` (line 36) returns the full list. The
QO product payload carries `product_id`, `name`, `sku`, `product_type`, `category`, `price` — everything
needed here.

If the audit page's nonce/permission context makes those endpoints unusable as-is, add a thin audit-side
handler that calls `MealsDB_Quick_Order_Products::get_all_quick_order_products()` directly. **Do not
duplicate the product-cache logic.**

### Storage — a NEW payload key, not `edited_items`

This is the part that will bite if done by analogy. `edit_row()`
(`class-order-audit.php` line 407) validates every submitted key against the snapshot:

```php
if (!array_key_exists($key, $known)) {
    return new WP_Error('unknown_item', __('Unknown order item.', 'meals-db'));
}
```

`edited_items` is keyed by `item_key`, which **is the WooCommerce `order_item_id`** (line 175). An added
item has no order item, therefore no `order_item_id`, therefore no valid key. Pushing added items through
`edited_items` would either trip `unknown_item` or force a fake key that collides with a real one later.

Add a separate row key instead:

```php
'added_items' => [],   // list of ['product_id'=>int,'sku'=>string,'product_name'=>string,'qty'=>int]
```

- Initialise it to `[]` in the snapshot builder alongside `'edited_items' => []` (~line 198) and in the
  revert path (~line 389).
- Add a sibling method to `edit_row()` — e.g. `add_item_row()` / extend `edit_row()` with an
  `$added` parameter — that validates: `product_id` exists in the product catalogue, `qty >= 1`, and the
  note length cap (`MAX_NOTE_LEN`, 500) still applies.
- Set `audit_status = ROW_EDITED` when an item is added, so the row counts as resolved exactly as a
  quantity edit does.
- **Audit-log it**, matching the existing delta logging (line ~443, which logs `key:old→new` deltas with no
  PII). An added item should log as something like `+{product_id}:{qty}` — product ids and counts only, no
  client data.

### Snapshot counts

Do **not** recompute `mains_count` / `sides_count` from added items. Those are snapshot values and the
existing `Δ` marker (`.oa-delta`, ~line 304) already signals "this row was adjusted". The code comment
there notes mains/sides cannot be re-derived without product categories — added items do carry a known
product, but mixing derived and snapshot counts in the same column would make the number mean two different
things. Leave the counts alone and let the forthcoming audit report do the arithmetic.

### Display

An added item must be **visually distinct** from a snapshot item in the editor — snapshot items show
`(delivered: N)`; an added item should be labelled as added (e.g. "added — not on original order"). After
save, added items persist in the editor when it is reopened, and can be removed.

================================================================================
## ITEM 2 — SKU column
================================================================================

Janet needs the SKU visible for wrong/extra items.

The snapshot stores only `product_name` from `order_item_name` (line 175–176) — no SKU. Note the WooCommerce
item name often embeds the number (`"Chicken with Honey BBQ Sauce - 12148"`), but **do not parse the SKU out
of the name** — the format is inconsistent (`"Fish with Spiced Rice - sku# 12029"` appears in the same data).

Instead:
- Capture `sku` in the snapshot builder, resolving it from the line item's `_product_id` against
  `meals_products.sku`, and store it alongside `product_name` in each `items[]` entry.
- Add a **SKU column** to the audit table, and show the SKU in the editor next to each item name.
- Added items already carry `sku` from the product payload.

**Existing draft audits have no `sku` in their snapshots.** Display an empty cell for those rather than
backfilling or erroring — they age out weekly.

================================================================================
## ITEM 3 — Sort alphabetically by last name
================================================================================

Rows currently render in whatever order the snapshot map yields (keyed by `order_id`). Janet wants the
audit table sorted **alphabetically by client last name**.

The snapshot stores only a combined `client_name` (`first + ' ' + last`, ~line 190), which cannot be split
reliably — the client data contains multi-word first names ("Joseph Roger", "Mary Lee") and compound
surnames ("Avery-Jones", "Soucy Blackmore"). **Do not split the string.**

Add `'client_last_name'` to the snapshot from `$client['last_name']`, which is already in scope where
`client_name` is built, and sort on it. Sort case-insensitively, with `client_name` as the tiebreaker so
same-surname clients order predictably.

For rows from existing snapshots lacking the field, fall back to the trailing word of `client_name` so the
page still sorts rather than erroring.

================================================================================
## Must NOT change
================================================================================
- **No inventory effect of any kind.**
- `edited_items` semantics and the `unknown_item` guard — added items go in `added_items`, not through that
  path.
- `item_key` continuing to mean the WooCommerce `order_item_id`.
- The finalized/draft gate: rows are editable only while the audit is a draft (`$editable`). **Add Item must
  be unavailable on a finalized audit**, same as the existing controls.
- `MAX_NOTE_LEN` (500), and the note field serving as the reason for an added item — no separate reason
  code (Zak, 2026-08-14).
- The `mutate_row()` concurrency wrapper — added items must go through it, not around it.
- The audit-log isolation pattern (a broken audit-log backend must not make a successful edit report
  failure) — keep added-item logging inside `log_lifecycle` the same way.
- `delivery_occurrence` as the source of `delivery_date`.

================================================================================
## Verify
================================================================================
1. Open a draft audit, click **Add Item** on a row, search the dropdown, pick a product, set qty 2, Save.
   Row status becomes **Edited**; reopening the editor shows the added item with its SKU. 📷
2. **Inventory is unchanged** by that add — check the inventory table READ-ONLY before and after. 📷
   **This is the assertion that matters most.**
3. Add an item, then remove it before saving → nothing persists. Add, save, reopen, remove, save → it's
   gone. 📷
4. `mains_count` / `sides_count` on the row are **unchanged** by an added item; the `Δ` marker shows. 📷
5. SKU column populates for a **newly created** weekly audit; an older draft shows blank SKUs without
   erroring. 📷
6. Rows sort by last name — verify with a compound surname (Avery-Jones, Soucy Blackmore) and a multi-word
   first name (Joseph Roger Cormier) landing in the right place. 📷
7. Finalize the audit → **Add Item is not available**, and existing added items still display. 📷
8. Quantity edits on snapshot items still work exactly as before (regression on `edit_row`). 📷
9. Audit log shows the added item as product id + qty, with no client PII. 📷
