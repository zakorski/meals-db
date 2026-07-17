# Advanced-Tools Menu Toggle Implementation Plan (Admin UI Consolidation, PR 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a global "Show advanced tools" checkbox to Settings (under Shadow Mode) that hides/shows the Rate Definitions, Data Ops, and Migration menu items — hidden by default, pages still reachable by direct URL.

**Architecture:** A new stateless helper class `MealsDB_Advanced_Tools` reads a `show_advanced_tools` key inside the existing `mealsdb_settings` option and, on a late `admin_menu` hook, removes the three governed submenu entries via `remove_submenu_page()` when the toggle is off. `remove_submenu_page()` removes only the menu *entry* — the pages stay registered with their original hook suffixes, so direct URLs, asset enqueues, and every existing capability gate keep working untouched. The checkbox is persisted through the existing `mealsdb_save_settings` AJAX handler exactly like `shadow_mode` (explicit `'0'`/`'1'`), but with the **opposite fail-safe**: anything other than an explicit "on" means hidden.

**Tech Stack:** WordPress admin menu API, existing `mealsdb_settings` option + `MealsDB_Ajax_Settings::save_settings()`, jQuery (`assets/js/settings.js`), standalone-PHP test convention (`php tests/test-*.php`, no PHPUnit).

**Spec:** `docs/superpowers/specs/2026-07-16-admin-ui-consolidation-design.md` (§5, §PR 1)

**Reference facts (verified against the code 2026-07-16):**
- Governed submenu slugs and their `admin_menu` registration priorities:
  - `mealsdb-data-ops` — registered in `MealsDB_Admin_UI::register_menu()` (priority 10)
  - `mealsdb-migration` — `MealsDB_Migration_Page::register_menu()` (priority 22)
  - `mealsdb_rate_definitions` — `MealsDB_Rate_Definitions_Page::register_menu()` (priority 23, `MealsDB_Rate_Definitions_Page::PAGE_SLUG`)
- Shadow mode's storage pattern to mirror: `includes/class-shadow-mode.php` (key in `mealsdb_settings`), persisted at `includes/ajax/class-ajax-settings.php:221`, checkbox at `views/settings.php:54`, JS payload at `assets/js/settings.js:418`.
- Cron Status and Event Log are **deliberately not governed** (design decision).
- Tests are standalone scripts: define `ABSPATH`, register `MealsDB_Autoloader`, stub WP functions, `assert_equal`/`assert_true` helpers, exit 0/1 (see `tests/test-hook-logger.php`).
- Known local baseline: 2 PDF tests fail for lack of mbstring/imagick — not caused by this work.

---

### Task 0: Create the feature branch

**Files:** none

- [ ] **Step 1: Branch from main**

```bash
cd /mnt/fastssd/meals-db && git checkout -b feat/advanced-tools-toggle
```

Note: the working tree has pre-existing uncommitted changes under `directives/` that belong to the operator. Do NOT stage or commit them. Every commit in this plan stages explicit paths only.

---

### Task 1: `MealsDB_Advanced_Tools` helper (TDD)

**Files:**
- Test: `tests/test-advanced-tools.php` (create)
- Create: `includes/class-advanced-tools.php`
- Modify: `meals-db-main.php` (one `init()` call)

- [ ] **Step 1: Write the failing test**

Create `tests/test-advanced-tools.php` with exactly this content:

