# Directive LB-7: Consolidate SDNB rates, drop price-keyed tier lookup, fix HST to a clean 15%

**Audit reference:** recon-04 (BUG/STRUCT, Q4.1/Q4.2), recon-11 (constants dual-maintenance, Q11.6), recon-14 §2 LB-7. Operator pricing email + clarifications (mains never taxed; pre-tax prices; taxable sides × 15%).
**Severity:** LAUNCH BLOCKER (cutover) — but the CODE fixes here are safe to do NOW; only the rate VALUE flip is deferred. **Scope:** moderate — `class-invoice-generator.php` (two HST/rate sites + delete the private tier table) and `class-operational-constants.php` (remove the obsolete multipliers). **Risk:** MEDIUM — touches live invoice tax math; mitigated by the existing per-item taxable tracking and a thorough test plan.

> **IMPORTANT — do NOT change the SDNB rate VALUES in this directive.** Per the operator, SDNB pricing stays as-is pending Social Development IT's retroactivity answer; retro billing is out of scope. This directive does the structural CODE fixes (de-dup, re-key, HST mechanism) so the eventual value flip is a one-line, one-place change. Private/Veteran values are also set at cutover, not here. The VAC per-main allowance is still PENDING the operator.

---

## Background (three intertwined problems)

**1. Dual-maintained rates (Q4.2/Q11.6).** SDNB rates live in BOTH `MealsDB_Operational_Constants` (the intended single source) AND the invoice generator's private `$sdnb_rate_tiers` array (lines 53–66): `secondary_rate_mains/sides` and `hst_multiplier_line1/2`. A rate change must edit both or invoicing silently mis-bills.

**2. Tier lookup keyed on the mutable combined price.** Both consumption sites build `$rate_key = number_format($resolved_rate, 2)` → `'14.66'` / `'15.47'` and index `$sdnb_rate_tiers[$rate_key]` (lines 196–198 and 261–262). When the combined price changes to `16.40` / `17.30`, the keys match nothing → `$tier` is null → HST multipliers and secondary rates silently default to 0 (lines 270–271, 296). This is the "unrecognized value → silent default" failure class.

**3. The HST multiplier model is wrong for pre-tax pricing.** HST is computed as `tax_sides_count × hst_multiplier` (lines 199, 280–290), where the multipliers (`0.672`/`0.82`/`0.681`) are baked-in net-portion factors derived from the OLD combined prices. The operator has confirmed: **prices are PRE-TAX, mains are NEVER taxed, and taxable sides simply get HST = price × 15%.** So the entire multiplier apparatus is obsolete; the correct HST is `tax_sides_count × side_rate × 0.15`.

**The constants file already flags this.** `MealsDB_Operational_Constants` lines 118–133 also carry `HST_MULTIPLIER_*` constants with a comment: "Values are historical... If HST rate changes... these need recomputing." That's the same obsolete model, duplicated. It can be deleted too.

**What's already correct (do NOT rebuild):** the per-item taxable split is tracked end-to-end — `tax_sides_count` / `nontax_sides_count` on allocation detail, the rebuilder allocates taxable-first, and the invoice only ever taxes `tax_sides` (mains are never fed into any HST calc). The taxable flag lives on the products table. So "mains never taxed; only taxable sides taxed" is already the structure — this directive just fixes HOW the taxable-side HST is computed and WHERE the rates come from.

---

## Pre-flight verification

**P1 — Confirm the private tier table and its two consumers.**
```bash
sed -n '53,66p' includes/services/class-invoice-generator.php
grep -n "sdnb_rate_tiers\|rate_key\|hst_multiplier\|secondary_rate" includes/services/class-invoice-generator.php
```
Expect the table at 53–66 and consumers at ~196–199 and ~261–301.

**P2 — Confirm the constants' zone-aware rate helpers exist.**
```bash
sed -n '95,100p;174,195p' includes/class-operational-constants.php
```
Expect `SDNB_RATE_PRIMARY_MAIN[_RURAL]`, `SDNB_RATE_SECONDARY_MAIN[_RURAL]`, `SDNB_RATE_SIDE[_RURAL]`, and helpers `get_sdnb_main_rate($tier, $rural)` / `get_sdnb_side_rate($rural)`.

