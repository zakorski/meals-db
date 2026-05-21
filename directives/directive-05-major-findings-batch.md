# Directive: Major Findings Batch — Uninstall, Cascade, Invoice Zone

**Severity:** MEDIUM (MAJ-2, MAJ-3, MAJ-4 from synthesis)
**Audit reference:** `recon-09-synthesis.md` MAJ-2, MAJ-3, MAJ-4
**Target files:** `uninstall.php`, `includes/class-clients.php`, `includes/admin/class-invoice-page.php`
**Estimated scope:** ~30-50 lines across 3 files
**Risk:** LOW — small, well-scoped fixes
**Must complete before:** cutover (MAJ-4); after cutover is acceptable for MAJ-2 and MAJ-3

---

## Context

Three independent medium-severity findings, grouped because each is a small file-level change with no cross-dependencies. Each can be completed and verified independently.

---

## Part A: MAJ-2 — Complete uninstall.php cron cleanup

### Context

The plugin's deactivation hook (`meals-db-main.php` lines 532-538) clears **5 cron hooks** but `uninstall.php` only clears 2. WordPress fires uninstall (`uninstall.php`) when the user clicks "Delete plugin" on an already-deactivated plugin. If the plugin is deactivated first and then deleted, deactivation handles the cleanup. But if it's deleted directly from active state, WP fires deactivation THEN uninstall — so technically both run. However, on some hosts and during WP upgrades, the deactivation hook can be skipped (e.g. if the plugin file is already removed before WP gets to run it).

The safe pattern is: `uninstall.php` must be self-sufficient. It should not depend on the deactivation hook having run.

### Pre-flight verification

```bash
# Confirm current state of uninstall.php
grep -n "wp_clear_scheduled_hook" uninstall.php
```

Expected current state: 2 hooks cleared (`mealsdb_nightly_allocation_sync`, `mealsdb_nightly_sync`).

Required state: 5 hooks cleared. The missing three are:
- `mealsdb_nightly_task_sync`
- `mealsdb_daily_report`
- `mealsdb_log_retention`

### Pre-flight: cross-reference with deactivation

```bash
grep -A 2 "register_deactivation_hook\|meals_db_check_requirements" meals-db-main.php | grep -i "wp_clear_scheduled"
```

OR directly:

```bash
grep -B 1 -A 1 "wp_clear_scheduled_hook" meals-db-main.php
```

Confirm the deactivation hook clears exactly these 5. If it clears more or fewer, the canonical list needs updating. Use whatever the deactivation hook clears as the source of truth.

### The fix

In `uninstall.php`, find the existing `wp_clear_scheduled_hook` calls (currently 2). They're around line 100-101.

Replace the cron-clearing block with a comprehensive version that clears all 5 hooks. The new block:

```php
// Clear ALL plugin-scheduled cron hooks. The deactivation hook in
// meals-db-main.php is the canonical list — if you add a new
// scheduled hook there, mirror it here. On hosts where WP-Cron is
// disabled (DISABLE_WP_CRON=true), these hooks won't fire anyway,
// but unscheduling them keeps wp_options.cron clean.
//
// HISTORY: The original uninstall cleared only 2 hooks; the rest
// were added in subsequent phases (task engine, Phase W observability).
// Direct uninstall without prior deactivation would leave 3 orphan
// cron entries that would attempt to fire against undefined
// callbacks on the next cron tick, producing PHP fatals in cron.log.
$plugin_cron_hooks = [
    'mealsdb_nightly_allocation_sync',
    'mealsdb_nightly_sync',
    'mealsdb_nightly_task_sync',
    'mealsdb_daily_report',
    'mealsdb_log_retention',
];

foreach ($plugin_cron_hooks as $hook) {
    wp_clear_scheduled_hook($hook);
}
```

