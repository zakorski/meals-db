# Directive GUI-F3F5 — New-client create fails ("Database error occurred") — diagnose-then-fix

**Status:** ready to implement. STEP 1 is a diagnostic (capture the failing value), STEP 2 branches
the fix on what STEP 1 shows. Do STEP 1 first — do NOT pre-commit to a fix.
**Severity:** MAJOR — creating a NEW client through the GUI fails at the DB insert; the client is
not persisted (saved only as a draft). Surfaced by the Phase-1 GUI test (cases F3, F5). Editing
existing clients works; this is specific to the create path.
**Verified at:** v1.0.422. Real `$wpdb` error from the staging debug.log:
`Processing the value for the following field failed: client_phone_1. The supplied value may be too
long or contains invalid data.` — 126 occurrences on `client_phone_1`, 2 on `province`.

---

## WHAT'S ESTABLISHED (from code + the debug log)

- The failing columns are `client_phone_1` (`VARCHAR(20) NULL`) and `province` (`VARCHAR(10) NULL`).
  **Neither is in `ENCRYPTED_CLIENT_COLUMNS`** (that list is only `individual_id`, `requisition_id`,
  `vet_health_card`, `diet_concerns`, `customer_comments` — and those columns are sized generously
  for ciphertext: VARCHAR(500)/(50)/TEXT). So this is NOT an encrypted-blob-overflowing-its-own-
  column case.
- `create_client` (`class-clients-repository.php:145`) calls `MealsDB_Encryption::encrypt_columns($data)`
  then `$wpdb->insert`. On failure it logs `$wpdb->last_error` (which gave us the column) and returns
  false → the GUI shows generic "Database error occurred."
- The form's length-clamp map (`class-client-form.php:460-469`) covers first/last name, email, diet,
  comments, delivery address/city/postal, individual/requisition id — **but NOT phone fields and NOT
  province.** Unmapped fields get no length check before insert.
- The phone *format* validator requires `(###)-###-####` (14 chars, fits VARCHAR(20)) but is
  `!empty($x) && !preg_match(...)` — it only catches a non-empty value that fails the pattern.

**Two live hypotheses, distinguished only by the actual value that failed (not in the log):**
- **(A) Plaintext too long / wrong shape.** Phone entered longer than 20 chars (extension, two
  numbers, stray formatting) and/or province entered as a full name ("New Brunswick" = 13 > 10)
  instead of a 2-letter code — slipping past because neither is clamped and the create path's
  validation differs from edit. *Most likely given the evidence.*
