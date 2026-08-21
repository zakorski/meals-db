# v561 — reactivate clients ordering while flagged inactive (operator, staging-first)

> **STATUS (v562): RUN — nil result.** All eight inactive clients are genuinely
> dormant (verified three ways: raw counts at all statuses, a `billing_email`
> cross-check for unlinked guest orders, and the `wc-` status-prefix filter).
> Ruth Williamson, Scott Wile and Ida Cameron last ordered in 2024; the rest
> have no orders or only cancelled ones. The v561 reactivation-on-order work
> stands as forward-looking insurance, not a fix for an existing backlog. Re-run
> the query below only if new inactive-but-ordering clients appear.

`search_clients` hides `active = 0` clients from Quick Order. Reactivation-on-order
(v561 ITEM 4b) fixes this going forward; this runbook backfills any existing ones.

## 1. Find them (read-only)

```sql
SELECT c.client_id, c.first_name, c.last_name, c.client_type, c.wp_user_id,
       COUNT(o.id) AS orders_since_jun, MAX(o.date_created_gmt) AS last_order
FROM 2xnIt_meals_clients c
LEFT JOIN 2xnIt_wc_orders o
       ON o.customer_id = c.wp_user_id
      AND o.status <> 'wc-cancelled'
      AND o.date_created_gmt >= '2026-06-01'
WHERE c.active = 0
GROUP BY c.client_id, c.first_name, c.last_name, c.client_type, c.wp_user_id
ORDER BY orders_since_jun DESC;
```

Rows with `orders_since_jun > 0` are ordering while inactive → reactivate. Zero → leave alone.

## 2. Reactivate (per confirmed client)

Staging has **no WP-CLI**, so use the GUI: open the client in the Clients table
and click **Activate**. That calls `wp_ajax_mealsdb_activate_client` →
`MealsDB_Clients::activate_client()`, which is audited (`activate_client`) and
creates/recalculates the allocation row consistently — do **not** run a raw
`UPDATE ... SET active = 1` (skips the audit + allocation).

Then confirm each reactivated client appears in Quick Order client search and
has an allocation row for the current billing month with the right `used_mains`
(allowance programs only — Private clients legitimately have no allocation row).

> Table prefix here is `2xnIt_` — adjust if staging differs.
