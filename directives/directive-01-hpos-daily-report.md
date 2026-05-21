# Directive: Fix HPOS Reconciliation in Daily Report

**Severity:** CRITICAL (CRIT-1)
**Audit reference:** `recon-08-phase-w.md`, "HPOS bug — CRITICAL" section; `recon-09-synthesis.md` CRIT-1
**Target file:** `includes/class-daily-report.php`
**Estimated scope:** ~50-80 lines of changes in three methods within one file
**Risk:** LOW — the broken code currently returns zero rows always, so any fix is an improvement
**Must complete before:** shadow-mode trial begins

---

## Context

The site runs WooCommerce in **HPOS-exclusive mode**. Orders live in custom tables `wc_orders` and `wc_orders_meta`, NOT in `wp_posts` (`post_type='shop_order'`) and `wp_postmeta`.

The three reconciliation check methods in `class-daily-report.php` currently query `$wpdb->posts` JOIN `$wpdb->postmeta` with `post_type='shop_order'` filter. On this HPOS-exclusive site, **these queries return zero rows every single day**, regardless of whether reconciliation issues actually exist. Operators receive a daily false "all clear" report.

This is the only place in the entire plugin that incorrectly assumes classic shop_order CPT storage. Every other order-querying code path correctly uses HPOS tables or `wc_get_orders()`. This is a one-off oversight to be remediated.

---

## Pre-flight verification

Before making any code changes, perform these checks and confirm each in your response:

### Step P1: Confirm HPOS table structure

Run the following to confirm the HPOS tables exist on the target environment:

```bash
wp db query "SHOW TABLES LIKE 'wp_wc_orders'" --skip-column-names
wp db query "SHOW TABLES LIKE 'wp_wc_orders_meta'" --skip-column-names
wp db query "SHOW TABLES LIKE 'wp_wc_order_addresses'" --skip-column-names
wp db query "SHOW TABLES LIKE 'wp_wc_order_operational_data'" --skip-column-names
```

The DB prefix on this site is `2xnIt_`, not `wp_`. Substitute accordingly: `SHOW TABLES LIKE '2xnIt_wc_orders'` etc. All four queries must return one row each.

If any of these return empty, **STOP** and report to the operator — the assumption that this site is HPOS-exclusive is wrong, and the fix would break instead of help.

### Step P2: Confirm the queries currently return zero

Run the three reconciliation queries against the production DB (read-only). Each MUST currently return zero rows. If any returns non-zero, the audit's HPOS-exclusive assumption is wrong somehow.

```bash
# Query 1 — orders without allocations (existing, classic-tables form)
wp db query "SELECT COUNT(*) FROM 2xnIt_posts p
  INNER JOIN 2xnIt_postmeta pm ON p.ID = pm.post_id
  WHERE p.post_type = 'shop_order'
    AND p.post_status IN ('wc-processing','wc-completed')
    AND pm.meta_key = '_mealsdb_client_id'
    AND pm.meta_value > 0
    AND NOT EXISTS (
      SELECT 1 FROM 2xnIt_meals_delivery_allocations da
      WHERE da.order_id = p.ID
    )
  LIMIT 1"
```

Expected: 0. If non-zero, the audit is wrong about HPOS-exclusivity.

### Step P3: Locate the three methods

Open `includes/class-daily-report.php` and locate the **three** affected methods. They are approximately at:

- `check_orders_without_allocations` — around lines 324-360
- `check_active_orders_missing_meta` — around lines 370-411
- `check_clients_with_orders_no_record` — around lines 421-471

Each contains a SQL query that joins `$wpdb->posts` with `$wpdb->postmeta`. Confirm the line ranges by reading the file — they may have drifted since the audit. **Do not proceed if you cannot find all three methods.**

### Step P4: Read the surrounding context

For each method, read the **20 lines before and after** to understand:
- What the method returns (shape of the array).
- How the result is consumed by the caller (likely `run_reconciliation_checks`).
- Whether there are any helper methods or constants used.

Document what you find in your response before proceeding.

---

## The fix

Each of the three queries must be rewritten to use HPOS tables. The mapping from classic to HPOS:

