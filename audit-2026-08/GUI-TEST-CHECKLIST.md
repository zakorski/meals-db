# Browser / GUI test checklist — 2026-08 session

Manual verification for everything changed in this session. The automated PHP
suite already covers the logic; this list targets what only a real browser +
live WP/WC/HPOS + MySQL 8 can prove — rendering, AJAX round-trips, dompdf/Imagick
(mbstring), and concurrency.

**Environment:** run on staging (or a safe copy) with a logged-in admin
(`manage_options`). A few checks need two browser sessions (or an incognito
window) to exercise concurrency. PDF/print paths only work where mbstring +
Imagick are installed (they fail in local CLI by design — see the two skipped
PDF unit tests).

Legend: **[C]** = correctness-sensitive (a failure means wrong data/money/access);
**[U]** = UI/cosmetic (a failure is visible but not dangerous).

---

## 1. Settings page — `Meals DB → Settings`  (PR #506, settings.js `runTool`)

The whole page was refactored to a shared `runTool()` helper. Click **every**
action button and confirm the running → success/error line and button
re-enable all still work. This is the highest-value smoke test in the list.

- [ ] **[U]** **Generate Encryption Key** — fills the key field + shows the key-warning banner.
- [ ] **[C]** **Resync Delivery Days** — shows "N updated, M already correct, K orphan(s)."; when there are orphans, the orphan `<ul>` renders with `#id Name — zone: …` lines (green when 0 orphans, amber when >0).
- [ ] **[U]** **Backfill next dates** — "Processed N clients: … order dates, … delivery dates (… skipped)." in green.
- [ ] **[C]** **Recalculate Allocations** — "Rebuilt N client-months" (amber + "(K with spillover errors)" when errors > 0).
- [ ] **[C]** **Private backfill — Preview** — result count green; when rows exist, the WP User/Name/Email/Orders table renders **and** the **Run** button enables. With zero rows, Run stays disabled.
- [ ] **[C]** **Private backfill — Run** — confirm dialog appears; on OK, "Promoted N of M (errors…, skipped…)" and the preview table clears.
- [ ] **[C]** **Private deactivation — Preview** — count + Client ID/WP User/Name table; Run enables only with rows.
- [ ] **[C]** **Private deactivation — Run** — confirm dialog; "Deactivated N of M (errors…)"; table clears.
- [ ] **[U]** **Enrich skeletons — Dry Run** — no confirm; "Dry run: would enrich N of M (…)". Both enrich buttons disable during, re-enable after.
- [ ] **[C]** **Enrich skeletons — Run (live)** — confirm dialog appears; "Enriched N of M (…)".
- [ ] **[U]** **Sync Products** — "Synced N products…" green (this endpoint returns a FLAT `message`; the divergence was preserved — verify the real message shows, not a generic "Done.").
- [ ] **[U]** **Case Count Sync** — success message shows (also FLAT-response — same check).
- [ ] **[C]** **Save Settings** (form submit) — "Settings saved."; re-open and confirm the zone schedule, shadow_mode, show_advanced_tools, overage product IDs, and the **derived-autocorrect checkboxes** all persisted (the checkbox-persistence fix).
- [ ] **[U]** Trigger a failure (e.g. temporarily wrong nonce, or disable network) on any one button → "Request failed." in red and the button re-enables.

---

## 2. Weekly Order Audit  (PR #502, order-audit TOCTOU)

- [ ] **[C]** Generate/open a week's audit; confirm rows render, counts (confirmed/edited) update as you **Confirm** / **Edit** / **Revert** rows.
- [ ] **[C]** Confirm-then-confirm toggles a row back to pending; Edit stores per-item quantities + note; Confirm over an edited row clears the edit.
- [ ] **[C]** **Finalize** is refused while any row is pending; succeeds once all rows are confirmed/edited; the audit then renders read-only.
- [ ] **[C]** **Unfinalize** requires a typed reason; blank reason is refused; on success the row states are intact.
- [ ] **[C]** **Delete** is offered for a draft, refused for a finalized audit.
- [ ] **[C] Concurrency (the actual fix):** open the SAME audit in two tabs.
  - Tab A finalize → succeeds. Tab B (still showing draft) tries to Confirm/Edit a row → should get a clean **"This audit changed in another window; reload and try again."** conflict, NOT a silent success on the now-finalized record.
  - Tab A finalize → Tab B finalize → second one returns the same conflict, not a double-success.
  - Tab A unfinalize → Tab B unfinalize → conflict on the loser.

---

## 3. Add/Edit Client form  (PRs #503 phone, #508 initials)

- [ ] **[C]** **Pull Data** from a linked WP user — phone fields come back formatted `(###)-###-####`; a 10-digit or `+1…`/`1…` 11-digit source normalises; a too-short/too-long number comes back as the trimmed original so `validate()` flags it (not silently reshaped).
- [ ] **[C]** **Generate Initials** — produces a 3-letter code not already in use.
- [ ] **[C]** **Validate Initials** — type an existing code → "already in use"; a banned/profane code → "not allowed"; a non-3-letter code → format error; a free code → "available."
- [ ] **[C]** Edit an existing client and re-save with its OWN initials unchanged → accepted (self-exclusion works — the delegated `initials_exist` exclusion path).
- [ ] **[C]** Save a client with a valid phone + initials → persists; re-open and confirm the stored values.

