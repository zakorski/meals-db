# Slips + PO Merge Implementation Plan (Admin UI Consolidation, PR 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retire the main page's "Daily Slips" and "Purchase Order" tabs by folding their capabilities into the Packing Slips page (collapsed on-demand section) and the Purchase Orders list (Generate button), with legacy-URL redirects.

**Architecture:** Pure UI relocation — no AJAX endpoint, handler, nonce, capability, or rate-limit changes. `views/daily-slips.php` becomes an embeddable section included by `MealsDB_Slip_Batch_Page::render()` inside a collapsed `<details>` (its JS + JSON island move with it via a page-scoped enqueue). The one-click generate action moves from `purchase-order.js` (deleted, with its view) into `purchase-orders.js`, reusing the existing `#mealsdb-po-admin-data` island. A new pure, unit-tested map `MealsDB_Admin_UI::retired_tab_target()` drives an `admin_init` redirect so `?page=mealsdb&tab=slips|po` bookmarks keep working.

**Tech Stack:** WordPress admin pages/enqueue API, jQuery, JSON-island pattern (`CLAUDE.md`: no inline script blobs), standalone-PHP test convention (`php tests/test-*.php`).

**Spec:** `docs/superpowers/specs/2026-07-16-admin-ui-consolidation-design.md` §3 (slips), §4 (PO), §6 (redirects), rollout PR 2.

