# DIRECTIVE K19 — `client_phone_2` is the WordPress `shipping_phone`

**Targets:** `includes/class-sync.php`, `includes/services/class-slip-pdf-generator.php`,
`includes/class-quick-order-ajax.php`
**Severity:** MED — delivery-contact accuracy
**Depends on:** v1.0.588

---

## Facts (production, 2026-10-30)

- `client_phone_2` is mapped to `billing_phone_2`, a key with **0 rows** in `wp_usermeta`. It is
  empty on all 992 active clients and can never populate. (K13 left this inert.)
- `shipping_phone` has **1,015 non-empty rows** and **nothing in MealsDB reads it**.
- Against `client_phone_1` (which mirrors `billing_phone`, 100% match), `shipping_phone`
  **differs for 193 of 1,013 clients** and is identical for the other 820.
- WordPress/Enzebra has exactly two phone fields per client: `billing_phone` and
  `shipping_phone`. There is no second billing phone and no alternate-contact phone in usermeta
  under any spelling.

`shipping_phone` is the delivery contact, and it is the only real second number that exists.

---

## ITEM 1 — Remap the sync

In `MealsDB_Sync::get_field_to_wp_meta_map()`:

```php
            // K19 ITEM 1: was `billing_phone_2`, a key with 0 usermeta rows, so
            // the column could never populate. WP/Enzebra's second phone is the
            // delivery phone `shipping_phone` (1,015 non-empty; differs from
            // billing_phone for 193 clients).
            'client_phone_2' => ['type' => 'meta', 'key' => 'shipping_phone'],
```

`client_phone_2` stays in `get_wp_authoritative_fields()`. The K13 ITEM 3 empty-over-real guard
already prevents a blank `client_phone_2` from overwriting a populated `shipping_phone` in the
push direction; confirm with a test rather than assuming.

Store the value verbatim, including when it equals `client_phone_1`. The column mirrors the WP
field faithfully; de-duplication is a display concern (ITEM 2), not a storage one.

---

## ITEM 2 — Suppress the duplicate on the slip

`build_driver_block()` maps `client_phone_2` to `phone_secondary`, and the doc renderer
(~line 1424) prints `phone_secondary` whenever it is non-empty. After ITEM 1, that would print the
same number twice for 820 clients.

Render `phone_secondary` only when it differs from `phone`. Compare **digits only**, so
`506-858-1234` and `(506)-858-1234` count as the same number:

```php
        // K19 ITEM 2: client_phone_2 now mirrors shipping_phone, which equals
        // billing_phone for most clients. Print the secondary line only when it
        // is genuinely a different number, compared digits-only so formatting
        // differences do not produce a duplicate line.
        $digits = static fn (string $p): string => preg_replace('/\D+/', '', $p);
        if ($phone2 !== '' && $digits($phone2) !== $digits($phone)) {
            $lines .= '<div class="d4-phone">' . self::esc($phone2) . '</div>';
        }
```

Apply the same rule anywhere else `phone_secondary` is rendered.

---

## ITEM 3 — The order's shipping phone uses `client_phone_2`

K15 sets both `billing_phone` and `shipping_phone` on the order from `client_phone_1`. For the 193
clients with a distinct delivery number, that writes the billing number into the delivery slot.

In `resolve_order_phone()`, split the result by destination:

- **Billing phone** → `client_phone_1` (unchanged)
- **Shipping phone** → `client_phone_2` if non-empty, otherwise `client_phone_1`

The `do_not_call_client_phone` fallback to the alternate contact applies to both, exactly as in
K15 — a do-not-call client's own numbers must not reach the order on either side.

---

## Out of scope

- The four `alternate_contact_*` columns. No WordPress source exists for them; they stay as they
  are.
- Backfilling `client_phone_2` on existing clients.
- Changing what `client_phone_1` maps to.

---

## Tests

1. **Sync reads `shipping_phone` into `client_phone_2`.** **Fails against v1.0.588.**
2. **Blank `client_phone_2` does not overwrite a populated `shipping_phone`** in the push
   direction (K13 ITEM 3 regression).
3. **Slip prints one line** when `client_phone_2` equals `client_phone_1`, including when they
   differ only in formatting. **Fails against v1.0.588.**
4. **Slip prints two lines** when they genuinely differ.
5. **Order shipping phone is `client_phone_2`** when populated; billing phone stays
   `client_phone_1`.
6. **Order shipping phone falls back to `client_phone_1`** when `client_phone_2` is empty.
7. **`do_not_call` set** → neither `client_phone_1` nor `client_phone_2` reaches the order.

---

## Acceptance criteria

- `client_phone_2` maps to `shipping_phone`; no mapping references `billing_phone_2`.
- No slip renders the same number twice.
- An order's shipping phone is the client's delivery number where one exists.
- Tests 1 and 3 each fail against v1.0.588.
