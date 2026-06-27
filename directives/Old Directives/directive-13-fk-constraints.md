# Directive: Resolve FK Constraint Metadata-vs-Reality Mismatch

**Severity:** LOW (STRUCT-3 from synthesis)
**Audit reference:** `recon-03-sync-subsystem.md` lines 970-980; `recon-09-synthesis.md` STRUCT-3
**Target files:** `includes/class-schema.php` and/or `includes/class-schema-rebuild.php`
**Estimated scope:** ~20-50 lines depending on chosen option
**Risk:** LOW for Option A (delete metadata), MEDIUM for Option B (enable constraints — could fail on existing data with orphan rows)
**Must complete before:** v1.1 release (not pre-cutover blocking)

---

## Context

`MealsDB_Schema::get_canonical_schema()` accepts an `$include_foreign_keys` parameter that defaults to `false`. The schema definition includes `foreign_keys` metadata for relationships like:
- `meals_client_rates.client_id` → `meals_clients.client_id`
- `meals_client_allocations.client_id` → `meals_clients.client_id`
- `meals_delivery_allocations.client_id` → `meals_clients.client_id`
- etc.

But because the default is `false`, **no `ALTER TABLE ADD CONSTRAINT` statements ever execute**. The FK metadata exists for documentation only; the database has no actual referential integrity enforcement.

The audit also found that `MealsDB_Schema_Rebuild::determine_create_order` runs its topological sort on the empty `$foreign_keys` input — it produces a no-op ordering that happens to coincide with the natural array order in `MealsDB_Tables::all()`.

This is "half-implemented" — the worst state. Either:
- **Option A**: Remove the FK metadata. Document that referential integrity is enforced at the PHP layer only.
- **Option B**: Enable FK constraint creation. Accept the cascade complexity and the risk that existing data may have orphan rows that prevent constraint creation.

---

## Pre-flight verification

### Step P1: Check for existing orphan rows

If we choose Option B, FK creation will fail on tables with orphan rows. Pre-check every FK relationship:

```bash
# Each query should return 0. Non-zero means orphans exist.

# client_rates orphans
wp db query "SELECT COUNT(*) FROM 2xnIt_meals_client_rates cr
  LEFT JOIN 2xnIt_meals_clients c ON cr.client_id = c.client_id
  WHERE c.client_id IS NULL"

# client_allocations orphans
wp db query "SELECT COUNT(*) FROM 2xnIt_meals_client_allocations ca
  LEFT JOIN 2xnIt_meals_clients c ON ca.client_id = c.client_id
  WHERE c.client_id IS NULL"

# delivery_allocations orphans
wp db query "SELECT COUNT(*) FROM 2xnIt_meals_delivery_allocations da
  LEFT JOIN 2xnIt_meals_clients c ON da.client_id = c.client_id
  WHERE c.client_id IS NULL"
```

Run a similar query for each FK relationship in the canonical schema. Document the results in your response.

### Step P2: Read the schema definition

Open `includes/class-schema.php`. Locate `get_canonical_schema`. Read the full method.

Document:
- The `$include_foreign_keys` parameter default and where it's used.
- The list of every table that has `foreign_keys` metadata.
- The structure of the FK metadata (column references, ON DELETE / ON UPDATE actions).

### Step P3: Read the rebuild path

Open `includes/class-schema-rebuild.php`. Locate `determine_create_order`. Read it.

Document:
- How it processes `foreign_keys`.
- What order it produces with the current empty input.
- What order it would produce if `$include_foreign_keys = true`.

### Step P4: Present the decision

In your response, present these options:

**Option A — Remove the metadata (recommended):**
- Pros: Cleanest. Reflects the reality that PHP enforces invariants. No risk of FK constraint creation failing on existing orphans.
- Cons: Loses the documentation value of the metadata. Future devs lose the "schema says these tables should be related" hint.

**Option B — Enable FK constraint creation:**
- Pros: Database-level integrity. Catches PHP bugs that fail to cascade.
- Cons: Requires cleaning up any existing orphan rows (P1 check). Requires testing that ON DELETE CASCADE behavior matches PHP-layer expectations. MyISAM tables would need to be converted to InnoDB (likely already are).
- Risk: An orphan row in production blocks the schema upgrade. Operators may not know how to resolve it.

**Recommended:** Option A. The PHP-layer referential integrity is well-tested and the FK metadata adds no current value.

**Do not proceed past this point without explicit dev confirmation.**

---

## Option A: Remove FK metadata

### Step F1: Remove the `foreign_keys` key from every table definition

In `includes/class-schema.php`, locate each table definition in `get_canonical_schema`. Each table is an array with keys like `columns`, `indexes`, `engine`, `foreign_keys`.

For each table that has a `foreign_keys` key, delete that key and its array value entirely.

Example before:
```php
'meals_client_rates' => [
    'columns' => [ /* ... */ ],
    'indexes' => [ /* ... */ ],
    'engine'  => 'InnoDB',
    'foreign_keys' => [
        'fk_client_rates_client' => [
            'column' => 'client_id',
            'references' => ['table' => 'meals_clients', 'column' => 'client_id'],
            'on_delete' => 'CASCADE',
            'on_update' => 'CASCADE',
        ],
    ],
],
```

