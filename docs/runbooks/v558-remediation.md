# v558 test-run remediation runbook

**RUN ONCE, ON STAGING FIRST.** Reverses the two data side effects of the v558 GUI test run. Not idempotent — verify with the SELECTs before running and do not re-run.

**Prerequisite:** `DIRECTIVE-v558-test-findings` ITEM 1 (the `accepted` ENUM) must be deployed first, so re-Accept works cleanly afterward.

## What went wrong
The `accepted` ENUM value never migrated (bundled with a `counted` removal → whole change withheld). On a non-strict MySQL connection, `status = 'accepted'` writes were silently coerced to `''`, leaving:

- **(A)** 3 purchase orders stranded at `status = ''` (their action buttons unreachable).
- **(B)** 132 phantom stock units committed for those POs across 6 products: `2818 +12, 2819 +24, 2718 +36, 2820 +12, 2714 +24, 2738 +24`.

## End state
POs reset to `placed` (Approved) and stock returned to the pre-accept baseline, so the operator can re-Accept each PO normally — which re-commits the stock **once**, correctly. If instead these were throwaway test POs, set the Part A target status to `cancelled`; the stock reversal is identical either way.

> Table prefix here is `2xnIt_` — adjust if staging differs.

## Part A — unstrand the POs (plugin table, safe SQL)

```sql
-- A1. INSPECT first — confirm exactly the expected rows (expect 3):
SELECT po_id, po_number, status, accepted_at
FROM   2xnIt_meals_purchase_orders
WHERE  status = '' AND accepted_at IS NOT NULL;

-- A2. Reset them to 'placed' (Approved), guarded to the stranded set only.
--     Confirm ROW_COUNT() = 3 before COMMIT, else ROLLBACK.
START TRANSACTION;
UPDATE 2xnIt_meals_purchase_orders
SET    status = 'placed', accepted_by = NULL, accepted_at = NULL
WHERE  status = '' AND accepted_at IS NOT NULL;
-- SELECT ROW_COUNT();   -- expect 3
COMMIT;

-- A3. VERIFY — expect 0 rows:
SELECT po_id, status FROM 2xnIt_meals_purchase_orders WHERE status = '';
```

## Part B — reverse the phantom stock (WooCommerce, via WP-CLI)

Do **not** hand-edit `_stock` postmeta: WC keeps stock in postmeta **and** the `wc_product_meta_lookup` table **and** a stock-status flag, and they must stay in sync. `wc_update_product_stock()` updates all three atomically. Run from the site root; it prints before/after so you can confirm the −132 total:

```bash
wp eval '
  $deltas = [2818=>12, 2819=>24, 2718=>36, 2820=>12, 2714=>24, 2738=>24];
  $total = 0;
  foreach ($deltas as $pid => $qty) {
    $p = wc_get_product($pid);
    if (!$p) { WP_CLI::warning("missing product $pid"); continue; }
    $before = (int) $p->get_stock_quantity();
    $after  = (int) wc_update_product_stock($p, $qty, "decrease");
    $total += $qty;
    WP_CLI::log("product $pid: $before -> $after (-$qty)");
  }
  WP_CLI::success("reversed $total units");'
```

Expected total reduction across the six products: **132 units**. After both parts, re-Accept one PO and confirm a single clean stock bump.
