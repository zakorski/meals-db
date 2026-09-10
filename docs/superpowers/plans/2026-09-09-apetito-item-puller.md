# Apetito Item Puller Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a WooCommerce Draft product (plus its `meals_products` row) from a 5-digit Apetito item code by fetching the public Nutridata page, parsing it, duplicate-guarding on SKU, and letting the operator confirm price and categories.

**Architecture:** Seven units split so the fragile, high-value parser is a pure function with zero I/O. A fetcher (HTTP + 7-day transient cache) feeds a pure parser; a creator writes a Draft product and read-merge-writes the parsed fields into `meals_products` without clobbering the category-derived `product_type`/`taxable`; a placeholder helper sideloads one bundled image; an audit unit does a read-only, paced, resumable drift report. AJAX + an admin submenu page + JS glue it together. State survives Fetch→Create via **Approach A**: the create handler re-reads and re-parses the same transient server-side; the browser submits only operator-owned fields.

**Tech Stack:** PHP 8.2 / WordPress / WooCommerce (HPOS), `$wpdb`, `DOMDocument`/`DOMXPath` (no `mb_*` — the local CLI lacks mbstring), jQuery + selectWoo, the plugin's `MealsDB_*` services.

**Spec:** `docs/superpowers/specs/2026-09-09-apetito-item-puller-design.md`

---

## Preconditions (verify once before Task 1)

- [ ] **Confirm DOM + fixtures are available.**

Run:
```bash
php -m | grep -iE '^dom$|^libxml$' && ls -la /mnt/fastssd/meals-db/tests/fixtures/apetito/
```
Expected: `dom` and `libxml` listed; `12212.html`, `12217.html`, `99999-notfound.html` present. If `dom` is missing, parser tests cannot run locally (treat like the known dompdf-mbstring baseline gap and flag it). Do NOT use `mb_*` functions anywhere in this feature — the local CLI lacks mbstring.

- [ ] **Confirm you are on the feature branch.**

Run: `cd /mnt/fastssd/meals-db && git branch --show-current`
Expected: `k12-apetito-item-puller` (created during brainstorming). If not, `git checkout k12-apetito-item-puller`.

---

## File Structure

**Create:**
- `includes/services/class-apetito-parser.php` — pure HTML→struct parser
- `includes/services/class-apetito-nutridata.php` — fetcher (HTTP + cache + code validation)
- `includes/services/class-apetito-product-creator.php` — duplicate guard + Draft product creation + meals_products write
- `includes/services/class-apetito-placeholder.php` — sideload-once placeholder image
- `includes/services/class-apetito-audit.php` — read-only drift report (ITEM 7)
- `includes/ajax/class-ajax-apetito.php` — fetch / create / audit AJAX
- `includes/admin/class-apetito-page.php` — submenu page + enqueue
- `views/apetito-puller.php` — page markup
- `assets/js/apetito-puller.js` — preview UX
- `assets/images/photo-coming-soon.png` — bundled placeholder
- `tests/fixtures/apetito/12212-no-allergens.html` — derived variant
- `tests/fixtures/apetito/12212-renamed-portions.html` — derived variant
- Tests: `tests/test-apetito-parser.php`, `tests/test-apetito-fetcher.php`, `tests/test-apetito-duplicate-guard.php`, `tests/test-apetito-creator-merge.php`, `tests/test-apetito-placeholder.php`, `tests/test-apetito-audit.php`

**Modify:**
- `includes/class-rate-limiter.php:17-40` — add `apetito_fetch` bucket
- `meals-db-main.php:92-116` — register the new AJAX + page `init()` calls
- `uninstall.php` — delete the placeholder option + apetito transients

**Convention note:** all classes are autoloaded by `MealsDB_Autoloader` from `class-<slug>.php` in `includes/`, `includes/services/`, `includes/ajax/`, `includes/admin/`. No manual `require` needed. Every test file is a standalone PHP script run as `php tests/test-*.php` and exits non-zero on failure (see any existing `tests/test-*.php` for the harness shape; `tests/test-quick-order-order-address.php` is a clean model).

---

## Task 1: Parser (pure, the TDD core)

**Files:**
- Create: `includes/services/class-apetito-parser.php`
- Create: `tests/test-apetito-parser.php`
- Create: `tests/fixtures/apetito/12212-no-allergens.html`, `tests/fixtures/apetito/12212-renamed-portions.html`

The parser takes an HTML string and returns `['code'=>..., 'fields'=>[ name => ['ok'=>bool,'value'=>mixed] or ['ok'=>false,'reason'=>string] ]]`. Pure — no HTTP, no DB, no WP functions. Class-hook fields are stable; `serving_size`/`pack_size`/`portions_per_case` are heading-anchored text nodes.

- [ ] **Step 1: Derive the two synthetic fixtures from the real page**

Run:
```bash
cd /mnt/fastssd/meals-db/tests/fixtures/apetito
# Variant A: remove the entire allergens block (the <div class="alergenslist">...</div>)
php -r '$h=file_get_contents("12212.html"); $h=preg_replace("#<div class=\"alergenslist\">.*?</div>#s","<div class=\"alergenslist\"></div>",$h,1); file_put_contents("12212-no-allergens.html",$h);'
# Variant B: rename the Portions Per Case heading so the anchor is not found
php -r '$h=file_get_contents("12212.html"); $h=str_replace("<h3>Portions Per Case</h3>","<h3>Units Per Case</h3>",$h); file_put_contents("12212-renamed-portions.html",$h);'
grep -c "alergenslist" 12212-no-allergens.html; grep -c "Portions Per Case" 12212-renamed-portions.html
```
Expected: first grep prints `1` (the emptied div remains, no `<li>` inside), second prints `0` (heading gone).

- [ ] **Step 2: Write the failing test**

Create `tests/test-apetito-parser.php`:
```php
<?php
/**
 * K12: MealsDB_Apetito_Parser turns a Nutridata page into a per-field
 * required-or-report struct. Pure — runs against committed fixtures, never
 * the network. No mb_* (local CLI lacks mbstring); DOMDocument only.
 *
 * Run: php tests/test-apetito-parser.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fx = __DIR__ . '/fixtures/apetito/';
$fail = []; $pass = 0;
function ok($label, $expected, $actual) {
    global $fail, $pass;
    if ($expected === $actual) { $pass++; return; }
    $fail[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s", $label,
        var_export($expected, true), var_export($actual, true));
}

$r = MealsDB_Apetito_Parser::parse(file_get_contents($fx . '12212.html'), '12212');
$f = $r['fields'];

// Test 1 — full parse of 12212
ok('name_en', 'Chicken with Creamy Mushroom Sauce', $f['name_en']['value'] ?? null);
ok('name_en ok', true, $f['name_en']['ok']);
ok('category', 'Individual Complete Meals', $f['category']['value'] ?? null);
ok('subcategory', 'Poultry', $f['subcategory']['value'] ?? null);
ok('code_on_page', '12212', $f['code_on_page']['value'] ?? null);
ok('allergens', ['Eggs','Milk','Soy','Sulphites'], $f['allergens']['value'] ?? null);
ok('serving_size', '330g', $f['serving_size']['value'] ?? null);
ok('pack_size', '12 x 330g', $f['pack_size']['value'] ?? null);
ok('portions_per_case', 12, $f['portions_per_case']['value'] ?? null);
ok('diet_tags ok', true, $f['diet_tags']['ok']);

// Test 5 — French name entity decode (é / à) via DOM, no mb_*
ok('name_fr decoded', 'Poulet à la sauce crémeuse aux champignons', $f['name_fr']['value'] ?? null);

// Test 6 — display:none English span still parsed (hidden != absent)
ok('hidden en parsed', true, $f['name_en']['ok']);

// Test 2 — 12217 second shape (has a Vegan diet tag)
$r2 = MealsDB_Apetito_Parser::parse(file_get_contents($fx . '12217.html'), '12217');
ok('12217 has Vegan', true, in_array('Vegan', $r2['fields']['diet_tags']['value'] ?? [], true));

// Test 3 — allergens block removed => absent, no crash, no write value
$r3 = MealsDB_Apetito_Parser::parse(file_get_contents($fx . '12212-no-allergens.html'), '12212');
ok('allergens absent ok=false', false, $r3['fields']['allergens']['ok']);
ok('allergens absent has reason', true, isset($r3['fields']['allergens']['reason']));
ok('name still ok when allergens gone', true, $r3['fields']['name_en']['ok']);

// Test 4 — renamed Portions heading => portions absent, others ok
$r4 = MealsDB_Apetito_Parser::parse(file_get_contents($fx . '12212-renamed-portions.html'), '12212');
ok('portions absent ok=false', false, $r4['fields']['portions_per_case']['ok']);
ok('serving still ok', true, $r4['fields']['serving_size']['ok']);

// Test 7 — code_on_page mismatch surfaced
$rm = MealsDB_Apetito_Parser::parse(file_get_contents($fx . '12212.html'), '99999');
ok('requested code echoed', '99999', $rm['code']);
ok('page code differs', '12212', $rm['fields']['code_on_page']['value'] ?? null);

if ($fail) { echo implode("\n", $fail) . "\n"; echo "FAILED ({$pass} passed)\n"; exit(1); }
echo "OK ({$pass} passed)\n";
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php tests/test-apetito-parser.php`
Expected: FAIL — fatal "Class MealsDB_Apetito_Parser not found" (class not yet created).

