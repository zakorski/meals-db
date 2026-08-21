# DIRECTIVE — v561 findings: select2 CSS, approve-date validation, silent no-ops, client active-state

**Baseline:** v1.0.561.
**Source:** full GUI verification run 2026-08-20 (`Meals DB — v561 Verification`).

**Passed and closed — do not touch:** Item 2 (added items survive reload, server-rendered), Item 3
(empty-reason re-prompt on both un-accept and un-approve, cancel correctly distinct), Item 5 (serialised
stepper saves, cross-row verified), Item 6 (category decode, no manual flush needed), and the full accepted
lifecycle (no double-count at Received, delta-only reconcile).

---

# ITEM 1 — Add Item picker: the stylesheet never loads (HIGH)

## What happened

Third run in a row where the picker is unusable. Measured:
- native `.oa-added-select`: `{x:366, y:557, w:400, h:40}`, `position: static`, `clip: auto`,
  `visibility: visible` — fully visible, and the only clickable control
- `.select2-container`: `{x:767, y:568, w:103, h:15}` — an unstyled stub that opens nothing when clicked

The tester's decisive datum: the native select **does** carry `select2-hidden-accessible`, but scanning all
59 readable stylesheets found **no rule matching that class**.

## Root cause — the JS fix landed; the CSS was never enqueued

`includes/admin/class-order-audit-page.php` **lines 76–79**:
```php
if (wp_style_is('select2', 'registered')) {
    wp_enqueue_style('select2');
}
```

WooCommerce registers the `select2` **style** handle inside `WC_Admin_Assets::admin_styles()`, which returns
early on screens outside its own allowed list. The Meals DB order-audit page is not one of them, so at the
time this guard runs the handle is **not registered**, the condition is false, and no stylesheet is
enqueued. The guard was written defensively and fails silently — exactly as designed, just against an
assumption that doesn't hold.

The `selectWoo` **script** is registered globally in admin, which is why it loads, initialises, and its
search works correctly (forced open, it filters by name *and* SKU). Without the stylesheet:
- `.select2-container` gets no width rules → renders as an unstyled ~103×15 inline-block, `width: '100%'`
  notwithstanding, because there is no CSS to apply it to
- `.select2-hidden-accessible` has no rule → the native select is never hidden

**The deferred init, the `width` option and the double-init guard are all correct.** The guard is proven —
`document.querySelectorAll('.select2-container').length === 1` after close and reopen. Do not change them.

## Fix

**Register the stylesheet rather than assuming WooCommerce has.** Before enqueuing:

```php
if (!wp_style_is('select2', 'registered') && function_exists('WC')) {
    wp_register_style(
        'select2',
        WC()->plugin_url() . '/assets/css/select2.css',
        [],
        defined('WC_VERSION') ? WC_VERSION : false
    );
}
if (wp_style_is('select2', 'registered')) {
    wp_enqueue_style('select2');
}
```

Keep the defensive guard — a missing handle still must not fatal. If WooCommerce is inactive the picker
falls back to the plain `<select>`, which is the existing intended fallback.

**If the WooCommerce asset path proves unreliable across versions**, ship a minimal stylesheet with the
plugin covering `.select2-container` sizing and the `.select2-hidden-accessible` clip rule, and enqueue
that. Do not leave it depending on another plugin's internal screen list.

## Verify
1. On the audit page, confirm the stylesheet actually loads:
   ```js
   [...document.styleSheets].filter(s => (s.href||'').includes('select2')).map(s => s.href)
   ```
   Expect at least one URL. 📷
2. Open a row editor → **Add Item** → measure both elements again. **Expected: `.select2-container` at
   roughly full field width (~400px), native select hidden** (`position: absolute` / clipped). This is the
   exact reverse of the last three runs. 📷
3. Click the picker **as a user** → a search box opens. Type `pot pie` → filters to Chicken Pot Pie #12135.
   Type `12135` → same product by SKU. 📷
4. Close and reopen the editor → still full width, and still exactly **one** `.select2-container`. 📷
5. Regression: the added item still saves, survives a hard reload in the draft SKU column, and shows in the
   finalized view (all verified working — must not regress).

---

# ITEM 2 — Approve date silently falls back to the computed default

## What happened

The v561 fix validates the format but its **failure branch approves anyway with the computed default**,
which is the original defect.

