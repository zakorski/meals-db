# Case Count Sync Button — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a repeat-safe "Case Count Sync" Data Ops button that backfills `meals_products.case_size` from the legacy bare `case_size` postmeta, so Purchase Orders compute real case quantities instead of the stuck default `1`.

**Architecture:** A new worker `MealsDB_Product_Display_Sync::case_count_sync()` walks the published product catalog (same paginated `wc_get_products` + allowed-category filter as the existing `full_sync()`). For each product it reads the CURRENT case size from the **canonical `meals_products.case_size` COLUMN** via `MealsDB_Products::get_product_data()` — *not* from the never-persisted `_mealsdb_case_size` postmeta — and, only when the column is still the default (`<= 1`) and a real legacy value (`> 1`) exists, fills the column via the existing `save_product_data()` upsert. This is the single source of truth read by the PO and every other consumer, so writing it fixes them all. A new AJAX handler `ajax_case_count_sync()` (guard stack copied verbatim from `ajax_full_sync()`) drives it; a Data Ops button + `settings.js` handler trigger it.

**Tech Stack:** PHP 8.2 / WordPress (HPOS) / WooCommerce, jQuery, bespoke `php tests/test-*.php` harness (no PHPUnit).

**Source directive:** `directives/DIRECTIVE-case-count-sync-button.md` (patched version — column is the source of truth, NOT the `_mealsdb_case_size` postmeta).

**Key facts verified against the code (do not re-derive):**
- `meals_products.case_size` is `INT NOT NULL DEFAULT 1` (`includes/class-schema.php:129`).
- The plugin NEVER calls `update_post_meta()` for case size. `_mealsdb_case_size` is only a WC form-field name read from `$_POST` in `class-wc-product-tab.php:248` and written to the COLUMN via `save_product_data()`. The product tab reads its displayed value back from the column (`get_product_data()`). **So the postmeta is virtually always empty — the column is the source of truth.**
- `MealsDB_Products::get_product_data(int $pid): array` returns the full row including `case_size` (the column). `MealsDB_Products::save_product_data(int $pid, array $data): bool` upserts via `ON DUPLICATE KEY UPDATE ... case_size = VALUES(case_size)` and re-checks `edit_product`/`manage_woocommerce` internally (returns `false` if it refuses).
- The PO reads the column at `includes/services/class-reports.php:338-340` (`column > 0 ? column : legacy ?: 1`) — it never falls back because the column defaults to `1`. **Leave the PO code unchanged**; fixing the data fixes all consumers.
- The Data Ops page DOES enqueue `settings.js` (`class-admin-ui.php:697` `enqueue_data_ops_page_scripts()`), localizing `window.mealsdbSettings` with `ajaxUrl` and `nonces.general = wp_create_nonce('mealsdb_nonce')`. The existing `#mealsdb-sync-products` handler lives at `settings.js:328`. So mirroring `settings.js` is correct.

---

## File Structure

| File | Change |
|---|---|
| `includes/class-product-display-sync.php` | Add `case_count_sync()` worker + `ajax_case_count_sync()` handler; register the AJAX action in `init()`. |
| `tests/test-case-count-sync.php` | New test for the worker (fill / destructive-guard / no-legacy / idempotent / failed-write). |
| `views/data-ops.php` | Add the "Case Count Sync" button block after the existing "Sync Product Display Data" block. |
| `assets/js/settings.js` | Add the `#mealsdb-case-count-sync` click handler, mirroring `#mealsdb-sync-products`. |

**Why this home:** the worker is cohesive with the existing product-sync code in `MealsDB_Product_Display_Sync` (same catalog walk, same `MealsDB_Products` writes), per the directive's preference. No new class needed.

---

## Task 1: The `case_count_sync()` worker (TDD)

**Files:**
- Create: `tests/test-case-count-sync.php`
- Modify: `includes/class-product-display-sync.php` (add `case_count_sync()` — place it right after the existing `full_sync()` method, which ends around line 240)

- [ ] **Step 1: Write the failing test**