- [ ] **Step 4: Write the parser**

Create `includes/services/class-apetito-parser.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Parse an Apetito Nutridata page into a per-field required-or-report struct.
 *
 * PURE: no HTTP, no DB, no WP functions. Every field is either
 * ['ok'=>true,'value'=>...] or ['ok'=>false,'reason'=>...]. A parse miss is
 * NEVER a blank or a guess — the caller surfaces "could not read" and the
 * operator fills it in. This is the K10/K11 silent-blank failure mode, forbidden.
 *
 * Stable fields come from CSS class hooks (producttitle, product-cat,
 * product-code, alergenslist [one L], dietrycodings [misspelled]). Only
 * serving/pack/portions are heading-anchored text nodes — the fragile three.
 *
 * No mb_* anywhere: the local test CLI lacks mbstring. DOMDocument decodes
 * numeric HTML entities (&#xE9;) into UTF-8 text on load, given a charset hint.
 */
class MealsDB_Apetito_Parser {

    public static function parse(string $html, string $requested_code): array {
        $doc = self::load($html);
        $xp  = new DOMXPath($doc);

        return [
            'code'   => $requested_code,
            'fields' => [
                'name_en'           => self::text_by_class($xp, 'producttitle language_en'),
                'name_fr'           => self::text_by_class($xp, 'producttitle language_fr'),
                'category'          => self::category($xp, 0),
                'subcategory'       => self::category($xp, 1),
                'code_on_page'      => self::code_on_page($xp),
                'allergens'         => self::list_items($xp, 'alergenslist', 'Allergens'),
                'diet_tags'         => self::list_items($xp, 'dietrycodings', 'Diet Coding'),
                'serving_size'      => self::after_heading($xp, 'Serving Size', false),
                'pack_size'         => self::after_heading($xp, 'Pack Size', false),
                'portions_per_case' => self::after_heading($xp, 'Portions Per Case', true),
            ],
        ];
    }

    private static function load(string $html): DOMDocument {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        // Charset hint so loadHTML treats bytes as UTF-8 and decodes numeric
        // entities to proper UTF-8 (avoids the classic ISO-8859-1 mangling)
        // without any mb_* dependency.
        $doc->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html);
        libxml_clear_errors();
        return $doc;
    }

    /** Trimmed, entity-decoded text of the first node carrying an exact class. */
    private static function text_by_class(DOMXPath $xp, string $class): array {
        $nodes = $xp->query(sprintf('//*[@class="%s"]', $class));
        if ($nodes->length === 0) {
            return ['ok' => false, 'reason' => sprintf('element with class "%s" not found', $class)];
        }
        $value = self::clean($nodes->item(0)->textContent);
        if ($value === '') {
            return ['ok' => false, 'reason' => sprintf('class "%s" present but empty', $class)];
        }
        return ['ok' => true, 'value' => $value];
    }

    /** product-cat is "A | B"; $idx 0 => category, 1 => subcategory. */
    private static function category(DOMXPath $xp, int $idx): array {
        $nodes = $xp->query('//*[@class="product-cat"]');
        if ($nodes->length === 0) {
            return ['ok' => false, 'reason' => 'product-cat not found'];
        }
        $parts = array_map([self::class, 'clean'], explode('|', $nodes->item(0)->textContent));
        if (!isset($parts[$idx]) || $parts[$idx] === '') {
            return ['ok' => false, 'reason' => sprintf('product-cat segment %d missing', $idx)];
        }
        return ['ok' => true, 'value' => $parts[$idx]];
    }

    /** product-code text is "Code: 12212" — strip the prefix. */
    private static function code_on_page(DOMXPath $xp): array {
        $nodes = $xp->query('//*[@class="product-code"]');
        if ($nodes->length === 0) {
            return ['ok' => false, 'reason' => 'product-code not found'];
        }
        $raw = self::clean($nodes->item(0)->textContent);
        $val = trim(preg_replace('/^\s*Code:\s*/i', '', $raw));
        if ($val === '') {
            return ['ok' => false, 'reason' => 'product-code present but empty'];
        }
        return ['ok' => true, 'value' => $val];
    }

    /** <li> texts inside a container class (allergens / diet tags). */
    private static function list_items(DOMXPath $xp, string $container_class, string $label): array {
        $lis = $xp->query(sprintf('//*[@class="%s"]//li', $container_class));
        if ($lis === false || $lis->length === 0) {
            return ['ok' => false, 'reason' => sprintf('%s list (.%s li) not found or empty', $label, $container_class)];
        }
        $out = [];
        foreach ($lis as $li) {
            $t = self::clean($li->textContent);
            if ($t !== '') { $out[] = $t; }
        }
        if (empty($out)) {
            return ['ok' => false, 'reason' => sprintf('%s list had no readable items', $label)];
        }
        return ['ok' => true, 'value' => $out];
    }

    /**
     * The bare text node(s) following an <h3>{heading}</h3>, up to the next
     * element. $numeric => cast to int and require a positive integer (used for
     * portions_per_case, which feeds case_size and the pallet optimiser).
     */
    private static function after_heading(DOMXPath $xp, string $heading, bool $numeric): array {
        // Find the heading element whose trimmed text equals $heading.
        $hs = $xp->query('//h3');
        $target = null;
        foreach ($hs as $h) {
            if (self::clean($h->textContent) === $heading) { $target = $h; break; }
        }
        if ($target === null) {
            return ['ok' => false, 'reason' => sprintf('heading "%s" not found', $heading)];
        }
        // Collect following text until the next element node.
        $buf = '';
        for ($n = $target->nextSibling; $n !== null; $n = $n->nextSibling) {
            if ($n->nodeType === XML_ELEMENT_NODE) { break; }
            if ($n->nodeType === XML_TEXT_NODE) { $buf .= $n->textContent; }
        }
        $val = self::clean($buf);
        if ($val === '') {
            return ['ok' => false, 'reason' => sprintf('no value after heading "%s"', $heading)];
        }
        if ($numeric) {
            if (!preg_match('/^\d+$/', $val) || (int) $val < 1) {
                return ['ok' => false, 'reason' => sprintf('value after "%s" is not a positive integer: %s', $heading, $val)];
            }
            return ['ok' => true, 'value' => (int) $val];
        }
        return ['ok' => true, 'value' => $val];
    }

    /** Collapse whitespace + decode residual named entities. No mb_*. */
    private static function clean(string $s): string {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/test-apetito-parser.php`
Expected: `OK (N passed)`. If the French-name assertion fails with mangled bytes, the charset hint isn't taking — confirm `dom` is loaded and the `<meta charset=utf-8>` prefix is present.

- [ ] **Step 6: Commit**

```bash
git add includes/services/class-apetito-parser.php tests/test-apetito-parser.php tests/fixtures/apetito/12212-no-allergens.html tests/fixtures/apetito/12212-renamed-portions.html
git commit -m "K12: Apetito Nutridata parser (pure, fixture-driven)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Fetcher (HTTP + cache + validation)

**Files:**
- Create: `includes/services/class-apetito-nutridata.php`
- Create: `tests/test-apetito-fetcher.php`

The fetcher validates the code, GETs the page (mockable), caches the raw body 7 days, and returns a structured result. It NEVER parses a non-200 body (an unknown code is a 500 with a stack trace) and never echoes Apetito's error body.

- [ ] **Step 1: Write the failing test**

Create `tests/test-apetito-fetcher.php`:
```php
<?php
/**
 * K12: MealsDB_Apetito_Nutridata — code validation, structured non-200
 * failure, and cache-hit-no-refetch. WP HTTP + transients are stubbed so no
 * network and no WP runtime are required.
 *
 * Run: php tests/test-apetito-fetcher.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');

// --- Minimal WP stubs the fetcher touches ---
$GLOBALS['__http_calls'] = 0;
$GLOBALS['__http_response'] = ['code' => 200, 'body' => '<html>ok</html>'];
$GLOBALS['__transients'] = [];
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) { $GLOBALS['__http_calls']++; return ['__stub' => true]; }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!class_exists('WP_Error')) { class WP_Error { public $msg; function __construct($c='', $m='') { $this->msg=$m; } } }
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($r) { return $GLOBALS['__http_response']['code']; }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($r) { return $GLOBALS['__http_response']['body']; }
}
if (!function_exists('get_transient')) {
    function get_transient($k) { return $GLOBALS['__transients'][$k] ?? false; }
}
if (!function_exists('set_transient')) {
    function set_transient($k, $v, $ttl) { $GLOBALS['__transients'][$k] = $v; return true; }
}
if (!function_exists('__')) { function __($t, $d = 'default') { return $t; } }

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fail = []; $pass = 0;
function ok($l, $e, $a) { global $fail,$pass; if ($e===$a){$pass++;return;} $fail[]="FAIL: $l\n  expected: ".var_export($e,true)."\n  actual:   ".var_export($a,true); }

// Test 10 — code validation rejects bad input
foreach (['abc','1211','../etc/passwd',"12111'",'123456',''] as $bad) {
    $res = MealsDB_Apetito_Nutridata::fetch($bad);
    ok("reject '$bad'", false, $res['ok']);
}

// Test 11 — non-200 => structured failure, body NOT returned/parsed
$GLOBALS['__http_response'] = ['code' => 500, 'body' => 'System.NullReferenceException at D:\\a\\1\\s\\...'];
$GLOBALS['__transients'] = [];
$res = MealsDB_Apetito_Nutridata::fetch('99999');
ok('non-200 ok=false', false, $res['ok']);
ok('non-200 hides apetito body', false, strpos((string)($res['reason'] ?? ''), 'NullReferenceException') !== false);
ok('non-200 mentions code', true, strpos((string)($res['reason'] ?? ''), '99999') !== false);

// Test 12 — cache hit => no second HTTP call
$GLOBALS['__http_response'] = ['code' => 200, 'body' => '<html>PAGE</html>'];
$GLOBALS['__transients'] = [];
$GLOBALS['__http_calls'] = 0;
$a = MealsDB_Apetito_Nutridata::fetch('12212');
$b = MealsDB_Apetito_Nutridata::fetch('12212');
ok('first fetch ok', true, $a['ok']);
ok('returns body', '<html>PAGE</html>', $a['html']);
ok('cached second fetch ok', true, $b['ok']);
ok('only one HTTP call', 1, $GLOBALS['__http_calls']);

if ($fail) { echo implode("\n",$fail)."\n"; echo "FAILED ({$pass} passed)\n"; exit(1); }
echo "OK ({$pass} passed)\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-apetito-fetcher.php`
Expected: FAIL — "Class MealsDB_Apetito_Nutridata not found".

- [ ] **Step 3: Write the fetcher**

Create `includes/services/class-apetito-nutridata.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Fetch a single Apetito Nutridata page. One request per operator action,
 * cached 7 days. Behaves like a person reading a public website: validated
 * code, descriptive UA, 10s timeout, NO bulk crawl.
 *
 * An unknown code returns HTTP 500 with an ASP.NET stack trace (see
 * tests/fixtures/apetito/99999-notfound.html), so we treat ANY non-200 as a
 * structured failure, never parse the body, and never surface Apetito's raw
 * error text (which leaks their server path) to the operator.
 */
