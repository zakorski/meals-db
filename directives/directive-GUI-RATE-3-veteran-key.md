# Directive GUI-RATE-3 (SURGICAL) — Give Veteran its own rate keys (split from private_*)

## HOW TO EXECUTE THIS DIRECTIVE — READ THIS FIRST
- This is **4 surgical edits** across 3 files. Do EXACTLY these 4 and nothing else.
- For each: `read` the named file, find the EXACT verbatim FIND block, apply the change. Do NOT
  regenerate any method, array, or file from memory. Do NOT reformat untouched lines.
- If a FIND block doesn't match verbatim, STOP and report — do not improvise.
- Expected change: 1 new key in a defaults array, 1 new label row + 1 new group on the admin page,
  1 changed get() call + comment in the resolver. If you're editing more than that, STOP.

**Why:** RATE-2 resolved Veteran and Private to the SAME `private_main` Definitions key because their
prices are currently equal. Operator decision: **Veteran does equal Private, but each must have its
OWN key** in the Definitions page — equal values today, independent knobs tomorrow. One shared key
means changing Veteran pricing silently changes Private (and vice-versa); two keys seeded to the same
value avoids that. This adds Veteran main/side/combo keys (= private values) and points the resolver's
Veteran branch at it.

NOTE: This adds Veteran main, side, AND combo keys (seeded equal to their private_* counterparts)
so the Definitions page is symmetric and the three Veteran prices can diverge later. The fallback
resolver only READS the main rate today; the side/combo keys exist for editing + future use (operator
decision — they are not wired into billing logic by this directive, which is correct: Veteran
sides/combos equal Private today).

---

## EDIT 1 — add the three Veteran defaults (seeded equal to private_*)
**File:** `includes/class-rate-definitions.php`
**FIND (verbatim, 4 lines):**
```
            // Janet's private/veteran prices — NEW, born here (not constants).
            'private_main'              => 9.50,
            'private_side'              => 4.25,
            'private_combo'             => 13.75,
```
**REPLACE WITH:**
```
            // Janet's private/veteran prices — NEW, born here (not constants).
            // Veteran prices equal private prices today but are SEPARATE keys so the two
            // can diverge without editing the other. Keep values in sync until/unless the
            // operator sets distinct Veteran prices.
            'private_main'              => 9.50,
            'private_side'              => 4.25,
            'private_combo'             => 13.75,
            'veteran_main'              => 9.50,
            'veteran_side'              => 4.25,
            'veteran_combo'             => 13.75,
```

## EDIT 2 — show Veteran keys on the Definitions admin page (own group)
**File:** `includes/admin/class-rate-definitions-page.php`
**FIND (verbatim, 6 lines):**
```
            'Private / Veteran prices' => [
                'private_main'  => __('Main', 'meals-db'),
                'private_side'  => __('Side', 'meals-db'),
                'private_combo' => __('Main + side combo', 'meals-db'),
            ],
```
**REPLACE WITH:**
```
            'Private prices' => [
                'private_main'  => __('Main', 'meals-db'),
                'private_side'  => __('Side', 'meals-db'),
                'private_combo' => __('Main + side combo', 'meals-db'),
            ],
            'Veteran prices' => [
                'veteran_main'  => __('Main', 'meals-db'),
                'veteran_side'  => __('Side', 'meals-db'),
                'veteran_combo' => __('Main + side combo', 'meals-db'),
            ],
```
**NOTE:** the heading "Private / Veteran prices" becomes "Private prices", and a new "Veteran prices"
group is added below it with the same three fields (main/side/combo), mirroring Private.

---

## EDIT 3 — point the resolver's Veteran branch at `veteran_main` (main only)
**File:** `includes/services/class-wc-order-query.php`
**FIND (verbatim, 6 lines — the comment + branch):**
```
        // Veteran and Private share the per-main rate. Operator confirmed veteran prices
        // equal private prices; both are seeded in MealsDB_Rate_Definitions as 'private_main'.
        // get() returns null only for an unknown key (a caller bug) — fall back to 0.00 then.
        if ($type === 'VETERAN' || $type === 'PRIVATE') {
            $main_rate = MealsDB_Rate_Definitions::get('private_main');
            return $main_rate !== null ? (float) $main_rate : 0.00;
        }
```
**REPLACE WITH:**
```
        // Veteran and Private have EQUAL main prices today but SEPARATE Definitions keys
        // ('veteran_main' / 'private_main') so either can change without affecting the other.
        // get() returns null only for an unknown key (a caller bug) — fall back to 0.00 then.
        if ($type === 'VETERAN') {
            $main_rate = MealsDB_Rate_Definitions::get('veteran_main');
            return $main_rate !== null ? (float) $main_rate : 0.00;
        }
        if ($type === 'PRIVATE') {
            $main_rate = MealsDB_Rate_Definitions::get('private_main');
            return $main_rate !== null ? (float) $main_rate : 0.00;
        }
```

---

## EDIT 4 — update the method docblock to match (no shared-key claim)
**File:** `includes/services/class-wc-order-query.php`
**FIND (verbatim, 3 lines — in the docblock above resolve_program_rate):**
```
     * Veteran/Private: the per-main rate from MealsDB_Rate_Definitions. These prices are
     * BORN in Definitions (not WC) and are equal per the operator (see class-rate-definitions
     * defaults() — 'private_main'), so both types resolve the same 'private_main' key.
```
**REPLACE WITH:**
```
     * Veteran/Private: the per-main rate from MealsDB_Rate_Definitions. These prices are
     * BORN in Definitions (not WC). Equal today but on SEPARATE keys ('veteran_main' /
     * 'private_main') so either can change independently.
```

---

## VERIFICATION (after the 4 edits)
```bash
cd <plugin-root>
grep -n "veteran_main\|veteran_side\|veteran_combo" includes/class-rate-definitions.php   # 3 new defaults
grep -n "veteran_main\|veteran_side\|veteran_combo" includes/admin/class-rate-definitions-page.php  # 3 new label rows
grep -n "veteran_main\|private_main" includes/services/class-wc-order-query.php  # resolver: veteran->veteran_main, private->private_main
grep -rn "'Private / Veteran prices'" includes/  # expect: GONE (renamed)
php tests/test-*.php   # expect all green
```
- **T (key exists):** `MealsDB_Rate_Definitions::get('veteran_main')` returns 9.50 (the seed).
- **T (resolver):** a Veteran client with no rates row resolves via `veteran_main`; a Private client
  via `private_main`. Changing one Definitions value in a test does NOT change the other.
- **T (admin page):** the Definitions page shows a "Veteran prices" group with a Main field, and the
  Private group no longer says "Private / Veteran".
- **Manual:** open the Definitions page in wp-admin — confirm Veteran Main appears, editable, seeded
  at the same value as Private Main.

## DO NOT
- Do not change the private_main / private_side / private_combo VALUES.
- Do not delete the Private group — it keeps its three keys.
- Do not touch the SDNB branch or get_sdnb_main_rate.
- Do not modify save()/get()/defaults() logic — only ADD the one key to the defaults array.

## NOTE ON SIDE/COMBO KEYS
- `veteran_side` and `veteran_combo` are ADDED (editable, seeded equal to private) but are NOT yet
  read by any billing logic — the fallback resolver only uses the main rate, and Veteran sides/combos
  equal Private today. They exist so the Definitions page is symmetric and so Veteran side/combo can
  diverge later with only a small consuming-code change (no schema/key work needed at that point).
  Do NOT wire them into billing in this directive.
