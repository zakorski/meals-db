# Directive BC (Billing Correctness) — index

**Source:** 2026-06 security & correctness review (full-codebase, nine-subsystem parallel audit). These are billing/allocation **correctness** bugs found *beyond* the already-applied LB-1…LB-7 series. Every one of them is a *silent* miscalculation — it produces a wrong number or loses data without raising an error, which is the failure class CLAUDE.md explicitly calls out (Pattern 7 caveat: "swallow the exception; do not pretend the work happened").

None is a security hole. All affect money owed to / collected from SDNB, Veterans Affairs, or the client. Treat the HIGH items as **cutover blockers**: once live invoicing starts they corrupt submitted figures.

| # | Title | Severity | Files | Risk |
|---|---|---|---|---|
| BC-1 | Rebuilder loses spilled meals & double-counts dual-program clients | **HIGH** | `class-allocation-rebuilder.php` | MED-HIGH (core fill) |
| BC-2 | `contribution_applied` is never released; wrong-month keying | **HIGH** | `class-allocation-engine.php`, `class-order-fees.php` | LOW-MED |
| BC-3 | Orders into a finalized month are silently dropped; unfinalize doesn't re-dirty | **HIGH** | `class-allocation-rebuilder.php`, `class-invoice-draft.php` | LOW |
| BC-4 | Contribution reconciliation reports false discrepancies | MEDIUM | `class-reports.php` | LOW |
| BC-5 | Invoice contribution sum hardcodes product 5675, sums wrong column, double-deducts on spill | MEDIUM | `class-invoice-generator.php` | LOW-MED |
| BC-6 | Partial refunds never reduce allocations | MEDIUM | `class-allocation-hooks.php`, `class-allocation-rebuilder.php` | MED |
| BC-7 | Failed/refunded orders print delivery slips & inflate POs | MEDIUM | `class-wc-order-query.php` | LOW |

## Suggested order

1. **BC-3** then **BC-1** (BC-3 establishes the finalized-month invariants BC-1's window logic leans on; BC-1 is the riskiest and benefits from BC-3's guards being in place).
2. **BC-2** + **BC-5** together — both touch the client-contribution lifecycle (apply → release → invoice-read); doing them in one pass keeps the flag semantics and the invoice read in agreement.
3. **BC-6**, **BC-7**, **BC-4** — independent, any order.

## Cross-cutting note

BC-1, BC-5, and BC-6 all stem from the same root: the rebuilder and invoice generator re-derive allocations from raw WC order rows using **ad-hoc SQL** (status lists, product-ID filters, customer_id joins) instead of routing through `MealsDB_WC_Order_Query`, which already centralises HPOS-correct status filtering and the dual fee-mechanism sum. After the BC fixes land, consider a follow-up consolidation directive (BC-CONSOLIDATE) routing the rebuilder's order pull through `MealsDB_WC_Order_Query::get_orders_with_items_for_users()` so these filters live in exactly one place. Tracked separately; not required for cutover.