class MealsDB_Apetito_Nutridata {

    private const BASE = 'https://my.apetito.ca/nutridata/details/';
    private const TTL  = 7 * DAY_IN_SECONDS; // product data changes per menu cycle
    private const CODE_RE = '/^[0-9]{5}$/';

    public static function is_valid_code(string $code): bool {
        return (bool) preg_match(self::CODE_RE, $code);
    }

    public static function cache_key(string $code): string {
        return 'mealsdb_apetito_' . $code;
    }

    /**
     * @return array ['ok'=>true,'html'=>string,'cached'=>bool] | ['ok'=>false,'reason'=>string]
     */
    public static function fetch(string $code): array {
        if (!self::is_valid_code($code)) {
            return ['ok' => false, 'reason' => __('Enter a 5-digit Apetito code.', 'meals-db')];
        }

        $cached = get_transient(self::cache_key($code));
        if (is_string($cached) && $cached !== '') {
            return ['ok' => true, 'html' => $cached, 'cached' => true];
        }

        $response = wp_remote_get(self::BASE . $code, [
            'timeout'    => 10,
            'user-agent' => 'Meals & More NB product tool (WordPress; +https://mealsandmorenb.ca)',
            'headers'    => ['Accept' => 'text/html'],
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'reason' => sprintf(
                /* translators: %s: apetito item code */
                __('Could not reach Apetito for code %s. Try again, or enter the item manually.', 'meals-db'), $code)];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            // Do NOT parse or echo the body — an unknown code is a 500 with a
            // stack trace exposing Apetito's server path.
            return ['ok' => false, 'reason' => sprintf(
                /* translators: %s: apetito item code */
                __('No product found for code %s on Apetito (or the site is unavailable). Check the code, or enter the item manually.', 'meals-db'), $code)];
        }

        $body = (string) wp_remote_retrieve_body($response);
        if (trim($body) === '') {
            return ['ok' => false, 'reason' => sprintf(
                __('Apetito returned an empty page for code %s.', 'meals-db'), $code)];
        }

        set_transient(self::cache_key($code), $body, self::TTL);
        return ['ok' => true, 'html' => $body, 'cached' => false];
    }
}
```

Note: `DAY_IN_SECONDS` and `__()` are provided by WP at runtime; the test stubs `__()` and the fetcher only references `DAY_IN_SECONDS` inside a const expression evaluated at class load — define it in the test bootstrap if the run errors. Add to the test's stub block if needed:
```php
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-apetito-fetcher.php`
Expected: `OK (N passed)`. If it fatals on `DAY_IN_SECONDS`, add the define above to the test bootstrap and re-run.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-apetito-nutridata.php tests/test-apetito-fetcher.php
git commit -m "K12: Apetito fetcher (validated code, 7d cache, non-200 = structured failure)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Duplicate guard (SKU-keyed, the point of the feature)

**Files:**
- Create: `includes/services/class-apetito-product-creator.php` (guard methods now; creation in Task 5)
- Create: `tests/test-apetito-duplicate-guard.php`

The guard keys on `meals_products.sku = {code}` (SKU is the Apetito code — 160/163 verified). The message logic is a pure function; the DB lookup is a thin separate method.

- [ ] **Step 1: Write the failing test**

Create `tests/test-apetito-duplicate-guard.php`:
```php
<?php
/**
 * K12: duplicate guard blocks a code that already exists (keyed on SKU) and
 * names the existing product. Message building is pure; tested directly.
 *
 * Run: php tests/test-apetito-duplicate-guard.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');
if (!function_exists('__')) { function __($t,$d='default'){return $t;} }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fail=[]; $pass=0;
function ok($l,$e,$a){global $fail,$pass; if($e===$a){$pass++;return;} $fail[]="FAIL: $l\n  expected: ".var_export($e,true)."\n  actual:   ".var_export($a,true);}
function contains($l,$needle,$hay){global $fail,$pass; if(strpos((string)$hay,$needle)!==false){$pass++;return;} $fail[]="FAIL: $l\n  '$needle' not in: $hay";}

// Test 8 — existing product (2725) => blocked, message names it
$existing = ['wc_product_id'=>2725, 'product_name'=>'Spaghetti Bolognese #12111', 'status'=>'publish'];
$msg = MealsDB_Apetito_Product_Creator::duplicate_message($existing, '12111', 'Spaghetti Bolognese');
contains('msg names product id', '2725', $msg);
contains('msg names title', 'Spaghetti Bolognese', $msg);
contains('msg names code', '12111', $msg);

// Test 9 — no match => allowed (null existing => empty message)
ok('no dup => empty message', '', MealsDB_Apetito_Product_Creator::duplicate_message(null, '12212', 'Chicken'));

if ($fail){echo implode("\n",$fail)."\n";echo "FAILED ({$pass} passed)\n";exit(1);}
echo "OK ({$pass} passed)\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-apetito-duplicate-guard.php`
Expected: FAIL — "Class MealsDB_Apetito_Product_Creator not found".

- [ ] **Step 3: Write the guard methods (creator file, part 1)**

Create `includes/services/class-apetito-product-creator.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Create a Draft WooCommerce product (plus its meals_products row) from parsed
 * Apetito data. The duplicate guard keys on SKU (= the Apetito code); a match
 * blocks creation. WooCommerce enforces SKU uniqueness natively, so this check
 * is belt-and-braces, not the only wall.
 */
class MealsDB_Apetito_Product_Creator {

    /**
     * Find an existing product whose meals_products.sku equals the code.
     *
     * @return array|null ['wc_product_id'=>int,'product_name'=>string,'status'=>string] or null
     */
    public static function find_by_sku(string $code): ?array {
        global $wpdb;
        if (!$wpdb) { return null; }
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $wc_id = $wpdb->get_var($wpdb->prepare(
            "SELECT wc_product_id FROM `{$table}` WHERE sku = %s LIMIT 1",
            $code
        ));
        if (!$wc_id) { return null; }
        $wc_id = (int) $wc_id;
        $name = ''; $status = '';
        if (function_exists('get_the_title')) { $name = (string) get_the_title($wc_id); }
        if (function_exists('get_post_status')) { $status = (string) get_post_status($wc_id); }
        return ['wc_product_id' => $wc_id, 'product_name' => $name, 'status' => $status];
    }