| Classic (current, broken) | HPOS (correct) |
|---|---|
| `{$wpdb->posts}` (filter `post_type='shop_order'`) | `{$wpdb->prefix}wc_orders` |
| `{$wpdb->posts}.ID` | `{$wpdb->prefix}wc_orders.id` |
| `{$wpdb->postmeta}` | `{$wpdb->prefix}wc_orders_meta` |
| `{$wpdb->postmeta}.post_id` | `{$wpdb->prefix}wc_orders_meta.order_id` |
| `{$wpdb->postmeta}.meta_key` | `{$wpdb->prefix}wc_orders_meta.meta_key` |
| `{$wpdb->postmeta}.meta_value` | `{$wpdb->prefix}wc_orders_meta.meta_value` |
| `post_status IN ('wc-processing', 'wc-completed')` | `status IN ('wc-processing', 'wc-completed')` |
| `post_date_gmt` | `date_created_gmt` |

Order status values keep the `wc-` prefix on HPOS — same as classic. Don't strip them.

### Step F1: Implement the table name helpers

At the top of the class (after any existing constants, before the methods), add a private helper method that returns the HPOS table names. This centralizes the prefix concatenation:

```php
/**
 * Get HPOS table names. Centralised so the three reconciliation
 * checks don't repeat the same prefix concatenation.
 *
 * This site is HPOS-exclusive: orders live in wc_orders / wc_orders_meta,
 * not in wp_posts / wp_postmeta. Querying the classic tables returns
 * zero rows — that was the bug this method exists to prevent.
 *
 * @return array{orders: string, meta: string} Table names with prefix.
 */
private static function get_hpos_tables(): array {
    global $wpdb;

    return [
        'orders' => $wpdb->prefix . 'wc_orders',
        'meta'   => $wpdb->prefix . 'wc_orders_meta',
    ];
}
```

Place it at a private static method position consistent with the rest of the class.

### Step F2: Rewrite `check_orders_without_allocations`

Replace the existing query (whatever its current form). The new method should:

1. Get HPOS table names via `self::get_hpos_tables()`.
2. SELECT order IDs from the wc_orders table.
3. JOIN to wc_orders_meta where `meta_key = '_mealsdb_client_id'` AND `meta_value > 0`.
4. Filter by `status IN ('wc-processing', 'wc-completed')` (NOT `post_status`).
5. EXCLUDE orders that exist in `meals_delivery_allocations` (existing logic).
6. Limit and order behaviour: match what the original did (likely no LIMIT for a count, or LIMIT for a sample).

Preserve the method's existing return shape EXACTLY. If it returned `['count' => N, 'sample' => [...]]`, keep that. If it returned an integer, keep that. Read the caller (`run_reconciliation_checks` or wherever) to verify.

The query template:

```php
$tables = self::get_hpos_tables();

$query = $wpdb->prepare(
    "SELECT o.id
     FROM {$tables['orders']} o
     INNER JOIN {$tables['meta']} m
         ON o.id = m.order_id
         AND m.meta_key = %s
     WHERE o.type = %s
       AND o.status IN ('wc-processing', 'wc-completed')
       AND CAST(m.meta_value AS UNSIGNED) > 0
       AND NOT EXISTS (
           SELECT 1 FROM {$wpdb->prefix}meals_delivery_allocations da
           WHERE da.order_id = o.id
       )
     ORDER BY o.date_created_gmt DESC
     LIMIT %d",
    '_mealsdb_client_id',
    'shop_order',
    100  // or whatever the original LIMIT was
);
```

**Note the `o.type = 'shop_order'` filter.** HPOS uses a `type` column on `wc_orders` to distinguish shop_order from shop_order_refund. You MUST include this filter or refunds will be counted as orders. (Classic CPT made this distinction via separate post_type values.)

If the original query had additional WHERE conditions (date ranges, specific meta key checks, etc.), translate each one to the HPOS equivalent. **Do not silently drop conditions.**

### Step F3: Rewrite `check_active_orders_missing_meta`

Same translation pattern. This query is likely checking for orders that should have `_mealsdb_client_id` set but don't. Translate:

```php
$tables = self::get_hpos_tables();

$query = $wpdb->prepare(
    "SELECT o.id
     FROM {$tables['orders']} o
     LEFT JOIN {$tables['meta']} m
         ON o.id = m.order_id
         AND m.meta_key = %s
     WHERE o.type = %s
       AND o.status IN ('wc-processing', 'wc-completed')
       AND (m.meta_value IS NULL OR m.meta_value = '' OR m.meta_value = '0')
     ORDER BY o.date_created_gmt DESC
     LIMIT %d",
    '_mealsdb_client_id',
    'shop_order',
    100
);
```

If the original query checked multiple meta keys via separate JOINs (e.g. `_mealsdb_client_id` AND `_mealsdb_rate_id`), translate each JOIN to its own LEFT JOIN against wc_orders_meta with the appropriate `meta_key` filter.

### Step F4: Rewrite `check_clients_with_orders_no_record`

