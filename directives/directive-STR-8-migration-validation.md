# Directive STR-8 — Pre-cutover migration validation (legacy-key / value fragility)

**Status:** PRE-CUTOVER VALIDATION TASK — mostly NOT a code change. The migration is more defended
than the original finding implied; what remains is a data-verification step that must run against
REAL legacy data before the production migration, plus two small optional code hardenings.
**Severity:** the residual risk is silent MIS-DERIVATION of zone/area (→ wrong billing rate, wrong
delivery day) for clients whose legacy values don't match the migration's hardcoded string maps.
**Verified at:** v1.0.418, `includes/services/class-migration-consolidated.php`.

---

## WHAT'S ALREADY HANDLED (the original STR-8 finding was partly stale — credit these)

1. **Pre-flight encryption check.** Before any PII is processed, the migration does
   `MealsDB_Encryption::encrypt('migration-key-check')` (line ~151) and aborts if encryption isn't
   working — so a misconfigured key fails up front, not silently per row.
2. **Identity-field encryption failure → skip the row, don't insert a NULL-ID client.** The
   individual_id / requisition_id / vet-health-card block (lines ~236-253) catches `\Throwable`,
   increments `errors`, logs the user, and `continue`s — a government client is never silently
   written without their ID.
3. **Medical-data (diet/comments) drop is no longer silent.** On encryption failure (lines
   ~325-342) it stores null AND emits a `degraded` STR-LOG trunk event
   (`encrypt_diet_comments.dropped`) with the user ID — the silent-loss shape the finding worried
   about is now visible/greppable.
4. **Unrecognized `customer_group` → skip + log, not mis-classify.** Lines ~51-59: a group not in
   `$type_map` is skipped with a message naming the user and the bad value.
5. **A `dry_run` mode exists and DEFAULTS to true** (lines ~47-49): it reads and counts exactly
   what a real run would, writing nothing. **This is the validation harness this directive uses.**

---

## THE RESIDUAL RISK (real — silent mis-derivation, not a loud skip)

Some legacy values are mapped through HARDCODED string tables whose miss-case is a silent `null`,
NOT a skip. Unlike `customer_group` (skips loudly), these produce a *successfully-migrated client
with a silently-wrong derived field*:

- **`zone_map = ['Moncton'=>'M','Sussex'=>'S','veterans'=>null]`** (line ~264), keyed on
  `service_centre_charged`. Any other value (new service centre, spelling variant, trailing space)
  → `$zone = ... ?? null`. A null `delivery_area_zone` flows into `is_rural_zone()` (BILLING rate)
  and the zone/area logic (DELIVERY). Silent wrong rate / wrong routing.
- **`delivery_area_name = $meta['billing_address_2'] ?? null`** (line ~268). If the legacy data
  didn't store the delivery area in address-line-2 for some clients, the area is silently null and
  the entire area→day→next_delivery_date cascade has no input. (ITEM1's nightly check would later
  FLAG these as "can't compute" — good — but better to catch at migration.)
- **`req_period` / `period_map`, frequency casts, `commence_date`/`termination` "0"-as-null
  guards** — lower risk (they normalize or null-guard), but same family: a legacy value outside
  the expected vocabulary degrades quietly.

The point: the migration is SAFE against catastrophic failure (it won't insert ID-less clients or
crash), but it can produce clients that *look* migrated while a derived billing/delivery field is
quietly empty or wrong. That's only detectable by comparing against the real legacy data.

---

## THE TASK — validate against REAL legacy data before production cutover

This is the core of STR-8 and it is a PROCESS step, run by the operator/dev together, not a code
change. Do this against a COPY of the production legacy database (or production in a maintenance
window with dry_run), never blind.

### Step 1 — Dry-run the full migration against real legacy data
Run every `run_phase_*` with `dry_run=true` against the real legacy DB. Collect the returned
`['created','skipped','errors']` per phase. **Investigate every `skipped` and every `errors`** —
each one is a client who will NOT migrate, or will migrate with a logged problem. A skip is a
client silently absent from the new system unless someone looks.

### Step 2 — Enumerate the real distinct legacy values for each hardcoded map
Before trusting the string maps, query the legacy DB for the ACTUAL distinct values, and diff
against what the code handles:

```sql
-- customer_group: every value present vs. the 6 the migration accepts.
SELECT DISTINCT meta_value, COUNT(*) FROM wp_usermeta
  WHERE meta_key = 'customer_group' GROUP BY meta_value;
-- Expected handled: sdnb, SDNB, sdnb rural, veterans, Extra Mural, extra mural.
-- ANY other value = a client who will be SKIPPED. Decide: add to map, or intentionally exclude?

-- service_centre_charged: every value vs. the zone_map keys (Moncton, Sussex, veterans).
SELECT DISTINCT meta_value, COUNT(*) FROM wp_usermeta
  WHERE meta_key = 'service_centre_charged' GROUP BY meta_value;
-- ANY other value = a client migrated with delivery_area_zone = NULL (silent wrong rate/route).

-- billing_address_2 presence (the delivery_area_name source) for gov clients.
SELECT COUNT(*) AS missing_area FROM wp_usermeta m
  WHERE m.meta_key = 'customer_group' AND m.meta_value IN ('sdnb','SDNB','sdnb rural','veterans','Extra Mural','extra mural')
  AND NOT EXISTS (SELECT 1 FROM wp_usermeta a WHERE a.user_id = m.user_id AND a.meta_key = 'billing_address_2' AND a.meta_value <> '');
-- >0 = that many clients will migrate with a NULL delivery area (no delivery day derivable).

-- requisition period vocabulary vs. period_map.
SELECT DISTINCT meta_value FROM wp_usermeta WHERE meta_key = 'service';
```
For each map, the diff between "values present in real data" and "values the code handles" is the
exact list of clients who will silently mis-derive. That list IS the deliverable of STR-8.

### Step 3 — Decide each gap (operator)
For every unhandled value found in Step 2: either (a) extend the map in the migration code (e.g. a
new service centre → its zone code), or (b) confirm it's intentionally excluded. Record the
decision. This is where the operator's knowledge of the real client base is irreplaceable.

### Step 4 — Reconcile counts
Total gov-eligible legacy users (Step 2 customer_group counts) should equal
`created + skipped + already-migrated`, with every `skipped`/`error` explained by Steps 2-3. A
client unaccounted for is a client about to be lost. Reconcile to zero-unexplained before the real
run.

---

## OPTIONAL CODE HARDENINGS (small; do if the Step-2 audit shows real gaps)

- **H1 — Surface silent zone/area nulls as a migration warning.** Where `zone` resolves to null
  for a non-veteran client, or `delivery_area_name` is null for a gov client, emit a `degraded`
  trunk event (same pattern as the diet/comments fix) naming the user and the unmatched source
  value. Turns the silent mis-derivation into the same greppable signal the other failure modes
  now have. LOW effort, HIGH value — it's the one place the migration still fails silently.
- **H2 — Dry-run report includes the value-gap summary.** Have the dry-run return (or log) the
  distinct unmatched `service_centre_charged` / `customer_group` / period values it encountered,
  so Step 2's SQL is also produced automatically by the harness. Nice-to-have.

H1 is the only code change I'd actively recommend; it closes the last silent-failure gap. H2 is
convenience. Everything else is verification, not code.

---

## ACCEPTANCE CRITERIA

1. A dry-run of all phases against REAL legacy data is completed; created/skipped/errors per phase
   are recorded and every skip/error is explained.
2. Distinct real legacy values for `customer_group`, `service_centre_charged`, `billing_address_2`
   presence, and the requisition period are enumerated and diffed against the code's maps; every
   gap has an operator decision (extend map vs. intentional exclude).
3. Counts reconcile: gov-eligible legacy users = created + skipped + already-migrated, zero
   unexplained.
4. (If H1 done) silent zone/area nulls now emit a `degraded` trunk event.
5. The production migration is run ONLY after 1-3 pass on a copy.

---

## OUT OF SCOPE

- Rewriting the migration's architecture (the dynamic-SET, chunked, idempotent, dry-run-defaulted
  design is sound — already-good per the audit).
- The address-overwrite-on-differ behavior (line ~798) — a separate, intentional choice (legacy
  usermeta treated as source of truth for addresses); not part of STR-8's silent-derivation risk.
- ITEM1's nightly derived-value check already catches post-migration drift; STR-8 is about getting
  the migration INPUT right so there's less for ITEM1 to flag on day one.
