# v561 — reactivate clients ordering while flagged inactive (operator, staging-first)

`search_clients` hides `active = 0` clients from Quick Order, but some inactive clients are still ordering (e.g. Ruth Williamson / client 502; client 713 had orders in the 2026-07-27 audit week). Reactivation-on-order (v561 ITEM 4b) fixes it going forward; this backfills the existing ones.

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

Rows with `orders_since_jun > 0` are ordering while inactive → reactivate. Zero → genuinely inactive, leave alone.

## 2. Reactivate (per confirmed client_id)

Use the plugin API, **not** a raw `UPDATE`, so the change is audited and the allocation row is created/recalculated consistently:

```bash
wp eval 'MealsDB_Clients::activate_client(502);'   # repeat per confirmed client_id
```

Then confirm each reactivated client:
- appears in Quick Order client search, and
- has an allocation row for the current billing month with the right `used_mains`.

Prefer this over a bulk `UPDATE ... SET active = 1` so reactivation is audited (`client_reactivated`... note: a manual `activate_client` logs `activate_client`, not the order-triggered `client_reactivated`) and allocation-consistent.

> Table prefix here is `2xnIt_` — adjust if staging differs.