Create `tests/test-case-count-sync.php` with exactly this content. It stubs `wc_get_products` + `get_post_meta` + an in-memory `MealsDB_Products` column store + `MealsDB_Quick_Order_Products`, then loads the REAL `MealsDB_Product_Display_Sync` via the autoloader (collaborator stubs are defined BEFORE the autoloader so the real classes don't load — the same pattern as `tests/test-ajax-slip-batch.php`):

```php
<?php
/**
 * Tests for MealsDB_Product_Display_Sync::case_count_sync() — the legacy
 * case_size → meals_products.case_size COLUMN backfill.
 *
 * Verifies the column-keyed, idempotent, non-destructive behavior:
 *   CS-1 fill:             column default (1) + legacy>1 → column set to legacy, counted 'filled'
 *   CS-2 destructive guard: column already real (36) + differing legacy (24) → stays 36, 'already_ok'
 *   CS-3 no legacy:        column default + no legacy meta → unchanged, 'no_legacy'
 *   CS-4 idempotent:       second run fills nothing; filled products become 'already_ok'
 *   CS-5 failed write:     save_product_data returns false → counted 'failed', not 'filled'
 *
 * Stubs wc_get_products + get_post_meta + MealsDB_Products (in-memory column store)
 * + MealsDB_Quick_Order_Products; loads the REAL MealsDB_Product_Display_Sync.
 *
 * Run: php tests/test-case-count-sync.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

// ---- fixtures (mutated by the stubs) ----
$GLOBALS['CS_COLUMN']   = [];   // pid => meals_products.case_size
$GLOBALS['CS_LEGACY']   = [];   // pid => legacy `case_size` postmeta
$GLOBALS['CS_PRODUCTS'] = [];   // list of pids wc_get_products returns
$GLOBALS['CS_SAVE_OK']  = true; // save_product_data return value

// ---- WP / WC function stubs ----
if (!function_exists('__')) { function __($t, $d = null) { return $t; } }
if (!function_exists('get_post_meta')) {
    function get_post_meta($pid, $key, $single = false) {
        if ($key === 'case_size') { return $GLOBALS['CS_LEGACY'][$pid] ?? ''; }
        return '';
    }
}
if (!class_exists('WC_Product')) { class WC_Product {} }
class CS_FakeProduct extends WC_Product {
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
}
if (!function_exists('wc_get_products')) {
    function wc_get_products($args) {
        $page = (int) ($args['page'] ?? 1);
        if ($page > 1) { return []; } // single page
        $out = [];
        foreach ($GLOBALS['CS_PRODUCTS'] as $pid) { $out[] = new CS_FakeProduct($pid); }
        return $out;
    }
}

// ---- collaborator stubs (defined BEFORE the autoloader so the real ones don't load) ----
class MealsDB_Products {
    public static function get_product_data(int $pid): array {
        return [
            'wc_product_id'   => $pid,
            'product_type'    => 'meal',
            'taxable'         => 0,
            'main_ingredient' => '',
            'dietary_tags'    => [],
            'allergen_flags'  => [],
            'case_size'       => (int) ($GLOBALS['CS_COLUMN'][$pid] ?? 1),
            'unit_cost'       => '0.00',
            'last_updated'    => null,
        ];
    }
    public static function save_product_data(int $pid, array $data): bool {
        if (!$GLOBALS['CS_SAVE_OK']) { return false; }
        $GLOBALS['CS_COLUMN'][$pid] = (int) ($data['case_size'] ?? 1);
        return true;
    }
}
class MealsDB_Quick_Order_Products {
    public static function get_allowed_category_slugs(): array { return ['meals']; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

// ---- harness ----
$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}
function cs_reset() {
    $GLOBALS['CS_COLUMN'] = []; $GLOBALS['CS_LEGACY'] = [];
    $GLOBALS['CS_PRODUCTS'] = []; $GLOBALS['CS_SAVE_OK'] = true;
}

// CS-1..CS-3 — combined scenario: fill / destructive-guard / no-legacy.
cs_reset();
$GLOBALS['CS_PRODUCTS'] = [1, 2, 3];
$GLOBALS['CS_COLUMN']   = [1 => 1, 2 => 36, 3 => 1];
$GLOBALS['CS_LEGACY']   = [1 => 24, 2 => 24, 3 => 0];
$r = MealsDB_Product_Display_Sync::case_count_sync();
chk($r['scanned'], 3, 'CS: scanned 3');
chk($r['filled'], 1, 'CS-1: one filled (pid1)');
chk($r['already_ok'], 1, 'CS-2: one already_ok (pid2)');
chk($r['no_legacy'], 1, 'CS-3: one no_legacy (pid3)');
chk($r['failed'], 0, 'CS: none failed');
chk($GLOBALS['CS_COLUMN'][1], 24, 'CS-1: pid1 column filled to 24');
chk($GLOBALS['CS_COLUMN'][2], 36, 'CS-2: pid2 column unchanged (36, NOT lowered to 24)');
chk($GLOBALS['CS_COLUMN'][3], 1,  'CS-3: pid3 column stays default 1');

// CS-4 — idempotent second run (no new fills; filled product now already_ok).
$r2 = MealsDB_Product_Display_Sync::case_count_sync();
chk($r2['filled'], 0, 'CS-4: second run fills nothing');
chk($r2['already_ok'], 2, 'CS-4: pid1+pid2 now already_ok');
chk($r2['no_legacy'], 1, 'CS-4: pid3 still no_legacy');
chk($GLOBALS['CS_COLUMN'][1], 24, 'CS-4: pid1 still 24');

// CS-5 — failed write counted as 'failed', not 'filled'.
cs_reset();
$GLOBALS['CS_PRODUCTS'] = [5];
$GLOBALS['CS_COLUMN']   = [5 => 1];
$GLOBALS['CS_LEGACY']   = [5 => 48];
$GLOBALS['CS_SAVE_OK']  = false;
$r3 = MealsDB_Product_Display_Sync::case_count_sync();
chk($r3['filled'], 0, 'CS-5: failed write NOT counted as filled');
chk($r3['failed'], 1, 'CS-5: failed write counted as failed');
chk($GLOBALS['CS_COLUMN'][5], 1, 'CS-5: column unchanged on failed write');

echo "\n=== MealsDB case_count_sync ===\n";
if (empty($failures)) { echo "PASS — {$passed} checks\n"; exit(0); }
echo "FAIL — {$passed} passed, " . count($failures) . " failed:\n";
foreach ($failures as $f) { echo "  - $f\n"; }
exit(1);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/test-case-count-sync.php`
Expected: a fatal/error because `MealsDB_Product_Display_Sync::case_count_sync()` does not exist yet (e.g. `Call to undefined method`).

- [ ] **Step 3: Implement the worker**

In `includes/class-product-display-sync.php`, add this method immediately AFTER the existing `full_sync()` method (which ends `return $synced; }` around line 240). READ `full_sync()` first to match the `wc_get_products` arg shape and pagination idiom exactly.

```php
    /**
     * Backfill meals_products.case_size from the legacy bare `case_size` postmeta.
     *
     * Column-keyed, idempotent, non-destructive. Reads the CURRENT case size from the
     * canonical meals_products.case_size COLUMN (via get_product_data) — NOT from the
     * `_mealsdb_case_size` postmeta, which the plugin never persists (it is only a WC
     * form-field name; the tab writes the column directly). Only FILLS the default
     * (column <= 1) from a real legacy value (> 1); never lowers or overwrites a column
     * that already holds a real value, and never touches the legacy meta. Running it
     * twice changes nothing the second time.
     *
     * NOTE: a product legitimately having case_size 1 cannot be distinguished from the
     * unset default 1 — acceptable because the real case sizes are 12/24/36/48/100 and
     * the operation is non-destructive (a true-1 product simply stays 1).
     *
     * @return array{scanned:int, filled:int, already_ok:int, no_legacy:int, failed:int}
     */
    public static function case_count_sync(): array {
        $stats = ['scanned' => 0, 'filled' => 0, 'already_ok' => 0, 'no_legacy' => 0, 'failed' => 0];

        if (!function_exists('wc_get_products') || !class_exists('MealsDB_Quick_Order_Products')) {
            return $stats;
        }

        $allowed_slugs = MealsDB_Quick_Order_Products::get_allowed_category_slugs();

        $page     = 1;
        $per_page = 100;

        do {
            $products = wc_get_products([
                'status'   => 'publish',
                'limit'    => $per_page,
                'page'     => $page,
                'orderby'  => 'ID',
                'order'    => 'ASC',
                'return'   => 'objects',
                'category' => $allowed_slugs,
            ]);

            if (!is_array($products) || empty($products)) {
                break;
            }

            foreach ($products as $product) {
                if (!$product instanceof WC_Product) {
                    continue;
                }
                $pid = (int) $product->get_id();
                if ($pid <= 0) {
                    continue;
                }

                $stats['scanned']++;

                $existing = MealsDB_Products::get_product_data($pid);
                $current  = (int) ($existing['case_size'] ?? 1);          // canonical COLUMN value
                $legacy   = (int) get_post_meta($pid, 'case_size', true); // legacy bare key (READ ONLY)

                if ($current > 1) {
                    // Column already holds a real (non-default) case size — leave it ALONE.
                    $stats['already_ok']++;
                } elseif ($legacy > 1) {
                    // Default column + real legacy value: fill the COLUMN via the existing
                    // upsert so every column stays consistent. The legacy meta is left intact.
                    // Reading/writing the column (never the empty _mealsdb_case_size postmeta)
                    // is what keeps this idempotent and non-destructive.
                    $ok = MealsDB_Products::save_product_data($pid, array_merge($existing, ['case_size' => $legacy]));
                    if ($ok) {
                        $stats['filled']++;
                    } else {
                        // Upsert refused (e.g. capability) — surface it, never report a phantom success.
                        $stats['failed']++;
                    }
                } else {
                    $stats['no_legacy']++;
                }
            }

            $page++;
        } while (count($products) === $per_page);

        return $stats;
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/test-case-count-sync.php`
Expected: `PASS — 16 checks`

Also lint: `php -l includes/class-product-display-sync.php` → `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-product-display-sync.php tests/test-case-count-sync.php
git commit -m "feat(products): case_count_sync worker — backfill case_size column from legacy meta

Column-keyed (reads/writes meals_products.case_size, not the never-persisted
_mealsdb_case_size postmeta), idempotent, non-destructive. Fills only the default
from a real legacy value; never lowers a real value. TDD: covers fill, the
destructive guard, no-legacy, idempotency, and failed-write.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: AJAX handler + registration

**Files:**
- Modify: `includes/class-product-display-sync.php` (add `ajax_case_count_sync()`; register the action in `init()`)

- [ ] **Step 1: Add the AJAX handler**

In `includes/class-product-display-sync.php`, add this method right after `ajax_full_sync()` (which ends around line 190, just before `full_sync()`). It copies the `ajax_full_sync()` guard stack verbatim — READ `ajax_full_sync()` (around lines 145-190) first and mirror it exactly (nonce `mealsdb_nonce` via `$_REQUEST['nonce']` → `MealsDB_Permissions::required_capability()` → rate-limit `settings_modify`):

```php
    /**
     * AJAX handler: backfill meals_products.case_size from legacy postmeta.
     * Guard stack mirrors ajax_full_sync() verbatim (nonce → capability → rate limit).
     */
    public static function ajax_case_count_sync(): void {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'mealsdb_nonce')) {
            wp_send_json([
                'success' => false,
                'message' => __('Invalid request.', 'meals-db'),
            ]);
        }

        $capability = class_exists('MealsDB_Permissions')
            ? MealsDB_Permissions::required_capability()
            : 'manage_woocommerce';
        if (!current_user_can($capability)) {
            wp_send_json([
                'success' => false,
                'message' => __('You are not allowed to perform this action.', 'meals-db'),
            ], 403);
        }

        // Rate-limit: this walks the whole published-product catalog and writes a
        // meals_products row per filled product — same heavy-loop profile as the
        // display sync, so reuse the bulk-backfill bucket.
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('settings_modify')) {
            wp_send_json([
                'success' => false,
                'message' => __('Rate limit exceeded. Please try again later.', 'meals-db'),
            ], 429);
        }

        $result = self::case_count_sync();

        wp_send_json([
            'success' => true,
            'message' => sprintf(
                /* translators: 1: scanned, 2: filled, 3: already correct, 4: no legacy, 5: failed */
                __('Case Count Sync complete: %1$d products scanned, %2$d filled from legacy data, %3$d already correct, %4$d had no legacy value, %5$d failed to write.', 'meals-db'),
                $result['scanned'], $result['filled'], $result['already_ok'], $result['no_legacy'], $result['failed']
            ),
        ]);
    }
