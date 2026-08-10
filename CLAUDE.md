# CLAUDE.md

Guidance for Claude (and humans) working in this codebase. These conventions reflect what the existing code does consistently — follow them when adding or modifying functionality. When something here conflicts with what you observe in a specific file, the file usually wins (it has more context); but the conflict is worth flagging in your response.

> **Maintenance note (rewritten 2026-05):** this document was corrected against a full line-by-line audit (`recon-01`…`recon-14` in `/mnt/user-data/outputs/`, or wherever the audit docs live in your tree). Several factual claims in the previous version had drifted from the code (`dbDelta`, encryption entry points, cron times, runtime floor). Where this file states "what the code does," it now matches the audited reality. Where it states a *known bug*, that is called out explicitly with an audit reference. Keep this file in sync when you fix those bugs.

---

## Project context

This plugin replaces ~17 legacy plugins for **Meals and More NB**, a Moncton-based meal delivery operation serving SDNB (Social Development NB), Veterans Affairs Canada, and private customers. Roughly 890 active clients, 16,000+ orders/year.

**Deployment:** WordPress on cPanel hosting. **Runtime target: PHP 8.2+, WordPress 7.0+, latest WooCommerce (10.x), HPOS-exclusive** (orders live in `wc_orders` / `wc_orders_meta`, NOT `wp_posts` / `wp_postmeta` — assume this everywhere). DB prefix `2xnIt_`.

> **Known drift (audit STR-9):** the plugin header (`meals-db-main.php`), `composer.json`, and the activation check still declare `Requires PHP: 7.4` / `Requires at least: 5.8`. These are stale and should be bumped to PHP 8.2 / WP 7.0 to match the real target. Do not rely on the manifest values.

**Author convention:** the dev is Zak Sikorski (Fishhorn Design). The code is GPL-3.0. The dev is the primary reader of any code you produce — write for an audience that values correctness and traceability over cleverness.

**Communication style:** the dev does not want diplomatic softening. State problems plainly. If you find a bug, call it a bug. If you're uncertain, say so explicitly. No editorializing during code reads; save analysis for synthesis.

---

## Architectural patterns (follow these)

### 1. Defense-in-depth permission gating

**Every** destructive or data-modifying code path is gated at three layers:
1. **View layer**: `MealsDB_Permissions::enforce()` at the top of every admin view file.
2. **AJAX handler / form handler**: nonce check + capability check + rate limit.
3. **Service layer**: capability re-check via `MealsDB_Permissions::can_access_plugin()` or direct `current_user_can()`.

The pattern repeats deliberately. When adding new endpoints, replicate all three layers. Comment example from `MealsDB_Client_Form::is_authorized_to_modify_clients()`:

> "This guard exists so a future caller (WP-CLI command, REST endpoint, import script) that reaches these methods without going through the view layer can't write to meals_clients without the plugin's required capability."

For especially destructive operations (force_rebuild, delete_users), additionally:
- Require a typed confirmation string (`REBUILD`, `DELETE`).
- Rate-limit (`MealsDB_Rate_Limiter::check_rate_limit`).
- Audit-log via `MealsDB_Logger::log()`.

> **FIXED (directive QW-1, was audit MAJ-8):** `includes/ajax/class-ajax-db-sync.php` (`mealsdb_db_sync_phase`) now applies the `migration_destructive` rate limit (5/hour, fail-closed) — but on the FIRST chunk of a real write only (`!$dry_run && $offset === 0`), NOT on every call. This endpoint is driven by `views/data-ops.php`, which recursively re-posts per 100-row chunk and then again for the rates phase, so gating every chunk would 429 a normal sync mid-walk. The fix mirrors the established `MealsDB_Ajax_Migration::run_consolidated_phase` first-chunk-only pattern (and `::verify()`, which is deliberately unthrottled for chunked phases). A full sync consumes 2 tokens (clients + rates) — well within the bucket — while still capping fresh destructive runs.

### 2. Capability conventions

| Capability | When to require |
|---|---|
| `manage_woocommerce` | Default plugin access. Reading reports, editing clients, generating invoices. |
| `manage_options` | Settings changes, migration, destructive ops (force_rebuild, delete_users, schema_sync). |
| `delete_users && manage_options` | User deletion specifically. |
| `edit_product` | Product tab CRUD (with `manage_woocommerce` as fallback in service layer). |

`MealsDB_Permissions::required_capability()` returns the configured default (defaults to `manage_woocommerce`). Use this rather than hardcoding `'manage_woocommerce'` in new code. The `mealsdb_required_capability` filter is **whitelist-validated** (only `manage_woocommerce` / `manage_options` / `edit_shop_orders` are honoured) so a hook can't weaken access below the allowed set.

> **Design note (audit STR-6/STR-7):** access is a single flat capability gate — there is NO role-based granularity, even though the task system has an `assignee_role` field. Any user with the baseline capability can do everything short of the `manage_options`-gated operations. Also, report capability tiers are inconsistent: PO/spillover/slip reports use the baseline; the financial reconciliation reports require `manage_options`. This is intentional (financial = admin-only) but undocumented elsewhere — note it if you touch report gating.

### 3. Database access — use `wpdb`, not `mysqli`

The entire plugin uses `$wpdb` for database access against the WordPress database (prefix `2xnIt_`). **Do not introduce `mysqli` or PDO.**