```php
<?php
/**
 * Tests for MealsDB_Advanced_Tools — the settings-driven visibility toggle
 * for rare/destructive admin pages (admin UI consolidation spec 2026-07-16,
 * PR 1). Covers:
 *   - is_enabled() fail-safe semantics: default is HIDDEN — only an
 *     explicit truthy stored value shows the tools
 *   - maybe_hide_governed_menu_items() removes exactly the three governed
 *     submenu entries when disabled, and nothing when enabled
 *
 * Run with: php tests/test-advanced-tools.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// --- WP stubs ----------------------------------------------------------
$GLOBALS['test_options'] = [];
function get_option(string $name, $default = false) {
    return array_key_exists($name, $GLOBALS['test_options'])
        ? $GLOBALS['test_options'][$name]
        : $default;
}
function add_action($hook, $cb, $priority = 10, $args = 1) { return true; }
$GLOBALS['removed_submenus'] = [];
function remove_submenu_page($parent, $slug) {
    $GLOBALS['removed_submenus'][] = [$parent, $slug];
    return true;
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
// is_enabled() — fail-safe is HIDDEN (opposite of shadow mode's fail-safe ON).
// ---------------------------------------------------------------------------
$GLOBALS['test_options'] = [];
assert_equal(false, MealsDB_Advanced_Tools::is_enabled(), 'missing option => disabled');

$GLOBALS['test_options'] = ['mealsdb_settings' => 'garbage'];
assert_equal(false, MealsDB_Advanced_Tools::is_enabled(), 'non-array option => disabled');

$GLOBALS['test_options'] = ['mealsdb_settings' => ['shadow_mode' => '1']];
assert_equal(false, MealsDB_Advanced_Tools::is_enabled(), 'key absent => disabled');

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => '0']];
assert_equal(false, MealsDB_Advanced_Tools::is_enabled(), "explicit '0' => disabled");

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => '1']];
assert_equal(true, MealsDB_Advanced_Tools::is_enabled(), "explicit '1' => enabled");

// ---------------------------------------------------------------------------
// maybe_hide_governed_menu_items()
// ---------------------------------------------------------------------------
$GLOBALS['test_options'] = [];
$GLOBALS['removed_submenus'] = [];
MealsDB_Advanced_Tools::maybe_hide_governed_menu_items();
assert_equal(
    [
        ['mealsdb', 'mealsdb_rate_definitions'],
        ['mealsdb', 'mealsdb-data-ops'],
        ['mealsdb', 'mealsdb-migration'],
    ],
    $GLOBALS['removed_submenus'],
    'disabled => the three governed submenu entries removed from mealsdb'
);

$GLOBALS['test_options'] = ['mealsdb_settings' => ['show_advanced_tools' => '1']];
$GLOBALS['removed_submenus'] = [];
MealsDB_Advanced_Tools::maybe_hide_governed_menu_items();
assert_equal([], $GLOBALS['removed_submenus'], 'enabled => nothing removed');

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
php tests/test-advanced-tools.php
```

Expected: PHP fatal error `Class "MealsDB_Advanced_Tools" not found` (the autoloader finds no `includes/class-advanced-tools.php`). Exit code non-zero.

- [ ] **Step 3: Write the implementation**

Create `includes/class-advanced-tools.php` with exactly this content:

```php
<?php
/**
 * Advanced-tools menu visibility toggle (admin UI consolidation spec
 * 2026-07-16, PR 1).
 *
 * Rarely-used / destructive admin pages (Rate Definitions, Data Ops,
 * Migration) are hidden from the Meals DB menu unless the operator has
 * ticked "Show advanced tools" in Settings. Hiding is a CONVENIENCE, not
 * a security layer: the pages stay registered (direct URLs and bookmarks
 * keep working) and every governed page keeps its own capability gate,
 * which is enforced on render regardless of menu visibility.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Advanced_Tools {

    /** Key within the mealsdb_settings option array. */
    const SETTING_KEY = 'show_advanced_tools';

    /**
     * Submenu slugs governed by the toggle, in menu order. Cron Status and
     * Event Log are deliberately NOT governed — they are how the team
     * notices breakage (design decision, spec 2026-07-16).
     */
    const GOVERNED_SLUGS = [
        'mealsdb_rate_definitions',
        'mealsdb-data-ops',
        'mealsdb-migration',
    ];

    public static function init(): void {
        // Priority 99: the governed pages register their menu entries at
        // admin_menu 10 (Data Ops via MealsDB_Admin_UI), 22 (Migration)
        // and 23 (Rate Definitions); removal must run after all of them.
        add_action('admin_menu', [self::class, 'maybe_hide_governed_menu_items'], 99);
    }

    /**
     * Whether advanced tools are shown in the menu. Opposite fail-safe to
     * shadow mode: anything other than an explicit, readable "on" keeps
     * the tools HIDDEN (missing option, non-array option, absent key,
     * '0'/''/0/false all mean hidden).
     */
    public static function is_enabled(): bool {
        $settings = get_option('mealsdb_settings', null);
        if (!is_array($settings)) {
            return false;
        }
        // empty() treats '0', '', 0, false and an absent key all as "off".
        return !empty($settings[self::SETTING_KEY]);
    }

    /**
     * Remove the governed submenu entries when the toggle is off.
     *
     * remove_submenu_page() only removes the MENU ENTRY — the page stays
     * registered and reachable at admin.php?page={slug} with its original
     * hook suffix, so asset enqueues keyed on the hook and the pages' own
     * capability checks are untouched.
     */
    public static function maybe_hide_governed_menu_items(): void {
        if (self::is_enabled()) {
            return;
        }
        if (!function_exists('remove_submenu_page')) {
            return;
        }
        foreach (self::GOVERNED_SLUGS as $slug) {
            remove_submenu_page('mealsdb', $slug);
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/test-advanced-tools.php
```

