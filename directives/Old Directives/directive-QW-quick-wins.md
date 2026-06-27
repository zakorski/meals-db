# Directive QW (combined): Three safe quick wins — db-sync rate limit, draft fail-closed, negative-money CSV

**Audit reference:** recon-12/05 (QW-1, Q5.1/Q12.1), recon-11 (QW-2, Q11.1/MAJ-4), recon-07/12 (QW-3, Q7.1/Q12.3/MAJ-3). recon-14 §3 MAJ-3/4/8.
**Severity:** MAJOR (not launch blockers, but safe and worth landing immediately). **Scope:** small, three independent files + one test. **Risk:** LOW for all three. These touch NO allocation/billing core logic, so they can ship during the shadow trial without interacting with the LB fixes.

This directive bundles three unrelated low-risk fixes. They share nothing except being safe to do now — implement and test each independently.

---

## QW-1 — Add the missing rate limit to the DB-sync AJAX handler

**File:** `includes/ajax/class-ajax-db-sync.php`

### Problem
`run_phase()` (the `mealsdb_db_sync_phase` action) is the ONLY AJAX handler in the plugin without a `check_rate_limit` call. It has `check_ajax_referer` + `current_user_can('manage_options')` but then runs `set_time_limit(300)` and executes destructive migration phases 4/5 (`create_clients` / `create_rates`). An admin (or a nonce-bearing CSRF/XSS-assisted request) could loop these 300-second destructive phases unthrottled. The `migration_destructive` bucket (5/hour) exists precisely for this; every sibling migration handler already uses it.

### Pre-flight
```bash
sed -n '21,30p' includes/ajax/class-ajax-db-sync.php
grep -rn "migration_destructive" includes/ajax/class-ajax-migration.php   # confirm the bucket name + usage pattern
```
Expect `run_phase` with no `check_rate_limit`, and the sibling migration AJAX using `migration_destructive`.

### Fix
Add the rate-limit check immediately after the capability check, matching the established pattern:

```php
    public static function run_phase(): void {
        check_ajax_referer( 'mealsdb_db_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }

        // QW-1: throttle these 300s destructive phases like every other
        // migration handler. Mutating + expensive → fail-closed bucket.
        if ( class_exists( 'MealsDB_Rate_Limiter' )
            && ! MealsDB_Rate_Limiter::check_rate_limit( 'migration_destructive' ) ) {
            wp_send_json_error( [ 'message' => __( 'Rate limit exceeded.', 'meals-db' ) ], 429 );
        }

        set_time_limit( 300 );
        // ... unchanged ...
```

### Test
Confirm (manually or via the rate-limiter test harness) that the 6th call within an hour returns 429. No new test file strictly required, but extend the rate-limiter coverage if convenient.

### Acceptance
- [ ] `run_phase` calls `check_rate_limit('migration_destructive')` after the capability check, before any work.
- [ ] Exceeding the bucket returns 429 without running a phase.

---

## QW-2 — Make draft saving fail CLOSED, not open, on encryption failure

**File:** `includes/class-client-form.php` (`encode_draft_payload`)

### Problem
`encode_draft_payload()` encrypts the draft JSON, but its `catch` block returns the **plaintext** `$json` on any encryption exception (logging only a warning). So if the encryption key is missing/misconfigured, drafts silently persist full PII (individual_id, requisition_id, vet_health_card, names, addresses) as cleartext in `meals_drafts`. The main client-save path fail-CLOSES (aborts rather than storing plaintext); drafts must match — for a government-PII system, never write cleartext PII to the database.

### Pre-flight
```bash
sed -n '/function encode_draft_payload/,/^    }/p' includes/class-client-form.php
grep -n "encode_draft_payload\|save_draft" includes/class-client-form.php   # find the caller + how it handles a false return
```
Confirm the `catch` returns `$json` (plaintext), and check how the caller (`save_draft`) handles a `false`/failure return so the fail-closed path surfaces a clean error rather than a fatal.

