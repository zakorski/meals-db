# Directive — Treat JSON ≡ LONGTEXT in schema-drift detection on MariaDB (Solution B)

## Problem (root-caused via Adminer in v544 verification)
The staging DB is **MariaDB 10.6**, where the `JSON` type is an ALIAS for `LONGTEXT` + a CHECK constraint.
So `INFORMATION_SCHEMA.COLUMNS.COLUMN_TYPE` for any JSON column always reports **`longtext`**, never
`json` — no matter how many `ALTER ... JSON` statements run. The canonical schema declares 11 columns as
`JSON NULL` / `JSON NOT NULL` (category_data, dietary_tags, allergen_flags, schedule_rules.{recurrence,
query_criteria,payload_template,tags}, tasks.{payload,tags}, purchase_orders.items, event_log.context,
invoice_drafts.params). The drift detector normalizes the canonical side to `json` and the live side to
`longtext`, so these 11 columns are flagged as drifted FOREVER on MariaDB and can never clear.

Consequences confirmed in the v544 test:
- The Schema Changes tool can never reach a clean "no pending changes" state (11 permanent false-positives).
- This is the long-standing `schema.drift_detected` warning (seen across dozens of versions).
- **Sync Products fails 0/163** because it depends on the products json columns "matching" — which they
  never will on MariaDB — so product display sync is effectively blocked on this environment.

The v544 idempotency fix is working correctly (it honestly refuses to falsely-clear these); the remaining
issue is that `json` and `longtext` are being treated as DIFFERENT types when, on MariaDB, they are the
same physical type.

## Goal (Solution B)
Make the drift detector treat **`json` and `longtext` as an equivalent column type** so a canonical `JSON`
column backed by a MariaDB `longtext` storage is NOT flagged as drifted. This lets the 11 items clear, ends
the perpetual `schema.drift_detected` warning, and unblocks Sync Products. Do NOT change the canonical
schema (keep declaring `JSON` — it's correct on real MySQL 8); only make the COMPARISON tolerant.

## Reference (v1.0.544)
- `includes/class-schema-sync.php::normalize_column_type()` (~575) — the single normalization applied to
  BOTH the actual side (`column_matches_definition()` ~435, from INFORMATION_SCHEMA `COLUMN_TYPE`) and the
  expected side (`parse_expected_column()` ~534, from the canonical definition). It already maps
  `bool/boolean → tinyint` and strips int display widths — this is exactly where a `json → longtext`
  mapping belongs so BOTH sides normalize identically.
- The type is compared as one field inside `column_matches_definition()` (type/nullable/default/
  auto_increment) — normalizing the type string is sufficient; no other change needed.
- DB engine is detectable via `$wpdb->db_version()` / the `dbh` server info if a MariaDB-scoped variant is
  preferred.

## Change
In `normalize_column_type()`, add a `json → longtext` equivalence so both sides collapse to the same token.

**Recommended (unconditional, simplest, safe):**
```php
// JSON is a distinct type on MySQL 8 but a stored ALIAS for LONGTEXT (+CHECK) on
// MariaDB, where INFORMATION_SCHEMA.COLUMN_TYPE always reports 'longtext' for a
// JSON column. Collapse the two to one token so a canonical `JSON` column backed
// by MariaDB longtext storage is not perpetually flagged as drifted. Safe on real
// MySQL too: we only ever declare a given column as one of the two canonically, so
// treating them as equivalent cannot mask a meaningful difference here.
if ($normalized === 'json') {
    $normalized = 'longtext';
}
```
Place it alongside the existing `bool/boolean → tinyint` mapping. Because `normalize_column_type()` runs on
BOTH the expected and actual type strings, canonical `JSON` → `longtext` and MariaDB actual `longtext` →
`longtext` now match; and on MySQL 8 an actual `json` → `longtext` matches canonical `json` → `longtext`
too. Symmetric either way.

**Alternative (MariaDB-scoped) — only if you want JSON kept strict on MySQL 8:**
Gate the collapse behind a MariaDB check (e.g. `stripos($wpdb->db_version_string ?? '', 'mariadb') !==
false`, or a cached `is_mariadb()` helper). This keeps `json` vs `longtext` a real distinction on MySQL 8
while tolerating it on MariaDB. More precise, slightly more code, needs a reliable engine probe. The
unconditional version is recommended unless there's a concrete reason to distinguish them on MySQL 8 —
there isn't one in this schema (no column is ever legitimately BOTH a json and a longtext canonically).

## Must NOT change
- The canonical schema definitions (keep `JSON NULL` / `JSON NOT NULL` — correct for MySQL 8 deployments).
- The v544 idempotency / verify-after-apply logic (works — it will simply now see these columns as matching
  and stop listing them).
- Any other type normalization (int widths, bool→tinyint), nullable/default/auto_increment comparison.
- The CHECK-constraint aspect of MariaDB JSON — out of scope; we only compare base column type.

## Verify
```
php -l includes/class-schema-sync.php
php tests/test-schema-sync.php tests/test-schema-alter-*.php
```
- On MariaDB staging: open **Data Ops → Schema Changes** → the 11 longtext↔json items are NO LONGER listed;
  pending count drops to only genuinely-appliable/real drifts (ideally reaching "No pending schema
  changes"). 📷
- `detect_column_mismatches()` returns no entry for `meals_products.category_data` (and the other 10 json
  columns) when the live column is MariaDB `longtext`.
- The `schema.drift_detected` Event Log warning stops firing on the next schema scan.
- **Sync Products** now runs clean ("Synced N products…") instead of 0/163 — the products json columns no
  longer block it. (This is the key downstream win; confirm it.)
- On a MySQL 8 environment (if available/CI): a canonical `JSON` column backed by a real `json` column
  still matches (no false drift), and no unrelated column starts matching that shouldn't.

## Test to add
`test-schema-sync-json-longtext.php` (or extend `test-schema-sync.php`):
- Assert `column_matches_definition('JSON NULL', <actual: column_type 'longtext', nullable YES>)` returns
  TRUE (the MariaDB case — would have been false before).
- Assert `column_matches_definition('JSON NOT NULL', <actual longtext, nullable NO>)` returns TRUE.
- Assert a genuinely different type (e.g. canonical `JSON` vs actual `varchar(255)`) still returns FALSE —
  the equivalence is json↔longtext only, not json↔anything.

## Operator note
After this ships and a schema scan runs, the products json columns will read as matching, so **Sync
Products should succeed** — re-run it to confirm the 163 products sync. This resolves the product-display-
sync block on MariaDB that the v544 test flagged for ops.