Locate the existing two `wp_clear_scheduled_hook` calls and replace them with the block above. Preserve any surrounding context (the comment about "legacy name, for safety" can be folded into the new block's history note).

### Testing for Part A

```bash
php -l uninstall.php
```

Manual test note for the response:

> **Manual test required on staging only:**
> 1. Confirm all 5 cron hooks are scheduled: `wp cron event list | grep mealsdb`
> 2. Deactivate the plugin: `wp plugin deactivate meals-db`. Confirm hooks gone: `wp cron event list | grep mealsdb` (should be empty).
> 3. Re-activate, then re-confirm hooks scheduled.
> 4. Delete the plugin via WP admin (this triggers uninstall.php). Re-confirm hooks gone.

---

## Part B: MAJ-3 — Remove dead cascade code in `MealsDB_Clients::delete_client`

### Context

`MealsDB_Clients::delete_client` attempts to cascade-delete from `meals_drafts` and `meals_ignored_conflicts` keyed by `client_id`. Neither table has a `client_id` column:
- `meals_drafts` is keyed by `created_by` (the user_id of whoever saved the draft); the client identity is buried in the encrypted JSON payload.
- `meals_ignored_conflicts` is keyed by `field_name` + `woo_user_id`.

The `table_has_column` check guards each DELETE and silently skips. The Logger logs the delete as successful. The "cascade" is misleading code.

Operationally, drafts auto-prune after 30 days and ignored conflicts are per-field-name (not per-client), so leaving rows orphaned is harmless.

### Pre-flight verification

```bash
grep -n "delete_client\|table_has_column" includes/class-clients.php
```

Locate the `delete_client` method. Read its full body.

```bash
grep -n "client_id" includes/class-schema.php | head -30
```

Confirm `meals_drafts` and `meals_ignored_conflicts` schemas do NOT contain `client_id` columns. If they do, the audit was wrong and this directive doesn't apply. STOP and investigate.

### The fix

Remove the dead cascade attempts. Replace with a clear comment.

Locate the block(s) in `delete_client` that look like:

```php
if (MealsDB_DB::table_has_column($drafts_table, 'client_id')) {
    $wpdb->delete($drafts_table, ['client_id' => $client_id], ['%d']);
}
```

Replace each such block with a comment-only block:

```php
// NOTE: No cascade to meals_drafts or meals_ignored_conflicts.
//   - meals_drafts is keyed by created_by (user_id), not client_id.
//     The client identity is in the encrypted JSON payload, which
//     is intentionally opaque to direct queries. Drafts auto-prune
//     after 30 days via MealsDB_Drafts::prune_old_drafts.
//   - meals_ignored_conflicts is keyed by field_name + woo_user_id,
//     not client_id. Entries are per-conflict, not per-client.
// A previous version of this method tried to DELETE FROM these
// tables WHERE client_id = X. Both tables lack that column, so
// table_has_column guarded the DELETE and silently skipped. The
// cascade was dead code; this comment preserves the intent.
```

If there are TWO such blocks (one for drafts, one for ignored_conflicts), replace each with the appropriate comment subset. Keep the comments in their original positions to preserve the flow of the method.

### Testing for Part B

```bash
php -l includes/class-clients.php
```

Then exercise the method:

> **Manual test required:**
> 1. Create a test client on staging.
> 2. Save a draft for them (via the client form).
> 3. Add an ignored conflict referencing the test client.
> 4. Delete the test client via the admin UI.
> 5. Verify:
>    - The `meals_clients` row is gone.
>    - The `meals_drafts` row is still present (acceptable — drafts orphan and auto-prune).
>    - The `meals_ignored_conflicts` row is still present (acceptable).
>    - No PHP errors in `wp_content/debug.log`.

---

## Part C: MAJ-4 — Post-cutover invoice zone dropdown

### Context

`MealsDB_Invoice_Page::get_available_zones` queries:

```sql
SELECT DISTINCT delivery_area_zone
FROM meals_clients
WHERE client_type = 'SDNB'
  AND use_legacy_billing = 1
  AND delivery_area_zone IS NOT NULL
  AND delivery_area_zone != ''
ORDER BY delivery_area_zone
```

The `use_legacy_billing = 1` filter scopes to clients still on legacy billing. After cutover, when all clients are migrated (`use_legacy_billing = 0`), this returns empty. The fallback to `['M', 'S']` kicks in but produces a confusing UI (the dropdown shows zones unrelated to actual client data).

### Pre-flight verification

```bash
grep -n "use_legacy_billing" includes/admin/class-invoice-page.php
```

Confirm the filter exists. If it's already removed, this directive's Part C is already done.

```bash
wp db query "SELECT COUNT(*) AS total,
  SUM(CASE WHEN use_legacy_billing = 1 THEN 1 ELSE 0 END) AS legacy_count,
  SUM(CASE WHEN use_legacy_billing = 0 THEN 1 ELSE 0 END) AS modern_count
  FROM 2xnIt_meals_clients
  WHERE client_type = 'SDNB' AND active = 1"
```

Document the result in your response. If `modern_count > 0` already (some clients are post-cutover), this Part is urgent. If all clients are still `legacy_count` (pre-cutover state), this Part can wait but should still be done for forward-compatibility.

### Decision point

The directive offers two implementations. Confirm with the dev before proceeding.

**Implementation 1: Remove the filter entirely.**
- Query all SDNB clients regardless of billing mode.
- Simplest fix.
- Risk: if there are pre-existing zone names that only appear in legacy-billed clients, those become available post-cutover even if no modern client uses them.

**Implementation 2: Replace the filter with `(use_legacy_billing = 1 OR use_legacy_billing = 0)`** which is functionally identical to "no filter on this column" but documents intent.

Either is acceptable. Implementation 1 is cleaner; Implementation 2 leaves a documentation trail. **Recommend Implementation 1.**

### The fix (Implementation 1)

Locate the query in `get_available_zones` (approximately lines 87-109). Remove the `AND use_legacy_billing = 1` line.

The new query:

```php
$query = $wpdb->prepare(
    "SELECT DISTINCT delivery_area_zone
     FROM {$wpdb->prefix}meals_clients
     WHERE client_type = %s
       AND delivery_area_zone IS NOT NULL
       AND delivery_area_zone != ''
       AND active = 1
     ORDER BY delivery_area_zone",
    'SDNB'
);
```

Note three additional considerations:
1. **Added `AND active = 1`**: Inactive clients shouldn't surface their zones either. Verify the original query did this; if not, add it. Inactive clients with stale zones could otherwise pollute the dropdown.
2. **Verify the existing query doesn't have additional filters** that should be preserved. If it does, keep them.
3. **Method signature and return shape stay the same**.

Add a comment block:

```php
/**
 * Get the list of zones currently used by SDNB clients.
 *
 * HISTORY: A previous version filtered by use_legacy_billing = 1
 * to scope to clients still on the legacy billing path. Post-cutover
 * (when all clients have use_legacy_billing = 0), that filter
 * returned empty and the UI fell back to a hardcoded ['M', 'S']
 * which didn't match actual operational zones. The filter has been
 * removed; the query now considers all active SDNB clients.
 */
```

### Testing for Part C

```bash
php -l includes/admin/class-invoice-page.php
```

Functional test:

> **Manual test required:**
> 1. Navigate to Meals DB → Invoices.
> 2. Verify the zone dropdown is populated with the actual zone codes currently in use by active SDNB clients.
> 3. Cross-check by querying the DB directly: `wp db query "SELECT DISTINCT delivery_area_zone FROM 2xnIt_meals_clients WHERE client_type='SDNB' AND active=1"`. The dropdown must match.

---

## Out of scope for all parts

- Do NOT remove the `use_legacy_billing` column from the schema. It's still used by the invoice generator's routing logic and the migration tool.
- Do NOT add a new "zones" table or normalize the zone names. The current denormalized approach is fine for ~890 clients.
- Do NOT refactor `MealsDB_Clients::delete_client` beyond removing the dead cascade. The transactional structure and audit logging stay as-is.
- Do NOT consolidate the deactivation/uninstall cron list into a shared constant. The duplication is intentional (deactivation runs in plugin context; uninstall might run without the plugin's classes available).

---

## Acceptance criteria

The directive is complete when:

**Part A (uninstall):**
1. ✅ All 5 plugin cron hooks are cleared in `uninstall.php`.
2. ✅ The history comment is added.
3. ✅ `php -l uninstall.php` passes.

**Part B (cascade no-op):**
4. ✅ Dead cascade code in `MealsDB_Clients::delete_client` is replaced with explanatory comments.
5. ✅ `php -l includes/class-clients.php` passes.
6. ✅ Method signature and return type unchanged.

**Part C (invoice zone):**
7. ✅ `use_legacy_billing = 1` filter removed from `get_available_zones`.
8. ✅ `AND active = 1` added (if not already present).
9. ✅ History comment added.
10. ✅ `php -l includes/admin/class-invoice-page.php` passes.

When complete, your final response should include:
- A diff or summary for each of the three files modified.
- The cron-list verification result from Part A pre-flight.
- The client-billing-mode breakdown from Part C pre-flight.
- Manual test instructions for the dev (all three parts).
