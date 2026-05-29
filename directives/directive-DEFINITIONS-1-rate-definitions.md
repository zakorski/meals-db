# Directive DEFINITIONS-1 — Operator-editable billing-rate definitions

**Status:** scoping + implementation directive
**Relationship to other work:** feeds INV-DRAFT-3 (the invoice serializers will read rates from
Definitions instead of constants) and the VAC rework. Consciously supersedes the LB-7
"constants are the single source of truth" decision — *for program-wide rates only* (see the
boundary section). Reuses the STR-LOG audit-log boundary, the admin/AJAX security stack, and the
`manage_options` discipline from the Event Log / Invoice Draft pages.

**One-line goal:** give the operator a `manage_options`, audit-logged admin page to edit the
program-wide billing *rates* (SDNB per-region main/side, VAC per-main coverage + side, Janet's
private/veteran prices) that today are hardcoded constants — so an annual rate change is a form
edit, not a code deploy — WITHOUT pulling the per-client rate table, WooCommerce product wiring,
or the LB-7 WC-sourced HST into the page.

---

## THE BOUNDARY (read this before designing anything)

There are **two pre-existing rate systems** plus several non-rate constants. Definitions touches
exactly one of them. Getting this boundary wrong duplicates an existing system or wrongly
centralizes config — so it is stated first and is non-negotiable.