---

## 4. Private customer intake  (PRs #503 phone, #508 initials)

- [ ] **[C]** Run a private-intake promotion (via the Settings backfill, section 1) and spot-check a promoted client: phone stored in form-valid shape, a unique initials/nickname assigned, no duplicate-initials collision.

---

## 5. Data-Ops → Schema Changes tool  (H7 ALTER feature — pre-compaction)

This shipped earlier in the session and has **never run against real MySQL** —
this is the most important pre-production check besides Settings.

- [ ] **[C]** Open **Data-Ops → Schema Changes**. With the schema current, it reports no pending changes.
- [ ] **[C]** Introduce a SAFE drift (bump a `VARCHAR` width in the canonical schema + version) and load an admin page → it auto-applies on the version-bump path; the tool shows it applied (online DDL, no lock).
- [ ] **[C]** Introduce a RISKY drift (narrow a column, or any DECIMAL/money change) → it is **surfaced, not auto-applied**; the tool shows the exact `ALTER`, a pre-flight row-count check, and requires the typed **`ALTER`** confirmation. A change that would lose data is **blocked** by the pre-flight.
- [ ] **[C]** Confirm a COPY-algorithm change engages maintenance mode, and an INPLACE one does not.
- [ ] Run `tests/test-schema-alter-integration.php` via `wp eval-file` on staging (the integration path that can't run in local CLI).

---

## 6. Invoice drafts & generation  (PR #507 headers, #504 site_tz, money/HST/LB-7 pre-compaction)

- [ ] **[U]** `Meals DB → Invoice Drafts` list — the header row renders correctly (Pipeline / Period / Month / Status / Rows / Edits / Created by / Created (UTC) / Finalized by / Finalized (UTC) / actions). English text unchanged.
- [ ] **[C]** Generate an SDNB draft — period window is correct across the month boundary (site_tz), HST is computed as `taxable_sides × pre-tax side rate × WC rate (15%)`, mains never taxed, urban vs rural side rate resolves from `delivery_area_zone`. **Compare against a known-good legacy invoice with the operator** (the corrected HST differs from the old baked-in multipliers — expected).
- [ ] **[C]** Generate a VAC draft/PDF — mains-only total, folded sides + fold HST as expected; the PDF renders (needs Imagick/mbstring on staging).
- [ ] **[C]** Spot-check money: a negative/fractional amount rounds correctly (half-up) and a negative value exports to CSV intact as `-10.24`, not `'-10.24`.

---

## 7. Event Log dashboard  (PR #507 headers, sensitive-keys pre-compaction)

- [ ] **[U]** `Meals DB → Event Log` — **Operational** tab header row renders (Occurred (UTC) / Sev / Category / Subsystem / Event / Outcome / Entity / Correlation / Message).
- [ ] **[U]** **Audit** tab header row renders (When / User / Action / Target / Field / Old / New / Source).
- [ ] **[C]** Confirm PII fields (email/phone + the configured sensitive client columns) appear scrubbed/fingerprinted in the audit rows, not in cleartext.

---

## 8. Delivery slips  (PR #507 headers, delivery-date override pre-compaction)

- [ ] **[U]** Slip batch history table header renders (Zone / Delivery date / # orders / Generated (UTC) / Status / Actions).
- [ ] **[C]** Generate slips for a zone/day where an order carries a `_delivery_date` override → the order is selected by its overridden delivery date, and the slip PDF renders (Imagick/mbstring).

---

## 9. Reports  (PR #505 dead-code, wc-order-query statuses pre-compaction)

- [ ] **[C]** **Purchase Order** page — generate a draft PO, then **Export CSV**. The CSV downloads (this is the client-side path that replaced the removed PHP serializer) with correct columns and NO Buffer/Qty Needed/Future columns. A hostile product name (leading `=`/`@`) is neutralised in the exported cell.
- [ ] **[C]** **Private customer report** / **Order errors** / **Spillover** — run each; confirm cancelled/on-hold/pending/refunded/etc. orders are excluded (the `EXCLUDED_ORDER_STATUSES` constant) and the date range is inclusive of the final day.
- [ ] **[U]** Fee-reconciliation / private-sales / spillover **Export CSV** buttons still download.

---

## 10. Tasks UI  (PR #505 dead-code)

- [ ] **[C]** Task list **bulk actions** — select several tasks, **Bulk Skip** and **Bulk Defer** still work (these use the per-id AJAX loop; the unused filter-based `bulk_skip` engine method was removed — confirm no regression in the live bulk buttons).

---

## 11. Products tab  (taxable per-product override — pre-compaction)

- [ ] **[C]** Toggle a product's **taxable** checkbox and save → the per-product override is honoured downstream in side-tax classification (taxable vs non-taxable side counts on the allocation/invoice).

---

## Notes

- Items in sections **5** (Schema Changes) and **1** (Settings) are the two
  biggest un-verified surfaces and should ride a release only after passing here.
- The invoice **HST** change (section 6) intentionally produces different (correct)
  numbers than legacy — review with the operator before cutover; do NOT flip the
  SDNB rate constants yet.
- PDF checks (VAC, slips) will fail anywhere without Imagick/mbstring — that's the
  known local-CLI baseline, not a regression.
