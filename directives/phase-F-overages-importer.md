# Phase F — Overages Import Pipeline

## Objective

Create an admin page that generates overage data directly from the allowance engine (no CSV round-trip), previews clients with non-zero overages, and creates WooCommerce orders for those overages using the standard WC CRUD API. This replaces both the old `overages-importer` and `vet-overages` plugins.

---

## Context

The old system required a manual workflow: generate the month-end CSV → download it → upload it to a separate plugin → preview → confirm → create orders. Your system can skip the CSV round-trip entirely because the allowance engine (Phase B) already computes overage quantities. The overages importer just needs to read those values and create WC orders.

### Old system's overage product IDs

The old system used three hardcoded WooCommerce product IDs for overage items:

| Product ID | Purpose |
|---|---|
| 5056 | Overage Main |
| 5059 | Overage Non-Taxable Side |
| 5180 | Overage Taxable Side |

**CRITICAL:** These product IDs are site-specific and may differ on your installation. The importer MUST look up overage products from `meals_products` by `product_type` rather than hardcoding IDs. However, you need to define which products are "overage" products. The recommended approach is to add a convention: overage products should have a specific naming pattern or a flag in `meals_products`.

For now, use configurable product IDs stored as a WordPress option that the admin can set. This avoids both hardcoding and requiring a schema change.

---

## Step 1 — Add overage product ID settings

**File:** `includes/services/class-invoice-generator.php`

Add a static helper method that reads overage product IDs from a WordPress option:

```php
/**
 * Get WooCommerce product IDs for overage order items.
 *
 * These are stored as a WordPress option so admins can configure them
 * without code changes. Defaults match the legacy system.
 *
 * @return array{mains: int, taxable_sides: int, nontax_sides: int}
 */
public static function get_overage_product_ids(): array {
    $defaults = [
        'mains'         => 5056,
        'taxable_sides' => 5180,
        'nontax_sides'  => 5059,
    ];

    $saved = get_option('mealsdb_overage_product_ids', []);
    if (!is_array($saved)) {
        $saved = [];
    }

    return [
        'mains'         => (int) ($saved['mains'] ?? $defaults['mains']),
        'taxable_sides' => (int) ($saved['taxable_sides'] ?? $defaults['taxable_sides']),
        'nontax_sides'  => (int) ($saved['nontax_sides'] ?? $defaults['nontax_sides']),
    ];
}
```

