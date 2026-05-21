# Shadow-Mode Trial Test Plan

**Status:** DRAFT. Numeric thresholds marked `[TBD — dev to confirm
with client]` are placeholders pending the client conversation about
acceptable tolerance.

**Trial period:** _[START DATE] — [END DATE]_
**Scope:** _[All active clients / Subset]_
**Operator reviewer:** _[Janet / Dev / Both]_

---

## Trial objective

Verify that the new MealsDB plugin generates billing outputs equivalent
to the legacy Enzebra system, within acceptable tolerance, before
flipping the cutover switch (`use_legacy_billing = 0` for production).

**Pass criteria for cutover approval:**

1. For each active client, the new system's monthly invoice total
   matches the legacy system's within **±$[TBD — dev to confirm
   with client]** per client.
2. Where differences exceed the per-client threshold, every difference
   is explained by a known operational quirk (the $17K under-billing
   pattern, fee mechanism difference, etc.) — NOT by a bug in either
   system.
3. The Phase W daily report produces non-trivial reconciliation output
   (proves directive 01 worked).
4. No silent data loss observed (no client missing entirely from
   either system's output).
5. The migration tool ran cleanly with zero unresolved conflicts.

---

## Daily checks during trial

### D1: Phase W daily report

Operator: review the daily email each morning.

Look for:
- Job status: all 4 monitored jobs (`wp_to_mealsdb_sync`,
  `nightly_allocation_sync`, `task_cron`, `daily_report`) should show
  "completed" within the last 24h.
- Anomalies: any hook count >50% deviation from 7-day average.
  Investigate.
- Reconciliation: any non-zero count in the three reconciliation
  checks (now HPOS-correct per directive 01). Investigate.

Log any anomaly investigations in `docs/trial-log.md` with date,
finding, and resolution.

### D2: Order error report

Operator: navigate to Meals DB → Reports → Order Errors.

Look for:
- New entries since yesterday.
- Any error category not previously seen.

Log new error patterns in `docs/trial-log.md`.

### D3: Quick Order spot-check

If operators are using Quick Order during the trial:
- Filter WC order list to QO-created orders.
- Spot-check **[TBD — dev: typical N=5/day, confirm]** random QO orders
  per day.
- Verify the order total matches expectation (delivery fee +
  contribution applied per the once-per-month rule).

---

## Weekly checks during trial

### W1: Invoice comparison

For each SDNB and VAC client, generate the invoice on BOTH systems.

Per-client spreadsheet template (`docs/trial-invoice-comparison.xlsx`,
schema described in `docs/trial-invoice-comparison.schema.md`):

| Client ID | Client name | Legacy mains $ | New mains $ | Legacy sides $ | New sides $ | Legacy contribution $ | New contribution $ | Legacy delivery fee $ | New delivery fee $ | Diff $ | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1234 | _name_ | 100.00 | 100.00 | 50.00 | 50.00 | 25.00 | 25.00 | 5.00 | 5.00 | 0.00 | OK |

For each client where `|Diff $|` exceeds the per-client threshold:
- Investigate the source of the difference.
- Tag in the Notes column with one of:
  - `KNOWN-UB17K` — the $17K legacy under-billing pattern (delivered
    overage_main rows that were never real overage).
  - `FEE-MECH-DIFF` — fee mechanism difference (QO `WC_Order_Item_Fee`
    vs legacy product 5675/4122 line items). After directive 03 these
    should no longer surface as differences in the reconciliation
    reports; an unexpected `FEE-MECH-DIFF` tag is now a bug.
  - `INVESTIGATE` — unknown cause; needs root-cause analysis.
- For `INVESTIGATE` items, file as bug or document as expected
  difference.

**Pass criteria for W1:** by trial end, every row is tagged. No row
has an `INVESTIGATE` tag without root-cause analysis. Total `Diff $`
across all clients is explainable.

### W2: Sync drift check

Operator: navigate to Meals DB → Sync Dashboard → Compare Databases.

Look for:
- Conflicts list. Should be empty or only contain known
  operator-decisions from the migration.
- New conflicts since last week. Investigate each.

Log conflicts in `docs/trial-log.md`.

### W3: Allocation engine sanity

For **[TBD — dev: typical N=5, confirm]** random SDNB clients:
- Open `meals_client_allocations` for the current month.
- Verify `permitted_mains`, `permitted_sides` match the client's
  `allowance_mains`, `allowance_sides` (adjusted for
  `requisition_period` and effective days).
- Verify `used_mains`, `used_sides` match the actual order line items
  for the month.
- Verify `overage_mains = max(0, used_mains - permitted_mains)`.

Any discrepancy: bug in the allocation engine. Document and escalate.

---

## End-of-trial checks

### E1: Aggregate comparison

For the full trial period:

| Metric | Legacy total | New total | Diff | Acceptable? |
|---|---|---|---|---|
| SDNB Moncton invoiced | _$X_ | _$Y_ | _$Z_ | If `\|Z\|` < $[TBD] |
| SDNB Sussex invoiced | _$X_ | _$Y_ | _$Z_ | If `\|Z\|` < $[TBD] |
| VAC invoiced | _$X_ | _$Y_ | _$Z_ | If `\|Z\|` < $[TBD] |
| Total mains billed | _N_ | _M_ | _N-M_ | If `\|diff\|` < [TBD] |
| Total sides billed | _N_ | _M_ | _N-M_ | If `\|diff\|` < [TBD] |

The aggregate threshold is the dev's call, in consultation with the
client. Document the chosen thresholds in `docs/trial-log.md` once
finalized.

### E2: Operator UX walkthrough

Janet (or the operator) performs each of these workflows on the new
system. Pass if completed without confusion or workaround:

1. Create a Quick Order for a known client. Verify success.
2. Edit an existing client's allowances. Verify the change propagates
   to the next billing cycle.
3. Generate the SDNB Moncton monthly invoice. Verify the output
   matches the legacy system within trial tolerance.
4. Mark a daily delivery slip as delivered. Verify allocation updates.
5. Resolve a sync conflict via the Sync Dashboard.
6. View the allocation history widget on a client edit page. Verify
   it shows the expected months.
7. Run the Daily Report email manually via Cron Status page. Verify
   the email arrives and the reconciliation rows are populated (not
   the broken pre-directive-01 zero-row output).

Document any operator confusion or "I expected this to work
differently" moments. These are UX gaps even if technically
functional.

### E3: Performance check

Measure during peak operation. Acceptable thresholds are the dev's
call; the directive's suggested values are placeholders.

| Operation | Measured time | Threshold (dev TBD) |
|---|---|---|
| Open Quick Order page | _t_ | _[TBD]_ (directive suggested <2s) |
| Search clients in QO | _t_ | _[TBD]_ (directive suggested <500ms) |
| Create QO order | _t_ | _[TBD]_ (directive suggested <3s) |
| Open daily slips view | _t_ | _[TBD]_ (directive suggested <2s) |
| Generate SDNB invoice (50 clients) | _t_ | _[TBD]_ (directive suggested <30s) |
| Nightly sync cron | _t_ | _[TBD]_ (directive suggested <10 min) |

For anything that exceeds the agreed threshold, profile and identify
cause.

---

## Decision matrix at trial end

Based on the trial results, the dev and operator decide:

**GO (proceed to cutover):**
- All pass criteria met.
- All `INVESTIGATE` items resolved.
- Operator confident in the new system.

**HOLD (delay cutover, address findings):**
- Some pass criteria failed.
- Some `INVESTIGATE` items still unresolved.
- Operator needs more confidence-building.

**ROLL BACK (something is fundamentally wrong):**
- Major bug in the new system.
- Data corruption observed.
- Operator unable to use the system.

The decision is documented in `docs/trial-decision.md` with a date,
signature, and rationale.

---

## Cutover plan (only if GO decision is made)

Once GO is decided:

1. Schedule a 30-minute maintenance window.
2. Run a final sync to ensure both systems are aligned.
3. Set `use_legacy_billing = 0` for every active client:
   ```sql
   UPDATE 2xnIt_meals_clients SET use_legacy_billing = 0 WHERE active = 1;
   ```
4. Generate the first real invoice cycle on the new system.
5. Compare to last month's legacy invoice. The ~$17K under-billing
   delta should show up here — it is EXPECTED, not a bug.
6. Brief Janet on the delta before she sees the invoice totals.
7. Send the first new-system invoices to SDNB.
8. Monitor Phase W daily report closely for the next 7 days.
9. Backups: maintain legacy Enzebra DB snapshot for 90 days post-cutover
   in case of rollback need.
10. After parity holds for at least 30 days post-cutover, execute
    directive 12 (drop the deprecated allowance path). Until then,
    the legacy code remains for safety.

---

## Rollback plan

If post-cutover a critical bug is found:

1. Set `use_legacy_billing = 1` for affected clients.
2. Re-generate any erroneously-issued invoices from the legacy system.
3. Contact SDNB if any invoice has already been submitted — issue a
   correction.
4. Document the bug, fix it, re-run shadow-mode for the affected
   client population before re-attempting cutover.

The legacy system stays available as a fallback for at least 90 days
after cutover. Do NOT uninstall the legacy plugin until 90 days have
elapsed with no rollback needed.

---

## Prerequisites that have been completed

Directives shipped in the pre-launch PR (this branch):

- 01 HPOS daily report — operators now get real reconciliation signal.
- 02 link client AJAX — interactive UI no-op fixed.
- 03 fee mechanism reconciliation — both mechanisms surfaced in reports.
- 04 backfill_deterministic_indexes — wrong PK + ciphertext hash fixed.
- 05 uninstall cron, delete_client cascade no-op, invoice zones.
- 06 dead code removal (staff stubs, partial, __() fallback, auto-init).
- 07 STATUS_COUNTED removed.
- 08 operational constants consolidated.
- 09 column-name logging in filter_to_known_columns.
- 10 postal key fix in initials validator.
- 11 inline JS extracted to assets/js/.
- 13 FK constraint metadata removed (PHP-layer enforcement remains).
- 14 nonce consolidation (see commit history; categorization applied).
- 15 client_id / wp_user_id naming in Quick Order AJAX.
- 16 security hardening sweep (audit logging + rate limits on missing
  mutating endpoints).

Directive that runs ONLY after this trial passes:

- 12 drop deprecated allowance path — hard-gated on a successful
  trial and confirmed parity per the decision matrix above.

---

## TBD thresholds — to be filled in by the dev

Track decisions here once the client conversation happens:

- Per-client invoice variance acceptable threshold: `$_____`
- Aggregate SDNB Moncton / Sussex / VAC variance acceptable: `$_____`
- Total mains / sides count variance acceptable: `_____` units
- Performance thresholds (one row per workflow above): _____
- Number of consecutive clean days before GO: _____ days
- Per-day QO spot-check sample size: _____ orders
- Allocation engine spot-check sample size: _____ clients
- Trial duration: _____ days / _____ billing cycles
- Trial scope (full population vs subset): _____
