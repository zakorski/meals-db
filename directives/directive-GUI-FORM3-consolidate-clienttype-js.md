# Directive GUI-FORM3 (SURGICAL, v446) — Consolidate client-type show/hide into one script

## HOW TO EXECUTE — READ FIRST
- This is **3 edits** in `includes/class-admin-ui.php` + **1 file deletion**. Do exactly these.
  For each PHP edit: `read` the file, find the EXACT verbatim FIND, apply. Do NOT regenerate methods.
  If a FIND doesn't match verbatim, STOP and report.
- This REMOVES a redundant, buggy script (`client-type-logic.js`) and lets the existing, correct
  handler in `admin.js` be the single source of truth. Verified safe (see "Why it's safe" below).

**The bug (FORM-3):** the "Case Management" section (`data-client-type="sdnb,veteran"`) shows for
Veteran but is HIDDEN for SDNB, so Social Worker fields + Requisition Period don't appear for SDNB
clients.

**Root cause:** TWO scripts both manage client-type show/hide on the client form:
- `admin.js` → `toggleClientTypeSections()`: data-driven, iterates ALL `[data-client-type]` elements,
  splits the comma list and uses `.includes(selectedType)`. **Handles `sdnb,veteran` CORRECTLY.**
- `client-type-logic.js`: a narrower REIMPLEMENTATION that toggles hardcoded section lists via two
  independent calls — `toggleSection($sdnbSections, isSdnb)` then `toggleSection($veteranSections,
  isVeteran)`. A shared `sdnb,veteran` section is in BOTH lists, so the second call (veteran) HIDES
  it whenever the type isn't veteran. This clobbers admin.js's correct result. → SDNB hides it.

**Why removing client-type-logic.js is safe (verified):**
- Its show/hide of sections is a buggy subset of what admin.js already does correctly and generically.
- Its only other behavior is an `isStaff` branch — but the client form has NO "staff" client_type
  (only SDNB/Veteran/Private), so that branch is DEAD CODE here.
- The address + initials sections it toggled have NO `data-client-type`, so admin.js shows them for
  all types anyway (its `if (!allowedRaw)` branch). No behavior lost.
- Result: admin.js alone produces correct visibility for ALL types, INCLUDING shared sdnb,veteran
  sections — which IS the FORM-3 fix.

---

## EDIT 1 — remove the client-type-logic.js enqueue
**File:** `includes/class-admin-ui.php`
**FIND (verbatim, 9 lines):**
```
        $client_type_logic_path = MEALS_DB_PLUGIN_DIR . 'assets/js/client-type-logic.js';
        $client_type_logic_version = file_exists($client_type_logic_path) ? filemtime($client_type_logic_path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-client-type-logic',
            MEALS_DB_PLUGIN_URL . 'assets/js/client-type-logic.js',
            ['jquery', 'mealsdb-admin'],
            $client_type_logic_version,
            true
        );

```
**ACTION:** delete all 9 lines (the whole block + its trailing blank line).

---

## EDIT 2 — remove the dead dependency from the initials script enqueue
**File:** `includes/class-admin-ui.php`
The initials script declares `mealsdb-client-type-logic` as a dependency. Since that handle no longer
exists after EDIT 1, it MUST be removed from the dependency array or the initials script may fail to
enqueue.
**FIND (verbatim, 1 line):**
```
            ['jquery', 'mealsdb-admin', 'mealsdb-client-type-logic', $notice_handle],
```
**REPLACE WITH:**
```
            ['jquery', 'mealsdb-admin', $notice_handle],
```

---

## EDIT 3 — delete the now-unused script file
**File:** `assets/js/client-type-logic.js`
**ACTION:** delete the file. (After EDITS 1-2, nothing enqueues or depends on it.)
Verify no other reference first (should be none): grep the repo for `client-type-logic` — only the
two spots above should have referenced it.

---

## VERIFICATION
```bash
cd <plugin-root>
grep -rn "client-type-logic" includes/ assets/ --include=*.php --include=*.js   # expect: NOTHING
grep -n "mealsdb-client-type-logic" includes/class-admin-ui.php                 # expect: NOTHING
ls assets/js/client-type-logic.js 2>/dev/null && echo "FILE STILL PRESENT (delete it)" || echo "file deleted OK"
php tests/test-*.php   # expect green (no PHP logic touched beyond enqueues)
```
**Manual GUI (the real check):**
- Open Add New Client. Select **SDNB** → the **Case Management** section (Social Worker Name, Social
  Worker Email, Requisition Period) must now be VISIBLE. Select **Veteran** → still visible. Select
  **Private** → Case Management must be HIDDEN (Private isn't in sdnb,veteran).
- Confirm the rest still gates correctly: SDNB Program Details shows only for SDNB; Veteran Details
  only for Veteran; Address + Delivery Initials show for all; required-field behavior unchanged.
- Create an SDNB client filling Requisition Period via the now-visible field (no DOM workaround) →
  saves. (This unblocks the SDNB requisition_period entry the tester previously had to force via DOM.)

## DO NOT
- Do not modify `admin.js`'s `toggleClientTypeSections` — it is already correct; the fix is removing
  the competing script, not changing the good one.
- Do not remove any `data-client-type` attributes from the form — they drive admin.js's logic.
- Do not touch the initials script itself (`client-initials.js`) — only its dependency array in the
  enqueue (EDIT 2).
- If grep in VERIFICATION still finds a `client-type-logic` reference, STOP — there's a dependency you
  haven't cleared; report it rather than forcing the deletion.
