# Phase A — Schema: Add Allowance Columns to meals_clients

## Objective

Add two new nullable integer columns to `meals_clients` so the SDNB allowance engine can store per-client mains and sides allowance values separately. The existing `units` column remains untouched.

---

## Context

The old billing system stored `mains` and `sides` as separate WordPress user meta fields on every user profile. Your plugin's `meals_clients` table has a single `units INT NULL` column that cannot represent separate mains/sides allowances. The invoice generator needs both values to compute billable vs overage quantities.

### Current schema excerpt (from `includes/class-schema.php`, lines 56–68):

```php
'units'                        => 'INT NULL',
'client_contribution'          => 'DECIMAL(10,2) NULL',
'vet_health_card'              => 'VARCHAR(50) NULL',
```

### Old system user meta fields being replaced:

| Old user meta key | Old type | New column | Purpose |
|---|---|---|---|
| `mains` | text (cast to float) | `allowance_mains` | Mains allowed per billing period |
| `sides` | text (cast to float) | `allowance_sides` | Sides allowed per billing period |
| `service` | text (`day`/`week`/`month`) | Already exists as `requisition_period` | Billing frequency |

---

## Step 1 — Add columns to canonical schema

**File:** `includes/class-schema.php`

**Location:** Inside the `MealsDB_Tables::CLIENTS` schema definition array, in the `'columns'` sub-array. Add the two new columns immediately after the existing `'units'` column (currently at line 56).

**Add these two entries after the `'units'` line:**

```php
'allowance_mains'              => 'INT NULL',
'allowance_sides'              => 'INT NULL',
```

The resulting block should read:

```php
'units'                        => 'INT NULL',
'allowance_mains'              => 'INT NULL',
'allowance_sides'              => 'INT NULL',
'client_contribution'          => 'DECIMAL(10,2) NULL',
```

Do NOT modify any other column definitions. Do NOT rename the `units` column.

---

## Step 2 — Add to client form allowed columns

**File:** `includes/class-client-form.php`

**Location:** The `$db_columns` static array (starts at line 31). Add the two new field names so form data can be persisted.

**Add these two entries after `'units'` (currently at line 89):**

```php
'allowance_mains',
'allowance_sides',
```

---

## Step 3 — Add field labels

**File:** `includes/class-client-form.php`

**Location:** The `$field_labels` static array (starts at line 140). Add labels for the new fields.

**Add these two entries after the `'units'` label (currently at line 198):**

```php
'allowance_mains'                => 'Mains Allowance',
'allowance_sides'                => 'Sides Allowance',
```

---

## Step 4 — Add UI fields to client form

**File:** `includes/class-admin-ui.php`

Find where the `units` field is rendered for the client form. Add two new number inputs immediately after it, gated to show only for SDNB and Veteran client types.

The new fields should follow the exact same pattern as the existing `units` field:

```html
<tr data-client-type="sdnb,veteran">
    <th><label for="allowance_mains">Mains Allowance</label></th>
    <td>
        <input type="number" name="allowance_mains" id="allowance_mains" min="0" class="regular-text"
               value="<?= esc_attr($data['allowance_mains'] ?? '') ?>" />
        <p class="description">Number of main meals allowed per billing period (per requisition period).</p>
    </td>
</tr>
<tr data-client-type="sdnb,veteran">
    <th><label for="allowance_sides">Sides Allowance</label></th>
    <td>
        <input type="number" name="allowance_sides" id="allowance_sides" min="0" class="regular-text"
               value="<?= esc_attr($data['allowance_sides'] ?? '') ?>" />
        <p class="description">Number of side items allowed per billing period (per requisition period).</p>
    </td>
</tr>
```

The `data-client-type` attribute ensures these fields are only visible when SDNB or Veteran is selected, matching the existing pattern used by other conditional fields.

---

## Step 5 — Schema sync will handle the ALTER TABLE

The existing `MealsDB_Schema_Sync` class compares the canonical schema definition (Step 1) to the live database and adds missing columns automatically. No manual ALTER TABLE statement is needed. The next time the plugin loads and sync runs, the two columns will be created.

Verify by checking `includes/class-schema-sync.php` — it calls `get_canonical_schema()` and compares against `SHOW COLUMNS FROM` results.

---

## Verification checklist

- [ ] `includes/class-schema.php` has `'allowance_mains' => 'INT NULL'` and `'allowance_sides' => 'INT NULL'` in the CLIENTS columns array, directly after `'units'`
- [ ] `includes/class-client-form.php` `$db_columns` array includes `'allowance_mains'` and `'allowance_sides'`
- [ ] `includes/class-client-form.php` `$field_labels` array includes labels for both new fields
- [ ] The client form UI renders two new number inputs for SDNB and Veteran clients
- [ ] No existing columns, constants, or table definitions were modified or renamed
- [ ] The `units` column remains unchanged
- [ ] The `requisition_period` column remains unchanged (it already serves as billing frequency)
