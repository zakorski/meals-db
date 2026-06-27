# Directive GUI-F3F5-v2 — Client create anchored to a validated WP user (Validate + Pull Data)

**Status:** ready to implement. Supersedes the value-clamp portion of the prior F3/F5 work for the
ROOT cause. The prior fix (phone/province validation + the diagnostic logging) was correct and
stays — it fixed a *first* cause; this fixes the *actual* cause it exposed.
**Severity:** MAJOR — creating a new Private client through the GUI fails ("Database error
occurred."; saved only as draft; client not created). Confirmed by the Phase-1R re-test (F-R1) and
a follow-up diagnostic. The same would block creating ANY new client (SDNB/VAC/Private) without a
WP user.
**Verified at:** v1.0.426.

---

## ROOT CAUSE (confirmed in code)

`wp_user_id` is `BIGINT UNSIGNED NOT NULL` with **no default** (schema). Every client is meant to
link to an existing WordPress user (operator-confirmed: Private clients link to WP users exactly
like SDNB/VAC; the migration built every client FROM a WP user's usermeta). But the **create path
silently drops a blank WP-user field**:

- `class-client-form.php:810-811` (create/`save`): if `wordpress_user_id` is `''` →
  **`unset($sanitized['wordpress_user_id'])`** → the column is omitted from the INSERT → MySQL
  rejects the row (NOT NULL, no default) → `$wpdb->insert` returns false → GUI shows the generic
  "Database error occurred."
- `class-client-form.php:966-967` (update): the SAME blank case sets `null` instead of unsetting —
  a divergence (STR-1 "done two ways"), and also wrong for a NOT NULL column.

Editing an existing client works because it already has a `wp_user_id`; CREATING a new one with no
WP user is what fails. A human operator hits this immediately (the WP-User-ID field isn't obviously
required), so it would block Janet's first real Private client.

**The fix is NOT to make the column nullable** (that would allow the orphaned userless records the
operator says shouldn't exist). The fix is to make creation FLOW FROM a validated WP user — which
also delivers a better data-entry UX (Validate + Pull Data) and structurally prevents the bug.

---

## THE FEATURE (operator-specified): text field + Validate + Pull Data

The WP-User-ID stays a **text field**, gains two buttons:

1. **Validate** — confirms the entered ID maps to a real WP user and echoes back that user's
   **billing first + last name** so the operator visually confirms the right person. Read-only.
2. **Pull Data** — fetches the WooCommerce/WP usermeta the migration reads and auto-populates the
   form's identity/contact/address fields, so the operator isn't re-typing data that already exists
   on the WP user. Reuses the migration's proven field mapping.

Creation then requires a **validated** WP-User-ID → the NOT NULL insert can't fail for a missing
user, and no userless client can be created.

---

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>
grep -n "unset(\$sanitized\['wordpress_user_id'\])\|wordpress_user_id'\] = null" includes/class-client-form.php   # 810-811 (create) & 966-967 (update)
grep -n "wp_user_id" includes/class-schema.php   # confirm NOT NULL, no default
grep -n "add_action('wp_ajax_mealsdb" includes/ajax/class-ajax-clients.php   # AJAX pattern to mirror
grep -n "billing_phone\|billing_address_1\|billing_city\|billing_postcode\|billing_state\|shipping_address_1" includes/services/class-migration-consolidated.php   # the Pull Data source map
# STOP if create no longer unsets wordpress_user_id (someone may have changed it).
```

---

## STEP 1 — Two AJAX endpoints (in `class-ajax-clients.php`, mirror its guard stack)

Both use the existing pattern: `check_ajax_referer('mealsdb_nonce','nonce')` + the same capability
the other client-mutation endpoints require + rate limit + `wp_send_json_*`, fail-safe on
`\Throwable`.

### 1a. `mealsdb_validate_wp_user`
Input: `wp_user_id` (int). Logic:
```php
$uid = absint($_POST['wp_user_id'] ?? 0);
if ($uid <= 0) { wp_send_json_error(['message' => 'Enter a positive WordPress User ID.']); }
$u = get_userdata($uid);
if (!$u instanceof WP_User) { wp_send_json_error(['message' => 'No WordPress user with that ID.']); }
// Billing name (operator's choice) with sensible fallbacks.
$first = get_user_meta($uid, 'billing_first_name', true) ?: ($u->first_name ?: '');
$last  = get_user_meta($uid, 'billing_last_name',  true) ?: ($u->last_name  ?: '');
$name  = trim($first . ' ' . $last);
if ($name === '') { $name = $u->display_name; }
// Guard: is this WP user ALREADY linked to a client? (warn, don't hard-block — MAJ-1 allows
// a dual-program person, but surface it so the operator doesn't accidentally double-create.)
$existing = MealsDB_Clients_Repository::find_client_id_by_wp_user($uid); // add if not present
wp_send_json_success([
    'wp_user_id'      => $uid,
    'name'            => $name,
    'already_linked'  => $existing ? (int) $existing : null,
]);
```
Returns the billing name for the inline "✓ <name>" confirmation; flags if the user is already
linked to a client (operator sees "⚠ already linked to client #N" — informational, per MAJ-1).

### 1b. `mealsdb_pull_wp_user_data`
Input: `wp_user_id` (int), validated as above. Reads the user's usermeta and maps it via the SAME
pairs the migration uses (`class-migration-consolidated.php`), returning a field map the JS drops
into the form. **Scope of pulled fields — identity / contact / address / service prefs ONLY; NOT
program-classification** (the operator sets client_type/program on the form; a new Private client
won't have `customer_group`/`service_centre` anyway):

| Form field            | WP usermeta source (migration mapping)             |
|-----------------------|----------------------------------------------------|
| first_name            | `billing_first_name` ?: `first_name` ?: display    |
| last_name             | `billing_last_name`  ?: `last_name`                |
| phone_primary         | `billing_phone`                                    |
| phone_secondary       | `mealsdb_client_phone_2` ?: `billing_phone_2`      |
| client_email          | user email (`$u->user_email`) ?: `billing_email`   |
| address_street_name   | `billing_address_1`                                |
| address_city          | `billing_city`                                     |
| address_province      | `billing_state`  (normalize to 2-letter code)      |
| address_postal        | `billing_postcode` (normalize to A1A1A1)           |
| delivery_address_*    | `shipping_address_1`/`shipping_city`/`shipping_state`/`shipping_postcode` (fallback to billing_* if shipping empty) |
| payment_method        | `payment_method`                                   |
| ordering_frequency    | `ordering_frequency` (int)                         |
| delivery_frequency    | `delivery_frequency` (int)                         |
| client_contribution   | `contribution` (float)                             |
| delivery_fee          | `delivery_fee` (float)                             |

- **Normalize on pull** so pulled values are already form-valid: province → 2-letter code, postal →
  `A1A1A1`, phone → the `(###)-###-####` format the validator expects (or leave raw and let the
  operator see the validation — but normalizing is friendlier). Reuse the form's existing
  normalizers, don't reimplement.
- **Do NOT pull** dietary/comments here unless desired (they're encrypted PII and the migration
  treats them specially; out of scope for v1 Pull Data — operator can enter them). Flag if the
  operator wants them included later.
- Return ONLY non-empty fields (don't blank out a field the user has no data for).

**Refactor note:** the migration's field-extraction logic is currently inline in
`class-migration-consolidated.php`. Factor the usermeta→client-fields mapping into a shared method
(e.g. `MealsDB_WP_User_Mapper::map_usermeta_to_client_fields($uid)`) that BOTH the migration and
this endpoint call, so they can't drift (the STR-1 lesson). If a full refactor is too large now, at
minimum duplicate the exact mapping with a comment pointing to the migration as the source of
truth, and file the shared-mapper extraction as follow-up.

---

## STEP 2 — Form UI (the field + two buttons + feedback)

In the client form template, next to the WordPress-User-ID text field:
- A **Validate** button → calls 1a → on success shows inline "✓ <billing name>" (and "⚠ already
  linked to client #N" if applicable); on failure shows the error inline (use the on-page notice
  pattern from GUI-NOTICES, not an alert).
- A **Pull Data** button → calls 1b → fills the mapped fields. Because it's an explicit press,
  **overwrite** the mapped fields with the pulled values (operator reviews before save). Show an
  on-page notice "Populated N fields from WP user <name> — review and save."
- **Gate Pull Data behind a successful Validate** (can't pull from an unvalidated/nonexistent
  user). Track a "validated" state in the form JS; invalidate it if the ID field is edited after
  validating.

---

## STEP 3 — Require a validated WP user on CREATE (the actual bug fix)

In `save()` (create path):
- **Remove the silent `unset`** at 810-811. Replace with validation: if `wordpress_user_id` is
  blank or not a positive integer → `$record_format_error('wordpress_user_id', 'A WordPress User ID
  is required. Use Validate to confirm it.')` and DO NOT attempt the insert (return the form error,
  same as other field validations).
- Re-confirm the user exists server-side at save time (don't trust the client) — a blank/invalid/
  nonexistent ID is a named field error, never a raw DB failure.
- Reconcile create vs. update: both paths should treat `wordpress_user_id` identically (required +
  validated). Remove the 810-vs-967 divergence.

This is what turns "Database error occurred." into "A WordPress User ID is required" — and makes the
NOT NULL insert structurally safe.

---

## STEP 4 — Log the failure (observability gap the re-test found)

The re-test found the client-create DB failure produces **no Event Log entry** (filtered
error/critical, 72h → nothing). Regardless of the validation fix, a failed core write should be
auditable. In `create_client`'s failure branch (it already `error_log`s), ALSO emit a `degraded`/
error STR-LOG trunk event (`category='client'`, `event='client.create.db_error'`, the
`last_failed_column` in context — no raw PII) so insert failures are visible in the Event Log, not
just the PHP log.

---

## STEP 5 — Orphan draft cleanup (housekeeping from the re-test)

The re-test left orphan Add-Client drafts (#2 ClaudeTest, #3 BadTest, #4 DiagTest) from the failed
creates. Not a code bug, but: confirm the Add-Client **draft store** has a way for the operator to
delete drafts from the UI (the re-test agent noted it didn't delete them as "out of scope/needs
care"). If there's no delete-draft affordance, that's a small UX gap worth a follow-up. Clean up
the existing staging orphans manually.

---

## TESTS

- **T-1 validate endpoint:** valid existing user ID → success + billing name; nonexistent ID →
  error; 0/blank → error; capability/nonce enforced.
- **T-2 already-linked flag:** validating a WP user already tied to a client returns
  `already_linked` = that client id.
- **T-3 pull endpoint maps correctly:** for a user with known billing/shipping meta, the returned
  field map matches the migration's mapping (assert against the shared mapper — guards drift).
- **T-4 pull normalizes:** province→2-letter, postal→A1A1A1, phone→formatted in the returned data.
- **T-5 create requires WP user (THE FIX):** `save()` with blank `wordpress_user_id` → named field
  error, NO insert attempted, NO "Database error occurred." Create WITH a valid WP user → client
  persists (this is F-R1 — must PASS).
- **T-6 create == update handling:** both paths require+validate the WP user identically.
- **T-7 failure logs an event:** a forced insert failure emits a client.create.db_error trunk event.
- **T-8 nonexistent user at save:** a syntactically-valid but nonexistent ID submitted directly
  (bypassing the button) → named error server-side, no DB failure.

Run new tests + FULL suite. Then **re-run Phase-1R F-R1/F-R2** on staging — F-R1 must PASS (a valid
new Private client, with a validated WP user, persists).

---

## ACCEPTANCE CRITERIA

1. WP-User-ID is a text field with Validate (echoes billing name; flags already-linked) and Pull
   Data (populates identity/contact/address/prefs from the WP user via the migration mapping).
2. Pull Data is gated behind a successful Validate; overwrites mapped fields on explicit press;
   pulled values are normalized to form-valid form.
3. CREATE requires a validated WP user; blank/invalid → named field error, never a raw DB failure;
   create and update handle it identically (810-vs-967 divergence removed).
4. A valid new Private client (with a WP user) PERSISTS through the GUI (F-R1 PASS).
5. Client-create DB failures emit an Event Log entry.
6. New tests + full suite green; Phase-1R F-R1 re-run PASS.

---

## NOTES

- The prior F3/F5 work (phone format, province-code validation, length clamps, failed-column
  diagnostic) STAYS — it fixed the first cause and hardens input generally; this fixes the WP-user
  root cause it revealed. Two layers, both real.
- Reuse the migration's usermeta→client mapping via a shared method so Pull Data and the migration
  can't drift (STR-1 discipline). This also means a future improvement to one improves both.
- The validate/pull endpoints read WP user data the operator already has access to (admin
  capability); no new exposure. Keep raw PII out of logs as elsewhere.
- This makes client creation BETTER than pre-bug: validated identity + auto-populated data, fewer
  keystrokes, no userless orphans.
