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

> **Known gap (audit MAJ-8):** `includes/ajax/class-ajax-db-sync.php` (`mealsdb_db_sync_phase`) is the ONLY AJAX handler missing the rate-limit layer, and it runs 300-second destructive migration phases. It should use the `migration_destructive` bucket. When you touch it, add the rate limit.

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

> **Known bug (audit MAJ-4):** client DRAFTS encrypt their payload too, but the draft codec (`MealsDB_Client_Form::encode_draft_payload`) **fails OPEN** — on an encryption exception it stores the PII as plaintext JSON. This is inconsistent with the three write paths above (which fail closed). Drafts should be made to fail closed.

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

Three distinct logging systems, used for different things:

**`MealsDB_Logger`** — audit log for business events (writes to `meals_audit_log` table):
```php
MealsDB_Logger::log($action, $target_id, $field, $old, $new, $source = 'mealsdb');
```
Has built-in PII redaction. Use this for: client edits, link/unlink, deletes, schema changes, force rebuilds, key rotations.

**`MealsDB_Logger::error($message)`** — operational error log via `error_log()` with PII scrubbing. Use for: caught exceptions, validation failures, anything that goes wrong but doesn't crash. Pattern:
```php
catch (\Throwable $e) {
    MealsDB_Logger::error('[MealsDB Subsystem] failed: ' . $e->getMessage());
    return false;
}
```

**`MealsDB_Job_Logger`** — long-running job lifecycle (Phase W). Use `start()`, `heartbeat()`, `finish()`, `fail()` for any cron job or batch operation. Writes to `meals_job_log` with UTC timestamps, duration, and a size-capped context blob. The daily report reads this to answer "did the job run / succeed?"

**`MealsDB_Hook_Logger`** — WC hook firing telemetry (Phase W), to `meals_hook_log`. Used by `class-allocation-hooks.php` and `class-sync.php`. New hook handlers should integrate.

