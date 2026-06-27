# Directive GUI-FORM-TIDY (SURGICAL, v438) — Hide unused fields + reorganize + unblock Veteran create

## HOW TO EXECUTE — READ FIRST
- This is a list of discrete edits in `includes/class-admin-ui.php` (the client form) plus the
  Veteran requisition fix. Do EXACTLY these edits, nothing else.
- For each: `read` the file, find the EXACT verbatim FIND block, apply the change. NEVER regenerate
  `render_client_form`, a field-group array, or a render closure from memory. If a FIND block does
  not match verbatim, STOP and report.
- The form is built from field-group arrays (`$identity_fields`, `$service_delivery_fields`,
  `$requisition_fields`, `$sdnb_program_fields`, etc.), each a list of anonymous
  `static function (array $client) { ?> ...html... <?php }` render closures, rendered by
  `render_field_group()`. "Hide a field" = remove its closure (or its `<tr>` within a shared
  closure). "Move a field" = move its closure between arrays. Preserve the `static function(){...}`
  wrapper exactly when moving.
- All changes are PRESENTATION ONLY. No DB columns dropped, no data changed.
- Presentation note: PHP files in this repo render to text/markdown for review; the actual change
  is to the named .php file on disk.

**Scope:** (A) hide 8 unused fields; (B) reorganize sections incl. a new Case Management group;
(C) make `requisition_period` visible to Veterans (they require it but the row was SDNB-gated).

KEEP (do NOT remove): Gender, Birth Date, delivery_fee, client_contribution, allowance_mains,
allowance_sides. Only the 8 fields named in Part A are hidden.

---

## PART A — HIDE 8 FIELDS (remove each field's render closure / its `<tr>`)

All verified to have no consumer beyond passthrough lists. Columns stay; only the form rows go.

### A-1: # of Units (`units`) — remove ONLY its `<tr>` from the shared units+allowances closure
**File:** `includes/class-admin-ui.php`
This `<tr>` is the FIRST row in a closure that ALSO renders Mains/Sides Allowance (which you KEEP).
Remove only the units `<tr>` (3 lines), leave the rest of the closure intact.
**FIND (verbatim, 4 lines):**
```
                <tr data-client-type="sdnb,veteran" data-required-for="sdnb,veteran">
                    <th><label for="units"><?php esc_html_e('# of Units *', 'meals-db'); ?></label></th>
                    <td><input type="number" name="units" id="units" class="small-text" min="1" max="31" data-base-required="1" value="<?php echo esc_attr($client['units'] ?? ''); ?>" /></td>
                </tr>
```
**ACTION:** delete those 4 lines. (The Mains/Sides Allowance `<tr>`s immediately after stay.)
**NOTE:** `units` is NOT in any required-fields list in class-client-form.php (verified — line 91 is
the sanitize list, not the required set; the only validation is a range check that fires only if a
value is present). So removing the row fully de-requires it. No client-form.php change needed for units.

### A-2: Freezer Capacity (`freezer_capacity`)
**File:** `includes/class-admin-ui.php`
**FIND** the render closure whose `<tr>` contains:
```
                    <td><input type="text" name="freezer_capacity" id="freezer_capacity" class="regular-text" value="<?php echo esc_attr($client['freezer_capacity'] ?? ''); ?>" /></td>
```
**ACTION:** remove that field's ENTIRE closure (the `static function (array $client) { ?> ... <?php },`
block that renders the Freezer Capacity `<tr>` — including its `<tr>`, the `<th>` label line above
this `<td>`, and the wrapping `static function`/`},`). Read the surrounding lines to capture the full
closure; remove it whole.

### A-3: Service Name Zone (field name is `service_zone`)
**File:** `includes/class-admin-ui.php`
**FIND** the closure rendering:
```
                    <th><label for="service_zone"><?php esc_html_e('Service Name Zone', 'meals-db'); ?></label></th>
```
**ACTION:** remove that entire render closure (the `static function (...) use (...) { ?> ... <?php },`
that renders the Service Name Zone row — note it has a `use (...)` clause; remove the whole closure).

### A-4: Meal Type (`meal_type`)
**File:** `includes/class-admin-ui.php`
**FIND** the closure containing:
```
                        <select name="meal_type" id="meal_type">
```
**ACTION:** remove that field's entire render closure.

### A-5: Per SDNB Requirement (`per_sdnb_req`)
**File:** `includes/class-admin-ui.php`
**FIND (verbatim, 2 lines):**
```
                    <th><label for="per_sdnb_req"><?php esc_html_e('Per SDNB Requirement', 'meals-db'); ?></label></th>
                    <td><textarea name="per_sdnb_req" id="per_sdnb_req" rows="3" class="large-text"><?php echo esc_textarea($client['per_sdnb_req'] ?? ''); ?></textarea></td>
```
**ACTION:** remove this field's entire render closure (the `static function`/`},` wrapping these lines).