Expected: `OK: 7 assertions passed`, exit code 0.

- [ ] **Step 5: Wire into the bootstrap**

In `meals-db-main.php`, find the line `MealsDB_Ajax_Settings::init();` (inside the plugins_loaded bootstrap, around line 112) and insert directly after it:

```php
    // Admin UI consolidation (spec 2026-07-16) PR 1 — hide rare/destructive
    // pages (Rate Definitions, Data Ops, Migration) from the menu unless the
    // Settings "Show advanced tools" toggle is on. Hidden pages remain
    // URL-reachable; their capability gates are unchanged.
    MealsDB_Advanced_Tools::init();
```

- [ ] **Step 6: Lint and commit**

```bash
php -l includes/class-advanced-tools.php && php -l meals-db-main.php
git add tests/test-advanced-tools.php includes/class-advanced-tools.php meals-db-main.php
git commit -m "feat(admin): advanced-tools visibility helper — hides Rate Definitions, Data Ops, Migration menu entries by default"
```

---

### Task 2: Persist the toggle in the settings save handler

**Files:**
- Modify: `includes/ajax/class-ajax-settings.php` (~line 222, directly after the `shadow_mode` assignment)

- [ ] **Step 1: Add the key to `save_settings()`**

In `MealsDB_Ajax_Settings::save_settings()`, find:

```php
        $settings[MealsDB_Shadow_Mode::SETTING_KEY] =
            empty($_POST['shadow_mode']) ? '0' : '1';
```

and insert directly after it:

```php
        // Advanced-tools menu visibility (admin UI consolidation spec
        // 2026-07-16). Same explicit '0'/'1' storage as shadow_mode, but
        // the OPPOSITE fail-safe: absent/unreadable means HIDDEN.
        $settings[MealsDB_Advanced_Tools::SETTING_KEY] =
            empty($_POST['show_advanced_tools']) ? '0' : '1';
```

- [ ] **Step 2: Lint and commit**

```bash
php -l includes/ajax/class-ajax-settings.php
git add includes/ajax/class-ajax-settings.php
git commit -m "feat(admin): persist show_advanced_tools through the settings save handler"
```

(No new endpoint, nonce, capability check, or rate limit — the existing `mealsdb_save_settings` guards [`manage_options`, `settings_modify` bucket, `mealsdb_settings_nonce`] cover the new key.)

---

### Task 3: Settings UI — checkbox row and JS payload

**Files:**
- Modify: `views/settings.php` (variable near line 18; new `<tr>` after the shadow-mode row, ~line 62)
- Modify: `assets/js/settings.js` (save payload, ~line 418)

- [ ] **Step 1: Add the effective-state variable to the view**

In `views/settings.php`, directly after the `$shadow_on = ...` assignment (ends around line 18), add:

```php
// Advanced-tools toggle state (fail-safe HIDDEN — see MealsDB_Advanced_Tools).
$advanced_on = class_exists( 'MealsDB_Advanced_Tools' )
    ? MealsDB_Advanced_Tools::is_enabled()
    : false;
```

- [ ] **Step 2: Add the checkbox row**

In the Shadow Mode `form-table`, directly after the closing `</tr>` of the shadow-mode row (before `</tbody>`), add:

```php
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Advanced tools', 'meals-db' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_advanced_tools" value="1" <?php checked( $advanced_on ); ?> />
                            <?php echo esc_html__( 'Show advanced tools (Rate Definitions, Data Ops, Migration) in the menu', 'meals-db' ); ?>
                        </label>
                        <p class="description">
                            <?php echo esc_html__( 'Off by default to keep the menu focused on daily work. Hiding is a convenience, not security — the pages stay reachable by direct URL and keep their own permission checks.', 'meals-db' ); ?>
                        </p>
                    </td>
                </tr>
```

