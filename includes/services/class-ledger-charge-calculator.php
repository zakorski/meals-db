<?php
/**
 * Computes the per-order CLIENT-side charge for the receivables ledger (K17
 * ITEM 2). Pure integer-cents arithmetic — every price is passed in, so this is
 * unit-testable with no WooCommerce and no float.
 *
 * Two shapes, by client type:
 *   - SDNB / Veteran: the FEE LINES only (client_contribution + delivery_fee).
 *     The meals belong to the PROGRAM charge (the government invoice) and must
 *     not appear on the client's account, or they'd be billed twice.
 *   - Private: the ORDER TOTAL at audit-adjusted quantities — each delivery
 *     line's unit price x its effective quantity (the edited qty if the auditor
 *     changed it, else the ordered qty), plus any added items. Reflects what
 *     was actually delivered.
 *
 * Anything else (unknown type) → 0: no client-side charge.
 */
defined('ABSPATH') || exit;

class MealsDB_Ledger_Charge_Calculator {

    /**
     * @param string $client_type 'SDNB' | 'Veteran' | 'Private' (case-insensitive)
     * @param array  $row    audit row entry: items[] (item_key, qty), edited_items{}, added_items[]
     * @param array  $prices contribution_cents, delivery_fee_cents,
     *                        line_unit_cents[item_key], added_unit_cents[product_id]
     * @return int charge in integer cents (>= 0)
     */
    public static function client_charge_cents(string $client_type, array $row, array $prices): int {
        $type = strtolower(trim($client_type));

        if ($type === 'sdnb' || $type === 'veteran') {
            // Fee lines only. Both are per-order amounts already resolved from
            // the order's fee product lines (contribution appears only on the
            // order that won the monthly claim, so this is once-per-month for
            // free — directive Test 4).
            return max(0, (int) ($prices['contribution_cents'] ?? 0) + (int) ($prices['delivery_fee_cents'] ?? 0));
        }

        if ($type === 'private') {
            $line_unit  = (array) ($prices['line_unit_cents'] ?? []);
            $added_unit = (array) ($prices['added_unit_cents'] ?? []);
            $edited     = (array) ($row['edited_items'] ?? []);

            $total = 0;
            foreach ((array) ($row['items'] ?? []) as $item) {
                $key = (int) ($item['item_key'] ?? 0);
                // Effective qty: the audited quantity if edited, else as ordered.
                $qty = array_key_exists($key, $edited) ? (int) $edited[$key] : (int) ($item['qty'] ?? 0);
                $total += (int) ($line_unit[$key] ?? 0) * max(0, $qty);
            }
            foreach ((array) ($row['added_items'] ?? []) as $added) {
                $pid = (int) ($added['product_id'] ?? 0);
                $qty = max(0, (int) ($added['qty'] ?? 0));
                $total += (int) ($added_unit[$pid] ?? 0) * $qty;
            }
            return max(0, $total);
        }

        return 0;
    }
}