### A. STAYS as-is — the per-client rate table (`meals_client_rates`)
`MealsDB_WC_Order_Query::resolve_rate_for_order($rate_id, $client_id)` reads a client's
`default_rate_id` → their contracted per-main rate. This is per-client contract data (e.g. each
veteran's $9.05/main from the Jan-2025 invoice analysis). **Definitions does NOT replace, wrap,
or shadow this.** A program-wide default is not a per-client contract; conflating them rebuilds
the per-client system badly. If a client has a `default_rate_id`, that wins — Definitions
supplies the *program default* used where there is no per-client override, and the
program-wide rates the SDNB generator currently reads from constants.

### B. MOVES to Definitions — the program-wide rate constants
From `MealsDB_Operational_Constants` (verified line refs):
- `SDNB_RATE_PRIMARY_MAIN` (14.66), `SDNB_RATE_PRIMARY_MAIN_RURAL` (15.47)
- `SDNB_RATE_SECONDARY_MAIN` (10.18), `SDNB_RATE_SECONDARY_MAIN_RURAL` (10.93)
- `SDNB_RATE_SIDE` (4.48), `SDNB_RATE_SIDE_RURAL` (4.54)
- `VAC_PER_MAIN_ALLOWANCE` (10.64) — **becomes the VAC per-main coverage** (the $11.14
  annually-changing number; see VAC note below)
- `VAC_RATE_SIDE` (4.10)
- (`VAC_SIDES_CONVERSION_RATE` 4.715 — see "dead constants" below; likely retired, not moved)
- **NEW (Janet's prices, not currently constants):** private main (9.50), private side (4.25),
  main+side combo (13.75). These don't exist in the code yet — Definitions is where they're
  born. (Veteran prices = private prices per the operator; model them as the same values, not a
  separate set, unless the operator says otherwise.)

### C. STAYS a constant — NOT rates, do not move
- `PRODUCT_ID_*` (client_contribution 5675, delivery_fee 4122, overage products) — WooCommerce
  wiring. Tamper-evident on purpose; changes only on a WC reconfiguration, not a business cadence.
- `CATEGORY_ID_*` (mains 35, soup 43, muffins 37, cereal 23, dessert 25) — taxonomy.
- `SDNB_RURAL_ZONE_CODES` (['S']), `APETITO_CASES_PER_PALLET` (75) — config.
- **The HST rate** — sourced LIVE from WooCommerce (LB-7). **Definitions MUST NOT reintroduce an
  HST constant or field.** This is the explicit anti-goal: LB-7 removed the HST multiplier model;
  Definitions does not walk it back. (VAC's `VAC_SIDES_HST_RATE` is a separate question — see the
  VAC note; default is to leave VAC HST sourcing exactly as the VAC rework decides, NOT to add an
  HST knob to this page.)

**Litmus test for "does field X belong on Definitions?":** *Is it a dollar rate/price that
changes on a business cadence (annually, at contract renewal, at a pricing decision)?* → yes.
*Is it an ID, a category, a structural code, or the tax rate?* → no.

---

## VAC note (why $11.14 drives this whole page)

The operator's VAC model: VAC pays a fixed per-main **coverage** ($11.14 this year), which
**increases annually**, and was not maintained in the old constant (10.64). That annual cadence
is the single strongest argument for Definitions: a yearly coverage bump should be a form edit by
Janet, not a developer deploy. The VAC per-main coverage is therefore the flagship Definitions
value. Note this is the *coverage ceiling*, distinct from the per-veteran *cost* rate in
`meals_client_rates` (the $9.05/$9.50) — two different numbers, two different homes (coverage →
Definitions program rate; cost → per-client table). The VAC rework (INV-DRAFT-3) consumes both;
Definitions only owns the coverage.

---

## STORAGE DESIGN — option, with a recommendation

Two viable homes for the editable values:

**Option 1 — a `wp_options` row (recommended for v1).** A single option
`mealsdb_rate_definitions` holding a versioned associative array of the rates. Pros: no schema,
trivially additive, matches how the plugin already stores operator config
(`mealsdb_zone_delivery_schedule`, `mealsdb_overage_product_ids`, the digest options). Cons: no
built-in history (the audit log supplies that), no per-row effective-dating.

**Option 2 — a `meals_rate_definitions` table.** Pros: natural home for eventual
**effective-dating** (the annual VAC bump is the exact case where "this rate, effective
YYYY-MM-DD" matters — formerly STR-5). Cons: more machinery than v1 needs.

**Recommendation: Option 1 now, designed so Option 2 is a clean migration later.** Store the
option as `{ 'schema': 1, 'rates': { ...key => value... } }`. When effective-dating is genuinely
needed, the option becomes the "current effective set" and a table holds dated history — the
read API (below) is the seam that makes that swap invisible to callers. Do **not** build
effective-dating in v1 (the operator said date-effective pricing isn't needed yet — STR-5), but
do not paint the corner: route every read through one accessor so adding dating later touches one
file.

**Whichever option: the values are seeded from the current constants.** On first load (no option
yet), the accessor returns the constant values. So the page ships pre-populated with today's
numbers, and the constants become the *seed/fallback* — not a second source of truth. This is the
LB-7 supersession done cleanly: one source (the option), with the constants as documented
defaults, NOT constants-AND-option both live (that's the dual-maintenance trap STR-LOG taught us
to avoid).

---

## THE READ ACCESSOR (the most important piece)

A single accessor every consumer calls — `MealsDB_Rate_Definitions::get($key, $context = [])` —
is what makes the constant→option supersession safe and the future effective-dating invisible.

```php
class MealsDB_Rate_Definitions {

    public const OPTION = 'mealsdb_rate_definitions';

    /** Canonical key list + their seed (constant) defaults. The ONLY place the
     *  rate vocabulary is defined. */
    private static function defaults(): array {
        return [
            'sdnb_primary_main'         => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN,
            'sdnb_primary_main_rural'   => MealsDB_Operational_Constants::SDNB_RATE_PRIMARY_MAIN_RURAL,
            'sdnb_secondary_main'       => MealsDB_Operational_Constants::SDNB_RATE_SECONDARY_MAIN,
            'sdnb_secondary_main_rural' => MealsDB_Operational_Constants::SDNB_RATE_SECONDARY_MAIN_RURAL,
            'sdnb_side'                 => MealsDB_Operational_Constants::SDNB_RATE_SIDE,
            'sdnb_side_rural'           => MealsDB_Operational_Constants::SDNB_RATE_SIDE_RURAL,
            'vac_per_main_coverage'     => MealsDB_Operational_Constants::VAC_PER_MAIN_ALLOWANCE, // 10.64 → edit to 11.14
            'vac_side'                  => MealsDB_Operational_Constants::VAC_RATE_SIDE,
            'private_main'              => 9.50,   // NEW — born here
            'private_side'              => 4.25,   // NEW
            'private_combo'             => 13.75,  // NEW
        ];
    }

    /** Read one rate. Option value wins; else the constant seed. Never throws. */
    public static function get(string $key) {
        $defaults = self::defaults();
        if (!array_key_exists($key, $defaults)) {
            return null; // unknown key — caller bug; do not invent a value
        }
        $stored = get_option(self::OPTION, []);
        $rates  = (is_array($stored) && isset($stored['rates']) && is_array($stored['rates'])) ? $stored['rates'] : [];
        return array_key_exists($key, $rates) && is_numeric($rates[$key])
            ? (float) $rates[$key]
            : (float) $defaults[$key];
    }

    /** All current effective rates (for the edit form + for callers that need the set). */
    public static function all(): array { /* defaults merged with stored overrides */ }

    /** Persist edited rates (called only by the audited admin endpoint). */
    public static function save(array $rates): bool { /* validate numeric/non-negative; write option */ }
}
```

**Then migrate the consumers** (the point of the whole exercise):
- `class-operational-constants.php::get_sdnb_main_rate()` / `get_sdnb_side_rate()` → return
  `MealsDB_Rate_Definitions::get('sdnb_...')` instead of the raw constants. Keep the method
  signatures (callers in the generator at lines 246/317/354 don't change — facade discipline).
- The VAC `$vac_billing` array (generator lines 64–67) → source `vac_per_main_coverage` and
  `vac_side` from the accessor. (The exact VAC consumption is finalized in INV-DRAFT-3; this
  directive just makes the values *available* via the accessor — INV-DRAFT-3 wires them in.)
- Keep the constants in the file as the seed defaults the accessor reads. Add a doc comment on
  each that it is now a SEED for Definitions, not the live value, so a future dev doesn't "fix" a
  rate by editing the constant and wonder why nothing changes.

---

## THE ADMIN PAGE + ENDPOINT

Mirror the Invoice Draft / Event Log pages exactly (it's the established pattern):

- **Page** `MealsDB_Rate_Definitions_Page`, submenu, `manage_options`. Renders a grouped form:
  *SDNB rates* (urban/rural × primary/secondary main, side), *VAC* (per-main coverage, side),
  *Private/Veteran prices* (main, side, combo). Each field pre-filled from
  `MealsDB_Rate_Definitions::all()`, with the seed/default shown as a hint where the current value
  differs from the seed (same "was:"/"default:" affordance as the draft grid). Server-rendered,
  every value `esc_attr`'d.
- **Save endpoint** (AJAX or admin-post, matching the sibling pages): `manage_options` + nonce +
  rate limit (`settings_modify` bucket — this is exactly what it's for) + **server-side
  validation** (each value numeric, non-negative, within a sane ceiling — a rate is dollars, so
  reject a fat-fingered 1114 where 11.14 was meant; the same MAX_DOLLARS discipline as
  INV-DRAFT-2).
- **Confirmation friction.** These values bill two governments. The save should require an
  explicit confirm step (a "these rates take effect immediately for new invoices — confirm"
  interstitial or checkbox), matching the destructive-operation friction elsewhere. A fat-fingered
  rate is a wrong government invoice.
- **Audit every change, per field** (STR-LOG boundary — a committed change to billing-determining
  data → `meals_audit_log`, NOT the operational trunk): for each rate whose value actually
  changes, `MealsDB_Logger::log('rate_definition_edit', 0, $key, $old, $new)`. This is the "who
  changed the VAC coverage from 10.64 to 11.14, when" record. Per-field, change-only (no row for
  an unchanged field), exactly like the draft edits. Rate values are not PII, so no redaction
  concern here — but route through the same `log()` for consistency.

---

## THE DRAFT-IMMUTABILITY INTERACTION (important, easy to miss)

Invoice drafts store **resolved** rate values in their payload at generation time (INV-DRAFT-1).
So changing a Definitions rate **does NOT** retro-alter an existing draft — a draft generated
last week keeps the rates it was generated with; only a *newly generated* draft picks up the new
rate. **This is correct and desirable for government billing** (an in-flight invoice shouldn't
silently change because someone edited a rate), and it's worth stating in the page UI: "changes
apply to newly generated drafts; existing drafts keep the rates they were generated with." It also
means Definitions and the draft layer compose cleanly with no special-casing — the draft's
self-containment (INV-DRAFT-1 Step 3) already gives us point-in-time rate capture for free.

---

## TESTS (`tests/test-rate-definitions.php`)

- **T-1 accessor falls back to seed:** with no option set, `get('vac_per_main_coverage')` returns
  the constant (10.64); `all()` returns the full seed set.
- **T-2 option overrides seed:** after `save(['vac_per_main_coverage' => 11.14])`, `get()` returns
  11.14; un-set keys still return their seeds.
- **T-3 unknown key:** `get('not_a_rate')` returns null (doesn't invent a value).
- **T-4 save validation:** negative / non-numeric / over-ceiling rejected; valid set persists.
- **T-5 consumer migration:** `MealsDB_Operational_Constants::get_sdnb_side_rate(false)` returns
  the Definitions value after an override (proves the generator now reads through the accessor).
- **T-6 audit per change:** saving with one changed + one unchanged rate writes exactly one
  `rate_definition_edit` audit row (old→new for the changed key only).
- **T-7 endpoint security:** save rejected on bad nonce / missing `manage_options` / rate limit,
  before any option write or audit row.
- **T-8 draft point-in-time:** generate a draft, change a Definitions rate, confirm the existing
  draft's stored payload rate is unchanged (the immutability interaction above). *(May live in the
  draft test file instead; place wherever the fixtures are cleanest.)*

Run new test + FULL suite (expect 65 + this; mbstring/gd for PDF tests).

---

## ACCEPTANCE CRITERIA

1. `MealsDB_Rate_Definitions` accessor: `get/all/save`, option-wins-else-seed, never throws,
   unknown key → null.
2. SDNB rate methods on `Operational_Constants` now read through the accessor; signatures
   unchanged; generator call sites untouched.
3. Constants remain as documented SEED defaults (commented as seeds, not live values).
4. Admin page (`manage_options`) renders a grouped, pre-filled, escaped form with seed hints +
   the "applies to new drafts" note.
5. Save endpoint: capability + nonce + rate limit + numeric/non-negative/ceiling validation +
   confirmation friction; fail-safe.
6. Every actual rate change writes one per-field `rate_definition_edit` audit row (old→new);
   no row for unchanged fields.
7. NO HST field/constant introduced (LB-7 preserved). Per-client `meals_client_rates` untouched.
   Product/category IDs untouched.
8. New test green; full suite green.

---

## OUT OF SCOPE (deferred)

- **Effective-dating** (dated rate history). Designed-for via the single accessor seam + the
  storage note, but NOT built (STR-5 / operator said not needed yet). The accessor is the seam
  that makes it a one-file change later.
- **Wiring VAC consumption** of `vac_per_main_coverage` into the actual fold/billing math →
  INV-DRAFT-3 / the VAC rework. Definitions only makes the value *available* via the accessor.
- **Retiring the dead VAC constants** (`VAC_SIDES_CONVERSION_RATE`, and deciding the fate of
  `VAC_PER_MAIN_ALLOWANCE`'s old "allowance" semantics) → the VAC rework owns that; Definitions
  simply repurposes the per-main value as "coverage."
- **Per-client rate editing** — that's the `meals_client_rates` table and the client form's
  domain, not this page.

---

## NOTES FOR THE IMPLEMENTER

- The accessor is the whole game. If every consumer reads `MealsDB_Rate_Definitions::get()` and
  the page only ever writes the option, then the constant→option supersession is clean and the
  future effective-dating swap touches one file. Resist letting any consumer read the constant
  directly after this lands.
- Keep the seed defaults literally equal to today's constants so the page ships showing the exact
  current numbers — no behavior change on install, only the *ability* to change them.
- The "$11.14" is NOT hardcoded anywhere by this directive — the seed stays 10.64 (today's
  constant) and Janet edits it to 11.14 on the page at cutover. That edit is the first audited
  `rate_definition_edit`, which is exactly the record you want of the coverage change.
