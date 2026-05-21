# Phase E — Error Detection & New User Flags

## Objective

Add validation logic to both the SDNB and VAC invoice generators that flags missing required fields, duplicate clients, and newly registered users — matching the old system's error reporting that appeared in the `Errors` and `New User flag` CSV columns.

---

## Context

Both the SDNB Legacy CSV and VAC CSV have `Errors` and `New User flag` columns at the end of each row. The current implementation outputs empty strings for errors and hardcoded `'No'` for the new user flag. The old system checked for:

1. **Missing service ID** — SDNB only (column `meals_clients.service_id`)
2. **Missing requisition ID** — SDNB only (column `meals_clients.requisition_id`)
3. **Missing individual ID** — both SDNB and VAC (column `meals_clients.individual_id`)
4. **Duplicate individual IDs** — SDNB: `> 2` occurrences across all clients; VAC: `> 1` occurrence within Veteran clients
5. **New user flag** — user's WordPress registration date falls within the billing period

### Important: encrypted fields

In your plugin, `individual_id` and `requisition_id` are encrypted in `meals_clients`. The `individual_id_index` column (a SHA-256 hash) is used for duplicate detection. The `service_id` column is NOT encrypted.

---

## Step 1 — Add a private static validation method

**File:** `includes/services/class-invoice-generator.php`

Add this method after `split_into_invoice_lines()`:

```php
/**
 * Validate a client row and return error messages.
 *
 * @param array  $client       Client row from meals_clients.
 * @param string $client_type  'SDNB' or 'Veteran'.
 * @param array  $duplicate_counts Map of individual_id_index => count of clients sharing that index.
 * @param int    $duplicate_threshold How many is too many (SDNB = 2, Veteran = 1).
 * @return string Comma-separated error messages, or 'No' if none.
 */
private static function validate_client_row(
    array $client,
    string $client_type,
    array $duplicate_counts,
    int $duplicate_threshold = 2
): string {
    $errors = [];

    // Missing field checks.
    if ($client_type === 'SDNB') {
        if (empty($client['service_id'])) {
            $errors[] = 'Missing service ID';
        }
        if (empty($client['requisition_id'])) {
            $errors[] = 'Missing requisition ID';
        }
    }

    if (empty($client['individual_id'])) {
        $errors[] = 'Missing individual ID';
    }

    // Duplicate check via deterministic index.
    $id_index = $client['individual_id_index'] ?? '';
    if ($id_index !== '' && isset($duplicate_counts[$id_index]) && $duplicate_counts[$id_index] > $duplicate_threshold) {
        $errors[] = 'Duplicate person';
    }

    return !empty($errors) ? implode(', ', $errors) : 'No';
}
```

---

## Step 2 — Add a private static method for new user flag

```php
/**
 * Check if a WordPress user was created during the billing period.
 *
 * @param int    $wp_user_id  WordPress user ID.
 * @param string $start_date  Y-m-d.
 * @param string $end_date    Y-m-d.
 * @return string Flag text or empty string.
 */
private static function check_new_user_flag(int $wp_user_id, string $start_date, string $end_date): string {
    if ($wp_user_id <= 0) {
        return '';
    }

    $user = get_userdata($wp_user_id);
    if (!$user || empty($user->user_registered)) {
        return '';
    }

    $registered = new DateTime($user->user_registered);
    $period_start = new DateTime($start_date);
    $period_end   = new DateTime($end_date);

    if ($registered >= $period_start && $registered <= $period_end) {
        return 'New - account - user created on ' . $registered->format('Y-m-d');
    }

    return '';
}
```

---

## Step 3 — Pre-compute duplicate counts for SDNB

In `generate_sdnb_legacy()`, after the client query returns `$client_rows` and before calling `get_allowance_data_for_clients()`, add the duplicate count computation:

```php
// Pre-compute duplicate individual_id counts for error checking.
$sdnb_duplicate_counts = [];
foreach ($client_rows as $c) {
    $idx = $c['individual_id_index'] ?? '';
    if ($idx !== '') {
        if (!isset($sdnb_duplicate_counts[$idx])) {
            $sdnb_duplicate_counts[$idx] = 0;
        }
        $sdnb_duplicate_counts[$idx]++;
    }
}
```

**Important:** The SQL query in `generate_sdnb_legacy()` must also SELECT `individual_id_index`. Add it to the SELECT clause:

```sql
SELECT client_id, wp_user_id, first_name, last_name, service_id, requisition_id,
       individual_id, individual_id_index, client_contribution, delivery_area_zone,
       default_rate_id, allowance_mains, allowance_sides, requisition_period
FROM meals_clients
WHERE ...
```