**P3 — Determine how rurality and tier are known at the two consumption sites.** This is the crux: the tier table was keyed on the combined PRICE, which encoded both rurality and primary/secondary. To replace it you need rurality (urban/rural) and which tier (primary/secondary = line 1 / line 2) at each site.
```bash
grep -n "resolve_rate_for_order\|rural\|is_rural\|delivery_area\|zone\|second_line_rate\|secondary" includes/services/class-invoice-generator.php | head
sed -n '/function resolve_rate_for_order/,/^    }/p' includes/services/class-wc-order-query.php 2>/dev/null | head -40
```
Expect to find how `resolved_rate` is derived. **If rurality is currently DERIVED from the rate value** (e.g. "15.47 means rural"), you must instead derive it from the client's zone/`delivery_area` so the code no longer depends on the price. Confirm the client row carries a zone/rurality signal (recon-05/09 noted `delivery_area_name` / zone tag; the operator confirmed Sussex rurality comes from the legacy zone tag). **Record exactly how rurality is determined before writing the fix** — this determines the helper calls below.

**P4 — Confirm `MealsDB_Money` helpers for the math** (`multiply`, `to_cents`, rounding) so HST is computed without float drift.

**P5 — Confirm HST constant.** `MealsDB_Operational_Constants::VAC_SIDES_HST_RATE = 0.15` (line 115) already exists. Either reuse it or add a clearly-named `HST_RATE = 0.15`. (HST is the same 15% for SDNB and VAC sides; a single `HST_RATE` constant is cleanest.)

---

## The fix

### Step 0 — Add a single HST_RATE constant (if not reusing VAC_SIDES_HST_RATE)
In `MealsDB_Operational_Constants`:
```php
    /** Harmonized Sales Tax rate applied to taxable sides (mains are never taxed). */
    const HST_RATE = 0.15;
```

### Step 1 — Replace the HST math at site 1 (the per-client basic calc, ~lines 195–200)

Replace:
```php
            $tax_cents = 0;
            $rate_key  = number_format((float) $resolved_rate, 2, '.', '');
            if (isset(self::$sdnb_rate_tiers[$rate_key]) && $allocated_tax_sides > 0) {
                $mult = (float) self::$sdnb_rate_tiers[$rate_key]['hst_multiplier_line1'];
                $tax_cents = (int) round($allocated_tax_sides * $mult * 100);
            }
```
with (taxable sides × pre-tax side rate × 15%; mains contribute nothing):
```php
            // HST: taxable sides only, at the pre-tax side rate × 15%. Mains are
            // never taxed. (LB-7 — replaces the obsolete net-portion multiplier.)
            $tax_cents = 0;
            if ($allocated_tax_sides > 0) {
                $rural        = self::client_is_rural($client);          // see Step 3
                $side_rate    = MealsDB_Operational_Constants::get_sdnb_side_rate($rural);
                $sides_cents  = MealsDB_Money::multiply($allocated_tax_sides, $side_rate);
                $tax_cents    = MealsDB_Money::multiply_rate($sides_cents, MealsDB_Operational_Constants::HST_RATE);
            }
```
(Use whatever `MealsDB_Money` API multiplies a cents amount by a rate; if none exists, compute `to_cents(round(tax_sides * side_rate * 0.15, 2))`. Confirm in P4.)

### Step 2 — Replace the two-line split (~lines 259–302)

This is the intricate part. The two-line SDNB invoice splits mains across a primary line (line 1) and secondary line (line 2). Replace the tier-keyed lookups:

- `$hst_mult_l1 / $hst_mult_l2` → compute HST per line as `tax_sides_on_line × side_rate × 0.15`.
- `$tier['secondary_rate_mains'] / ['secondary_rate_sides']` (the line-2 rate, ~lines 296–301) → `MealsDB_Operational_Constants::get_sdnb_main_rate('secondary', $rural)` and `get_sdnb_side_rate($rural)`.