```

- [ ] **Step 2: Register the AJAX action in `init()`**

In `init()` (around line 38), directly after the existing line:

```php
        add_action('wp_ajax_mealsdb_sync_product_display', [self::class, 'ajax_full_sync']);
```

add:

```php
        // AJAX endpoint for the legacy case-size backfill (Data Ops button).
        add_action('wp_ajax_mealsdb_case_count_sync', [self::class, 'ajax_case_count_sync']);
```

- [ ] **Step 3: Verify lint + the worker test still passes**

```bash
php -l includes/class-product-display-sync.php
php tests/test-case-count-sync.php
```
Expected: `No syntax errors detected` and `PASS — 16 checks`. (No new test for the AJAX handler — its guard stack is byte-identical to the production-exercised `ajax_full_sync()`; the new logic under test is the worker.)

- [ ] **Step 4: Commit**

```bash
git add includes/class-product-display-sync.php
git commit -m "feat(products): add mealsdb_case_count_sync AJAX endpoint

Guard stack mirrors ajax_full_sync (nonce mealsdb_nonce, required_capability,
settings_modify rate limit); returns the worker's scanned/filled/already_ok/
no_legacy/failed counts.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Data Ops button markup

**Files:**
- Modify: `views/data-ops.php` (insert after the "Sync Product Display Data" block, which ends with its `</p>` around line 239, just before the closing `</div>` at ~line 240)

