# HST Rate Source + Quick Order Address Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Source the government invoice HST rate from the New Brunswick `hst` tax row explicitly (not the coincidentally-correct store-base standard row), fix the same bug in the Quick Order preview, and make Quick Order orders carry the client's address so WooCommerce taxes them at the right province.

**Architecture:** One shared helper (`MealsDB_Tax::resolve_nb_hst_rate()`) becomes the single source for the government-invoice rate and the QO preview rate; both call `WC_Tax::find_rates()` with an explicit CA/NB/`hst` location and assert exactly one row. The real Quick Order path stops relying on a rate at all — it copies the client's address onto the order and lets WooCommerce resolve province tax the normal way (`create_wc_order`).

**Tech Stack:** PHP 8.2, WooCommerce 10.x (HPOS), `$wpdb`, plugin autoloader, plain-PHP unit tests run with `php tests/<file>.php`.

---

## Decisions locked (read before coding)

- **Government path bills a flat NB HST rate — deliberately.** Verified 2026-08-21: five VAC Veterans have genuine Nova Scotia (Amherst, B4H) delivery addresses, yet legacy Enzebra bills every VAC side flat at `$4.25 × 15% = $0.6383`, NB and NS alike, and Janet submits those invoices as such. A flat NB rate **reproduces legacy output exactly**, which is the pass condition for the July re-run comparison. This is a business choice (NB place-of-supply for all government meals), documented in code — not an accident, but also not automatic. If NS delivery should attract NS 14%, that's a future operator decision needing per-client `delivery_province` resolution and is **out of scope**.
- **`find_rates()` with an explicit location, then assert exactly one row.** Do not `reset()`-and-hope on a multi-row result — that habit is the root cause of the original bug.
- **QO preview uses the same NB rate.** It is a single flat rate feeding the on-screen JS summary (`taxable_types = ['PRIVATE']`), resolved once per page with no client context. NB matches the real order (which bills the client's province via the Item 2 fix) for local NB clients — essentially all private-pay — and removes the store-base dependency and the "NS-standard corrected to 14%" divergence trap.
- **Out of scope (note only):** three `delivery_province='NS'` records are mis-flagged (Shediac E4P + Sackville E4L are NB; client 1 is the Fishhorn test record) — a data-cleanup item, not tax logic. Config sequencing (standard CA/NB → 15% before moving store base to CA:NB) is operator work; Item 1a removes the code's dependence on it.

## File structure

- **Create** `includes/services/class-tax.php` — `MealsDB_Tax`, the single NB-HST resolver + a source-description helper for the draft screen. One responsibility: turn WC's tax table into "the NB HST rate," loudly.
- **Create** `tests/test-tax-nb-hst-rate.php` — unit tests for the resolver (happy path, empty, ambiguous, non-positive).
- **Create** `tests/test-quick-order-order-address.php` — unit test for the address-copy helper.
- **Modify** `includes/services/class-invoice-generator.php:80` — `resolve_hst_rate()` becomes a thin delegate to `MealsDB_Tax`.
- **Modify** `includes/class-admin-ui.php:2466` — `resolve_quick_order_tax_rate()` delegates to `MealsDB_Tax`.
- **Modify** `includes/class-quick-order-ajax.php:970` — add `apply_client_address_to_order()` and call it in `create_wc_order()` before line items / `calculate_totals()`.
- **Modify** `tests/test-invoice-hst-lb7.php:31` — extend the `WC_Tax` mock with `find_rates()` so the existing suite still passes.
- **Modify** `includes/admin/class-invoice-draft-page.php` — surface the resolved rate + source on the draft screen (Item 1c).
- **Modify** `meals-db-main.php` — bump `MEALS_DB_VERSION` (CI normally owns this; leave for the version-bump step).

---

### Task 1: `MealsDB_Tax` — the shared NB-HST resolver

**Files:**
- Create: `includes/services/class-tax.php`
- Test: `tests/test-tax-nb-hst-rate.php`

- [ ] **Step 1: Write the failing test**

Create `tests/test-tax-nb-hst-rate.php`:

```php
<?php
/**
 * MealsDB_Tax::resolve_nb_hst_rate() — DIRECTIVE hst-rate-source ITEM 1.
 * Asks WC_Tax::find_rates() for the CA/NB row in the 'hst' class explicitly
 * and asserts EXACTLY one row, so the answer no longer depends on the store
 * base and a reset()-and-hope can't slip through.
 *
 * Run: php tests/test-tax-nb-hst-rate.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');

// $GLOBALS['__nb_rows'] is what the mocked find_rates() returns for a CA/NB/hst
// query: an array of rate rows keyed by id. Any other query returns [].
if (!class_exists('WC_Tax')) {
    class WC_Tax {
        public static function find_rates($args = []) {
            $match = ($args['country'] ?? '') === 'CA'
                && ($args['state'] ?? '') === 'NB'
                && ($args['tax_class'] ?? '') === 'hst';
            return $match ? ($GLOBALS['__nb_rows'] ?? []) : [];
        }
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = []; $passed = 0;
function nb_eq($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}

// One well-formed NB row at 15% → 0.15
$GLOBALS['__nb_rows'] = [7 => ['rate' => '15.0000', 'label' => 'HST', 'shipping' => 'yes', 'compound' => 'no']];
nb_eq('single NB row 15% -> 0.15', 0.15, MealsDB_Tax::resolve_nb_hst_rate());

// No NB row → 0.0 (no silent store-base fallback)
$GLOBALS['__nb_rows'] = [];
nb_eq('no NB row -> 0.0', 0.0, MealsDB_Tax::resolve_nb_hst_rate());

// Ambiguous (two rows) → 0.0, we refuse to guess
$GLOBALS['__nb_rows'] = [
    7 => ['rate' => '15.0000'],
    8 => ['rate' => '14.0000'],
];
nb_eq('ambiguous rows -> 0.0', 0.0, MealsDB_Tax::resolve_nb_hst_rate());

// Non-positive rate → 0.0
$GLOBALS['__nb_rows'] = [7 => ['rate' => '0']];
nb_eq('zero rate -> 0.0', 0.0, MealsDB_Tax::resolve_nb_hst_rate());

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-tax-nb-hst-rate.php`
Expected: FATAL — `Class "MealsDB_Tax" not found` (or autoload failure). The class does not exist yet.

- [ ] **Step 3: Write minimal implementation**

Create `includes/services/class-tax.php`:

```php
<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Tax-rate resolution helper.
 *
 * WHY this exists (DIRECTIVE hst-rate-source, ITEM 1): the old code called
 * WC_Tax::get_rates('') — the STANDARD class at "no location", which
 * WooCommerce falls back to the store base (currently CA:NS), and then took
 * reset() of the result. It was correct only by coincidence: the NS
 * standard-class row happens to sit at 15%. The moment anyone corrects that
 * row to NS's real 14%, every government invoice would silently drop to 14%
 * and under-report HST. We instead ask WooCommerce for the CA/NB row in the
 * 'hst' class EXPLICITLY, so the answer no longer depends on the store base.
 *
 * PLACE-OF-SUPPLY NOTE (verified 2026-08-21): five VAC Veterans have genuine
 * Nova Scotia (Amherst, B4H) delivery addresses. Legacy Enzebra has always
 * billed them at NB 15% regardless, and Janet submits those invoices as such,
 * so a flat NB rate reproduces current practice exactly. This is a DELIBERATE
 * business choice (NB place-of-supply for all government meals) — not an
 * accident, but a choice. If NS delivery should attract NS HST, the government
 * path would need per-client delivery_province resolution; that is an operator
 * decision, out of scope here.
 */
class MealsDB_Tax {

    /**
     * Resolve the New Brunswick HST rate as a decimal fraction (e.g. 0.15).
     *
     * NO FALLBACK to the store base, by design. Returns 0.0 on any of:
     * WC_Tax missing, find_rates() throwing, the 'hst' class holding no CA/NB
     * row, MORE than one matching row (ambiguous — we refuse to guess), or a
     * non-positive rate. Every 0.0 path records an ERROR-severity event so a
     * misconfigured tax table is visible, never silently billed at 0%.
     */
    public static function resolve_nb_hst_rate(): float {
        if (!class_exists('WC_Tax')) {
            self::record_rate_failure('WC_Tax unavailable', null);
            return 0.0;
        }

        try {
            // find_rates() takes an EXPLICIT location + class and filters
            // internally, so it does not depend on WooCommerce resolving a
            // customer location it doesn't have (the get_rates('') trap).
            $rates = \WC_Tax::find_rates([
                'country'   => 'CA',
                'state'     => 'NB',
                'tax_class' => 'hst',
            ]);
        } catch (\Throwable $e) {
            self::record_rate_failure('WC_Tax::find_rates threw: ' . $e->getMessage(), null);
            return 0.0;
        }

        if (!is_array($rates) || count($rates) === 0) {
            self::record_rate_failure('No CA/NB row in the hst tax class', 0);
            return 0.0;
        }
        if (count($rates) > 1) {
            // Refuse to reset()-and-hope on a multi-row result — that habit is
            // exactly what caused this bug. Surface it and bill 0 so it can't
            // pass silently.
            self::record_rate_failure('Ambiguous CA/NB hst rows', count($rates));
            return 0.0;
        }

        $row  = reset($rates); // exactly one row, asserted above
        $rate = (is_array($row) && isset($row['rate'])) ? (float) $row['rate'] : 0.0;
        if ($rate <= 0) {
            self::record_rate_failure('CA/NB hst row resolved to a non-positive rate', 0);
            return 0.0;
        }

        return $rate / 100;
    }

    /**
     * Human-readable description of the rate + source for the invoice draft
     * screen (DIRECTIVE ITEM 1c). Never throws.
     */
    public static function describe_nb_hst_source(): string {
        $rate = self::resolve_nb_hst_rate();
        if ($rate <= 0) {
            return __('HST rate could not be resolved from WooCommerce (billed as 0%) — check WC Settings → Tax.', 'meals-db');
        }
        $pct = rtrim(rtrim(number_format($rate * 100, 4, '.', ''), '0'), '.');
        return sprintf(
            /* translators: %s is a percentage such as "15" */
            __('%s%% — WooCommerce “hst” tax class, CA/NB row', 'meals-db'),
            $pct
        );
    }

    private static function record_rate_failure(string $why, ?int $rows_found): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Tax] NB HST rate unresolved: ' . $why . ' — billing HST as 0%.');
        }
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'error',
                'category'  => 'billing',
                'subsystem' => 'tax',
                'event'     => 'resolve_nb_hst_rate.failed',
                'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                'message'   => 'NB HST rate unresolved (' . $why . ') — HST resolved to 0%.',
                'context'   => ['rows_found' => $rows_found],
            ]);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-tax-nb-hst-rate.php`
Expected: `OK (4 passed)`

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-tax.php tests/test-tax-nb-hst-rate.php
git commit -m "feat(tax): add MealsDB_Tax NB-HST resolver via explicit find_rates (ITEM 1)"
```

---

### Task 2: Invoice generator delegates to `MealsDB_Tax`

**Files:**
- Modify: `includes/services/class-invoice-generator.php:80-116`
- Modify (test mock): `tests/test-invoice-hst-lb7.php:31-40`

- [ ] **Step 1: Update the existing test mock so it survives the API change**

The current mock only implements `get_rates()`. Add `find_rates()` to it. In `tests/test-invoice-hst-lb7.php`, replace the `WC_Tax` mock block (around lines 31-40):

```php
$GLOBALS['__wc_hst_percent'] = 15.0;
if (!class_exists('WC_Tax')) {
    class WC_Tax {
        public static function get_rates($tax_class = '') {
            $p = $GLOBALS['__wc_hst_percent'] ?? null;
            return $p === null ? [] : [['rate' => $p]];
        }
        // resolve_hst_rate() now delegates to MealsDB_Tax::resolve_nb_hst_rate(),
        // which calls find_rates() for the CA/NB hst row.
        public static function find_rates($args = []) {
            $p = $GLOBALS['__wc_hst_percent'] ?? null;
            if ($p === null) { return []; }
            $match = ($args['country'] ?? '') === 'CA'
                && ($args['state'] ?? '') === 'NB'
                && ($args['tax_class'] ?? '') === 'hst';
            return $match ? [1 => ['rate' => $p, 'label' => 'HST', 'shipping' => 'yes', 'compound' => 'no']] : [];
        }
    }
}
```

- [ ] **Step 2: Run the existing test to confirm it still fails against the OLD generator**

Run: `php tests/test-invoice-hst-lb7.php`
Expected: still PASS at this point (the generator still uses `get_rates()`, mock still provides it). This step just proves the mock change didn't break the current suite before the code change.

- [ ] **Step 3: Replace the generator's resolver with a delegate**

In `includes/services/class-invoice-generator.php`, replace the body of `resolve_hst_rate()` (lines 80-116) with:

```php
    private static function resolve_hst_rate(): float {
        // DIRECTIVE hst-rate-source ITEM 1: NB HST is resolved from the WC
        // 'hst' class CA/NB row EXPLICITLY (was get_rates('') → store base,
        // correct only because the NS standard row happens to be 15%). The
        // resolver logs loudly and returns 0.0 rather than silently falling
        // back — see MealsDB_Tax::resolve_nb_hst_rate().
        return MealsDB_Tax::resolve_nb_hst_rate();
    }
```

Leave the long doc-comment above `resolve_hst_rate()` in place but update its first paragraph to say the rate comes from the `hst` class CA/NB row (not "the STANDARD tax rate"). The VAC/`fold_hst` notes below it remain accurate.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/test-invoice-hst-lb7.php`
Expected: PASS with the same assertion count as before (HST figures unchanged — 15% still flows through, now via `find_rates()`).

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-invoice-generator.php tests/test-invoice-hst-lb7.php
git commit -m "fix(invoice): source HST from the NB hst row explicitly, not store base (ITEM 1a/1b)"
```

---

### Task 3: Quick Order preview uses the same NB rate (third site)

**Files:**
- Modify: `includes/class-admin-ui.php:2466-2489`

- [ ] **Step 1: Replace the preview resolver body**

In `includes/class-admin-ui.php`, replace the body of `resolve_quick_order_tax_rate()` (lines 2466-2489) with:

```php
    private function resolve_quick_order_tax_rate(): float
    {
        // DIRECTIVE hst-rate-source ITEM 1 (third site): the private-pay QO
        // preview is a single flat rate feeding the on-screen summary. Show the
        // NB HST rate — it matches the real order (which bills the client's
        // province via MealsDB_Quick_Order_Ajax::create_wc_order) for local NB
        // clients and no longer depends on the store base, so a corrected
        // NS-standard row can't make the preview disagree with the order.
        return MealsDB_Tax::resolve_nb_hst_rate();
    }
```

- [ ] **Step 2: Verify no syntax/type regressions**

Run: `php -l includes/class-admin-ui.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/class-admin-ui.php
git commit -m "fix(quick-order): preview tax rate reads NB hst row, matching real orders (ITEM 1 third site)"
```

---

### Task 4: Quick Order copies the client's address onto the order (Item 2)

**Files:**
- Modify: `includes/class-quick-order-ajax.php:970-982` (call site) + new private helper
- Test: `tests/test-quick-order-order-address.php`

- [ ] **Step 1: Write the failing test for the address-copy helper**

Create `tests/test-quick-order-order-address.php`:

```php
<?php
/**
 * DIRECTIVE hst-rate-source ITEM 2: Quick Order orders must carry the client's
 * address so WooCommerce resolves tax at the right province instead of the
 * store base (CA:NS). apply_client_address_to_order() is the pure, testable
 * unit; it sets billing/shipping from a DB-side client row and returns the
 * billing province that will drive tax ('' when none resolvable).
 *
 * Run: php tests/test-quick-order-order-address.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');

// Minimal WC_Order stub that records every set_*() call.
if (!class_exists('WC_Order')) {
    class WC_Order {
        public array $set = [];
        public function __call($name, $args) {
            if (strpos($name, 'set_') === 0) { $this->set[substr($name, 4)] = $args[0] ?? null; }
            return null;
        }
        public function get_id() { return 999; }
    }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }

$failures = []; $passed = 0;
function addr_eq($label, $expected, $actual): void {
    global $failures, $passed;
    if ($expected === $actual) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}

$m = new ReflectionMethod('MealsDB_Quick_Order_Ajax', 'apply_client_address_to_order');
$m->setAccessible(true);

// A Moncton NB client with a distinct delivery address.
$client = [
    'first_name' => 'Jane', 'last_name' => 'Doe', 'client_email' => 'jane@example.com',
    'street_name' => '10 Main St', 'city' => 'Moncton', 'province' => 'NB', 'postal_code' => 'E1C 1A1',
    'delivery_street_name' => '11 Side St', 'delivery_city' => 'Dieppe',
    'delivery_province' => 'NB', 'delivery_postal_code' => 'E1A 2B2',
];
$order = new WC_Order();
$province = $m->invoke(null, $order, $client);
addr_eq('returns billing province', 'NB', $province);
addr_eq('billing state set', 'NB', $order->set['billing_state'] ?? null);
addr_eq('billing country set', 'CA', $order->set['billing_country'] ?? null);
addr_eq('billing city set', 'Moncton', $order->set['billing_city'] ?? null);
addr_eq('shipping state from delivery', 'NB', $order->set['shipping_state'] ?? null);
addr_eq('shipping city from delivery', 'Dieppe', $order->set['shipping_city'] ?? null);

// Shipping falls back to billing when delivery fields are absent.
$client2 = ['first_name' => 'A', 'last_name' => 'B', 'street_name' => '1 X', 'city' => 'Moncton',
    'province' => 'NB', 'postal_code' => 'E1C 1A1'];
$order2 = new WC_Order();
$m->invoke(null, $order2, $client2);
addr_eq('shipping state falls back to billing', 'NB', $order2->set['shipping_state'] ?? null);

// No province anywhere → returns '' and does NOT set a country (no CA:NS fallback).
$order3 = new WC_Order();
$province3 = $m->invoke(null, $order3, ['first_name' => 'A', 'last_name' => 'B']);
addr_eq('no province returns empty', '', $province3);
addr_eq('no billing country set when province unknown', null, $order3->set['billing_country'] ?? null);

// Null client → '' and no setters called.
$order4 = new WC_Order();
addr_eq('null client returns empty', '', $m->invoke(null, $order4, null));
addr_eq('null client sets nothing', [], $order4->set);

if ($failures) { echo implode("\n", $failures) . "\n"; echo "FAILED ({$passed} passed)\n"; exit(1); }
echo "OK ({$passed} passed)\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-quick-order-order-address.php`
Expected: FATAL — `Method MealsDB_Quick_Order_Ajax::apply_client_address_to_order() does not exist`.

- [ ] **Step 3: Add the helper method**

In `includes/class-quick-order-ajax.php`, add this private static method next to `create_wc_order()`:

```php
    /**
     * Copy the client's address onto a freshly-created order so WooCommerce
     * resolves tax at the correct province (DIRECTIVE hst-rate-source ITEM 2).
     *
     * MUST run BEFORE calculate_totals() — WC fixes the tax location at
     * calculation time, so an address set afterwards is ignored.
     *
     * The client record is the operator-maintained source of truth. Billing is
     * taken from the mailing address, shipping from the delivery address
     * (falling back to billing). Returns the billing province that will drive
     * tax (woocommerce_tax_based_on = billing), or '' if none could be
     * resolved — the caller logs that case rather than silently billing the
     * store base.
     *
     * @param WC_Order   $order
     * @param array|null $client DB-side client row (get_client_by_id), or null.
     * @return string Billing province set on the order ('' if unknown).
     */
    private static function apply_client_address_to_order(WC_Order $order, ?array $client): string {
        if (!is_array($client)) {
            return '';
        }

        $bill_province = trim((string) ($client['province'] ?? ''));
        $bill_country  = $bill_province !== '' ? 'CA' : '';

        $order->set_billing_first_name((string) ($client['first_name'] ?? ''));
        $order->set_billing_last_name((string) ($client['last_name'] ?? ''));
        if (!empty($client['client_email'])) {
            $order->set_billing_email((string) $client['client_email']);
        }
        $order->set_billing_address_1((string) ($client['street_name'] ?? ''));
        $order->set_billing_city((string) ($client['city'] ?? ''));
        $order->set_billing_state($bill_province);
        $order->set_billing_postcode((string) ($client['postal_code'] ?? ''));
        if ($bill_country !== '') {
            $order->set_billing_country($bill_country);
        }

        // Shipping from the delivery address, falling back to billing.
        $ship_province = trim((string) ($client['delivery_province'] ?? ''));
        if ($ship_province === '') { $ship_province = $bill_province; }
        $ship_country  = $ship_province !== '' ? 'CA' : '';

        $order->set_shipping_first_name((string) ($client['first_name'] ?? ''));
        $order->set_shipping_last_name((string) ($client['last_name'] ?? ''));
        $order->set_shipping_address_1((string) ($client['delivery_street_name'] ?? $client['street_name'] ?? ''));
        $order->set_shipping_city((string) ($client['delivery_city'] ?? $client['city'] ?? ''));
        $order->set_shipping_state($ship_province);
        $order->set_shipping_postcode((string) ($client['delivery_postal_code'] ?? $client['postal_code'] ?? ''));
        if ($ship_country !== '') {
            $order->set_shipping_country($ship_country);
        }

        return $bill_province;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-quick-order-order-address.php`
Expected: `OK (11 passed)`

- [ ] **Step 5: Wire the helper into `create_wc_order()`**

In `includes/class-quick-order-ajax.php`, immediately after the `set_customer_id()` block (lines 980-982), insert:

```php
        // DIRECTIVE hst-rate-source ITEM 2: WooCommerce does NOT copy a linked
        // customer's address onto the order. Without an address, WC resolves tax
        // at the store base (CA:NS) and a Moncton NB client is billed NS HST.
        // Copy the client's address BEFORE line items and calculate_totals(),
        // which is when WC fixes the tax location.
        $client_row   = $client_id > 0 ? (new MealsDB_Clients_Repository())->get_client_by_id($client_id) : null;
        $tax_province = self::apply_client_address_to_order($order, $client_row);
        if ($tax_province === '') {
            // Do NOT silently fall back to the store base. Record the order and
            // client so an unknown tax region is visible, not quietly mis-priced.
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'warning',
                    'category'  => 'billing',
                    'subsystem' => 'quick_order',
                    'event'     => 'order.address_missing_province',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'   => 'Quick Order created with no resolvable province; tax region may be wrong.',
                    'context'   => ['order_id' => $order->get_id(), 'client_id' => $client_id, 'wp_user_id' => $wp_user_id],
                ]);
            }
        }