Concretely:
```php
    private static function split_into_invoice_lines(array $row): array {
        $client = $row['client'];
        $rural  = self::client_is_rural($client);                       // Step 3
        $side_rate = MealsDB_Operational_Constants::get_sdnb_side_rate($rural);

        $bill_mains        = (int) $row['bill_mains'];
        $bill_sides        = (int) $row['bill_sides'];
        $bill_tax_sides    = (int) $row['bill_tax_sides'];
        $bill_nontax_sides = (int) $row['bill_nontax_sides'];
        $client_contribution_cents = MealsDB_Money::to_cents($row['client_contribution'] ?? 0);

        // Line 1 / Line 2 unit splits — UNCHANGED logic.
        $mains_on_line_1 = ($bill_sides == 0) ? $bill_mains : min($bill_mains, $bill_sides);
        $tax_sides_on_line_1 = ($bill_sides == 0 || $bill_tax_sides == 0)
            ? 0 : min($mains_on_line_1, $bill_tax_sides);
        $nontax_sides_on_line_1 = ($bill_sides == 0 || $bill_nontax_sides == 0)
            ? 0 : min($mains_on_line_1 - $tax_sides_on_line_1, $bill_nontax_sides);

        // HST per line: taxable sides × pre-tax side rate × 15%. (LB-7)
        $hst_line_1_cents = ($tax_sides_on_line_1 > 0)
            ? MealsDB_Money::multiply_rate(MealsDB_Money::multiply($tax_sides_on_line_1, $side_rate), MealsDB_Operational_Constants::HST_RATE)
            : 0;

        $mains_on_line_2        = max(0, $bill_mains - $mains_on_line_1);
        $tax_sides_on_line_2    = $bill_tax_sides - $tax_sides_on_line_1;
        $nontax_sides_on_line_2 = $bill_nontax_sides - $nontax_sides_on_line_1;
        $hst_line_2_cents = ($tax_sides_on_line_2 > 0)
            ? MealsDB_Money::multiply_rate(MealsDB_Money::multiply($tax_sides_on_line_2, $side_rate), MealsDB_Operational_Constants::HST_RATE)
            : 0;

        $has_second_line = ($mains_on_line_2 + $tax_sides_on_line_2 + $nontax_sides_on_line_2 + $hst_line_2_cents) > 0;

        // Line-2 rate from constants, not the deleted tier table. (LB-7)
        $second_line_rate = 0;
        if ($has_second_line) {
            $second_line_rate = ($mains_on_line_2 > 0)
                ? MealsDB_Operational_Constants::get_sdnb_main_rate('secondary', $rural)
                : (($tax_sides_on_line_2 + $nontax_sides_on_line_2 > 0) ? $side_rate : 0);
        }
        // ... rest of the line-array construction UNCHANGED ...
```

> Keep all the line-array construction (the `$lines[] = [...]` blocks) as-is; only the HST and secondary-rate SOURCES change. The line-1 primary rate is still `$row['resolved_rate']` (the primary rate); do not change how line-1's main rate is sourced — only the HST and the line-2 rate.

### Step 3 — Add a rurality helper (replaces the price-encoded rurality)

Add a private helper that derives rurality from the client's zone, NOT from the rate value (P3 tells you the exact field):
```php
    /**
     * Whether a client is in a rural SDNB zone (Dorchester, Memramcook,
     * Lakeville, Sussex). Derived from the client's delivery zone/area, NOT
     * from the rate value — the rate must not be the source of truth for
     * rurality (LB-7). Sussex rurality comes from the legacy zone tag.
     */
    private static function client_is_rural(array $client): bool {
        // Implement per P3 findings: e.g. check $client['delivery_area_zone'] /
        // delivery_area_name against the rural set, or a stored rurality flag.
        // Centralise the rural-zone list (consider a constant in
        // MealsDB_Operational_Constants: RURAL_ZONES = ['Dorchester','Memramcook','Lakeville','Sussex']).
    }
```
If a rurality signal is NOT reliably present on the client row, STOP and raise it — the rate flip cannot proceed safely until rurality is sourced independently of price. (This is the one place LB-7 could expand in scope.)

### Step 4 — Delete the obsolete data

- Remove the private `$sdnb_rate_tiers` array (lines 53–66) once both consumers are migrated.
- Remove `HST_MULTIPLIER_PRIMARY_MAIN / RURAL_MAIN / SECONDARY_MAIN` from `MealsDB_Operational_Constants` (lines 131–133) and their explanatory comment block — they encode the obsolete model. Grep first to ensure nothing else references them:
```bash
grep -rn "HST_MULTIPLIER\|sdnb_rate_tiers\|hst_multiplier" includes/ --include=*.php | grep -vi test
```
Migrate or remove any other references.

