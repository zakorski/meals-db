# Directive: Resolve `STATUS_COUNTED` Purchase Order Status

**Severity:** LOW (MAJ-6 from synthesis)
**Audit reference:** `recon-06-migration-encryption.md`; `recon-09-synthesis.md` MAJ-6
**Target file:** `includes/services/class-purchase-orders.php` (plus possibly admin views)
**Estimated scope:** ~5-15 lines depending on decision
**Risk:** LOW
**Must complete before:** v1.1 release (not pre-cutover blocking)

---

## Context

`MealsDB_Purchase_Orders` declares 6 status constants but only 5 are used in the lifecycle:

| Status | Used? |
|---|---|
| `STATUS_PLANNED` | ✓ (initial state) |
| `STATUS_PLACED` | ✓ (after `place_po` task) |
| `STATUS_ARRIVED` | ✓ (after `confirm_po_arrival` task) |
| `STATUS_COUNTED` | ✗ — never set anywhere |
| `STATUS_RECONCILED` | ✓ (after `physical_count` task) |
| `STATUS_CANCELLED` | ✓ (manual cancel) |

The task workflow currently jumps `ARRIVED → RECONCILED` in one step (the `physical_count` task does both the count AND the reconcile in one task completion handler).

`STATUS_COUNTED` is a planned-but-unused intermediate. Either:
1. Implement it (insert between count and reconcile).
2. Remove the constant.

The decision belongs to the dev and depends on operational reality. **Do not make code changes until the dev has confirmed which option.**

---

## Pre-flight verification

### Step P1: Confirm `STATUS_COUNTED` is in fact unused

```bash
grep -rn "STATUS_COUNTED\|'counted'" includes/ views/ --include="*.php"
```

Expected matches:
- The constant declaration in `class-purchase-orders.php`.
- The `ALLOWED_STATUSES` array reference.
- Possibly a documentation comment.

If grep returns matches in task handler code (`task-types/class-task-type-physical-count.php` etc.) that actually SET the status, `STATUS_COUNTED` is in use after all. **STOP** — the audit was wrong and this directive doesn't apply.

### Step P2: Read the current physical_count handler

Open `includes/task-types/class-task-type-physical-count.php`. Read the `on_complete` method and `apply_adjustments` method. Document:
- What status the PO is set to after a successful count.
- Whether there's any "intermediate" state during the count operation.

The current behavior (per audit): on `physical_count` completion with `count_received='yes'`, the PO is updated with `status=STATUS_RECONCILED` and `reconciled_at=NOW()`. The status `COUNTED` never appears.

### Step P3: Present the decision to the dev

In your response, present the two options:

**Option (a): Remove `STATUS_COUNTED`**
- Pros: Cleaner code. Reflects the actual two-step workflow (placed → arrived → reconciled).
- Cons: If operators ever want to record "counted but not yet reconciled" as a separate state (e.g. a count discovered discrepancies that need supervisor approval), this would be a future regression.

**Option (b): Implement `STATUS_COUNTED`**
- Pros: Records the count step independently. If supervisor approval is added later, the state machine supports it.
- Cons: Adds a step that the current physical_count task doesn't actually use. Risk of leaving POs stuck in `COUNTED` if reconciliation logic is buggy.

The synthesis recommended Option (a). The dev's input may differ depending on whether warehouse workflow needs the intermediate state.

**Do not proceed past this point without explicit confirmation from the dev.**

---

## Option (a): Remove `STATUS_COUNTED`

**Only proceed if the dev confirmed Option (a).**

### Step F1: Remove the constant declaration

In `includes/services/class-purchase-orders.php`, locate:

```php
const STATUS_COUNTED = 'counted';
```

Delete this line.

### Step F2: Remove from `ALLOWED_STATUSES`

