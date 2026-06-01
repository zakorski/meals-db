# Directive GUI-RATE-2 (SURGICAL) — Rate fallback by client type + location

## HOW TO EXECUTE THIS DIRECTIVE — READ THIS FIRST
- This is **3 edits + 2 STOP-points** in `includes/services/class-wc-order-query.php` and one call
  site in `includes/services/class-invoice-generator.php`.
- For each edit: `read` the named file, find the EXACT verbatim `FIND` block, apply the change.
  Do NOT regenerate the method or file from memory. Do NOT reformat untouched lines.
- This directive ADDS a small private method and changes one `return`. It does NOT rewrite
  `resolve_rate_for_order`'s existing lookups — leave those exactly as they are.
- **Two values are intentionally left as `__CONFIRM__` placeholders** (Veteran Definitions key,
  Private WC price source). Do NOT guess them. Where you see `__CONFIRM__`, STOP and ask the
  operator / check the named source. Shipping a guessed billing rate is worse than not shipping.
- If any FIND block does not match verbatim, STOP and report — do not improvise.
- Expected change: 1 signature edit, 1 `return` replacement, 1 new private method (~15 lines), 1
  call-site edit. If you are editing more than that, STOP.

**Why:** the SDNB urban/rural, Veteran, and per-client rate logic ALREADY EXISTS and works. The only
gap: `resolve_rate_for_order` returns `0.00` when a client has no `meals_client_rates` row (the
new-client case after GUI-RATE-1). That makes a new client bill at $0. This makes the no-row case
fall back to the correct PROGRAM rate by type. **Do GUI-RATE-1 first.**

---

## EDIT 1 — widen the resolver signature to receive type + zone
**File:** `includes/services/class-wc-order-query.php`
**FIND (verbatim, 1 line):**
```
    public function resolve_rate_for_order(int $rate_id, int $client_id): float {
```
**REPLACE WITH:**
```
    public function resolve_rate_for_order(int $rate_id, int $client_id, string $client_type = '', ?string $zone = null): float {
```
(Adding two optional params keeps every existing caller valid.)

---

## EDIT 2 — replace the $0.00 fallback with a program-rate resolution
**File:** `includes/services/class-wc-order-query.php`
**FIND (verbatim, 6 lines — the tail of `resolve_rate_for_order`):**
```
        if (is_array($row) && isset($row['rate'])) {
            return (float) $row['rate'];
        }

        return 0.00;
    }
```
**REPLACE WITH:**
```
        if (is_array($row) && isset($row['rate'])) {
            return (float) $row['rate'];
        }

        // No per-client contracted rate -> fall back to the program rate by type/location.
        return $this->resolve_program_rate($client_type, $zone, $client_id);
    }
```
**NOTE:** there are TWO `if (is_array($row) && isset($row['rate'])) {` blocks in this method (the
rate_id lookup and the default-rate lookup). The one to match is the SECOND, immediately followed by
`return 0.00;`. The 6-line FIND block above is unique because of the `return 0.00;` — match that.

---

