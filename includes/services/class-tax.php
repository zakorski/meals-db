<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Tax-rate resolution helper.
 *
 * WHY this exists (DIRECTIVE hst-rate-source, ITEM 1): the old code called
 * WC_Tax::get_rates('') — the STANDARD class at "no location", which
 * WooCommerce falls back to the store base (currently CA:NS), and then took
 * reset() of the result. It was correct only by coincidence: the NS
 * standard-class row happens to sit at 15%. The moment anyone corrects that
 * row to NS's real 14%, every government invoice would silently drop to 14%
 * and under-report HST. We instead ask WooCommerce for the CA/NB row in the
 * 'hst' class EXPLICITLY, so the answer no longer depends on the store base.
 *
 * PLACE-OF-SUPPLY NOTE (verified 2026-08-21): five VAC Veterans have genuine
 * Nova Scotia (Amherst, B4H) delivery addresses. Legacy Enzebra has always
 * billed them at NB 15% regardless, and Janet submits those invoices as such,
 * so a flat NB rate reproduces current practice exactly. This is a DELIBERATE
 * business choice (NB place-of-supply for all government meals) — not an
 * accident, but a choice. If NS delivery should attract NS HST, the government
 * path would need per-client delivery_province resolution; that is an operator
 * decision, out of scope here.
 */
class MealsDB_Tax {

    /**
     * Resolve the New Brunswick HST rate as a decimal fraction (e.g. 0.15).
     *
     * NO FALLBACK to the store base, by design. Returns 0.0 on any of:
     * WC_Tax missing, find_rates() throwing, the 'hst' class holding no CA/NB
     * row, MORE than one matching row (ambiguous — we refuse to guess), or a
     * non-positive rate. Every 0.0 path records an ERROR-severity event so a
     * misconfigured tax table is visible, never silently billed at 0%.
     */
    public static function resolve_nb_hst_rate(): float {
        if (!class_exists('WC_Tax')) {
            self::record_rate_failure('WC_Tax unavailable', null);
            return 0.0;
        }

        try {
            // find_rates() takes an EXPLICIT location + class and filters
            // internally, so it does not depend on WooCommerce resolving a
            // customer location it doesn't have (the get_rates('') trap).
            $rates = \WC_Tax::find_rates([
                'country'   => 'CA',
                'state'     => 'NB',
                'tax_class' => 'hst',
            ]);
        } catch (\Throwable $e) {
            self::record_rate_failure('WC_Tax::find_rates threw: ' . $e->getMessage(), null);
            return 0.0;
        }

        if (!is_array($rates) || count($rates) === 0) {
            self::record_rate_failure('No CA/NB row in the hst tax class', 0);
            return 0.0;
        }
        if (count($rates) > 1) {
            // Refuse to reset()-and-hope on a multi-row result — that habit is
            // exactly what caused this bug. Surface it and bill 0 so it can't
            // pass silently.
            self::record_rate_failure('Ambiguous CA/NB hst rows', count($rates));
            return 0.0;
        }

        $row  = reset($rates); // exactly one row, asserted above
        $rate = (is_array($row) && isset($row['rate'])) ? (float) $row['rate'] : 0.0;
        if ($rate <= 0) {
            self::record_rate_failure('CA/NB hst row resolved to a non-positive rate', 0);
            return 0.0;
        }

        return $rate / 100;
    }

    /**
     * Human-readable description of the rate + source for the invoice draft
     * screen (DIRECTIVE ITEM 1c). Never throws.
     */
    public static function describe_nb_hst_source(): string {
        $rate = self::resolve_nb_hst_rate();
        if ($rate <= 0) {
            return __('HST rate could not be resolved from WooCommerce (billed as 0%) — check WC Settings → Tax.', 'meals-db');
        }
        $pct = rtrim(rtrim(number_format($rate * 100, 4, '.', ''), '0'), '.');
        return sprintf(
            /* translators: %s is a percentage such as "15" */
            __('%s%% — WooCommerce “hst” tax class, CA/NB row', 'meals-db'),
            $pct
        );
    }

    private static function record_rate_failure(string $why, ?int $rows_found): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Tax] NB HST rate unresolved: ' . $why . ' — billing HST as 0%.');
        }
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'error',
                'category'  => 'billing',
                'subsystem' => 'tax',
                'event'     => 'resolve_nb_hst_rate.failed',
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => 'NB HST rate unresolved (' . $why . ') — HST resolved to 0%.',
                'context'   => ['rows_found' => $rows_found],
            ]);
        }
    }
}
