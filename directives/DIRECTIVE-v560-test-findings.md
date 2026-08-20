# DIRECTIVE — v560 test findings: audit picker, audit draft visibility, PO UX, category decode

**Baseline:** v1.0.560.
**Source:** full GUI test run 2026-08-19 (`Meals DB — v559 Remediation + Verification`).

**Closed by the operator, not in scope:**
- Version-stamp fix — **applied and confirmed working** (the stamp now withholds correctly).
- ENUM auto-apply via INSTANT — **not wanted.** The ALTER was run manually; automatic application of
  online-unsupported DDL is deliberately out of scope.
- Reconcile's Stock column showing PO-generation numbers — **correct by design.** Reconcile confirms what
  the vendor shipped against what was ordered and accepted, so it must show the PO's own quantities, not
  live inventory. **Record this in a code comment** so it is not "fixed" by a future reader; it has now been
  queried twice.

---

# ITEM 1 — Add Item picker is unusable (HIGH — the feature is unreachable)

## What happened

The search logic works. The widget does not render.

Measured on the audit row editor:

| Element | Position | Size | Computed style |
|---|---|---|---|
| native `<select class="oa-added-select select2-hidden-accessible">` | x=366, y=742 | **400 × 40** | `position: static; opacity: 1; clip: none` — fully visible |
| `.select2-container` | x=767, y=753 | **103 × 15** | collapsed stub |

Clicking the stub does nothing — 0 search fields, 0 dropdown, 0 results. What the operator can actually
click is the plain native select with 164 options, which only jumps on first letter.

