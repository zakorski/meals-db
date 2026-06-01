# Directive INV-3 (SURGICAL, v446, EXPANDED) — Consolidate to ONE invoice page

## HOW TO EXECUTE — READ FIRST
- This REMOVES the old "Invoices" page entirely and RENAMES the draft page to "Invoices",
  reusing the old page's slug so existing links survive. Edits across several files + 3 file
  deletions. `read` each file, find the EXACT verbatim FIND, apply. If a FIND doesn't match, STOP.
- Do NOT delete the `MealsDB_Invoice_Generator::generate_*()` methods — they are still referenced by
  tests (tests/test-phase2-billing.php). They become UI-orphaned but must stay (flagged for future
  cleanup). Removing them WILL break the suite.

**Why:** there are currently TWO ways to generate an invoice — the old "Invoices" page (a direct
generate→download form that BYPASSES the draft/review/finalize/audit discipline) and the newer draft
page (generate→review→edit→finalize→download, audited, with un-finalize). Two paths caused confusion
(tonight's finalized-at-zero invoices). Consolidate to ONE: remove the old page, rename the draft page
to "Invoices". Verified safe — the draft page supports all the old page's pipelines (VAC CSV/PDF,
SDNB legacy, SDNB new portal), so no capability is lost; integrity improves (no bypass path).

---

## PART A — REMOVE the old invoice page + its direct-generate AJAX

### A-1: delete the old page class file
**DELETE FILE:** `includes/admin/class-invoice-page.php`

### A-2: delete the old direct-generate AJAX class file
**DELETE FILE:** `includes/ajax/class-ajax-invoice.php`
(This is the `mealsdb_generate_invoice` bypass endpoint — removing it is a goal, not a side effect.)

### A-3: delete the old page's view
**DELETE FILE:** `views/admin-invoice.php`

### A-4: delete the old page's JS
**DELETE FILE:** `assets/js/invoice.js`

### A-5: remove the old page's bootstrap init
**File:** `meals-db-main.php`
**FIND (verbatim, 1 line):**
```
    MealsDB_Invoice_Page::init();
```
**ACTION:** delete the line.

### A-6: remove the old AJAX class's DIRECT bootstrap init
**File:** `meals-db-main.php`
**FIND (verbatim, 1 line):**
```
    MealsDB_Ajax_Invoice::init();
```
**ACTION:** delete the line.

### A-7: remove the old AJAX class from the CENTRAL registry
**File:** `includes/class-ajax.php`
**FIND (verbatim — the handler list entry):**
```
            'MealsDB_Ajax_Invoice',
```
**ACTION:** delete the line. (NOTE: do NOT remove `'MealsDB_Ajax_Invoice_Draft'` or any other —
ONLY the `'MealsDB_Ajax_Invoice',` line. They are distinct classes; the Draft one stays.)

### A-8: check for a require/include of the deleted class files
**File:** wherever the plugin require_once's these classes (likely meals-db-main.php or a loader).
**FIND** any `require`/`require_once`/`include` lines referencing:
- `class-invoice-page.php`
- `ajax/class-ajax-invoice.php`
**ACTION:** remove those require lines. (If the plugin autoloads by class name rather than explicit
requires, there may be nothing to remove here — in that case, confirm no `require.*invoice-page`
or `require.*ajax-invoice.php` remains. Do NOT remove requires for `class-invoice-draft-page.php`
or `class-ajax-invoice-draft.php` — those stay.)

---

## PART B — RENAME the draft page to "Invoices" + reuse the old slug

### B-1: change the slug constant (reuse the old page's slug so old links survive)
**File:** `includes/admin/class-invoice-draft-page.php`
**FIND (verbatim, 1 line):**
```
    public const PAGE_SLUG = 'mealsdb_invoice_drafts';
```
**REPLACE WITH:**
```
    public const PAGE_SLUG = 'mealsdb-invoices';
```
**Why safe:** every internal use of the slug derives from this constant (enqueue hook check, pageUrl,
form hidden field, base URLs), so this one change updates them all. The old page that used
`mealsdb-invoices` is removed in Part A, so there is no collision.