> **Important (audit theme #1):** the job logger is HONEST — it records exactly the `finish()`/`fail()` it is told. Several bugs in this codebase come from CALLERS that detect a failure, discard it, and still call `finish()` with success stats (see the nightly-allocation and install bugs below). When you write a job, make sure your success/failure decision is correct — don't call `finish()` on a path that swallowed an error.

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

> **CRITICAL — corrected from the previous CLAUDE.md, and a known bug (audit LB-6 / recon-02):** the installer does **NOT** use `dbDelta`. It uses `MealsDB_Schema_Sync`, which **only ADDS missing tables and columns — it NEVER MODIFIES an existing column.** It computes a type/ENUM/width mismatch report and then **discards it.** This means: **bumping the version will NOT apply a column type change, ENUM-value change, or width change to existing installs.** If you need to MODIFY an existing column (not just add one), Schema_Sync will silently not do it — you must write an explicit `ALTER` migration or add ALTER support to Schema_Sync. Do not assume "the installer handles it idempotently"; it only handles additions.

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

> **CRITICAL — known bug (audit LB-1 / recon-03):** the nightly allocation sync does NOT rebuild dirty client-months. It only calls `recalculate_month_totals`, which **re-sums existing `meals_delivery_allocations` detail rows** — it never invokes the rebuilder that *creates* those rows. So a month marked dirty but never materialised (no invoice run, no manual recalc) stays empty, and the job logs success anyway. This is the single highest-impact bug in the plugin: it cascades into billing, reporting, driver cash collection, and the Quick Order allowance preview. The migration tool calls `allocate_order` per order and materialises correctly — use it as the reference for what the nightly path SHOULD do. Do not assume nightly materialisation happens; today it does not.

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

> **Known bug (audit MAJ-3 / recon-07/12):** the guard mis-handles legitimate NEGATIVE money. `MealsDB_Money::format` emits a leading `-`, which triggers the `'`-prefix, so `-10.24` becomes `'-10.24` in a numeric column of a government-bound CSV. The bug exists in BOTH the PHP `MealsDB_CSV` and the JS `report-utils.js`, and `test-reports-csv-injection.php` currently asserts the buggy behavior as correct. When you fix it (exempt numeric-leading-minus), fix BOTH files and update that test.

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

> **Known bug (audit LB-5 / recon-03/07):** several order-lifecycle hooks still guard on `get_post_type($id) === 'shop_order'`, which never matches under HPOS — so `on_order_trashed` / `on_order_deleted` never deallocate, and the daily report still *monitors* the dead `trashed_post` / `before_delete_post` hooks. The reconciliation QUERIES in the daily report were already rewritten to HPOS tables (prior-audit directive-01, partially applied); the HOOK guards and monitored-hook list were not. When you fix these, also fix `test-allocation-hooks-swallow.php`, whose stub `get_post_type()→'shop_order'` currently MASKS the bug.

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
- **Per-client fees:** `meals_clients.client_contribution` and `delivery_fee` are per-client DECIMAL columns. **Known bug (audit LB-2):** the fee applier currently bills the flat WC product CATALOG price instead of these per-client values. The intended behavior is to bill the per-client amounts (as `WC_Order_Item_Fee`). The form, slips, and Quick Order preview all already treat the per-client columns as authoritative — only the invoice path doesn't.
- **Finalized months are immutable.** **Known bug (audit LB-3):** the rebuilder's `fill_months` and re-running invoice generation can DELETE and rewrite finalized per-delivery detail (the finalize guard only protects the summary). Treat finalized months as read-only; the migration tool's `$finalized_set` skip is the correct pattern.
- **Operational constants:** rates, fee/overage product IDs, category IDs, and the Apetito pallet size live in `MealsDB_Operational_Constants` (the single source of truth — use it, don't hardcode). **Known bug (audit LB-7 / STR-2):** the SDNB rates are ALSO duplicated in the invoice generator's private `$sdnb_rate_tiers` array, which is keyed on the combined-price STRING (`'14.66'`/`'15.47'`). Changing the rate without updating that array — or changing the combined price so the key no longer matches — silently mis-bills. The fix is to make the invoice generator derive everything from `MealsDB_Operational_Constants` and delete the duplicate table.

### HST / tax rules (confirmed with the operator, 2026-05)

- **Mains are NEVER taxed.** (The code already does this correctly — tax is only ever applied to taxable sides.)
- **Prices are PRE-TAX.** HST is *added*, not backed out.
- **Taxable sides:** HST = side price × **15%**. Non-taxable sides get no HST.
- The taxable/non-taxable split is tracked end-to-end (`tax_sides_count` / `nontax_sides_count` on the allocation detail; the rebuilder allocates taxable-first; the invoice taxes only taxable sides). Taxability is per-item via the products table `taxable` flag.
- **Known bug (audit LB-7):** the invoice generator computes HST as `taxable_side_count × baked-in-net-multiplier` (`0.672`/`0.82`/`0.681`). This multiplier model is wrong for pre-tax pricing — replace it with `taxable_side_price × 0.15`. Doing so also lets you delete the price-keyed rate-tier table (above).

### Current pricing (pending changes — see the operator's rate email)

New rates are approved. **Do NOT change the SDNB values yet** — the operator has said to leave SDNB pricing as-is pending Social Development IT's answer on retroactive billing. (Retroactive billing is the agency's operations problem and is OUT OF SCOPE for the plugin.) The *code* fixes above (de-dup rates, re-key, switch HST to ×15%) can be done now; the *value* flip happens at cutover.

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

The known bugs flagged throughout this file are not signs of a weak codebase — the security, encryption, rate-limiting, and access-control layers are genuinely strong and the XSS surface is clean. The risks are specific and isolated, most of them tracing back to a single allocation-materialisation gap (Pattern 10) and the fee mechanism (Billing section). Fix those with care and re-verify.

The plugin is one operator's lifeline for managing a real-world meal delivery service. Treat it accordingly.