## EDIT 3 — add the program-rate helper method
**File:** `includes/services/class-wc-order-query.php`
**FIND (verbatim — the closing of `resolve_rate_for_order`, which you just edited in EDIT 2, then the
next method's doc comment; locate this to insert BEFORE it):**
```
        // No per-client contracted rate -> fall back to the program rate by type/location.
        return $this->resolve_program_rate($client_type, $zone, $client_id);
    }
```
**ACTION:** immediately AFTER the closing `}` of that method, INSERT this new method:
```php

    /**
     * Program-wide rate fallback when a client has no contracted meals_client_rates row.
     * SDNB: urban/rural primary-main rate (existing is_rural_zone rule). Veteran: Definitions.
     * Private: WooCommerce price. Never returns a silent 0 for a recognised type.
     */
    private function resolve_program_rate(string $client_type, ?string $zone, int $client_id): float {
        $type = strtoupper(trim($client_type));

        if ($type === 'SDNB') {
            $rural = MealsDB_Operational_Constants::is_rural_zone($zone);
            if ($zone === null || $zone === '') {
                // Missing zone on an SDNB client defaults to urban — surface it, don't hide it.
                error_log('[MealsDB Rate] SDNB client ' . $client_id . ' has no delivery_area_zone; defaulting to URBAN rate.');
            }
            return MealsDB_Operational_Constants::get_sdnb_main_rate('primary', $rural);
        }

        if ($type === 'VETERAN') {
            // __CONFIRM__ : exact MealsDB_Rate_Definitions key for the Veteran primary-main rate.
            // Do NOT guess. Confirm the key name, then use it here:
            $veteran_rate = MealsDB_Rate_Definitions::get('__CONFIRM__veteran_primary_main_key');
            return $veteran_rate !== null ? (float) $veteran_rate : 0.00;
        }

        if ($type === 'PRIVATE') {
            // __CONFIRM__ : how a Private per-main rate is sourced from WooCommerce.
            // The codebase reads WC price via $product->get_price() / wc_get_price_to_display
            // (see class-products-loader.php, class-quick-order-products.php). Reuse that;
            // do NOT invent a new price path. Confirm WHICH product represents the per-main
            // rate before implementing. Until confirmed, leave this returning 0.00 and FLAG it.
            return 0.00; // __CONFIRM__ replace with WC-sourced rate
        }

        return 0.00;
    }
```
**STOP-POINTS:** the two `__CONFIRM__` markers are deliberate. Do NOT replace them with guessed
values. Report them to the operator. The SDNB branch is complete and correct as written (it reuses
existing, verified methods).

---

## EDIT 4 — pass type + zone at the call site
**File:** `includes/services/class-invoice-generator.php`
**FIND (verbatim, 1 line):**
```
            $resolved_rate = $order_query->resolve_rate_for_order($rate_id, $cid);
```
**REPLACE WITH:**
```
            $resolved_rate = $order_query->resolve_rate_for_order($rate_id, $cid, $client['client_type'] ?? '', $client['delivery_area_zone'] ?? null);
```
**NOTE:** `$client['client_type']` and `$client['delivery_area_zone']` are already available at this
point in the loop (the surrounding code reads `$client[...]`). Confirm by reading the lines above
this one; if either key is named differently in this scope, use the actual key — do not add new
queries.

---

## DEPENDENCIES / CONFIRM BEFORE SHIPPING
1. **`__CONFIRM__veteran_primary_main_key`** — the real `MealsDB_Rate_Definitions` key for the
   Veteran primary-main rate. (`MealsDB_Rate_Definitions::get(string $key): ?float` exists; find the
   right key from the Definitions seeds/keys.)
2. **Private WC rate source** — which WooCommerce product/price is the Private per-main rate, sourced
   via the existing `get_price()` / `wc_get_price_to_display` helpers. If Private billing is
   per-line-item rather than per-flat-main-rate, the Private branch needs a different shape — FLAG
   for the operator rather than guessing.
3. **SDNB rural set** — `SDNB_RURAL_ZONE_CODES = ['S']` (Sussex=rural, Moncton=urban) is ALREADY in
   `class-operational-constants.php` and is reused as-is. Operator to confirm with Janet whether
   `['S']` is the COMPLETE rural set; if more zones are rural, add their codes to that ONE constant
   (no logic change here).

---

## VERIFICATION (after edits + after the __CONFIRM__ values are filled)
```bash
cd <plugin-root>
grep -n "resolve_program_rate" includes/services/class-wc-order-query.php   # signature call + new method
grep -n "resolve_rate_for_order(\$rate_id, \$cid" includes/services/class-invoice-generator.php  # updated call site
grep -n "__CONFIRM__" includes/services/class-wc-order-query.php   # MUST be empty before shipping
php tests/test-*.php   # expect green
```
- **T (SDNB urban):** SDNB client, zone 'M', no rates row -> non-zero urban primary-main rate.
- **T (SDNB rural):** SDNB client, zone 'S', no rates row -> non-zero rural primary-main rate.
- **T (contracted wins):** client WITH a meals_client_rates is_default row -> that rate, fallback not used.
- **T (missing zone):** SDNB client, null zone -> urban rate AND an error_log line.
- **T (end-to-end):** a NEW client (GUI-RATE-1 applied, no rate typed) invoices at a non-zero,
  type-correct basic cost.

## DO NOT
- Do not rewrite `resolve_rate_for_order`'s existing lookups — only the final `return 0.00` changed.
- Do not guess the Veteran key or Private price — leave `__CONFIRM__` and report.
- Do not change the SDNB rurality rule or `is_rural_zone` — it's reused as-is.
- Do not add new DB queries at the call site — reuse the `$client` array already in scope.