### B-2: menu label + page title → "Invoices"
**File:** `includes/admin/class-invoice-draft-page.php`
**FIND (verbatim, 2 consecutive lines):**
```
            __('Invoice Drafts', 'meals-db'),
            __('Invoice Drafts', 'meals-db'),
```
**REPLACE WITH:**
```
            __('Invoices', 'meals-db'),
            __('Invoices', 'meals-db'),
```

### B-3: page heading
**File:** `includes/admin/class-invoice-draft-page.php`
**FIND (verbatim):**
```
        echo '<h1>' . esc_html__('Meals DB — Invoice Drafts', 'meals-db') . '</h1>';
```
**REPLACE WITH:**
```
        echo '<h1>' . esc_html__('Meals DB — Invoices', 'meals-db') . '</h1>';
```

### B-4: (comment-only) update the file header
**File:** `includes/admin/class-invoice-draft-page.php`
**FIND (verbatim):**
```
 * Admin page: MealsDB → Invoice Drafts (directive INV-DRAFT-2).
```
**REPLACE WITH:**
```
 * Admin page: MealsDB → Invoices (formerly "Invoice Drafts"; the sole invoice page as of INV-3).
```

---

## VERIFICATION
```bash
cd <plugin-root>
# old page fully gone:
ls includes/admin/class-invoice-page.php includes/ajax/class-ajax-invoice.php views/admin-invoice.php assets/js/invoice.js 2>&1   # all: No such file
grep -rn "MealsDB_Invoice_Page\|MealsDB_Ajax_Invoice\b\|mealsdb_generate_invoice" includes/ assets/ meals-db-main.php --include=*.php --include=*.js | grep -v "Invoice_Draft\|Invoice_Generator"   # expect: NOTHING
# generator methods still present (must NOT be removed):
grep -n "function generate_vac_csv\|function generate_sdnb_legacy" includes/services/class-invoice-generator.php   # expect: present
# renamed page:
grep -n "mealsdb-invoices\|'Invoices'" includes/admin/class-invoice-draft-page.php   # slug + labels
grep -rn "Invoice Drafts" includes/admin/class-invoice-draft-page.php   # expect: none
# full suite (the generator-method test must still pass):
php tests/test-*.php   # expect green, including test-phase2-billing.php
```
**Manual (staging):**
- The Meals DB menu shows ONE "Invoices" item (the old separate "Invoices" is gone). No duplicate.
- Visiting `admin.php?page=mealsdb-invoices` lands on the (renamed) draft page — old links survive.
- The page's scripts still load (generate button, edit, finalize, un-finalize all work) — confirms
  the slug change didn't break the enqueue hook check.
- Generate a draft for each pipeline (VAC, SDNB legacy, SDNB new portal) → all still available.
- No console/PHP errors about a missing invoice page or AJAX handler.

## DO NOT
- Do NOT delete `MealsDB_Invoice_Generator::generate_vac_csv / generate_sdnb_legacy /
  generate_sdnb_new_portal / generate_vac_pdf` — still test-referenced. (Future cleanup ticket: remove
  these orphaned one-shot generators + their test once confirmed unused. NOT now.)
- Do NOT touch the draft AJAX class (`MealsDB_Ajax_Invoice_Draft`) or its file — only the OLD
  `MealsDB_Ajax_Invoice` is removed. The names are similar; remove only the non-Draft one.
- Do NOT change the draft page's class name or file name — only its PAGE_SLUG constant + labels.
- If any FIND doesn't match verbatim, STOP and report rather than guessing.

## FUTURE CLEANUP (note, do not do now)
- Orphaned `MealsDB_Invoice_Generator::generate_*()` one-shot methods + test-phase2-billing.php's
  reliance on them — remove together in a later pass once the draft path is confirmed sole.
