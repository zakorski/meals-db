# Directive Index — MealsDB v1.0.346 Pre-Cutover Remediation

This index lists all directives produced from the v1.0.346 audit synthesis (`recon-09-synthesis.md`). Each directive is self-contained, step-by-step, and can be handed to Claude Code with no ambiguity.

**Execute in the order listed below.** Some directives have prerequisites — these are noted. Many can be skipped or deferred based on the dev's risk tolerance and pre-cutover timeline.

---

## Execution order

### Phase 1: Critical fixes (must complete before shadow-mode trial)

| # | Directive | Severity | Scope | Risk |
|---|---|---|---|---|
| 01 | [HPOS daily report rewrite](directive-01-hpos-daily-report.md) | CRIT-1 | ~50-80 lines, 1 file | LOW |
| 02 | [Link client AJAX fix](directive-02-link-client-fix.md) | CRIT-2 | ~10 lines, 1 file | VERY LOW |
| 16 (Pass A) | [Security hardening sweep — AJAX audit only](directive-16-security-hardening-sweep.md) | HIGH | Audit + targeted fixes | LOW |
| 17 | [Pre-launch testing strategy](directive-17-pre-launch-testing.md) | N/A | Documentation | N/A |

**Phase 1 gate**: After Phase 1, the dev should be able to run shadow-mode with confidence.

---

### Phase 2: Pre-cutover fixes (must complete before cutover, during shadow-mode is OK)

| # | Directive | Severity | Scope | Risk | Prerequisites |
|---|---|---|---|---|---|
| 03 | [Fee mechanism reconciliation](directive-03-fee-mechanism-reconcile.md) | CRIT-3 | ~150-250 lines, 3-5 files | MEDIUM | Dev decision: Option a or b |
| 04 | [Backfill indexes fix](directive-04-backfill-indexes-fix.md) | CRIT-4 | ~30-40 lines, 1 file | LOW | None |
| 05 | [Major findings batch](directive-05-major-findings-batch.md) | MAJ-2/3/4 | ~30-50 lines, 3 files | LOW | None |
| 10 | [Postal key fix](directive-10-postal-key-fix.md) | STRUCT-7 | ~10-20 lines, 1-2 files | LOW-MEDIUM | None |

**Phase 2 gate**: After Phase 2, all critical and high-priority bugs are addressed. Cutover-eligible.

---

### Phase 3: Hardening and cleanup (v1.1 work, can defer past cutover)

| # | Directive | Severity | Scope | Risk | Prerequisites |
|---|---|---|---|---|---|
| 06 | [Dead code removal batch](directive-06-dead-code-removal.md) | MAJ-1/5, STRUCT-9/10 | ~200 lines removed, 4 files | LOW | None |
| 07 | [STATUS_COUNTED resolution](directive-07-status-counted-resolution.md) | MAJ-6 | ~5-15 lines | LOW | Dev decision |
| 08 | [Operational constants extraction](directive-08-operational-constants.md) | STRUCT-4 | ~80-line new file + refs | MEDIUM | Directive 03 (helpful but not required) |
| 09 | [Column name hardening](directive-09-column-name-hardening.md) | STRUCT-1 | ~40-60 lines, 1 file | LOW | None |
| 11 | [Inline JS extraction](directive-11-inline-js-extraction.md) | STRUCT-8 | ~200 lines moved, 4 files | LOW | None |
| 13 | [FK constraints resolution](directive-13-fk-constraints.md) | STRUCT-3 | ~20-50 lines | LOW (Option A) | Dev decision |
| 14 | [Nonce consolidation](directive-14-nonce-consolidation.md) | STRUCT-5 | ~100-200 lines, 10-15 files | MEDIUM | None |
| 15 | [QO client_id naming](directive-15-qo-client-id-naming.md) | STRUCT-6 | ~30-60 lines, 1 file | LOW | None |
| 16 (Pass B-E) | [Security hardening sweep — remaining passes](directive-16-security-hardening-sweep.md) | HIGH | Variable | LOW | 16 Pass A |

