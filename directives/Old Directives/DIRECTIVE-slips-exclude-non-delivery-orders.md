# DIRECTIVE — Slips: exclude orders with no meal line items

**Baseline:** v1.0.567.
**Source:** August holdout comparison against Enzebra packer slips, 2026-08-26.
**Severity:** MEDIUM-HIGH — operational, not billing. Roughly **60–100 phantom slips per month** are handed
to the packer.

---

## What happens

Slip generation includes orders that contain **no meals**. The monthly contribution-reset orders — created
at midnight Atlantic on the 1st of each month, `wc-completed`, `$0.00`, carrying only a contribution
product and no meal line items — are rendered as full packer and driver slips.

Midland receives a slip for a client who is receiving nothing.

## Evidence — confirmed across two independent months

Ground truth was extracted from Enzebra's Midland packer PDFs (`Zone N - Order #NNNNN` plus
`Delivery Date:` per page) and joined to our generated slips on WooCommerce order number.

**August 2–21, 2026:**

| | |
|---|---|
| Orders on our slips | 583 |
| Enzebra delivered in window | 510 |
| Present on both | 507 |
| Extras on our slips | 76 → **62 are `$0.00`, all created 2026-08-01 03:00** |
| Genuine extras (real value) | 14 |

**July 5–11, 2026 (re-checked):**

| | |
|---|---|
| Orders on our slips | 248 |
| Extras on our slips | 97 → **97 of 97 are `$0.00`, all created 2026-07-01 03:00** |

Enzebra excludes them in both months. Every one is `wc-completed`, `$0.00`, timestamped `03:00` — midnight
Atlantic on the 1st — which is the signature of the contribution-reset order, not a delivery.

**Delivery-date accuracy is unaffected and remains good:** 485/507 = **95.7%** for August, 96.8% for July.
This defect is about *which orders appear*, not what date they carry.

## Root cause

`MealsDB_Delivery_Slip_Generator::get_orders_for_delivery_range()`
(`includes/services/class-delivery-slip-generator.php`, line 367) selects candidates by creation window and
by `_delivery_date` override, then filters by **computed delivery occurrence**. Nothing in that path asks
whether the order contains anything deliverable.

An order with zero meal line items still resolves to a delivery occurrence (it has a creation date and the
client has a delivery weekday), so it lands on a slip.

---

# The fix

**Exclude orders with no meal line items from slip generation.**

Filter on **line-item content**, not on order total or status:

- **Do not filter on `total_amount == 0`.** A legitimate delivery could be fully covered by allowance and
  contribution and still total zero. Value is not the test; contents are.
- **Do not filter on status.** `wc-completed` is a normal status; the reset orders merely happen to use it.
- **Do filter on: the order contains at least one line item whose product is a MEAL.**

The product classification already exists — `meals_products.product_type` is `'meal'` or `'side'`, and the
same distinction is used elsewhere in the plugin.

## Which products count

**Include** an order if it has at least one line item of `product_type = 'meal'`.

**Decision needed (Zak):** should an order containing **only sides** — a dessert-only or soup-only
delivery, no mains — still produce a slip? It is a real delivery and the packer needs to pack it, so the
safer rule is likely *"at least one meal OR side line item"*, i.e. exclude only orders whose sole contents
are fee/contribution/overage products.

The exclusion list already exists and is used by the Quick Order clone path: `client_contribution` (5675),
`delivery_fee` (4122), and the legacy overage SKUs (5056, 5059, 5180), resolved through
`MealsDB_Invoice_Generator::get_fee_product_ids()` and
`MealsDB_Operational_Constants::overage_product_ids()` — the **configured** ids, not the seed constants.

**Reuse that resolution.** Do not hard-code product ids in the slip generator.

## Where to apply it

Prefer filtering at the **query** level (`get_orders_with_items_for_users` and
`get_orders_with_items_for_users_by_delivery_date` already fetch items) so empty orders never enter the
candidate set — cheaper than rendering and discarding.

If that proves awkward, filter immediately after `$by_id` is assembled and before the occurrence filter
runs, so the exclusion applies to both the creation-window candidates and the override rows.

**The override path must be filtered too.** An operator-set `_delivery_date` on a reset order must not
force it onto a slip.

## Must NOT change

- The **14-day creation lookback** window and the comment explaining it. That was derived from the
  following-week rule and is correct; a generous window is deliberate.
- The `_delivery_date` **override taking precedence** over the computed occurrence ("meta wins, occurrence
  otherwise").
- `delivery_occurrence_for_order()` and the following-week rule.
- The blank-`delivery_day` behaviour: no day → no occurrence → not on a slip.
- Packer and driver slips must continue to select the **same** order set. They agreed exactly on all three
  August ranges (233 / 191 / 159) and on July; that invariant must hold.

---

# Verify

1. Regenerate **2026-08-02 to 2026-08-08**, all zones. Order count should drop from **233** by the number
   of `$0.00` reset orders in that range. 📷
2. Confirm no slip in the output is for an order dated **2026-08-01 03:00** with `$0.00` total.
3. Regenerate all three August ranges and confirm the totals move from **233 / 191 / 159** to roughly
   **171 / 191 / 159** — the resets cluster in the first week, since they are created on the 1st.
4. **Packer and driver counts must still match each other exactly** for every range.
5. Regression: an order that legitimately totals `$0.00` **but contains meal line items** still produces a
   slip. Construct one if none exists naturally.
6. Regression: the 507 orders that matched Enzebra in Aug 2–21 are all still present. The exclusion must
   remove only the empty ones.
7. Spot-check a client who received both a reset order and a real delivery in the same week — the real
   delivery still appears, the reset does not.

---

# Not in this build

- The **3 orders Enzebra delivered in Aug 2–21 that our slips omit** — 28212 (created 2026-07-29),
  28605 (created 2026-08-12) and 28518 (created **2026-08-31**, i.e. entered after the delivery). The last
  is retroactive entry; the other two are worth a look but are a separate question from this filter.
- The **14 valued extras** on our August slips with no Enzebra record — most created 27–31 July, likely
  real deliveries missing from the packer PDFs I could extract rather than defects.
- Delivery-date residual misses (22 in August, all retroactive entry or one-day route shuffles).
