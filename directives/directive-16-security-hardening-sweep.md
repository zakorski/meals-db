# Directive: Pre-Launch Security & Hardening Sweep

**Severity:** HIGH for cutover readiness
**Audit reference:** `recon-09-synthesis.md` Security observations, SEC-1 through SEC-10
**Target files:** Multiple, mostly defensive additions
**Estimated scope:** ~50-100 lines added (or none, if all checks pass)
**Risk:** VERY LOW — defensive only, no behavioral changes
**Must complete before:** shadow-mode trial begins

---

## Context

The synthesis identified the plugin as **structurally sound with strong defensive patterns**. This directive is not a fix for known broken security; it's a **uniformity sweep** to ensure that the standards observed in the best instances are applied consistently across the codebase.

Specifically, the audit observed these patterns done well in SOME places and inconsistently in others:

1. **Capability layered defense** (SEC-2): observed three deep in `MealsDB_Client_Form` but inconsistently in newer AJAX handlers.
2. **Rate limiting** (SEC-8): applied to destructive operations but not uniformly to read-heavy ones that could be DoS'd.
3. **Audit logging** (SEC-10): comprehensive on client mutations, less so on staff and PO mutations.
4. **CSV safety** (SEC-6): applied to some exports but not all.
5. **`$_POST` / `$_REQUEST` direct access** (synthesis "Concerns"): unsanitized in some places.

This directive is an audit-and-harden pass, not a rewrite. The goal: bring all destructive and PII-touching endpoints up to the standard set by the best-implemented ones.

---

## Pre-flight — scope this directive carefully

This directive is **broad**. Tackle it as a SERIES of small focused passes, NOT as one giant change.

The recommended order:
- **Pass A**: Audit all AJAX handlers for capability + nonce + rate limit + audit log.
- **Pass B**: Audit all destructive endpoints (delete, rebuild, modify) for confirmation strings and audit log entries.
- **Pass C**: Audit all CSV/file outputs for `MealsDB_CSV_Safety` usage.
- **Pass D**: Audit all `$_POST` / `$_REQUEST` access for sanitization.
- **Pass E**: Verify trusted-proxy configuration is documented.

Each Pass produces a report. Code changes only happen for the LOWEST-RISK improvements; HIGH-RISK changes require dev confirmation per finding.

---

## Pass A: AJAX handler hardening audit

### Step A1: Inventory all AJAX handlers

```bash
grep -rn "add_action.*wp_ajax_\|wp_ajax_nopriv" includes/ --include="*.php"
```

For each handler, document:
- File and class.
- Action name.
- Capability check present? (`current_user_can` or `MealsDB_Permissions::*`)
- Nonce check present? (`wp_verify_nonce` or `check_ajax_referer`)
- Rate limit present? (`MealsDB_Rate_Limiter::check_rate_limit`)
- Audit log present? (`MealsDB_Logger::log` on mutations)

Produce a markdown table with one row per handler. Mark each column ✅ (present), ❌ (missing), or N/A (not applicable for read-only).

### Step A2: Identify gaps

For each AJAX handler:

**Critical gap**: missing nonce or missing capability check on a mutating endpoint. **STOP** and escalate to dev. These are bugs, not hardening opportunities.

**Hardening gap**: missing rate limit on a non-trivial endpoint, or missing audit log on a mutation.

In your response, list all gaps with severity classifications. **Do not fix in this directive without dev approval per gap.** Each gap may have a legitimate reason it's missing (e.g. the endpoint is read-only and rate-limit would be overkill).

### Step A3: Apply approved hardenings

For each gap the dev approved fixing:

**Missing rate limit** — add at the top of the handler:
```php
if (class_exists('MealsDB_Rate_Limiter')
    && !MealsDB_Rate_Limiter::check_rate_limit('<appropriate_bucket>')) {
    wp_send_json_error([
        'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
    ], 429);
}
```

Choose the right bucket per the existing buckets in `class-rate-limiter.php`:
- `client_modify` for client CRUD.
- `quick_order_read` for QO listing.
- `quick_order_create` for QO creation.
- `schema_rebuild` for force rebuilds.

If no existing bucket fits, **do not invent a new one in this directive**. Note the gap and flag for the rate limiter's own follow-up directive.

**Missing audit log** — add after the mutation:
```php
MealsDB_Logger::log(
    '<action_name>',   // e.g. 'staff_save', 'po_status_changed'
    $target_id,         // the entity ID being modified
    '<field>',          // the field changed (or 'multiple' for batch)
    $old_value,
    $new_value
);
```

The action name should be descriptive and consistent with existing patterns (`client_modified`, `client_deleted`, `staff_save`, etc).

---

## Pass B: Destructive endpoint hardening

### Step B1: Inventory destructive endpoints

Destructive endpoints are those that:
- DELETE rows
- ALTER TABLE / DROP TABLE
- Modify the encryption key
- Reset / rebuild data structures
- Bulk-modify many records