> **Doc warning:** `DEV-NOTES-db-inventory.md` describes an OLD architecture that used a `mysqli` connection to a separate external database (`MealsDB_DB::connection()`, `MealsDB_Config::db_host()` etc.). **That architecture no longer exists** — the plugin was migrated to `$wpdb`. Ignore that DEV-NOTES file's mysqli/external-DB description; it is superseded and actively misleading. `MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS)` returns a `2xnIt_`-prefixed table in the WP database.

When constructing SQL:
- **Always** use `$wpdb->prepare()` for any value derived from user input or external data.
- For table names (which can't be parameterized), build via `MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS)` etc., and concatenate inside backticks.
- For dynamic column names, use a hardcoded whitelist before concatenation. Never accept a column name from `$_POST` without whitelist filtering. (`filter_to_known_columns` enforces this on the client write path — but note it SILENTLY DROPS unknown columns, see Pattern 5.)
- When you must escape a value into a non-parameterized position (rare), use `$wpdb->_real_escape()`. Note this does NOT escape LIKE wildcards; use `$wpdb->esc_like()` separately.

### 4. Encryption pattern

PII columns are encrypted at rest via `MealsDB_Encryption`. The canonical list lives in `MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS` and is currently:
`individual_id`, `requisition_id`, `vet_health_card`, `diet_concerns`, `customer_comments`.

The encryption itself is strong: encrypt-then-MAC, `hash_equals` before decrypt, random-bytes IV per value, key hierarchy (constant > env > option), a legacy-decrypt kill-switch, and idempotent `encrypt_columns` (safe to call on already-encrypted data).

**There are THREE auto-encrypting write paths.** All of them call `MealsDB_Encryption::encrypt_columns()` and **fail CLOSED** (abort the write on encryption failure):
1. **The form layer** — `MealsDB_Client_Form::save()` / `update()`.
2. **The static repository create** — `MealsDB_Clients_Repository::create()` (used by private-intake).
3. **The sync mutator** — `MealsDB_Sync_Mutate` create + update paths.

The **instance** repository methods `MealsDB_Clients_Repository::create_client()` / `update_client()` do **NOT** auto-encrypt. They are safe today only because `encrypt_columns` is idempotent and the data reaching them is already encrypted — but if you add a NEW caller of the instance methods that passes plaintext PII, you must call `MealsDB_Encryption::encrypt_columns($data)` first.

> **Correction from the previous CLAUDE.md:** the old version said "only the form encrypts; the repository does not." That was wrong — there are three auto-encrypt entry points (form, static `create()`, sync mutator), and only the *instance* create/update skip it. Don't assume the form is the only encrypting path.

> **FIXED (directive QW-2, was audit MAJ-4):** client DRAFTS now fail CLOSED like the three live write paths. `MealsDB_Client_Form::encode_draft_payload` returns `false` (never plaintext) when encryption is unavailable or throws; the caller surfaces a clean "Failed to save draft." rather than silently persisting PII as cleartext in `meals_drafts`. Note the READ path (`decode_draft_payload`) still decodes legacy plaintext drafts — only WRITING cleartext is forbidden.

Decryption:
- `MealsDB_Encryption::decrypt($ciphertext)` — strict, throws on failure.
- `MealsDB_Encryption::safe_decrypt($value)` — best-effort, returns input unchanged on failure. Use this when reading legacy data that may be plaintext.

Adding a new encrypted column requires:
1. Add to `MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS`.
2. If queryable by exact match: add a deterministic index column (`{column}_index CHAR(64)`) via schema + `MealsDB_Client_Form::$deterministic_index_map`.
3. `deterministic_hash($plaintext)` for the index. Never hash ciphertext.

> **Known issues (audit STR-10):** the deterministic index hashes are unsalted SHA-256 of the government IDs (consider a pepper); the cipher and MAC currently share one key; and `delivery_initials` has a deterministic-hash *index* column that the form populates but nothing reads (the initials uniqueness check queries the plaintext column instead) — that index is dead weight for that field.

### 5. Form-side vs DB-side column names

The codebase maintains two parallel column name vocabularies (plus some aliases — three vocabularies in total in a few spots):

| Form-side (UI, `$_POST` keys) | DB-side (canonical schema) |
|---|---|
| `wordpress_user_id` | `wp_user_id` (clients) / `wordpress_user_id` (staff — note the split) |
| `phone_primary` | `client_phone_1` |
| `phone_secondary` | `client_phone_2` |
| `address_postal` | `postal_code` |
| `client_comments` | `customer_comments` |
| ... | (~20 more — see `MealsDB_Client_Form::map_form_to_db()`) |

`MealsDB_Client_Form::load_client()` maps DB → form (for the edit view). `MealsDB_Client_Form::map_form_to_db()` maps form → DB (for the save path).

**Common bug pattern:** writing form-side names to the repository, where `filter_to_known_columns` silently drops them as unknown. The handler returns success and the change disappears. If you're touching client data outside the form flow, double-check which vocabulary you're using.

When in doubt: read from `MealsDB_Clients_Repository::get_client_by_id()` (returns DB-side names), pass to `MealsDB_Client_Form::map_form_to_db()` if you need to convert for a form-side caller.

> **Design note (audit STR-1):** there is NO database-level referential integrity (no foreign keys) anywhere in the schema — this is intentional (the prior audit's FK directive was deferred) but it is the root of several issues: orphaned tasks after rule deletion, and the order→client routing fragility below.

> **Allocation-routing risk (audit MAJ-1):** there is no UNIQUE constraint on `meals_clients.wp_user_id`, and one link path (`link_client_to_wp_user`) writes it without the uniqueness check its sibling enforces. The allocation rebuilder routes orders to clients via the `wp_user_id ↔ wc_orders.customer_id` join, so two active clients sharing a `wp_user_id` makes routing nondeterministic. Per the operator, duplicates are RARE BUT LEGITIMATE (e.g. an SDNB recipient who is also a Veteran, or a government client buying extra meals personally). So the intended fix is a **soft guard: allow the link but warn/flag by default**, NOT a hard unique constraint that would block the legitimate case.

### 6. Logging pattern

**TWO logging worlds, kept deliberately apart (directive STR-LOG, Option A).** What used to be three operational loggers (`meals_job_log` + `meals_hook_log`) is now ONE operational trunk; the audit log stays separate.

**The boundary (non-negotiable):**
- **An *attempt/outcome*** → the operational trunk (`meals_event_log`). Pruned freely, queried freely.
- **A *committed change to a data record*** → the audit log (`meals_audit_log`). Append-only, long retention, PII-fingerprinted, sensitive to read. **Never collapse the two.** They may share the Event Log dashboard (separate tabs) but never the table.

**`MealsDB_Event_Log`** — the operational trunk. ONE write path for jobs, hooks, swallowed exceptions, degraded outcomes:
```php
MealsDB_Event_Log::record([
    'severity' => 'error', 'category' => 'allocation', 'subsystem' => 'allocation_rebuilder',
    'event' => 'rebuild.dirty_month', 'outcome' => 'degraded',
    'message' => '…', 'context' => ['client_id' => 42], 'correlation_id' => $run_id,
]);
```
Plus job-lifecycle helpers on the same table: `start_job()` (INSERT `outcome='running'`, returns log_id), `finish_job($id, $stats, $outcome)`, `fail_job($id, $msg, $stats)`, `heartbeat($id, $stats)`. The five inherited disciplines are enforced in `record()`: **PII-scrub on write** (message + recursive context), **UTC** (`gmdate`), **16KB / depth-10 context cap**, **fail-safe write** (a throwing `$wpdb` is swallowed → `error_log`, never propagates), **retention-aware** (`MealsDB_Log_Retention` prunes the trunk by severity+age, NEVER prunes `running`).

**The `degraded` outcome** is the point of the whole exercise. `outcome` is one of `succeeded | failed | degraded | running`. `degraded` = "I continued, but swallowed a problem" (caught exception, partial result, a re-sum that found nothing, a CREATE that failed but we pressed on). It doesn't *prevent* a careless caller from writing `succeeded` — but it turns "silently lied" into "explicitly chose," which is greppable and code-reviewable. The dashboard + digest default to `outcome IN ('failed','degraded')`. **When you write a job or catch-and-swallow, make the success/failure/degraded decision correct — don't call `finish()`/log `succeeded` on a path that ate an error.**

**`MealsDB_Job_Logger` and `MealsDB_Hook_Logger` are now THIN FACADES over the trunk.** Their public signatures are unchanged (`start/finish/fail/heartbeat/last_success/recent_runs/latest_in_window`; `record/count_in_window/count_by_outcome/last_fire/trailing_window_counts` + the `OUTCOME_*` constants), so the ~53 existing call sites were not touched — keep calling the facades; they translate to trunk rows (`category='job'` / `category='hook'`). Hook outcomes map onto the trunk as: `processed`→`succeeded`/severity `info`, `skipped`→`succeeded`/severity `debug`, `errored`→`degraded`/severity `warning` (the severity pairing is how `count_by_outcome` reconstructs the three-way breakdown — don't write a `category='hook'` row directly with a different severity). The reader methods re-express the old `status`/`error_message` shapes from the trunk's `outcome`/`message` columns so the daily report and Cron Status page render identically. **The old `meals_job_log` / `meals_hook_log` tables are retired** — removed from `MealsDB_Schema` and `MealsDB_Tables`; `uninstall.php` drops the legacy physical tables by literal name. On installs upgrading across this change they linger empty (no new writes) until uninstall.

**`MealsDB_Logger::log(...)`** — the audit log (`meals_audit_log`). Built-in PII redaction. Use for: client edits, link/unlink, deletes, schema changes, force rebuilds, key rotations. NOT collapsed; NOT pruned by `MealsDB_Log_Retention`.

**`MealsDB_Logger::error($message)`** — operational `error_log()` line with PII scrubbing. Still fine for a quick caught-exception breadcrumb, but for anything an operator should *see*, also `MealsDB_Event_Log::record(outcome:'degraded', …)` so it surfaces on the dashboard/digest. Pattern:
```php
catch (\Throwable $e) {
    MealsDB_Logger::error('[MealsDB Subsystem] failed: ' . $e->getMessage());
    MealsDB_Event_Log::record([
        'severity' => 'error', 'category' => 'sync', 'subsystem' => 'subsystem',
        'event' => 'thing.failed', 'outcome' => 'degraded', 'message' => $e->getMessage(),
    ]);
    return false;
}
```

**Dashboard + digest:** WP Admin → Meals DB → **Event Log** (`manage_options`, no external surface; two tabs — operational trunk + read-only audit trail). A daily `mealsdb_event_digest` sweep (~05:00, out of the hot path) emails a scrubbed summary of failed/degraded events since the last run. Recipients reuse the daily-report recipient option.

### 7. Catch `\Throwable`, log, swallow — don't rethrow

Background jobs, hook handlers, and AJAX endpoints should not propagate exceptions:

```php
try {
    // work
} catch (\Throwable $e) {
    if (class_exists('MealsDB_Hook_Logger')) {
        MealsDB_Hook_Logger::record($hook_name, 'errored', ['error' => $e->getMessage()]);
    }
    MealsDB_Logger::error('[MealsDB Hook] failed: ' . $e->getMessage());
}
```

The pattern: log the failure, swallow the exception, return a sentinel (false / null / empty array). This keeps WC's event loop from breaking when our handler has a bug.

User-facing methods (form save/update, AJAX handlers) should return `false` or `WP_Error` on failure rather than throwing.

> **Caveat:** "swallow and report a sentinel" is correct for keeping checkout alive, but make sure the sentinel is then HANDLED. The recurring failure class in this codebase is swallowing an error and then proceeding as if it succeeded (e.g. marking a job successful, marking schema current). Swallow the exception; do not pretend the work happened.

### 8. Rate limiting

`MealsDB_Rate_Limiter::check_rate_limit($bucket)` — returns false when the user has exceeded the bucket's quota. The limiter is atomic (object-cache `incr` or a MySQL `ON DUPLICATE KEY UPDATE` fallback), **fails CLOSED for mutating actions** and fail-open for reads when the backend is unavailable, and uses a trusted-proxy allowlist for `X-Forwarded-For`. Standard buckets:

| Bucket | Quota | Used by |
|---|---|---|
| `client_modify` | 50/hour | Client CRUD AJAX |
| `quick_order_read` | 200/hour | QO list/search + most report reads |
| `quick_order_create` | 50/hour | QO order creation |
| `client_search` | 100/hour | Client search |
| `sync_operations` | 100/hour | Sync |
| `delivery_slips` | 100/hour | Slip generation |
| `task_modify` | 100/hour | Task / rule mutations |
| `settings_modify` | 20/hour | Settings + bulk backfills |
| `migration_destructive` | 5/hour | Migration phases, cleanup, reset |
| `schema_rebuild` | 2/hour | Force rebuild (drops every plugin table) |

New destructive endpoints should use a bucket. Pattern at AJAX handler top:
```php
if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('client_modify')) {
    wp_send_json_error(['message' => __('Rate limit exceeded.', 'meals-db')], 429);
}
```

### 9. Schema changes

The plugin's tables are defined in the canonical schema (`MealsDB_Schema`). Schema setup/upgrade runs through `MealsDB_Installer::install()` → **`MealsDB_Schema_Sync::run_full_sync()`**.

> **Schema_Sync now MODIFIES (H7 — this replaces the old "NEVER MODIFIES" note).** The installer does **NOT** use `dbDelta`. It uses `MealsDB_Schema_Sync::run_full_sync()`, which:
> - **ADDs** missing tables and columns (always).
> - **Auto-applies SAFE column MODIFYs** on the version-bump path — value-preserving changes (widen `VARCHAR`/`CHAR`/`TEXT`, `INT`→`BIGINT`, add an `ENUM` value, relax `NOT NULL`, change a `DEFAULT`) via **online DDL** (`ALGORITHM=INPLACE, LOCK=NONE`). `MealsDB_Schema_Alter_Planner::classify()` is the SAFE-vs-RISKY authority; `MealsDB_Schema_Alter_Executor` applies them.
> - **Does NOT auto-apply RISKY changes** — narrowing, removing an `ENUM` value, tightening to `NOT NULL`, a type/sign change, or **any `DECIMAL`/money change** (money is always manual, by operator decision). It also won't auto-apply a SAFE change MySQL can't do INPLACE (a COPY). These are surfaced in the **Data-Ops → "Schema Changes"** tool, where the operator reviews the exact ALTER, a **pre-flight** row-count check *blocks* anything that would lose data, and a typed `ALTER` confirmation applies it (under maintenance mode if a COPY is needed).
> - **PRIMARY KEY drift** is detected/surfaced but never auto-altered.
>
> So: for a SAFE column widening, just update the canonical schema + bump the version — it applies on the next admin load, idempotently. For a RISKY change, use the tool (or write a bespoke `ALTER` migration in `install-schema.php`, e.g. `widen_vet_health_card_column`). A SAFE ALTER that genuinely fails feeds `$results['errors']`, so the version isn't marked current on a real DDL failure. Production is **MySQL 8.0.46**, so online DDL is available. (This closes audit LB-6 / recon-02: the drift report is no longer discarded.)

To add a NEW column or table (additions are handled):
1. Update the canonical schema.
2. Bump `MEALS_DB_VERSION` in the plugin header.
3. The `mealsdb_maybe_upgrade_schema` admin_init hook runs `MealsDB_Installer::install()` on next admin page load. The lock mechanism (atomic `INSERT IGNORE` on `mealsdb_install_lock`) prevents concurrent upgrades.

> **Known bug (recon-01):** `install()` continues past individual CREATE-TABLE failures and the caller still marks the schema version current. A failed table create can leave the schema partially built but recorded as up-to-date. Be cautious adding tables; verify creation succeeded.

For data backfills (one-time data corrections, not schema changes), put them in `includes/services/` as `class-backfill-*.php` and wire them up via the updates page or a settings AJAX endpoint.

### 10. Cron jobs

Plugin-scheduled cron hooks and their **actual** scheduled times (verified against the code):
- `mealsdb_nightly_sync` — **02:00** — sync engine (`class-sync.php`).
- `mealsdb_nightly_allocation_sync` — **03:00** — allocation engine (`class-allocation-hooks.php`). **NOT 02:00.**
- `mealsdb_nightly_task_sync` — **02:00** (design) — task engine (`class-task-cron.php`).
- `mealsdb_daily_report` — **04:00** — Phase W health report.
- `mealsdb_log_retention` — **04:30** — Phase W log pruning (hook_log 90d, job_log 365d; does NOT prune `meals_allocation_errors`).

> **FIXED (directive LB-1):** the nightly allocation sync now **materialises dirty client-months before re-summing.** `MealsDB_Allocation_Hooks::nightly_sync()` calls `MealsDB_Allocation_Rebuilder::rebuild_all_dirty()` (the same proven path used by invoice generation and the manual Data-Ops button) and then runs the existing `bulk_recalculate_month` re-sum as a belt-and-suspenders consistency pass. The rebuild stats are recorded in the job-logger `finish()` payload (`dirty_rebuild_stats`). This was previously the single highest-impact bug — a dirty-but-unbuilt month stayed empty while the job logged success, cascading into billing, reporting, driver cash collection, and the Quick Order allowance preview. It depended on LB-3 (below): the rebuilder now skips finalized months, so running it nightly is safe.

**When adding a new cron hook:**
1. Schedule it via `wp_schedule_event()` in the relevant class's `register_hooks()`.
2. Register a handler with `add_action()`.
3. Add it to the deactivation hook in `meals-db-main.php` so it's cleared on deactivate.
4. Add it to `uninstall.php` so it's cleared on uninstall too. (uninstall.php has historically lagged on this — verify.)

cPanel cron fires WP-Cron at `:15` and `:45` past each hour, so jobs run ~15 minutes after their scheduled UTC time. Don't rely on exact-minute precision.

### 11. UTC timestamps everywhere

Use `gmdate('Y-m-d H:i:s')` for any timestamp written to the database. Use `current_time('mysql', true)` if you want WP's interpretation of UTC (equivalent).

Display layer can convert to site timezone via `wp_timezone()`.

Don't use `date()` (uses server local timezone) or `time()` for storage. The bug class is silent until DST. (A few spawn/display paths still use server-local `date()`/`CURDATE()` — see recon-08; prefer UTC in new code.)

### 12. Settings storage

Plugin settings live in three places:
- **wp-config.php constants** — preferred for secrets (`MEALS_DB_KEY`, `MEALSDB_TRUSTED_PROXIES`, etc).
- **`mealsdb_settings` option** — for non-secret config.
- **Dedicated options** — for things with their own lifecycle (`mealsdb_fee_product_ids`, `mealsdb_overage_product_ids`, `mealsdb_zone_delivery_schedule`, `mealsdb_appetito_excluded_categories`, `mealsdb_db_version`).

`autoload='no'` for options that aren't read on every page load. The schema version option (`mealsdb_db_version`) IS autoloaded since it's checked on every admin_init.

> **Note (recon-01/12):** the legacy-decrypt kill-switch option (`mealsdb_legacy_decrypt_disabled`) is read by the encryption layer but has no settings-page UI — it appears to be wp-config/CLI-only. If you need to expose it, add a control; don't assume one exists.

### 13. Nonces

Don't introduce a new nonce context unless the action is meaningfully distinct from existing ones. Current contexts include:

- `mealsdb_nonce` — most general AJAX
- `mealsdb_settings_nonce` — settings save
- `mealsdb_migration_nonce` — migration phases
- `mealsdb_db_sync_nonce` — DB sync phases
- `mealsdb_invoice_nonce` — invoice generation
- `mealsdb_quick_order_create_order` — QO order creation (stricter)
- `mealsdb_generate_initials` / `mealsdb_validate_initials` — initials AJAX
- Plus form-specific ones (`mealsdb_force_rebuild`, delete-users, etc.)

If you're tempted to add a new nonce: ask whether the action category warrants it. Generally, "destructive" gets its own nonce; "read-only with rate limit" doesn't.

### 14. CSV export safety

If you're writing data to a CSV (reports, invoices, downloads), route through the CSV safety helper (`MealsDB_CSV::cell()` server-side; `Report.csvCell` in `report-utils.js` client-side). It prepends `'` to cells starting with `=`, `+`, `-`, `@`, tab, or CR to neutralise spreadsheet formula injection.

Don't bypass this. CSV injection is a real attack vector and the legacy system had instances of it.

> **FIXED (directive QW-3, was audit MAJ-3 / recon-07/12):** the guard no longer corrupts legitimate NEGATIVE money. Both `MealsDB_CSV::cell()` (PHP) and `Report.csvCell` / `csvCell` (JS, `report-utils.js`) now exempt well-formed plain numbers — anchored `/^[-+]?\d+(\.\d+)?$/` — from the leading-char formula guard, so `-10.24` exports intact instead of becoming `'-10.24`. A leading `-`/`+`/`@`/`=` on a NON-numeric string (e.g. `-2+3` in a name field) is still neutralised — the distinction is numeric-VALUE vs text-FIELD. `cell_strict()` is intentionally unchanged (it strips for must-never-be-a-formula exports). `test-reports-csv-injection.php` now asserts the corrected behavior.

### 15. File handling

For file uploads, follow the migration upload pattern in `MealsDB_Ajax_Migration::upload_file()`:
1. Extension whitelist. 2. Content sniffing (magic bytes). 3. Size cap. 4. Random destination filenames in a dedicated subdir. 5. `.htaccess` + `index.php` web blockers in the subdir. 6. 0600 permissions. 7. Path-traversal defense via realpath containment.

If you're writing files outside the upload directory (cache, exports, etc.), use `wp_get_upload_dir()` or `WP_CONTENT_DIR` — never `__DIR__` for writable paths.

---

## Code style

### PHP conventions

- **Strict types where it matters.** Type hints on method signatures (`int $client_id`, `array $data`, `?string $value`, return types `: bool`, `: array`, `: void`).
- **`use \Throwable` not `\Exception`** when catching. PHP 7+ Errors (TypeError, ValueError, DivisionByZeroError) extend `\Throwable` but NOT `\Exception`; catching only `\Exception` lets them escape. Always `\Throwable` for outer try/catch in handlers.
- **`function_exists()` / `class_exists()` guards** for WP/WC functions when the class might load outside an admin context (test fixtures, WP-CLI).
- **Static methods for stateless utilities, instance methods for stateful services.** `MealsDB_Logger` is all static; `MealsDB_Task_Engine`, `MealsDB_Purchase_Orders` are instantiated.

### Naming

- **Classes**: `MealsDB_*` prefix, underscores between words. Filename `class-<slug>.php` where slug is lowercased with hyphens.
- **Methods**: snake_case.
- **DB columns**: snake_case, descriptive. Use the existing vocabulary (Pattern 5) — don't introduce new conventions mid-table.
- **Hooks**: `mealsdb_<action>` for our own; consume WC hooks by their WC names.
- **Options**: `mealsdb_<purpose>`.
- **Constants**: `MEALS_DB_*` for plugin-level (file paths, version), `MEALSDB_*` for runtime config (constants a user might set in wp-config.php).

### Comments

The codebase has an unusually strong comment culture. Match it.

**Comments explain *why*, not *what*.** When you fix a bug, leave the explanation: "Previous behavior accepted X, which let an attacker do Y; now we strict-validate Z." There are dozens of these throughout the codebase; they are load-bearing documentation. `// Increment counter` above `$count++` is noise — don't add that kind.

### Translation

Use WP's translation functions (`__()`, `esc_html__()`, `esc_attr__()`) for all user-facing strings. Domain is `'meals-db'`. Do NOT define a fallback `__()` function in your file.

### Output escaping

- HTML: `esc_html()` for text, `esc_attr()` for attributes, `esc_url()` for URLs, `esc_textarea()` for textarea content.
- JS contexts (inline JS): `esc_js()`. Client-side, escape before any `.html()`/`innerHTML` insertion — there is a shared `Report.esc` in `report-utils.js`, though several files reimplement their own escaper (consolidate when you can; audit STR-2).
- SQL: `$wpdb->prepare()` or `$wpdb->_real_escape()`.
- Late-escape pattern: escape at the output point, not at the input point. Store raw, escape when emitting.

The view and JS layers are currently XSS-clean (audited) — keep them that way.

---

## Things the codebase deliberately does NOT do

### Don't replicate the legacy overage workaround

The legacy Enzebra system used product IDs 5056 (mains), 5059 (Z side non-tax), 5180 (Z side tax) as "overage" line items in WC orders. This produced significant net under-billing. **The new plugin does NOT replicate this** — it uses the allocation engine to track overage as separate ledger entries. If you find yourself adding "overage workaround product" logic, stop.

### Don't bypass the form validation pipeline

`MealsDB_Client_Form::validate()` is the single source of truth for client data validation. If you're tempted to write a new validation function, extend `validate()` instead. Two parallel validation paths invariably diverge.

### Don't query orders via `wp_posts` on HPOS

The site is HPOS-exclusive. Order data lives in `wc_orders`, `wc_orders_meta`, `wc_order_addresses`, `wc_order_operational_data`. Use `wc_get_order($order_id)` / `wc_get_orders($args)`, or the `MealsDB_WC_Order_Query` service (the cleanest data-access layer in the plugin — HPOS-correct, unifies both fee mechanisms). Direct `wp_posts` queries with `post_type='shop_order'` return zero rows.

> **FIXED (directive LB-5):** the trash/delete deallocation handlers are now registered on the HPOS order-lifecycle hooks `woocommerce_trash_order` / `woocommerce_delete_order` (which fire with an order ID, only for orders) instead of the wp_posts hooks `trashed_post` / `before_delete_post` (which never fire for orders under HPOS). `on_order_trashed` / `on_order_deleted` no longer use the `get_post_type($id) === 'shop_order'` guard (it never matched under HPOS, leaving both handlers dead so trashed/deleted orders silently kept counting in the allocation ledger); they now route through the shared `process_deallocation_hook()` helper. `deallocate_order` was already self-validating (no-ops on an ID with no allocations, then marks the affected client-months dirty for the rebuilder — which, post-LB-3, won't touch finalized months). `MealsDB_Daily_Report::INSTRUMENTED_HOOKS` now monitors the two HPOS hooks so their silence reflects reality. The masking stub in `test-allocation-hooks-swallow.php` (`get_post_type()→'shop_order'`) was removed and a regression test (`tests/test-hpos-deallocation.php`) proves the handlers mark client-months dirty through the real, ungated path.

### Don't use `\Exception` when you mean `\Throwable`

(See Code style.) Always `\Throwable` for outer try/catch in handlers.

### Don't write inline `<script>` blocks > 20 lines

Extract interactive JS to `assets/js/` files with proper enqueue rather than embedding large blobs in PHP.

### Don't introduce new form-side vs DB-side column name pairs

Stick to the existing pairs (Pattern 5). If you need a new column, use the same name on both sides if possible; otherwise document the mapping in `MealsDB_Client_Form::map_form_to_db()` and the reverse.

---

## Billing & pricing (read before touching invoices)

Billing is the highest-risk area in the plugin. Key facts:

- **Money math:** all money goes through `MealsDB_Money` (integer cents, half-up rounding, bcmath fallback). Use it — do not do float math on money. (There is currently NO dedicated unit test for `MealsDB_Money`; be especially careful.)
- **Per-client fees:** `meals_clients.client_contribution` and `delivery_fee` are per-client DECIMAL columns. **FIXED (directive LB-2):** `MealsDB_Order_Fees::add_fee_product()` now overrides each fee line's subtotal/total to the per-client amount (rounded via `MealsDB_Money`) instead of billing the flat WC product CATALOG price. It deliberately KEEPS the fee PRODUCT shape (5675/4122) rather than switching to `WC_Order_Item_Fee`, so the reconciliation/order-query layer (which sums `_line_subtotal`) still finds fees uniformly. The form, slips, and Quick Order preview already treated the per-client columns as authoritative; billing now agrees with them.
- **Finalized months are immutable.** **FIXED (directive LB-3):** the rebuilder now treats finalized months as read-only. `rebuild_client_month` skips a finalized **target** month (and consumes its dirty flag), and `fill_months` excludes any finalized month in its 3-month window from both the DELETE and the inserts — so a finalized **neighbour** (e.g. finalized May pulled in while rebuilding open June) keeps its submitted detail byte-identical. Meals that would have spilled into a finalized month are surfaced as unplaced (a spillover error), which is correct — you cannot add meals to a submitted invoice. The finalize check reads `is_finalized` on the `meals_client_allocations` summary (per `client_id`+`billing_month`); the migration tool's `$finalized_set` skip remains the equivalent pattern for the migration path.
- **Operational constants:** rates, fee/overage product IDs, category IDs, and the Apetito pallet size live in `MealsDB_Operational_Constants` (the single source of truth — use it, don't hardcode). **FIXED (directive LB-7):** the invoice generator's duplicate `$sdnb_rate_tiers` array (keyed on the combined-price STRING `'14.66'`/`'15.47'`) is **deleted**. Both consumers (`get_phase2_billing_data` and `split_into_invoice_lines`) now derive the side rate and the line-2 secondary main rate from `MealsDB_Operational_Constants::get_sdnb_side_rate()` / `get_sdnb_main_rate('secondary', $rural)`. Rurality is resolved from the client's `delivery_area_zone` (zone `'S'` = Sussex = rural; see `SDNB_RURAL_ZONE_CODES` / `is_rural_zone()`), **not** from the rate value — so a future rate flip can no longer make the lookup key miss and silently default to 0. The eventual cutover flip is now a one-place edit of the six `SDNB_RATE_*` constants.

### HST / tax rules (confirmed with the operator, 2026-05)

- **Mains are NEVER taxed.** (The code already does this correctly — tax is only ever applied to taxable sides.)
- **Prices are PRE-TAX.** HST is *added*, not backed out.
- **Taxable sides:** HST = side price × the configured HST rate (NB = **15%**). Non-taxable sides get no HST.
- The taxable/non-taxable split is tracked end-to-end (`tax_sides_count` / `nontax_sides_count` on the allocation detail; the rebuilder allocates taxable-first; the invoice taxes only taxable sides). Taxability is per-item via the products table `taxable` flag.
- **FIXED (directive LB-7):** HST is computed as `taxable_sides × pre-tax side rate × hst_rate` at both SDNB sites (mains never taxed). The obsolete baked-in net-portion multipliers (`0.672`/`0.82`/`0.681`, formerly `$sdnb_rate_tiers[...]['hst_multiplier_*']` and `HST_MULTIPLIER_*` constants) are deleted.
- **SDNB HST rate is sourced LIVE from WooCommerce** (`MealsDB_Invoice_Generator::resolve_hst_rate()` → `WC_Tax::get_rates('')`, mirroring the Quick Order preview), per the operator's decision. There is **no SDNB HST constant** — change the rate once in WC Settings → Tax. **NO FALLBACK by design:** if WC tax is disabled/unconfigured the rate resolves to 0 and the invoice's HST is 0 (the zero case is logged but does not change the value). Keep the WC standard tax rate configured at 15% or the government CSV under-reports HST. The VAC path is unchanged — it still uses `VAC_SIDES_HST_RATE` (15%); switching VAC to WC would be a separate change.
- **Note:** because the old multipliers were NOT a clean 15%, the corrected math produces DIFFERENT (correct) HST amounts on SDNB invoices — review against a known-good legacy invoice with the operator before cutover.
- **New-portal caveat (LB-7):** the SDNB client queries must select `delivery_area_zone` — it's what resolves the urban vs rural side rate for HST. `generate_sdnb_new_portal` originally omitted it (fixed); if you add another SDNB generator, select the zone or rural clients silently bill the urban side rate.

### Current pricing (pending changes — see the operator's rate email)

New rates are approved. **Do NOT change the SDNB values yet** — the operator has said to leave SDNB pricing as-is pending Social Development IT's answer on retroactive billing. (Retroactive billing is the agency's operations problem and is OUT OF SCOPE for the plugin.) The *code* fixes (de-dup rates, drop the price-keyed lookup, WC-sourced HST) are **done** (directive LB-7); the *value* flip is now a one-place edit of the six `SDNB_RATE_*` constants at cutover (the HST rate itself lives in WC Settings → Tax).

| Item | New value (pre-tax) | When |
|---|---|---|
| SDNB urban — main / side / combined | 11.40 / 5.00 / 16.40 | at cutover (deferred pending SD IT) |
| SDNB rural — main / side / combined | 12.25 / 5.05 / 17.30 | at cutover (deferred) |
| Private — main / side | 9.50 / 4.25 | at cutover |
| Veteran — main / side | 9.50 / 4.25 **via the existing VAC billing mechanism** (change pricing only, NOT the mechanism) | at cutover |
| VAC per-main allowance | **PENDING — confirm with the operator** (currently $10.64) | — |

There is **no date-effective pricing** mechanism and (per the operator) none is needed — rates are edited manually at cutover. The June website price flip for private/Veteran is a WooCommerce product-price change, done outside this plugin.

---

## Subsystems quick reference

When working on a specific area, read the relevant audit doc first (`recon-NN-*.md`). They're more accurate than file inspection alone. **Audit-doc numbering below refers to THIS audit's recon files** (the previous CLAUDE.md pointed at the prior audit's numbering, which was wrong).

| Subsystem | Primary files | Audit doc |
|---|---|---|
| Bootstrap, autoloader, lifecycle, config, CI | `meals-db-main.php`, `class-plugin.php`, `class-autoloader.php` | recon-01 |
| Schema & DB layer | `class-schema.php`, `class-schema-sync.php`, `class-db.php`, `install-schema.php` | recon-02 |
| Allocation engine (event-sourced ledger) | `class-allocation-engine.php`, `class-allocation-rebuilder.php`, `class-allocation-hooks.php`, `class-collection-calculator.php` | recon-03 |
| Billing & invoicing | `class-invoice-generator.php`, `class-order-fees.php`, `class-rates.php`, `class-money.php` | recon-04 |
| Sync (WP user ↔ meals_client) | `class-sync.php`, `sync/class-sync-mutate.php`, `class-private-intake.php` | recon-05 |
| Clients & encryption | `class-clients-repository.php`, `class-client-form.php`, `class-encryption.php`, `class-encryption-migrator.php` | recon-06 |
| Reports, CSV, order-query | `class-reports.php`, `class-wc-order-query.php`, report views | recon-07 |
| Task engine | `class-task-engine.php`, `class-task-registry.php`, `class-task-rules.php`, `class-task-cron.php`, `task-types/*` | recon-08 |
| Delivery slips & PDF | `class-slip-pdf-generator.php`, `class-delivery-slip-generator.php` | recon-09 |
| Quick Order | `class-quick-order-ui.php`, `class-quick-order-products.php`, `class-quick-order-ajax.php`, `assets/js/quick-order.js` | recon-10 |
| Migration, products, staff, initials, permissions, rate limiter, observability, drafts, constants | `class-migration-consolidated.php`, `class-products.php`, `class-staff.php`, `class-initials*.php`, `class-permissions.php`, `class-rate-limiter.php`, `class-job-logger.php`, `class-hook-logger.php`, `class-log-retention.php`, `class-operational-constants.php` | recon-11 |
| Admin UI, views, remaining AJAX, client-side JS/CSS | `views/*`, `includes/admin/*`, `ajax/*`, `assets/js/*` | recon-12 |
| Test suite | `tests/*` | recon-12.5 |
| Directives, patches, doc drift | `directives/*`, `CLAUDE.md`, `DEV-NOTES-*` | recon-13 |
| **Synthesis & launch verdict** | — | **recon-14** |

---

## When you're stuck

1. **Read the audit document for the relevant subsystem.** It tells you what's there, what's broken, and what the dev already decided.
2. **Grep for similar patterns elsewhere.** Almost any pattern you need has been done somewhere; matching the existing style beats inventing a new one.
3. **Check the `directives/` folder.** The numbered `directive-NN-*.md` files are the prior-audit remediation series (mostly applied — see recon-13). New fix work from the current audit will arrive as its own directive series.
4. **When in doubt, ask the dev** rather than guessing.

---

## Closing note

This codebase is the work of someone who has been burned by silent data loss, broken cron jobs, and ambiguous billing rules — and learned from it. The defensive patterns, the audit logging, the "why" comments, the rate limits, the encryption-at-rest — they are all there for reasons. When you change something, preserve the reason. When you add something, add the reason as a comment.

The known bugs flagged throughout this file are not signs of a weak codebase — the security, encryption, rate-limiting, and access-control layers are genuinely strong and the XSS surface is clean. The risks are specific and isolated; the highest-impact one — the allocation-materialisation gap (Pattern 10) — and its finalized-immutability prerequisite are now fixed (directives LB-1 / LB-3). The fee mechanism (Billing section) remains the main outstanding risk. Fix the rest with care and re-verify.

The plugin is one operator's lifeline for managing a real-world meal delivery service. Treat it accordingly.
