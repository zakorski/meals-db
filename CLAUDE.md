# CLAUDE.md

Guidance for Claude (and humans) working in this codebase. These conventions reflect what the existing code does consistently — follow them when adding or modifying functionality. When something here conflicts with what you observe in a specific file, the file usually wins (it has more context); but the conflict is worth flagging in your response.

---

## Project context

This plugin replaces ~17 legacy plugins for **Meals and More NB**, a Moncton-based meal delivery operation serving SDNB (Social Development NB), Veterans Affairs Canada, and private customers. Roughly 890 active clients, 16,000+ orders/year.

**Deployment:** WordPress on cPanel hosting. WooCommerce 10.6.1, WP 6.9.4, PHP 7.4+. **HPOS-exclusive** (orders live in `wc_orders` / `wc_orders_meta`, NOT `wp_posts` / `wp_postmeta` — assume this everywhere). DB prefix `2xnIt_`.

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

### 2. Capability conventions

| Capability | When to require |
|---|---|
| `manage_woocommerce` | Default plugin access. Reading reports, editing clients, generating invoices. |
| `manage_options` | Settings changes, migration, destructive ops (force_rebuild, delete_users, schema_sync). |
| `delete_users && manage_options` | User deletion specifically. |
| `edit_product` | Product tab CRUD (with `manage_woocommerce` as fallback in service layer). |

`MealsDB_Permissions::required_capability()` returns the configured default (defaults to `manage_woocommerce`). Use this rather than hardcoding `'manage_woocommerce'` in new code.

### 3. Database access — use `wpdb`, not `mysqli`

The entire plugin (except the one-time migration utility) uses `$wpdb` for database access. **Do not introduce `mysqli` or PDO**.

