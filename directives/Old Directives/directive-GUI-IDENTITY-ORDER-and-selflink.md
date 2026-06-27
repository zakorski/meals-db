# Directive GUI-IDENTITY-ORDER — Reorder Identity fields, show Client ID, fix self-link warning

**Status:** ready to implement. Small, presentation + one warning-logic refinement.
**Severity:** UX. (1) Reorder the top of the Identity section and surface the read-only Client ID;
(2) the "already linked to client #N" validate warning should say "this client" when the WP user is
linked to the client currently being edited (currently it alarms the operator about a correct
self-link).
**Verified at:** v1.0.426, `includes/class-admin-ui.php`, `includes/ajax/class-ajax-clients.php`,
`assets/js/client-wp-user.js`.

---

## CHANGE 1 — Identity field order + show Client ID

**Desired order at the top of Identity:** Client Type → WordPress User ID → **Client ID** →
First Name → (then the existing order: Last Name, Email, Open Date, …).

Current state: `client_id` exists ONLY as a hidden input (`class-admin-ui.php` ~1754); there is no
visible Client ID field. So this requires ADDING a read-only Client ID display row, plus moving the
WordPress User ID row up to just after Client Type.

Implementation in `$identity_fields` (render order within the group):
1. Client Type (stays first).
2. **WordPress User ID** — move up to second (it currently sits lower, after Open Date). Keep its
   Validate / Pull Data buttons and `required` status intact — just reposition the row.
3. **Client ID (NEW, read-only display):**
   - On **Edit**: render the client's `client_id` as read-only text (e.g. a `<span>` or a disabled/
     readonly input showing `#<client_id>`). Do NOT make it an editable or submitted field — it's
     the auto-increment PK; it must never be user-set. The existing hidden `client_id` input
     (line ~1754) stays as-is for form submission; this new row is display-only.
   - On **Add**: there is no client_id yet (assigned by auto-increment on insert). Either hide this
     row on Add, or show a muted placeholder like "(assigned when saved)". Recommended: show the
     placeholder so the field's position is consistent between Add and Edit.
4. First Name, Last Name, Email, Open Date, Gender, Birth Date — existing order (Gender + Birth Date
   per GUI-FORM-TIDY are now in Identity, all-types; they follow the above).

Guard: the read-only Client ID must not be in the POSTed data as an editable value (no `name="client_id"`
on the new display element — the hidden input already carries it). This prevents any chance of a
user altering the PK.

---

## CHANGE 2 — "Already linked to this client" for a self-link

When validating a WP User ID on the EDIT form, if the WP user is already linked to the SAME client
being edited, the current message "already linked to client #N" is misleading — it's linked to
ITSELF, which is correct and expected. Show "Already linked to this client" instead. For a DIFFERENT
client, keep "already linked to client #N" (the real dual-use warning).

This needs the validate endpoint to know the current client_id (it currently does NOT receive it).

### `assets/js/client-wp-user.js`
- Send the current client_id with the validate request. The form has it (hidden input
  `name="client_id"`). In the validate AJAX `data` (line ~143), add `client_id: <current client_id>`
  (read from the hidden input, e.g. `$('input[name="client_id"]').val()` or a cfg value; 0/empty on
  Add).
- In `setValidated` (line ~75-83): branch the alreadyLinked rendering:
  - if `already_linked` is truthy AND equals the current client_id → show
    `messages.alreadyLinkedSelf || 'already linked to this client'` (no "#N").
  - else if `already_linked` is truthy (different client) → existing
    `'already linked to client #' + alreadyLinked`.
  - else → no warning.
  (You can compute the self-case in JS using the current client_id the form already knows, OR have
  the server return a flag — see endpoint option below. JS-side is simplest since the form has the
  current client_id.)

### `includes/ajax/class-ajax-clients.php` (`validate_wp_user`, ~line 39)
- Accept the optional `client_id` from POST (`$current_client_id = isset($_POST['client_id']) ? absint(wp_unslash($_POST['client_id'])) : 0;`).
- Return an explicit self-link flag so the client doesn't have to infer it:
  ```php
  $existing = MealsDB_Clients_Repository::find_client_id_by_wp_user($uid);
  $is_self  = ($existing && $current_client_id && (int) $existing === $current_client_id);
  wp_send_json_success([
      'wp_user_id'        => $uid,
      'name'              => self::resolve_billing_name($uid, $user),
      'already_linked'    => $existing ? (int) $existing : null,
      'already_linked_self' => $is_self,   // true when linked to the client being edited
  ]);
  ```
- JS then prefers `already_linked_self === true` → "already linked to this client";
  else `already_linked` (different client) → "#N". (Server-side flag is the robust source of truth;
  keep the JS current-client compare as a fallback if you prefer, but the flag is cleaner.)

**Net behavior:**
- Edit client #87, validate its own WP user → "Confirmed: <name> — already linked to this client" (reassuring, not alarming).
- Edit client #87, validate a WP user linked to #90 → "Confirmed: <name> — ⚠ already linked to client #90" (real warning — possible dual use / mistake).
- Add a new client, validate an in-use WP user → "already linked to client #N" (no current client to be "this", so the #N form is correct).

---

## PRE-FLIGHT VERIFICATION
```bash
cd <plugin-root>
grep -n "client_id\|wordpress_user_id\|\$identity_fields" includes/class-admin-ui.php | head
grep -n "validate_wp_user\|already_linked\|find_client_id_by_wp_user" includes/ajax/class-ajax-clients.php
grep -n "already_linked\|setValidated\|client_id\|data:" assets/js/client-wp-user.js
```

---

## TESTS / VERIFICATION (GUI re-test — layout + JS)
- **T-1 order:** Identity shows Client Type, WordPress User ID, Client ID, First Name, … in that
  order on the Edit form.
- **T-2 Client ID read-only:** on Edit, Client ID shows the client's #; it cannot be edited; it is
  not submitted as an editable field (PK unchanged after save).
- **T-3 Add placeholder:** on Add, Client ID shows "(assigned when saved)" (or is hidden) — no error,
  and a new client still saves and receives an auto-increment client_id.
- **T-4 self-link wording:** editing a client and validating ITS OWN WP user shows "already linked to
  this client" (not "#N").
- **T-5 other-link wording:** validating a WP user linked to a DIFFERENT client shows "already linked
  to client #N".
- **T-6 add-form wording:** on Add, validating an in-use WP user shows "already linked to client #N"
  (no current client).
- Full PHP suite green (no consuming logic changed; the endpoint gains an optional param + flag).

---

## ACCEPTANCE CRITERIA
1. Identity order: Client Type → WordPress User ID → Client ID → First Name → (existing order).
2. Client ID is shown read-only on Edit (placeholder/hidden on Add); never user-editable; PK never
   submitted as an editable value.
3. Validate on the Edit form shows "already linked to this client" when the WP user is linked to the
   client being edited; "already linked to client #N" for a different client; "#N" on Add.
4. Existing Validate/Pull Data behavior otherwise unchanged; full suite green.

---

## OUT OF SCOPE
- Making Client ID editable (it's an auto-increment PK — never).
- Any change to how wp_user_id linkage is validated/enforced (separate; this only repositions the
  field and refines the warning text).
