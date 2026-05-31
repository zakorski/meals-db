# Directive GUI-INITIALS — Initials are globally unique (drop address-sharing) + surface generator exhaustion

**Status:** ready to implement. Two related changes in the delivery-initials code.
**Severity:** correctness + UX. (1) The validator currently ALLOWS a duplicate initials code when the
delivery address matches — contradicts the rule that initials are globally unique. (2) The generator
returns a bare `false` after 100 failed attempts with only a generic message; the operator should
get a clear on-window (non-popup) "couldn't find an unused combination, retry" message.
**Verified at:** v1.0.426, `includes/class-initials-validator.php`, `includes/ajax/class-ajax-initials.php`,
`includes/class-client-form.php`, `assets/js/client-initials.js`.

---

## OPERATOR DECISION (settled)

Delivery initials are a GENERATED unique code from a 3-letter space (~17,500 usable combinations
after the banlist). They must be **globally unique — period.** The existing "allow a duplicate if
the delivery address matches" exception is removed. There is no same-address exception.

---

## CHANGE 1 — Remove the address-sharing exception (initials globally unique)

The `MealsDB_Initials_Validator` is currently built around "address-based duplicate checking" — its
header says it "allows multiple clients ... the same physical address," and `validate_code` /
`validate` return `shared` / `sharing_with` and call `normalize_delivery_address()`. Collapse this
to a plain global-uniqueness check.