```

- [ ] **Step 6: Confirm the regression test still passes and lint is clean**

Run: `php tests/test-quick-order-status.php && php -l includes/class-quick-order-ajax.php`
Expected: `OK (...)` from the status test (order still created as `processing`, guards unchanged) and `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add includes/class-quick-order-ajax.php tests/test-quick-order-order-address.php
git commit -m "fix(quick-order): set client address on created orders so tax lands on NB (ITEM 2)"
```

---

### Task 5: Surface the HST rate + source on the invoice draft screen (Item 1c)

**Files:**
- Modify: `includes/admin/class-invoice-draft-page.php` (edit view, near `render_edit_view` around line 258, before `render_grid`)

- [ ] **Step 1: Locate the exact insertion point**

Run: `grep -n "render_edit_view\|render_grid(\|is_finalized\|<h2" includes/admin/class-invoice-draft-page.php | head`
Read the `render_edit_view()` method body (the block that starts near line 250 and calls `render_grid()` near line 287). Choose the point **after** the draft header/finalize controls and **before** `self::render_grid(...)`.

- [ ] **Step 2: Emit the rate + source line**

Immediately before the `self::render_grid((int) $draft_id, ...)` call, insert:

```php
        // DIRECTIVE hst-rate-source ITEM 1c: show the HST rate actually used and
        // where it came from, so the operator signing off (Janet) sees the rate
        // behind the totals rather than inferring it. Read-only, no side effects.
        if (class_exists('MealsDB_Tax')) {
            echo '<p class="description mealsdb-draft-hst-source" style="margin:6px 0;">'
                . esc_html__('HST rate applied: ', 'meals-db')
                . esc_html(MealsDB_Tax::describe_nb_hst_source())
                . '</p>';
        }
