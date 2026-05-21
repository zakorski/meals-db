# Shadow-Mode Trial Decision

**Filled by:** Dev (with operator concurrence) at trial end.
**Reviewed by:** Operator before cutover is executed.

For the criteria, see `docs/shadow-mode-trial-plan.md`.

---

## Decision

**Date:** YYYY-MM-DD
**Decision:** GO / HOLD / ROLL BACK
**Decided by:** _Dev name_, _Operator name_

---

## Rationale

(Why this decision? Reference specific findings from
`docs/trial-log.md` and the invoice-comparison spreadsheet.)

---

## Outstanding items at decision time

For HOLD: which findings need resolution before re-attempting cutover.
For GO: which post-cutover monitoring tasks must be tracked.
For ROLL BACK: what needs to happen before any future cutover attempt.

---

## Pass criteria summary

| # | Criterion | Met? |
|---|---|---|
| 1 | Per-client variance within agreed threshold | _Y/N_ |
| 2 | Exceeding variances all explained (no `INVESTIGATE` left) | _Y/N_ |
| 3 | Phase W reconciliation produces real output | _Y/N_ |
| 4 | No silent data loss | _Y/N_ |
| 5 | Migration ran clean | _Y/N_ |

---

## Next steps

- If GO: schedule the cutover per the plan's cutover section.
- If HOLD: file the outstanding items as directives or issues; agree
  on a re-trial date.
- If ROLL BACK: brief client; document root cause; plan recovery.
