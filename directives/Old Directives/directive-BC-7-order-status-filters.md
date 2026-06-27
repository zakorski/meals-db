# Directive BC-7: Failed/refunded orders print delivery slips and inflate purchase orders

**Audit reference:** 2026-06 review, reports/order-query subsystem (`class-wc-order-query.php`, `class-reports.php`).
**Severity:** MEDIUM — a refunded/failed order still ships (driver slip prints "Collect: $X") and inflates the purchase-order projection.
**Scope:** ~5–15 lines, primarily `includes/services/class-wc-order-query.php`. **Risk:** LOW. (Operator confirmed `wc-pending` is excluded — see P3.)

---

## Background — two incompatible status conventions

The codebase uses two different order-status filters for "which orders count":

**Whitelist** (paid statuses only) — used by the reconciliation sums and the private report:
```php
// class-wc-order-query.php ~413, 452, 489; class-reports.php ~987
AND o.status IN ('wc-processing', 'wc-completed', 'wc-paid', ...)
```

**Blacklist** (exclude a few) — the DEFAULT for `get_orders_for_users()` / `get_orders_for_delivery_range()`, which feed **delivery slips** and **purchase-order demand**:
```php
// class-wc-order-query.php ~42, ~169
array $exclude_statuses = ['wc-cancelled', 'wc-on-hold', 'wc-draft', 'draft', 'wc-trash', 'trash']
```

The blacklist **admits `wc-failed`, `wc-refunded`, and `wc-pending`**. Consequences:

- A **fully refunded** order (`wc-refunded`) is excluded from the allocation ledger (the refund hook deallocates it) but is **still returned by the slip/PO query** — so the driver gets a slip and a collection amount for an order the customer was refunded.
- A **failed** order (`wc-failed`) likewise prints slips and inflates the PO meal projection.
- **Pending** (`wc-pending`) — the operator confirmed unpaid pending orders are not cooked/delivered until payment clears, so this too is excluded (resolved in P3).

The whitelist callers are correct; the blacklist default is the problem.

---

## Pre-flight verification

**P1 — Confirm the two conventions and their callers.**
```bash
grep -n "exclude_statuses\|status IN\|status NOT IN" includes/services/class-wc-order-query.php
grep -n "get_orders_for_users\|get_orders_for_delivery_range\|get_orders_with_items_for_users" includes/services/class-delivery-slip-generator.php includes/services/class-reports.php
```

**P2 — Confirm slips flow through the blacklist default.**
```bash
grep -n "get_orders_for_delivery_range\|exclude_statuses" includes/services/class-delivery-slip-generator.php includes/services/class-slip-pdf-generator.php
```

**P3 — OPERATOR DECISION (resolved):** the operator confirmed an unpaid `wc-pending` order is NOT cooked/delivered until payment clears, so `wc-pending` is excluded from the slip/PO set alongside failed/refunded.

---

## The fix

### Step 1 — Add failed/refunded to the default exclusion list

In `class-wc-order-query.php`, both `get_orders_for_users()` (~42) and `get_orders_for_delivery_range()`/`get_orders_with_items_for_users()` (~169) default `$exclude_statuses`:

```php
// BC-7: a failed or refunded order must never print a delivery slip or inflate
// the purchase-order projection. (Cancelled/trash/draft already excluded.)
// wc-pending is excluded per the operator's decision (P3): an unpaid pending
// order is not cooked/delivered until payment clears.
array $exclude_statuses = [
    'wc-cancelled', 'wc-on-hold', 'wc-draft', 'draft', 'wc-trash', 'trash',
    'wc-failed', 'wc-refunded',
    'wc-checkout-draft',                 // HPOS abandoned-checkout drafts
    'wc-pending',                        // unpaid — not cooked until payment clears
]
```

Also add `wc-checkout-draft` (HPOS abandoned-checkout placeholder) — it should never count anywhere; it's only absent from the current list because the list predates HPOS.

### Step 2 — Add the `shop_order` type filter (defense in depth)

Confirm `get_orders_for_users` already filters `o.type = 'shop_order'` (the audit noted line ~79 does). If any sibling query in this file omits it, add it — a `shop_order_refund` row matching a `customer_id` must never be hydrated as an order. The rebuilder's own pull (BC-1) gets the same `type = 'shop_order'` filter.

### Step 3 — Document each report's intent

Add a one-line comment at each PO/demand query in `class-reports.php` stating whether pending is intentionally included, so the next maintainer doesn't "fix" a deliberate choice. The blacklist/whitelist split is fine as long as each is intentional and commented.

---

## Testing

`tests/test-order-status-filters.php` (mock `$wpdb` / stub):
1. **Refunded excluded from slips:** a `wc-refunded` order does not appear in `get_orders_for_delivery_range`.
2. **Failed excluded from PO demand:** a `wc-failed` order does not contribute meals to the PO projection.
3. **Checkout-draft excluded everywhere.**
4. **Pending behaviour matches the P3 decision** (assert included or excluded per the operator's answer).
5. **Paid statuses still counted** (no regression on processing/completed/paid).

**Manual:** on staging, mark an order refunded, generate the day's delivery slips, confirm it no longer prints; generate the PO, confirm its meals aren't projected.

---

## Out of scope

- Do not change the whitelist callers (reconciliation / private report) — they're already correct.
- Do not change refund **allocation** handling (BC-6 owns the partial-refund meal-count reduction; BC-7 only stops fully-failed/refunded orders from being treated as live).

## Acceptance criteria

- [ ] `wc-failed`, `wc-refunded`, `wc-checkout-draft` added to the default `$exclude_statuses`.
- [ ] `wc-pending` inclusion/exclusion matches a recorded operator decision (P3), commented.
- [ ] `o.type = 'shop_order'` present on every order query in the file.
- [ ] Per-report pending intent documented.
- [ ] Tests cover refunded/failed/checkout-draft exclusion, pending-per-decision, paid no-regression.