- [ ] **Step 1: Add the button block**

READ `views/data-ops.php` around lines 230-240 first. The existing "Sync Product Display Data" block ends:

```php
            <span id="mealsdb-sync-products-result" style="margin-left:12px;"></span>
        </p>
</div>
```

Insert the new block between that `</p>` and the `</div>`, so it reads:

```php
            <span id="mealsdb-sync-products-result" style="margin-left:12px;"></span>
        </p>

        <h2><?php echo esc_html__( 'Case Count Sync', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'Fills in product case sizes from legacy data and writes them to the products table used by Purchase Orders. Safe to run repeatedly; it only fills missing (default) values and never overwrites, lowers, or deletes existing data.', 'meals-db' ); ?>
        </p>
        <p>
            <button type="button" class="button" id="mealsdb-case-count-sync">
                <?php echo esc_html__( 'Case Count Sync', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-case-count-sync-result" style="margin-left:12px;"></span>
        </p>
</div>
```

- [ ] **Step 2: Verify lint**

Run: `php -l views/data-ops.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add views/data-ops.php
git commit -m "feat(products): add Case Count Sync button to Data Ops page

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: settings.js click handler

**Files:**
- Modify: `assets/js/settings.js` (add the handler after the existing `#mealsdb-sync-products` handler, which ends with its `.fail(...)` block around line 347)