- [ ] **Step 3: Include the field in the JS save payload**

In `assets/js/settings.js`, in the `$.post(ajaxUrl, { action: 'mealsdb_save_settings', ... })` payload, directly after the `shadow_mode:` line (~418), add:

```js
            // Advanced-tools menu visibility — same explicit '0'/'1'
            // convention as shadow_mode (server treats absent as '0').
            show_advanced_tools: $('input[name="show_advanced_tools"]').is(':checked') ? '1' : '0',
```

- [ ] **Step 4: Lint and commit**

```bash
php -l views/settings.php
node --check assets/js/settings.js 2>/dev/null || echo "node unavailable — visually verify the JS diff"
git add views/settings.php assets/js/settings.js
git commit -m "feat(admin): 'Show advanced tools' checkbox in Settings under Shadow Mode"
```

---

### Task 4: Full-suite verification and PR

**Files:** none

- [ ] **Step 1: Run the new test plus the full suite**

```bash
php tests/test-advanced-tools.php
for f in tests/test-*.php; do php "$f" >/dev/null 2>&1 || echo "FAIL: $f"; done
```

Expected: `OK: 7 assertions passed`; the loop reports only the 2 known-baseline PDF failures (mbstring/imagick missing locally — see memory note). Any OTHER failure blocks the PR.

- [ ] **Step 2: Manual smoke checklist (record in the PR body as done-or-deferred-to-staging)**

1. With the option unset: Meals DB menu shows no Rate Definitions / Data Ops / Migration; `admin.php?page=mealsdb-data-ops`, `?page=mealsdb-migration`, `?page=mealsdb_rate_definitions` still render for an admin.
2. Tick the checkbox in Settings → Save → the three items appear in the menu on next page load.
3. Untick → Save → they disappear again.
4. Confirm the Migration page's CSS/JS still load when visited hidden (its enqueue keys on the unchanged hook suffix `meals-db_page_mealsdb-migration`).

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feat/advanced-tools-toggle
gh pr create --title "feat(admin): advanced-tools menu toggle (UI consolidation PR 1)" --body "$(cat <<'EOF'
## Summary
- New Settings checkbox (under Shadow Mode): **Show advanced tools** — global, stored in `mealsdb_settings.show_advanced_tools`, default OFF
- When off, the **Rate Definitions, Data Ops, Migration** menu entries are removed via `remove_submenu_page()` on a late `admin_menu` hook; pages stay registered and URL-reachable with unchanged capability gates
- Cron Status and Event Log are deliberately not governed
- PR 1 of 4 for the admin UI consolidation: docs/superpowers/specs/2026-07-16-admin-ui-consolidation-design.md

## Test plan
- [ ] `php tests/test-advanced-tools.php` (7 assertions: fail-safe-hidden semantics + exact removal set)
- [ ] Full standalone suite green apart from the 2 known local PDF baseline failures
- [ ] Manual smoke on staging: menu hides/shows with the toggle; hidden pages reachable by URL; Migration assets still enqueue

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Merge on request only (operator's workflow); CI owns version bumps — do NOT bump `MEALS_DB_VERSION` (no schema change here anyway).

---

## Amendment (2026-07-16, post-review)

Code review of Task 1 found the plan's `remove_submenu_page()` approach **broken
for plugin pages**: removing the submenu entry after registration makes
`user_can_access_admin_page()` resolve an unregistered hookname
(`admin_page_{slug}` vs the registered `meals-db_page_{slug}`), 403-ing the
governed pages for everyone — including admins — whenever the toggle is off
(the default). Verified empirically against WP core.

**Corrected architecture (matches spec §5's original prescription):** the
toggle governs the *parent slug at registration time*.
`MealsDB_Advanced_Tools::menu_parent()` returns `'mealsdb'` when enabled and
`''` when disabled; the three governed `register_menu()` methods pass it as
`add_submenu_page()`'s first argument. `''` registers the page hook without a
menu entry (WP's hidden-page pattern), so direct URLs keep working. There is
no `admin_menu` hook and no bootstrap wiring anymore.

Knock-on: the page hook suffix differs by state (`meals-db_page_{slug}`
visible, `admin_page_{slug}` hidden), so the Migration and Data Ops enqueue
checks now accept both suffixes. Task 4's smoke item 4 must verify Migration's
assets load in BOTH toggle states.