Add a settings field in `views/settings.php` (or wherever the plugin's settings page renders) to let the admin configure these three product IDs. The settings should be saved under the WordPress option key `mealsdb_overage_product_ids` as an associative array.

---

## Step 2 — Add overages data generation methods

**File:** `includes/services/class-invoice-generator.php`

### 2.1 SDNB overages

```php
/**
 * Get SDNB clients with non-zero overages for a billing period.
 *
 * @param string $zone           Zone code (M or S).
 * @param string $start_date     Y-m-d.
 * @param string $end_date       Y-m-d.
 * @param int    $weeks_in_month Number of Wednesdays.
 * @return array Rows with overage quantities per client.
 */
public static function get_sdnb_overages(string $zone, string $start_date, string $end_date, int $weeks_in_month = 4): array {
    $clients_table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS));
    $sql = sprintf(
        'SELECT client_id, wp_user_id, first_name, last_name, service_id, requisition_id,
                individual_id, individual_id_index, client_contribution, delivery_area_zone,
                default_rate_id, allowance_mains, allowance_sides, requisition_period
         FROM `%s`
         WHERE client_type = ? AND use_legacy_billing = 1
           AND delivery_area_zone = ? AND active = 1 AND wp_user_id > 0',
        $clients_table
    );

    $client_type = 'SDNB';
    $client_rows = self::query_clients($sql, 'ss', [$client_type, $zone]);

    $allowance_rows = self::get_allowance_data_for_clients($client_rows, $start_date, $end_date, $weeks_in_month);

    // Filter to only clients with overages.
    return array_filter($allowance_rows, function ($row) {
        return ($row['bnm_mains'] > 0 || $row['overage_tax_sides'] > 0 || $row['overage_nontax_sides'] > 0);
    });
}
```

### 2.2 VAC overages

```php
/**
 * Get Veteran clients with non-zero overages for a billing period.
 *
 * @param string $start_date Y-m-d.
 * @param string $end_date   Y-m-d.
 * @return array Rows with overage quantities per client.
 */
public static function get_vac_overages(string $start_date, string $end_date): array {
    // Re-use the VAC CSV logic to get the aggregated data with overages.
    // This is not ideal (generates a full CSV then discards it), but avoids
    // duplicating the VAC allowance logic. A future refactor should extract
    // the VAC allowance engine into its own method (similar to SDNB's
    // get_allowance_data_for_clients).
    //
    // For now, we parse the CSV output.
    $csv_content = self::generate_vac_csv($start_date, $end_date);
    if (empty($csv_content)) {
        return [];
    }

    $lines   = explode("\n", $csv_content);
    $headers = str_getcsv(array_shift($lines));
    $results = [];

    foreach ($lines as $line) {
        if (empty(trim($line))) { continue; }
        $data = array_combine($headers, str_getcsv($line));
        if (!$data) { continue; }

        $bnm_mains        = (int) ($data['BNM Mains'] ?? 0);
        $overage_tax       = (int) ($data['Overage Tax Sides'] ?? 0);
        $overage_nontax    = (int) ($data['Overage Non Taxable Sides'] ?? 0);

        if ($bnm_mains > 0 || $overage_tax > 0 || $overage_nontax > 0) {
            $results[] = [
                'health_card'          => $data['K#'] ?? '',
                'last_name'            => $data['Client Last Name'] ?? '',
                'first_name'           => $data['Client First Name'] ?? '',
                'bnm_mains'            => $bnm_mains,
                'overage_tax_sides'    => $overage_tax,
                'overage_nontax_sides' => $overage_nontax,
            ];
        }
    }

    return $results;
}
```

---

## Step 3 — Create the AJAX handler for overage order creation

**File:** `includes/ajax/class-ajax-invoice.php`

Add two new AJAX actions in the `init()` method:

```php
add_action('wp_ajax_mealsdb_preview_overages', [__CLASS__, 'preview_overages']);
add_action('wp_ajax_mealsdb_create_overage_orders', [__CLASS__, 'create_overage_orders']);
```

### 3.1 Preview handler

```php
/**
 * Preview overages for a billing period.
 */
public static function preview_overages() {
    if (!check_ajax_referer('mealsdb_invoice_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid security token.']);
        return;
    }
    if (!MealsDB_Permissions::can_access_plugin()) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
        return;
    }

    $client_type    = sanitize_text_field($_POST['client_type'] ?? '');
    $start_date     = sanitize_text_field($_POST['start_date'] ?? '');
    $end_date       = sanitize_text_field($_POST['end_date'] ?? '');
    $zone           = sanitize_text_field($_POST['zone'] ?? '');
    $weeks_in_month = intval($_POST['weeks_in_month'] ?? 4);

    if (empty($start_date) || empty($end_date)) {
        wp_send_json_error(['message' => 'Start and end dates are required.']);
        return;
    }

    try {
        if ($client_type === 'SDNB') {
            $overages = MealsDB_Invoice_Generator::get_sdnb_overages($zone, $start_date, $end_date, $weeks_in_month);
            $rows = array_map(function ($row) {
                return [
                    'individual_id'       => $row['client']['individual_id'] ?? '',
                    'name'                => ($row['client']['last_name'] ?? '') . ', ' . ($row['client']['first_name'] ?? ''),
                    'wp_user_id'          => (int) ($row['client']['wp_user_id'] ?? 0),
                    'bnm_mains'           => $row['bnm_mains'],
                    'overage_tax_sides'   => $row['overage_tax_sides'],
                    'overage_nontax_sides'=> $row['overage_nontax_sides'],
                ];
            }, $overages);
        } elseif ($client_type === 'Veteran') {
            $rows = MealsDB_Invoice_Generator::get_vac_overages($start_date, $end_date);
        } else {
            wp_send_json_error(['message' => 'Invalid client type.']);
            return;
        }

        wp_send_json_success(['overages' => array_values($rows), 'count' => count($rows)]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
    }
}
```

### 3.2 Create orders handler

```php
/**
 * Create WooCommerce orders for overages.
 */
public static function create_overage_orders() {
    if (!check_ajax_referer('mealsdb_invoice_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid security token.']);
        return;
    }
    if (!MealsDB_Permissions::can_access_plugin()) {
        wp_send_json_error(['message' => 'Insufficient permissions.']);
        return;
    }

    $invoice_date = sanitize_text_field($_POST['invoice_date'] ?? '');
    $overages_json = stripslashes($_POST['overages'] ?? '[]');
    $overages = json_decode($overages_json, true);

    if (!is_array($overages) || empty($overages)) {
        wp_send_json_error(['message' => 'No overage data provided.']);
        return;
    }

    if (empty($invoice_date)) {
        $invoice_date = date('Y-m-d');
    }

    $product_ids    = MealsDB_Invoice_Generator::get_overage_product_ids();
    $order_count    = 0;
    $skipped        = [];

    foreach ($overages as $item) {
        $wp_user_id = (int) ($item['wp_user_id'] ?? 0);
        $bnm_mains  = (int) ($item['bnm_mains'] ?? 0);
        $overage_tax = (int) ($item['overage_tax_sides'] ?? 0);
        $overage_nt  = (int) ($item['overage_nontax_sides'] ?? 0);

        if ($wp_user_id <= 0) {
            $skipped[] = $item['name'] ?? 'Unknown';
            continue;
        }

        if ($bnm_mains <= 0 && $overage_tax <= 0 && $overage_nt <= 0) {
            continue;
        }

        $order = wc_create_order(['customer_id' => $wp_user_id]);
        if (is_wp_error($order)) {
            $skipped[] = $item['name'] ?? 'Unknown';
            continue;
        }

        $order->update_status('completed');
        $order->set_date_created($invoice_date . ' 00:00:00');
        $order->set_date_paid($invoice_date . ' 00:00:00');

        if ($bnm_mains > 0 && $product_ids['mains'] > 0) {
            $product = wc_get_product($product_ids['mains']);
            if ($product) {
                $order->add_product($product, $bnm_mains);
            }
        }
        if ($overage_nt > 0 && $product_ids['nontax_sides'] > 0) {
            $product = wc_get_product($product_ids['nontax_sides']);
            if ($product) {
                $order->add_product($product, $overage_nt);
            }
        }
        if ($overage_tax > 0 && $product_ids['taxable_sides'] > 0) {
            $product = wc_get_product($product_ids['taxable_sides']);
            if ($product) {
                $order->add_product($product, $overage_tax);
            }
        }

        $order->calculate_totals();
        $order->save();
        $order_count++;
    }

    wp_send_json_success([
        'created' => $order_count,
        'skipped' => $skipped,
        'skipped_count' => count($skipped),
    ]);
}
```

---

## Step 4 — Add UI for overages import

Add an "Import Overages" section to the existing invoice admin page (`views/admin-invoice.php`), below the existing invoice generation card. This avoids creating a new admin page.

The UI should contain:
1. A client type selector (SDNB / Veteran)
2. Date range inputs (can reuse the existing ones via JS)
3. Zone selector (shown only for SDNB, same as invoice form)
4. Weeks in month input (shown only for SDNB)
5. Invoice date input (the date to stamp on created orders)
6. A "Preview Overages" button that calls `mealsdb_preview_overages` via AJAX
7. A results table showing Individual ID, Name, BNM Mains, Overage Tax Sides, Overage Non-Tax Sides
8. A "Create Overage Orders" button that sends the previewed data to `mealsdb_create_overage_orders`
9. A summary showing orders created and any skipped users

Wire this UI in `assets/js/invoice.js` using the existing `mealsdbInvoice.ajaxUrl` and `mealsdbInvoice.nonce` localized variables.

---

## Verification checklist

- [ ] `get_overage_product_ids()` reads from WordPress option `mealsdb_overage_product_ids` with fallback defaults
- [ ] `get_sdnb_overages()` uses `get_allowance_data_for_clients()` (Phase B) and filters to non-zero overages
- [ ] `get_vac_overages()` parses the output of `generate_vac_csv()` to extract overage rows
- [ ] AJAX actions `mealsdb_preview_overages` and `mealsdb_create_overage_orders` are registered in `MealsDB_Ajax_Invoice::init()`
- [ ] Both AJAX handlers verify nonce (`mealsdb_invoice_nonce`) and permissions (`MealsDB_Permissions::can_access_plugin()`)
- [ ] Order creation uses `wc_create_order()`, `$order->add_product()`, `$order->calculate_totals()`, `$order->save()` — standard WC CRUD, no direct SQL
- [ ] Orders are set to `completed` status with the specified invoice date
- [ ] Line 2 of each order uses `wc_get_product()` to validate product exists before adding
- [ ] The overages UI section appears on the existing invoice admin page — no new menu pages
- [ ] No new database tables or schema changes
