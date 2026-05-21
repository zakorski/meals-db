# Trial Invoice Comparison Spreadsheet — Schema

The actual spreadsheet (`docs/trial-invoice-comparison.xlsx`) is
created by the operator; this file documents the expected columns
and how to fill them.

## Columns

| Column | Source | Notes |
|---|---|---|
| Client ID | `meals_clients.client_id` | PK |
| Client name | `first_name` + `last_name` | For human reading only |
| Legacy mains $ | Legacy Enzebra invoice | Sum of mains line items |
| New mains $ | New plugin invoice | Sum from `MealsDB_Invoice_Generator` |
| Legacy sides $ | Legacy Enzebra invoice | Sum of sides line items |
| New sides $ | New plugin invoice | Sum from new generator |
| Legacy contribution $ | Legacy invoice | Product 5675 line items |
| New contribution $ | New plugin invoice | Both fee mechanisms (per directive 03) |
| Legacy delivery fee $ | Legacy invoice | Product 4122 line items |
| New delivery fee $ | New plugin invoice | Both fee mechanisms |
| Diff $ | New total − Legacy total | Signed: positive = new is higher |
| Notes | Operator | One of `OK`, `KNOWN-UB17K`, `FEE-MECH-DIFF`, `INVESTIGATE` |

## Tags

- **OK** — `|Diff $|` is within the per-client threshold. No action.
- **KNOWN-UB17K** — The $17K legacy under-billing pattern. Expected
  after cutover; not a bug.
- **FEE-MECH-DIFF** — A fee mechanism difference that the directive-03
  helper should have unified. If this tag appears post-directive-03,
  it indicates a bug — investigate as `INVESTIGATE` instead.
- **INVESTIGATE** — Unknown cause. Root-cause before trial end. Either
  resolves to a bug fix, an `OK` reclassification with explanation, or
  a documented known-difference.

## Pass criteria

By trial end:
- Every row has a tag.
- No row is `INVESTIGATE` without a paired entry in
  `docs/trial-log.md` explaining what was found.
- Total signed `Diff $` across all clients reconciles with the known
  patterns above.
