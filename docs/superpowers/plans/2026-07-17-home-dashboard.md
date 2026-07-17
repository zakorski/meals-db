# Home Dashboard Implementation Plan (Admin UI Consolidation, PR 4 — final)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fill the Home shell with the spec's "today's work" widgets: alerts strip (failed/degraded events last 24h + the operator's unfinished drafts), today's delivery zones with prefilled Packing Slips links, and the shared tasks widget.

**Architecture:** Everything is server-rendered PHP — no new JS, no new endpoints, no enqueue changes (Home stays CSS-only). The shell markup moves out of `render_main_page()` into a proper `views/home.php`, which composes: quick actions (unchanged), two cheap prepared COUNTs (event-log trunk, owner-scoped drafts), a "today's deliveries" list driven by a new pure, unit-tested `MealsDB_Zone_Day::zones_for_day()`, and an include of the existing `views/partials/dashboard-tasks-widget.php`. The Packing Slips generate form learns to prefill zone + date from GET so the Home links land ready to click.

**Tech Stack:** WordPress admin, `$wpdb->prepare`, standalone-PHP test convention (`php tests/test-*.php`).

**Spec:** `docs/superpowers/specs/2026-07-16-admin-ui-consolidation-design.md` §2 (Home dashboard), rollout PR 4.

