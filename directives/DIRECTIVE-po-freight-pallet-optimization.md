# Directive — PO freight optimization: snap orders to whole pallets (toggle)

> **STATUS: IMPLEMENTED (2026-07-06).** `MealsDB_Reports::optimize_po_for_pallets()`,
> the `optimize` AJAX flag, the view toggle, and `tests/test-po-freight-optimization.php`
> (39 checks) are in. As-built deviations from the spec below, all deliberate:
> 1. **Response shape = Option A (siblings), NOT `wp_send_json_success`/nested.** The base
>    rows stay under `data` and the base CSV under `csv` (unchanged contract); the optimised
>    variant is added as sibling keys `optimized` / `optimized_csv` / `summary` only when the
>    toggle is on. Nesting under `data` (as the spec sketched) would have broken the existing
>    JS, which reads `res.data` AS the rows array.
> 2. **Row note field is `seasonal_note`, not `note`.** The freight tag is appended there;
>    the machine-readable delta is a new `freight_delta_cases` field (+added / −removed).
> 3. **Coverage metric uses `current_stock` only (confirmed correct — see the note at the
>    coverage rule).** The base forecast was separately changed to stop reading the retired
>    future-inventory meta, so `current_stock` == availability and the two agree.

## Why
The company ships LTL from Ontario to the Maritimes and pays dimensional/cube weight, so a partial
pallet is nearly as expensive as a full one — partial pallets waste freight. The 9-week base order
usually lands on a fractional pallet count. This adds an OPTIONAL post-processing pass that snaps the
order to whole pallets: fill up a large remainder with soon-needed cases (freight already paid), or drop
a small remainder by trimming over-covered items — using the built-in 9→7 week cushion as the give.

This is a TOGGLE, not automatic: Janet generates the raw 9-week PO and can switch on a freight-optimized
view to see BOTH and what changed. Do NOT alter the base forecast; this is a pure post-pass over its rows.

## The rules (locked with operator)
- Pallet = **75 cases** (`MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET`, already defined).
- Compute the base order's total whole cases → `partial = total_cases mod 75`.
- **If `partial` < ⅓ pallet (< 25 cases): DROP** down to the whole-pallet count below. Remove whole cases
  from the MOST-over-covered products first, re-ranking after each case (spread), NEVER letting any
  product's post-order coverage fall below **7 weeks**. If the full partial can't be shed without
  breaching the 7-week floor, ROUND UP instead (fill — see next).
- **If `partial` ≥ ⅓ pallet (≥ 25 cases): FILL** up to the next whole pallet. Add whole cases to the
  LEAST-covered products first, re-ranking after each case (spread), staying under a **12-month (52-week)**
  coverage ceiling (with 12-mo shelf life and monthly ordering this rarely binds).
- **Spread, don't concentrate:** after each single whole case added/removed, RE-RANK by coverage and pick
  the next product — so adjustments distribute across many SKUs (each nudged a little), rather than piling
  onto one. This keeps any single item from being pulled too far forward / trimmed too hard.
