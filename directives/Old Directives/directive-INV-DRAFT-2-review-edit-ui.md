# Directive INV-DRAFT-2 — Invoice Draft Review/Edit UI + per-field audit

**Status:** ready to implement
**Series:** INV-DRAFT (directive 2 of 3)
  1. INV-DRAFT-1 — schema + draft service ✅ **SHIPPED & VERIFIED** (PR #390; 46/46 new tests, full suite 64/64)
  2. **INV-DRAFT-2 — review/edit admin UI + per-field audit** ← *this directive*
  3. INV-DRAFT-3 — finalize serialization per pipeline (VAC first, then SDNB legacy, then SDNB new portal)

**Depends on:** INV-DRAFT-1 (the `MealsDB_Invoice_Draft` service — `create` / `get` / `list` /
`edit_field` / `finalize`), and the existing admin/AJAX security stack in
`class-ajax-invoice.php`. Reuses STR-LOG audit-log boundary, QW-3 CSV/XSS hygiene patterns.

**Goal of THIS directive:** the screen Janet actually uses. Generate a draft from any pipeline,
see every client's billing row in an editable grid, edit any field with each edit written to the
audit log (old→new, who, when), navigate the list of all drafts for a period, and trigger
finalize. NO serialization changes (that's INV-DRAFT-3) — finalize still returns the placeholder
row map from INV-DRAFT-1, so this directive must NOT present finalize output as a downloadable
invoice yet (see "The finalize-output caveat" below).

---

## PRE-FLIGHT VERIFICATION (run before writing code; STOP if any check fails)

```bash
cd <plugin-root>

# 1. INV-DRAFT-1 service is present with the expected public surface.
grep -n "function create\|function get\|function list\|function edit_field\|function finalize" includes/services/class-invoice-draft.php
#   STOP if any are missing — INV-DRAFT-1 not fully present.

# 2. edit_field returns the OLD value (this directive audits old→new off that return).
grep -n "return \$old_value" includes/services/class-invoice-draft.php
#   STOP if edit_field doesn't return the prior value — the audit story depends on it.

# 3. The draft-row builders exist (the "Generate draft" button calls these).
grep -n "function build_vac_draft_rows\|function build_sdnb_legacy_draft_rows\|function build_sdnb_new_portal_draft_rows" includes/services/class-invoice-generator.php

# 4. Existing invoice AJAX pattern to mirror (capability + nonce + rate-limit).
grep -n "check_ajax_referer\|current_user_can\|check_rate_limit\|wp_send_json" includes/ajax/class-ajax-invoice.php | head -20
#   Read this file before writing the new endpoints — match its exact guard pattern.

# 5. The audit logger signature (per-field edit logging rides this).
grep -n "public static function log(" includes/class-logger.php
#   Expect: log($action, $target_id, $field, $old, $new, $source = ...).

# 6. The capability the invoice endpoints currently require — match or exceed it.
grep -n "current_user_can" includes/ajax/class-ajax-invoice.php
#   VAC/SDNB billing is sensitive. If the existing endpoints use 'manage_options',
#   use the same; do NOT loosen it. If they use a plugin-specific cap, match that.
```

---

## STEP 1 — The admin page: `MealsDB_Invoice_Draft_Page`

New file `includes/admin/class-invoice-draft-page.php` (autoloads from `includes/admin`).
Registered like the Event Log page (STR-LOG) — a submenu under the plugin, behind the same
capability the invoice AJAX endpoints require (Step 6 of pre-flight; almost certainly
`manage_options` — VAC/SDNB billing names clients and government IDs, keep the audience tight).

It has **two views**, switched by query param, mirroring the Event Log page's tab pattern:

### 1a. Draft list view (default)
Calls `MealsDB_Invoice_Draft::list($filters)` (meta only — no PII, by design). Renders a table:
pipeline, billing period, status, row_count, edit_count, created_by/at, finalized_by/at, and a
"Review" link per row (to view 1b) plus, for `status='draft'` rows, a "Finalize" action.

Filters: pipeline, billing_month, status. Server-renders; output escaped at emission (match the
Event Log page's XSS-clean discipline — escape every cell with `esc_html`).

Above the list: a **"Generate draft"** form — pipeline selector + period (start/end) + the
pipeline-specific param (SDNB legacy needs `zone`). Submitting it POSTs to the generate endpoint
(Step 2a). Per operator decision #4, generating ALWAYS creates a NEW draft — never mutates an
existing one — so this form is safe to use repeatedly; the list shows the full history.

### 1b. Draft review/edit view (`?draft_id=N`)
Calls `MealsDB_Invoice_Draft::get($draft_id)` and renders the `payload['current']` rows in an
**editable grid** — one row per client, columns = the editable fields.

**Editable fields (operator decision #1: she can edit everything).** Render every field present
in the row as an editable cell. Two presentation refinements, not restrictions:
- Show the `generated` baseline value alongside any field whose `current` value differs from it
  (a small "was: X" hint), so Janet can see at a glance what's been changed from the system
  output. This is read from `payload['generated']` — already in the decoded draft, no extra query.
- Group/label columns sensibly per pipeline (VAC vs SDNB have different row shapes). A simple
  approach that avoids per-pipeline UI forks: iterate the row's keys, render each as
  `label = humanized(key)`. Don't hardcode a column list — the row is an open assoc array
  (INV-DRAFT-1 Step 3) and INV-DRAFT-3 will add VAC fold fields; a key-driven grid picks those
  up for free.

Each editable cell carries `data-client-id` and `data-field` so the save JS knows what it's
editing. Finalized drafts render the same grid **read-only** (no inputs) — `status !== 'draft'`
disables editing, matching `edit_field`'s server-side refusal (defense in depth).

---

## STEP 2 — AJAX endpoints (new file `includes/ajax/class-ajax-invoice-draft.php`)

Three endpoints, each carrying the FULL existing guard stack (match `class-ajax-invoice.php`
exactly): capability check → nonce (`check_ajax_referer`) → rate limit (`check_rate_limit`) →
server-side validation → act → `wp_send_json_success/error`. Fail-safe: catch `\Throwable`,
return a JSON error, never 500.

### 2a. `generate_draft`
Input: pipeline, period_start, period_end, pipeline params (zone for SDNB legacy). Validate:
pipeline ∈ the three constants; dates are well-formed `Y-m-d`; zone (if SDNB legacy) is a known
zone. Then:
```
$rows = MealsDB_Invoice_Generator::build_<pipeline>_draft_rows(...);
$draft_id = MealsDB_Invoice_Draft::create($pipeline, $billing_month, $start, $end, $rows, $params);
```
Return the new `draft_id` (the UI redirects to the review view). `create()` already audits
"invoice_draft_created" — do NOT double-log here.

**Empty-draft handling:** if `$rows` is empty (no eligible clients / no allocations that month),
`create()` still makes a valid zero-row draft. That's fine — return it; the review view shows
"no rows." Do not treat empty as an error (an operator generating early in a month legitimately
sees nothing yet).

### 2b. `edit_draft_field` — **the audit-critical endpoint**
Input: draft_id, client_id, field, new_value. This is where per-field auditing happens
(operator decision #3). Sequence, in this exact order:

1. **Capability + nonce + rate limit.** (Rate-limit bucket: a dedicated one, or reuse a
   settings-mutate bucket — NOT the quick-order-read bucket; this is a write.)
2. **Server-side value validation — do NOT skip this.** The value is going onto a government
   invoice. Validate by field semantics:
   - Money/amount fields (rates, costs, contribution, fold values): numeric, non-negative,
     within a sane ceiling (reject e.g. a fat-fingered 999999). Normalize to the stored
     representation (the rows mix dollars-as-float and cents-as-int — match the field's existing
     type; check the row's generated value's type as the guide).
   - Count fields (mains/sides counts): non-negative integers.
   - Free-text identity fields (names, addresses): `sanitize_text_field`, length-capped. These
     still flow to CSV/PDF later, but the serializer routes through `MealsDB_CSV::cell()`
     (QW-3) — do NOT re-implement injection guarding here; just sanitize for storage.
   - Reject an unknown `field` that isn't present in the row (don't let the UI invent columns).
3. **Apply via the service:** `$old = MealsDB_Invoice_Draft::edit_field($draft_id, $client_id, $field, $validated);`
   - If `edit_field` returns `false` → it refused (finalized draft, missing row, or DB error).
     Return a JSON error; write NO audit row (nothing changed).
4. **Audit the change** (only on success, only if the value actually changed):
   ```php
   if ($old !== $validated) {
       MealsDB_Logger::log(
           'invoice_draft_edit',
           $draft_id,                              // target_id = the draft
           $client_id . ':' . $field,              // field = which client's which field
           is_scalar($old) ? (string) $old : wp_json_encode($old),
           is_scalar($validated) ? (string) $validated : wp_json_encode($validated)
       );
   }
   ```
   This is the STR-LOG boundary in action: a committed change to billing data → the **audit
   log** (`meals_audit_log`), not the operational trunk. Per-field grain (decision #3): one
   audit row per field edit, with old→new, the editing user (the logger captures
   `get_current_user_id`), and the timestamp.
5. Return success with the stored value (so the grid can confirm/repaint the cell, and update
   the "was:" hint).

**PII-in-audit consideration (flag, decide before shipping):** the audit log fingerprints
known sensitive *client* fields, but here the OLD/NEW values of an edited field could themselves
be PII (e.g. editing a name or address). `MealsDB_Logger::log` already runs its sanitizer over
values — confirm it scrubs/fingerprints these the same way it does elsewhere. If a name edit
would land in the audit log as cleartext, that's a (small) leak surface; the safe default is to
let the logger's existing sanitization handle it, but **verify** rather than assume — add a test
(T-5 below). Do not invent a parallel scrub here; rely on and confirm the logger's.

### 2c. `finalize_draft`
Input: draft_id. Guards as above. Calls `MealsDB_Invoice_Draft::finalize($draft_id)`.
- On `null` → error (already finalized, lost race, or failure — the service distinguishes; the
  UI just needs "couldn't finalize, reload").
- On success → see the caveat below. For THIS directive, return success + a flag that the draft
  is finalized; the UI refreshes into the read-only view. Do **not** stream the return value as
  a file download yet.

---

## STEP 3 — The edit JS (inline, in the admin page; no build step)

Match the plugin's existing admin-JS conventions (the Event Log / reports pages use plain
inline JS, no framework). On blur/change of an editable cell:
- POST to `edit_draft_field` with draft_id, client_id (`data-client-id`), field
  (`data-field`), new_value, and the nonce.
- On success: repaint the cell, update/show the "was:" hint, increment an on-screen edit counter.
- On error: revert the cell to its prior value and surface the server's error message (e.g.
  "value must be a non-negative number", "draft already finalized — reload").
- Debounce/disable double-submits per cell.

**No localStorage/sessionStorage** (per the artifact storage rule and because drafts persist
server-side anyway — the whole point of INV-DRAFT-1's table). All state is server-side; the
grid is a view over it.

---

## STEP 4 — Wire it up

- Register the page in the admin menu init (alongside where the Event Log / invoice pages
  register).
- Register the three AJAX actions (`wp_ajax_mealsdb_*`) in the AJAX init, following the existing
  registration pattern in `class-ajax-invoice.php`.
- Add nonces to the page render (one nonce action for the draft endpoints is fine; each endpoint
  still verifies it).

---

## The finalize-output caveat (carried from INV-DRAFT-1)

INV-DRAFT-1's `finalize()` returns the raw `current` row map as a **placeholder** — per-pipeline
CSV/PDF serialization lands in INV-DRAFT-3. Therefore in THIS directive:
- Finalize must perform the freeze + status transition + audit (it does, via the service) and
  the UI reflects "finalized."
- The UI must **NOT** offer a "download invoice" button wired to finalize's return value yet, or
  if it shows anything, it must be clearly labeled provisional/preview — because the real CSV/PDF
  doesn't exist until INV-DRAFT-3. Simplest: in this directive, finalize just locks the draft and
  shows the read-only grid; the "download finalized CSV/PDF" affordance is added in INV-DRAFT-3
  when there's real output to download.

State this in a code comment on the finalize button handler so it isn't mistaken for an
oversight.

---

## TESTS (`tests/test-ajax-invoice-draft.php`, in-memory $wpdb + stubbed WP ajax helpers)

Follow the existing AJAX-test style (the codebase has AJAX tests with stubbed
`wp_send_json_*`/`check_ajax_referer`). Cover:

- **T-1 generate_draft happy path:** valid input → calls the right `build_*_draft_rows` → creates
  a draft → returns a draft_id. (Stub the generator builder to return a fixed row map.)
- **T-2 generate_draft validation:** unknown pipeline / malformed date / (SDNB legacy) bad zone →
  JSON error, no draft created.
- **T-3 edit_draft_field happy path + audit:** valid edit → `edit_field` applied → exactly ONE
  audit row written with `action='invoice_draft_edit'`, `target_id=draft_id`,
  `field='<cid>:<field>'`, correct old→new.
- **T-4 edit_draft_field no-op:** new value === old value → edit applied but **no** audit row
  (we only log actual changes).
- **T-5 edit_draft_field PII path:** editing a name field → confirm the audit value is run
  through the logger's sanitizer (assert the stored audit old/new are fingerprinted/scrubbed if
  the logger scrubs that field elsewhere; this PINS the PII-in-audit decision rather than leaving
  it implicit).
- **T-6 edit validation rejects bad money:** negative / non-numeric / over-ceiling amount →
  JSON error, `edit_field` NOT called, no audit row.
- **T-7 edit refused on finalized draft:** `edit_field` returns false → JSON error, no audit row.
- **T-8 finalize_draft:** success path returns finalized flag; calling again → error (matches
  INV-DRAFT-1's refusal).
- **T-9 capability/nonce:** missing capability or bad nonce on each endpoint → rejected before
  any service call (no draft created / no edit applied / no audit row). This is the security
  spine — test it explicitly.

Run the new test + the FULL suite (expect the prior 64 + this one, all green; mbstring/gd present
for the PDF tests — CI note).

**Acceptance:**
1. Admin page renders the draft list and an editable review grid behind the correct capability.
2. The three AJAX endpoints exist with capability + nonce + rate-limit + server-side validation,
   fail-safe.
3. Every successful, value-changing field edit writes exactly one per-field audit row (old→new,
   user, time) to `meals_audit_log` — and no audit row on no-op, refusal, or validation failure.
4. Generating always creates a new draft; the list shows all drafts; finalized drafts are
   read-only.
5. Finalize locks + audits but does NOT present a download yet (deferred to INV-DRAFT-3), clearly
   commented.
6. New test green; full suite green.

---

## OUT OF SCOPE (deferred)

- **Per-pipeline finalize serialization** (the actual CSV/PDF from a finalized draft), and the
  download affordance → INV-DRAFT-3.
- **VAC-specific editable fold/HST fields** (the hand-work columns) — INV-DRAFT-3 adds them to
  the row shape; this directive's key-driven grid will render them automatically once present,
  but the directive that DEFINES them is INV-DRAFT-3.
- **The Definitions page** (editable rates) — separate work-stream. The draft grid edits a
  draft's stored values; it does not edit the rate catalog.
- **Bulk edit / apply-to-all** affordances — out of scope; per-cell editing only for v1. (Can be
  a v1.1 nicety once the audited single-edit path is proven.)

---

## NOTES FOR THE IMPLEMENTER

- The key-driven grid is the move that keeps this from forking per pipeline. Resist hardcoding
  VAC vs SDNB column lists; iterate the row's keys. INV-DRAFT-3's VAC fold fields then appear
  with zero UI changes.
- The audit-on-change-only rule (T-4) matters: Janet may tab through cells without changing them;
  logging no-ops would bury the real edits in noise and defeat the "what did she actually change"
  purpose of the audit trail.
- Defense in depth on the read-only finalized grid: the UI hides inputs, AND `edit_field` refuses
  server-side (INV-DRAFT-1, verified by its T-5). Keep both — never rely on the UI alone to
  enforce immutability of a finalized government invoice.