When constructing SQL:
- **Always** use `$wpdb->prepare()` for any value derived from user input or external data.
- For table names (which can't be parameterized), build via `MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS)` etc., and concatenate inside backticks.
- For dynamic column names, use a hardcoded whitelist before concatenation. Never accept a column name from `$_POST` without whitelist filtering.
- When you must escape a value into a non-parameterized position (rare), use `$wpdb->_real_escape()`. Note this does NOT escape LIKE wildcards; use `$wpdb->esc_like()` separately.

### 4. Encryption pattern

PII columns (`individual_id`, `requisition_id`, `vet_health_card`, `diet_concerns`, `customer_comments`) are encrypted at rest via `MealsDB_Encryption`. The canonical list lives in `MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS`.

**Key rule:** the form layer (`MealsDB_Client_Form::save()`, `MealsDB_Client_Form::update()`) auto-encrypts before calling the repository. The repository methods (`MealsDB_Clients_Repository::create_client()`, `update_client()`) **do not auto-encrypt**.

If you're adding a code path that writes encrypted columns directly to the repository, you must call `MealsDB_Encryption::encrypt_columns($data)` first. There's prior art in `class-backfill-private-clients.php`.

Decryption:
- `MealsDB_Encryption::decrypt($ciphertext)` — strict, throws on failure.
- `MealsDB_Encryption::safe_decrypt($value)` — best-effort, returns input unchanged on failure. Use this when reading legacy data that may be plaintext.

Adding a new encrypted column requires:
1. Add to `MealsDB_Encryption::ENCRYPTED_CLIENT_COLUMNS`.
2. If queryable by exact match: add a deterministic index column (`{column}_index CHAR(64)`) via schema + `MealsDB_Client_Form::$deterministic_index_map`.
3. `deterministic_hash($plaintext)` for the index. Never hash ciphertext.

### 5. Form-side vs DB-side column names

The codebase maintains two parallel column name vocabularies:

| Form-side (UI, `$_POST` keys) | DB-side (canonical schema) |
|---|---|
| `wordpress_user_id` | `wp_user_id` |
| `phone_primary` | `client_phone_1` |
| `phone_secondary` | `client_phone_2` |
| `address_postal` | `postal_code` |
| `client_comments` | `customer_comments` |
| ... | (~20 more — see `MealsDB_Client_Form::map_form_to_db()`) |

`MealsDB_Client_Form::load_client()` maps DB → form (for the edit view). `MealsDB_Client_Form::map_form_to_db()` maps form → DB (for the save path).

**Common bug pattern:** writing form-side names to the repository, where `filter_to_known_columns` silently drops them as unknown. The handler returns success and the change disappears. If you're touching client data outside the form flow, double-check which vocabulary you're using.

When in doubt: read from `MealsDB_Clients_Repository::get_client_by_id()` (returns DB-side names), pass to `MealsDB_Client_Form::map_form_to_db()` if you need to convert for a form-side caller.

### 6. Logging pattern

Two distinct logging systems, used for different things:

**`MealsDB_Logger`** — audit log for business events (writes to `meals_audit_log` table):
```php
MealsDB_Logger::log($action, $target_id, $field, $old, $new, $source = 'mealsdb');
```
Has built-in PII redaction for ~32 sensitive field names. Use this for: client edits, link/unlink, deletes, schema changes, force rebuilds, key rotations.

**`MealsDB_Logger::error($message)`** — operational error log via `error_log()` with PII scrubbing. Use for: caught exceptions, validation failures, anything that goes wrong but doesn't crash. Pattern:
```php
catch (\Throwable $e) {
    MealsDB_Logger::error('[MealsDB Subsystem] failed: ' . $e->getMessage());
    return false;
}
```

**`MealsDB_Job_Logger`** — long-running job lifecycle (Phase W). Use `start()`, `heartbeat()`, `finish()`, `fail()` for any cron job or batch operation.

**`MealsDB_Hook_Logger`** — WC hook firing telemetry (Phase W). Used by `class-allocation-hooks.php` and `class-sync.php`. New hook handlers should integrate.

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

### 8. Rate limiting

`MealsDB_Rate_Limiter::check_rate_limit($bucket)` — returns false when the user has exceeded the bucket's quota. Standard buckets:

| Bucket | Quota | Used by |
|---|---|---|
| `client_modify` | 50/hour | Client CRUD AJAX |
| `quick_order_read` | 200/hour | QO list/search endpoints |
| `quick_order_create` | 50/hour | QO order creation |
| `schema_rebuild` | 2/hour | Force rebuild |

New destructive endpoints should use a bucket. Pattern at AJAX handler top:
```php
if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit('client_modify')) {
    wp_send_json_error(['message' => __('Rate limit exceeded.', 'meals-db')], 429);
}
```

### 9. Schema changes

The plugin's tables are defined in `MealsDB_Schema::get_canonical_schema()`. To add a column or table:

1. Update the canonical schema.
2. Bump `MEALS_DB_VERSION` in the plugin header.
3. The `mealsdb_maybe_upgrade_schema` admin_init hook will run `MealsDB_Installer::install()` on next admin page load, which uses `dbDelta` to apply changes idempotently.

Do **not** write standalone migration scripts for schema. Let the installer handle it. The lock mechanism (atomic INSERT IGNORE on `mealsdb_install_lock`) prevents concurrent upgrades.

For data backfills (one-time data corrections, not schema changes), put them in `includes/services/` as `class-backfill-*.php` and wire them up via the updates page or a settings AJAX endpoint.

### 10. Cron jobs

Plugin-scheduled cron hooks (as of v1.0.346):
- `mealsdb_nightly_sync` (02:00 UTC) — sync engine
- `mealsdb_nightly_allocation_sync` (02:00 UTC) — allocation engine
- `mealsdb_nightly_task_sync` (02:00 UTC) — task engine
- `mealsdb_daily_report` (04:00 UTC) — Phase W health report
- `mealsdb_log_retention` (04:30 UTC) — Phase W log pruning

**When adding a new cron hook:**
1. Schedule it via `wp_schedule_event()` in the relevant class's `register_hooks()`.
2. Register a handler with `add_action()`.
3. **Add it to the deactivation hook in `meals-db-main.php` line 532** so it's cleared on deactivate.
4. **Add it to `uninstall.php`** so it's cleared on uninstall too. (uninstall.php is currently behind on this — fix it as part of your work).

cPanel cron fires WP-Cron at `:15` and `:45` past each hour. Cron jobs effectively run ~15 minutes after their scheduled UTC time. Don't rely on exact minute precision.

### 11. UTC timestamps everywhere

Use `gmdate('Y-m-d H:i:s')` for any timestamp written to the database. Use `current_time('mysql', true)` if you want WP's interpretation of UTC (equivalent).

Display layer can convert to site timezone via `wp_timezone()`.

Don't use `date()` (uses server local timezone) or `time()` for storage. The bug class is silent until DST.

### 12. Settings storage

Plugin settings live in three places:
- **wp-config.php constants** — preferred for secrets (`MEALS_DB_KEY`, `MEALSDB_TRUSTED_PROXIES`, etc).
- **`mealsdb_settings` option** — for non-secret config (legacy encryption_key location, etc).
- **Dedicated options** — for things with their own lifecycle (`mealsdb_overage_product_ids`, `mealsdb_zone_delivery_schedule`, `mealsdb_db_version`).

`autoload='no'` for options that aren't read on every page load. The schema upgrade option (`mealsdb_db_version`) IS autoloaded since it's checked on every admin_init.

### 13. Nonces

Don't introduce a new nonce context unless the action is meaningfully distinct from existing ones. Current contexts:

- `mealsdb_nonce` — most general AJAX
- `mealsdb_settings_nonce` — settings save
- `mealsdb_migration_nonce` — migration phases
- `mealsdb_quick_order_create_order` — QO order creation (stricter)
- Plus several form-specific ones (force_rebuild, delete_nonadmin_users, etc.)

If you're tempted to add a new nonce: ask whether the action category warrants it. Generally, "destructive" gets its own nonce; "read-only with rate limit" doesn't.

### 14. CSV export safety

If you're writing data to a CSV (reports, invoices, downloads), route through `MealsDB_CSV_Safety`:
- `safe_cell($value)` — prepends `'` to cells starting with `=`, `+`, `-`, `@`. Default for ordinary exports.
- `safe_cell($value, true)` — strict mode strips trigger characters entirely. Use for government-bound exports where formula injection is a regulatory concern.

Don't bypass this. CSV injection is a real attack vector and the legacy system had instances of it.

### 15. File handling

For file uploads, follow the migration upload pattern in `MealsDB_Ajax_Migration::upload_file()`:
1. Extension whitelist.
2. Content sniffing (magic bytes).
3. Size cap.
4. Random destination filenames in a dedicated subdir.
5. `.htaccess` + `index.php` web blockers in the subdir.
6. 0600 permissions.
7. Path traversal defense via realpath containment.

If you're writing files outside the upload directory (cache, exports, etc.), use `wp_get_upload_dir()` or `WP_CONTENT_DIR` — never `__DIR__` for writable paths.

---

## Code style

### PHP conventions

- **Strict types where it matters.** Use type hints on method signatures (`int $client_id`, `array $data`, `?string $value`, return types like `: bool`, `: array`, `: void`).
- **`use \Throwable` not `\Exception`** when catching. The plugin catches Errors too (TypeError, division by zero, etc.).
- **`function_exists()` / `class_exists()` guards** for WP/WC functions when the class might load outside an admin context (test fixtures, WP-CLI). The pattern is everywhere:
  ```php
  if (function_exists('wp_unslash')) {
      $value = wp_unslash($value);
  }
  ```
- **Static methods for stateless utilities, instance methods for stateful services.** `MealsDB_Logger` is all static; `MealsDB_Task_Engine`, `MealsDB_Purchase_Orders` are instantiated.

### Naming

- **Classes**: `MealsDB_*` prefix, underscores between words. Filename is `class-<slug>.php` where slug is lowercased with hyphens.
- **Methods**: snake_case.
- **DB columns**: snake_case, descriptive. Use the existing vocabulary (see Pattern 5 above) — don't introduce new conventions mid-table.
- **Hooks (WP actions/filters)**: `mealsdb_<action>` for our own; consume WC hooks by their WC names.
- **Options**: `mealsdb_<purpose>`.
- **Constants**: `MEALS_DB_*` for plugin-level (file paths, version), `MEALSDB_*` for runtime config (constants user might set in wp-config.php).

### Comments

The codebase has an unusually strong comment culture. Match it.

**Comments explain *why*, not *what*.** Pattern:
```php
// The atomic INSERT IGNORE serialises concurrent inserts: whichever
// request lands first gets rows_affected=1, every other request gets
// 0 and returns early. A transient or a WP-API add_option() is NOT
// safe here because add_option() uses INSERT ... ON DUPLICATE KEY
// UPDATE, which succeeds (as an update) under contention.
$wpdb->query($wpdb->prepare("INSERT IGNORE INTO ...", ...));
```

When you fix a bug, leave the explanation in a comment: "Previous behavior accepted X, which let an attacker do Y; now we strict-validate Z." Future readers (including future you) will thank you. There are dozens of these throughout the codebase.

**Comments do not need to repeat what the code says.** `// Increment counter` above `$count++` is noise.

### Translation

Use WP's translation functions (`__()`, `esc_html__()`, `esc_attr__()`) for all user-facing strings. Domain is `'meals-db'`.

Do NOT define a fallback `__()` function in your file. There's one in `class-client-form.php` that should be removed; don't replicate the pattern.

### Output escaping

- HTML: `esc_html()` for text, `esc_attr()` for attributes, `esc_url()` for URLs, `esc_textarea()` for textarea content.
- JS contexts (inline JS): `esc_js()`.
- SQL: `$wpdb->prepare()` or `$wpdb->_real_escape()`.
- Late-escape pattern: escape at the output point, not at the input point. Store raw, escape when emitting.

---

## Things the codebase deliberately does NOT do

### Don't replicate the legacy overage workaround

The legacy Enzebra system used product IDs 5056 (mains), 5059 (Z side), 5180 (Z side tax) as "overage" line items in WC orders. This produced ~$17K net under-billing in 2025. **The new plugin does NOT replicate this** — it uses the allocation engine to track overage as separate ledger entries.

If you find yourself adding "overage workaround product" logic, stop. The Phase V invoice fixes should clean up the legacy pattern, not extend it.

### Don't add an HTML `<form>` tag inside a React artifact

(This is a meta-note for Claude specifically. If we ever build React UIs for this plugin's settings, use onClick handlers — not form submission.)

### Don't bypass the form validation pipeline

The `MealsDB_Client_Form::validate()` method is the single source of truth for client data validation. If you're tempted to write a new validation function, see if you can extend `validate()` instead. Two parallel validation paths invariably diverge.

### Don't query orders via `wp_posts` on HPOS

The site is HPOS-exclusive. Order data lives in:
- `wc_orders` — main order table
- `wc_orders_meta` — order metadata
- `wc_order_addresses` — billing/shipping addresses
- `wc_order_operational_data` — status, dates, currency

Use `wc_get_order($order_id)` for individual orders, `wc_get_orders($args)` for queries. Direct `wp_posts` queries with `post_type='shop_order'` return zero rows.

**One file currently violates this**: `class-daily-report.php`'s three reconciliation checks. They need to be rewritten. If you fix it, also bump the audit's CRIT-1 status.

### Don't use `\Exception` when you mean `\Throwable`

PHP 7+ Errors (TypeError, ValueError, DivisionByZeroError) extend `\Throwable` but NOT `\Exception`. Catching only `\Exception` lets these escape. Always `\Throwable` for outer try/catch in handlers.

### Don't write inline `<script>` blocks > 20 lines

The codebase has a few large inline JS blobs in PHP files (`class-admin-ui.php`'s allocation history widget at 161 lines, `class-wc-product-tab.php`'s tax sync at 30 lines). These should be extracted to `assets/js/` files, not extended. If you're adding interactive JS, put it in a real file with proper enqueue.

### Don't introduce new form-side vs DB-side column name pairs

Stick to the existing 20 pairs (see Pattern 5). If you need a new column, use the same name on both sides if possible. If the form vocabulary genuinely differs from the DB vocabulary, document the mapping in `MealsDB_Client_Form::map_form_to_db()` and the reverse.

---

## Subsystems quick reference

When working on a specific area, start by reading the relevant audit doc (`recon-NN-*.md` files). They're not API docs but they're more accurate than going by file inspection alone.

| Subsystem | Primary files | Audit doc |
|---|---|---|
| Plugin bootstrap, autoloader, admin notices | `meals-db-main.php`, `class-plugin.php`, `class-autoloader.php` | Pass 7 |
| Billing & invoicing | `class-invoice-generator.php`, `class-rates.php`, `class-money.php` | Pass 2 |
| Sync (WP user ↔ meals_client) | `class-sync.php`, `class-allocation-hooks.php`, `class-private-intake.php` | Pass 3 |
| Schema management | `class-schema.php`, `class-schema-sync.php`, `class-schema-rebuild.php`, `class-db.php` | Pass 3, Pass 7 |
| Reports | `class-reports/*`, `class-purchase-order-engine.php` | Pass 4 |
| Task engine | `class-task-engine.php`, `class-task-registry.php`, `class-task-rules.php`, `class-task-cron.php`, `task-types/*` | Pass 5, Pass 8 |
| Purchase orders | `class-purchase-orders.php` | Pass 5, Pass 6 |
| Delivery slip PDFs | `class-slip-pdf-generator.php`, `class-delivery-slip-generator.php` | Pass 5 |
| Migration tool | `class-migration.php`, `class-ajax-migration.php`, `class-migration-page.php` | Pass 6 |
| Encryption | `class-encryption.php`, `class-encryption-migrator.php` | Pass 6 |
| Permissions & rate limiting | `class-permissions.php`, `class-rate-limiter.php` | Pass 6 |
| Repositories | `class-clients-repository.php`, `class-orders-repository.php` | Pass 6 |
| Admin UI & client form | `class-admin-ui.php`, `class-client-form.php`, `views/*` | Pass 7 |
| AJAX handlers | `ajax/*` | Pass 7 |
| Quick Order | `class-quick-order-ui.php`, `class-quick-order-products.php`, `class-quick-order-ajax.php` | Pass 8 |
| Staff Directory | `class-staff.php` | Pass 8 |
| Initials validation | `class-initials-validator.php`, `class-initials.php` | Pass 8 |
| Products | `class-products.php`, `class-products-loader.php`, `class-product-display-sync.php`, `class-wc-product-tab.php` | Pass 8 |
| Phase W (cron monitoring) | `class-job-logger.php`, `class-hook-logger.php`, `class-log-retention.php`, `class-daily-report.php`, `class-cron-status-page.php` | Pass 8 |
| Cross-cutting utilities | `class-logger.php`, `class-helpers.php`, `class-csv-safety.php` | Pass 6, Pass 8 |

---

## Operational constants

Hardcoded values that matter for SDNB/VAC billing:

```
Fee products (legacy mechanism):
  - Client Contribution: WC product 5675
  - Delivery Fee: WC product 4122

Overage workaround products (legacy, not extended):
  - Overage main: 5056
  - Overage Z side (non-tax): 5059
  - Overage Z side (taxable): 5180

Category IDs:
  - Mains: 35    - Soup: 43
  - Muffins: 37  - Cereal: 23
  - Dessert: 25

SDNB rates:
  - Primary mains: $14.66  (rural: $15.47)
  - Secondary mains: $10.18  (rural: $10.93)
  - Sides: $4.48  (rural: $4.54)

VAC rates:
  - Mains: $9.05
  - Sides: $4.10

HST multipliers (for invoice line items):
  - $14.66 primary: 0.672
  - $15.47 rural: 0.82
  - $14.66 Line 2: 0.681

Apetito pallet: 75 cases (confirmed with Janet)
```

These should eventually live in `MealsDB_Operational_Constants` but currently are scattered. When you encounter them inline, leave a TODO comment but don't refactor opportunistically.

---

## When you're stuck

1. **Read the audit document for the relevant subsystem.** It will tell you what's there, what's broken, and what the dev already decided.
2. **Grep for similar patterns elsewhere in the codebase.** Almost any UX or backend pattern you need has been done somewhere; matching the existing style is more valuable than inventing a new one.
3. **Check the directives folder** (`directives/`). If your task corresponds to a planned phase (V, U, X, etc.), there may be a directive document with the dev's intended approach.
4. **When in doubt, ask the dev** rather than guessing. The dev would rather answer one question now than debug a wrong assumption later.

---

## Closing note

This codebase is the work of someone who has been burned by silent data loss, broken cron jobs, and ambiguous billing rules — and learned from it. The defensive patterns, the audit logging, the "why" comments, the rate limits, the HTTPS gates, the encryption-at-rest — they are all there for reasons. When you change something, preserve the reason. When you add something, add the reason as a comment.

The plugin is one operator's lifeline for managing a real-world meal delivery service. Treat it accordingly.