Locate the `ALLOWED_STATUSES` array (it's typically a static array or returned by a method). Remove the entry `self::STATUS_COUNTED` or `'counted'`.

Example (your file may differ slightly):

Before:
```php
const ALLOWED_STATUSES = [
    self::STATUS_PLANNED,
    self::STATUS_PLACED,
    self::STATUS_ARRIVED,
    self::STATUS_COUNTED,
    self::STATUS_RECONCILED,
    self::STATUS_CANCELLED,
];
```

After:
```php
const ALLOWED_STATUSES = [
    self::STATUS_PLANNED,
    self::STATUS_PLACED,
    self::STATUS_ARRIVED,
    self::STATUS_RECONCILED,
    self::STATUS_CANCELLED,
];
```

### Step F3: Add a removal comment

Above the `ALLOWED_STATUSES` array, add or update the docblock:

```php
/**
 * Valid PO statuses.
 *
 * The lifecycle is: PLANNED → PLACED → ARRIVED → RECONCILED, with
 * CANCELLED as a terminal state available from any prior state.
 *
 * HISTORY: A `COUNTED` status was previously declared but never
 * used. The physical_count task handler does both the count and
 * the reconcile in one operation, so the intermediate state had no
 * place in the workflow. Removed for clarity.
 */
```

### Step F4: Check for any orphaned references

```bash
grep -rn "STATUS_COUNTED\|'counted'" . --include="*.php"
```

Expected: zero matches after removal.

If matches remain (e.g. in admin status filter UI), remove them too. Each removal should be a separate small commit if possible.

Common locations to check:
- `views/purchase-orders.php` (PO list view — might have a "Counted" filter button)
- `includes/services/class-purchase-orders.php` itself (other methods)
- `assets/js/*.js` (if any JS hardcodes the status string)

### Step F5: Database safety

If any production PO row has `status = 'counted'` (despite the code never setting it), removing the status from validation logic would mean that row fails any future validation check. Verify:

```bash
wp db query "SELECT COUNT(*) FROM 2xnIt_meals_purchase_orders WHERE status = 'counted'"
```

Expected: 0. If non-zero, those rows need to be migrated. Either:
- Update them to `'reconciled'` (if the count succeeded) or `'arrived'` (if pending count).
- Decide on a per-row basis with the operator.

Include this verification result in your response. If non-zero, **STOP** and report to the dev — production has unexpected state.

---

## Option (b): Implement `STATUS_COUNTED`

**Only proceed if the dev confirmed Option (b).**

This is a more substantial change. Defer to a future directive if pursuing this path — the directive should specify:
- When `STATUS_COUNTED` is set (between `apply_adjustments` and the final reconcile UPDATE in `class-task-type-physical-count.php::on_complete`).
- Whether a new task type (`approve_count` or `reconcile_po`) is needed to advance from COUNTED to RECONCILED.
- UI changes to surface the new state in `views/purchase-orders.php`.

This directive does NOT proceed with Option (b) implementation. **Halt and request a follow-up directive specifically for Option (b) implementation if the dev chooses it.**

---

## Testing for Option (a)

```bash
php -l includes/services/class-purchase-orders.php
```

Functional test:

> **Manual test required:**
> 1. Create a test PO via the place_po task.
> 2. Confirm arrival via the confirm_po_arrival task (status: ARRIVED).
> 3. Run the physical_count task (status: RECONCILED).
> 4. Verify the PO status appears correctly in:
>    - `meals_purchase_orders.status` column.
>    - The Purchase Orders admin view.
>    - The audit log (`po_status_changed` action).
> 5. Verify no PHP errors in `wp_content/debug.log`.

---

## Out of scope for this directive

- Do NOT change the PO lifecycle workflow itself (PLANNED → PLACED → ARRIVED → RECONCILED). The lifecycle is correct as-is.
- Do NOT add new statuses (`APPROVED`, `ON_HOLD`, etc.). Status changes are operational decisions that need separate analysis.
- Do NOT modify the `physical_count` task handler beyond removing references to the deleted status.
- Do NOT touch the inventory bump logic in `class-task-type-confirm-po-arrival.php`. Stock writes are correct as-is.

---

## Acceptance criteria

The directive is complete when:

1. ✅ Pre-flight steps P1, P2, P3 are complete and documented.
2. ✅ The dev has explicitly confirmed Option (a) or Option (b).

**For Option (a):**
3. ✅ The `STATUS_COUNTED` constant is removed.
4. ✅ The constant is removed from `ALLOWED_STATUSES`.
5. ✅ All other code references (if any) are removed.
6. ✅ The history comment is added.
7. ✅ Production DB check (Step F5) returns 0 rows.
8. ✅ `php -l` passes.

**For Option (b):**
9. ✅ This directive halts and requests a follow-up directive.

When complete, your final response should include:
- Confirmation of which option the dev chose.
- A diff of the changes.
- The grep result confirming zero remaining references.
- The production DB verification result.