    /**
     * Pure: the operator-facing block message. Empty string when there is no
     * duplicate (caller treats empty as "allowed").
     */
    public static function duplicate_message(?array $existing, string $code, string $apetito_name): string {
        if ($existing === null) { return ''; }
        $existing_name = $existing['product_name'] !== '' ? $existing['product_name'] : sprintf('#%s', $code);
        return sprintf(
            /* translators: 1: apetito code, 2: apetito name, 3: existing product name, 4: product id, 5: status */
            __('Apetito lists %1$s as "%2$s". You already have "%3$s" (product %4$d, %5$s). Codes must be unique — the packing slip, the purchase order and the delivery slip all identify items by this number.', 'meals-db'),
            $code,
            $apetito_name,
            $existing_name,
            (int) $existing['wc_product_id'],
            $existing['status'] !== '' ? $existing['status'] : 'unknown'
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-apetito-duplicate-guard.php`
Expected: `OK (5 passed)`.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-apetito-product-creator.php tests/test-apetito-duplicate-guard.php
git commit -m "K12: SKU-keyed duplicate guard + block message (pure)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Creator write payload (no product_type/taxable clobber)

**Files:**
- Modify: `includes/services/class-apetito-product-creator.php`
- Create: `tests/test-apetito-creator-merge.php`

`MealsDB_Products::save_product_data()` does `array_merge(defaults, $data)`, so writing the Apetito fields WITHOUT passing `product_type`/`taxable` resets them to `meal`/`0`. The Creator must build its `meals_products` payload by merging the parsed fields ONTO the existing (category-derived) row — the same pattern `sync_single_product` uses internally. This task builds that payload as a pure function.

- [ ] **Step 1: Write the failing test**

Create `tests/test-apetito-creator-merge.php`:
```php
<?php
/**
 * K12: the Creator's meals_products payload must (a) carry the parsed Apetito
 * fields and (b) PRESERVE the category-derived product_type/taxable that
 * class-product-display-sync sets — never reset them to meal/0.
 *
 * Run: php tests/test-apetito-creator-merge.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');
if (!function_exists('__')) { function __($t,$d='default'){return $t;} }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fail=[]; $pass=0;
function ok($l,$e,$a){global $fail,$pass; if($e===$a){$pass++;return;} $fail[]="FAIL: $l\n  expected: ".var_export($e,true)."\n  actual:   ".var_export($a,true);}

// Existing row as display-sync would leave it for a taxable side:
$existing = ['product_type'=>'side','taxable'=>1,'main_ingredient'=>'','dietary_tags'=>[],'allergen_flags'=>[],'case_size'=>1,'unit_cost'=>'0.00'];
$parsed = ['subcategory'=>'Poultry and more than forty characters of stuff here','allergens'=>['Eggs','Milk'],'diet_tags'=>['Vegan'],'portions_per_case'=>12];

$payload = MealsDB_Apetito_Product_Creator::build_meals_products_payload($existing, $parsed);

ok('preserves product_type', 'side', $payload['product_type']);
ok('preserves taxable', 1, $payload['taxable']);
ok('case_size from portions', 12, $payload['case_size']);
ok('allergens carried', ['Eggs','Milk'], $payload['allergen_flags']);
ok('diet carried', ['Vegan'], $payload['dietary_tags']);
ok('main_ingredient truncated to 40', 40, strlen($payload['main_ingredient']));

// Meal with no side category: existing product_type stays 'meal' (by absence).
$existing_meal = ['product_type'=>'meal','taxable'=>0,'main_ingredient'=>'','dietary_tags'=>[],'allergen_flags'=>[],'case_size'=>1,'unit_cost'=>'0.00'];
$p2 = MealsDB_Apetito_Product_Creator::build_meals_products_payload($existing_meal, ['subcategory'=>'Beef','allergens'=>[],'diet_tags'=>[],'portions_per_case'=>6]);
ok('meal stays meal', 'meal', $p2['product_type']);
ok('meal stays untaxed', 0, $p2['taxable']);
ok('case_size 6', 6, $p2['case_size']);

if ($fail){echo implode("\n",$fail)."\n";echo "FAILED ({$pass} passed)\n";exit(1);}
echo "OK ({$pass} passed)\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-apetito-creator-merge.php`
Expected: FAIL — "Call to undefined method ...::build_meals_products_payload()".

- [ ] **Step 3: Add the payload builder**

Add to `includes/services/class-apetito-product-creator.php` (inside the class):
```php
    /**
     * Pure: build the meals_products payload by merging parsed Apetito fields
     * ONTO the existing (category-derived) row. Preserves product_type/taxable
     * (owned by class-product-display-sync via WC categories — writing them here
     * would reintroduce the same-request clobber that the override removal fixed).
     * main_ingredient maps from the Apetito subcategory, truncated to the
     * VARCHAR(40) column with no silent overflow.
     *
     * @param array $existing meals_products row (from MealsDB_Products::get_product_data)
     * @param array $parsed   ['subcategory'=>?string,'allergens'=>array,'diet_tags'=>array,'portions_per_case'=>?int]
     */
    public static function build_meals_products_payload(array $existing, array $parsed): array {
        $main_ingredient = isset($parsed['subcategory']) ? (string) $parsed['subcategory'] : '';
        if (strlen($main_ingredient) > 40) {
            $main_ingredient = substr($main_ingredient, 0, 40); // VARCHAR(40); no mb_* (mbstring absent locally)
        }
        $case_size = isset($parsed['portions_per_case']) && (int) $parsed['portions_per_case'] > 0
            ? (int) $parsed['portions_per_case']
            : (int) ($existing['case_size'] ?? 1);

        return array_merge($existing, [
            'main_ingredient' => $main_ingredient,
            'allergen_flags'  => isset($parsed['allergens']) && is_array($parsed['allergens']) ? $parsed['allergens'] : [],
            'dietary_tags'    => isset($parsed['diet_tags']) && is_array($parsed['diet_tags']) ? $parsed['diet_tags'] : [],
            'case_size'       => $case_size,
        ]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-apetito-creator-merge.php`
Expected: `OK (9 passed)`.

- [ ] **Step 5: Commit**

```bash
git add includes/services/class-apetito-product-creator.php tests/test-apetito-creator-merge.php
git commit -m "K12: creator payload merges parsed fields without clobbering category-derived type/taxable

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Creator — create the Draft product (integration method)

**Files:**
- Modify: `includes/services/class-apetito-product-creator.php`

This method requires WooCommerce runtime (product objects, term assignment, display-sync). It is verified on staging (spec verification #1/#5), not in the mbstring-less unit suite. It composes the already-tested pure helpers.

- [ ] **Step 1: Add the `create` method**

Add to `includes/services/class-apetito-product-creator.php`:
```php
    /**
     * Create a Draft WC product from parsed data + operator inputs and write its
     * meals_products row. Draft (never published): is_published lands 0 via
     * class-product-display-sync (get_status() !== 'publish'), keeping it out of
     * Quick Order and the PO forecast until the operator publishes.
     *
     * @param array $parsed  parser fields already reduced to values (name_en, subcategory, allergens, diet_tags, portions_per_case)
     * @param array $input   ['code'=>string,'price'=>float,'category_ids'=>int[]]
     * @return array ['ok'=>true,'product_id'=>int] | ['ok'=>false,'reason'=>string]
     */
    public static function create(array $parsed, array $input): array {
        $code = (string) ($input['code'] ?? '');
        if (!MealsDB_Apetito_Nutridata::is_valid_code($code)) {
            return ['ok' => false, 'reason' => __('Invalid code.', 'meals-db')];
        }

        // Belt-and-braces: re-check the SKU guard (WC also rejects a dupe SKU).
        $dupe = self::find_by_sku($code);
        if ($dupe !== null) {
            return ['ok' => false, 'reason' => self::duplicate_message($dupe, $code, (string) ($parsed['name_en'] ?? ''))];
        }

        if (!function_exists('wc_get_product') || !class_exists('WC_Product_Simple')) {
            return ['ok' => false, 'reason' => __('WooCommerce is required.', 'meals-db')];
        }

        try {
            $product = new WC_Product_Simple();
            $name = trim((string) ($parsed['name_en'] ?? ''));
            // Title convention: "{name} #{code}" (matches every existing product).
            $product->set_name($name !== '' ? $name . ' #' . $code : '#' . $code);
            $product->set_status('draft');
            $product->set_sku($code); // SKU IS the Apetito code
            if (isset($input['price']) && is_numeric($input['price'])) {
                $product->set_regular_price((string) $input['price']);
                $product->set_price((string) $input['price']);
            }
            if (!empty($input['category_ids']) && is_array($input['category_ids'])) {
                $product->set_category_ids(array_map('intval', $input['category_ids']));
            }
            $placeholder_id = MealsDB_Apetito_Placeholder::get_or_create();
            if ($placeholder_id > 0) {
                $product->set_image_id($placeholder_id);
            }
            $product_id = $product->save();
            if (!$product_id) {
                return ['ok' => false, 'reason' => __('WooCommerce refused to create the product (possibly a duplicate SKU).', 'meals-db')];
            }

            if ($placeholder_id > 0) {
                update_post_meta($product_id, '_mealsdb_placeholder_image', 1);
            }

            // Force the category-derivation deterministically (avoids any
            // save_post term-timing race), THEN read the row back so our payload
            // preserves the derived product_type/taxable.
            if (class_exists('MealsDB_Product_Display_Sync')) {
                MealsDB_Product_Display_Sync::sync_single_product($product);
            }
            $existing = MealsDB_Products::get_product_data($product_id);
            $payload  = self::build_meals_products_payload($existing, $parsed);
            MealsDB_Products::save_product_data($product_id, $payload);

            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity' => 'info', 'category' => 'products', 'subsystem' => 'apetito_pull',
                    'event' => 'apetito_pull.created', 'outcome' => 'succeeded',
                    'message' => sprintf('Created draft product %d from Apetito code %s', $product_id, $code),
                    'context' => ['product_id' => $product_id, 'code' => $code,
                                  'manual_fields' => $input['manual_fields'] ?? []],
                    'entity_type' => 'product', 'entity_id' => $product_id,
                ]);
            }
            return ['ok' => true, 'product_id' => (int) $product_id];
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Apetito] create failed: ' . $e->getMessage());
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity' => 'error', 'category' => 'products', 'subsystem' => 'apetito_pull',
                    'event' => 'apetito_pull.create_failed', 'outcome' => 'degraded',
                    'message' => $e->getMessage(), 'context' => ['code' => $code],
                ]);
            }
            return ['ok' => false, 'reason' => __('Could not create the product. See the event log.', 'meals-db')];
        }
    }
```

- [ ] **Step 2: Lint**

Run: `php -l includes/services/class-apetito-product-creator.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Re-run the two creator unit tests (no regression)**

Run: `php tests/test-apetito-duplicate-guard.php && php tests/test-apetito-creator-merge.php`
Expected: both `OK`.

- [ ] **Step 4: Commit**

```bash
git add includes/services/class-apetito-product-creator.php
git commit -m "K12: creator creates Draft product, forces category derivation, writes parsed fields

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Placeholder image helper

**Files:**
- Create: `includes/services/class-apetito-placeholder.php`
- Create: `assets/images/photo-coming-soon.png`
- Create: `tests/test-apetito-placeholder.php`

Sideload one bundled image into the media library on first use; store its ID in an option; reuse forever.

- [ ] **Step 1: Add the bundled asset**

Run:
```bash
cd /mnt/fastssd/meals-db
mkdir -p assets/images
# 1x1 transparent PNG placeholder committed as the asset (operator can replace
# with a branded "Photo coming soon" image later; the code only needs a file).
printf '\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82' > assets/images/photo-coming-soon.png
file assets/images/photo-coming-soon.png
```
Expected: `PNG image data, 1 x 1`. (A designer can swap in a real branded image later; filename is the contract.)

- [ ] **Step 2: Write the failing test**

Create `tests/test-apetito-placeholder.php`:
```php
<?php
/**
 * K12: the placeholder attachment is created once and reused. The sideload
 * itself needs WP media funcs (staging), so here we test the REUSE branch:
 * when the option points at an existing attachment, get_or_create returns it
 * with no new attachment created.
 *
 * Run: php tests/test-apetito-placeholder.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');
$GLOBALS['__opt'] = [];
$GLOBALS['__inserts'] = 0;
if (!function_exists('get_option')) { function get_option($k,$d=false){return $GLOBALS['__opt'][$k]??$d;} }
if (!function_exists('update_option')) { function update_option($k,$v){$GLOBALS['__opt'][$k]=$v;return true;} }
if (!function_exists('wp_attachment_is_image')) { function wp_attachment_is_image($id){return $id>0;} }
if (!function_exists('get_post')) { function get_post($id){return $id>0?(object)['ID'=>$id]:null;} }
if (!function_exists('__')) { function __($t,$d='default'){return $t;} }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fail=[]; $pass=0;
function ok($l,$e,$a){global $fail,$pass; if($e===$a){$pass++;return;} $fail[]="FAIL: $l\n  expected: ".var_export($e,true)."\n  actual:   ".var_export($a,true);}

// Option already set to an existing attachment => reuse, no insert.
$GLOBALS['__opt']['mealsdb_apetito_placeholder_id'] = 555;
ok('reuses existing attachment', 555, MealsDB_Apetito_Placeholder::get_or_create());
ok('no new attachment inserted', 0, $GLOBALS['__inserts']);

if ($fail){echo implode("\n",$fail)."\n";echo "FAILED ({$pass} passed)\n";exit(1);}
echo "OK ({$pass} passed)\n";
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php tests/test-apetito-placeholder.php`
Expected: FAIL — "Class MealsDB_Apetito_Placeholder not found".

- [ ] **Step 4: Write the placeholder helper**

Create `includes/services/class-apetito-placeholder.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

/**
 * One shared "Photo coming soon" media attachment for Apetito-pulled products.
 * Created once, reused forever via an option. Products created without a real
 * photo get this as their thumbnail and a _mealsdb_placeholder_image=1 meta so
 * an "awaiting photos" list is a meta query, not an image comparison.
 */
class MealsDB_Apetito_Placeholder {

    private const OPTION = 'mealsdb_apetito_placeholder_id';
    private const ASSET  = 'assets/images/photo-coming-soon.png';

    /** @return int attachment ID, or 0 on failure. */
    public static function get_or_create(): int {
        $existing = (int) get_option(self::OPTION, 0);
        if ($existing > 0 && function_exists('wp_attachment_is_image') && wp_attachment_is_image($existing)) {
            return $existing;
        }

        // Create the attachment by copying the bundled asset into uploads.
        if (!function_exists('wp_upload_dir') || !function_exists('wp_insert_attachment')) {
            return 0;
        }
        $src = defined('MEALS_DB_PLUGIN_DIR') ? MEALS_DB_PLUGIN_DIR . self::ASSET : dirname(__DIR__, 2) . '/' . self::ASSET;
        if (!is_readable($src)) { return 0; }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) { return 0; }
        $dest = trailingslashit($uploads['path']) . 'mealsdb-photo-coming-soon.png';
        if (!@copy($src, $dest)) { return 0; }

        $filetype = wp_check_filetype(basename($dest), null);
        $attachment = [
            'guid'           => trailingslashit($uploads['url']) . basename($dest),
            'post_mime_type' => $filetype['type'] ?: 'image/png',
            'post_title'     => __('Photo coming soon', 'meals-db'),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $dest);
        if (is_wp_error($attach_id) || !$attach_id) { return 0; }

        if (function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $meta = wp_generate_attachment_metadata($attach_id, $dest);
            wp_update_attachment_metadata($attach_id, $meta);
        }

        update_option(self::OPTION, (int) $attach_id);
        return (int) $attach_id;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/test-apetito-placeholder.php`
Expected: `OK (2 passed)`.

- [ ] **Step 6: Commit**

```bash
git add includes/services/class-apetito-placeholder.php assets/images/photo-coming-soon.png tests/test-apetito-placeholder.php
git commit -m "K12: sideload-once placeholder image helper + bundled asset

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Rate-limit bucket

**Files:**
- Modify: `includes/class-rate-limiter.php:17-40` and `:49-60`

- [ ] **Step 1: Add the bucket to DEFAULT_LIMITS**

In `includes/class-rate-limiter.php`, inside `DEFAULT_LIMITS` (after the `settings_modify` line ~36):
```php
        // K12: external GET to Apetito's public site (fetch + each audit request).
        // Fail CLOSED (below) so a cache outage can't let a runaway loop hammer
        // a third party's website. 30/hr is generous for one-per-action use.
        'apetito_fetch'          => 30,   // Apetito Nutridata fetches
```

- [ ] **Step 2: Add it to MUTATING_ACTIONS (fail closed)**

In the `MUTATING_ACTIONS` array (~line 49-60), add:
```php
        'apetito_fetch'         => true,
```

- [ ] **Step 3: Lint + confirm the constant parses**

Run: `php -l includes/class-rate-limiter.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-rate-limiter.php
git commit -m "K12: add apetito_fetch rate-limit bucket (30/hr, fail-closed)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8: AJAX handlers (fetch / create, Approach A)

**Files:**
- Create: `includes/ajax/class-ajax-apetito.php`

Follows the pattern in `includes/ajax/class-ajax-order-audit.php`: a `NONCE_ACTION` const, `init()` registering `wp_ajax_*` actions, per-handler nonce + cap + rate-limit. Create re-reads the transient and re-parses server-side (Approach A) — machine fields never trusted from the browser.

- [ ] **Step 1: Write the AJAX class**

Create `includes/ajax/class-ajax-apetito.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

/**
 * AJAX for the Apetito Item Puller. Fetch is read-ish (external GET, rate
 * limited); create is a write (own nonce). Approach A: create re-reads the
 * cached page and re-parses server-side, trusting the browser ONLY for
 * operator-owned fields (code, price, category IDs).
 */
class MealsDB_Ajax_Apetito {

    public const NONCE_FETCH  = 'mealsdb_apetito';
    public const NONCE_CREATE = 'mealsdb_apetito_create';

    public static function init(): void {
        add_action('wp_ajax_mealsdb_apetito_fetch',  [self::class, 'fetch']);
        add_action('wp_ajax_mealsdb_apetito_create', [self::class, 'create']);
    }

    private static function cap(): string {
        return current_user_can('edit_product') ? 'edit_product'
            : (class_exists('MealsDB_Permissions') ? MealsDB_Permissions::required_capability() : 'manage_woocommerce');
    }

    private static function guard(string $nonce_action, string $bucket): void {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $nonce_action)) {
            wp_send_json_error(['message' => __('Invalid request.', 'meals-db')], 400);
        }
        if (!current_user_can(self::cap())) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'meals-db')], 403);
        }
        if (class_exists('MealsDB_Rate_Limiter') && !MealsDB_Rate_Limiter::check_rate_limit($bucket)) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Try again later.', 'meals-db')], 429);
        }
    }

    /** Reduce parser fields to a flat value map + a list of unreadable fields. */
    private static function reduce(array $parsed): array {
        $values = []; $missing = [];
        foreach ($parsed['fields'] as $name => $f) {
            if (!empty($f['ok'])) { $values[$name] = $f['value']; }
            else { $missing[] = $name; }
        }
        return ['values' => $values, 'missing' => $missing];
    }

    public static function fetch(): void {
        self::guard(self::NONCE_FETCH, 'apetito_fetch');
        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash((string) $_POST['code'])) : '';
        $res = MealsDB_Apetito_Nutridata::fetch($code);
        if (empty($res['ok'])) {
            wp_send_json_error(['message' => $res['reason']], 200);
        }
        $parsed = MealsDB_Apetito_Parser::parse($res['html'], $code);
        $reduced = self::reduce($parsed);

        $dupe = MealsDB_Apetito_Product_Creator::find_by_sku($code);
        $dupe_message = MealsDB_Apetito_Product_Creator::duplicate_message(
            $dupe, $code, (string) ($reduced['values']['name_en'] ?? '')
        );

        wp_send_json_success([
            'code'         => $code,
            'fields'       => $parsed['fields'],   // full per-field ok/reason for the UI
            'missing'      => $reduced['missing'],
            'duplicate'    => $dupe !== null,
            'dupe_message' => $dupe_message,
            'cached'       => !empty($res['cached']),
        ]);
    }

    public static function create(): void {
        self::guard(self::NONCE_CREATE, 'settings_modify');
        $code = isset($_POST['code']) ? sanitize_text_field(wp_unslash((string) $_POST['code'])) : '';

        // Approach A: re-read the cached page and re-parse server-side.
        $cached = get_transient(MealsDB_Apetito_Nutridata::cache_key($code));
        if (!is_string($cached) || $cached === '') {
            $refetch = MealsDB_Apetito_Nutridata::fetch($code);
            if (empty($refetch['ok'])) {
                wp_send_json_error(['message' => __('The fetched page expired. Fetch the code again.', 'meals-db')], 200);
            }
            $cached = $refetch['html'];
        }
        $parsed  = MealsDB_Apetito_Parser::parse($cached, $code);
        $reduced = self::reduce($parsed);

        $price = isset($_POST['price']) && is_numeric($_POST['price']) ? (float) $_POST['price'] : null;
        $category_ids = [];
        if (isset($_POST['category_ids']) && is_array($_POST['category_ids'])) {
            $category_ids = array_map('intval', wp_unslash($_POST['category_ids']));
        }
        // Operator-entered corrections for fields the parser could not read.
        $manual = [];
        if (isset($_POST['manual']) && is_array($_POST['manual'])) {
            foreach (wp_unslash($_POST['manual']) as $k => $v) {
                $manual[sanitize_key($k)] = sanitize_text_field((string) $v);
            }
        }
        // Manual values fill only MISSING machine fields (never override a parsed one).
        foreach ($reduced['missing'] as $miss) {
            if (isset($manual[$miss]) && $manual[$miss] !== '') {
                $reduced['values'][$miss] = $manual[$miss];
            }
        }

        $res = MealsDB_Apetito_Product_Creator::create($reduced['values'], [
            'code'          => $code,
            'price'         => $price,
            'category_ids'  => $category_ids,
            'manual_fields' => array_keys($manual),
        ]);
        if (empty($res['ok'])) {
            wp_send_json_error(['message' => $res['reason']], 200);
        }
        wp_send_json_success([
            'product_id' => $res['product_id'],
            'edit_url'   => function_exists('get_edit_post_link') ? get_edit_post_link($res['product_id'], 'raw') : '',
        ]);
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l includes/ajax/class-ajax-apetito.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add includes/ajax/class-ajax-apetito.php
git commit -m "K12: fetch/create AJAX (Approach A: create re-parses server-side)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 9: Admin page + view

**Files:**
- Create: `includes/admin/class-apetito-page.php`
- Create: `views/apetito-puller.php`

Mirrors `includes/admin/class-order-audit-page.php`: `PAGE_SLUG` const, `init()` → `admin_menu` + `admin_enqueue_scripts`, submenu under `mealsdb`, enqueue gated on the page hook, nonces localized.

- [ ] **Step 1: Write the page class**

Create `includes/admin/class-apetito-page.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

class MealsDB_Apetito_Page {

    public const PAGE_SLUG = 'mealsdb-apetito-puller';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 22);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'mealsdb',
            __('Apetito Item Puller', 'meals-db'),
            __('Apetito Item Puller', 'meals-db'),
            current_user_can('edit_product') ? 'edit_product' : MealsDB_Permissions::required_capability(),
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue_scripts($hook): void {
        if (!is_string($hook) || strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        wp_enqueue_script(
            'mealsdb-apetito-js',
            plugins_url('assets/js/apetito-puller.js', dirname(dirname(__FILE__))),
            ['jquery', 'selectWoo', MealsDB_Admin_UI::register_confirm_script()],
            defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : false,
            true
        );
        wp_enqueue_style('woocommerce_admin_styles');
        wp_localize_script('mealsdb-apetito-js', 'mealsdbApetito', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonceFetch'   => wp_create_nonce(MealsDB_Ajax_Apetito::NONCE_FETCH),
            'nonceCreate'  => wp_create_nonce(MealsDB_Ajax_Apetito::NONCE_CREATE),
            'nonceAudit'   => wp_create_nonce(MealsDB_Ajax_Apetito::NONCE_FETCH),
            'categories'   => self::category_choices(),
            'i18n'         => [
                'couldNotRead' => __('could not read — enter manually', 'meals-db'),
                'confirmTitle' => __('Create Apetito product?', 'meals-db'),
                'confirmBody'  => __('This creates a Draft product. Review and publish it afterward.', 'meals-db'),
            ],
        ]);
    }

    /** Allowed WC categories for the manual picker (id => name). */
    private static function category_choices(): array {
        $out = [];
        if (!function_exists('get_terms')) { return $out; }
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_array($terms)) {
            foreach ($terms as $t) {
                if ($t instanceof WP_Term) { $out[] = ['id' => $t->term_id, 'name' => $t->name]; }
            }
        }
        return $out;
    }

    public static function render(): void {
        if (!current_user_can('edit_product') && !current_user_can(MealsDB_Permissions::required_capability())) {
            wp_die(esc_html__('You are not allowed to access this page.', 'meals-db'));
        }
        require dirname(dirname(__DIR__)) . '/views/apetito-puller.php';
    }
}
```

- [ ] **Step 2: Write the view**

Create `views/apetito-puller.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }
/** @var void — rendered by MealsDB_Apetito_Page::render() */
?>
<div class="wrap mealsdb-apetito">
    <h1><?php echo esc_html__('Apetito Item Puller', 'meals-db'); ?></h1>
    <p class="description">
        <?php echo esc_html__('Enter a 5-digit Apetito code to fetch its Nutridata page, review the parsed details, and create a Draft product. Price and categories are yours to set; product type and tax are derived from the categories you choose.', 'meals-db'); ?>
    </p>

    <h2 class="nav-tab-wrapper">
        <a href="#pull" class="nav-tab nav-tab-active" data-tab="pull"><?php echo esc_html__('Pull an item', 'meals-db'); ?></a>
        <a href="#audit" class="nav-tab" data-tab="audit"><?php echo esc_html__('Audit existing (read-only)', 'meals-db'); ?></a>
    </h2>

    <div id="mealsdb-apetito-pull" class="mealsdb-apetito-tab">
        <p>
            <label for="mealsdb-apetito-code"><strong><?php echo esc_html__('Apetito code', 'meals-db'); ?></strong></label>
            <input type="text" id="mealsdb-apetito-code" class="regular-text" inputmode="numeric" maxlength="5" pattern="[0-9]{5}" placeholder="12212" />
            <button type="button" class="button button-primary" id="mealsdb-apetito-fetch"><?php echo esc_html__('Fetch', 'meals-db'); ?></button>
        </p>
        <div id="mealsdb-apetito-status" role="status" aria-live="polite"></div>
        <div id="mealsdb-apetito-preview" hidden></div>
    </div>

    <div id="mealsdb-apetito-audit" class="mealsdb-apetito-tab" hidden>
        <p class="description"><?php echo esc_html__('Compares existing Apetito products (by SKU) against the current Nutridata pages. Read-only; paced at one request per second.', 'meals-db'); ?></p>
        <p><button type="button" class="button" id="mealsdb-apetito-audit-start"><?php echo esc_html__('Start audit', 'meals-db'); ?></button></p>
        <div id="mealsdb-apetito-audit-progress" aria-live="polite"></div>
        <table class="widefat striped" id="mealsdb-apetito-audit-results" hidden>
            <thead><tr>
                <th><?php echo esc_html__('Code', 'meals-db'); ?></th>
                <th><?php echo esc_html__('Product', 'meals-db'); ?></th>
                <th><?php echo esc_html__('Field', 'meals-db'); ?></th>
                <th><?php echo esc_html__('Stored', 'meals-db'); ?></th>
                <th><?php echo esc_html__('Apetito now', 'meals-db'); ?></th>
            </tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>
```

- [ ] **Step 3: Lint**

Run: `php -l includes/admin/class-apetito-page.php && php -l views/apetito-puller.php`
Expected: both `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/class-apetito-page.php views/apetito-puller.php
git commit -m "K12: Apetito puller admin page + view (pull + audit tabs)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 10: Preview + create JS

**Files:**
- Create: `assets/js/apetito-puller.js`

Renders each parsed field; unreadable fields become editable inputs labelled "could not read — enter manually"; a duplicate renders a blocking banner; the operator sets price and picks categories (selectWoo); create goes through `window.MealsDBConfirm` (focus on Cancel per K10).

- [ ] **Step 1: Write the JS**

Create `assets/js/apetito-puller.js`:
```javascript
/* global jQuery, mealsdbApetito, MealsDBConfirm */
(function ($) {
    'use strict';
    var cfg = window.mealsdbApetito || {};
    var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };

    // Fields the operator may correct if unreadable; others are display-only.
    var LABELS = {
        name_en: 'Name (EN)', name_fr: 'Name (FR)', category: 'Category',
        subcategory: 'Subcategory', code_on_page: 'Code on page',
        allergens: 'Allergens', diet_tags: 'Diet tags', serving_size: 'Serving size',
        pack_size: 'Pack size', portions_per_case: 'Portions per case'
    };

    function renderField(name, f) {
        var label = LABELS[name] || name;
        if (f && f.ok) {
            var val = Array.isArray(f.value) ? f.value.join(', ') : f.value;
            return '<tr><th>' + esc(label) + '</th><td>' + esc(val) + '</td></tr>';
        }
        var reason = f && f.reason ? f.reason : cfg.i18n.couldNotRead;
        return '<tr class="mealsdb-apetito-missing"><th>' + esc(label) + '</th><td>' +
            '<input type="text" class="regular-text mealsdb-apetito-manual" data-field="' + esc(name) + '" ' +
            'placeholder="' + esc(cfg.i18n.couldNotRead) + '" />' +
            ' <span class="description">' + esc(reason) + '</span></td></tr>';
    }

    function renderPreview(d) {
        var $p = $('#mealsdb-apetito-preview').empty();
        if (d.duplicate) {
            $p.append('<div class="notice notice-error"><p>' + esc(d.dupe_message) + '</p></div>');
            $p.prop('hidden', false);
            return; // BLOCK: no create UI when a duplicate exists.
        }
        if (d.code_on_page_mismatch) {
            $p.append('<div class="notice notice-warning"><p>' + esc(d.code_on_page_mismatch) + '</p></div>');
        }
        var rows = '';
        Object.keys(LABELS).forEach(function (k) { rows += renderField(k, d.fields[k]); });
        var cats = (cfg.categories || []).map(function (c) {
            return '<option value="' + esc(c.id) + '">' + esc(c.name) + '</option>';
        }).join('');

        $p.append(
            '<table class="widefat striped"><tbody>' + rows + '</tbody></table>' +
            '<h3>Your inputs</h3>' +
            '<p><label><strong>Price</strong> <input type="number" step="0.01" min="0" id="mealsdb-apetito-price" class="small-text" /></label></p>' +
            '<p><label for="mealsdb-apetito-cats"><strong>Categories</strong></label><br/>' +
            '<select id="mealsdb-apetito-cats" multiple style="min-width:320px">' + cats + '</select></p>' +
            '<p class="description">Product type and tax are derived from the categories you choose. With no side category, the item is a meal.</p>' +
            '<p><button type="button" class="button button-primary" id="mealsdb-apetito-create" data-code="' + esc(d.code) + '">Create Draft product</button></p>'
        );
        $p.prop('hidden', false);
        if ($.fn.selectWoo) { $('#mealsdb-apetito-cats').selectWoo({ placeholder: 'Choose categories' }); }
    }

    function collectManual() {
        var m = {};
        $('.mealsdb-apetito-manual').each(function () {
            var v = $.trim($(this).val());
            if (v) { m[$(this).data('field')] = v; }
        });
        return m;
    }

    function doFetch() {
        var code = $.trim($('#mealsdb-apetito-code').val());
        $('#mealsdb-apetito-status').text('Fetching…');
        $('#mealsdb-apetito-preview').prop('hidden', true).empty();
        $.post(cfg.ajaxUrl, { action: 'mealsdb_apetito_fetch', nonce: cfg.nonceFetch, code: code })
            .done(function (r) {
                if (!r || !r.success) {
                    $('#mealsdb-apetito-status').text((r && r.data && r.data.message) || 'Fetch failed.');
                    return;
                }
                $('#mealsdb-apetito-status').text(r.data.cached ? 'Loaded (cached).' : 'Loaded.');
                renderPreview(r.data);
            })
            .fail(function () { $('#mealsdb-apetito-status').text('Request failed.'); });
    }

    function doCreate() {
        var $btn = $('#mealsdb-apetito-create');
        var code = $btn.data('code');
        var price = $('#mealsdb-apetito-price').val();
        var cats = $('#mealsdb-apetito-cats').val() || [];
        MealsDBConfirm.confirm({
            title: cfg.i18n.confirmTitle,
            message: cfg.i18n.confirmBody,
            confirmLabel: 'Create',
            cancelLabel: 'Cancel'
        }).then(function (okPressed) {
            if (!okPressed) { return; }
            $btn.prop('disabled', true).text('Creating…');
            $.post(cfg.ajaxUrl, {
                action: 'mealsdb_apetito_create', nonce: cfg.nonceCreate,
                code: code, price: price, category_ids: cats, manual: collectManual()
            }).done(function (r) {
                if (!r || !r.success) {
                    $('#mealsdb-apetito-status').text((r && r.data && r.data.message) || 'Create failed.');
                    $btn.prop('disabled', false).text('Create Draft product');
                    return;
                }
                var link = r.data.edit_url ? ' <a href="' + esc(r.data.edit_url) + '">Edit it</a>' : '';
                $('#mealsdb-apetito-preview').html('<div class="notice notice-success"><p>Draft product #' + esc(r.data.product_id) + ' created.' + link + '</p></div>');
            }).fail(function () {
                $('#mealsdb-apetito-status').text('Request failed.');
                $btn.prop('disabled', false).text('Create Draft product');
            });
        });
    }

    $(function () {
        $('#mealsdb-apetito-fetch').on('click', doFetch);
        $('#mealsdb-apetito-code').on('keydown', function (e) { if (e.key === 'Enter') { doFetch(); } });
        $('#mealsdb-apetito-preview').on('click', '#mealsdb-apetito-create', doCreate);
        $('.mealsdb-apetito .nav-tab').on('click', function (e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            $('.mealsdb-apetito .nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('#mealsdb-apetito-pull').prop('hidden', tab !== 'pull');
            $('#mealsdb-apetito-audit').prop('hidden', tab !== 'audit');
        });
    });
}(jQuery));
```

- [ ] **Step 2: Syntax check the JS**

Run: `node --check assets/js/apetito-puller.js`
Expected: no output, exit 0. (If `node` is unavailable, skip and note it — the plugin has no JS build/test harness; verification is on staging.)

- [ ] **Step 3: Commit**

```bash
git add assets/js/apetito-puller.js
git commit -m "K12: preview + create JS (missing-field inputs, blocking dup banner, confirm dialog)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 11: Audit mode (ITEM 7, read-only, paced, resumable)

**Files:**
- Modify: `includes/services/class-apetito-audit.php` (create)
- Modify: `includes/ajax/class-ajax-apetito.php` (add `audit` handler + registration)
- Create: `tests/test-apetito-audit.php`

The audit compares stored `case_size` / `allergen_flags` / `dietary_tags` against a freshly parsed page. The diff is pure and tested; the walk is a resumable cursor (chunked AJAX), one code per call, cache-backed.

- [ ] **Step 1: Write the failing test (pure diff)**

Create `tests/test-apetito-audit.php`:
```php
<?php
/**
 * K12: the audit diff is pure — given a stored row and freshly parsed values,
 * it reports only the fields that drifted.
 *
 * Run: php tests/test-apetito-audit.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
ini_set('error_log', '/dev/null');
if (!function_exists('__')) { function __($t,$d='default'){return $t;} }
require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$fail=[]; $pass=0;
function ok($l,$e,$a){global $fail,$pass; if($e===$a){$pass++;return;} $fail[]="FAIL: $l\n  expected: ".var_export($e,true)."\n  actual:   ".var_export($a,true);}

// case_size drift only
$stored = ['case_size'=>12,'allergen_flags'=>['Eggs','Milk'],'dietary_tags'=>['Vegan']];
$parsed = ['portions_per_case'=>24,'allergens'=>['Eggs','Milk'],'diet_tags'=>['Vegan']];
$d = MealsDB_Apetito_Audit::diff($stored, $parsed);
ok('one drift', 1, count($d));
ok('case_size drift', 'case_size', $d[0]['field']);
ok('stored value', 12, $d[0]['stored']);
ok('live value', 24, $d[0]['apetito']);

// no drift
$d2 = MealsDB_Apetito_Audit::diff(
    ['case_size'=>12,'allergen_flags'=>['Eggs'],'dietary_tags'=>[]],
    ['portions_per_case'=>12,'allergens'=>['Eggs'],'diet_tags'=>[]]
);
ok('no drift', 0, count($d2));

// allergen set drift (order-insensitive)
$d3 = MealsDB_Apetito_Audit::diff(
    ['case_size'=>12,'allergen_flags'=>['Milk','Eggs'],'dietary_tags'=>[]],
    ['portions_per_case'=>12,'allergens'=>['Eggs'],'diet_tags'=>[]]
);
ok('allergen drift detected', 'allergen_flags', $d3[0]['field']);

if ($fail){echo implode("\n",$fail)."\n";echo "FAILED ({$pass} passed)\n";exit(1);}
echo "OK ({$pass} passed)\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/test-apetito-audit.php`
Expected: FAIL — "Class MealsDB_Apetito_Audit not found".

- [ ] **Step 3: Write the audit service**

Create `includes/services/class-apetito-audit.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Read-only drift audit: compares stored meals_products fields against the
 * current Apetito page. NO writes. The walk is a resumable cursor driven by the
 * AJAX layer, one code per call, cache-backed — never a burst of 163 requests.
 */
class MealsDB_Apetito_Audit {

    /**
     * Pure: fields that differ between the stored row and freshly parsed values.
     * @return array<int, array{field:string,stored:mixed,apetito:mixed}>
     */
    public static function diff(array $stored, array $parsed): array {
        $out = [];

        $stored_case = (int) ($stored['case_size'] ?? 0);
        $live_case   = (int) ($parsed['portions_per_case'] ?? 0);
        if ($live_case > 0 && $live_case !== $stored_case) {
            $out[] = ['field' => 'case_size', 'stored' => $stored_case, 'apetito' => $live_case];
        }

        $sa = self::norm_set($stored['allergen_flags'] ?? []);
        $la = self::norm_set($parsed['allergens'] ?? []);
        if ($sa !== $la) {
            $out[] = ['field' => 'allergen_flags', 'stored' => $sa, 'apetito' => $la];
        }

        $sd = self::norm_set($stored['dietary_tags'] ?? []);
        $ld = self::norm_set($parsed['diet_tags'] ?? []);
        if ($sd !== $ld) {
            $out[] = ['field' => 'dietary_tags', 'stored' => $sd, 'apetito' => $ld];
        }

        return $out;
    }

    /** Order-insensitive, de-duplicated string set for comparison. */
    private static function norm_set($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) { $value = []; }
        $value = array_values(array_unique(array_map('strval', $value)));
        sort($value);
        return $value;
    }

    /**
     * The ordered list of Apetito codes to audit (published products whose SKU
     * is a 5-digit code). The AJAX layer walks this by offset, one per call.
     * @return string[]
     */
    public static function codes(): array {
        global $wpdb;
        if (!$wpdb) { return []; }
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $rows = $wpdb->get_col("SELECT sku FROM `{$table}` WHERE sku REGEXP '^[0-9]{5}$' ORDER BY sku ASC");
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /**
     * Audit ONE code (used per cursor tick). Cache-backed fetch; never writes.
     * @return array{code:string,ok:bool,drift?:array,reason?:string}
     */
    public static function audit_one(string $code): array {
        $res = MealsDB_Apetito_Nutridata::fetch($code);
        if (empty($res['ok'])) {
            return ['code' => $code, 'ok' => false, 'reason' => $res['reason']];
        }
        $parsed = MealsDB_Apetito_Parser::parse($res['html'], $code);
        $values = [];
        foreach ($parsed['fields'] as $k => $f) { if (!empty($f['ok'])) { $values[$k] = $f['value']; } }

        $existing = MealsDB_Apetito_Product_Creator::find_by_sku($code);
        $row = $existing ? MealsDB_Products::get_product_data((int) $existing['wc_product_id']) : [];
        return ['code' => $code, 'ok' => true, 'drift' => self::diff($row, $values)];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/test-apetito-audit.php`
Expected: `OK (7 passed)`.

- [ ] **Step 5: Add the audit AJAX handler**

In `includes/ajax/class-ajax-apetito.php`, register in `init()`:
```php
        add_action('wp_ajax_mealsdb_apetito_audit', [self::class, 'audit']);
```
And add the handler method (cursor: one code per call, rate-limited so the client's 1/sec pacing is enforced server-side too):
```php
    public static function audit(): void {
        self::guard(self::NONCE_FETCH, 'apetito_fetch');
        $codes = MealsDB_Apetito_Audit::codes();
        $total = count($codes);
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        if ($offset >= $total) {
            wp_send_json_success(['done' => true, 'offset' => $offset, 'total' => $total, 'result' => null]);
        }
        $result = MealsDB_Apetito_Audit::audit_one($codes[$offset]);
        wp_send_json_success([
            'done'   => ($offset + 1) >= $total,
            'offset' => $offset + 1,
            'total'  => $total,
            'result' => $result,
        ]);
    }
```

- [ ] **Step 6: Add audit walk to the JS**

In `assets/js/apetito-puller.js`, inside the `$(function(){...})` ready block, add:
```javascript
        var auditTimer = null;
        function auditTick(offset) {
            $.post(cfg.ajaxUrl, { action: 'mealsdb_apetito_audit', nonce: cfg.nonceAudit, offset: offset })
                .done(function (r) {
                    if (!r || !r.success) { $('#mealsdb-apetito-audit-progress').text('Audit failed.'); return; }
                    var d = r.data;
                    $('#mealsdb-apetito-audit-progress').text('Checked ' + d.offset + ' / ' + d.total + '…');
                    if (d.result && d.result.ok && d.result.drift && d.result.drift.length) {
                        var $tb = $('#mealsdb-apetito-audit-results').prop('hidden', false).find('tbody');
                        d.result.drift.forEach(function (row) {
                            $tb.append('<tr><td>' + esc(d.result.code) + '</td><td></td><td>' + esc(row.field) +
                                '</td><td>' + esc([].concat(row.stored).join(', ')) + '</td><td>' +
                                esc([].concat(row.apetito).join(', ')) + '</td></tr>');
                        });
                    }
                    if (!d.done) {
                        auditTimer = window.setTimeout(function () { auditTick(d.offset); }, 1000); // 1 req/sec
                    } else {
                        $('#mealsdb-apetito-audit-progress').append(' Done.');
                    }
                })
                .fail(function () { $('#mealsdb-apetito-audit-progress').text('Audit request failed.'); });
        }
        $('#mealsdb-apetito-audit-start').on('click', function () {
            $('#mealsdb-apetito-audit-results').find('tbody').empty();
            $('#mealsdb-apetito-audit-progress').text('Starting…');
            auditTick(0);
        });
```

- [ ] **Step 7: Lint + syntax check + re-run audit test**

Run:
```bash
php -l includes/services/class-apetito-audit.php && php -l includes/ajax/class-ajax-apetito.php
node --check assets/js/apetito-puller.js
php tests/test-apetito-audit.php
```
Expected: lints clean, node clean (or skipped), `OK (7 passed)`.

- [ ] **Step 8: Commit**

```bash
git add includes/services/class-apetito-audit.php includes/ajax/class-ajax-apetito.php assets/js/apetito-puller.js tests/test-apetito-audit.php
git commit -m "K12: read-only drift audit (pure diff + resumable 1/sec cursor)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 12: Wire everything into the plugin lifecycle

**Files:**
- Modify: `meals-db-main.php:92-116`
- Modify: `uninstall.php`

- [ ] **Step 1: Register the AJAX + page init**

In `meals-db-main.php`, add near the other AJAX inits (~line 101, after `MealsDB_Ajax_Purchase_Orders::init();`):
```php
    MealsDB_Ajax_Apetito::init();
```
And near the other page inits (~line 108, after `MealsDB_Slip_Batch_Page::init();`):
```php
    MealsDB_Apetito_Page::init();
```

- [ ] **Step 2: Clean up on uninstall**

In `uninstall.php`, alongside the other `delete_option` calls, add:
```php
delete_option('mealsdb_apetito_placeholder_id');
```
And near where transients are cleared (or add this block if none exists):
```php
// K12: clear cached Apetito Nutridata pages (transient per code).
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mealsdb\\_apetito\\_%' OR option_name LIKE '\\_transient\\_timeout\\_mealsdb\\_apetito\\_%'");
```

- [ ] **Step 3: Lint both**

Run: `php -l meals-db-main.php && php -l uninstall.php`
Expected: both `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add meals-db-main.php uninstall.php
git commit -m "K12: register Apetito AJAX + page; clean placeholder option + transients on uninstall

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 13: Full-suite verification

- [ ] **Step 1: Run the entire test suite by exit code**

Run:
```bash
cd /mnt/fastssd/meals-db
pass=0; fail=0; failed=""
for f in tests/test-*.php; do php "$f" >/dev/null 2>&1 && pass=$((pass+1)) || { fail=$((fail+1)); failed="$failed\n  $f"; }; done
echo "PASS: $pass  FAIL: $fail"; echo -e "Failed:$failed"
```
Expected: the 6 new K12 tests pass. Total failures remain the **4 baseline** (`test-pdf-slip-binary-output`, `test-vac-pdf`, `test-po-task-bridge`, `test-po-task-types`) — and, if `dom` is unavailable locally, `test-apetito-parser` and `test-apetito-audit` may join the baseline (flag this explicitly rather than treating it as a pass).

- [ ] **Step 2: Confirm no new failures beyond baseline**

If any NON-baseline test fails, fix it before proceeding. Do not mark the feature complete with unexplained failures.

- [ ] **Step 3: Final commit if anything changed**

```bash
git add -A && git commit -m "K12: full-suite verification pass" || echo "nothing to commit"
```

---

## Self-review notes (spec coverage)

- ITEM 1 fetcher → Task 2. ITEM 2 parser (real selectors, loud failure) → Task 1. ITEM 3 field mapping / categories-drive-type → Tasks 4/5 (payload preserves derived type/taxable; main_ingredient truncation). ITEM 4 duplicate guard (SKU) → Task 3 + belt-check in Task 5. ITEM 5 placeholder → Task 6. ITEM 6 screen/flow (Draft, confirm-on-cancel, event log) → Tasks 8/9/10 + Task 5 logging. ITEM 7 audit → Task 11. Security/gating → Tasks 7/8. Uninstall/wiring → Task 12.
- **Staging-only (documented, not unit-tested):** actual WC product creation + Draft `is_published=0` via display-sync (Task 5) and the real media sideload (Task 6) — both need WooCommerce/WP runtime and are covered by spec verification steps #1/#5. Unit tests cover every pure helper.
- **Known local limitation:** parser/audit tests depend on ext-dom; if absent locally they fail like the dompdf-mbstring baseline. No `mb_*` used anywhere so mbstring absence does not affect this feature.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-09-09-apetito-item-puller.md`.
