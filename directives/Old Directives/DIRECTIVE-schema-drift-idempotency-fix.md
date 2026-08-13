# Directive — Fix schema-drift detector: applied changes must clear (idempotency / release gate)

## Problem (root cause confirmed in code + reproduced in GUI test v542, Phase 5)
The Data-Ops → Schema Changes tool reports an apply as `{success:true}` (and audit-logs
`schema_column_altered`), but the drift STAYS in the pending list — the operator can never reach a clean
"no pending changes" state. GUI test v542 5.2/5.3 reproduced this for BOTH a SAFE change
(`meals_products.id`) and a RISKY one (`slip_batches.status`). This blocks confident schema sign-off and
is the long-standing `schema.drift_detected` warning (seen across many prior test runs) finally diagnosed.

There are TWO independent normalization defects in `MealsDB_Schema_Sync::column_matches_definition()` (and
its helpers). Each causes a column to be re-flagged forever even when the live column is actually correct.

### Bug 1 — AUTO_INCREMENT columns are ALWAYS re-flagged (the `meals_products.id` case)
- Canonical schema: `'id' => 'INT AUTO_INCREMENT'` (class-schema.php ~115) — no explicit `NOT NULL`.
- `normalize_expected_definition()` derives nullability by text search:
  `$nullable = stripos($masked_lower, 'not null') === false;` → for `'INT AUTO_INCREMENT'` there is no
  "not null" substring, so it computes **nullable = true**.
- But MySQL forces every AUTO_INCREMENT column to be **NOT NULL** implicitly, so `INFORMATION_SCHEMA`
  reports `is_nullable = NO` → the detector's actual **nullable = false**.
- `column_matches_definition()` compares `expected.nullable (true) !== actual.nullable (false)` → the
  column NEVER matches, even though it is exactly correct. The ALTER (`MODIFY COLUMN id INT
  AUTO_INCREMENT`) runs, MySQL keeps it NOT NULL (it must), and the detector immediately re-flags it.
  Infinite loop; apply can never clear it.

### Bug 2 — LONGTEXT->JSON RISKY changes don't clear (the 12 json drifts)
- Canonical: e.g. `'category_data' => 'JSON NULL'` → normalized type `json`.
- These re-flag because either (a) the ALTER to JSON did not actually run — `apply()` treats
  `$wpdb->query() !== false` as success, but a no-op / silently-unconverted result is `0`, not `false`, so
  a change that didn't really convert still reports "applied"; or (b) the actual column is genuinely still
  `longtext` and needs a real conversion the online path can't do. Either way the detector correctly still
  sees `longtext !== json` and re-flags — but the tool told the operator it succeeded.

## Goal
1. Make the detector's expected-nullability match MySQL reality for implicitly-NOT-NULL columns
   (AUTO_INCREMENT, and any PRIMARY KEY column), so a correct column is NOT perpetually re-flagged.
2. Make `apply()` VERIFY the post-ALTER column actually matches the canonical definition before reporting
   success, so a no-op/failed conversion is reported honestly instead of as `applied`.
After both, an applied change disappears from the pending list (idempotent/terminating), and a change that
can't be applied is surfaced as still-pending WITH an honest "not applied" status — never a false success.

## Reference (v1.0.542)
- `includes/class-schema-sync.php`:
  - `normalize_expected_definition()` (~the `$nullable = stripos(...'not null')===false;` line) — the
    nullability derivation to fix for AUTO_INCREMENT / PK.
  - `column_matches_definition()` — compares type/nullable/default/auto_increment.
  - `detect_column_mismatches()` — the per-column drift scan.
- `includes/class-schema-alter-executor.php::apply()` — runs `$plan['alter_online']` / `alter_plain`,
  checks `!== false`, audit-logs, returns `['status' => 'applied']`. Add post-apply verification here.
- `includes/class-schema-alter-planner.php` — builds the MODIFY SQL; unchanged unless needed.

## Change

