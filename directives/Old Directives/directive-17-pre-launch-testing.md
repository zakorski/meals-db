# Directive: Pre-Launch Testing Strategy for Shadow-Mode Trial

**Severity:** N/A — process directive, not code
**Audit reference:** `recon-09-synthesis.md` MIG-1 (shadow-mode trial)
**Target file:** New documentation file
**Estimated scope:** ~150-line test plan document
**Risk:** N/A
**Must complete before:** shadow-mode trial begins

---

## Context

The plugin is going into a shadow-mode trial month before cutover. During shadow mode:
- The plugin is installed and active alongside the legacy Enzebra system.
- Both systems generate invoices for the same billing cycle.
- Operators compare outputs.
- No customer touches the new system; no customer notice is sent.

The trial month is the critical validation step. **No static code audit catches what real billing data exposes.** The trial WILL surface issues — the question is whether the dev's process catches them or misses them.

This directive produces a structured test plan: what to compare, how to compare, what to log, and what counts as pass/fail. Without it, the trial month becomes a vague "see what happens" exercise.

---

## Pre-flight verification

### Step P1: Confirm shadow-mode prerequisites

Before the trial can begin, these directives MUST be complete:

- ✅ Directive 01 (HPOS daily report) — operators need real reconciliation signal during the trial.
- ✅ Directive 02 (link client fix) — interactive UI must work.
- ✅ Directive 03 (fee mechanism) — option chosen (recommend Option b, update reports).

Optional but recommended:
- Directive 05 (uninstall crons, cascade no-op, invoice zone).
- Directive 16 Pass A (AJAX handler audit complete).

In your response, document which directives are complete. If any required directive is incomplete, **STOP** and complete it before generating the test plan.

### Step P2: Identify trial scope

Confirm with the dev:
- Which client population is in scope (all active clients, or a subset)?
- Which client types (SDNB, Veteran, private, all)?
- How many billing cycles will the trial cover (typically one full month is the minimum)?
- Who is the operator reviewing the comparisons (Janet, the dev, both)?

---

## The test plan document

### Step F1: Create the test plan file

Create `docs/shadow-mode-trial-plan.md`:

```markdown
# Shadow-Mode Trial Test Plan

**Trial period:** [START DATE] — [END DATE]
**Scope:** [All active clients / Subset]
**Operator reviewer:** [Janet / Dev / Both]

---

## Trial objective

Verify that the new MealsDB plugin generates billing outputs equivalent
to the legacy Enzebra system, within acceptable tolerance, before
flipping the cutover switch (`use_legacy_billing = 0` for production).

**Pass criteria for cutover approval:**
1. For each active client, the new system's monthly invoice total
   matches the legacy system's within ±$0.50.
2. Where differences exceed $0.50, every difference is explained by
   a known operational quirk (the $17K under-billing pattern, fee
   mechanism difference, etc.) — NOT by a bug in either system.
3. The Phase W daily report produces non-trivial reconciliation
   output (proves directive 01 worked).
4. No silent data loss observed (no client missing entirely from
   either system's output).
5. The migration tool ran cleanly with zero unresolved conflicts.

---

## Daily checks during trial

### D1: Phase W daily report

Operator: review the daily email each morning.

What to look for:
- Job status: all 4 jobs (`wp_to_mealsdb_sync`, `nightly_allocation_sync`,
  `task_cron`, `daily_report`) should show "completed" within the last
  24h.
- Anomalies: any hook count >50% deviation from 7-day average. Investigate.
- Reconciliation: any non-zero count in the three reconciliation checks.
  Investigate.

Log any anomaly investigations in `docs/trial-log.md` with date, finding,
and resolution.

### D2: Order error report

Operator: navigate to Meals DB → Reports → Order Errors.

What to look for:
- New entries since yesterday.
- Any error category not previously seen.

Log new error patterns in `docs/trial-log.md`.

### D3: Quick Order usage

If operators are using Quick Order during the trial:
- Check the WC order list for orders created via QO (filter by user
  who created them).
- Spot-check 5 random QO orders per day.
- Verify the order total matches expected (delivery fee + contribution
  applied per the rules).

---

## Weekly checks during trial

### W1: Invoice comparison

For each SDNB and VAC client, generate the invoice on BOTH systems.

Per-client comparison spreadsheet template (`docs/trial-invoice-comparison.xlsx`):

| Client ID | Client name | Legacy mains $ | New mains $ | Legacy sides $ | New sides $ | Legacy contribution $ | New contribution $ | Legacy delivery fee $ | New delivery fee $ | Diff $ | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1234 | <name> | 100.00 | 100.00 | 50.00 | 50.00 | 25.00 | 25.00 | 5.00 | 5.00 | 0.00 | OK |
| ... | ... | ... | ... | ... | ... | ... | ... | ... | ... | ... | ... |

For each client where `|Diff $| > 0.50`:
- Investigate the source of the difference.
- Tag in the Notes column with one of:
  - `KNOWN-UB17K` — the $17K under-billing pattern (delivered overage_main rows that aren't real overage).
  - `FEE-MECH-DIFF` — fee mechanism difference (QO vs legacy product IDs) — should be resolved by directive 03.
  - `INVESTIGATE` — unknown cause; needs root-cause analysis.
- For `INVESTIGATE` items, file as bug or document as expected difference.

**Pass criteria**: by trial end, every row is tagged. No row has an
`INVESTIGATE` tag without root-cause analysis. Total `Diff $` across
all clients is explainable.

### W2: Sync drift check

Operator: navigate to Meals DB → Sync Dashboard → Compare Databases.

What to look for:
- Conflicts list. Should be empty (or only contain known operator-decisions).
- New conflicts since last week. Investigate each.

Log conflicts in `docs/trial-log.md`.

### W3: Allocation engine sanity

For 5 random SDNB clients:
- Open `meals_client_allocations` for the current month.
- Verify `permitted_mains`, `permitted_sides` match the client's
  `allowance_mains`, `allowance_sides` (adjusted for requisition_period
  and effective days).
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
| SDNB Moncton invoiced | $X | $Y | $Z | If \|Z\| < $200, yes |
| SDNB Sussex invoiced | $X | $Y | $Z | If \|Z\| < $200, yes |
| VAC invoiced | $X | $Y | $Z | If \|Z\| < $200, yes |
| Total mains billed | N | M | N-M | If \|diff\| < 5, yes |
| Total sides billed | N | M | N-M | If \|diff\| < 5, yes |

Thresholds are operator's call. Document the chosen thresholds in
`docs/trial-log.md`.

### E2: Operator UX walkthrough

Janet (or the operator) performs each of these workflows on the new
system. Pass if completed without confusion or workaround:

1. Create a Quick Order for a known client. Verify success.
2. Edit an existing client's allowances. Verify the change propagates
   to the next billing cycle.
3. Generate the SDNB Moncton monthly invoice. Verify the output matches
   the legacy system (within trial tolerance).
4. Mark a daily delivery slip as delivered. Verify allocation updates.
5. Resolve a sync conflict via the Sync Dashboard.
6. View the allocation history widget for a client. Verify it shows
   the expected months.
7. Run the Daily Report email manually via Cron Status page. Verify
   email arrives.

Document any operator confusion or "I expected this to work differently"
moments. These are UX gaps even if technically functional.

### E3: Performance check

Measure during peak operation:

| Operation | Time | Acceptable? |
|---|---|---|
| Open Quick Order page | <2s | If <2s, yes |
| Search clients in QO | <500ms | If <500ms, yes |
| Create QO order | <3s | If <3s, yes |
| Open daily slips view | <2s | If <2s, yes |
| Generate SDNB invoice (50 clients) | <30s | If <30s, yes |
| Nightly sync cron | <10 min | If <10 min, yes |

For anything that exceeds the threshold, profile and identify cause.

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
after cutover. Do not uninstall the legacy plugin until 90 days have
elapsed with no rollback needed.
```

### Step F2: Create the supporting documents

Create these files:

1. `docs/trial-log.md` — empty log file the operator fills during the trial.
2. `docs/trial-invoice-comparison.xlsx.template.md` — Markdown describing the spreadsheet schema (the operator creates the actual xlsx).
3. `docs/trial-decision.md` — empty file to be filled at trial end.

Each file should have a header with: title, purpose, who fills it in, who reviews it.

### Step F3: Add a CLAUDE.md cross-reference

Append to CLAUDE.md (or to a `docs/` section if CLAUDE.md is finalized):

```markdown
## Pre-cutover trial documentation

The shadow-mode trial test plan is at `docs/shadow-mode-trial-plan.md`.
The trial log is at `docs/trial-log.md`. Refer to these during the
trial period and the cutover decision.
```

---

## Testing for this directive

This directive produces documents, not code. The "test" is:
- The dev reviews each document for completeness.
- The dev confirms each document captures their intent for the trial.
- The dev agrees that following this plan will produce a confident GO/HOLD/ROLL BACK decision at trial end.

---

## Out of scope for this directive

- Do NOT automate the trial comparison. Operator review is the point.
- Do NOT modify any plugin code. This is documentation only.
- Do NOT define numerical thresholds without dev input. The $0.50 / $200 / etc. numbers in the template are placeholders the dev must confirm.

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight P1 confirms required directives are complete.
2. ✅ `docs/shadow-mode-trial-plan.md` exists with all sections.
3. ✅ Supporting documents are created.
4. ✅ Numeric thresholds are confirmed with the dev.
5. ✅ CLAUDE.md or equivalent points to the plan.
6. ✅ The dev has reviewed and confirmed the plan.

When complete, your final response should include:
- The full text of `docs/shadow-mode-trial-plan.md`.
- The numeric thresholds the dev confirmed.
- A summary of supporting documents created.