**The search itself is correct and complete.** Forced open programmatically
(`jQuery(sel).select2('open')`), it filters properly: `pot pie` → 1 result (Chicken Pot Pie #12135) by
name; `12135` → the same result by SKU. jQuery, `jQuery.fn.select2` and the selectWoo stylesheet are all
loaded.

## Root cause

The row editor is **injected dynamically and starts hidden**. select2 is initialised against a
zero-width/hidden container, so it computes a collapsed 103×15 width, and the `select2-hidden-accessible`
rule doesn't take effect on the original select (it stays `position: static`, `clip: none`).

## Fix

Initialise the picker **after the editor row is visible**, not when it is built — e.g. on the editor's
open/expand event rather than at injection time. Pass an explicit `width: '100%'` (or `'resolve'`) so the
container can't inherit a zero width.

If an editor can be opened, closed and reopened, **destroy and re-initialise** (or guard with
`.data('select2')`) so a second open doesn't stack widgets or reuse a stale collapsed container.

Keep the existing graceful fallback to a plain `<select>` when selectWoo is unavailable — but ensure that
when selectWoo *is* used, the native select is genuinely hidden.

## Verify
- Open a draft audit row editor → click **Add Item** → the picker renders at full field width, and there is
  no visible native dropdown beside it. 📷
- Type `pot pie` → filters to Chicken Pot Pie. Type `12135` → same product by SKU. 📷
- Close the editor and reopen it → the picker still renders correctly (no stacked or collapsed widget). 📷
- Confirm the added item still saves, persists and shows its SKU (regression on verified behaviour).

---

# ITEM 2 — Added items invisible in the draft audit list

## What happened

An added item shows in the **finalized** audit's SKU column (`… 12175, 12124 ✚ Chicken Pot Pie #12135
(12135)` — v558 ITEM 5, verified working), but in the **draft** list view the same row shows only the
original SKUs and a `Δ`. The added item is visible only inside the row editor.

So between adding an item and finalizing, the operator cannot see what was added without opening each row.

## Fix

Render added items in the draft list SKU column using the **same `✚` treatment already built for the
finalized view**. Reuse that rendering path rather than writing a second one — the finalized view is
verified correct, and two implementations will drift.

## Must not change
- The finalized view's rendering (verified).
- Snapshot `mains_count` / `sides_count` staying unchanged by an added item (verified).
- The `Δ` marker.

## Verify
- Add an item to a draft row, save, and confirm it appears in that row's SKU column **in the list**, marked
  the same way as on a finalized audit. 📷
- Finalize → the display is unchanged from the draft. 📷

---

# ITEM 3 — Un-accept's empty-reason refusal is silent

## What happened

`unaccept()` correctly returns `WP_Error('reason_required', …)` for an empty reason, and correctly does not
move stock or change status. But the UI shows **nothing** — no alert, no inline message. The operator
clicks, is prompted, submits blank, and the page appears to do nothing at all.

## Fix

Surface the returned error. Whatever the PO screen already uses for AJAX errors is the right mechanism —
do not add a new notification pattern. The message already exists server-side: *"A reason is required to
un-accept (it is audited)."*

Re-prompting is acceptable; silence is not.

## Verify
- Click Un-accept, submit an empty reason → a visible message naming the reason requirement. 📷
- Cancelling the prompt outright → no error and no action (cancel is not the same as empty). 📷
- A valid reason still un-accepts and reverses stock (regression on verified behaviour).

---

# ITEM 4 — Approve's expected-arrival prompt does no validation

## What happened

The Approve prompt (*"Expected arrival date (YYYY-MM-DD) — OK approves:"*) accepted a non-date string,
**silently discarded it**, and fell back to the computed default (2026-09-01). The PO approved with a date
the operator did not type and was never told about.

Harmless today, but it means a typo'd date is indistinguishable from an accepted one.

## Fix

Validate the input against `YYYY-MM-DD` before submitting:
- **Empty** → use the computed default, which is the existing intended behaviour. Say so in the prompt.
- **Valid date** → use it.
- **Invalid** → reject with a visible message and re-prompt. Do not silently fall back.

Server-side, keep whatever sanitisation exists — this is a UX fix, not a security one. The server must
still reject garbage; it simply shouldn't be the only thing that does.

## Verify
- Approve with an empty input → approves with the computed default, and the prompt states that's what
  empty does. 📷
- Approve with `not-a-date` → rejected with a message; the PO is **not** approved. 📷
- Approve with `2026-09-15` → that exact date is stored in `expected_arrival`. 📷

---

# ITEM 5 — PO line-item steppers lose rapid edits

## What happened

The +/- steppers on PO draft line items are debounce-saved. Three rapid programmatic clicks persisted only
**one** row. A ~2s gap between clicks saved reliably.

Probably automation-speed, but a human clicking quickly through a 117-row generated draft could plausibly
lose an edit — **and there is no feedback either way**, so a lost edit is invisible until the PO is
reviewed.

## Fix

Two options; either is acceptable, both are better than today:
- **Preferred:** queue edits per line item so a rapid sequence coalesces into one save carrying the *final*
  value, rather than dropping intermediate saves.
- **Minimum:** show a per-row saving/saved indicator, so a lost or pending edit is visible.

**Do not simply lengthen the debounce** — that widens the window for a lost edit on navigation.

## Must not change
- The `MAX_CASES` cap and the existing draft-edit validation.
- `po_draft_edit` audit logging (one entry per persisted change).

## Verify
- Click a stepper 5 times rapidly → the line item's final value equals 5 increments, and the audit log's
  final `po_draft_edit` entry matches. 📷
- Reload the PO → the persisted value matches what is on screen. 📷

---

# ITEM 6 — Category entity decode (the second site)

## What happened

The Quick Order category tab still renders **`Chicken &amp; Turkey`**. Confirmed again on every page load
of this run. Product names elsewhere decode correctly (`Chicken & Vegetable Soup #93051`, `Bangers & Mash
#12138`), so this is specific to the category tab strip.

## Root cause (already established, never built)

v553 decoded `MealsDB_Quick_Order_Products::get_product_categories()`, but **not**
`MealsDB_Products_Loader::get_product_categories()` at `includes/class-products-loader.php` **line 240**
(`'name' => $term->name`). The loader is the one that populates the cache the tabs read from, so the
encoded value survives.

## Fix

At `class-products-loader.php` line 240:
```php
'name' => html_entity_decode((string) $term->name, ENT_QUOTES, 'UTF-8'),
```

Add the same constraint comment used at the v553 site: **category names are consumed as TEXT only** (the
tabs use jQuery `text:`), so decoding is safe — if a category name is ever moved into an `.html()` call or
a concatenated HTML string, it must be escaped at that point.

**The cache flush already exists** in `install-schema.php` from the v553 build, so the fix takes effect on
the next version bump. If the entity persists after deploying, save any product (which triggers
`clear_cache_on_product_save`) and re-check before reporting it as failed.

## Verify
- Category tab reads **`Chicken & Turkey`**. 📷
- Clicking it still loads that category's products (the slug was never encoded). 📷
- List every category tab label and confirm none shows `&amp;`, `&#039;` or `&quot;`. 📷

---

# ITEM 7 — DIAGNOSTIC ONLY: Quick Order client search misses some clients

**Do not write a fix from this section.** The cause is not established and the server-side query looks
correct.

## What happened

Searching Quick Order for `Ruth Will` and for `Williamson` both returned *"No clients found"*, while other
clients resolve normally.

## Why a fix can't be written yet

`search_clients()` (`class-quick-order-ajax.php`) runs:
```sql
WHERE active = 1
  AND (first_name LIKE %term% OR last_name LIKE %term% OR CONCAT(first_name,' ',last_name) LIKE %term%)
ORDER BY last_name ASC, first_name ASC LIMIT 25
```
In the 2026-08-10 production dump, Ruth Williamson is **client 502, wp_user_id 747, Private, active = 1**.
Both search terms should match — `Williamson` on `last_name`, `Ruth Will` on the CONCAT branch. So either
staging's record differs from the dump, or something client-side is dropping the result.

## The probe (three steps, read-only)

1. **Adminer, on staging:**
   ```sql
   SELECT client_id, wp_user_id, first_name, last_name, client_type, active
   FROM 2xnIt_meals_clients WHERE last_name LIKE '%Williamson%';
   ```
   Record exactly what comes back — including `active`, and any leading/trailing whitespace or unusual
   characters in the name.
2. **Network tab:** search `Williamson` in Quick Order, find the `mealsdb_qo_search_clients` request, and
   record **the full JSON response**, not just the on-screen result. This is the branch point:
   - Response contains the client → the server is fine and the **JS is filtering it out**.
   - Response is empty → the server query isn't matching, despite the data looking right.
3. Repeat with a term that works (e.g. `Spurles`) and compare the two responses side by side.

Report both responses verbatim. A fix follows from which branch it lands in.

---

# Not in this build

- Automatic ENUM/online-unsupported DDL application — deliberately out of scope.
- The `mealsdb_db_version` stamp fix — already applied and confirmed.
- Reconcile's Stock column — correct as designed; add the clarifying comment (top of this directive).