- [ ] **Step 1: Add the handler**

READ `assets/js/settings.js` around lines 327-348 first to confirm the `#mealsdb-sync-products` handler shape, the `tint()` helper, and the `ajaxUrl` / `nonces` locals in scope. Add this handler immediately after that handler's closing `});` (the new handler must be inside the same `jQuery(...)` ready scope, so place it adjacent to the one you are mirroring):

```javascript
    // Case Count Sync — backfill case sizes from legacy data.
    $('#mealsdb-case-count-sync').on('click', function () {
        var $btn    = $(this);
        var $result = $('#mealsdb-case-count-sync-result');
        $btn.prop('disabled', true);
        $result.text('Syncing case counts...'); tint($result, '#666');

        $.post(ajaxUrl, {
            action: 'mealsdb_case_count_sync',
            nonce: nonces.general || ''
        }, function (resp) {
            $btn.prop('disabled', false);
            if (resp && resp.success) {
                $result.text(resp.message || 'Done.'); tint($result, '#46b450');
            } else {
                $result.text((resp && resp.message) || 'Sync failed.'); tint($result, '#dc3232');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.text('Request failed.'); tint($result, '#dc3232');
        });
    });
```

- [ ] **Step 2: Verify the JS parses**

Run: `node --check assets/js/settings.js && echo "JS OK"`
Expected: `JS OK`. (If `node` is unavailable, manually confirm the new handler sits inside the ready scope and braces/parens balance.)