### A-6: Expected Termination Date (`expected_termination_date`)
**File:** `includes/class-admin-ui.php`
**FIND (verbatim):**
```
                    <td><input type="date" name="expected_termination_date" id="expected_termination_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['expected_termination_date'] ?? ''); ?>" /></td>
```
**ACTION:** remove this field's entire render closure.

### A-7: Initial Renewal Termination Date (`initial_renewal_date`)
**File:** `includes/class-admin-ui.php`
**FIND (verbatim):**
```
                    <td><input type="date" name="initial_renewal_date" id="initial_renewal_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['initial_renewal_date'] ?? ''); ?>" /></td>
```
**ACTION:** remove this field's entire render closure.

### A-8: Most Recent Renewal Termination Date (`most_recent_renewal_date`)
**File:** `includes/class-admin-ui.php`
**FIND (verbatim):**
```
                    <td><input type="date" name="most_recent_renewal_date" id="most_recent_renewal_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['most_recent_renewal_date'] ?? ''); ?>" /></td>
```
**ACTION:** remove this field's entire render closure.

**After Part A, the `$requisition_fields` and `$sdnb_program_fields` groups will be shorter. Do not
touch any field not named above** (e.g. service_commence_date, termination_date STAY).

---

## PART B — REORGANIZE

### B-1: Gender + Birth Date → show for ALL types
Currently each is gated `data-client-type="sdnb,veteran"`. Change BOTH to all types.
**File:** `includes/class-admin-ui.php`
**B-1a — Birth Date FIND (verbatim):**
```
                <tr data-client-type="sdnb,veteran">
                    <th><label for="birth_date"><?php esc_html_e('Birth Date', 'meals-db'); ?></label></th>
```
**REPLACE WITH:**
```
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="birth_date"><?php esc_html_e('Birth Date', 'meals-db'); ?></label></th>
```
**B-1b — Gender:** find the Gender row's wrapping `<tr>` (the closure rendering the radio buttons
`name="gender"`). Its `<tr>` opening tag carries the client-type gating. Change that `<tr>`'s
`data-client-type` to include `private` (i.e. `sdnb,veteran,private`). Read the closure to find the
exact `<tr ...>` line for Gender and add `,private` to its data-client-type. If Gender's `<tr>` has
NO data-client-type attribute (shows for all already), leave it. Report which case you found.

### B-2: Social Worker Name + Email → new "Case Management" group (sdnb,veteran)
These two closures are currently in `$identity_fields`, gated `data-client-type="sdnb,veteran"`.
Move them into a NEW field-group array rendered as "Case Management".

**B-2a — create the group.** **FIND (verbatim — the start of `$requisition_fields`):**
```
        $requisition_fields = [
            '__attributes' => 'data-client-type="sdnb"',
```
**INSERT IMMEDIATELY BEFORE it:**
```
        $case_management_fields = [
            '__attributes' => 'data-client-type="sdnb,veteran"',
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="assigned_social_worker"><?php esc_html_e('Social Worker Name', 'meals-db'); ?></label></th>
                    <td><input type="text" name="assigned_social_worker" id="assigned_social_worker" class="regular-text" value="<?php echo esc_attr($client['assigned_social_worker'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="social_worker_email"><?php esc_html_e('Social Worker Email Address', 'meals-db'); ?></label></th>
                    <td><input type="email" name="social_worker_email" id="social_worker_email" class="regular-text" value="<?php echo esc_attr($client['social_worker_email'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
        ];

```

**B-2b — remove the two Social Worker closures from `$identity_fields`.** They currently look like
this inside `$identity_fields` (each wrapped in its own `static function`). FIND the
`assigned_social_worker` closure:
```
                <tr data-client-type="sdnb,veteran">
                    <th><label for="assigned_social_worker"><?php esc_html_e('Social Worker Name', 'meals-db'); ?></label></th>
                    <td><input type="text" name="assigned_social_worker" id="assigned_social_worker" class="regular-text" value="<?php echo esc_attr($client['assigned_social_worker'] ?? ''); ?>" /></td>
                </tr>
```
and the `social_worker_email` closure:
```
                <tr data-client-type="sdnb,veteran">
                    <th><label for="social_worker_email"><?php esc_html_e('Social Worker Email Address', 'meals-db'); ?></label></th>
                    <td><input type="email" name="social_worker_email" id="social_worker_email" class="regular-text" value="<?php echo esc_attr($client['social_worker_email'] ?? ''); ?>" /></td>
                </tr>
```
**ACTION:** remove BOTH closures (the full `static function (array $client) { ?> ... <?php },` for
each) from `$identity_fields`. They are now in `$case_management_fields` (B-2a).

