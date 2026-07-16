# Admin UI Consolidation — Design

**Date:** 2026-07-16
**Status:** Approved design, pending implementation plan
**Author:** Zak Sikorski / Claude

## Context & problem

The Meals DB admin interface has grown to ~21 distinct destinations: 11 menu items
(one of which — the main `mealsdb` page — hosts 10 internal tabs), backed by 66 AJAX
endpoints and 73 user-triggerable actions. Daily-use tools (Quick Order, Tasks,
Clients, Slips) are interleaved with rare/dangerous ones (Migration, schema rebuild,
encryption keys) at equal menu weight. Specific problems found in the UI audit:

1. **Two purchase-order destinations** — the `po` tab is a single Generate button
   plus prose; `po_admin` is the real list/workflow.
2. **Two slip systems** — the Daily Slips tab (on-demand packer/driver PDFs, nothing
   saved) and the Packing Slips page (persistent batch workflow with history,
   re-download, cancel).
3. **The landing tab is the Sync Dashboard** (mismatch reconciliation) — maintenance
   work, not the center of a workday.
4. **Satellite tabs** — Drafts is only meaningful relative to Add Client; Ignored
   Conflicts only relative to Sync. Both occupy top-level tab slots.
5. **One-time tools at full prominence** — Migration and most Data Ops backfills are
   one-time, yet sit beside daily tools.

## Decisions (from brainstorming, 2026-07-16)

- **Users:** small team with shared roles. Organize by frequency/workflow, not by
  role. Labels must be self-explanatory.
- **Landing page:** a new "today's work" Home dashboard.
- **Slips:** the batch workflow won. Consolidate on the Packing Slips page; retire
  the Daily Slips tab but carry its on-demand capability over.
- **Rare/dangerous tools:** hidden behind a **global** "Show advanced tools"
  checkbox in Settings (under Shadow Mode). The toggle governs **Rate Definitions,
  Data Ops, and Migration** only. **Cron Status and Event Log stay permanently
  visible** (they are how the team notices breakage).
- This is a **navigation-layer refactor**. No AJAX endpoints, handlers,
  capabilities, nonces, or rate-limit buckets change. Views move; they are not
  rewritten.

## Target menu structure

```
Meals DB                    (clicking the top-level item lands on Home)
├─ Home                     ← NEW today-dashboard (slug: mealsdb, default landing)
├─ Quick Order              (mealsdb_quick_order, unchanged)
├─ Clients                  ← NEW page (mealsdb-clients), tabs: List · Add · Sync
├─ Tasks                    ← NEW page (mealsdb-tasks), hosts existing task views
├─ Packing Slips            (mealsdb-packing-slips, gains on-demand section)
├─ Purchase Orders          ← NEW page (mealsdb-purchase-orders), list + Generate
├─ Invoices                 (mealsdb-invoices, unchanged)
├─ Reports                  (mealsdb-reports, unchanged — 4 sub-tabs)
├─ Staff                    (meals-db-staff, unchanged)
├─ Cron Status              (mealsdb_cron_status, always visible)
├─ Event Log                (mealsdb_event_log, always visible)
├─ Settings                 ← NEW page (mealsdb-settings), hosts settings view
└─ shown only when the advanced-tools toggle is ON:
   Rate Definitions (mealsdb_rate_definitions)
   Data Ops         (mealsdb-data-ops)
   Migration        (mealsdb-migration)
```

The 10-tab main page dissolves. New page slugs are kebab-case per the dominant
existing convention.

## Detailed design

### 1. Home dashboard (new view, slug `mealsdb`)

Deliberately cheap to render — no expensive queries, and it never auto-runs the
DB compare. Sections:

- **Tasks due today / overdue** — reuse `views/partials/dashboard-tasks-widget.php`;
  link to the full Tasks page.
- **Today's deliveries** — zones delivering today, read from the
  `mealsdb_zone_delivery_schedule` option (no DB query); each zone links to
  Packing Slips pre-filled with zone + today's date.