### Fix
Return `false` (or throw, matching the caller's contract) on encryption failure instead of the plaintext:

```php
    private static function encode_draft_payload(array $data) {
        $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
        if (!is_string($json)) {
            return false;
        }
        if (!class_exists('MealsDB_Encryption')) {
            // No encryption available at all → refuse to persist PII as plaintext.
            // (QW-2: fail closed, matching the client-save path.)
            error_log('[MealsDB] Draft not saved: encryption unavailable; refusing to store PII as plaintext.');
            return false;
        }
        try {
            return MealsDB_Encryption::encrypt($json);
        } catch (\Throwable $e) {
            // QW-2: fail CLOSED. Never fall back to plaintext PII at rest.
            error_log('[MealsDB] Draft not saved: encryption failed (' . $e->getMessage() . '); refusing plaintext fallback.');
            return false;
        }
    }
```

Then ensure the caller surfaces this as a user-visible "draft could not be saved" rather than a silent success or a fatal. Check `save_draft` (or wherever `encode_draft_payload` is consumed): if it currently assumes a string, add a `false` guard that returns a `WP_Error` / `wp_send_json_error` with a clear message.

> **Note:** the previous behaviour was presumably motivated by "don't lose the user's draft work." The right trade for a PII system is: a draft that can't be encrypted is not saved, and the user is told. Losing an unsaved draft is preferable to writing government IDs in cleartext.

### Test
Add/extend a draft test: stub `MealsDB_Encryption::encrypt` to throw; assert `encode_draft_payload` returns `false` and that NO plaintext row is written. Also assert the legacy-plaintext DECODE path still works (reading old plaintext drafts must still succeed — only WRITING plaintext is forbidden).

### Acceptance
- [ ] `encode_draft_payload` returns `false` (never plaintext) when encryption is unavailable or fails.
- [ ] The caller surfaces a clean "draft not saved" error.
- [ ] Decoding existing legacy-plaintext drafts still works (read path unchanged).

---

## QW-3 — Stop corrupting legitimate negative money in CSV exports

**Files:** `includes/services/class-csv.php` (PHP), `assets/js/report-utils.js` (JS), `tests/test-reports-csv-injection.php` (test).

### Problem
The CSV formula-injection guard prefixes any cell starting with `=`, `+`, `-`, `@`, tab, or CR with a single quote. `MealsDB_Money::format` emits a leading `-` for negative amounts, so a legitimate `-10.24` in a numeric/money column becomes `'-10.24` — corrupting government-bound CSVs. The bug exists in BOTH `MealsDB_CSV::cell()` (PHP) and `report-utils.js` `csvCell` (JS), and `test-reports-csv-injection.php` currently asserts the corruption as correct (it uses `first_name => '-2'` and asserts the `-` is prefixed — but that's a TEXT field; the bug is about NUMERIC fields).

### The correct distinction
The fix is NOT "stop prefixing `-`" universally — a leading `-` in a TEXT field (a name, a note) should still be neutralised, because `-2+3` typed into a name field is still a formula-injection vector. The fix is: **exempt genuine numeric values** (a value that is a well-formed number) from the guard, while still neutralising `-` (and the other triggers) when it leads a non-numeric string.

A value is "genuine numeric" if it matches a strict numeric pattern: optional leading `-`, digits, optional single decimal point, digits, and nothing else (no `=`, `+`, `@`, spaces, or a second operator). `-10.24` qualifies; `-2+3`, `-=1`, `-1-1` do not.

### Pre-flight
```bash
sed -n '/function cell/,/^    }/p' includes/services/class-csv.php
grep -n "FORMULA_TRIGGERS\|csvCell" assets/js/report-utils.js
grep -n "'-2'\|'-\|negative\|assert" tests/test-reports-csv-injection.php
```

### Fix — PHP (`MealsDB_CSV::cell`)
Add a numeric exemption before the trigger check:

```php
    public static function cell($value): string {
        if ($value === null || $value === false) {
            return '';
        }

        $string = is_scalar($value) ? (string) $value : '';

        // QW-3: a well-formed number (incl. negative money like -10.24) is NOT
        // a formula-injection vector — exempt it from the leading-char guard so
        // negative amounts aren't corrupted into text ('-10.24) in numeric
        // columns. A leading '-' in a NON-numeric string is still neutralised.
        $is_plain_number = ($string !== '') && preg_match('/^-?\d+(\.\d+)?$/', $string) === 1;

        if (!$is_plain_number
            && $string !== ''
            && in_array($string[0], self::FORMULA_TRIGGERS, true)) {
            $string = "'" . $string;
        }

        if (strpbrk($string, ",\"\r\n") !== false) {
            $string = '"' . str_replace('"', '""', $string) . '"';
        }

        return $string;
    }
```

> Leave `cell_strict()` as-is (it's the aggressive variant for fields that should never contain formulas — it's correct to strip there). Only `cell()` (the default money/data path) needs the numeric exemption. Confirm during pre-flight that money columns route through `cell()`, not `cell_strict()`.

### Fix — JS (`report-utils.js` `csvCell`)
Mirror the exemption:

```javascript
    var FORMULA_TRIGGERS = '=+-@\t\r';
    var NUMERIC_RE = /^-?\d+(\.\d+)?$/;   // QW-3
    function csvCell(value) {
        if (value === null || value === undefined) {
            return '';
        }
        var str = String(value);
        // QW-3: exempt well-formed numbers (incl. negative money) from the
        // leading-char guard; a leading '-' in a non-numeric string is still neutralised.
        if (str.length && !NUMERIC_RE.test(str) && FORMULA_TRIGGERS.indexOf(str.charAt(0)) !== -1) {
            str = "'" + str;
        }
        // ... existing quoting (comma/quote/newline) unchanged ...
    }
```

### Fix — the test (`test-reports-csv-injection.php`)
The current test enshrines the bug. Update it to assert the CORRECT behaviour:
1. KEEP the text-field injection assertions (a name like `=1+1+cmd`, `+2+2`, `@SUM`, and a NAME that is literally `-2+3` must still be prefixed) — these are real injection vectors and must stay neutralised.
2. CHANGE/ADD numeric assertions: a money cell of `-10.24` (and `-2` if it represents a numeric amount) must appear UNPREFIXED in a numeric column. Add a money/amount column to the fixture and assert `assert_not_contains("'-10.24", $csv)` and `assert_contains("-10.24", $csv)`.
3. Be explicit in a comment that the distinction is numeric-value vs text-field.

> Note: the existing fixture uses `first_name => '-2'`. A NAME of `-2` is text and SHOULD stay prefixed — keep that assertion. Add a SEPARATE numeric/amount cell to prove the exemption. This keeps both guarantees: text injection neutralised, numeric money preserved.

### Acceptance
- [ ] `MealsDB_CSV::cell()` exempts well-formed numbers from the leading-char guard; `cell_strict()` unchanged.
- [ ] `report-utils.js` `csvCell` mirrors the exemption.
- [ ] Negative money (`-10.24`) exports unprefixed in numeric columns, in both PHP and JS paths.
- [ ] Text-field injection (`=`, `+`, `@`, and `-` leading a non-numeric string) is still neutralised in both paths.
- [ ] `test-reports-csv-injection.php` asserts the correct behaviour (text injection prefixed; numeric money not), no longer enshrining the bug.

---

## Cross-cutting notes

- All three are independent of each other and of the LB directives. They can land in any order, individually, during the shadow trial.
- QW-3 is the one with a subtlety (numeric-vs-text distinction) — get the regex and the test right; the goal is "preserve money, still block injection," not "stop prefixing `-`."
- After all three ship, update the corresponding CLAUDE.md notes: §8 (db-sync rate limit gap → resolved), §4 (draft fail-open → resolved), §14 (negative-money CSV → resolved, both files).

## Relationship to other directives
- QW-2's fail-closed aligns with the encryption discipline in CLAUDE.md §4 (all PII write paths fail closed).
- QW-3 must fix BOTH the PHP and JS exporters (recon-12 found the JS duplicates the bug); a partial fix leaves one export path corrupting.
- None of these depend on or block the LB fixes.