```

- [ ] **Step 3: Verify lint**

Run: `php -l includes/admin/class-invoice-draft-page.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-invoice-draft-page.php
git commit -m "feat(invoice-draft): show the HST rate and its source row on the draft screen (ITEM 1c)"
```

---

### Task 6: Run the full local suite and bump the version

**Files:**
- Modify: `meals-db-main.php` (version header) — only if CI does not own this in your workflow.

- [ ] **Step 1: Run every affected test**

```bash
php tests/test-tax-nb-hst-rate.php \
 && php tests/test-invoice-hst-lb7.php \
 && php tests/test-quick-order-order-address.php \
 && php tests/test-quick-order-status.php
```
Expected: each prints `OK (...)`.

- [ ] **Step 2: Broader billing regression sweep (best-effort locally)**

```bash
for t in test-phase2-billing test-invoice-serialize test-sdnb-legacy-compute test-product-taxability; do
  echo "== $t =="; php tests/$t.php || echo "  (needs live WC/PDF — verify on staging)"
done
```
Expected: pass, or the documented local-env skips (per memory: dompdf/Imagick paths are live-only). Note any that need staging.

- [ ] **Step 3: Version bump (skip if CI owns it — it does in this repo)**

Bump `MEALS_DB_VERSION` in `meals-db-main.php` only if you are not relying on CI's bump-on-merge. Otherwise leave it.

- [ ] **Step 4: Commit (if version changed)**

```bash
git add meals-db-main.php
git commit -m "chore: bump plugin version for HST-source + QO-address fixes"
```

---

## Staging verification checklist (operator / live WC — from the directive)

These need a live WooCommerce tax table and cannot be fully proven on the local CLI. Run on staging and capture screenshots.

**Item 1 — government HST (numbers must be UNCHANGED; the rate is the same 15%, sourced correctly now):**
1. Regenerate 2026-07 SDNB legacy Zone M → 86 clients, basic **$22,913.02**, HST **$656.54**, total **$23,405.63**. 📷
2. Zone S → 9 clients, HST **$11.58**, total **$1,456.02**. 📷
3. New portal → 128 rows, HST **$1,366.89**. 📷
4. VAC → 20 clients, 611 meals, **$5,529.55**. 📷
5. Draft screen shows the HST rate used and its source row (Task 5). 📷
6. **Negative test:** temporarily change the `hst`-class CA/NB row in WooCommerce, regenerate, confirm invoice HST **and** the on-screen rate change accordingly; then **restore** the row and confirm totals return to step 1. 📷

**Item 2 — Quick Order address/tax:**
1. Create a QO for a private-pay Moncton **NB** client. Then:
   ```sql
   SELECT id, status, customer_id, total_amount, tax_amount FROM 2xnIt_wc_orders WHERE id = <ID>;
   SELECT * FROM 2xnIt_wc_order_addresses WHERE order_id = <ID>;
   ```
   Expect a billing row with `state = NB` and the tax line at the **NB** rate — a single $4.25 taxable item → **$0.64**, not $0.60. Show the arithmetic. 📷
2. The tax line name references the **NB** row, not `CA-NS-HST-2`. 📷
3. Create a QO for a client with **no** address → order still creates and an event-log entry records the missing region (`order.address_missing_province`). 📷
4. Regression: a government client's QO still creates as `processing`, one delivery-fee line, correct contribution. 📷

**Business sign-off (not code):** confirm with Janet that the five Amherst NS Veterans should keep billing at **NB 15%** (place-of-supply choice), so the July comparison matching legacy is expected, not a defect.

---

## Self-review notes

- **Spec coverage:** ITEM 1a (explicit `hst`/CA-NB via `find_rates`) → Task 1/2; 1b (fail loudly, no silent 0%) → Task 1 `record_rate_failure`; 1c (surface rate on draft) → Task 5; third site → Task 3; ITEM 2a (copy address before totals) → Task 4; 2b (log missing province, no base fallback) → Task 4 Step 5. "Must NOT change" items ($4.48 side rate, VAC precedence, `MealsDB_Money`, order status `processing`, fee/contribution, client meta) are untouched — Task 4 Step 6 re-runs the status regression to prove it.
- **Type consistency:** `resolve_nb_hst_rate(): float`, `describe_nb_hst_source(): string`, `apply_client_address_to_order(WC_Order, ?array): string` used identically in tests and call sites.
- **Known dependency:** switching to `find_rates()` breaks the old `get_rates()`-only mock — handled explicitly in Task 2 Step 1 before the code change.