**Reference facts (verified against the code 2026-07-17, post-PR #467):**
- `MealsDB_Admin_UI::render_main_page()` currently echoes the Home shell inline (wrap + h1 + five quick-action buttons). Enqueue for `toplevel_page_mealsdb` is admin.css only (early return) — unchanged by this PR.
- `views/partials/dashboard-tasks-widget.php` is self-contained (guards `class_exists('MealsDB_Task_Engine')`, needs no JS, links to `mealsdb-tasks`), and is also included by `views/dashboard.php` — shared partial, include as-is.
- `MealsDB_Zone_Day` (`includes/services/class-zone-day.php`): `SCHEDULE_OPTION`, `schedule(): array<string, array{day: string, label: string}>` (validated, day keeps stored case 'Monday'–'Friday'), `day_for_zone()` returns lowercase. The settings UI only allows Monday–Friday day values.
- The tasks widget resolves "today" via `MealsDB_Task_Rules::site_timezone()`; `wp_timezone()` is the WP-native equivalent — use it for Home's day/date.
- Event-log trunk: table `MealsDB_Tables::EVENT_LOG` (`meals_event_log`), `occurred_at` written with `gmdate()` (UTC), `outcome` ENUM includes `failed`/`degraded`. `MealsDB_Event_Log::query()` has no COUNT — a direct prepared COUNT matches the dashboard-count pattern used elsewhere (drafts panel, ignored link).
- Drafts count precedent (owner-scoped): `views/partials/drafts-panel.php`.
- Capability tiers: Event Log page and Packing Slips page are `manage_options`; Home is baseline. Alerts/links into those pages must not dangle a 403 in front of a baseline user.
- Packing Slips generate form (`MealsDB_Slip_Batch_Page::render_generate_form()`): echo-built `<select id="mealsdb-slip-zone">` over `array_keys(schedule)` + `<input type="date" id="mealsdb-slip-date" />` (no value attr).
- Execution rules that stand from PRs 1–3: subagents never `git checkout <commit>` (use `git show`); nothing under `directives/` staged; local baseline: 2 PDF tests fail (mbstring/imagick).

---

### Task 0: Create the feature branch

**Files:** none

- [ ] **Step 1: Branch from up-to-date main**

```bash
cd /mnt/fastssd/meals-db && git checkout main && git stash push -m "operator directives (auto-restore)" -- directives/ 2>/dev/null; git pull --ff-only && git checkout -b feat/home-dashboard && git stash pop 2>/dev/null; git log --oneline -2
```

Verify the log shows the PR #467 merge (menu restructure) — this plan builds on it. The stash dance protects the operator's uncommitted `directives/` changes across the pull.

---

### Task 1: `MealsDB_Zone_Day::zones_for_day()` (TDD)

**Files:**
- Test: `tests/test-zones-for-day.php` (create)
- Modify: `includes/services/class-zone-day.php`

- [ ] **Step 1: Write the failing test**

Create `tests/test-zones-for-day.php` with exactly this content:

```php
<?php
/**
 * Tests for MealsDB_Zone_Day::zones_for_day() — the pure selector behind
 * the Home page's "Today's deliveries" widget (admin UI consolidation
 * spec 2026-07-16 §2, PR 4). Given the validated schedule() shape and a
 * weekday name, returns the zones delivering that day, preserving
 * schedule order, comparing case-insensitively, and skipping malformed
 * rows instead of warning (same defensive stance as schedule()).
 *
 * Run with: php tests/test-zones-for-day.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// --- assertion helpers (test-hook-logger.php convention) ----------------
$failures = [];
$passed   = 0;
function assert_equal($expected, $actual, string $label) {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s",
        $label, var_export($expected, true), var_export($actual, true));
}

$schedule = [
    'Moncton North' => ['day' => 'Wednesday', 'label' => 'North run'],
    'Riverview'     => ['day' => 'Thursday',  'label' => 'River run'],
    'Moncton East'  => ['day' => 'Wednesday', 'label' => 'East run'],
    'Sussex'        => ['day' => 'Friday',    'label' => ''],
];

// ---------------------------------------------------------------------------
// Matching zones, schedule order preserved.
// ---------------------------------------------------------------------------
assert_equal(
    [
        'Moncton North' => ['day' => 'Wednesday', 'label' => 'North run'],
        'Moncton East'  => ['day' => 'Wednesday', 'label' => 'East run'],
    ],
    MealsDB_Zone_Day::zones_for_day($schedule, 'Wednesday'),
    'two Wednesday zones, schedule order preserved'
);

// ---------------------------------------------------------------------------
// Case-insensitive on both sides.
// ---------------------------------------------------------------------------
assert_equal(
    ['Riverview' => ['day' => 'Thursday', 'label' => 'River run']],
    MealsDB_Zone_Day::zones_for_day($schedule, 'thursday'),
    'lowercase needle matches stored case'
);
assert_equal(
    ['Sussex' => ['day' => 'Friday', 'label' => '']],
    MealsDB_Zone_Day::zones_for_day(
        ['Sussex' => ['day' => 'FRIDAY', 'label' => '']],
        'Friday'
    ),
    'uppercase stored day matches'
);

// ---------------------------------------------------------------------------
// No match / empty inputs.
// ---------------------------------------------------------------------------
assert_equal([], MealsDB_Zone_Day::zones_for_day($schedule, 'Sunday'), 'no zones on Sunday');
assert_equal([], MealsDB_Zone_Day::zones_for_day($schedule, ''), 'empty day => nothing');
assert_equal([], MealsDB_Zone_Day::zones_for_day([], 'Wednesday'), 'empty schedule => nothing');

// ---------------------------------------------------------------------------
// Malformed rows are skipped, not warned about.
// ---------------------------------------------------------------------------
assert_equal(
    ['Good' => ['day' => 'Monday', 'label' => 'ok']],
    MealsDB_Zone_Day::zones_for_day(
        [
            'NotArray' => 'garbage',
            'NoDay'    => ['label' => 'no day key'],
            'Good'     => ['day' => 'Monday', 'label' => 'ok'],
        ],
        'Monday'
    ),
    'malformed rows skipped'
);

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

`php tests/test-zones-for-day.php` → fatal `Call to undefined method MealsDB_Zone_Day::zones_for_day()`.

- [ ] **Step 3: Implement**

In `includes/services/class-zone-day.php`, directly after the closing brace of `schedule()`, add:

```php
    /**
     * Zones delivering on a given weekday. $schedule is the validated
     * schedule() shape; $day is a full weekday name, compared
     * case-insensitively. Preserves schedule order and skips malformed
     * rows (same defensive stance as schedule() — the option is
     * operator-set). Pure, for unit tests and the Home page's "Today's
     * deliveries" widget (spec 2026-07-16 §2).
     *
     * @param array<string, array{day: string, label: string}> $schedule
     * @return array<string, array{day: string, label: string}>
     */
    public static function zones_for_day(array $schedule, string $day): array {
        $needle = strtolower(trim($day));
        if ($needle === '') {
            return [];
        }
        $out = [];
        foreach ($schedule as $zone => $config) {
            if (!is_array($config)) {
                continue;
            }
            if (strtolower(trim((string) ($config['day'] ?? ''))) === $needle) {
                $out[(string) $zone] = $config;
            }
        }
        return $out;
    }
```

- [ ] **Step 4: Run the test — expect `OK: 7 assertions passed`.**

- [ ] **Step 5: Lint, regression, commit**

```bash
php -l includes/services/class-zone-day.php
php tests/test-zones-for-day.php
php tests/test-client-form-zone-day.php
git add tests/test-zones-for-day.php includes/services/class-zone-day.php
git commit -m "feat(home): MealsDB_Zone_Day::zones_for_day — pure weekday selector for the Home deliveries widget"
```

(`test-client-form-zone-day.php` exercises the existing zone-day paths — must stay green.)

---

### Task 2: `views/home.php` + slip-form prefill

**Files:**
- Create: `views/home.php`
- Modify: `includes/class-admin-ui.php` (`render_main_page()` becomes an include)
- Modify: `includes/admin/class-slip-batch-page.php` (`render_generate_form()` GET prefill)

- [ ] **Step 1: Create the Home view**

Create `views/home.php` with exactly this content:

```php
<?php
/**
 * Home — the plugin's landing page (spec 2026-07-16 §1–2, PR 4). A cheap,
 * server-rendered "today" overview: quick actions, an alerts strip
 * (failed/degraded trunk events in the last 24h + the operator's own
 * unfinished client drafts), today's delivery zones with prefilled batch
 * links, and the shared tasks widget. Deliberately no JS and no expensive
 * queries — the DB compare is never auto-run here.
 */

defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

global $wpdb;

// ---------------------------------------------------------------------
// Quick actions (unchanged from the PR 3 shell).
// ---------------------------------------------------------------------
$mealsdb_home_actions = [
    [admin_url('admin.php?page=mealsdb-clients&tab=add'), __('New Client', 'meals-db')],
    [admin_url('admin.php?page=mealsdb_quick_order'), __('Quick Order', 'meals-db')],
    [admin_url('admin.php?page=mealsdb-packing-slips'), __("Today's Slips", 'meals-db')],
    [admin_url('admin.php?page=mealsdb-tasks'), __('Tasks', 'meals-db')],
    [admin_url('admin.php?page=mealsdb-clients'), __('Clients', 'meals-db')],
];

// ---------------------------------------------------------------------
// Alerts strip.
// ---------------------------------------------------------------------
$mealsdb_home_alerts = [];

// Failed/degraded trunk events, last 24h. occurred_at is written with
// gmdate() (UTC) so the window is computed in UTC too. The Event Log
// page is manage_options — suppress the alert entirely below that tier
// rather than dangling a link into a 403.
if (current_user_can('manage_options') && class_exists('MealsDB_Event_Log')) {
    $mealsdb_event_table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
    $mealsdb_event_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$mealsdb_event_table}`
          WHERE outcome IN ('failed','degraded') AND occurred_at >= %s",
        gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
    ));
    if ($mealsdb_event_count > 0) {
        $mealsdb_home_alerts[] = [
            'url'   => admin_url('admin.php?page=mealsdb_event_log'),
            'label' => sprintf(
                /* translators: %d: failed/degraded operational events in the last 24 hours */
                _n('%d failed or degraded event in the last 24h', '%d failed or degraded events in the last 24h', $mealsdb_event_count, 'meals-db'),
                $mealsdb_event_count
            ),
        ];
    }
}

// The operator's own unfinished client drafts — owner-scoped, matching
// the drafts list and the Add-tab resume panel.
$mealsdb_drafts_table = MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS);
$mealsdb_draft_count  = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `{$mealsdb_drafts_table}` WHERE created_by = %d",
    get_current_user_id()
));
if ($mealsdb_draft_count > 0) {
    $mealsdb_home_alerts[] = [
        'url'   => admin_url('admin.php?page=mealsdb-clients&tab=add'),
        'label' => sprintf(
            /* translators: %d: the operator's saved client-form drafts */
            _n('%d unfinished client form', '%d unfinished client forms', $mealsdb_draft_count, 'meals-db'),
            $mealsdb_draft_count
        ),
    ];
}

// ---------------------------------------------------------------------
// Today's deliveries (site timezone, like the tasks widget).
// ---------------------------------------------------------------------
try {
    $mealsdb_home_now  = new DateTimeImmutable('now', wp_timezone());
    $mealsdb_today_name = $mealsdb_home_now->format('l');
    $mealsdb_today_date = $mealsdb_home_now->format('Y-m-d');
} catch (Throwable $e) {
    $mealsdb_today_name = gmdate('l');
    $mealsdb_today_date = gmdate('Y-m-d');
}
$mealsdb_today_zones = class_exists('MealsDB_Zone_Day')
    ? MealsDB_Zone_Day::zones_for_day(MealsDB_Zone_Day::schedule(), $mealsdb_today_name)
    : [];
// Packing Slips is manage_options — plain text below that tier.
$mealsdb_can_slips = current_user_can('manage_options');
?>
<div class="wrap">
    <h1><?php esc_html_e('Meals DB', 'meals-db'); ?></h1>

    <p class="mealsdb-home-actions" style="margin-top:16px;">
        <?php foreach ($mealsdb_home_actions as $mealsdb_home_action) : ?>
            <a class="button button-hero" style="margin:0 8px 8px 0;" href="<?php echo esc_url($mealsdb_home_action[0]); ?>"><?php echo esc_html($mealsdb_home_action[1]); ?></a>
        <?php endforeach; ?>
    </p>

    <?php if (!empty($mealsdb_home_alerts)) : ?>
        <div class="mealsdb-home-alerts" style="margin:16px 0; padding:12px 16px; background:#fff; border:1px solid #ccd0d4; border-left:4px solid #d63638;">
            <h3 style="margin:0 0 8px;"><?php esc_html_e('Needs attention', 'meals-db'); ?></h3>
            <ul style="margin:0; list-style:disc; padding-left:20px;">
                <?php foreach ($mealsdb_home_alerts as $mealsdb_home_alert) : ?>
                    <li><a href="<?php echo esc_url($mealsdb_home_alert['url']); ?>"><?php echo esc_html($mealsdb_home_alert['label']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="mealsdb-home-deliveries" style="margin:16px 0; padding:12px 16px; background:#fff; border:1px solid #ccd0d4; border-left:4px solid #00a32a;">
        <h3 style="margin:0 0 8px;">
            <?php echo esc_html(sprintf(
                /* translators: %s: weekday name */
                __("Today's Deliveries (%s)", 'meals-db'),
                $mealsdb_today_name
            )); ?>
        </h3>
        <?php if (empty($mealsdb_today_zones)) : ?>
            <p style="margin:0;"><em><?php esc_html_e('No zones deliver today.', 'meals-db'); ?></em></p>
        <?php else : ?>
            <ul style="margin:0; list-style:disc; padding-left:20px;">
                <?php foreach ($mealsdb_today_zones as $mealsdb_zone_name => $mealsdb_zone_config) : ?>
                    <li>
                        <strong><?php echo esc_html($mealsdb_zone_name); ?></strong>
                        <?php if ($mealsdb_zone_config['label'] !== '') : ?>
                            &mdash; <?php echo esc_html($mealsdb_zone_config['label']); ?>
                        <?php endif; ?>
                        <?php if ($mealsdb_can_slips) : ?>
                            <a style="margin-left:8px;" href="<?php echo esc_url(admin_url('admin.php?page=mealsdb-packing-slips&zone=' . rawurlencode($mealsdb_zone_name) . '&date=' . $mealsdb_today_date)); ?>">
                                <?php esc_html_e('Generate batch', 'meals-db'); ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php include MealsDB_Plugin::path('views/partials/dashboard-tasks-widget.php'); ?>
</div>
```

- [ ] **Step 2: render_main_page becomes an include**

In `includes/class-admin-ui.php`, replace the entire body of `render_main_page()` (keep its docblock, but update the second sentence) so the method reads:

```php
    /**
     * Home — the plugin's landing page (spec 2026-07-16 §1). PR 4 filled
     * the shell with the dashboard widgets — see views/home.php. The tab
     * router that lived here is gone: every tab is a dedicated page now,
     * and redirect_retired_tabs() catches old ?tab= URLs before render.
     */
    public static function render_main_page() {
        MealsDB_Permissions::enforce();

        include MealsDB_Plugin::path('views/home.php');
    }
```

(The old inline `$actions` array and echo loop are deleted — they moved into the view.)

- [ ] **Step 3: Packing Slips GET prefill**

In `includes/admin/class-slip-batch-page.php`, `render_generate_form()`: at the top of the method (before the first `echo`), add:

```php
        // The Home page's "Today's deliveries" links prefill zone + date
        // via GET (spec 2026-07-16 §2). Read-only convenience — generating
        // still requires the explicit button click. Unknown zones simply
        // don't match an <option>; malformed dates are dropped.
        $prefill_zone = isset($_GET['zone']) && is_string($_GET['zone'])
            ? sanitize_text_field(wp_unslash($_GET['zone']))
            : '';
        $prefill_date = isset($_GET['date']) && is_string($_GET['date'])
            ? sanitize_text_field(wp_unslash($_GET['date']))
            : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefill_date)) {
            $prefill_date = '';
        }
```

Then change the zone `<option>` line inside the schedule loop from:

```php
                echo '<option value="' . esc_attr((string) $zone_name) . '">'
                    . esc_html((string) $zone_name) . '</option>';
```

to:

```php
                echo '<option value="' . esc_attr((string) $zone_name) . '"'
                    . selected($prefill_zone, (string) $zone_name, false) . '>'
                    . esc_html((string) $zone_name) . '</option>';
```

and the date input line from:

```php
        echo '<label>' . esc_html__('Delivery date', 'meals-db')
            . ' <input type="date" id="mealsdb-slip-date" /></label> ';
```

to:

```php
        echo '<label>' . esc_html__('Delivery date', 'meals-db')
            . ' <input type="date" id="mealsdb-slip-date" value="' . esc_attr($prefill_date) . '" /></label> ';
```

- [ ] **Step 4: Lint, tests, commit**

```bash
php -l views/home.php && php -l includes/class-admin-ui.php && php -l includes/admin/class-slip-batch-page.php
php tests/test-zones-for-day.php && php tests/test-menu-order.php && php tests/test-retired-tab-redirects.php && php tests/test-ajax-slip-batch.php
git add views/home.php includes/class-admin-ui.php includes/admin/class-slip-batch-page.php
git commit -m "feat(home): dashboard widgets — alerts strip, today's deliveries with batch prefill, tasks widget"
```

Expected: lints clean; `OK: 7`, `OK: 4`, `OK: 19`, `PASS — 21 checks`.

---

### Task 3: Full-suite verification and PR

**Files:** none

- [ ] **Step 1: Full suite**

```bash
for f in tests/test-*.php; do php "$f" >/dev/null 2>&1 || echo "FAIL: $f"; done
```

Expected: only the 2 known PDF baseline failures.

- [ ] **Step 2: Manual smoke checklist (record in the PR body)**

1. Home shows: quick actions; "Needs attention" (only when counts > 0 — seed a draft to test); "Today's Deliveries (<weekday>)" listing today's zones or the empty state; the tasks widget.
2. A zone's "Generate batch" link lands on Packing Slips with zone preselected and today's date filled; generation still requires the button.
3. As a hypothetical baseline-capability user: no event-log alert, no batch links (plain zone text) — nothing links into a 403.
4. Timezone check: near midnight UTC the deliveries weekday must follow the SITE timezone (America/Moncton), not UTC.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin feat/home-dashboard
gh pr create --base main --head feat/home-dashboard --title "feat(home): Home dashboard widgets (UI consolidation PR 4 — final)" --body "$(cat <<'EOF'
## Summary
- Home now renders the spec's "today's work" overview, all server-side (no new JS/endpoints/enqueues):
  - **Needs attention** strip: failed/degraded trunk events in the last 24h (UTC window, links to Event Log, manage_options-gated) + the operator's own unfinished client drafts (owner-scoped, links to Clients → Add)
  - **Today's Deliveries**: zones delivering today (site timezone), each with a "Generate batch" link that lands on Packing Slips with zone + today's date prefilled (`selected()`/value-attr prefill on the existing form; generation still requires the explicit click)
  - The shared **tasks widget** (same partial the Sync dashboard uses)
- New pure helper `MealsDB_Zone_Day::zones_for_day()` (7-assertion test): case-insensitive weekday selector over the validated schedule
- Capability-aware: baseline users see no links into manage_options pages
- Home shell markup moved from `render_main_page()` into `views/home.php`
- PR 4 of 4 — completes `docs/superpowers/specs/2026-07-16-admin-ui-consolidation-design.md`

## Test plan
- [ ] `php tests/test-zones-for-day.php` (7 assertions)
- [ ] Full standalone suite green except the 2 known local PDF baseline failures
- [ ] Staging smoke: widgets render with and without data; batch prefill; no 403-dangling links for baseline users; weekday follows site timezone

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Merge on request only; CI owns version bumps — do NOT bump `MEALS_DB_VERSION`.
