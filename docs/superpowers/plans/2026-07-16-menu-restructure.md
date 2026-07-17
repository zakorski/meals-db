# Menu Restructure Implementation Plan (Admin UI Consolidation, PR 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dissolve the main page's remaining 8 tabs into dedicated submenu pages (Clients, Tasks, Settings, Purchase Orders), make the `mealsdb` slug a minimal Home shell, and keep every legacy URL working via an args-preserving redirect map.

**Architecture:** Four new submenu pages render the existing view files unchanged (the Clients page adds a 3-tab subnav: List / Add / Sync, with the drafts list embedded as a collapsed panel under Add and Ignored Conflicts as a `view=ignored` sub-view under Sync). `MealsDB_Admin_UI::enqueue_assets()` translates the new page hooks back into the legacy `$tab`/`$action` vocabulary its asset blocks are keyed on, so per-view asset logic is unchanged. The PR 2 redirect map is redesigned to take the full query array and preserve extra args (`client_id`, `po_id`, `task_id`, `paged`, filters). A canonical-slug submenu re-order (late `admin_menu` hook, pure sorted function, TDD) produces the spec's menu order without touching the other page classes' registration priorities.

**Tech Stack:** WordPress admin pages/enqueue API, standalone-PHP test convention (`php tests/test-*.php`).

**Spec:** `docs/superpowers/specs/2026-07-16-admin-ui-consolidation-design.md` §1 (Home shell only — widgets are PR 4), §3, §5 (Settings page), §6, §7, rollout PR 3.