Then in the `get_allowance_data_for_clients()` method (Phase B), ensure `individual_id_index` is passed through in the result rows. It's already in the `$client` array since the method returns `'client' => $client`, and `$client` is the full row from the query.

---

## Step 4 — Add error/flag columns to SDNB invoice lines

In `generate_sdnb_legacy()`, inside the loop that calls `split_into_invoice_lines()`, compute errors and new user flag per client (not per line — all lines for the same client share the same flags):

Replace the existing loop:

```php
$all_invoice_lines = [];
foreach ($invoice_rows as $row) {
    $lines = self::split_into_invoice_lines($row);
    foreach ($lines as $line) {
        $all_invoice_lines[] = $line;
    }
}
```

With:

```php
$all_invoice_lines = [];
foreach ($invoice_rows as $row) {
    $client = $row['client'];
    $error_string  = self::validate_client_row($client, 'SDNB', $sdnb_duplicate_counts, 2);
    $new_user_flag = self::check_new_user_flag((int) ($client['wp_user_id'] ?? 0), $start_date, $end_date);

    $lines = self::split_into_invoice_lines($row);
    foreach ($lines as $line) {
        $line['errors']        = $error_string;
        $line['new_user_flag'] = $new_user_flag;
        $all_invoice_lines[]   = $line;
    }
}
```

Note: The government CSV format (Electronic Invoice Datasheet v36e) does NOT have error/flag columns. These are informational columns that appeared in the old Part 1 diagnostic CSV. If you want them in the government CSV output, you would need to add them. Otherwise, they serve as data available for a separate diagnostic report. For now, they are computed and attached to the line data but not added to the CSV rows.

---

## Step 5 — Add error/flag columns to VAC CSV

In `generate_vac_csv()`, the `$errors` variable (around line 624) is currently always empty. The `'No'` new user flag is hardcoded at line 664.

### 5.1 Add `individual_id_index` to the VAC client query

Update the SQL SELECT in `generate_vac_csv()` to include `individual_id_index`:

```sql
SELECT client_id, wp_user_id, first_name, last_name, requisition_id,
       vet_health_card, requisition_period, client_contribution, default_rate_id,
       apartment_number, street_number, street_name, city, postal_code, client_phone_1,
       allowance_mains, allowance_sides, individual_id, individual_id_index
FROM meals_clients
WHERE client_type = ? AND active = 1 AND wp_user_id > 0
```

### 5.2 Pre-compute Veteran duplicate counts

After the client query returns `$client_rows`, add:

```php
$vet_duplicate_counts = [];
foreach ($client_rows as $c) {
    $idx = $c['individual_id_index'] ?? '';
    if ($idx !== '') {
        if (!isset($vet_duplicate_counts[$idx])) {
            $vet_duplicate_counts[$idx] = 0;
        }
        $vet_duplicate_counts[$idx]++;
    }
}
```

### 5.3 Replace the error and new user flag assignments

In the per-veteran loop, replace:

```php
$errors = '';
```

With:

```php
$errors = self::validate_client_row($vet, 'Veteran', $vet_duplicate_counts, 1);
```

And replace:

```php
'No' // New user flag
```

With:

```php
self::check_new_user_flag((int) ($vet['wp_user_id'] ?? 0), $start_date, $end_date) ?: 'No'
```

Note the VAC duplicate threshold is `1` (flags when more than 1 Veteran shares the same `individual_id_index`), vs SDNB which uses `2`.

---

## Verification checklist

- [ ] `validate_client_row()` is a private static method on `MealsDB_Invoice_Generator`
- [ ] `check_new_user_flag()` is a private static method on `MealsDB_Invoice_Generator`
- [ ] Both use `get_userdata()` (WordPress function) for user registration date — NOT direct database queries
- [ ] Duplicate detection uses `individual_id_index` (deterministic hash column) — NOT decrypting `individual_id`
- [ ] SDNB duplicate threshold is `> 2` (matches old system's `$individual_count > 2`)
- [ ] VAC duplicate threshold is `> 1` (matches old `vet-invoice.php` check)
- [ ] SDNB checks for missing `service_id`, `requisition_id`, and `individual_id`
- [ ] VAC checks for missing `individual_id` only
- [ ] The SQL queries for both SDNB and VAC now SELECT `individual_id_index`
- [ ] VAC CSV `Errors` column is now populated (was empty string)
- [ ] VAC CSV `New User flag` column is now dynamic (was hardcoded `'No'`)
- [ ] No new files, classes, or database tables were created