**Reference facts (verified against the code 2026-07-16, post-PR #465):**
- `views/daily-slips.php` is a self-contained fragment (no `.wrap`): its own `MealsDB_Permissions::enforce()` + `manage_options` `wp_die` gate (lines 14–27), inline styles only (no rules in `assets/css/admin.css`), and a `#mealsdb-daily-slips-data` JSON island read by `assets/js/daily-slips.js`.
- `MealsDB_Slip_Batch_Page` (`includes/admin/class-slip-batch-page.php`): slug `mealsdb-packing-slips`, `manage_options`, `render()` = `render_generate_form()` + `render_history_table()`; `enqueue_scripts()` keys on `strpos($hook, self::PAGE_SLUG)`.
- `MealsDB_Admin_UI::register_report_utils_script()` is `public static` and returns the handle — reusable from the slip-batch page.
- The main page's per-tab assets live in `MealsDB_Admin_UI::enqueue_tab_view_scripts()` (cases `'slips'` and `'po'` to delete); tab routing in `render_main_page()` (~line 1062 and 1066); tab labels in `render_tabs()` (~line 1150).
- `views/purchase-orders.php`: list view starts ~line 331 (`<h2>Purchase Orders</h2>` + description); shared island builder closure `$mealsdb_po_render_island` (~line 29) already carries `nonce`, `ajaxUrl`, `baseUrl`, `i18n.requestFailed`; `assets/js/purchase-orders.js` reads `#mealsdb-po-admin-data` into `cfg` and has a `msg(text, isError)` status helper targeting `#mealsdb-po-action-msg`.
- `assets/js/purchase-order.js` (to delete): posts `mealsdb_po_save_draft` with the PO nonce, redirects to `poAdminUrl + '&po_id=' + id` on success, disables the button in flight.
- Repo-wide grep: NOTHING else references `tab=slips`, `tab=po`, `purchase-order.js`, or `purchase-order.php` besides the sites named in this plan (tasks-list links use `tab=po_admin`, which survives until PR 3).
- Redirect precedent: `MealsDB_Admin_UI::redirect_legacy_quick_order_slug()` on `admin_init`, registered in `register_hooks()` (~line 164).
- Tests are standalone scripts (`php tests/test-*.php`); local baseline: 2 PDF tests fail (missing mbstring/imagick) — not caused by this work.

---

### Task 0: Create the feature branch

**Files:** none

- [ ] **Step 1: Branch from up-to-date main**

```bash
cd /mnt/fastssd/meals-db && git checkout main && git pull && git checkout -b feat/slips-po-merge
```

Note: the working tree has pre-existing uncommitted operator changes under `directives/`. Do NOT stage or commit them. Every commit in this plan stages explicit paths only.

Execution note (learned in PR 1): review/verification subagents must NEVER `git checkout <commit>` — a detached HEAD made later commits land off-branch. Inspect other revisions with `git show <sha>:<path>` instead.

---

### Task 1: Retired-tab redirect map (TDD)

**Files:**
- Test: `tests/test-retired-tab-redirects.php` (create)
- Modify: `includes/class-admin-ui.php` (two new methods + one hook registration)

- [ ] **Step 1: Write the failing test**

Create `tests/test-retired-tab-redirects.php` with exactly this content:

```php
<?php
/**
 * Tests for MealsDB_Admin_UI::retired_tab_target() — the legacy-URL map for
 * main-page tabs retired by the admin UI consolidation (spec 2026-07-16,
 * PR 2: slips + po). Bookmarks and muscle memory must keep working:
 *   - ?page=mealsdb&tab=slips → the Packing Slips page
 *   - ?page=mealsdb&tab=po    → the po_admin tab (until PR 3 moves it)
 *   - live tabs / other pages → null (no redirect)
 *
 * Run with: php tests/test-retired-tab-redirects.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// --- WP stubs ----------------------------------------------------------
function admin_url(string $path = '') {
    return 'https://example.test/wp-admin/' . $path;
}

// --- assertion helpers (test-hook-logger.php convention) ----------------
$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s",
        $label, var_export($expected, true), var_export($actual, true));
}

// ---------------------------------------------------------------------------
// Retired tabs redirect.
// ---------------------------------------------------------------------------
assert_equal(
    'https://example.test/wp-admin/admin.php?page=mealsdb-packing-slips',
    MealsDB_Admin_UI::retired_tab_target('mealsdb', 'slips'),
    'slips => Packing Slips page'
);
assert_equal(
    'https://example.test/wp-admin/admin.php?page=mealsdb&tab=po_admin',
    MealsDB_Admin_UI::retired_tab_target('mealsdb', 'po'),
    'po => po_admin tab'
);

// ---------------------------------------------------------------------------
// Live tabs and foreign pages are untouched.
// ---------------------------------------------------------------------------
assert_equal(null, MealsDB_Admin_UI::retired_tab_target('mealsdb', 'po_admin'), 'live tab po_admin => no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target('mealsdb', 'tasks'), 'live tab tasks => no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target('mealsdb', ''), 'no tab => no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target('mealsdb-reports', 'slips'), 'other page => no redirect');

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("OK: %d assertions passed\n", $passed));
exit(0);
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php tests/test-retired-tab-redirects.php
```

Expected: PHP fatal error — `Call to undefined method MealsDB_Admin_UI::retired_tab_target()`. Non-zero exit. (If instead the CLASS fails to load for an unrelated parse-time reason, stop and report — that's environment, not the test.)

- [ ] **Step 3: Implement the map + redirect hook**

In `includes/class-admin-ui.php`, directly after the closing brace of `redirect_legacy_quick_order_slug()` (~line 270), add:

```php
    /**
     * Legacy-URL map for main-page tabs retired by the admin UI
     * consolidation (spec 2026-07-16). Returns the replacement admin URL,
     * or null when the request is not a retired-tab URL. Pure (no request
     * reads, no redirect) so it is unit-testable; redirect_retired_tabs()
     * is the admin_init wrapper that acts on it.
     */
    public static function retired_tab_target(string $page, string $tab): ?string {
        if ($page !== 'mealsdb') {
            return null;
        }
        switch ($tab) {
            // PR 2: Daily Slips folded into the Packing Slips batch page
            // (collapsed "On-demand PDFs" section).
            case 'slips':
                return admin_url('admin.php?page=mealsdb-packing-slips');
            // PR 2: the generate-only tab merged into the PO list.
            // Still tab=po_admin until PR 3 gives the list its own page —
            // update this target in PR 3, not the callers.
            case 'po':
                return admin_url('admin.php?page=mealsdb&tab=po_admin');
            default:
                return null;
        }
    }

    /**
     * admin_init: redirect retired ?page=mealsdb&tab=… URLs so bookmarks
     * and muscle memory survive the consolidation. Same pattern as
     * redirect_legacy_quick_order_slug() above.
     */
    public function redirect_retired_tabs(): void {
        if (!isset($_GET['page'], $_GET['tab'])) {
            return;
        }

        $page = $_GET['page'];
        $tab  = $_GET['tab'];
        if (function_exists('wp_unslash')) {
            $page = wp_unslash($page);
            $tab  = wp_unslash($tab);
        }
        if (function_exists('sanitize_key')) {
            $tab = sanitize_key((string) $tab);
        }

        $target = self::retired_tab_target((string) $page, (string) $tab);
        if ($target === null) {
            return;
        }

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($target);
        } else {
            wp_redirect($target);
        }
        exit;
    }
```

Then in `register_hooks()` (~line 161), directly after the `redirect_legacy_quick_order_slug` registration line, add:

```php
        add_action('admin_init', [$this, 'redirect_retired_tabs']);
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/test-retired-tab-redirects.php
```

Expected: `OK: 6 assertions passed`, exit 0.

- [ ] **Step 5: Lint and commit**

```bash
php -l includes/class-admin-ui.php
git add tests/test-retired-tab-redirects.php includes/class-admin-ui.php
git commit -m "feat(admin): redirect map for retired main-page tabs (slips, po)"
```

---

### Task 2: Slips merge — on-demand section on the Packing Slips page

**Files:**
- Modify: `includes/admin/class-slip-batch-page.php` (render + enqueue)
- Modify: `views/daily-slips.php` (doc header only)
- Modify: `includes/class-admin-ui.php` (retire the `slips` tab: 3 sites)

- [ ] **Step 1: Render the on-demand section on the batch page**

In `includes/admin/class-slip-batch-page.php`, `render()` currently calls `render_generate_form()` then `render_history_table()`. Add a third call after them:

```php
        self::render_generate_form();
        self::render_history_table();
        self::render_on_demand_section();
```

Then add this method after `render_history_table()` (before `render_row()`):

```php
    // -----------------------------------------------------------------
    // On-demand PDFs (merged Daily Slips)
    // -----------------------------------------------------------------

    /**
     * On-demand slip PDFs — the retired Daily Slips tab, relocated here
     * (admin UI consolidation spec 2026-07-16 §4: "batches won"). Renders
     * views/daily-slips.php inside a collapsed <details>: immediate
     * packer/driver PDFs by zone + date range or by delivery day, streamed
     * to the browser. Nothing is saved — batch history/cancel above does
     * not apply to these.
     */
    private static function render_on_demand_section(): void {
        echo '<hr style="margin:24px 0;" />';
        echo '<details id="mealsdb-on-demand-slips">';
        echo '<summary style="cursor:pointer;"><strong>'
            . esc_html__('On-demand PDFs (not saved)', 'meals-db')
            . '</strong></summary>';
        include MealsDB_Plugin::path('views/daily-slips.php');
        echo '</details>';
    }
```

- [ ] **Step 2: Enqueue the daily-slips assets on this page**

Still in `class-slip-batch-page.php`, at the end of `enqueue_scripts()` (after the existing `wp_add_inline_script(...)` call), add:

```php
        // On-demand section (merged Daily Slips, spec 2026-07-16): the view
        // emits the #mealsdb-daily-slips-data JSON island; daily-slips.js
        // reads it by element id. report-utils supplies the shared status
        // helper. The main page used to enqueue this per-tab — that site is
        // retired along with the tab.
        $report_utils = class_exists('MealsDB_Admin_UI')
            ? MealsDB_Admin_UI::register_report_utils_script()
            : 'jquery';

        wp_enqueue_script(
            'mealsdb-daily-slips-js',
            plugins_url('assets/js/daily-slips.js', dirname(dirname(__FILE__))),
            ['jquery', $report_utils],
            defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : false,
            true
        );
```

- [ ] **Step 3: Update the daily-slips view's doc header**

In `views/daily-slips.php`, replace the header comment block (lines 2–11, the `/** ... */` before `defined('ABSPATH')`) with:

```php
/**
 * On-demand slip PDFs — embedded section of the Packing Slips page
 * (MealsDB_Slip_Batch_Page::render_on_demand_section(), spec 2026-07-16).
 * Formerly the main page's Daily Slips tab (Phase T), retired in the admin
 * UI consolidation PR 2.
 *
 * Two PDF outputs per generation request:
 *   - Packer slips (no financial info, right column reserved for notes)
 *   - Driver slips (packer slip + collection breakdown + customer info)
 *
 * Slip-mode toggle (zone + date range vs. single delivery day) is
 * preserved from Phase Q. The view stays self-guarding (enforce() +
 * manage_options wp_die) so a future includer can't skip the gate.
 */
```

Do NOT touch the rest of the file — the gates, controls, and JSON island move as-is.

- [ ] **Step 4: Retire the `slips` tab (3 sites in class-admin-ui.php)**

1. `render_tabs()` (~line 1150): delete the line `'slips'   => __('Daily Slips', 'meals-db'),`
2. `render_main_page()` (~line 1062): delete the three lines:
```php
            case 'slips':
                include MealsDB_Plugin::path('views/daily-slips.php');
                break;
```
3. `enqueue_tab_view_scripts()` (~line 124): delete the three lines:
```php
            case 'slips':
                $enqueue('daily-slips', [self::register_report_utils_script()]);
                break;
```

- [ ] **Step 5: Lint, test, commit**

```bash
php -l includes/admin/class-slip-batch-page.php && php -l views/daily-slips.php && php -l includes/class-admin-ui.php
php tests/test-retired-tab-redirects.php
php tests/test-ajax-slip-batch.php
git add includes/admin/class-slip-batch-page.php views/daily-slips.php includes/class-admin-ui.php
git commit -m "feat(slips): fold Daily Slips into the Packing Slips page as a collapsed on-demand section; retire the slips tab"
```

Expected: both tests exit 0 (`OK: 6 assertions passed`; slip-batch test unchanged/green).

---

### Task 3: PO merge — Generate button on the list; retire the po tab

**Files:**
- Modify: `views/purchase-orders.php` (list header + island i18n)
- Modify: `assets/js/purchase-orders.js` (generate handler)
- Modify: `includes/class-admin-ui.php` (retire the `po` tab: 3 sites)
- Delete: `views/purchase-order.php`, `assets/js/purchase-order.js`

- [ ] **Step 1: Verify the island renders on the list view**

```bash
grep -n 'mealsdb_po_render_island(' views/purchase-orders.php
```

Expected: at least two call sites — one in the detail branch, one at the end of the list view. If the list view does NOT call it, stop and report (the generate handler depends on `cfg` from that island).

- [ ] **Step 2: Add the Generate button + forecast note to the list header**

In `views/purchase-orders.php` (list view, ~line 340), replace:

```php
    <h2><?php esc_html_e('Purchase Orders', 'meals-db'); ?></h2>
    <p class="description"><?php esc_html_e('Drafts are created from the Purchase Order tab ("Generate draft PO") and arrive pallet-optimized. Approve locks a draft; Mark received adds it to inventory; Reconcile records what actually arrived.', 'meals-db'); ?></p>
```

with:

```php
    <h2><?php esc_html_e('Purchase Orders', 'meals-db'); ?></h2>

    <div class="mealsdb-po-controls" style="margin-bottom:12px;">
        <button type="button" class="button button-primary" id="mealsdb-po-generate">
            <?php esc_html_e('Generate draft PO', 'meals-db'); ?>
        </button>
    </div>

    <p class="description"><?php esc_html_e('Generate creates a seasonally-adjusted, pallet-optimized draft and opens it for review. Approve locks a draft; Mark received adds it to inventory; Reconcile records what actually arrived.', 'meals-db'); ?></p>

    <details style="margin-bottom:12px;">
        <summary class="description" style="cursor:pointer;"><?php esc_html_e('How the forecast works', 'meals-db'); ?></summary>
        <p class="description"><?php esc_html_e('Fixed model, validated by back-test: 12-week recency-weighted history, 6-week order horizon plus a 3-week demand-proportional safety buffer (9 weeks of coverage), seasonal index clamped to 0.3–3.0. The order is snapped to whole Apetito pallets (75 cases): filled up if the partial pallet is at least a third full, otherwise trimmed — within a 7–52 week coverage guard. Not configurable.', 'meals-db'); ?></p>
    </details>
```

- [ ] **Step 3: Add the two i18n keys the generate handler needs to the island**

In the same file, inside the `$mealsdb_po_render_island` closure's `'i18n' => [` array (~line 37), add after the `'saving'` entry:

```php
            'generating'      => __('Generating…', 'meals-db'),
            'draftSaveFailed' => __('Could not save the draft purchase order.', 'meals-db'),
```

- [ ] **Step 4: Move the generate handler into purchase-orders.js**

In `assets/js/purchase-orders.js`, at the END of the IIFE (directly before the closing `})(jQuery);`), add:

```js
    // ------------------------------------------------------------------
    // Generate draft PO — merged from the retired Purchase Order tab
    // (purchase-order.js, deleted in the same change). The server
    // REGENERATES the forecast rows and pallet-optimizes them; the browser
    // never supplies row data. On success, open the new draft's detail page.
    // ------------------------------------------------------------------
    $('#mealsdb-po-generate').on('click', function () {
        // Disabled while in flight: a double-click must not create two drafts.
        var $btn = $(this).prop('disabled', true);
        msg(t('generating', 'Generating…'), false);
        $.post(cfg.ajaxUrl, {
            action: 'mealsdb_po_save_draft',
            nonce: cfg.nonce || ''
        }, function (res) {
            if (res && res.success && res.data && res.data.po_id) {
                window.location.href = (cfg.baseUrl || '') + '&po_id=' + parseInt(res.data.po_id, 10);
                return;
            }
            $btn.prop('disabled', false);
            msg((res && res.data && res.data.message) || t('draftSaveFailed', 'Could not save the draft purchase order.'), true);
        }).fail(function () {
            $btn.prop('disabled', false);
            msg(t('requestFailed', 'Request failed.'), true);
        });
    });
```

(`cfg`, `t()`, and `msg()` already exist at the top of this file; `cfg.baseUrl` is `admin.php?page=mealsdb&tab=po_admin`, so the `&po_id=` concatenation is safe. PR 3 changes only the island's `baseUrl` value, not this code.)

- [ ] **Step 5: Retire the `po` tab and delete the merged-away files**

1. `includes/class-admin-ui.php` `render_tabs()` (~line 1156): delete the line `'po'      => __('Purchase Order', 'meals-db'),`
2. `render_main_page()` (~line 1066): delete the three lines:
```php
            case 'po':
                include MealsDB_Plugin::path('views/purchase-order.php');
                break;
```
3. `enqueue_tab_view_scripts()` (~line 127): delete the three lines:
```php
            case 'po':
                $enqueue('purchase-order', [self::register_report_utils_script()]);
                break;
```
4. Delete the files:
```bash
git rm views/purchase-order.php assets/js/purchase-order.js
```

- [ ] **Step 6: Lint, check, commit**

```bash
php -l views/purchase-orders.php && php -l includes/class-admin-ui.php
node --check assets/js/purchase-orders.js 2>/dev/null || echo "node unavailable — visually verify the JS diff"
grep -rn "purchase-order\.js\|purchase-order\.php\|tab=po'\|tab=po\"" includes/ views/ assets/ --include="*.php" --include="*.js" | grep -v purchase-orders || echo "no stale references"
git add views/purchase-orders.php assets/js/purchase-orders.js includes/class-admin-ui.php
git commit -m "feat(po): Generate draft PO button on the PO list; retire the generate-only tab"
```

Expected: lints clean; grep prints `no stale references`.

---

### Task 4: Full-suite verification and PR

**Files:** none

- [ ] **Step 1: Run the full suite**

```bash
php tests/test-retired-tab-redirects.php
for f in tests/test-*.php; do php "$f" >/dev/null 2>&1 || echo "FAIL: $f"; done
```

Expected: `OK: 6 assertions passed`; the loop reports only the 2 known-baseline PDF failures (`test-pdf-slip-binary-output.php`, `test-vac-pdf.php`). Any OTHER failure blocks the PR.

- [ ] **Step 2: Manual smoke checklist (record in the PR body as done-or-deferred-to-staging)**

1. Main page shows 8 tabs (no Daily Slips, no Purchase Order); `?page=mealsdb&tab=slips` redirects to Packing Slips; `?page=mealsdb&tab=po` redirects to `tab=po_admin`.
2. Packing Slips page: batch generate/history unchanged; the collapsed "On-demand PDFs (not saved)" section expands, both modes toggle, packer + driver PDFs download (endpoints unchanged).
3. PO list: "Generate draft PO" creates a draft and lands on its detail page; double-click can't create two drafts; failure path shows the message in `#mealsdb-po-action-msg`.
4. Existing PO list/detail actions (Approve / Cancel / Receive / Reconcile) still work — same JS file, verify no regression.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feat/slips-po-merge
gh pr create --base main --head feat/slips-po-merge --title "feat(admin): slips + PO merges (UI consolidation PR 2)" --body "$(cat <<'EOF'
## Summary
- **Packing Slips** is now the single slips destination: the Daily Slips tab is retired and its full capability (zone+date-range / by-day modes, packer + driver PDFs) lives on as a collapsed "On-demand PDFs (not saved)" section on the batch page — same view, same JS, same endpoints
- **Purchase Orders list** gains the "Generate draft PO" button (same `mealsdb_po_save_draft` action + nonce, handler moved into purchase-orders.js); the generate-only Purchase Order tab is retired and its view/JS deleted
- **Legacy redirects:** `?page=mealsdb&tab=slips` → Packing Slips; `?page=mealsdb&tab=po` → `tab=po_admin`, via a pure unit-tested map (`MealsDB_Admin_UI::retired_tab_target`)
- Main page drops from 10 tabs to 8; no AJAX endpoint, capability, nonce, or rate-limit changes
- PR 2 of 4: `docs/superpowers/specs/2026-07-16-admin-ui-consolidation-design.md`

## Test plan
- [ ] `php tests/test-retired-tab-redirects.php` (6 assertions)
- [ ] Full standalone suite green except the 2 known local PDF baseline failures
- [ ] Staging smoke: redirects; on-demand section renders/downloads on Packing Slips; Generate button creates + opens a draft; existing PO actions unregressed

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Merge on request only; CI owns version bumps — do NOT bump `MEALS_DB_VERSION`.