- **Alerts strip** —
  - count of `outcome IN ('failed','degraded')` rows in `meals_event_log` over the
    last 24h (one indexed query), linking to Event Log;
  - count of saved client drafts ("N unfinished client forms"), linking to
    Clients → Add.
- **Quick actions** — buttons: New Client, Quick Order, Today's Slips.

Alert links must work regardless of menu visibility (Event Log is always visible
anyway; the principle stands for any future link into a toggle-hidden page).

### 2. Clients page (`mealsdb-clients`, tabbed)

- **List** (default tab) — current `views/view-clients.php` unchanged (filters,
  search, pagination, edit/activate/delete). Edit stays
  `?action=edit&client_id=N` within this tab, rendering `views/edit-client.php`.
- **Add** — current `views/add-client.php`, with saved drafts surfaced as a
  "Resume a draft (N)" panel above the form (rendering the existing
  `views/drafts.php` list, collapsed by default when N > 0, hidden when N = 0).
  The top-level Drafts tab is retired.
- **Sync** — current `views/dashboard.php` (compare/resolve mismatches). Ignored
  Conflicts becomes a sub-view here (`&view=ignored` rendering
  `views/ignored.php`, reached via a "View ignored (N)" link), since unignoring
  is part of the same reconciliation job. The top-level Ignored tab is retired.

### 3. Slips merge (Packing Slips page)

`mealsdb-packing-slips` becomes the single slips destination:

- Batch workflow (generate, history, re-download, cancel) stays front and center,
  unchanged.
- Below it, a collapsed **"On-demand PDFs (not saved)"** section carries over the
  entire Daily Slips form: both modes (zone + date range / by delivery day),
  packer and driver PDFs. Same JS (`mealsdb-daily-slips.js`,
  `mealsdb-report-utils.js`), same four AJAX endpoints, same JSON island.