**B-2c — render the new group.** **FIND (verbatim):**
```
                    self::render_field_group(__('Service & Delivery', 'meals-db'), $service_delivery_fields, $client);
                    self::render_field_group(__('Requisition Details (SDNB)', 'meals-db'), $requisition_fields, $client);
```
**REPLACE WITH:**
```
                    self::render_field_group(__('Service & Delivery', 'meals-db'), $service_delivery_fields, $client);
                    self::render_field_group(__('Case Management', 'meals-db'), $case_management_fields, $client);
                    self::render_field_group(__('Requisition Details (SDNB)', 'meals-db'), $requisition_fields, $client);
```

**NOTE on allowances:** Mains/Sides Allowance currently render in `$identity_fields` (the closure you
edited in A-1). The original directive intent was to move them to Service & Delivery. This is OPTIONAL
and cosmetic — if moving them is risky/fiddly, LEAVE them where they render now (they already work and
are gated sdnb,veteran). Do NOT break them to relocate them. If you do move them, move the two
allowance `<tr>`s into a closure in `$service_delivery_fields` gated `sdnb,veteran`. Report whether
you moved them or left them.

---

## PART C — make `requisition_period` visible to Veterans (unblock Veteran create)

Veterans REQUIRE `requisition_period` (it's in the VETERAN required list in client-form.php) but the
row only renders for SDNB (the `$requisition_fields` group is gated `data-client-type="sdnb"`), so a
Veteran create can't satisfy it. Operator confirms Veterans DO have a requisition period. Fix: render
`requisition_period` for both sdnb and veteran. Because the rest of `$requisition_fields` is SDNB-only,
move ONLY the requisition_period closure to a both-types-visible spot.

**C-1 — remove requisition_period from the SDNB-only requisition group.** FIND its closure in
`$requisition_fields` (the `static function (array $client) use ($requisition_period_value) { ... }`
rendering the `<select name="requisition_period">`). Read it fully (it spans the select + its options)
and CUT it (you'll paste it in C-2).

**C-2 — add it to Case Management** (which is gated sdnb,veteran — perfect, Veterans see it).
Paste the requisition_period closure you cut in C-1 as an ADDITIONAL entry inside
`$case_management_fields` (after the social worker closures, before the closing `];`). The closure's
internal `<tr>` does not need a data-client-type (the group's `__attributes` gates it to sdnb,veteran).
Keep the `use ($requisition_period_value)` clause intact.

**ALTERNATIVE (simpler, if moving the closure is error-prone):** instead of moving it, change the
`$requisition_fields` group attribute from `data-client-type="sdnb"` to `data-client-type="sdnb,veteran"`
— BUT only if Part A already removed the SDNB-specific date fields (expected/initial/most-recent
renewal) from that group, so Veterans don't also get SDNB-only fields. Since service_commence_date and
termination_date REMAIN in that group and are arguably SDNB-specific, the MOVE (C-1/C-2) is cleaner.
Prefer the move. If you use the alternative, report it.

---

## VERIFICATION
```bash
cd <plugin-root>
# Part A: the 8 fields gone from the form file:
for f in units meal_type freezer_capacity service_zone per_sdnb_req expected_termination_date initial_renewal_date most_recent_renewal_date; do
  echo -n "$f: "; grep -c "name=\"$f\"" includes/class-admin-ui.php
done   # all should be 0
# Part B: gender/birth_date all-types; Case Management group + render call exist:
grep -n "case_management_fields\|Case Management" includes/class-admin-ui.php
grep -n "birth_date" includes/class-admin-ui.php   # its <tr> now sdnb,veteran,private
# Part C: requisition_period reachable by veteran:
grep -n "requisition_period" includes/class-admin-ui.php
php tests/test-*.php   # all green
```
**Manual GUI (critical):**
- The 8 fields no longer appear on add/edit for any type.
- Gender + Birth Date appear for a PRIVATE client.
- A "Case Management" section shows Social Worker Name/Email for SDNB and Veteran (NOT Private).
- **Create a new VETERAN client end to end — it must SAVE** (requisition_period is now fillable).
- Create a new SDNB and Private client — both still save.
- Editing an existing client still saves; existing data intact.

## DO NOT
- Do not drop any DB column or touch class-schema.php.
- Do not remove Gender, Birth Date, allowances, delivery_fee, client_contribution.
- Do not regenerate render_client_form or any whole field-group array — edit only the named closures.
- Do not change required-field logic except as a side effect of removing the units row (which is not
  in the required set anyway).
- If any FIND block doesn't match verbatim, STOP and report rather than reconstructing.