### Step 5 — (do NOT do) the value flip
Leave `SDNB_RATE_*` constant VALUES unchanged. When SD IT confirms and cutover arrives, the flip is editing the six `SDNB_RATE_*` constants in ONE place (no tier table to keep in sync, no multipliers to recompute). Document this in the constants file comment.

---

## Testing

### Automated
- **Add a money/HST unit test** (there is none today — recon-12.5): for a known taxable-side count and side rate, assert `tax = round(count × side_rate × 0.15, 2)` for both urban and rural, line 1 and line 2.
- **Add a rurality test:** `client_is_rural` returns true for the rural zones and false otherwise, independent of rate.
- **Two-line split tests:** assert line-2 main rate = secondary main rate (urban/rural) and side-only line-2 rate = side rate, sourced from constants.
- **Regression with current values:** with the CURRENT rates ($14.66 etc.), the new HST math will NOT equal the old multiplier output (the old multipliers were a different model). Compute the expected HST under the new rule by hand for a couple of cases and assert those — do NOT assert "matches old multiplier." Document that the tax figures intentionally change to the correct pre-tax-×-15% basis.

> **Flag for the operator/dev:** because the old multipliers (0.672/0.82/0.681) were NOT simply 15%, switching to `side_rate × 0.15` WILL change the HST amounts on SDNB invoices. This is the correction, not a regression — but it should be reviewed against a known-good legacy invoice with the operator before cutover, so everyone agrees the new tax figures are right.

### Manual (dev + operator)
1. Generate an SDNB invoice (urban) on staging with the current rates; verify each taxable-side HST = side_rate × 0.15 and mains carry no HST.
2. Repeat for a rural client; verify rural side rate is used.
3. Verify a two-line invoice: line 1 primary rate, line 2 secondary rate (from constants), HST per line correct.
4. Review the resulting tax totals with the operator against a legacy invoice to confirm the corrected basis is what SD expects.

---

## Out of scope

- **The SDNB rate VALUES** — not changed here (deferred to cutover per the operator).
- **Private/Veteran value changes** — set at cutover; the VAC per-main allowance is pending the operator.
- **VAC billing mechanism** — unchanged (Veterans keep VAC billing; only their pricing changes later). Do not touch `$vac_allowances` logic here beyond the shared HST_RATE constant.
- **The Sussex placeholder address** (`'Sussex Service Center Address'`, line 43) — that's a separate small fix (Q4.6 / use the legacy Sussex zone tag); note it but don't bundle it unless trivial.
- **Per-item taxable tracking** — already correct; don't rebuild it.

---

## Acceptance criteria

- [ ] `$sdnb_rate_tiers` private array deleted; both consumers derive rates from `MealsDB_Operational_Constants`.
- [ ] HST computed as `taxable_sides × side_rate × HST_RATE(0.15)` at both sites; mains never taxed.
- [ ] Obsolete `HST_MULTIPLIER_*` constants removed (after grep confirms no other refs).
- [ ] Rurality derived from the client's zone, NOT from the rate value (`client_is_rural` helper).
- [ ] Two-line split sources line-2 rate from constants; line structure otherwise unchanged.
- [ ] New tests: HST math (urban/rural, line1/line2), rurality, two-line rates. (First dedicated money/tax unit test in the suite.)
- [ ] SDNB rate VALUES unchanged; the eventual flip is a one-place edit of `SDNB_RATE_*`.
- [ ] Operator has reviewed the corrected HST figures against a legacy invoice before cutover.
- [ ] CLAUDE.md Billing section updated (the multiplier-model note and the dual-maintenance note resolved).

---

## Relationship to other directives

- Independent of LB-1/2/3/4/5 (billing-rate code, not allocation/fees plumbing) — can land in parallel.
- This is the highest-care directive of the set because it changes live tax math AND because the rurality-source question (P3) could expand scope. Do P3 FIRST; if rurality isn't independently available, raise it before proceeding.
- The actual rate-value flip is a separate, trivial follow-up gated on SD IT (SDNB) and the operator (VAC allowance) — this directive makes that follow-up safe and one-place.