### Fix 1 — expected nullability must account for implicit NOT NULL
In `normalize_expected_definition()`, a column that is AUTO_INCREMENT (or declares PRIMARY KEY) is
implicitly NOT NULL in MySQL. Force `nullable = false` in that case:
```php
$nullable       = stripos($masked_lower, 'not null') === false;
$auto_increment = stripos($masked_lower, 'auto_increment') !== false;
$is_primary     = stripos($masked_lower, 'primary key') !== false;
// MySQL forces AUTO_INCREMENT and PRIMARY KEY columns to NOT NULL implicitly;
// INFORMATION_SCHEMA reports is_nullable=NO for them. Reflect that so a correct
// column is not perpetually re-flagged as drifted.
if ($auto_increment || $is_primary) {
    $nullable = false;
}
```
(Note: `sanitize_column_definition()` strips `PRIMARY KEY` before this runs in some paths — derive
`$is_primary` from the RAW/unsanitized canonical definition, or pass an explicit is-primary flag from the
schema, so the primary-key implicit-NOT-NULL is detected. For the immediate `id` bug, the AUTO_INCREMENT
check alone resolves it, since the canonical `id` is `INT AUTO_INCREMENT`.)

### Fix 2 — apply() verifies the column actually matches before claiming success
After the ALTER runs "successfully", re-read the live column and confirm it now matches the canonical
definition; only then report `applied`. Otherwise report a distinct non-success status so the tool shows
the truth:
```php
// A query() that returns 0 (no-op) is !== false, so "ok" is not proof the column
// now matches. Verify against the live definition before declaring success.
if ($ok) {
    $actual = MealsDB_Schema_Sync::fetch_existing_column($this->wpdb, $plan['table'], $plan['column']);
    if ($actual === null
        || !MealsDB_Schema_Sync::column_matches_definition($plan['expected_definition'], $actual)) {
        return ['status' => 'not_applied', 'plan' => $plan,
                'reason' => 'ALTER ran but the column still does not match the canonical definition'];
    }
}
```
(Expose a small public/static `fetch_existing_column()` on Schema_Sync if one isn't already callable, and
make `column_matches_definition()` reachable — it exists but is private; a thin public wrapper is fine.)
Keep the audit log ONLY on verified success.

### Do NOT change
- The SAFE/RISKY classification, the typed-`ALTER` confirmation gate, the pre-flight row-count / data-loss
  block, or maintenance-mode engagement — those all work correctly per the GUI test.
- The additive ADD-column / create-table path.
- Any actual column data. This is detector + verification only.

## Verify
```
php -l includes/class-schema-sync.php includes/class-schema-alter-executor.php
php tests/test-schema-alter-executor.php tests/test-schema-alter-planner.php
php tests/test-schema-alter-integration.php   # on staging via wp eval-file (needs real MySQL)
```
- On real MySQL/staging: with schema current, `detect_column_mismatches()` returns EMPTY for
  `meals_products.id` (and every AUTO_INCREMENT PK) — the implicit-NOT-NULL false-positive is gone.
- Apply the `meals_products.id` change (if still listed) → it now CLEARS from the pending list and does not
  reappear on reload. Re-run the tool → "no pending changes" for that item.
- Apply a longtext->json change on a table that supports it → verify the column becomes `json` and the
  item clears. If the conversion truly can't run, the tool now shows `not_applied` with the reason, NOT a
  false "applied".
- The operator can reach a clean "no pending changes" state for all SAFE + genuinely-appliable changes.
- Re-run 5.2/5.3 from the GUI test: applied items clear; only truly-un-appliable (bespoke-migration) items
  remain, honestly labelled.

## Test to add
Extend `test-schema-alter-executor.php` (or a new `test-schema-drift-idempotency.php`):
- Assert `column_matches_definition('INT AUTO_INCREMENT', <actual: type int, is_nullable NO,
  extra auto_increment>)` returns TRUE (the implicit-NOT-NULL case — would have returned false before).
- Assert `apply()` returns `not_applied` (not `applied`) when the post-ALTER column still doesn't match
  (simulate a no-op query), and `applied` only when the live column verifies.

## Operator note
Once this ships and the products longtext->json migrations actually apply, re-run **Sync Products** — GUI
test 1.11 ("0 synced, 163 failed") is almost certainly downstream of the un-applied products JSON drift and
should recover once the schema is clean.