### `includes/class-initials-validator.php`
- In `validate()` / `validate_code()`: an initials code that is already in use by ANOTHER client is
  **invalid**, full stop. Remove the branch (around the "Step 4: if initials ARE in use, check if
  delivery addresses match" logic) that treats a matching address as permitting reuse.
- Remove `shared` / `sharing_with` from the return shape (or hard-set `shared => false`), and remove
  the now-dead `normalize_delivery_address()` address-comparison path used only for the sharing
  exception. (Leave general address normalization if it's used elsewhere — grep first; only remove
  what existed to support the sharing exception.)
- Update the class docblock — it no longer "allows multiple clients at the same physical address."
- The uniqueness check itself (is this code already in `delivery_initials` for a different client)
  stays — that's the whole point; just without the address escape hatch.

### `includes/class-client-form.php` (~line 408-419)
- Remove the "address-based duplicate checking" comment and the `if (!empty($validation['shared']))`
  "shared at the same address" `error_log` block — there is no shared case anymore. A duplicate code
  is simply a `$record_format_error('delivery_initials', ...)` (invalid), which it already is when
  `$validation['valid']` is false. So the `else` branch just sets `$sanitized['delivery_initials']`
  with no shared-logging.

### `includes/ajax/class-ajax-initials.php`
- The "address-based generation" / `get_client_data_from_request()` passing of address into
  generation can stay if it's only used for the *pattern* seeding (initials derived from name), but
  the address is no longer used for a sharing EXCEPTION. Confirm the address data isn't doing
  anything other than seeding name-pattern candidates; if it only fed the sharing check, drop it.

**Net:** `delivery_initials` keeps its hard UNIQUE index (correct — confirmed by operator and by PR
#406, which deliberately kept it in `$hard_unique_index_columns`). This change just removes the
application-level exception that disagreed with that constraint. Now code and constraint agree:
globally unique.

---

## CHANGE 2 — Surface generator exhaustion as a clear on-window message (not a popup)

`MealsDB_Initials_Validator::generate()` tries name-based patterns, then up to `$max_attempts = 100`
random codes (skipping in-use + banlist), then `return false` ("Unable to generate"). The AJAX
handler already returns `['success' => false, 'message' => 'Unable to generate initials.']` and the
JS already has an on-page `.mealsdb-initials-message` element — so the infrastructure exists; make
the message SPECIFIC and make sure the JS renders it on-page (not a popup, not silent).

### `includes/ajax/class-ajax-initials.php`
- When `generate()` returns false, return a clearer message:
  `'Could not find an unused initials code after 100 attempts. Please click Generate again to retry.'`
  (Keep `success => false`.)

### `assets/js/client-initials.js`
- Confirm the generate handler renders a `success:false` response's `message` into the on-page
  `.mealsdb-initials-message` element (the same element used for validation feedback), styled as an
  error/warning notice — NOT a `window.alert()` and NOT swallowed silently. If the current handler
  only acts on `success:true` (sets the field) and ignores the failure branch, ADD the failure
  branch: show the message in `.mealsdb-initials-message`, leave the field empty, and keep the
  Generate button enabled so the operator can immediately retry.
- This mirrors the GUI-NOTICES direction (on-page, not popup).

### `includes/class-initials-validator.php` (optional, recommended)
- Consider raising `$max_attempts` modestly (e.g. 100 → 250) OR, better, making the random search
  smarter: since `get_all_existing_initials()` is already pre-fetched, the generator could compute
  the remaining free space and pick from it directly rather than blind-guessing — but that's an
  optimization, not required. At ~17,500 combinations, 100 random tries only realistically exhaust
  when the space is nearly full, which is itself a signal worth surfacing (Change 2's message). If
  the space is genuinely near-full someday, that message is the operator's early warning. Keep the
  message regardless of whether attempts are raised.

---

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>
grep -n "shared\|sharing_with\|same.*address\|normalize_delivery_address\|address-based" includes/class-initials-validator.php
grep -n "shared\|same address\|address-based duplicate" includes/class-client-form.php
grep -n "max_attempts\|Unable to generate\|return false" includes/class-initials-validator.php
grep -n "mealsdb-initials-message\|success\|message" assets/js/client-initials.js | head
# Confirm delivery_initials is still hard-unique (must NOT be touched by this change):
grep -n "hard_unique_index_columns\|delivery_initials_index" includes/class-client-form.php
```

---

## TESTS

- **T-1 duplicate initials rejected regardless of address:** two clients, SAME delivery_initials,
  SAME delivery address → the second is INVALID (rejected), not allowed-as-shared. (The old behavior
  would have allowed it; assert it no longer does.)
- **T-2 duplicate initials rejected, different address:** unchanged behavior (still rejected) — just
  confirms no regression.
- **T-3 unique initials accepted:** a fresh, unused, non-banlisted code validates.
- **T-4 generator returns false when space exhausted:** stub the in-use set to "all combos taken" →
  `generate()` returns false (unchanged), AND the AJAX handler returns the specific
  "after 100 attempts ... retry" message with `success:false`.
- **T-5 banlist still enforced:** a banlisted code (e.g. XXX) is never returned by generate and is
  invalid on validate.
- Full suite green.

(JS rendering of the failure message is verified in the GUI re-test, not unit tests.)

---

## ACCEPTANCE CRITERIA

1. Delivery initials are globally unique — no same-address exception anywhere (validator, form, ajax).
2. `shared`/`sharing_with` and the address-comparison-for-sharing path are removed; docblock updated.
3. `delivery_initials` retains its hard UNIQUE index (unchanged — code and constraint now agree).
4. Generator exhaustion (100 attempts) surfaces a SPECIFIC on-page message
   ("Could not find an unused initials code after 100 attempts … retry") in
   `.mealsdb-initials-message` — not a popup, not silent — with the Generate button still usable.
5. Banlist still enforced; T-1..T-5 green; full suite green.

---

## OUT OF SCOPE
- The banlist contents/maintenance (separate concern; not touched here).
- The hard UNIQUE index on delivery_initials (stays — this directive makes the app agree with it).
- The warn-only treatment of individual_id/requisition_id (PR #406, unrelated — those are a
  different, genuinely-shareable identifier; initials are not).

---

## NOTES
- This makes the app-level rule AGREE with the DB constraint PR #406 deliberately kept: delivery
  initials hard-unique. The address-sharing exception was the leftover of an earlier "initials =
  a person's actual initials (can repeat)" model; the system has since moved to "initials = a
  generated unique code," so the exception is now simply wrong.
- Generator note: 100 random attempts only realistically fail when the ~17,500-code space is nearly
  full. The new message doubles as an early-warning that the namespace is filling up — worth keeping
  even if attempts are later raised.
