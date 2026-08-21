# DIRECTIVE — v562 findings: approve-date input guards, blank hint, unguarded deactivation

**Baseline:** v1.0.562.
**Source:** verification + cleanup run 2026-08-20/21 (`Meals DB — v562 Verification + Cleanup`).

**Passed and closed:** Item 1 (select2 stylesheet now loads — two URLs in `document.styleSheets`, rule
present, container at full width, native select clipped, search by name and SKU, single container on
reopen), Item 3a (all-zero approve shows *"Every row is zero cases — nothing to approve."* in place, no
scroll needed), Item 3b (reconcile completes first-click with focus still in the note field; the flushed
note is in the payload), Item 4b/4c (order #28536 reactivated client 714 with `client_reactivated` audit
naming `order:28536`), Item 4 negative cases (cancelled #28537 and backdated #28538 both correctly did NOT
reactivate), and the full PO lifecycle stock arithmetic.

**Item 2's substantive half passed:** an invalid date is now **rejected server-side** with a visible in-view
error and the PO stays `planned` with `expected_arrival = NULL`. The original silent-substitution defect did
not recur. What remains is the input-guard half.

---

# ITEM 1 — `min` / `max` missing on the approve date input

## What happened

The complete attribute list of `#mealsdb-po-expected-arrival` is `type`, `id`, `value` — **no `min`, no
`max`**, and no `placeholder` or `title`.

Typing `09152026` from the start of the field now yields a clean `2026-09-15`. But placing the caret on the
year segment first and typing the same digits still produces **`152026-09-15`**, with
`validity.valid = true` and `rangeOverflow = false` — the control has no bound to refuse it with.

The server catches it (correctly, and visibly). This item is defence in depth, not the primary fix.

## Fix

