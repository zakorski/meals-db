# Directive INV-1 (SURGICAL, v446) — Suppress zero-activity clients from invoices

## HOW TO EXECUTE — READ FIRST
- ONE edit in `includes/services/class-invoice-generator.php`. `read` the file, find the EXACT
  verbatim FIND, apply. Do NOT regenerate the method. If FIND doesn't match, STOP and report.

**Why:** invoices currently include a row for EVERY client queried, even those with no orders that
month — producing empty lines/pages (the operator saw ~4 blank pages before the clients with real
orders). Fix: at the point where per-client billing data is assembled, SKIP any client with no
billable activity for the month.

**Where:** `get_phase2_billing_data()` builds the per-client `$out[$cid]` map and is the SHARED data
source for BOTH the VAC and SDNB draft builders — so one guard here fixes both pipelines.

**Include rule (operator-confirmed):** include a client only if they have ANY billable activity:
mains > 0 OR tax_sides > 0 OR nontax_sides > 0 OR contribution > 0. This deliberately KEEPS a client
who had a contribution/fee but zero meals (do not drop them), and suppresses only truly-empty clients.

---

## EDIT — skip zero-activity clients before adding them to the output
**File:** `includes/services/class-invoice-generator.php`
**FIND (verbatim):**
```
            $out[$cid] = array_merge($client, [
                'allocated_mains'        => $allocated_mains,
                'allocated_sides'        => $allocated_sides,
                'allocated_tax_sides'    => $allocated_tax_sides,
                'allocated_nontax_sides' => $allocated_nontax_sides,
                'resolved_rate'          => $resolved_rate,
                'contribution_cents'     => $contribution_cents,
                'basic_cents'            => $basic_cents,
                'tax_cents'              => $tax_cents,
            ]);
```
**REPLACE WITH:**
```
            // Suppress zero-activity clients: a client with no mains, no sides
            // of any kind, and no contribution has nothing to bill this month —
            // including them produces an empty invoice line/page. Keep any
            // client with ANY billable activity (a contribution with zero meals
            // still belongs on the invoice).
            $has_activity = ($allocated_mains > 0)
                || ($allocated_tax_sides > 0)
                || ($allocated_nontax_sides > 0)
                || ($contribution_cents > 0);
            if (!$has_activity) {
                continue;
            }

            $out[$cid] = array_merge($client, [
                'allocated_mains'        => $allocated_mains,
                'allocated_sides'        => $allocated_sides,
                'allocated_tax_sides'    => $allocated_tax_sides,
                'allocated_nontax_sides' => $allocated_nontax_sides,
                'resolved_rate'          => $resolved_rate,
                'contribution_cents'     => $contribution_cents,
                'basic_cents'            => $basic_cents,
                'tax_cents'              => $tax_cents,
            ]);
```
**NOTE:** `$allocated_sides` is the legacy combined count; the rule uses tax_sides/nontax_sides
explicitly (the fields that actually drive billing) plus mains and contribution. If
`$allocated_sides` is the only sides figure in scope and tax/nontax aren't both populated, the rule
still holds (it ORs them). Do not change how the activity figures are computed — only add the skip.

---

## VERIFICATION
```bash
cd <plugin-root>
grep -n "has_activity" includes/services/class-invoice-generator.php   # the new guard
php tests/test-*.php   # green
```
**Manual (staging):**
- Generate a VAC invoice draft for a month with real orders. Confirm the review grid + the downloaded
  output contain ONLY clients with activity — no empty leading pages/rows for non-ordering clients.
- Confirm a client who had a contribution but zero meals (if any exist) is STILL included.
- Confirm the totals/counts for the real clients are unchanged from before (suppression must not alter
  any included client's figures).

## DO NOT
- Do not change how mains/sides/contribution are computed — only add the skip.
- Do not apply the filter in the serializers or the builders separately — the single guard in
  get_phase2_billing_data covers both pipelines.
- Do not drop clients with a contribution/fee but no meals — the rule includes them.