The tester found a repro the plan hadn't anticipated. There is **no `prompt()` on this build** — Approve
uses an inline `<input type="date" id="mealsdb-po-expected-arrival">`, with only a `confirm()` for the
approval itself. Typing `not-a-date` is discarded by the date control, so that path is unreachable. But
typing a full date the way a user actually does — `09152026` in one go — **overflows the year segment**:

- resulting `input.value` = `152026-09-09` (six-digit year)
- `validity.valid = true`, `badInput = false`, `rangeOverflow = false` — the browser reports it as valid
- clicking Approve produced **no message**, approved the PO, and wrote `expected_arrival = 2026-09-02`
  (the computed default)

Control case confirms the field is otherwise honoured: `2026-09-15` stored exactly `2026-09-15`.

## Fix

**Three parts. The server-side one is the important one.**

**2a — Reject server-side.** The handler must validate `expected_arrival` against `YYYY-MM-DD` **and a
sane year range**, and return a `WP_Error` on failure rather than falling through to the default. Silently
substituting a different date than the operator supplied is the defect. Blank remains valid and means "use
the computed default" — that behaviour is correct and stays.

**The server path has never been exercised with a malformed value** (it needs a crafted request, which the
read-only run couldn't do). Assume it is currently permissive.

**2b — Constrain the input.** Add `min` and `max` to the date input (e.g. `min` = today, `max` = today +
1 year) so the year segment cannot overflow to six digits in the first place. This is prevention, not a
substitute for 2a.

**2c — Say what blank does.** There is currently no hint, placeholder or title anywhere near the field, so
"blank uses the computed default" is undiscoverable. Add a short hint next to the input naming the default
date it will use.

## Verify
1. Type `09152026` in one go → either the control refuses the overflow (2b), or Approve is **rejected with
   a visible message** (2a). Either way the PO must **not** be approved with a substituted date. 📷
2. Blank → approves with the computed default, and the UI states that is what blank does. 📷
3. `2026-09-15` → stored exactly. 📷
4. A malformed value submitted **directly to the handler** (bypassing the date control) → rejected with an
   error, PO not approved. This is the assertion that closes the real gap.

---

# ITEM 3 — Silent no-ops in `mealsdb-po-action` (one bug, two symptoms)

## What happened

Two separate failures with the identical shape — the `confirm()` fires, the operator accepts, and then
**nothing happens at all**: no notice, no error, no state change.

**(a) A PO with all-zero cases cannot be approved.** On po_id 14 with every row at 0, Approve fired the
confirm, was accepted, and the status stayed `planned`. Adding a single case made the identical click work.

**(b) First "Complete reconciliation" click was a no-op.** On po_id 13, after reducing a row from 2 → 1 and
typing the required note, Complete reconciliation fired its confirm, was accepted, and `status` stayed
`arrived`, `reconciled_at` NULL, stock unchanged. A second identical click completed it normally.

Likely mechanism for (b), worth checking first: the note input had been typed into but **not blurred**, so
the note may not have been committed and a "note required" rejection was swallowed.

## Root cause

Both are failure branches of the same `mealsdb-po-action` handler that return without surfacing anything.
The v561 Item 3 fix addressed only the **reason-prompt** branch (which now correctly re-prompts); the other
branches still fail silently.

## Fix

Surface every failure path in that handler, using whatever notice mechanism the PO screen already uses —
**do not add a new pattern**. Specifically:

- **Empty-cases approve:** either allow it, or reject with a message naming the reason ("A purchase order
  must contain at least one case."). Zak's call which — but silence is not an option.
- **Reconcile:** commit pending input before submitting (read the note field's current value rather than
  relying on a blur/change event), and surface any rejection.
- **Generic:** any `WP_Error` returned by a PO action must produce a visible message. Audit the handler for
  branches that `return` without one.

This is the same class as ITEM 2 — an operation appearing to succeed when it did not. Given this system
writes inventory, that is the most dangerous UI failure mode available.

## Verify
1. Approve a PO with all rows at zero → a clear message, or it approves. Not silence. 📷
2. Reconcile: type a note **without clicking away**, then click Complete reconciliation → it completes
   first time (or explains why not). 📷
3. Force any other PO action failure available → a visible message. 📷

---

# ITEM 4 — Client active-state: orders should keep a client active

## Background

`search_clients` filters `active = 1`, so an inactive client silently vanishes from Quick Order — the
operator concludes they don't exist. Ruth Williamson (client 502) is `active = 0`. Proved with a controlled
discriminator: two clients named Simone Jeffrey, 496 (`active 1`) and 713 (`active 0`); searching `Jeffrey`
returned 496 and omitted 713.

**Client 713 has orders in the 2026-07-27 audit week** — so "inactive" does not reliably mean "no longer
ordering". Zak's decision (2026-08-20): **an order should render a client active again.**

Note the Aug-10 production dump contains **zero** inactive clients, while staging on Aug 20 had **8**. Those
deactivations post-date the dump. Worth establishing whether they happened on live or staging before
building, since it affects whether the guard in 4a would have prevented them.

## 4a — Guard at deactivation

**Build this first — it is the branch that would have prevented the observed problem.**

Auto-reactivation cannot help a client who was deactivated *after* their orders were placed; it only helps
if the orders arrive afterwards. Simone Jeffrey's shape suggests deactivation came second.

When a client is set inactive and they have **non-cancelled orders in the current or previous billing
month**, warn the operator with the count and the most recent order date, and require confirmation. Block
outright only if Zak prefers — the warning is the minimum.

## 4b — Reactivate on a qualifying order

Hook order creation. Reactivate the client when **all** of these hold:

- the order is **non-cancelled** (a cancelled or refunded order must not resurrect anyone)
- the order's date falls in the **current or previous billing month** — a historical import or a backdated
  correction must not reactivate a client dormant for years
- the client row exists and is currently `active = 0`

## 4c — Audit it

Reactivation is a billing-relevant state change: an SDNB client becoming active means allocations, invoice
lines and slips resume. Log it to `meals_audit_log` as `client_reactivated`, carrying the **triggering order
id**, so it is never a mystery why someone reappeared on an invoice.

## Allocation note — already safe, confirm it stays that way

The allocation engine **upserts** the allocation row (`class-allocation-engine.php` ~line 518,
`INSERT ... ON DUPLICATE KEY UPDATE`), so a reactivated client gets an allocation row created on demand
rather than ending up with orders and no allocation. That removes the silent-zero-billing risk.

**But `recalculate_all()` takes an `$active_only` flag** (line 347, `' AND active = 1'`). Confirm which call
path runs after a reactivation and that the client's existing orders in that billing month are picked up —
otherwise a reactivated client could be active, visible, and still billing zero.

## 4d — Data cleanup (operator, not code)

Wherever the cleanup runs:
```sql
SELECT c.client_id, c.first_name, c.last_name, c.client_type, c.wp_user_id,
       COUNT(o.id) AS orders_since_jun, MAX(o.date_created_gmt) AS last_order
FROM 2xnIt_meals_clients c
LEFT JOIN 2xnIt_wc_orders o
       ON o.customer_id = c.wp_user_id
      AND o.status <> 'wc-cancelled'
      AND o.date_created_gmt >= '2026-06-01'
WHERE c.active = 0
GROUP BY c.client_id, c.first_name, c.last_name, c.client_type, c.wp_user_id
ORDER BY orders_since_jun DESC;
```
Any row with `orders_since_jun > 0` is ordering while flagged inactive → reactivate. Zero → genuinely
inactive, leave alone.

## Verify
1. Deactivate a client with a recent order → warning naming the order count and latest date; requires
   confirmation. 📷
2. Deactivate a client with no recent orders → no warning. 📷
3. Create an order for an inactive client (via a non-Quick-Order path, since QO can't select them) → client
   becomes `active = 1`, and `client_reactivated` appears in the audit log with the order id. 📷
4. A **cancelled** order for an inactive client → **no** reactivation. 📷
5. A backdated order outside the current/previous billing month → **no** reactivation. 📷
6. After reactivation, the client is findable in Quick Order search, and their allocation row for that
   billing month exists with the right `used_mains`. 📷

---

# Not in this build

- SDNB datasheet spec version (`36e` vs Janet's `37e`) — **pinned by Zak, deferred.**
- Zoneless clients not allocating (Gallant's 10 mains) — still open, cause still a hypothesis.
- PO order date fixed to today (no backdating) — only matters if Janet needs it.
- Staging cleanup: orders #28528–28535, POs 11–14, duplicate inactive plugin copies.
