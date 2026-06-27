# Directive GUI-RATE-1 (SURGICAL) — Remove the "Rate" form field + filter the create path

## HOW TO EXECUTE THIS DIRECTIVE — READ THIS FIRST
- This is a list of **6 surgical edits**. Do EXACTLY these 6 and nothing else.
- For EACH edit: use the `read` tool to open the named file, locate the EXACT `FIND` block shown
  (it is copied verbatim from the current source), and apply the change. Do NOT retype or
  regenerate any surrounding code. Do NOT reformat. Do NOT "clean up" nearby lines.
- **Never reconstruct a function, array, or file from memory.** If a FIND block does not match the
  file verbatim, STOP and report the mismatch — do not improvise a replacement.
- Each FIND block below was chosen to be UNIQUE in its file (some include neighbor lines precisely
  because the target line alone is not unique — preserve those neighbors).
- Expected total change: 5 small deletions + 1 small insertion across 3 files. If you find yourself
  editing more than that, or rewriting a whole method, you have gone off-track — STOP.
- After the edits, run the test commands at the bottom. Do not edit anything else to make tests pass
  without reporting first.

**Why:** the form collects a `rate` field, but `meals_clients` has NO `rate` column. On create it
hits `$wpdb->insert` -> "Unknown column 'rate'" -> INSERT rejected -> mislabeled "Rate invalid or too
long" (the F-R3.1 create failure). Edit silently drops it via an existing filter. Fix: remove the
field; add the same filter to create. KEEP `delivery_fee` and `client_contribution` (real columns).

---

## EDIT 1 — remove the Rate row from the form
**File:** `includes/class-admin-ui.php`
**FIND (verbatim, 4 lines):**
```
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="rate"><?php esc_html_e('Rate *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="rate" id="rate" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['rate'] ?? ''); ?>" /></td>
                </tr>
```
**ACTION:** delete all 4 lines.

---

## EDIT 2 — remove `rate` from the sanitized field list
**File:** `includes/class-client-form.php`
**FIND (verbatim, 3 lines — the `'payment_method',` neighbor makes this unique vs the other `'rate',`):**
```
        'payment_method',
        'rate',
        'client_contribution',
```
**REPLACE WITH:**
```
        'payment_method',
        'client_contribution',
```
(i.e. delete the middle `'rate',` line only.)

---

## EDIT 3 — remove the `rate` label mapping
**File:** `includes/class-client-form.php`
**FIND (verbatim, 1 line — unique):**
```
        'rate'                           => 'Rate',
```
**ACTION:** delete the line.

---

## EDIT 4 — remove the `rate` range-validation entry
**File:** `includes/class-client-form.php`
**FIND (verbatim, 1 line — unique):**
```
            'rate' => ['min' => 0, 'max' => 10000, 'message' => 'Rate must be between $0 and $10,000.'],
```
**ACTION:** delete the line.

---

## EDIT 5 — remove `rate` from the required-fields list
**File:** `includes/class-client-form.php`
**FIND (verbatim, 3 lines — the `'requisition_period',` neighbor makes this unique vs the other `'rate',`):**
```
                'requisition_period',
                'rate',
                'payment_method',
```
**REPLACE WITH:**
```
                'requisition_period',
                'payment_method',
```
(i.e. delete the middle `'rate',` line only.)

---

## EDIT 6 — add the column filter to the create path (the durable fix)
**File:** `includes/services/class-clients-repository.php`
**FIND (verbatim, 4 lines — inside `create_client`, just before the insert):**
```
        self::$last_failed_column = null;

        try {
            $result = $wpdb->insert($this->table_name, $data);
```
**REPLACE WITH:**
```
        self::$last_failed_column = null;

        // Parity with update_client(): drop any keys that aren't real columns
        // (e.g. the removed 'rate' field) so an unknown column can never cause
        // an INSERT rejection. filter_to_known_columns logs anything it drops.
        $data = self::filter_to_known_columns($data);

        try {
            $result = $wpdb->insert($this->table_name, $data);
```
**NOTE:** `filter_to_known_columns` already exists in this same file (it's a `private static`
method used by `update_client`). Do NOT create a new one. Do NOT modify it. Just call it.

---

## VERIFICATION (run after the 6 edits)
```bash
cd <plugin-root>
# 1. rate field fully gone from the two PHP files (expect: no output, or only comments):
grep -rn "name=.rate.\|'rate' *=>\|^[[:space:]]*'rate',\|=> *'Rate'" includes/class-admin-ui.php includes/class-client-form.php
# 2. create now filters (expect: a filter_to_known_columns call inside create_client AND update's):
grep -n "filter_to_known_columns" includes/services/class-clients-repository.php
# 3. full test suite:
php tests/test-*.php   # or the project's test runner; expect all green
```
**Manual GUI check (F-R3.1):** create a new client of each type (Private, SDNB, Veteran) with valid
data — each must SAVE (no "Rate invalid or too long", no draft-only). The Rate field must be absent
from the add/edit form. `delivery_fee` and `client_contribution` must still be present and save.

## DO NOT
- Do not touch `delivery_fee` or `client_contribution` anywhere.
- Do not modify `filter_to_known_columns` itself.
- Do not change `update_client`.
- Do not regenerate `render_client_form`, the field arrays, or `create_client` — edit only the
  exact blocks above.
- Do not address rate RESOLUTION here (that's GUI-RATE-2). This directive only removes the field
  and stops the crash.