---

### Phase 4: Post-trial cleanup (only after shadow-mode trial validates new system)

| # | Directive | Severity | Scope | Risk | Prerequisites |
|---|---|---|---|---|---|
| 12 | [Drop deprecated allowance path](directive-12-deprecated-allowance-path.md) | STRUCT-2 | ~200-400 lines deleted | MEDIUM | Trial parity confirmed |

**Phase 4 gate**: After Phase 4, the codebase is single-path for SDNB billing. No legacy fallback exists.

---

## How to use these directives with Claude Code

Each directive is self-contained. To execute one:

1. Open a Claude Code session in the plugin repo.
2. Provide the directive file as input. Verbatim text or paste contents.
3. Claude Code executes the Pre-flight verification first. If pre-flight fails, the directive halts.
4. Claude Code executes the The fix steps.
5. Claude Code executes the Testing steps (where automated) and provides Manual test instructions for the dev.
6. Claude Code provides a summary against the Acceptance criteria.

Each directive contains:
- Pre-flight verification — checks before any code changes.
- The fix — exact code changes with line numbers and snippets.
- Testing — automated where possible, manual where required.
- Out of scope — explicit non-goals to prevent scope creep.
- Acceptance criteria — checklist for "this directive is complete."

---

## Decision points across the directive series

These directives REQUIRE dev confirmation before code changes:

| Directive | Decision required |
|---|---|
| 03 | Option (a) make QO use legacy product IDs, or Option (b) update reports to handle both mechanisms. Recommended: (b). |
| 07 | Remove `STATUS_COUNTED` constant, or implement it as a real lifecycle state. Recommended: remove. |
| 10 | Canonical postal key name: `address_postal` (recommended) or `address_postal_code`. |
| 12 | Confirmation that shadow-mode trial established parity. Hard gate. |
| 13 | Option A: remove FK metadata (recommended) or Option B: enable FK constraints. |
| 14 | Categorize each existing nonce context into NONCE_ADMIN, NONCE_DESTRUCTIVE, or kept-separate. |
| 15 | Option I: backward-compat (recommended) or Option II: rename JS-side. |
| 17 | Numeric thresholds for trial pass/fail. |

Resolve each decision before starting the corresponding directive.

---

## What this directive series does NOT cover

The synthesis flagged some items that are out of scope for code remediation:

- **OPS-3 (Apetito vs Appetito spelling)**: requires confirmation from Janet on the canonical spelling. Once confirmed, a small rename directive can be produced.
- **OPS-4 (Bundled vs separately-billed sides)**: this is Phase V's billing-logic question. Requires operator input, not code.
- **PERF-1 (Slow query in `MealsDB_Sync::get_mismatches`)**: deferred per audit. Would require building a denormalized search table — out of scope for v1.1.
- **MIG-1 through MIG-4**: migration-related; covered in the synthesis but the operational steps are the dev's responsibility, not Claude Code's.

---

## Estimated total scope

- **Files modified**: ~30-40 PHP files
- **New files**: 3-5 (operational constants, helper classes, JS extractions, documentation)
- **Files deleted**: 2-3 (dead code removal)
- **Total lines added**: ~800-1200
- **Total lines removed**: ~500-700
- **Net change**: roughly neutral with significantly improved structure and security uniformity

**Resource overhead added**: minimal. The most expensive additions are:
- Phase W observability (already shipped in v1.0.346, not new).
- Column-name logging in `filter_to_known_columns` — adds one log entry per buggy caller per request, deduped. Negligible cost.
- HPOS query rewrites in directive 01 — likely FASTER than the previous (broken) classic-table queries because wc_orders is indexed.

Nothing in this series adds a heavy new background process or doubles existing query loads. The plugin's resource profile remains substantially the same.