This query finds WP users who have placed orders but don't have a `meals_clients` row. The customer ID in HPOS is in the `customer_id` column on `wc_orders` directly — no JOIN to user_meta needed.

```php
$tables = self::get_hpos_tables();

$query = $wpdb->prepare(
    "SELECT DISTINCT o.customer_id
     FROM {$tables['orders']} o
     WHERE o.type = %s
       AND o.status IN ('wc-processing', 'wc-completed')
       AND o.customer_id > 0
       AND NOT EXISTS (
           SELECT 1 FROM {$wpdb->prefix}meals_clients c
           WHERE c.wp_user_id = o.customer_id
       )
     ORDER BY o.date_created_gmt DESC
     LIMIT %d",
    'shop_order',
    100
);
```

Note `c.wp_user_id` (DB-side column name, NOT `wordpress_user_id` — see CLAUDE.md section on form-side vs DB-side column names).

### Step F5: Preserve method signatures and return types

For all three methods:
- Method signature stays the same (same parameter list, same return type hint).
- Return value structure stays the same.
- Any logging calls (`MealsDB_Logger::error`, etc.) stay in place.
- Any try/catch wrappers stay in place.

If the original method returned a `WP_Error` on query failure, the new version must too. Check `$wpdb->last_error` after each query and return `WP_Error` if non-empty.

### Step F6: Add a comment explaining the fix

At the top of each rewritten method, add a comment block:

```php
/**
 * <Original docblock content preserved>
 *
 * HPOS NOTE: This site is HPOS-exclusive. Orders live in wc_orders /
 * wc_orders_meta, not in wp_posts / wp_postmeta. A previous version of
 * this method joined the classic tables filtered by post_type='shop_order'
 * and returned zero rows on every run — operators received daily false
 * "all clear" reports. See CLAUDE.md section "Don't query orders via
 * wp_posts on HPOS" for the canonical translation table.
 */
```

This comment is mandatory. Future readers need to know why the queries look the way they do.

---

## Testing

### Step T1: Static check

After all three methods are rewritten:
1. Run `php -l includes/class-daily-report.php` and confirm no syntax errors.
2. Run any PHPCS / PHPStan rules the project uses (`composer phpstan` if configured; otherwise skip).

### Step T2: Smoke test — manual report generation

The cron status page has a "Send Test Report Now" button. The dev needs to verify this manually after deployment.

In your response, leave a note: **"Manual test required: navigate to Meals DB → Cron Status → 'Send Test Report Now' button. The reconciliation table in the resulting email should show non-zero counts if there are reconciliation issues, or '0' if there are none. Previously it always showed 0 regardless."**

### Step T3: Query verification

Before deploying, run each new query directly against the staging DB to confirm:
1. It returns rows when expected (insert a test order via WC admin, verify the reconciliation flags it).
2. It does NOT return refunds (verify `o.type = 'shop_order'` filter works).
3. Execution time is reasonable (<500ms). If it's slow, check indexes on wc_orders.status, wc_orders.type, wc_orders_meta.meta_key.

---

## Out of scope for this directive

- Do NOT modify the email composition logic.
- Do NOT modify the cron schedule.
- Do NOT modify the anomaly detection (that operates on hook log counts, not on these reconciliation queries).
- Do NOT modify the `class-cron-status-page.php` UI — the page reads the report data, doesn't query directly.
- Do NOT change the table name helper to a public method — keep it private to this class. If other classes need HPOS table names later, they can use `wc_get_orders()` (the canonical WC API).

---

## Acceptance criteria

The directive is complete when:

1. ✅ All three reconciliation methods query `wc_orders` and `wc_orders_meta` instead of `wp_posts` and `wp_postmeta`.
2. ✅ Each method has the HPOS NOTE comment block.
3. ✅ The `get_hpos_tables()` private helper exists.
4. ✅ Each query uses `o.type = 'shop_order'` to exclude refunds.
5. ✅ The customer ID join in `check_clients_with_orders_no_record` uses `o.customer_id` directly.
6. ✅ All three methods preserve their original signatures, return types, and structural return shape.
7. ✅ All three methods preserve their existing logging and error handling.
8. ✅ `php -l` passes on the modified file.
9. ✅ The pre-flight HPOS table existence checks (Step P1) all returned positive.
10. ✅ The "manual test required" note is included in your final response.

When complete, your final response should include:
- A diff or summary of the changes made.
- Confirmation that pre-flight steps P1-P4 succeeded.
- The exact line ranges where the three methods now live.
- The manual test instruction for the dev.