After:
```php
'meals_client_rates' => [
    'columns' => [ /* ... */ ],
    'indexes' => [ /* ... */ ],
    'engine'  => 'InnoDB',
    // NOTE: Referential integrity for client_id is enforced at the PHP
    // layer via MealsDB_Clients_Repository and MealsDB_Client_Rates
    // service methods. Database-level FK constraints are intentionally
    // not used — see CLAUDE.md / DEV-NOTES for rationale.
],
```

The comment is short and documents the intent (FK metadata removed deliberately, not forgotten).

### Step F2: Remove the `$include_foreign_keys` parameter

If `get_canonical_schema` accepts `$include_foreign_keys` as a parameter, remove it. Update the signature:

Before:
```php
public static function get_canonical_schema(bool $include_foreign_keys = false): array {
```

After:
```php
public static function get_canonical_schema(): array {
```

Then update every caller. Most likely the parameter is always called with default. Verify:

```bash
grep -rn "get_canonical_schema" includes/ --include="*.php"
```

Each call site should either drop the parameter entirely (was passing false) or be flagged for review (if it was passing true, which would have created FK constraints — unlikely given the metadata-only state).

### Step F3: Remove or simplify `determine_create_order`

In `class-schema-rebuild.php`, the `determine_create_order` method processes `foreign_keys` for topological sort. With FK metadata removed, the topological sort has no input. Two options:

**Option A.1**: Remove `determine_create_order` entirely. Replace its call site with the natural order from `MealsDB_Tables::all()`.

**Option A.2**: Simplify `determine_create_order` to return `MealsDB_Tables::all()` directly.

Recommended: Option A.2 — preserves the abstraction in case future FK metadata is reintroduced. Update the method:

```php
/**
 * Determine the order in which to create tables during schema rebuild.
 *
 * HISTORY: A previous version processed foreign_keys metadata via
 * topological sort to ensure child tables created after parents.
 * The metadata was never used to actually create FK constraints, so
 * the sort was a no-op. After the metadata was removed (see
 * class-schema.php), this method returns the natural array order.
 *
 * @return string[] Table names in creation order.
 */
public static function determine_create_order(): array {
    return MealsDB_Tables::all();
}
```

---

## Option B: Enable FK constraint creation

**Only proceed if the dev confirmed Option B.**

This is significantly more work and higher risk. Outline only (NOT for execution in this directive):

1. Fix all orphan rows identified in P1.
2. Change `get_canonical_schema` default to `$include_foreign_keys = true`.
3. Update schema installer to execute `ALTER TABLE ADD CONSTRAINT` after CREATE TABLE.
4. Test that the install path is idempotent — second install must not re-add or fail on existing constraints.
5. Test that constraint failures produce useful operator errors.
6. Verify ON DELETE CASCADE behavior matches PHP-layer expectations.
7. Test that schema rebuild via the admin UI handles constraints correctly.

**This directive does NOT proceed with Option B implementation.** If the dev chooses B, this directive halts and a follow-up directive specifically for Option B implementation is required.

---

## Testing for Option A

### Step T1: Static check

```bash
php -l includes/class-schema.php
php -l includes/class-schema-rebuild.php
```

### Step T2: Schema rebuild test

> **Manual test required on staging:**
> 1. Navigate to Meals DB → Updates → Force Rebuild Schema (or whatever admin path triggers schema rebuild).
> 2. Confirm rebuild completes without error.
> 3. Spot-check 3 tables for column completeness via `SHOW CREATE TABLE`.
> 4. No PHP errors in `wp_content/debug.log`.

### Step T3: Verify FK metadata is gone

```bash
grep -n "foreign_keys" includes/class-schema.php
```

Expected: zero matches (or only matches in comments/docblocks).

### Step T4: Verify the parameter is gone

```bash
grep -rn "include_foreign_keys\|\\\$include_fks" includes/ --include="*.php"
```

Expected: zero matches outside the old definition.

---

## Out of scope for this directive

- Do NOT add new FK constraints to the database. That's Option B, which is a separate large directive.
- Do NOT modify the cascade behavior in `MealsDB_Clients::delete_client` — that's directive 05 Part B's scope.
- Do NOT change column types or default values. The schema definition changes here are metadata-only (removing `foreign_keys` keys).
- Do NOT change engine from InnoDB. All plugin tables should remain InnoDB.

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight steps P1-P3 are complete.
2. ✅ The dev has confirmed Option A or Option B.

**For Option A:**
3. ✅ The `foreign_keys` metadata is removed from every table definition in `class-schema.php`.
4. ✅ Each removal has a comment noting PHP-layer enforcement.
5. ✅ The `$include_foreign_keys` parameter is removed from `get_canonical_schema`.
6. ✅ `determine_create_order` is simplified.
7. ✅ T1-T4 all pass.

**For Option B:**
8. ✅ This directive halts and a follow-up directive is requested.

When complete, your final response should include:
- The chosen option.
- A diff of `class-schema.php` showing all metadata removals.
- A diff of `class-schema-rebuild.php` (if modified).
- The schema rebuild test result.
