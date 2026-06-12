<?php
/**
 * Shared cash-collection math for driver and packing slips.
 *
 * The driver slip and the enhanced packing slip both show "what the
 * driver is expected to collect at the door". Keeping this logic in one
 * place guarantees the two surfaces never drift — the packing slip
 * doubles as the customer-facing invoice the driver reconciles against,
 * so any divergence would look like a reporting mismatch.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Collection_Calculator {

    /**
     * Compute what the driver collects for a private customer delivery.
     *
     * Rules:
     *   - Cash payments: collect the order total PLUS the client's
     *     delivery fee (billed separately from the order subtotal).
     *   - Non-cash with a delivery fee: collect only the delivery fee.
     *   - Non-cash with no delivery fee: prepaid; nothing to collect.
     *
     * @return array{collect: float|null, is_prepaid: bool}
     */
    public static function for_private(float $total, float $delivery_fee, string $payment_method): array {
        $delivery_fee = max(0.0, $delivery_fee);

        if ($payment_method === 'cash') {
            return [
                // Sum in integer cents so two 2-dp floats can't render as
                // 25.700000000000003 if a display path drops number_format().
                'collect'    => (MealsDB_Money::to_cents($total) + MealsDB_Money::to_cents($delivery_fee)) / 100.0,
                'is_prepaid' => false,
            ];
        }

        if ($delivery_fee > 0) {
            return [
                'collect'    => $delivery_fee,
                'is_prepaid' => false,
            ];
        }

        return [
            'collect'    => null,
            'is_prepaid' => true,
        ];
    }

    /**
     * Compute collection for government (SDNB / Veteran) clients.
     *
     * Government orders settle via the program, not at the door. The
     * driver still collects the delivery fee per delivery, plus the
     * client's monthly contribution on the first delivery of each
     * billing month.
     *
     * @return array{collect: float, contribution_due: float, is_prepaid: bool}
     */
    public static function for_government(
        float $delivery_fee,
        float $client_contribution,
        bool $is_first_delivery_of_month
    ): array {
        $delivery_fee        = max(0.0, $delivery_fee);
        $client_contribution = max(0.0, $client_contribution);

        $contribution_due = ($is_first_delivery_of_month && $client_contribution > 0)
            ? $client_contribution
            : 0.0;

        return [
            // Integer-cents sum — avoid float drift (see for_private()).
            'collect'          => (MealsDB_Money::to_cents($delivery_fee) + MealsDB_Money::to_cents($contribution_due)) / 100.0,
            'contribution_due' => $contribution_due,
            'is_prepaid'       => false,
        ];
    }
}