Find them:

```bash
grep -rn "wp_delete_user\|DROP TABLE\|TRUNCATE TABLE\|force_rebuild\|delete_client\|delete_staff" includes/ --include="*.php"
```

For each destructive endpoint, document:
- Does it require a typed confirmation string ("DELETE", "REBUILD", etc.)?
- Does it require `manage_options` (not just `manage_woocommerce`)?
- Is there a 2-step UI (preview → confirm)?
- Is the action audit-logged BEFORE and AFTER the destructive operation?

The audit's observed best practice (from `force_rebuild` and `delete_users`):
1. Form requires typed confirmation string.
2. Capability check: `manage_options`.
3. Nonce check (specific to the destructive action).
4. Rate limit (especially low quota — e.g. 2/hour for schema_rebuild).
5. Audit log entry BEFORE the operation, including operator's user_id.
6. Operation itself.
7. Audit log entry AFTER the operation (success/failure).

### Step B2: Apply the standard to weaker endpoints

For each destructive endpoint that lacks one or more of the seven elements above, propose adding them. Get dev confirmation per endpoint before changes.

The most common gap: **audit log BEFORE the operation**. Adding this is cheap and creates a forensic trail. Apply uniformly with the dev's approval.

Example pattern:

```php
// AUDIT: Log the operation start with operator identity. If the
// operation fails or corrupts state, this log entry is the
// forensic anchor — it captures intent and operator.
MealsDB_Logger::log(
    'force_rebuild_initiated',
    get_current_user_id(),
    'schema_version',
    MEALS_DB_VERSION,
    null  // no new value yet — operation not started
);

try {
    // ... destructive operation ...
    $result = MealsDB_Installer::force_rebuild();

    // AUDIT: Log success.
    MealsDB_Logger::log(
        'force_rebuild_succeeded',
        get_current_user_id(),
        'schema_version',
        null,
        MEALS_DB_VERSION
    );
} catch (\Throwable $e) {
    // AUDIT: Log failure with error context.
    MealsDB_Logger::log(
        'force_rebuild_failed',
        get_current_user_id(),
        'error',
        null,
        $e->getMessage()
    );
    throw $e;
}
```

---

## Pass C: CSV/file output safety audit

### Step C1: Inventory all CSV outputs

```bash
grep -rn "fputcsv\|Content-Type:.*csv\|->generateCSV\|MealsDB_CSV_Safety" includes/ --include="*.php"
```

For each CSV output, document:
- The data being exported.
- Whether `MealsDB_CSV_Safety::safe_cell` is used.
- Whether the export is government-bound (requires strict mode).

### Step C2: Apply CSV safety uniformly

For any CSV output that does NOT use `MealsDB_CSV_Safety::safe_cell`:

```php
// Before:
fputcsv($fh, [$client_name, $email, $address]);

// After:
fputcsv($fh, array_map(function ($cell) {
    return MealsDB_CSV_Safety::safe_cell($cell);
}, [$client_name, $email, $address]));
```

For government-bound exports (SDNB invoices, VAC invoices, etc), use strict mode:

```php
fputcsv($fh, array_map(function ($cell) {
    return MealsDB_CSV_Safety::safe_cell($cell, true); // strict
}, $row));
```

### Step C3: PDF output safety

PDFs don't have CSV injection but DO have other risks:
- Unescaped user-supplied text in PDF templates can embed PDF commands.
- Long user-supplied text can DoS the PDF generator.

For each PDF output (`class-slip-pdf-generator.php`, invoice PDFs):
- Confirm user-supplied text fields are length-bounded.
- Confirm DomPDF or whichever generator is configured for safety (no remote loading, no shell exec).

This is a check, not necessarily a change. Document findings. If any PDF generator has unsafe config, flag for a follow-up directive.

---

## Pass D: `$_POST` / `$_REQUEST` sanitization audit

### Step D1: Find direct super-global access

```bash
grep -rn "\\\$_POST\[\|\\\$_REQUEST\[\|\\\$_GET\[" includes/ --include="*.php" | grep -v "sanitize\|absint\|intval"
```

The grep filters out the obviously-safe cases (where sanitization is on the same line). The remaining matches are candidates for review.

### Step D2: Categorize each remaining match

For each direct super-global access without same-line sanitization:

- **Safe**: the value is sanitized on the next line, or passed to `sanitize_text_field`, or compared with a strict whitelist before use.
- **Probably safe**: the value is used as a flag (`isset`, `empty` check). No injection risk.
- **Needs sanitization**: the value is used directly in SQL (without prepare), HTML output (without escape), or filesystem operations.

Document each in your response.

### Step D3: Fix the unsafe ones

For each "needs sanitization" finding, add appropriate sanitization. Use the existing pattern in the same file as your guide. Common patterns:

```php
// Text field:
$value = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';

// Integer:
$value = isset($_POST['id']) ? absint($_POST['id']) : 0;

// Whitelisted enum:
$allowed = ['option_a', 'option_b'];
$value = isset($_POST['choice']) && in_array($_POST['choice'], $allowed, true)
    ? $_POST['choice']
    : 'option_a';

// Email:
$value = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

// URL:
$value = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
```

**Do not change unsafe-to-safe patterns without dev approval per fix.** A "needs sanitization" finding might actually be safe in context (e.g. the value is only used to set a counter on a session-bound cache that the user owns).

---

## Pass E: Trusted-proxy documentation check

### Step E1: Verify the constant is documented

```bash
grep -rn "MEALSDB_TRUSTED_PROXIES" includes/ wp-config-sample.php 2>/dev/null
```

The plugin's rate limiter uses `MEALSDB_TRUSTED_PROXIES` to determine when to honor `X-Forwarded-For`. If this constant is unset in production, the rate limiter falls back to `REMOTE_ADDR`, which is correct for hosts without reverse proxies.

But: many shared hosts DO sit behind reverse proxies (Cloudflare, etc). If the constant is unset, rate limiting is ineffective — all requests appear to come from the proxy IP.

### Step E2: Add operator documentation

If `wp-config.php` doesn't document the constant, add to `DEV-NOTES.md` or a new `docs/operations.md`:

```markdown
## Reverse-proxy configuration

If this site sits behind a reverse proxy (Cloudflare, AWS CloudFront,
nginx in proxy mode), set MEALSDB_TRUSTED_PROXIES in wp-config.php
to allow rate limiting to identify real client IPs.

```php
// In wp-config.php:
define('MEALSDB_TRUSTED_PROXIES', '192.168.1.0/24,10.0.0.0/8');
```

Without this setting, rate limiting falls back to REMOTE_ADDR,
which is the proxy's IP. All requests will appear to come from
one client, defeating per-IP rate limits.
```

### Step E3: Add a settings UI display

In Meals DB → Settings, add a read-only display of the current rate limiter mode:

- If `MEALSDB_TRUSTED_PROXIES` is set: "Rate limiter: trusted-proxy mode (X-Forwarded-For respected for ranges: ...)"
- If unset: "Rate limiter: direct mode (REMOTE_ADDR only). If this site is behind a reverse proxy, configure MEALSDB_TRUSTED_PROXIES in wp-config.php."

This makes the operator aware without forcing a change. **Read-only display only — do not add the option as configurable from the UI.** Settings affecting trust boundaries should live in wp-config.

---

## Testing

### Step T1: Static checks

For every modified file: `php -l <file>`. All must pass.

### Step T2: Smoke test each modified endpoint

For each AJAX handler modified in Pass A, exercise the endpoint and verify:
- Still works for authorized users.
- Still rejects unauthorized requests.
- Audit log entry appears (if added).
- Rate limit kicks in if exceeded (test by hammering).

### Step T3: CSV export sanity test

For each CSV output modified in Pass C:
1. Generate an export with a sample row containing `=cmd|' /C calc'!A1` in a text field.
2. Open the CSV in Excel/LibreOffice.
3. Confirm the formula does NOT execute. Cell should display literally.

### Step T4: Documentation review

Have the dev review the added documentation for completeness and accuracy.

---

## Out of scope for this directive

- Do NOT rewrite the rate limiter, the audit logger, or any core security primitive. They're well-implemented; this directive only adds usage.
- Do NOT introduce new security primitives. Match existing patterns.
- Do NOT add automated security testing infrastructure. This is a manual audit pass.
- Do NOT modify the encryption layer. SEC-1 is solid.
- Do NOT change capability requirements unless flagged as missing. Existing capabilities are correctly assigned.

---

## Acceptance criteria

The directive is complete when:

**Pass A:**
1. ✅ All AJAX handlers are inventoried in a markdown table.
2. ✅ All Critical gaps are escalated to the dev.
3. ✅ Approved Hardening gaps are fixed.

**Pass B:**
4. ✅ All destructive endpoints are inventoried.
5. ✅ The 7-element standard is documented.
6. ✅ Approved deviations are fixed.

**Pass C:**
7. ✅ All CSV outputs use `MealsDB_CSV_Safety::safe_cell`.
8. ✅ Government-bound exports use strict mode.

**Pass D:**
9. ✅ All direct `$_POST` / `$_REQUEST` access is reviewed.
10. ✅ "Needs sanitization" findings are fixed.

**Pass E:**
11. ✅ Trusted-proxy documentation exists.
12. ✅ Settings UI displays current rate limiter mode.

**Overall:**
13. ✅ Every modified file passes `php -l`.
14. ✅ T2-T4 manual tests pass.

When complete, your final response should include:
- The audit tables from each Pass (A, B, C).
- A summary of gaps found vs gaps fixed (with dev approval references).
- Test results from T2, T3, T4.
- The added documentation snippets.