- [ ] **Step 3: Commit**

```bash
git add assets/js/settings.js
git commit -m "feat(products): wire Case Count Sync button to mealsdb_case_count_sync

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Final verification

**Files:** none (verification only)

- [ ] **Step 1: Lint every changed/added file**

```bash
php -l includes/class-product-display-sync.php
php -l views/data-ops.php
node --check assets/js/settings.js && echo "JS OK"
```
Expected: no syntax errors; `JS OK`.

- [ ] **Step 2: Run the new test + the full suite**

```bash
php tests/test-case-count-sync.php
pass=0; fail=0; failed=""
for t in tests/test-*.php; do php "$t" >/dev/null 2>&1 && pass=$((pass+1)) || { fail=$((fail+1)); failed="$failed $t"; }; done
echo "PASS: $pass  FAIL: $fail"; echo "Failed:$failed"
```
Expected: `test-case-count-sync.php` → `PASS — 16 checks`. The full suite should show only the documented baseline failures — `tests/test-pdf-slip-binary-output.php` and `tests/test-vac-pdf.php` (missing local `mbstring`/`imagick`: `undefined function Dompdf\mb_internal_encoding()`) — and NOTHING else.

- [ ] **Step 3: Confirm the AJAX action + button wiring line up (static check)**

```bash
grep -rn "mealsdb_case_count_sync" includes/ assets/ views/
```
Expected: the action appears in three places — `add_action('wp_ajax_mealsdb_case_count_sync', ...)` and `wp_ajax`-handler in `includes/class-product-display-sync.php`, and `action: 'mealsdb_case_count_sync'` in `assets/js/settings.js`. The button id `mealsdb-case-count-sync` appears in `views/data-ops.php` and `assets/js/settings.js`.

- [ ] **Step 4: Manual smoke test (live host — needs WP/WC + real data)**

This cannot be verified locally (no WP runtime). On the live/staging site:
- `SELECT wc_product_id, case_size FROM 2xnIt_meals_products WHERE case_size > 1;` → note the (likely empty) before-state.
- Data Ops → click **Case Count Sync**. Result message reports `scanned / filled / already correct / no legacy / failed` counts.
- Re-run the SELECT → real case sizes (12/24/36/48/100) now present; the legacy `case_size` postmeta is unchanged (still present).
- Generate a NEW Purchase Order → Cases / Order Qty use real case sizes (round to whole cases), not 1.
- Click **Case Count Sync** AGAIN → `0 filled`, all `already correct` (idempotent); no values changed.

---

## Self-Review Notes (author)

- **Spec coverage:** directive §1 (AJAX handler) → Task 2; §2 (worker, column-keyed, idempotent, non-destructive, failed-write counting) → Task 1; §3 (column write IS the propagation, reuse upsert) → Task 1 (`save_product_data(array_merge($existing, ['case_size'=>$legacy]))`); §4 (button) → Task 3; §5 (JS) → Task 4; "Suggested test" (fill / destructive-guard / no-legacy / idempotent) → Task 1's CS-1..CS-5. The directive's `failed` count + `save_product_data` bool check → Task 1 CS-5.
- **Out-of-scope respected:** no change to the PO math (`class-reports.php`), the existing "Sync Products" handler, or the product-tab save path; the legacy `case_size` meta is only READ.
- **Type/name consistency:** worker `case_count_sync(): array` returns keys `scanned/filled/already_ok/no_legacy/failed`; the AJAX message consumes exactly those five (Task 2); the test asserts exactly those five (Task 1). Action string `mealsdb_case_count_sync` and button id `mealsdb-case-count-sync` are identical across Tasks 2-4.
- **Idempotency/non-destructive guarantee** lives in the column-keyed guard (`$current > 1` → leave alone), proven by CS-2 (column 36 + legacy 24 stays 36) and CS-4 (second run fills nothing). This is the exact failure the patched directive corrected and the test pins it.
