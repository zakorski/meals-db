# DIRECTIVE K11 — Order addresses carry the zone; quantity field accepts typing

**Targets:** `includes/class-quick-order-ajax.php`, `assets/js/quick-order.js`
**Severity:** ITEM 1 MED (operational — packers and order-takers rely on the zone);
ITEM 2 MED (latent data bug); ITEM 3 LOW-MED (daily friction, wrong-quantity risk)
**Depends on:** v1.0.576 (K10, PR #544)
**Reported by:** Janet, 2026-09-09, from order #29328 (Kimberley L Donald)

---

## Why this exists

Janet took an order on the new system and reported two things:

1. The order's **Ship to** column is empty and neither address shows a **zone**, where every
   older order reads e.g. *"Kimberley L Donald, Apt 1513 – 101 Archibald St, Zone 1, Moncton
   NB E1C 9J7"*. The order list is scrolled daily and the address + zone is how staff
   recognise a row.
2. Entering a quantity required highlighting over the existing `0` several times before `20`
   could be typed.

Both are reproducible in code. ITEM 2 below is a third, related bug found while reading the
address code — it was not reported but has the same symptom and would otherwise resurface.

---

## ITEM 1 — Put the delivery area (zone) on the order address

**File:** `includes/class-quick-order-ajax.php`, `apply_client_address_to_order()` (~line 1312)

The method sets `address_1`, `city`, `state`, `postcode` and `country` for both billing and
shipping. It never sets **`address_2`** — and `address_2` is where the zone lives.

Confirmed mapping, three independent sources:
- Kimberley Donald's WP customer meta: `billing_address_2` = `'Zone 1'`,
  `shipping_address_2` = `'Zone 1'`, and her client record has
  `delivery_area_name` = `'Zone 1'`.
- The migration's own event log: *"billing_address_2 (delivery_area_name) was empty; no
  delivery area -> no derivable delivery day"* — the migration treats `billing_address_2` and
  `delivery_area_name` as the same field.
- Every pre-existing order in Janet's screenshot renders the zone between street and city,
  which is the `address_2` slot in WooCommerce's formatted address.

Add to the billing block, after `set_billing_address_1(...)`:

```php
        // K11 ITEM 1: the delivery area (e.g. "Zone 1") belongs in address_2 —
        // that is where the migration puts it (billing_address_2 <->
        // delivery_area_name) and where WooCommerce renders it in the Ship-to
        // column that staff scan daily. Orders created without it lose the zone.
        $order->set_billing_address_2((string) ($client['delivery_area_name'] ?? ''));
```

And to the shipping block, after `set_shipping_address_1(...)`:

```php
        $order->set_shipping_address_2((string) ($client['delivery_area_name'] ?? ''));
```

Use `delivery_area_name` (the human label, "Zone 1") — **not** `delivery_area_zone`, which
holds the SDNB service-centre code `M`/`S` and means something else entirely.

Confirm `delivery_area_name` is present in the `$client` array this method receives. If the
SELECT that builds it does not include the column, add it — a silent `?? ''` here would
reproduce the exact bug this item fixes.

---

## ITEM 2 — `??` does not fall back on an empty string

**Same method, the shipping block.**

```php
$order->set_shipping_address_1((string) ($client['delivery_street_name'] ?? $client['street_name'] ?? ''));
$order->set_shipping_city((string) ($client['delivery_city'] ?? $client['city'] ?? ''));
$order->set_shipping_postcode((string) ($client['delivery_postal_code'] ?? $client['postal_code'] ?? ''));
```

`??` falls back only on **NULL**. A client whose delivery fields hold `''` — which is the
common case, since a client with one address has no separate delivery address — gets an empty
shipping address instead of the billing fallback the code plainly intends. WooCommerce then
renders **"No shipping address set."** and the Ship-to column is blank.

The province fallback immediately above already handles this correctly:

```php
$ship_province = trim((string) ($client['delivery_province'] ?? ''));
if ($ship_province === '') { $ship_province = $bill_province; }
```

So the bug is an inconsistency within one method, not a missing concept. Apply the same
empty-string-aware pattern to `address_1`, `address_2`, `city` and `postcode`. A small helper
keeps it readable:

```php
        // K11 ITEM 2: `??` only falls back on NULL. Delivery fields are usually
        // '' (not NULL) for a client with a single address, so the intended
        // fallback to the billing value never fired and the order shipped with
        // an empty address — rendering as "No shipping address set." Compare
        // against '' the way the province fallback directly above already does.
        $first_non_empty = static function (...$values): string {
            foreach ($values as $value) {
                $value = trim((string) ($value ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
            return '';
        };
```

Then:

```php
        $order->set_shipping_address_1($first_non_empty($client['delivery_street_name'] ?? null, $client['street_name'] ?? null));
        $order->set_shipping_address_2($first_non_empty($client['delivery_area_name'] ?? null));
        $order->set_shipping_city($first_non_empty($client['delivery_city'] ?? null, $client['city'] ?? null));
        $order->set_shipping_postcode($first_non_empty($client['delivery_postal_code'] ?? null, $client['postal_code'] ?? null));
```

**Do not apply the fallback to the billing block.** Billing has no second source, and
`trim()`-ing a billing value that is deliberately blank would be a behaviour change beyond
this directive's scope.

---

## ITEM 3 — Quantity field: select the existing value on focus

**File:** `assets/js/quick-order.js`, near the existing qty guards (~line 205)

`<input type="number" min="0" class="... mealsdb-quick-order__qty-input ...">` renders with
`0` in it. Clicking places a caret beside the `0` rather than selecting it, so typing `20`
yields `020` / `200` / `020` depending on caret position. The operator has to manually
highlight a single character in a `small-text` field, which is what Janet described.

This is also a wrong-quantity risk, not only friction: `200` is a plausible-looking value that
would reach a packer.

Add alongside the existing arrow-key and wheel guards:

```js
            // K11 ITEM 3: the field renders with "0" in it, so a click places a
            // caret beside the zero instead of selecting it and typing "20"
            // produces "020" or "200". Select on focus so typing replaces.
            // `select()` is deferred because some browsers set the caret from
            // the click AFTER the focus handler runs, which would undo it.
            $(document).on('focus', '.mealsdb-quick-order__qty-input', (event) => {
                const input = event.target;
                window.setTimeout(() => {
                    if (document.activeElement === input) {
                        input.select();
                    }
                }, 0);
            });
```

Do not add a `click` handler as well — it would fight the user re-positioning the caret
deliberately on a value they are mid-edit. Focus only.

Leave the arrow-key and wheel guards (Directive 2 ITEM 3) exactly as they are; they exist for
the same phone-order-accuracy reason and this is additive.

---

## Out of scope

- Clients whose `street_name` / `delivery_street_name` are genuinely empty in the client
  record. That is a data gap, not a code defect — being counted separately. ITEM 2 makes the
  code use whatever data exists; it cannot invent an address that was never entered.
- The `Pull Data` button and `class-wp-user-mapper.php`.
- The WooCommerce order-list column definitions. Ship-to is a core WooCommerce column and
  renders correctly once the order carries an address.

---

## Tests

1. **Zone lands on both addresses.** Client with `delivery_area_name = 'Zone 1'` → new order
   has `billing_address_2` and `shipping_address_2` both `'Zone 1'`. Fails against v1.0.576.
2. **Empty-string delivery fields fall back to billing.** Client with
   `delivery_street_name = ''`, `delivery_city = ''`, `delivery_postal_code = ''` and
   populated billing fields → shipping address_1/city/postcode equal the billing values.
   Fails against v1.0.576.
3. **NULL delivery fields still fall back** (guards the existing behaviour).
4. **A real delivery address still wins** over billing when both are populated.
5. **Blank `delivery_area_name`** → `address_2` is `''`, no fatal, no literal `'null'`.
6. **Quantity focus selects** — assert `selectionStart === 0` and
   `selectionEnd === value.length` after focus. May need a JS harness; if none exists, say so
   rather than skipping silently.

Baseline is 158 pass / 4 baseline fails (2 dompdf-mbstring, 2 po-task).

---

## Acceptance criteria

- `set_billing_address_2` and `set_shipping_address_2` both called in
  `apply_client_address_to_order()`, sourced from `delivery_area_name`.
- No bare `??` chain remains for shipping address_1 / city / postcode.
- Billing block unchanged apart from the new `address_2` line.
- Arrow-key and wheel guards unchanged.
- Tests 1, 2 and 6 each fail against v1.0.576.

---

## Operator verification (staging)

1. Take a Quick Order for a client with a delivery area. Open the order — **Ship to shows the
   street, the zone, the city and the postcode**, and the Orders list Ship-to column is
   populated.
2. Take a Quick Order for a client with **no** separate delivery address (delivery fields
   blank). Shipping should mirror billing, not read "No shipping address set."
3. In Quick Order, click a quantity field showing `0` and type `20` — **the field reads `20`**,
   not `020` or `200`, first time.
4. Arrow keys and the mouse wheel still do **not** change a focused quantity.
5. The `+`/`−` steppers still work.