- `views/daily-slips.php` is refactored to render as an embeddable section (its
  `manage_options` gate matches the host page's, so no capability change).
- The `tab=slips` main-page tab is retired.

### 4. Purchase Orders merge (`mealsdb-purchase-orders`)

- `views/purchase-orders.php` (list + detail + workflow) moves to its own page.
- The list header gains a **"Generate draft PO"** button — the same AJAX action
  (`mealsdb_po_save_draft`, same nonce) that the `po` tab used, navigating into
  the new draft on success. The forecast-model explanation from
  `views/purchase-order.php` moves into a collapsible note next to the button.
- Both the `po` and `po_admin` tabs are retired; `views/purchase-order.php` is
  deleted once its content is absorbed.
- The tasks-list "Related" column link to PO detail
  (`?page=mealsdb&tab=po_admin&po_id=N`) is updated to the new slug.

### 5. Settings page (`mealsdb-settings`) + advanced-tools toggle

- `views/settings.php` moves from a tab to its own submenu page. Content is
  unchanged except one addition:
- **New checkbox directly under Shadow Mode:** "Show advanced tools
  (Rate Definitions, Data Ops, Migration) in the menu".
  - Stored in the global `mealsdb_settings` option (site-wide, not per-user),
    key `show_advanced_tools`, default **off**.
  - Saved through the existing settings AJAX handler (`mealsdb_save_settings`:
    `manage_options`, `settings_modify` bucket, `mealsdb_settings_nonce`). No new
    endpoint.
- **Menu behavior:** when off, the three governed pages register **without a
  visible menu entry** (empty-string/hidden parent — the standard hidden-page
  pattern) so direct URLs, bookmarks, and in-code links keep working. When on,
  they register as normal submenu items in the position shown above.
- Hiding is a convenience, **not** a security layer: every governed page keeps
  its existing `manage_options` (or stricter) gate, which is checked on render
  regardless of menu visibility.

### 6. Legacy URL redirects

Extend the existing `admin_init` redirect pattern
(`MealsDB_Admin_UI::redirect_legacy_quick_order_slug()`) with a tab→page map.
All extra query args (`client_id`, `action`, `task_id`, `po_id`, `paged`,
`search`, `type_preset`, …) are preserved across the redirect.

| Legacy URL | Redirects to |
|---|---|
| `?page=mealsdb&tab=sync` | `?page=mealsdb-clients&tab=sync` |
| `?page=mealsdb&tab=add` | `?page=mealsdb-clients&tab=add` |
| `?page=mealsdb&tab=clients` (+ edit args) | `?page=mealsdb-clients&tab=list` (+ args) |
| `?page=mealsdb&tab=drafts` | `?page=mealsdb-clients&tab=add` |
| `?page=mealsdb&tab=ignored` | `?page=mealsdb-clients&tab=sync&view=ignored` |
| `?page=mealsdb&tab=slips` | `?page=mealsdb-packing-slips` |
| `?page=mealsdb&tab=po` | `?page=mealsdb-purchase-orders` |
| `?page=mealsdb&tab=po_admin` (+ `po_id`) | `?page=mealsdb-purchase-orders` (+ `po_id`) |
| `?page=mealsdb&tab=tasks` (+ task args) | `?page=mealsdb-tasks` (+ args) |
| `?page=mealsdb&tab=settings` | `?page=mealsdb-settings` |
| `?page=mealsdb` (no tab) | renders Home (no redirect) |

The two existing legacy redirects (`meals-db-quick-order`, `meals-db`) are kept.

### 7. Asset enqueueing

`MealsDB_Admin_UI::enqueue_assets()` currently keys on the `mealsdb` hook suffix
plus `$_GET['tab']`. It is rekeyed to the new page hook suffixes (and `tab` within
the Clients page). Every view keeps exactly the JS/CSS set it has today — the
enqueue conditions move; the asset lists do not change.

## What explicitly does not change

- All 66 AJAX endpoints, their handler classes, nonce contexts, capability checks,
  and rate-limit buckets.
- All view markup and behavior (beyond the drafts panel, ignored sub-view link,
  on-demand slips section, and PO generate button described above).
- Quick Order, Invoices, Reports, Staff, Cron Status, Event Log pages.
- The defense-in-depth permission pattern (view / handler / service layers).
- Capability tiers per page (baseline vs `manage_options`).

## Out of scope

- Any redesign of the report views' repeated date-range/Run/Export pattern
  (candidate for a later shared component; not this project).
- Deleting the Daily Slips AJAX endpoints or the Migration page code.
- Role-based menu variation (rejected: team shares roles).
- Renaming DB tables, options (other than the one new settings key), or slugs of
  the pages that already exist as submenu pages.

## Rollout plan — four independently shippable PRs

1. **PR 1 — Advanced-tools toggle.** Settings checkbox + `show_advanced_tools`
   key + menu visibility (hidden registration when off) for Rate Definitions,
   Data Ops, Migration. No other structural change. Ships value immediately
   (menu drops from 11 to 8 items for daily use).
2. **PR 2 — Slips + PO merges.** On-demand section added to Packing Slips; the
   PO list view gains the Generate button while still living at the `po_admin`
   tab (the move to its own page happens in PR 3); `slips`/`po` tabs retired
   with redirects.
3. **PR 3 — Menu restructure.** Dedicated Clients/Tasks/Settings/Purchase Orders
   pages; Clients tab consolidation (drafts panel, ignored sub-view); main page
   becomes a minimal Home shell (quick actions only); full legacy redirect map;
   asset enqueue rekeying.
4. **PR 4 — Home dashboard.** Tasks widget, today's deliveries, alerts strip on
   the Home shell.

Each PR follows the standard feature-track workflow (branch → PR → merge on
request; CI owns version bumps).

## Testing

- **Toggle:** menu contains/omits the three governed items per option state;
  governed pages still render (capability permitting) via direct URL when hidden.
- **Redirects:** every row of the legacy map lands on the right page with query
  args preserved.
- **Home:** renders with zero tasks/zones/alerts (empty state) and with data;
  the event-log count query is exercised.
- **Slips/PO merges:** existing endpoint tests unchanged; smoke-test that the
  moved forms post to the same actions with the same nonces.
- Existing suite must stay green (note baseline: 2 PDF tests fail locally for
  lack of mbstring/imagick — live-only paths).