- **(B) Misrouted value (field-mapping bug on the create path).** A value intended for another
  column (potentially an encrypted blob, ~500 chars) lands in `client_phone_1` due to a create-path
  `$data` assembly error. The "value too long" error is exactly what a 500-char ciphertext in a
  VARCHAR(20) produces. Less likely (phone isn't encrypted) but must be ruled OUT, not assumed.

The fix diverges hard between A and B, and "widen the column" would HIDE B rather than fix it. So:
diagnose first.

---

## STEP 1 — DIAGNOSTIC: capture the failing value (do this first)

The current error log records the column but not the value. Add the value's length + a redacted
preview to the existing failure log in `create_client`, so reproduction names the bug.

In `class-clients-repository.php`, in the `$result === false` branch (~line 170-173), before the
return, add a per-field diagnostic that does NOT log raw PII in the clear:

```php
if ($result === false) {
    $last = $wpdb->last_error ?: 'unknown error';
    error_log('[MealsDB Clients Repository] Failed to execute client insert: ' . $last);

    // STR-LOG diagnostic (GUI-F3F5): name the offending value's SHAPE without
    // logging raw PII. Length + a masked preview is enough to tell
    // "plaintext too long" from "misrouted ciphertext".
    foreach ($data as $col => $val) {
        if (!is_string($val)) { continue; }
        $len = strlen($val);
        // base64 ciphertext from encrypt_columns is long + base64-charset;
        // flag anything suspiciously long for a contact/address field.
        $looks_b64 = (bool) preg_match('/^[A-Za-z0-9+\/=]{40,}$/', $val);
        $preview = $len <= 4 ? str_repeat('*', $len)
                             : substr($val, 0, 2) . str_repeat('*', max(0, $len - 4)) . substr($val, -2);
        error_log(sprintf(
            '[MealsDB Clients Repository] insert field shape: %s len=%d b64ish=%s preview=%s',
            $col, $len, $looks_b64 ? 'yes' : 'no', $preview
        ));
    }
    return false;
}
```

Then **reproduce F5 once** on staging (Add New Client, Private, valid postal `E1C 1A1`, validate
initials, submit) and read the log. Read the result:

- **`client_phone_1 len=14 b64ish=no preview=(5****...`** → a normal formatted phone that somehow
  exceeds/violates the column → likely an encoding/charset issue OR the value carries hidden chars.
  Branch toward A, sub-case "encoding".
- **`client_phone_1 len>20 b64ish=no`** (e.g. len=22, an extension) → plaintext too long. **Branch A.**
- **`province len=13 b64ish=no preview=Ne*****ck`** → "New Brunswick" full name in a 10-char code
  column. **Branch A** (province): map/validate to the 2-letter code.
- **`client_phone_1 len~500 b64ish=yes`** → a ciphertext landed in the phone column. **Branch B** —
  a create-path field-mapping bug; do NOT widen the column.

Keep this diagnostic logging in only until the fix is confirmed, then remove it (or downgrade to a
one-line length note) so it doesn't run on every failed insert forever.

---

## STEP 2A — FIX (if Branch A: plaintext too long / wrong shape)

The create path lets values exceed their column widths because phone + province aren't validated/
clamped. Fix at the form layer (where the other length checks live), NOT by widening columns.

1. **Add the missing fields to the length-clamp/validation map** (`class-client-form.php:460`), using
   the FORM-side field names and the real column limits:
   - `phone_primary` → 20, `phone_secondary` → 20, `alt_contact_phone_primary` → 20,
     `alt_contact_phone_secondary` → 20
   - `address_province` → 10, `delivery_address_province` → 10
   (Confirm the exact form-side keys against the `$sanitized` map; the clamp loop keys on those.)
2. **Province should be a 2-letter code, not a free-text name.** If the Add-New form offers a
   free-text province, constrain it (a NB/province-code dropdown or a validation to a 2-letter
   uppercase code). A 10-char column comfortably holds "NB"; the failure means a longer string is
   arriving. Validate to the code form with a clear field-level message.
3. **Phone: make the format validation actually gate the create path.** Confirm the
   `(###)-###-####` validator runs on the Add-New (Private) submit, not only on edit. If the create
   path bypasses it (the agent noted a separate initials gate on Add-New), wire the same phone
   format + length check in. A correctly formatted phone is 14 chars and fits; the goal is to reject
   the over-long input with a NAMED field error instead of a generic DB failure.

## STEP 2B — FIX (if Branch B: misrouted value)

A value is being assembled into the wrong column on the create path. Compare how `create_client`'s
caller builds `$data` vs. how the working `update_client` path builds it — the divergence is the
bug. Fix the mapping so each value goes to its intended column. Do NOT widen `client_phone_1`; that
would let a misrouted blob persist silently. Add a create/edit parity test (below).

---

## STEP 3 — FIX THE LATENT UX BUG (do regardless of branch)

Right now ANY create failure shows generic "Database error occurred." with no field attribution —
an operator hitting this in production is stuck. The specific field IS known (`$wpdb->last_error`
names it). Surface a useful, non-leaky message:

- When `create_client` fails, return enough for the UI to say *which field* was the problem (e.g.
  "Could not save: the phone number is too long / not in (###)-###-#### format") rather than
  "Database error occurred." Map the `$wpdb` field-failure to the form field and its label.
- Do NOT echo the raw `$wpdb->last_error` to the browser (it can leak schema detail) — translate it
  to a field-level user message; keep the raw error in the log only.

This is the fix that turns a dead-end into a correctable form error, and it's valuable independent of
A vs B.

---

## TESTS

- **T-1 (A) over-long inputs rejected with a NAMED error:** submit create with a 25-char phone and a
  full-name province → blocked with field-level messages ("phone… format", "province… code"), NOT a
  generic DB error, and no row inserted.
- **T-2 (A) valid create succeeds:** a Private client with valid postal, 14-char formatted phone,
  2-letter province → persists and appears in the client list (this is the F3/F5 case — it must PASS).
- **T-3 (B, if applicable) create/edit data parity:** the `$data` map built for create matches the
  column set/shape the edit path uses; no value is routed to a wrong column. (A guard test even if
  the branch is A — cheap insurance against future divergence.)
- **T-4 (Step 3) failure attribution:** a forced insert failure yields a field-attributed user
  message, and the raw `$wpdb` error is logged but NOT returned to the client.
- **T-5 encoding edge:** a phone with valid format but trailing whitespace/non-ASCII is normalized or
  rejected cleanly, not passed through to fail at `$wpdb`.

Run the new test + FULL suite (75 + this). Then **re-run the Phase-1 GUI suite cases F2/F3/F5** on
staging — F3 and F5 must flip to PASS for Phase-1 to reach 100% (the Phase-2 gate).

---

## ACCEPTANCE CRITERIA

1. STEP 1 diagnostic identifies the actual failing value's shape; the A/B branch is chosen from
   evidence, not assumption.
2. The correct branch fix is applied (A: validate/clamp phone+province at the form, province as a
   code; or B: correct the create-path field mapping — NOT a column widen).
3. Creating a valid new Private client through the GUI PERSISTS it (F3/F5 PASS).
4. Create failures surface a field-attributed user message; raw `$wpdb` error stays in the log only.
5. Diagnostic logging from STEP 1 removed/downgraded after confirmation.
6. New tests green; full suite green; Phase-1 F3/F5 re-run PASS.

---

## NOTES

- The encrypted columns are correctly sized for ciphertext (VARCHAR(500)/(50)/TEXT) — do NOT
  "fix" this by widening phone/province to match; that treats a symptom and, in Branch B, hides a
  real mapping bug.
- This is a textbook GUI-test catch: unit tests insert clean fixtures and never exercise an
  over-long/misrouted real-form value, so only end-to-end form submission surfaced it. Worth noting
  for the value of the Phase-1 suite.
- Phase 2 (synthetic-month simulation) stays gated until Phase-1 is 100% — i.e. until F3/F5 pass.