Add `min` and `max` to the input so a six-digit year cannot be entered:
- `min` = today (or the PO's order date)
- `max` = order date + ~1 year, matching the server-side window the validator already enforces
  (*"within about a year"*)

**Keep the server-side rejection exactly as it is.** It is the authoritative check and it works; the input
attributes only stop the bad value being typed in the first place. Do not replace one with the other.

## Verify
- Read the attribute list: `min` and `max` are present, and their values bracket the computed default. 📷
- Place the caret on the year segment and type `09152026` → the control refuses the six-digit year, or
  `validity.valid` is `false`. 📷
- A valid in-range date (`2026-09-15`) still stores exactly. 📷
- An out-of-range but well-formed date (e.g. `2030-01-01`) → refused by the control **and**, if forced
  through, rejected by the server with the existing message. 📷

---

# ITEM 2 — No proactive hint that blank uses the computed default

## What happened

Blank correctly approves using the computed default (`expected_arrival = 2026-09-03`, the T+13 value shown
in the schedule panel). But nothing in the UI says so before the fact — no placeholder, no title, no text
near the field. The phrase *"or blank for the default"* appears **only inside the validation error**, i.e.
only after the operator has already submitted something invalid.

## Fix

Add a short hint adjacent to the input, naming the actual date blank will use — e.g.
*"Leave blank to use the computed default (2026-09-03)."* The schedule panel already computes and displays
T+13, so the value is available; render it in the hint rather than recomputing it.

A `title` attribute alone is not sufficient — it is invisible until hover and unavailable on touch.

## Verify
- The hint is visible next to the field on a draft PO, and names the same date the schedule panel shows as
  Expected arrival (T+13). 📷
- Approving with a blank field still uses that exact date. 📷

---

# ITEM 3 — Deactivating a client with no recent orders is completely unguarded

## What happened

**A destructive state change on a single click, with no dialog of any kind.**

Client **500 (Bob and Marie Betts)** — active, Private, zero non-cancelled orders since 2026-05-01 — was
deactivated immediately on one click of **Deactivate**. `window.confirm` and `window.alert` were both
instrumented during the test; **neither fired**. The client went `active` 1 → 0 (audit id 4974). It was
restored with the Activate control (audit id 4975).

The **recent-orders** branch works correctly: client 714 produced *"This client has 1 recent order(s), most
recently 2026-08-21. Deactivate anyway?"*, and cancelling left the client active.

## Root cause

`assets/js/admin.js` (~line 28, `.mealsdb-client-toggle-status` handler): the confirmation is driven
entirely by the server returning **`needs_confirm`**, which the v561 ITEM 4a work made it do **only when
the client has recent non-cancelled orders**. With no recent orders there is no `needs_confirm`, so
`doToggle()` posts straight through. There is no baseline confirmation on the deactivate path at all.

## Why this matters more than it looks

An inactive client **silently disappears from Quick Order search** — `search_clients` filters
`active = 1`. So a misclick removes a client from the operator's view with no confirmation, no undo prompt,
and no visible cue that anything happened beyond a button label changing. That is precisely the shape of
the bug that took three sessions to trace back to `active = 0`.

## Fix

Add a **baseline confirmation to the deactivate path**, independent of `needs_confirm`:

- **No recent orders** → a plain confirm naming the client, e.g. *"Deactivate <name>? They will no longer
  appear in Quick Order search."* Mention the search consequence — that is the part the operator won't
  otherwise anticipate.
- **Recent orders** → the existing `needs_confirm` warning, unchanged. **Do not stack two dialogs.** The
  order-count warning supersedes the plain confirm; the operator must see exactly one.
- **Activate** needs no confirmation — it is non-destructive and reversible.

Keep the `confirm=1` retry mechanism and the server-side `needs_confirm` contract as they are; this is a
client-side gate in front of them.

## Must not change
- The `needs_confirm` warning wording or its order count and latest-order date.
- Cancelling a warning leaving the client active (verified).
- `activate_client` / `deactivate_client` audit logging (verified: ids 4974/4975 both recorded).
- The reactivate-on-order path and its guards (verified).

## Not a defect — do not "fix"
After a toggle, the button's `data-active` **attribute** still reads its original value while the label
flips correctly. This is expected: the handler writes `$button.data('active', newStatus)`, and jQuery's data
store does not update the HTML attribute. The handler reads through `.data()`, so it stays consistent.
The tester read the attribute directly, which is why it looked stale.

## Verify
1. Deactivate a client with **no** recent orders → a confirm appears naming the client and the search
   consequence. 📷 Record the wording.
2. **Cancel** it → the client stays active. 📷
3. Accept it → the client deactivates, and the audit log records it. 📷
4. Deactivate a client **with** recent orders → the existing order-count warning appears, and **only that
   one dialog**. 📷
5. **Activate** a client → no confirmation, and it activates. 📷

---

# Corrections to the record (no build work)

**The `client_id` ranges are program-partitioned, and the allocation table is correct.**
IDs 1–499 are entirely SDNB (443) and Veteran (56); IDs 500+ are entirely Private (240). Allocations exist
only for allowance-based programs, so private clients legitimately have no allocation row. The v562 run's
observation that `2xnIt_meals_client_allocations` covers only `client_id` 1–499 — and that active client
504 with four August orders has no row — is **correct behaviour, not a data gap**.

**Consequence:** the reactivate-before-allocate ordering **cannot be exercised on any current inactive
client**, because all eight are Private. Testing it would need an inactive SDNB or Veteran client, and none
exists. The ordering was confirmed by reading (`maybe_reactivate_on_order` runs before `allocate_order` in
`class-allocation-hooks.php`); leave it as verified-by-inspection rather than manufacturing test data.

**Client 713 does not have orders in the 2026-07-27 audit week.** That claim originated in the v561 run,
was carried into the v561 directive and the cleanup runbook unchallenged, and is **wrong** —
`wp_user_id 2739` has zero orders at any status or date. Correct the runbook.

*Note for future checks:* the v562 tester's corroborating method — `LOCATE('Jeffrey', payload)` on
`2xnIt_meals_order_audits` — is **not a valid test**. That payload is AES-encrypted, so a plaintext search
returns 0 for every row regardless. Their primary method (direct order counts) is sound and sufficient.

**The inactive-client cleanup found nothing to do.** All eight inactive clients are genuinely dormant —
Ruth Williamson, Scott Wile and Ida Cameron last ordered in 2024; the rest have no orders or only cancelled
ones. Verified three ways (raw counts at all statuses, a `billing_email` cross-check for unlinked guest
orders, and confirmation that the `wc-` status prefix makes the runbook's filter correct). **Mark
`docs/runbooks/v561-inactive-clients-cleanup.md` as run with a nil result**, and record that the v561
reactivation work stands as forward-looking insurance rather than a fix for an existing backlog.

**The runbook's step 2 is unrunnable as written.** It uses `wp eval`, and staging has no WP-CLI. The
**Activate** control in the client UI calls `wp_ajax_mealsdb_activate_client` → the same
`MealsDB_Clients::activate_client()`, audited identically. Update the runbook to name the GUI route.

---

# Still open elsewhere (unchanged by this build)

- Zoneless clients not allocating — Gallant's 10 mains absent from VAC; cause still a hypothesis.
- PO order date fixed to today (no backdating).
- Staging cleanup: orders #28528–28538 (including the two deliberate negative test cases, cancelled #28537
  and backdated #28538), POs 11–17, duplicate inactive plugin copies. **PO 16 is left `accepted`, so its
  +24 / +12 stock commitment stands until it is received or un-accepted.**
- SDNB datasheet spec version (`36e` vs `37e`) — pinned, deferred.
- HST cutover sign-off with Janet — the actual gate.