- **Coverage** (the ranking + floor/ceiling metric) = `(current_stock + order_quantity) / adjusted_weekly`
  weeks, using the row's own `current_stock` and `adjusted_weekly`. Products with `adjusted_weekly <= 0`
  are NOT eligible to fill (can't rank) and NOT dropped below one case; skip them.

  > **Reviewer note (2026-07-06):** this `current_stock`-only coverage is CORRECT — do not add
  > `future_inventory`. The base `generate_purchase_order()` used to fold `_future_inventory_quantity`
  > (a retired future-dated-inventory plugin's unreliable meta) into `total_available`, but the base
  > forecast was updated to stop reading it, so `total_available` is now `current_stock` only. The freight
  > pass's metric therefore already matches the base forecast (`(total_available + order_quantity) /
  > adjusted_weekly`). No change needed here.
- Adjust in WHOLE CASES only; recompute `cases_to_buy` and `order_quantity` per changed row.

## Reference (v1.0.494)
- `includes/services/class-reports.php::generate_purchase_order()` returns rows (~429-441) each with:
  `sku, product_name, adjusted_weekly, projected_need, current_stock, future_inventory,
   total_available, units_needed, case_size, cases_to_buy, order_quantity, note`.
- Constant: `MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET` (=75).
- AJAX: `class-ajax-reports.php::generate_purchase_order()` (~71) calls `$reports->generate_purchase_order()`
  (~94) and returns `$po_rows` as JSON.
- UI: `views/purchase-order.php` (Generate `#mealsdb-po-generate`, Export `#mealsdb-po-export`),
  `assets/js/purchase-order.js` (~125 generate handler renders the table into `#mealsdb-po-output`).

## Implementation

### 1. New method — the freight pass (pure, testable)
Add to `MealsDB_Reports` (or a small helper class):
```php
/**
 * Snap a purchase-order row set to whole pallets for LTL freight efficiency.
 * Pure transform: takes base rows, returns adjusted rows + a summary. No DB.
 *
 * @param array $rows generate_purchase_order() rows (each with case_size,
 *                    cases_to_buy, order_quantity, current_stock, adjusted_weekly).
 * @return array ['rows'=>array, 'summary'=>array]  adjusted rows (each tagged
 *               with freight_delta_cases when changed) + a summary
 *               (base_cases, final_cases, pallets, action:'fill'|'drop'|'none',
 *                cases_changed).
 */
public static function optimize_po_for_pallets(array $rows): array
```
Algorithm:
1. `CPP = MealsDB_Operational_Constants::APETITO_CASES_PER_PALLET;`
2. `base_cases = sum(cases_to_buy)`. `partial = base_cases % CPP`. If `partial === 0` → action 'none',
   return rows unchanged.
3. `third = CPP / 3` (≈25).
4. Decide:
   - `partial >= third` → FILL: `gap = CPP - partial` cases to add.
   - else → DROP: `gap = partial` cases to remove; if the drop can't complete under the 7-week floor,
     switch to FILL with `gap = CPP - partial`.
5. Eligible rows = those with `adjusted_weekly > 0` and `case_size > 0`.
6. Loop `gap` times, each iteration operating on ONE whole case:
   - FILL: pick the eligible row with the LOWEST coverage where adding a case keeps
     `coverage <= 52 weeks`; `order_quantity += case_size; cases_to_buy += 1`. Re-rank next iteration.
   - DROP: pick the eligible row with the HIGHEST coverage where `cases_to_buy >= 1` AND removing a case
     keeps `coverage >= 7 weeks`; `order_quantity -= case_size; cases_to_buy -= 1`. Re-rank next iteration.
   - If no eligible row can take the step (fill: all at ceiling; drop: all at floor):
     - drop-stuck → recompute as FILL for the remaining gap (round up) and continue.
     - fill-stuck → stop (leave a small partial; summary notes it — should be near-impossible).
   Recompute `coverage = (current_stock + order_quantity) / adjusted_weekly` after each change.
7. Tag each changed row: `freight_delta_cases` (+/- cases moved) and append to its `note`
   (e.g. "Freight fill +2 cases (pull-forward)" / "Freight trim -1 case (was 9.4→7.6 wks)").
8. Return adjusted rows + summary.

### 2. AJAX — accept an `optimize` flag, return both
In `class-ajax-reports.php::generate_purchase_order()`:
- Read `$optimize = !empty($_POST['optimize']);` (after the existing nonce/cap/rate guard — unchanged).
- Always build base `$po_rows`. If `$optimize`, also compute
  `$opt = MealsDB_Reports::optimize_po_for_pallets($po_rows);` and include it:
  `wp_send_json_success(['rows'=>$po_rows, 'optimized'=>$opt['rows'] ?? null, 'summary'=>$opt['summary'] ?? null, 'pallets_base'=>..., 'pallets_final'=>...]);`
- Keep the response shape backward-compatible (base `rows` always present; `optimized`/`summary` only
  when requested).

### 3. UI — the toggle + pallet summary
`views/purchase-order.php` + `assets/js/purchase-order.js`:
- Add a checkbox/toggle near Generate: **"Optimize for freight (whole pallets)"** (id e.g.
  `#mealsdb-po-optimize`). When checked, the generate POST includes `optimize: 1`.
- After generate, show a **pallet summary line**: base = X cases (Y.YY pallets); optimized = Z cases
  (N.00 pallets); action = filled/trimmed K cases. Render BOTH tables (or one table with a
  base-vs-optimized column, and highlight changed rows via `freight_delta_cases`). Simplest: when
  optimize is on, render the optimized rows with a "Δ cases" column and the summary line above; a
  "show base order" collapse can reveal the raw rows so she sees both.
- Export: when optimized, the CSV export should reflect whichever view is shown (add a note column with
  the freight delta). Keep the base export working when the toggle is off.

## Must NOT change
- The base `generate_purchase_order()` forecast math (9-week coverage, seasonal, case rounding). The
  freight pass is strictly downstream and only runs when the toggle is on.
- Whole-case integrity: never produce a non-case-multiple order_quantity.

## Verify
```
php -l includes/services/class-reports.php
php tests/test-*.php
```
- Live-like data: a base order of 788 cases (10.51 pallets) with a 38-case partial (≥ ⅓) → optimizer
  FILLS 37 cases into lowest-coverage products → 825 cases = 11.00 pallets exactly; every filled product
  stays ≤ 52 wks; fills spread across many SKUs (not piled on one).
- A base order with a small partial (e.g. 8 cases over a pallet) → optimizer DROPS 8 cases from
  highest-coverage products, none falling below 7 wks → lands on a whole pallet.
- Construct a case where dropping would breach 7 wks everywhere → optimizer ROUNDS UP instead.
- Toggle OFF → response identical to today (base rows only), no behavior change.
- Every optimized order total is an exact multiple of 75 cases (or documented near-impossible fill-stuck).

## Test to add
`tests/test-po-freight-optimization.php`:
- FILL: rows summing to 788 cases, partial 38 ≥ 25 → assert final 825, multiple of 75, only low-coverage
  rows increased, none over 52 wks, spread across ≥ ~20 rows.
- DROP: rows summing to 758 cases (partial 8 < 25) → assert final 750, none below 7 wks.
- DROP-STUCK→FILL: small partial but all rows already at ~7 wks → assert it rounds UP to whole pallet.
- Whole-case integrity: every adjusted order_quantity % case_size == 0.