**Reference facts (verified against the code 2026-07-16, post-PR #466 branch):**
- `render_main_page()` (class-admin-ui.php ~1073) routes 8 tabs: sync, add, clients(+edit), drafts, ignored, tasks(+detail/rules), po_admin, settings. `render_tabs()` (~1201) seeds the tab array; `views/partials/tabs.php` is its only includer.
- `render_subnav(string $page, array $subtabs, string $active)` (~1060) builds `admin.php?page={page}&sub={slug}` links — needs a `$param` arg to serve the Clients page's `tab` param.
- `enqueue_assets($hook)` (~384): early-returns for staff/quick-order/reports/data-ops pages; the tail block reads `$_GET['tab']`/`$_GET['action']` and enqueues admin.css, client-form.css (add/edit), notice script, admin.js, task-form.js (tasks), client-actions.js, client-initials.js, client-wp-user.js + zone-day (add/edit), allocation-history (edit), then `enqueue_report_scripts($tab)` (settings.js when tab=settings) and `enqueue_tab_view_scripts($tab, $action)` (drafts/ignored/po_admin/tasks cases).
- Complete inventory of legacy-URL construction sites (grep `page=mealsdb` excluding `mealsdb-`/`mealsdb_`): class-admin-ui.php:293 (po redirect target), :835 (fee-recon editUrl); views/ignored.php:114; views/purchase-orders.php:26, :304; views/tasks-list.php:67; views/view-clients.php:54–55; views/task-rules.php:15; views/task-detail.php:15, :52; views/partials/dashboard-tasks-widget.php:41; views/drafts.php:90, :114; views/partials/tabs.php:18 (deleted). NO JS file builds tab URLs.
- `views/drafts.php` lists ONLY the current operator's drafts (`created_by = get_current_user_id()`), paginates via `?paged=`, and posts the resume form to `?page=mealsdb&tab=add`. `views/ignored.php` paginates via `?paged=` with base `tab=ignored`.
- Drafts count query pattern (owner-scoped COUNT on `MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS)`) exists in drafts.php:23; ignored count on `MealsDB_Tables::IGNORED_CONFLICTS` in ignored.php:17.
- Submenu order today: Home, Staff, Quick Order, Reports, Data Ops (register_menu, prio 10), Cron Status (20), Event Log (21), Invoices/Packing Slips/Migration (22), Rate Definitions (23). Advanced-tools toggle (PR #465): Rate Definitions/Data Ops/Migration register with `MealsDB_Advanced_Tools::menu_parent()` (`''` when hidden) — a hidden page never appears in `$submenu['mealsdb']`, so the re-order function never sees it (harmless).
- `views/settings.php` self-gates `manage_options` (returns early); `views/purchase-orders.php` list/detail have their own `<h2>`s.
- PR 2 execution notes that still apply: subagents must NEVER `git checkout <commit>` (use `git show`); nothing under `directives/` may be staged; local baseline: 2 PDF tests fail (mbstring/imagick).

---

### Task 0: Create the feature branch

**Files:** none

- [ ] **Step 1: Confirm PR #466 is merged, then branch**

```bash
cd /mnt/fastssd/meals-db && git checkout main && git stash push -m "operator directives (auto-restore)" -- directives/ 2>/dev/null; git pull --ff-only && git log --oneline -8 | grep -i "slips + PO\|slips-po" && git checkout -b feat/menu-restructure && git stash pop 2>/dev/null; git log --oneline -2
```

If the grep finds no slips-po merge commit on main, STOP: PR #466 has not merged — report BLOCKED (this plan builds directly on it). The stash push/pop protects the operator's uncommitted `directives/` changes across the pull; if the stash was empty that's fine.

---

### Task 1: Page shells — registrations, render methods, subnav param, menu order (TDD)

**Files:**
- Test: `tests/test-menu-order.php` (create)
- Modify: `includes/class-admin-ui.php` (register_menu, render_subnav, 4 new render methods, MENU_ORDER + reorder, register_hooks)
- Create: `views/partials/drafts-panel.php`

- [ ] **Step 1: Write the failing menu-order test**

Create `tests/test-menu-order.php` with exactly this content:

```php
<?php
/**
 * Tests for MealsDB_Admin_UI::order_submenu_items() — the canonical
 * submenu ordering for the Meals DB menu (admin UI consolidation spec
 * 2026-07-16, PR 3). Registration priorities across six page classes are
 * left alone; a late admin_menu hook sorts $submenu['mealsdb'] into the
 * spec's order instead. Unknown slugs sort after all known ones, keeping
 * their relative order (so a future page never vanishes).
 *
 * Run with: php tests/test-menu-order.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// --- WP stub (class-admin-ui.php parse only; nothing else called) --------
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

/** Build a minimal WP submenu entry: [0]=title, [1]=cap, [2]=slug. */
function entry(string $slug): array {
    return [$slug . ' title', 'manage_woocommerce', $slug];
}

// ---------------------------------------------------------------------------
// Registration order (today's reality) sorts into the spec's menu order.
// ---------------------------------------------------------------------------
$registered = [
    entry('mealsdb'),
    entry('meals-db-staff'),
    entry('mealsdb_quick_order'),
    entry('mealsdb-clients'),
    entry('mealsdb-tasks'),
    entry('mealsdb-purchase-orders'),
    entry('mealsdb-settings'),
    entry('mealsdb-reports'),
    entry('mealsdb-data-ops'),
    entry('mealsdb_cron_status'),
    entry('mealsdb_event_log'),
    entry('mealsdb-invoices'),
    entry('mealsdb-packing-slips'),
    entry('mealsdb-migration'),
    entry('mealsdb_rate_definitions'),
];
$sorted = MealsDB_Admin_UI::order_submenu_items($registered);
assert_equal(
    [
        'mealsdb',
        'mealsdb_quick_order',
        'mealsdb-clients',
        'mealsdb-tasks',
        'mealsdb-packing-slips',
        'mealsdb-purchase-orders',
        'mealsdb-invoices',
        'mealsdb-reports',
        'meals-db-staff',
        'mealsdb_cron_status',
        'mealsdb_event_log',
        'mealsdb-settings',
        'mealsdb_rate_definitions',
        'mealsdb-data-ops',
        'mealsdb-migration',
    ],
    array_column($sorted, 2),
    'full registration set sorts into spec order'
);

// ---------------------------------------------------------------------------
// Unknown slugs land after all known ones, preserving relative order.
// ---------------------------------------------------------------------------
$with_unknown = [
    entry('future-page-b'),
    entry('mealsdb-tasks'),
    entry('future-page-a'),
    entry('mealsdb'),
];
$sorted = MealsDB_Admin_UI::order_submenu_items($with_unknown);
assert_equal(
    ['mealsdb', 'mealsdb-tasks', 'future-page-b', 'future-page-a'],
    array_column($sorted, 2),
    'unknown slugs: after known, original relative order kept'
);

// ---------------------------------------------------------------------------
// Entries survive untouched (only order changes) and missing slug index is
// tolerated.
// ---------------------------------------------------------------------------
$odd = [['Only title', 'read'], entry('mealsdb')];
$sorted = MealsDB_Admin_UI::order_submenu_items($odd);
assert_equal('mealsdb', $sorted[0][2] ?? '', 'slugless entry sorts after known entries');
assert_equal(['Only title', 'read'], $sorted[1], 'slugless entry passes through untouched');

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

- [ ] **Step 2: Run it — expect failure**

`php tests/test-menu-order.php` → fatal `Call to undefined method MealsDB_Admin_UI::order_submenu_items()`.

- [ ] **Step 3: Implement MENU_ORDER + sorter + reorder hook**

In `includes/class-admin-ui.php`, directly after the class's opening brace (before the first method), add:

```php
    /**
     * Canonical Meals DB submenu order (spec 2026-07-16 §menu). The pages
     * register across six classes at admin_menu priorities 10–23; rather
     * than re-prioritising them all, reorder_submenu() sorts the finished
     * $submenu['mealsdb'] array by this slug list on a late hook. The
     * toggle-governed tail (Rate Definitions, Data Ops, Migration) only
     * appears here when the advanced-tools toggle is on.
     */
    private const MENU_ORDER = [
        'mealsdb',                    // Home (the top-level's own entry)
        'mealsdb_quick_order',
        'mealsdb-clients',
        'mealsdb-tasks',
        'mealsdb-packing-slips',
        'mealsdb-purchase-orders',
        'mealsdb-invoices',
        'mealsdb-reports',
        'meals-db-staff',
        'mealsdb_cron_status',
        'mealsdb_event_log',
        'mealsdb-settings',
        'mealsdb_rate_definitions',
        'mealsdb-data-ops',
        'mealsdb-migration',
    ];

    /**
     * Sort WP submenu entries ([0]=title, [1]=cap, [2]=slug, …) into
     * MENU_ORDER. Unknown/slugless entries sort after every known slug,
     * keeping their original relative order — a future page can never
     * vanish because this list lags behind. Pure, for unit testing.
     */
    public static function order_submenu_items(array $items): array {
        $rank  = array_flip(self::MENU_ORDER);
        $after = count(self::MENU_ORDER);

        $decorated = [];
        foreach (array_values($items) as $i => $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            $decorated[] = [
                'rank' => $rank[$slug] ?? $after,
                'idx'  => $i,
                'item' => $item,
            ];
        }
        usort($decorated, static function (array $a, array $b): int {
            return ($a['rank'] <=> $b['rank']) ?: ($a['idx'] <=> $b['idx']);
        });

        return array_column($decorated, 'item');
    }

    /**
     * admin_menu@999: apply MENU_ORDER to the live submenu. Runs after
     * every registration (latest is priority 23) and after the
     * advanced-tools visibility resolution.
     */
    public function reorder_submenu(): void {
        global $submenu;
        if (isset($submenu['mealsdb']) && is_array($submenu['mealsdb'])) {
            $submenu['mealsdb'] = self::order_submenu_items($submenu['mealsdb']);
        }
    }
```

In `register_hooks()`, after the `redirect_retired_tabs` registration line, add:

```php
        add_action('admin_menu', [$this, 'reorder_submenu'], 999);
```

- [ ] **Step 4: Run the test — expect `OK: 4 assertions passed`.**

- [ ] **Step 5: Register the four new pages**

In `register_menu()`, directly after the Quick Order `add_submenu_page(...)` call, add:

```php
        // PR 3 (spec 2026-07-16): the main page's tabs become dedicated
        // pages. Visual order comes from reorder_submenu(), not from
        // registration order.
        add_submenu_page(
            'mealsdb',
            __('Clients', 'meals-db'),
            __('Clients', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb-clients',
            ['MealsDB_Admin_UI', 'render_clients_page']
        );

        add_submenu_page(
            'mealsdb',
            __('Tasks', 'meals-db'),
            __('Tasks', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb-tasks',
            ['MealsDB_Admin_UI', 'render_tasks_page']
        );

        add_submenu_page(
            'mealsdb',
            __('Purchase Orders', 'meals-db'),
            __('Purchase Orders', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb-purchase-orders',
            ['MealsDB_Admin_UI', 'render_po_page']
        );

        // Settings view self-gates manage_options; register the menu entry
        // at the same tier so non-admins don't see a dead link.
        add_submenu_page(
            'mealsdb',
            __('Settings', 'meals-db'),
            __('Settings', 'meals-db'),
            'manage_options',
            'mealsdb-settings',
            ['MealsDB_Admin_UI', 'render_settings_page']
        );
```

- [ ] **Step 6: Add the `$param` argument to render_subnav**

Replace the `render_subnav` method (~line 1060) with:

```php
    private static function render_subnav(string $page, array $subtabs, string $active, string $param = 'sub'): void {
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($subtabs as $slug => $label) {
            $url = admin_url('admin.php?page=' . $page . '&' . $param . '=' . $slug);
            $cls = 'nav-tab' . ($slug === $active ? ' nav-tab-active' : '');
            printf('<a href="%s" class="%s">%s</a>', esc_url($url), esc_attr($cls), esc_html($label));
        }
        echo '</h2>';
    }
```

- [ ] **Step 7: Add the four render methods**

Directly after `render_reports_page()`'s closing brace, add:

```php
    /**
     * Clients page (spec 2026-07-16 §3): List (with edit), Add (with the
     * resume-a-draft panel), and Sync (with Ignored Conflicts as a
     * view=ignored sub-view). The views are the former main-page tabs,
     * unchanged.
     */
    public static function render_clients_page(): void {
        MealsDB_Permissions::enforce();

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'list';
        $subtabs = [
            'list' => __('Client List', 'meals-db'),
            'add'  => __('Add Client', 'meals-db'),
            'sync' => __('WooCommerce Sync', 'meals-db'),
        ];
        if (!isset($subtabs[$tab])) {
            $tab = 'list';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Clients', 'meals-db') . '</h1>';
        self::render_subnav('mealsdb-clients', $subtabs, $tab, 'tab');
        echo '<div class="mealsdb-tab-content">';
        switch ($tab) {
            case 'add':
                include MealsDB_Plugin::path('views/partials/drafts-panel.php');
                include MealsDB_Plugin::path('views/add-client.php');
                break;

            case 'sync':
                $view = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
                if ($view === 'ignored') {
                    include MealsDB_Plugin::path('views/ignored.php');
                } else {
                    include MealsDB_Plugin::path('views/dashboard.php');
                }
                break;

            case 'list':
            default:
                $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
                if ($action === 'edit') {
                    include MealsDB_Plugin::path('views/edit-client.php');
                } else {
                    include MealsDB_Plugin::path('views/view-clients.php');
                }
                break;
        }
        echo '</div></div>';
    }

    /** Tasks page: list / detail / rules — the former tasks tab, unchanged. */
    public static function render_tasks_page(): void {
        MealsDB_Permissions::enforce();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Tasks', 'meals-db') . '</h1>';
        echo '<div class="mealsdb-tab-content">';
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
        if ($action === 'detail') {
            include MealsDB_Plugin::path('views/task-detail.php');
        } elseif ($action === 'rules') {
            include MealsDB_Plugin::path('views/task-rules.php');
        } else {
            include MealsDB_Plugin::path('views/tasks-list.php');
        }
        echo '</div></div>';
    }

    /** Settings page — the former settings tab, unchanged (view self-gates manage_options). */
    public static function render_settings_page(): void {
        MealsDB_Permissions::enforce();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Meals DB Settings', 'meals-db') . '</h1>';
        include MealsDB_Plugin::path('views/settings.php');
        echo '</div>';
    }

    /** Purchase Orders page — the former po_admin tab, unchanged. */
    public static function render_po_page(): void {
        MealsDB_Permissions::enforce();

        echo '<div class="wrap">';
        include MealsDB_Plugin::path('views/purchase-orders.php');
        echo '</div>';
    }
```

(No `<h1>` on the PO page — `views/purchase-orders.php` already renders its own `<h2>` headers for list and detail; adding an h1 above the list's "Purchase Orders" h2 would duplicate the title.)

- [ ] **Step 8: Create the drafts panel partial**

Create `views/partials/drafts-panel.php`:

```php
<?php
/**
 * Resume-a-draft panel — Add Client tab (spec 2026-07-16 §3). The former
 * top-level Drafts tab, demoted to a collapsed <details> above the add
 * form: visible only when the operator has saved drafts (the list is
 * owner-scoped, so the count must be too), rendering the existing
 * views/drafts.php list inside.
 */

defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

global $wpdb;
$mealsdb_drafts_table = MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS);
$mealsdb_draft_count  = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `{$mealsdb_drafts_table}` WHERE created_by = %d",
    get_current_user_id()
));

if ($mealsdb_draft_count > 0) : ?>
<details id="mealsdb-drafts-panel" style="margin-bottom:16px;">
    <summary style="cursor:pointer;"><strong>
        <?php echo esc_html(sprintf(
            /* translators: %d: number of the operator's saved client drafts */
            __('Resume a saved draft (%d)', 'meals-db'),
            $mealsdb_draft_count
        )); ?>
    </strong></summary>
    <?php include MealsDB_Plugin::path('views/drafts.php'); ?>
</details>
<?php endif; ?>
```

- [ ] **Step 9: Lint, test, commit**

```bash
php -l includes/class-admin-ui.php && php -l views/partials/drafts-panel.php
php tests/test-menu-order.php
php tests/test-retired-tab-redirects.php
git add tests/test-menu-order.php includes/class-admin-ui.php views/partials/drafts-panel.php
git commit -m "feat(admin): dedicated Clients/Tasks/Settings/Purchase Orders pages + canonical submenu order"
```

Expected: `OK: 4 assertions passed` and `OK: 6 assertions passed` (the PR 2 redirect test still passes — its map changes in Task 3).

---

### Task 2: Enqueue rekeying

**Files:**
- Modify: `includes/class-admin-ui.php` (`enqueue_assets()` head + one `enqueue_tab_view_scripts` case)

- [ ] **Step 1: Detect the new page hooks**

In `enqueue_assets()` (~line 384), the hook-detection block currently sets `$is_main_page`, `$is_staff_page`, `$is_quick_order_page`, `$is_reports_page`, `$is_data_ops_page`. Extend it — after the `$is_data_ops_page` assignment, add:

```php
        // PR 3 (spec 2026-07-16): the main page's tabs live on dedicated
        // pages now. Each new hook is translated back into the legacy
        // $tab/$action vocabulary below, so the per-view asset blocks are
        // unchanged.
        $is_clients_page  = ($hook === 'meals-db_page_mealsdb-clients');
        $is_tasks_page    = ($hook === 'meals-db_page_mealsdb-tasks');
        $is_settings_page = ($hook === 'meals-db_page_mealsdb-settings');
        $is_po_page       = ($hook === 'meals-db_page_mealsdb-purchase-orders');
```

and extend the gate:

```php
        if (!$is_main_page && !$is_staff_page && !$is_quick_order_page && !$is_reports_page && !$is_data_ops_page
            && !$is_clients_page && !$is_tasks_page && !$is_settings_page && !$is_po_page) {
            return;
        }
```

- [ ] **Step 2: Home early-return**

Directly after the `$is_data_ops_page` early-return block (`if ($is_data_ops_page) { ...; return; }`), add:

```php
        // Home shell (PR 3): title + quick-action buttons only — no admin
        // JS needed. PR 4's dashboard widgets will revisit this.
        if ($is_main_page) {
            return;
        }
```

(admin.css is enqueued above this point for every gated page, so Home keeps its styling.)

- [ ] **Step 3: Synthesize the legacy `$tab` from the new pages**

The existing code below reads `$tab` and `$action` from `$_GET`. Directly AFTER the existing `$tab`/`$action` sanitization block, add:

```php
        // Translate the dedicated pages into the legacy tab identities the
        // asset blocks below and the two dispatch helpers are keyed on.
        if ($is_clients_page) {
            if ($tab === 'add') {
                // keep 'add'
            } elseif ($tab === 'sync') {
                $view = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
                $tab  = ($view === 'ignored') ? 'ignored' : 'sync';
            } else {
                $tab = 'clients'; // list (default) + action=edit
            }
        } elseif ($is_tasks_page) {
            $tab = 'tasks';
        } elseif ($is_settings_page) {
            $tab = 'settings';
        } elseif ($is_po_page) {
            $tab = 'po_admin';
        }
```

- [ ] **Step 4: Drafts JS on the Add tab**

In `enqueue_tab_view_scripts()`, the `case 'drafts':` entry currently enqueues `drafts.js` for the retired Drafts tab. Replace that case with:

```php
            case 'add':
                // The Add tab hosts the resume-a-draft panel (spec §3);
                // its delete/confirm behaviour lives in drafts.js.
                $enqueue('drafts');
                break;
```

(The synthetic mapping never produces `'drafts'` anymore; `'ignored'`, `'po_admin'`, and `'tasks'` cases stay as they are.)

- [ ] **Step 5: Lint, smoke, commit**

```bash
php -l includes/class-admin-ui.php
php tests/test-menu-order.php && php tests/test-retired-tab-redirects.php
git add includes/class-admin-ui.php
git commit -m "feat(admin): rekey asset enqueue to the dedicated pages (legacy tab vocabulary preserved)"
```

---

### Task 3: Redirect map v2 (args-passthrough, TDD) + Home shell + tab retirement

**Files:**
- Test: `tests/test-retired-tab-redirects.php` (rewrite)
- Modify: `includes/class-admin-ui.php` (map, wrapper, render_main_page, delete render_tabs)
- Delete: `views/partials/tabs.php`

- [ ] **Step 1: Rewrite the redirect test**

Replace the entire contents of `tests/test-retired-tab-redirects.php` with:

```php
<?php
/**
 * Tests for MealsDB_Admin_UI::retired_tab_target() v2 — the args-preserving
 * legacy-URL map (admin UI consolidation spec 2026-07-16 §6, PR 3). Every
 * old ?page=mealsdb&tab=… URL maps to its dedicated page with extra query
 * args (client_id, po_id, task_id, paged, filters) preserved. The v1
 * (string,string) signature from PR 2 could not express arg preservation —
 * this is the redesign PR 2's review called for.
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

$base = 'https://example.test/wp-admin/admin.php?page=';

// ---------------------------------------------------------------------------
// Simple rows (no extra args).
// ---------------------------------------------------------------------------
assert_equal($base . 'mealsdb-clients&tab=sync',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'sync']), 'sync');
assert_equal($base . 'mealsdb-clients&tab=add',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'add']), 'add');
assert_equal($base . 'mealsdb-clients&tab=add',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'drafts']), 'drafts => add');
assert_equal($base . 'mealsdb-clients&tab=sync&view=ignored',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'ignored']), 'ignored => sync sub-view');
assert_equal($base . 'mealsdb-packing-slips',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'slips']), 'slips');
assert_equal($base . 'mealsdb-purchase-orders',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'po']), 'po => PO page (PR 3 target)');
assert_equal($base . 'mealsdb-tasks',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'tasks']), 'tasks');
assert_equal($base . 'mealsdb-settings',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'settings']), 'settings');

// ---------------------------------------------------------------------------
// Args-passthrough (extras keep their order; forced args appended).
// ---------------------------------------------------------------------------
assert_equal($base . 'mealsdb-clients&action=edit&client_id=42&tab=list',
    MealsDB_Admin_UI::retired_tab_target(
        ['page' => 'mealsdb', 'tab' => 'clients', 'action' => 'edit', 'client_id' => '42']
    ), 'clients edit link preserves action + client_id');
assert_equal($base . 'mealsdb-clients&paged=3&search=smith&type_preset=sdnb&tab=list',
    MealsDB_Admin_UI::retired_tab_target(
        ['page' => 'mealsdb', 'tab' => 'clients', 'paged' => '3', 'search' => 'smith', 'type_preset' => 'sdnb']
    ), 'clients list filters preserved');
assert_equal($base . 'mealsdb-purchase-orders&po_id=7',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'po_admin', 'po_id' => '7']),
    'po_admin detail preserves po_id');
assert_equal($base . 'mealsdb-tasks&action=detail&task_id=9',
    MealsDB_Admin_UI::retired_tab_target(
        ['page' => 'mealsdb', 'tab' => 'tasks', 'action' => 'detail', 'task_id' => '9']
    ), 'task detail preserves action + task_id');

// ---------------------------------------------------------------------------
// Values are urlencoded; array-typed args are dropped, not fataled.
// ---------------------------------------------------------------------------
assert_equal($base . 'mealsdb-clients&search=o%27brien%20%26%20co&tab=list',
    MealsDB_Admin_UI::retired_tab_target(
        ['page' => 'mealsdb', 'tab' => 'clients', 'search' => "o'brien & co"]
    ), 'arg values urlencoded');
assert_equal($base . 'mealsdb-tasks&task_id=9',
    MealsDB_Admin_UI::retired_tab_target(
        ['page' => 'mealsdb', 'tab' => 'tasks', 'ids' => ['1', '2'], 'task_id' => '9']
    ), 'array-typed args dropped');

// ---------------------------------------------------------------------------
// Non-matches.
// ---------------------------------------------------------------------------
assert_equal(null, MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb']), 'no tab => Home renders, no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'bogus']), 'unknown tab => no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb-reports', 'tab' => 'clients']), 'other page => no redirect');
assert_equal(null, MealsDB_Admin_UI::retired_tab_target(['page' => ['mealsdb'], 'tab' => 'sync']), 'array page => no redirect');
assert_equal($base . 'mealsdb-clients&tab=sync',
    MealsDB_Admin_UI::retired_tab_target(['page' => 'mealsdb', 'tab' => 'SYNC']), 'tab is case-normalized');

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

- [ ] **Step 2: Run it — expect failure** (v1 signature is `(string, string)`; passing an array raises a TypeError). Non-zero exit.

- [ ] **Step 3: Replace the map and wrapper**

In `includes/class-admin-ui.php`, replace the entire `retired_tab_target()` method with:

```php
    /**
     * Legacy-URL map for the retired ?page=mealsdb&tab=… URLs (spec
     * 2026-07-16 §6; PR 3 dissolved the main page's tabs into dedicated
     * pages). Takes the request's full query-arg array and returns the
     * replacement admin URL with every extra scalar arg preserved
     * (client_id, po_id, task_id, paged, search, filters…), or null when
     * the request is not a retired-tab URL. Pure — no superglobal reads,
     * no redirect — so it is unit-testable. The PR 2 (string,string)
     * signature could not express arg preservation; this is the redesign
     * its review called for.
     */
    public static function retired_tab_target(array $query): ?string {
        $page = isset($query['page']) && is_string($query['page']) ? $query['page'] : '';
        $tab  = isset($query['tab']) && is_string($query['tab']) ? strtolower($query['tab']) : '';
        if ($page !== 'mealsdb' || $tab === '') {
            return null;
        }

        // tab => [new page slug, forced args appended after the preserved
        // extras]. The bare mealsdb slug (no tab) renders Home — no row.
        $map = [
            'sync'     => ['mealsdb-clients', ['tab' => 'sync']],
            'add'      => ['mealsdb-clients', ['tab' => 'add']],
            'clients'  => ['mealsdb-clients', ['tab' => 'list']],
            'drafts'   => ['mealsdb-clients', ['tab' => 'add']],
            'ignored'  => ['mealsdb-clients', ['tab' => 'sync', 'view' => 'ignored']],
            'slips'    => ['mealsdb-packing-slips', []],
            'po'       => ['mealsdb-purchase-orders', []],
            'po_admin' => ['mealsdb-purchase-orders', []],
            'tasks'    => ['mealsdb-tasks', []],
            'settings' => ['mealsdb-settings', []],
        ];
        if (!isset($map[$tab])) {
            return null;
        }

        [$new_page, $forced] = $map[$tab];
        $args = $query;
        unset($args['page'], $args['tab']);
        $args = array_merge($args, $forced);

        $url = 'admin.php?page=' . $new_page;
        foreach ($args as $key => $value) {
            if (!is_scalar($value)) {
                continue; // ?ids[]=… can't be preserved through this builder
            }
            $url .= '&' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        return admin_url($url);
    }
```

Then replace the body of `redirect_retired_tabs()` with:

```php
    public function redirect_retired_tabs(): void {
        if (!isset($_GET['page'], $_GET['tab'])) {
            return;
        }

        $query = $_GET;
        if (function_exists('wp_unslash')) {
            $query = wp_unslash($query);
        }

        $target = self::retired_tab_target(is_array($query) ? $query : []);
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

- [ ] **Step 4: Run the test — expect `OK: 19 assertions passed`.**

- [ ] **Step 5: Replace render_main_page with the Home shell; delete render_tabs and the partial**

Replace the entire `render_main_page()` method with:

```php
    /**
     * Home — the plugin's landing page (spec 2026-07-16 §1). PR 3 ships
     * the shell (title + quick actions); PR 4 adds the dashboard widgets
     * (tasks due today, today's zones, alerts). The tab router that lived
     * here is gone: every tab is a dedicated page now, and
     * redirect_retired_tabs() catches old ?tab= URLs before render.
     */
    public static function render_main_page() {
        MealsDB_Permissions::enforce();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Meals DB', 'meals-db') . '</h1>';

        $actions = [
            [admin_url('admin.php?page=mealsdb-clients&tab=add'), __('New Client', 'meals-db')],
            [admin_url('admin.php?page=mealsdb_quick_order'), __('Quick Order', 'meals-db')],
            [admin_url('admin.php?page=mealsdb-packing-slips'), __("Today's Slips", 'meals-db')],
            [admin_url('admin.php?page=mealsdb-tasks'), __('Tasks', 'meals-db')],
            [admin_url('admin.php?page=mealsdb-clients'), __('Clients', 'meals-db')],
        ];
        echo '<p class="mealsdb-home-actions" style="margin-top:16px;">';
        foreach ($actions as $action) {
            echo '<a class="button button-hero" style="margin:0 8px 8px 0;" href="'
                . esc_url($action[0]) . '">' . esc_html($action[1]) . '</a>';
        }
        echo '</p>';
        echo '</div>';
    }
```

Then:
1. Delete the entire `render_tabs()` method (~line 1201, the one seeding the tab array and including `views/partials/tabs.php`).
2. `git rm views/partials/tabs.php` (its only includer was `render_tabs()` — verify with `grep -rn "partials/tabs" includes/ views/` → must return nothing after the method deletion).

- [ ] **Step 6: Lint, test, commit**

```bash
php -l includes/class-admin-ui.php
php tests/test-retired-tab-redirects.php && php tests/test-menu-order.php
grep -rn "render_tabs\|partials/tabs" includes/ views/ && echo "STALE REFS — fix before committing" || echo "clean"
git add tests/test-retired-tab-redirects.php includes/class-admin-ui.php
git commit -m "feat(admin): args-preserving legacy redirects, Home shell, tab router retired"
```

---

### Task 4: View-side URL updates + sync/ignored cross-links

**Files:**
- Modify: `views/view-clients.php`, `views/drafts.php`, `views/ignored.php`, `views/dashboard.php`, `views/tasks-list.php`, `views/task-detail.php`, `views/task-rules.php`, `views/purchase-orders.php`, `views/partials/dashboard-tasks-widget.php`, `includes/class-admin-ui.php` (one line)

- [ ] **Step 1: Mechanical URL swaps (exact old → new)**

| File:line | Old | New |
|---|---|---|
| `views/view-clients.php:54` | `admin_url('admin.php?page=mealsdb&tab=clients')` | `admin_url('admin.php?page=mealsdb-clients&tab=list')` |
| `views/view-clients.php:55` | `admin_url('admin.php?page=mealsdb&tab=clients&action=edit')` | `admin_url('admin.php?page=mealsdb-clients&tab=list&action=edit')` |
| `views/drafts.php:90` | `admin_url('admin.php?page=mealsdb&tab=add')` | `admin_url('admin.php?page=mealsdb-clients&tab=add')` |
| `views/drafts.php:114` | `admin_url('admin.php?page=mealsdb&tab=drafts')` | `admin_url('admin.php?page=mealsdb-clients&tab=add')` |
| `views/ignored.php:114` | `admin_url('admin.php?page=mealsdb&tab=ignored')` | `admin_url('admin.php?page=mealsdb-clients&tab=sync&view=ignored')` |
| `views/tasks-list.php:67` | `admin_url('admin.php?page=mealsdb&tab=tasks')` | `admin_url('admin.php?page=mealsdb-tasks')` |
| `views/task-rules.php:15` | `admin_url('admin.php?page=mealsdb&tab=tasks')` | `admin_url('admin.php?page=mealsdb-tasks')` |
| `views/task-detail.php:15` | `admin_url('admin.php?page=mealsdb&tab=tasks')` | `admin_url('admin.php?page=mealsdb-tasks')` |
| `views/task-detail.php:52` | `admin_url('admin.php?page=mealsdb&tab=po_admin&po_id=' . (int) $task['related_entity_id'])` | `admin_url('admin.php?page=mealsdb-purchase-orders&po_id=' . (int) $task['related_entity_id'])` |
| `views/purchase-orders.php:26` | `admin_url('admin.php?page=mealsdb&tab=po_admin')` | `admin_url('admin.php?page=mealsdb-purchase-orders')` |
| `views/purchase-orders.php:304` | `admin_url('admin.php?page=mealsdb&tab=tasks&action=detail&task_id=' . (int) $task['task_id'])` | `admin_url('admin.php?page=mealsdb-tasks&action=detail&task_id=' . (int) $task['task_id'])` |
| `views/partials/dashboard-tasks-widget.php:41` | `admin_url('admin.php?page=mealsdb&tab=tasks')` | `admin_url('admin.php?page=mealsdb-tasks')` |
| `includes/class-admin-ui.php:835` (fee-recon `editUrl`) | `admin_url('admin.php?page=mealsdb&tab=clients&action=edit&client_id=')` | `admin_url('admin.php?page=mealsdb-clients&tab=list&action=edit&client_id=')` |

Line numbers are from the PR 2 branch tip; locate by the old string, not the number. IMPORTANT: `$base_url` in `views/purchase-orders.php` feeds both the PHP links AND the JS island's `baseUrl` (which the generate handler concatenates `'&po_id='` onto) — the new URL still contains a query string (`?page=…`), so the concatenation stays valid.

- [ ] **Step 2: "View ignored" link on the Sync dashboard**

In `views/dashboard.php`, find the view's first heading/description block (the top of the file's output, before the mismatch listing). Directly after it, add:

```php
<?php
// Ignored Conflicts lives under this Sync tab now (spec 2026-07-16 §3) —
// unignoring is part of the same reconciliation job. Count is cheap
// (indexed COUNT on a small table).
global $wpdb;
$mealsdb_ignored_table = MealsDB_DB::get_table_name(MealsDB_Tables::IGNORED_CONFLICTS);
$mealsdb_ignored_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$mealsdb_ignored_table}`");
if ($mealsdb_ignored_count > 0) : ?>
    <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=mealsdb-clients&tab=sync&view=ignored')); ?>">
            <?php echo esc_html(sprintf(
                /* translators: %d: number of ignored sync mismatches */
                __('View ignored mismatches (%d)', 'meals-db'),
                $mealsdb_ignored_count
            )); ?>
        </a>
    </p>
<?php endif; ?>
```

- [ ] **Step 3: Back-link at the top of the ignored view**

In `views/ignored.php`, directly after the view's opening heading (inside its wrap, before the table), add:

```php
    <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=mealsdb-clients&tab=sync')); ?>">
            &larr; <?php echo esc_html__('Back to WooCommerce Sync', 'meals-db'); ?>
        </a>
    </p>
```

- [ ] **Step 4: Verify no legacy URL remains, lint, test, commit**

```bash
grep -rn "page=mealsdb&" --include="*.php" --include="*.js" includes/ views/ assets/ | grep -v "retired_tab_target\|admin.php?page=mealsdb-" && echo "STALE URLS — fix before committing" || echo "clean"
for f in views/view-clients.php views/drafts.php views/ignored.php views/dashboard.php views/tasks-list.php views/task-detail.php views/task-rules.php views/purchase-orders.php views/partials/dashboard-tasks-widget.php includes/class-admin-ui.php; do php -l "$f" || exit 1; done
php tests/test-retired-tab-redirects.php && php tests/test-menu-order.php
git add views/view-clients.php views/drafts.php views/ignored.php views/dashboard.php views/tasks-list.php views/task-detail.php views/task-rules.php views/purchase-orders.php views/partials/dashboard-tasks-widget.php includes/class-admin-ui.php
git commit -m "feat(admin): point every internal link at the dedicated pages; sync/ignored cross-links"
```

(The only permitted `page=mealsdb&` occurrences after this step are inside `retired_tab_target()`'s docblock/comments, if any.)

---

### Task 5: Full-suite verification and PR

**Files:** none

- [ ] **Step 1: Full suite**

```bash
php tests/test-retired-tab-redirects.php && php tests/test-menu-order.php && php tests/test-advanced-tools.php
for f in tests/test-*.php; do php "$f" >/dev/null 2>&1 || echo "FAIL: $f"; done
```

Expected: 19 + 4 + 10 assertions; the loop reports only the 2 known PDF baseline failures.

- [ ] **Step 2: Manual smoke checklist (record in the PR body)**

1. Menu shows, in order: Meals DB (Home), Quick Order, Clients, Tasks, Packing Slips, Purchase Orders, Invoices, Reports, Staff, Cron Status, Event Log, Settings (+ the governed three only when the toggle is on).
2. Home renders the quick-action buttons; every button lands on the right page.
3. Clients: List (filter/search/paginate/edit/delete), Add (drafts panel appears when drafts exist; resume + delete work; form submits), Sync (compare/sync works; "View ignored (N)" link; ignored view lists/unignores with back-link). Client-form CSS + WP-user/initials/zone-day JS load on Add and Edit.
4. Tasks: list filters, bulk skip/defer, detail complete/defer/skip, rules CRUD — all on the new page.
5. Purchase Orders: list + Generate + detail workflow on the new page; task-detail's PO link and PO detail's task links cross-navigate correctly.
6. Settings: form saves (incl. shadow mode, advanced-tools toggle, zones, resync).
7. Legacy URLs redirect with args: `?page=mealsdb&tab=clients&action=edit&client_id=N`, `?tab=po_admin&po_id=N`, `?tab=tasks&action=detail&task_id=N`, `?tab=drafts`, `?tab=ignored`, `?tab=settings`, and bare `?page=mealsdb` renders Home.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feat/menu-restructure
gh pr create --base main --head feat/menu-restructure --title "feat(admin): menu restructure — dedicated pages + Home shell (UI consolidation PR 3)" --body "$(cat <<'EOF'
## Summary
- The main page's 8 tabs dissolve into dedicated submenu pages: **Clients** (List / Add / Sync subnav — drafts as a collapsed resume panel under Add, Ignored Conflicts as a sub-view under Sync), **Tasks**, **Purchase Orders**, **Settings**
- The `mealsdb` slug becomes a minimal **Home shell** (title + quick actions; PR 4 adds the dashboard widgets)
- **Menu order** is now canonical (spec §menu) via a unit-tested submenu sorter on a late admin_menu hook — no registration-priority churn across the other page classes
- **Legacy redirects v2:** `retired_tab_target()` redesigned to take the full query array and preserve extra args (`client_id`, `po_id`, `task_id`, `paged`, filters) — every old bookmark lands parameter-intact; 19-assertion test
- **Asset enqueue rekeyed:** the new page hooks translate back into the legacy tab vocabulary, so every view keeps exactly the JS/CSS it had
- All 13 internal link-builder sites updated; `render_tabs()` + `views/partials/tabs.php` deleted
- No AJAX endpoint, handler, capability, nonce, or rate-limit changes
- PR 3 of 4: `docs/superpowers/specs/2026-07-16-admin-ui-consolidation-design.md`

## Test plan
- [ ] `php tests/test-retired-tab-redirects.php` (19 assertions: all 10 tab rows, args-passthrough, urlencoding, array-arg drop, case-normalization)
- [ ] `php tests/test-menu-order.php` (4 assertions: spec order, unknown-slug stability)
- [ ] Full standalone suite green except the 2 known local PDF baseline failures
- [ ] Staging smoke: menu order; Home quick actions; all Clients/Tasks/PO/Settings flows on their new pages; legacy URLs redirect args-intact

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Merge on request only; CI owns version bumps — do NOT bump `MEALS_DB_VERSION`.
