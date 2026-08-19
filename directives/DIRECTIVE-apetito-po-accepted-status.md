# DIRECTIVE — Apetito PO: `Accepted` status, inventory-commit move, and order cadence

**Baseline:** v1.0.553.
**Source:** stakeholder call 2026-08-14 (Janet).
**Severity:** feature + inventory-semantics change. **Inventory-touching — read ITEM 2 carefully.**

**Environment decisions already made by Zak (they remove the usual migration risk):**
- The task system is **not in use** and will be wiped → no task-chain compatibility to preserve, and the
  `payload IS NULL` legacy-PO guard has nothing left to protect.
- Existing POs will be **wiped** → **no in-flight migration needed.** No PO will be sitting at Approved
  when the inventory commit point moves.
- A **schema change is accepted.**

Files: `includes/class-schema.php`, `includes/services/class-purchase-orders.php`,
`views/purchase-orders.php`, `assets/js/purchase-orders.js`, plus the PO AJAX handler.

================================================================================
## ITEM 1 — Add the `accepted` status
================================================================================

New lifecycle:

```
planned (Draft) → placed (Approved) → accepted (Accepted) → arrived (Received) → reconciled
                                                                    cancelled (terminal, from any prior)
```

**Accepted = the vendor has confirmed the order.** It is a **manual** operator action, not automatic.

### Schema
`includes/class-schema.php` **line 490** currently declares:
```php
'status' => "ENUM('planned','placed','arrived','counted','reconciled','cancelled') NOT NULL DEFAULT 'planned'",
```
Change to:
```php
'status' => "ENUM('planned','placed','accepted','arrived','reconciled','cancelled') NOT NULL DEFAULT 'planned'",
```
**Also drop `counted`** in the same change — the file's own history note records that `STATUS_COUNTED` was
declared but never set anywhere and was removed for clarity; the ENUM member is the last vestige. Since POs
are being wiped, no row can hold it.

Add matching columns for the new step (mirroring the `received_by`/`received_at` pattern at the
`mark_received` transition):
```php
'accepted_by' => 'BIGINT UNSIGNED NULL',
'accepted_at' => 'DATETIME NULL',
```

### Service
In `MealsDB_Purchase_Orders`:
- Add `public const STATUS_ACCEPTED = 'accepted';`
- Remove any lingering `counted` reference.
- Add `'Accepted'` to `status_label()` (the switch at ~line 434 that maps `placed → Approved`,
  `arrived → Received`).
- Add `STATUS_ACCEPTED` to the valid-status list (~line 68).
- Update the class docblock lifecycle comment at the top of the file (lines 7 and 31) — it is the first
  thing every future reader sees and it currently states the old four-state flow.

================================================================================
## ITEM 2 — Move the inventory commit from Received to Accepted
================================================================================

**This is the part that must not be got wrong.** Today `apply_inventory_bump()` fires inside
`mark_received()` (**line 674**), on the Approved → Received transition. Janet's requirement is that stock
is committed when the **vendor confirms** — i.e. at Accepted — so inventory reflects confirmed-but-not-yet-
delivered stock.

### 2a — New `mark_accepted(int $po_id)`

Model it exactly on `mark_received()` (line 651), because that method already has the correct shape:

1. Permissions check (`MealsDB_Permissions::can_access_plugin()`).
2. `require_workflow_po($po_id, self::STATUS_PLACED, __('Only approved purchase orders can be marked accepted.', 'meals-db'))`.
3. **Run the guarded `transition()` FIRST, then the stock write.** This ordering is deliberate and is the
   existing double-click defence: the loser's `UPDATE` matches 0 rows and returns before any inventory is
   written. Do not reorder it.
   ```php
   $ok = $this->transition($po_id, self::STATUS_PLACED, self::STATUS_ACCEPTED, [
       'accepted_by' => get_current_user_id() ?: null,
       'accepted_at' => gmdate('Y-m-d H:i:s'),
   ]);
   if (!$ok) {
       return new WP_Error('race', __('Could not mark accepted (a concurrent change happened) — reload.', 'meals-db'));
   }
   self::apply_inventory_bump((array) $po['items']);
   ```
4. `MealsDB_Logger::log('po_accepted', $po_id, 'status', self::STATUS_PLACED, self::STATUS_ACCEPTED);`
5. `do_action('mealsdb_po_accepted', $po_id);`

### 2b — `mark_received()` becomes a pure state marker

- Change its `require_workflow_po` guard from `STATUS_PLACED` to **`STATUS_ACCEPTED`**, message: *"Only
  accepted purchase orders can be marked received."*
- Change its transition to `STATUS_ACCEPTED → STATUS_ARRIVED`.
- **DELETE the `self::apply_inventory_bump(...)` call at line 674.** Leaving it in place double-counts every
  PO — this is the single highest-risk line in the build.
- Keep `arrival_date` capture, the logger call, and `do_action('mealsdb_po_received', ...)` as-is.

### 2c — Un-accept (needed, because accept now moves stock)

`unapprove()` (~line 585) exists so an Approved PO can go back to Draft, and requires an audited reason.
Accepted needs the same escape hatch, but it must **reverse the inventory bump**, since accepting committed
stock:

- `unaccept(int $po_id, string $reason)`: require non-empty reason (mirror the existing
  `reason_required` error), guard on `STATUS_ACCEPTED`, transition `accepted → placed`, clear
  `accepted_by` / `accepted_at`, and **apply the inverse inventory adjustment**.
- Same ordering rule: transition first, stock write second.
- Log as `po_unaccepted` with the truncated reason, matching `po_unapproved`.

If `apply_inventory_bump()` cannot express a negative adjustment, reuse whatever mechanism
`complete_reconcile()` (line 763) uses to apply deltas — **do not write a second inventory-write
implementation.** The class comment at line 647 is explicit that there is exactly one inventory-bump
implementation in the plugin; keep it that way.

### 2d — Reconcile is unchanged

`reconcile_draft` / `complete_reconcile` still adjust inventory for what actually arrived. Its guard now
follows `arrived`, exactly as before — only the state that precedes `arrived` has changed.

================================================================================
## ITEM 3 — Order cadence and derived dates
================================================================================

Janet orders on a **Tuesday, every 4 weeks**. From the order date **T** (a Tuesday):

| Milestone | Offset | Weekday | Aug 2026 example |
|---|---|---|---|
| Order placed | T | Tuesday | Aug 4 |
| Inventory must be in system | **T + 8** | Wednesday (following week) | Aug 12 |
| Apetito ships | **T + 10** | Friday | Aug 14 |
| Order arrives | **T + 13** | **Monday after the Friday ship** | Aug 17 |
| Next order | **T + 28** | Tuesday | Sep 1 |

Confirmed with Zak 2026-08-14: arrival is the **Monday immediately following the Friday ship date**.

Implementation:
- Derive these from the order date rather than storing four hand-entered dates. A single helper
  (e.g. `po_schedule_from_order_date(string $order_date): array` returning
  `['inventory_due','ship_date','expected_arrival','next_order_date']`) keeps the arithmetic in one place.
- The PO table already has **`expected_arrival`** (schema line 488, with index `idx_expected_arrival`) and
  **`arrival_date`** (line 489). Populate `expected_arrival` from **T + 13** at approve time; `arrival_date`
  remains the ACTUAL arrival recorded at Received. Do not conflate them.
- `approve()` already accepts an optional `$expected_arrival` (line 517) — default it to the derived T + 13
  when the caller passes null, instead of leaving it null.
- Surface the derived ship / inventory-due / next-order dates on the PO screen so Janet can see the
  schedule without computing it.

**If the order date is not a Tuesday** (an off-cycle order), still compute the offsets from the actual
order date and show them — do not silently snap to the nearest Tuesday, and do not block the order. Flag it
visibly as off-cycle so it is obvious the cadence was broken deliberately.

================================================================================
## ITEM 4 — UI
================================================================================

- Add an **Accept** action to the PO list/detail for POs in `placed`, alongside the existing
  Approve / Un-approve / Mark Received controls, wired to a new AJAX handler mirroring the existing ones
  (nonce + permission checks identical).
- Add **Un-accept** for POs in `accepted`, with the same reason prompt pattern as Un-approve.
- **Mark Received must be available only for `accepted` POs** — not for `placed`. If the button remains
  active on an approved-but-unaccepted PO, the operator can skip Accepted and the stock is never committed.
- Show `Accepted` in the status column, and display `accepted_at` / `accepted_by` where
  `received_at` / `received_by` are shown.

================================================================================
## Must NOT change
================================================================================
- **The transition-before-stock-write ordering** in every method that touches inventory. It is the
  concurrency guard.
- `apply_inventory_bump()` itself — one implementation only, no second copy.
- The fail-closed `items` encode guard in `approve()` (~line 548). Its comment explains that an encode
  failure with `items=''` would leave a PO reading as approved while bumping nothing — that hazard now
  applies to `mark_accepted` instead of `mark_received`, so the guard matters more, not less.
- `create_draft`, `edit_draft_cases`, the `MAX_CASES` / `MAX_NOTE_LEN` caps, and `cancel_draft`'s
  terminal-from-any-state behaviour.
- Reconcile semantics.
- **Nothing in this directive touches the order audit.** Items added during the weekly audit must NOT
  affect inventory (Zak, 2026-08-14) — that is a separate directive.

================================================================================
## Verify
================================================================================
1. **Draft → Approve → Accept**: inventory increases by the PO's quantities **at Accept**, not before. 📷
   Confirm READ-ONLY against the inventory table before and after.
2. **Accept → Mark Received**: inventory does **NOT** change again. This is the double-count check and the
   most important assertion in the build. 📷
3. **Mark Received is unavailable on a `placed` PO** — the only route to Received is through Accepted. 📷
4. **Un-accept**: status returns to Approved, reason is required and logged, and inventory returns to its
   pre-accept level. 📷 Verify the exact quantities, not just that it moved.
5. **Double-click Accept**: one bump only. Same for double-clicked Received (no bump at all now).
6. **Reconcile** after Received still applies its adjustments correctly on top of the accepted quantities.
7. **Cadence**: create a PO dated **Tue 2026-08-04** → inventory-due 2026-08-12, ship 2026-08-14,
   expected arrival **2026-08-17**, next order 2026-09-01. 📷
8. **Off-cycle**: a PO dated a Wednesday still derives offsets from its own date and is flagged off-cycle,
   not blocked. 📷
9. Schema tool reports clean after the ENUM change; `counted` is gone and `accepted` is present.
