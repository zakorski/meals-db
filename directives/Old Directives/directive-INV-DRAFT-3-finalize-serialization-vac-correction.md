# Directive INV-DRAFT-3 — Finalize serialization per pipeline + VAC billing-model correction

**Status:** ready to implement — BUT read the "Decision gate" first; part of this directive is
blocked on one operator answer.
**Series:** INV-DRAFT (directive 3 of 3 — the capstone)
  1. INV-DRAFT-1 — schema + service ✅ SHIPPED & VERIFIED (PR #390)
  2. INV-DRAFT-2 — review/edit UI + per-field audit ✅ SHIPPED & VERIFIED (PR #391)
  3. **INV-DRAFT-3 — finalize serialization + VAC billing correction** ← *this directive*

**Depends on:** INV-DRAFT-1/2 (service + UI), DEFINITIONS-1 (rate accessor — VAC coverage/side
and SDNB rates read from `MealsDB_Rate_Definitions`). Reuses the existing serializers
(`generate_sdnb_legacy`, `generate_sdnb_new_portal`, `generate_vac_csv`, `generate_vac_pdf`),
LB-3 `finalize_month`, QW-3 `MealsDB_CSV`, LB-7 WC-sourced HST.

**Two distinct goals, kept separate on purpose:**
- **Goal A (mechanism, unblocked):** make `MealsDB_Invoice_Draft::finalize()` produce the REAL
  per-pipeline CSV/PDF from a draft's (possibly-edited) `current` rows, and add the download
  affordance INV-DRAFT-2 withheld.
- **Goal B (correctness, partly blocked):** correct the VAC billing model so the output matches
  how VAC is *actually* invoiced — mains-only, sides folded into the per-main gap — instead of
  the current `mains + separate sides + HST`. This is the highest-stakes change in the whole
  engagement; part of it waits on one operator answer (Decision gate below).

Implement Goal A fully. Implement Goal B's *structure* (make the fold an explicit, editable,
auditable thing on the draft) but DO NOT hardcode a fold *rule* — the operator does the fold by
hand on the draft grid (that is the entire reason the draft layer exists). See "The VAC model."

---

## DECISION GATE (resolve before writing the VAC serializer)

The 27 Jan-2025 VAC reimbursement PDFs established, definitively: **VAC invoices show mains only**
— one "Food and Delivery / of N / Meals" line and a single total, with an "(includes HST)" figure
that is the HST on *folded* sides, never a separate side line. The current `generate_vac_csv`
diverges: it bills `mains_cost + sides_cost + HST` with sides as real billed quantities.

The model is also NOT a clean formula — the invoices show at least three patterns (clean
mains-only; a uniform per-meal bump; mains + a folded amount carrying HST). That variation is
**Janet's hand-work**, which is exactly what the draft review/edit grid now captures. So the code
does NOT need to reproduce her fold rule — it needs to (a) produce a correct mains-only starting
point and (b) let her fold by editing the draft, audited.

**The one thing still genuinely unknown — ask the operator before writing the VAC serializer:**

> When the VAC invoice goes out mains-only, what is the per-main dollar figure on the wire — the
> per-veteran **cost** rate (the ~$9.05→$9.50 in `meals_client_rates`), or the VAC **coverage**
> ceiling ($11.14, the Definitions value)? And is the "(includes HST)" figure something you
> compute by hand (fold), or should the system seed it?

This determines whether the VAC draft row's `bill_rate` seeds from the per-client rate or the
Definitions coverage, and whether the HST-on-folded-sides cell starts at 0 (she fills it) or is
pre-computed. **Until answered, build the VAC serializer to seed the per-main rate from the
per-client `resolved_rate` and the fold/HST cells at 0 (operator fills them on the grid)** — this
is the safe default that matches the *clean* invoices (the majority) and makes every non-clean
case an explicit, audited hand-edit. Flag clearly in code that the seed source is the open
question. If the operator says "seed coverage" or "auto-compute the fold," that's a follow-up
edit to the seed, not a re-architecture.

---

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>

# 1. The serializers and their shapes.
grep -n "function generate_sdnb_legacy\|function generate_sdnb_new_portal\|function generate_vac_csv\|function generate_vac_pdf" includes/services/class-invoice-generator.php
# Note generate_vac_csv builds a 36-col CSV (header ~line 1106); generate_vac_pdf maps those
# columns positionally onto the Blue Cross form. STOP if the VAC CSV header column order changed.

# 2. finalize_month is called INLINE inside the generators (~line 1203 in VAC).
grep -n "finalize_month" includes/services/class-invoice-generator.php
# CRITICAL: the draft finalize ALSO calls finalize_month (INV-DRAFT-1). If the serializer is
# invoked from draft-finalize AND still calls finalize_month itself, the month is finalized
# twice. finalize_month is idempotent (verified, INV-DRAFT-1 T-6) so it's safe — but the
# serializer used by the draft path must NOT re-query/re-derive rows (it must serialize the
# draft's EDITED rows, not freshly-generated ones). See Step 2.

# 3. The draft service finalize() skeleton + DEFINITIONS accessor.
grep -n "function finalize" includes/services/class-invoice-draft.php
grep -n "class MealsDB_Rate_Definitions\|function get(" includes/services/class-rate-definitions.php
# STOP if DEFINITIONS-1 isn't present — VAC coverage/side + SDNB rates must read from it.

# 4. CSV + money helpers the serializers use (must stay intact).
grep -n "class MealsDB_CSV\|function row\|function cell" includes/class-csv.php
grep -n "class MealsDB_Money" includes/class-money.php
```

---

## STEP 1 — The serialization seam: split each generator into "build rows" + "serialize rows"

Each `generate_*` today does **query → assemble rows → serialize**. INV-DRAFT-1 already extracted
the top half (`build_*_draft_rows`). This step extracts the BOTTOM half so the SAME serializer
runs over EITHER freshly-built rows (direct-download path, unchanged) OR a draft's edited `current`
rows (finalize path). Refactor, don't fork — one serializer per pipeline, two callers.

For each pipeline, factor a pure serializer:

```php
// SDNB legacy: rows + invoice context (zone, invoice_number, service_center) → CSV string
private static function serialize_sdnb_legacy(array $rows, array $ctx): string { ... }

// SDNB new portal: rows → CSV string
private static function serialize_sdnb_new_portal(array $rows): string { ... }

// VAC: rows → 36-col data CSV string  (the CSV is stage 1; PDF is stage 2)
private static function serialize_vac_csv(array $rows): string { ... }
```

Then:
- `generate_sdnb_legacy()` = `collect → build → serialize_sdnb_legacy` (output byte-identical to
  today — characterization test below).
- `generate_vac_csv()` = `collect → build → serialize_vac_csv`.
- etc.

**The serializer takes ROWS, not a date.** It must not re-query or re-run the engine — it
serializes exactly the rows handed to it. This is what lets the draft path feed EDITED rows
through it. (The direct-download path hands it freshly-built rows; the draft path hands it
`payload['current']`. Same function, same output rules.)

**`finalize_month` placement:** move the inline `finalize_month` loop OUT of the serializer and
into the *callers*. The direct-download generator finalizes after serializing (as today); the
draft `finalize()` already finalizes (INV-DRAFT-1 Step 6). The serializer itself must be
side-effect-free (pure rows→string) so it can be called from either path without double or
mistimed finalization. (Idempotency makes double-finalize harmless, but a pure serializer is the
correct design and avoids surprise.)

---

## STEP 2 — Wire `MealsDB_Invoice_Draft::finalize()` to the real serializers

Replace the INV-DRAFT-1 placeholder (which returned the raw `current` row map) with real
serialization. `finalize()` already: refuses non-draft → freezes months → sets status. Add: after
the freeze + status transition, serialize `current` by pipeline and return a structured result:

```php
$current = $payload['current'];
switch ($draft['pipeline']) {
    case self::PIPELINE_VAC:
        $csv = MealsDB_Invoice_Generator::serialize_vac_csv($current);
        // VAC stage 2: the PDF. generate_vac_pdf currently consumes the CSV
        // string it just built; refactor it to accept the CSV (or rows) so the
        // draft's EDITED data flows into the PDF, not a fresh generation.
        $pdf = MealsDB_Invoice_Generator::serialize_vac_pdf_from_csv($csv);
        $output = ['csv' => $csv, 'pdf' => $pdf, 'mime' => 'application/pdf'];
        break;
    case self::PIPELINE_SDNB_LEGACY:
        $output = ['csv' => MealsDB_Invoice_Generator::serialize_sdnb_legacy($current, $draft['params'] + [...ctx...]), 'mime' => 'text/csv'];
        break;
    case self::PIPELINE_SDNB_NEW:
        $output = ['csv' => MealsDB_Invoice_Generator::serialize_sdnb_new_portal($current), 'mime' => 'text/csv'];
        break;
}
```

**Persist the finalized output on the draft** so it's downloadable later without re-serializing
(re-serializing could drift if any input changed): add a `finalized_output` LONGTEXT column to
`meals_invoice_drafts` (additive — STR-11), encrypted via `encode_payload` (it contains the same
PII as the rows; the VAC PDF is binary → base64 inside the encrypted blob). Write it in the SAME
guarded UPDATE that sets `status='finalized'`. Then a separate download endpoint (Step 3) reads
and decrypts it. *Rationale: a finalized government invoice is an immutable artifact — capture the
exact bytes at finalize time, don't regenerate on each download.*

**SDNB legacy context:** the legacy CSV needs invoice_number + service_center, derived from the
zone + end date. Those derive deterministically from `params['zone']` + `period_end` already on
the draft — recompute them in finalize (they're not editable, not PII). Confirm the invoice-number
date formatting matches the direct path (site-tz parse — there's an existing LB-era fix for the
midnight-boundary label; reuse it).

---

## STEP 3 — Download endpoint + UI affordance (the part INV-DRAFT-2 withheld)

Add `download_finalized` to `MealsDB_Ajax_Invoice_Draft` (admin-post, not AJAX — it streams a
file): `manage_options` + nonce + rate limit (read bucket is fine here — it's a download of an
already-finalized artifact, not a mutation). Loads the draft, requires `status='finalized'`,
decrypts `finalized_output`, streams it with the right headers (`text/csv` or
`application/pdf` + `Content-Disposition: attachment`). VAC offers both CSV and PDF (a `which`
param).

UI (`class-invoice-draft-page.php`): on a finalized draft's review view, show "Download CSV"
(and, VAC, "Download PDF") linking to the endpoint with the nonce. Remove the INV-DRAFT-2
"downloadable output arrives in a later release" note. The CSV download routes through the same
`MealsDB_CSV` output the direct path uses (QW-3 injection guard intact — do not re-implement).

---

## STEP 4 — The VAC billing-model correction (Goal B)

This is the substance. The VAC row shape and serializer change so the OUTPUT matches reality.

### 4a. The corrected VAC row (what `build_vac_draft_rows` produces, what the grid edits)
Add explicit, editable fields to the VAC draft row so the fold is a first-class, auditable thing
(the key-driven grid renders them automatically — INV-DRAFT-2):
- `bill_mains` (int) — mains billed (seeds from allocated mains).
- `bill_rate` (money) — per-main dollar figure on the wire. **Seeds from the per-client
  `resolved_rate`** (the Decision-gate default; revisit if the operator says "coverage").
- `fold_amount` (money) — the dollar value of sides folded into the gap. **Seeds to 0** — Janet
  enters it per veteran on the grid (this is her hand-work, now captured + audited).
- `fold_hst` (money) — HST on the folded *taxable* sides, the "(includes HST)" figure. **Seeds to
  0** (Decision-gate default; the operator may later ask to auto-seed it from `fold_amount` ×
  WC HST rate — a one-line change to the seed, not a re-architecture).
- Carry the existing identity + allocation fields for context/PDF (health card, name, address,
  allocated mains/sides counts as *display* context).

**Remove sides as a billed quantity from the VAC TOTAL.** The corrected total:
```
vac_total = bill_mains × bill_rate + fold_amount + fold_hst
```
NOT `mains_cost + sides_cost + sides_hst`. Sides are no longer billed to VAC as a line; they live
inside `fold_amount` (the gap) per the real invoices. The allocated side *counts* may still appear
as informational/display columns (the operator may want to see them while deciding the fold), but
they do not drive the total.

### 4b. The corrected VAC CSV/PDF
The 36-column legacy CSV layout maps to a pre-printed Blue Cross form, but the FORM itself
(verified from the 27 PDFs) shows only: K#, name, address, meal count, total, "(includes HST)".
So:
- The **PDF** (`serialize_vac_pdf_from_csv`) must stamp the mains-only reality: the meal line, the
  total (`vac_total`), and the "(includes HST)" cell (`fold_hst`). It must NOT stamp a separate
  side charge. Verify against the 27 reference PDFs: a clean veteran (fold 0) shows total =
  `bill_mains × bill_rate` and HST 0.00; a folded veteran shows the bumped total + the HST figure.
- The **CSV** is the internal data artifact (Janet's records / the stage-1 file). It MAY keep
  richer columns (allocated side counts for her reference) but its `New Total` column must be the
  corrected `vac_total`, and a `Bill HST` column = `fold_hst`. Do not keep a `Sides Cost` column
  that feeds the total — if retained for reference, it must be clearly informational and NOT
  summed into `New Total`.

### 4c. Rates from DEFINITIONS, not constants
- `bill_rate` seed: per-client `resolved_rate` (Decision-gate default).
- The VAC *coverage* ($11.14) and VAC *side* price, where referenced, read from
  `MealsDB_Rate_Definitions::get('vac_per_main_coverage' / 'vac_side')` — NOT the constants.
- Retire the dead `VAC_SIDES_CONVERSION_RATE` and the informational
  `monthly_allowance`/`allowance_remaining` columns IF they no longer reflect the real invoice
  (they were "not used in billing decisions anymore" even pre-rework). Removing them from the CSV
  is fine; if kept, they must be sourced from Definitions coverage, not the dead constant, and
  clearly informational.

### 4d. HST stays WC-sourced (LB-7)
`fold_hst`, where the system seeds or validates it, uses the LB-7 WC-sourced rate
(`resolve_hst_rate()`), NOT a constant. Do not reintroduce a VAC HST constant. (The legacy
`VAC_SIDES_HST_RATE` 0.15 is replaced by the live WC rate, consistent with LB-7's SDNB treatment.)

---

## STEP 5 — SDNB serializers (Goal A only — no model change)

SDNB legacy + new portal serialization is a straight extraction (Step 1) + wiring (Step 2). **No
billing-model change** — SDNB bills as it does today; the draft layer just lets Janet review/edit
before finalize. The only correctness note carried from LB-7: the new-portal generator reads the
WC-sourced HST and the urban/rural side rate via `delivery_area_zone` — that logic lives in
`get_phase2_billing_data`, which ran at *build* time, so the resolved figures are already in the
draft rows. Serialization just formats them. Confirm the rural side-rate (LB-7) survives into the
draft row and out through the serializer unchanged (characterization test).

---

## TESTS

Extend `tests/test-invoice-draft.php` + add `tests/test-invoice-serialize.php`:

**Goal A — serialization parity (characterization):**
- **T-A1 SDNB legacy parity:** build rows → `serialize_sdnb_legacy` produces byte-identical CSV to
  `generate_sdnb_legacy` for the same period+zone with NO edits. (The refactor must be
  output-preserving.)
- **T-A2 SDNB new-portal parity:** same, byte-identical.
- **T-A3 VAC CSV — clean veteran:** a veteran with fold 0 → `New Total` = `bill_mains × bill_rate`,
  `Bill HST` = 0.00, NO separate side charge summed into the total. (Matches the 0.00-HST invoices
  in the reference set.)
- **T-A4 finalize produces + persists output:** finalize a VAC draft → `finalized_output`
  populated (encrypted), status finalized, download endpoint streams it.
- **T-A5 download requires finalized + caps:** download on a `draft`-status draft → refused;
  finalized → streams with correct mime + Content-Disposition.

**Goal B — VAC model correction:**
- **T-B1 sides NOT in the VAC total:** a veteran WITH allocated sides but fold 0 → `vac_total`
  does NOT include any side cost (proves sides were removed from the billed total). Contrast with
  the OLD behavior (sides summed) to make the correction explicit.
- **T-B2 fold flows to total + HST cell:** set `fold_amount` and `fold_hst` via an edit →
  `vac_total` = `mains×rate + fold_amount + fold_hst`; the PDF "(includes HST)" cell = `fold_hst`.
- **T-B3 fold edit is audited:** editing `fold_amount` on a VAC draft writes a
  `invoice_draft_edit` audit row (rides INV-DRAFT-2's path — confirm the new fields audit like any
  other).
- **T-B4 rates from Definitions:** override `vac_per_main_coverage` via DEFINITIONS → the VAC
  serializer/seed reflects it (where coverage is referenced); SDNB side rate override flows
  through too.
- **T-B5 reference-invoice characterization:** reconstruct 2–3 of the 27 Jan-2025 veterans
  (a clean one: total = mains×rate, HST 0.00; a folded one: bumped total + non-zero HST) and
  assert the serializer reproduces those totals from the corrected row + the operator's fold
  values. *This is the test that proves the output matches what VAC actually receives.*

Run the FULL suite (expect 65 + new files). mbstring/gd required for the VAC PDF tests (CI note).

**Acceptance — operator sign-off gate:** before this is "done," the operator reviews a VAC PDF
generated from a real (or reconstructed) month against a known-good Blue Cross invoice and
confirms the mains-only output + the "(includes HST)" handling match. This is the same human
verification step flagged for LB-7 — the code can be green and still need eyes on the artifact,
because it's federal billing.

---

## ACCEPTANCE CRITERIA

1. Each pipeline split into pure `serialize_*` (rows→output) + caller; direct-download output
   byte-identical to today (characterization T-A1/A2 green).
2. `finalize()` serializes `current` per pipeline, persists encrypted `finalized_output`, returns
   structured output; download endpoint streams it (manage_options + nonce + rate limit).
3. VAC total corrected to `bill_mains × bill_rate + fold_amount + fold_hst`; sides NOT summed into
   the VAC total; PDF shows mains-only + "(includes HST)" = `fold_hst` (T-B1/B2/B5 green).
4. `fold_amount`/`fold_hst`/`bill_rate` are editable draft fields, audited like any other edit.
5. VAC coverage/side + SDNB rates read from `MealsDB_Rate_Definitions`; HST stays WC-sourced
   (LB-7); dead VAC constants retired or demoted to informational-from-Definitions.
6. SDNB billing model UNCHANGED (Goal A only); rural side rate (LB-7) survives into serialized
   output.
7. Full suite green; operator sign-off on a VAC artifact obtained before cutover.

---

## OUT OF SCOPE / DEFERRED

- **Auto-computing Janet's fold** — explicitly NOT done. The fold is hand-entered on the draft
  grid (the reason the draft layer exists). If the operator later wants the system to *seed* a
  fold suggestion, that's a future seed-value change, not a rule engine.
- **Effective-dated rates** — DEFINITIONS-1 deferred it; unchanged here.
- **The Decision-gate answer** (cost-rate vs coverage on the wire; auto-seed HST) — build to the
  safe default (per-client rate, fold/HST seed 0), flagged in code; adjust the seed when answered.
- **SDNB billing-model changes** — none. SDNB is mechanism-only in this series.

---

## NOTES FOR THE IMPLEMENTER

- The single most important property: **the serializer is pure (rows→string), called from both
  the direct path and the draft-finalize path.** If you find yourself re-querying inside the
  serializer, stop — that defeats the entire draft-edit purpose (it would serialize fresh data,
  not Janet's edits).
- The VAC correction is the highest-stakes change in this codebase. The characterization test
  against the real 27-invoice numbers (T-B5) is not optional — it's the proof the new output
  matches what VAC actually receives. Reconstruct at least one clean and one folded veteran.
- Capture finalized bytes at finalize time (`finalized_output`), don't regenerate on download — a
  finalized federal invoice must be the exact artifact that was finalized, immutable.
- Do not reintroduce any HST constant. LB-7 is load-bearing; the VAC HST is the WC-sourced rate.
