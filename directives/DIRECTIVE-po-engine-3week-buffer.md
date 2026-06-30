# Directive — Lock the Purchase-Order engine to the validated 3-week-buffer model

## Goal
Change `MealsDB_Reports::generate_purchase_order()` so it runs EXACTLY the forecasting model that the
2025 back-test validated: a fixed 6-week order horizon PLUS a demand-proportional 3-week safety buffer
(9 weeks of coverage total), with the other parameters fixed at their validated values. There must be
**no configurable way to run it differently** — the tunable parameters are removed and the validated
constants are hardcoded. One behavior, no knobs.

## Why (context, do not reimplement the simulation)
The back-test reconstructed the engine's math in standalone code and swept the buffer. The 3-week buffer
was the chosen setting: it cut order volatility below the status quo (0.245 → 0.165) and dropped
stockouts to a single month, at an acceptable inventory level. We are now making the live engine match
that validated configuration. The math already lives in the plugin; we are fixing its parameters and
changing how the buffer works — NOT porting Python.

## Reference (study before editing)
`includes/services/class-reports.php`, method `generate_purchase_order()`:
- Signature (~line 128): currently
  `generate_purchase_order(int $trailing_weeks = 12, int $order_horizon_weeks = 6, float $decay_factor = 0.85)`.
- Param clamps ~lines 140–146; seasonal clamp consts ~148–149 (`seasonal_min=0.3`, `seasonal_max=3.0`),
  `min_weeks_required=2` (~150).
- Weighted-average loop ~lines 282–298 (`$weighted_avg`).
- Seasonal index ~lines 320–326 (`$seasonal_index`, clamped).
- **The order-quantity block (~lines 328–345) — this is the core change:**
  ```php
  $projected_need  = (int) ceil($adjusted_weekly * $order_horizon_weeks);
  $buffer          = (int) get_post_meta($pid, 'buffer', true);
  ...
  $qty_needed      = $projected_need + $buffer;
  $units_needed    = max(0, $qty_needed - $total_available);
  $cases_to_buy    = $units_needed > 0 ? (int) ceil($units_needed / $case_size) : 0;
  $order_quantity  = $cases_to_buy * $case_size;
  ```

## The validated model (what the engine must now do, fixed)
Per product:
```
trailing_weeks   = 12                 (fixed)
decay_factor     = 0.85               (fixed; most-recent week weight = 1.0, decaying back)
order_horizon    = 6  weeks           (fixed)
buffer_weeks     = 3  weeks           (fixed)  <-- NEW: demand-proportional, NOT flat units
coverage_weeks   = order_horizon + buffer_weeks = 9
seasonal_index   = year-over-year ratio, clamped [0.3, 3.0], default 1.0 when no prior-year data
adjusted_weekly  = weighted_avg * seasonal_index
projected_need   = ceil( adjusted_weekly * coverage_weeks )           // 9 weeks of demand
units_needed     = max(0, projected_need - (current_stock + future_inventory))
order_quantity   = ceil(units_needed / case_size) * case_size         // whole cases, only if > 0
```

## Edits

### 1. Remove the configurable parameters — hardcode the validated constants
Change the method signature so it takes NO forecasting parameters (keep any non-forecasting args if the
method has them, e.g. an output/format flag — but the three tunables go):
```php
// BEFORE
public function generate_purchase_order(
    int $trailing_weeks = 12,
    int $order_horizon_weeks = 6,
    float $decay_factor = 0.85
) {
// AFTER
public function generate_purchase_order() {
    // Locked to the back-test-validated 3-week-buffer model. Not configurable by design.
    $trailing_weeks      = 12;
    $order_horizon_weeks = 6;
    $decay_factor        = 0.85;
    $buffer_weeks        = 3;
    $coverage_weeks      = $order_horizon_weeks + $buffer_weeks; // 9
```
Delete the now-dead parameter-clamp lines (~140–146) since the values are fixed and valid by construction.
Keep the seasonal clamp consts (0.3 / 3.0) and `min_weeks_required` as-is.

### 2. Replace the flat per-product buffer with the demand-proportional 3-week buffer
In the order-quantity block, the buffer must become 3 weeks of that product's adjusted weekly demand,
applied by extending the coverage from 6 to 9 weeks. Remove the `get_post_meta($pid, 'buffer', true)`
flat-unit buffer entirely (it is superseded and would double-count):
```php
// BEFORE
$projected_need  = (int) ceil($adjusted_weekly * $order_horizon_weeks);
$buffer          = (int) get_post_meta($pid, 'buffer', true);
...
$qty_needed      = $projected_need + $buffer;
$units_needed    = max(0, $qty_needed - $total_available);

// AFTER
// 6-week horizon + 3-week demand-proportional safety buffer = 9 weeks of coverage.
$projected_need  = (int) ceil($adjusted_weekly * $coverage_weeks);
$units_needed    = max(0, $projected_need - $total_available);
```
(`$total_available` = current_stock + future_inventory stays exactly as-is. Case rounding stays as-is.)

IMPORTANT: do NOT keep the old `buffer` meta as an additional add-on. The validated model's buffer IS
the 3 extra weeks of demand. Adding the flat meta on top would over-order. Remove its use here.

### 3. Update every caller of generate_purchase_order()
Find all call sites and remove the arguments they pass (they were passing trailing_weeks / horizon /
decay). Grep:
```
grep -rn "generate_purchase_order(" includes/ views/ assets/
```
Update each call to `generate_purchase_order()` with no forecasting args. If any UI exposed inputs for
trailing weeks / horizon / decay / buffer (a settings form or report controls), REMOVE those inputs —
the model is no longer configurable. Check the reports admin page + its JS for such fields.

### 4. Docblock + any on-screen explanation
Update the method docblock to state the model is fixed (12-week recency-weighted history, decay 0.85,
6-week horizon + 3-week demand-proportional buffer = 9 weeks coverage, seasonal index clamped
[0.3,3.0]). If the report screen shows a parameter summary, update it to reflect the fixed model rather
than editable values.

## Must NOT change
- The weighted-average math, the seasonal-index calculation/clamp, case-size rounding, the
  current_stock + future_inventory subtraction, CSV/output formatting. Only the parameterization and the
  buffer mechanism change.
- Do not introduce pallet rounding / floor-space cap / shelf-life ceiling here (separate future work).

## Verify
```
php -l includes/services/class-reports.php
grep -rn "generate_purchase_order(" includes/ views/ assets/   # no callers pass forecasting args
grep -n "get_post_meta(\$pid, 'buffer'" includes/services/class-reports.php   # should return nothing
php tests/test-*.php
```
- Confirm there is no code path (param, meta, setting, or filter) that runs the forecast with any horizon
  or buffer other than 6 + 3. The ONLY way to run it is the validated model.
- Spot-check one product by hand: order_quantity should equal
  `ceil( ceil(weighted_avg * seasonal_index * 9) - current_stock - future_inventory ) ` rounded up to a
  whole number of cases (0 if not positive).
- If a PO test asserts the old 6-week-only need, update it to expect 9-week coverage and no flat buffer.

## Note on case sizes (data, not code)
The forecast rounds to whole cases via `case_size`. The back-test confirmed real case sizes exist for
food products (12/24/36/48/100) and that non-food SKUs (fees/containers/gift cards) have none and should
be excluded. Ensure the engine continues to skip products without a valid case_size / not Apetito-supplied
(it already resolves case_size with a fallback; do not let fee/container/gift SKUs generate PO lines).
