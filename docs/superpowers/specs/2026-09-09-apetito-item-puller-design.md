# Apetito Item Puller (Directive K12) — Design

**Status:** Approved 2026-09-09
**Directive:** `directives/DIRECTIVE-K12-apetito-item-puller.md`
**Depends on:** v1.0.576 (K10). Independent of K11.
**Requested by:** Zak, after the Apetito Sept/Oct menu change.

Create a WooCommerce product (plus its `meals_products` row) from a 5-digit Apetito
item code by fetching the public Nutridata page, pre-filling what can be parsed, and
letting the operator confirm the rest. The feature's real payload is the **duplicate-code
guard**, which prevents two products sharing the code that the packer, the purchase order,
and the delivery slip all key from.

---

## Verified premises (checked against the code, 2026-09-09)

These are confirmed facts the design relies on. If any turns out false during the build,
stop and re-open the design.

1. **`meals_products` schema** (`includes/class-schema.php`) has exactly the columns used
   here: `sku VARCHAR(100)`, `case_size INT`, `allergen_flags JSON`, `dietary_tags JSON`,
   `main_ingredient VARCHAR(40) NOT NULL DEFAULT ''`, `is_published TINYINT(1) DEFAULT 1`,
   `product_type ENUM('meal','side','fee','other')`, `taxable TINYINT(1)`, `price DECIMAL(10,2)`.

2. **SKU is the Apetito code.** Operator audit: 160 of 163 products have `sku` exactly equal
   to the trailing `#code` in the title; the 3 exceptions are internal Z-items (Z Overage
   Side, Z Overage Side Tax, a legacy Z-item), none Apetito. The `{name} #{code}` title
   convention is real, unbroken data — but it is an operator practice, **not** parsed by any
   code today. The duplicate guard therefore keys on **`sku`**, not on a title regex.

3. **`is_published` tracks post status.** `class-product-display-sync.php:437`:
   `$is_published = $product->get_status() === 'publish' ? 1 : 0`, run on `save_post_product`.
   A Draft product lands with `is_published = 0` automatically — keeping it out of Quick Order
   and the PO forecast until deliberately published.

4. **`product_type` and `taxable` are PURELY category-derived.** `sync_single_product`
   (on `save_post_product`) derives both from WooCommerce categories; the per-product override
   and its `taxable_overridden` flag were removed (a prior directive) specifically to eliminate
   a same-request clobber bug. **Writing either column directly reintroduces that bug.** K12
   sets categories only; the sync derives the rest. The sync only *acts* when a side category
   matches — a product with no matching category keeps the `meal` default, so "meal" happens by
   absence, not assertion. The preview must state this plainly.

5. **`window.MealsDBConfirm`** (`assets/js/meals-confirm.js`) exists with focus-on-Cancel for
   confirm dialogs (K10 ITEM 4).

6. **`MealsDB_Event_Log::record()`** is the operator-action log; `apetito_pull.created` fits
   the existing `{action}.{qualifier}` convention.

7. **The real Nutridata page is server-rendered ASP.NET MVC** (HTTP/2 200, ~16KB, jQuery +
   Bootstrap, Cloudflare front, **no SPA framework**). All values are present in the raw
   `wp_remote_get` body. The `###`-heading description in the directive was a fetch-tool
   markdown conversion, not the real markup. Real selectors are in §Parser below. Confirmed
   against the committed fixtures `tests/fixtures/apetito/12212.html` and `12217.html`.

8. **An unknown code returns HTTP 500, not 404.** Verified against
   `tests/fixtures/apetito/99999-notfound.html`: Apetito throws
   `System.NullReferenceException` with a full ASP.NET stack trace (and an internal server
   path) rather than a friendly not-found page. Consequences: the Fetcher must treat **any
   non-200 as a structured failure and never parse the body**, and must emit **our own** clean
   "no product found for {code}" message — never echo Apetito's stack trace to the operator.
   The "clean not found" operator flow (verification #3) is just correct non-200 handling.

---

## Scope

**In:** ITEMs 1–7. A dedicated admin submenu page under **Meals Database** that fetches,
parses, previews, duplicate-checks, and on confirmation creates a Draft product; plus the
read-only audit/drift report (ITEM 7) as a second section on the same page.

**Out (from the directive):** bulk import, scheduled sync, automatic updates to existing
products, price inference, tax-class inference, WooCommerce-category inference, anything behind
Apetito's `Login`. Every write is an operator action.

**Resolved design decisions:**
- UI lives on a dedicated **Meals Database submenu page** (not a WC product-list button).
- **ITEM 7 is in this spec** (same-page second section).
- Placeholder image is a **static bundled plugin asset**, sideloaded once.
- Categories are chosen by a **manual WC picker** — no Apetito→WC mapping.
- State survives Fetch→Create via **Approach A**: the create handler re-reads and re-parses the
  same transient server-side; the browser submits only operator-owned fields (code, price,
  category IDs). Machine-parsed fields are never trusted from the client.

---

## Components

Seven units, split so the fragile, high-value parser is a pure function with no I/O.

| Unit | File | Responsibility | I/O |
|---|---|---|---|
| **Fetcher** | `includes/services/class-apetito-nutridata.php` | code validation, `wp_remote_get`, 7-day transient cache, structured failure | HTTP + cache |
| **Parser** | `includes/services/class-apetito-parser.php` | HTML string → structured per-field result; **pure** | none |
| **Creator** | `includes/services/class-apetito-product-creator.php` | duplicate guard, create Draft product, set title/SKU/thumbnail/categories/price | DB/WC write |
| **Placeholder** | `includes/services/class-apetito-placeholder.php` | sideload bundled image once, reuse via option | media write |
| **Audit** | `includes/services/class-apetito-audit.php` | iterate existing Apetito products, diff live data, report drift | read-only |
| **AJAX** | `includes/ajax/class-ajax-apetito.php` | nonce / cap / rate-limit glue for fetch / create / audit | — |
| **View + JS** | `views/apetito-puller.php`, `assets/js/apetito-puller.js` | submenu page + preview UX | — |
| **Asset** | `assets/images/photo-coming-soon.png` | bundled placeholder image | — |

---

## Parser contract (the TDD core)

Every field is **required-or-report** — never a blank, never a guess. A parse miss is loud on
screen (the K10-ITEM-1 / K11-ITEM-2 silent-blank failure mode is forbidden).

```php
[
  'code' => '12212',
  'fields' => [
    'name_en'           => ['ok'=>true,  'value'=>'Chicken with Creamy Mushroom Sauce'],
    'name_fr'           => ['ok'=>true,  'value'=>'Poulet à la sauce crémeuse aux champignons'],
    'category'          => ['ok'=>true,  'value'=>'Individual Complete Meals'],
    'subcategory'       => ['ok'=>true,  'value'=>'Poultry'],
    'code_on_page'      => ['ok'=>true,  'value'=>'12212'],
    'allergens'         => ['ok'=>true,  'value'=>['Eggs','Milk','Soy','Sulphites']],
    'diet_tags'         => ['ok'=>true,  'value'=>[/* … */]],
    'serving_size'      => ['ok'=>true,  'value'=>'330g'],
    'pack_size'         => ['ok'=>true,  'value'=>'12 x 330g'],
    'portions_per_case' => ['ok'=>false, 'reason'=>'heading "Portions Per Case" not found'],
  ],
]
```

### Real selectors

| Field | Markup |
|---|---|
| `name_en` | `<span class="producttitle language_en">` (may carry `style="display:none;"` because the page defaults to French — hidden ≠ absent, parse it normally) |
| `name_fr` | `<span class="producttitle language_fr">` |
| `category` + `subcategory` | `<span class="product-cat">Individual Complete Meals \| Poultry</span>` (split on `\|`) |
| `code_on_page` | `<span class="product-code">Code: 12212</span>` (strip the `Code: ` prefix) |
| `allergens[]` | `<div class="alergenslist">` **(one L — match exactly)** → `<ul class="allergens"><li title="Contains Eggs">Eggs</li>…` |
| `diet_tags[]` | `<ul class="dietrycodings">` **(misspelled `dietry` — match exactly)** → `<li>Vegan</li>…`. A class hook, **not** heading-anchored — as stable as allergens. |
| `serving_size`, `pack_size`, `portions_per_case` | **bare text nodes following** `<h3>Serving Size</h3>`, `<h3>Pack Size</h3>`, `<h3>Portions Per Case</h3>`. Verified layout: the value is the text node between the `<h3>` and the next element (e.g. `<h3>Portions Per Case</h3>` then `12` immediately before `</div>`) — trim it. |

### Parser rules
- Parse with `DOMDocument` under `libxml_use_internal_errors(true)`; DOM issues become per-field
  `ok:false`, never an exception reaching the page.
- **HTML-entity decode** every text value before storing (French names arrive as `&#xE9;` etc.);
  otherwise names land mangled.
- The two names are **cleanly separated by CSS class** — no concatenated-string split, no
  language detection. This retires the directive's ITEM 2 name-split concern.
- Class-hook fields (names, category, code, allergens, **diet tags**) are the stable path.
  Only `serving_size` / `pack_size` / **`portions_per_case`** are heading-anchored text nodes —
  the fragile three. `portions_per_case` gets the loudest failure because it feeds `case_size`
  and the pallet optimiser (a stale value silently mis-orders freight).
- **`code_on_page` cross-check** (beyond the directive): the parser reports the page's own code.
  If it differs from the operator's typed code, the preview surfaces it — a second line of
  defense on the 12111 case (Apetito's page reads "Spaghetti Bolognese / Code: 12111" no matter
  what the operator thought they were adding).
- **`main_ingredient`** maps from `subcategory` (Poultry/Beef/Vegetarian). Target is
  `VARCHAR(40)` — truncate-with-warning, never silently.

---

## Data flow (Approach A)

**Fetch** (AJAX `mealsdb_apetito_fetch`): validate `^[0-9]{5}$` → nonce + cap + rate-limit →
Fetcher (cache or GET; cache raw HTML 7 days in `mealsdb_apetito_{code}`) → Parser →
duplicate guard → return `{ fields, duplicate, type_suggestion }`.

**Preview** (JS): render every field; absent fields render as editable inputs labelled
**"could not read — enter manually"**; a duplicate match renders a **blocking** banner naming
the existing product; operator sets **price** and picks **WC categories**; a live note states
the derived consequence ("a side category makes this a taxable/non-taxable side; with none it
stays a meal by default").

**Create** (AJAX `mealsdb_apetito_create`): nonce (`mealsdb_apetito_create`) + `edit_product`
cap + rate-limit → confirm dialog already shown via `window.MealsDBConfirm` (focus on Cancel) →
**re-read the transient, re-parse server-side** (machine fields never trusted from the browser)
→ re-run duplicate guard (belt; WC SKU-uniqueness is the braces) → create product as **Draft**:
- `post_title` = `{name_en} #{code}`
- `sku` = `{code}`
- operator's categories assigned
- `regular_price` = operator price
- `_thumbnail_id` = placeholder attachment, `_mealsdb_placeholder_image = 1`

The save fires `save_post_product` → `class-product-display-sync` derives `product_type` /
`taxable` from categories and upserts `meals_products` with `is_published = 0` (Draft). Then log
`apetito_pull.created` with code, product ID, and which fields were manually entered.

> **Build-time verification:** confirm whether `display-sync` carries `price` into
> `meals_products` on a programmatic save. If not, the Creator upserts `price` explicitly. The
> Creator never writes `product_type` / `taxable` — categories own those (premise 4).

---

## Duplicate guard (ITEM 4)

`SELECT wc_product_id FROM meals_products WHERE sku = {code}` (plus the WC lookup). A match
**blocks** creation with a message naming the existing product, e.g.:

> Apetito lists 12111 as **Spaghetti Bolognese**. You already have **Spaghetti Bolognese
> #12111** (product 2725, published). Codes must be unique — the packing slip, the purchase
> order and the delivery slip all identify items by this number.

Soft-warn (no block) when the code is new but the parsed name closely matches an existing
product title (the legitimate "same dish, new code" case). WooCommerce's native SKU-uniqueness
rejects a duplicate at save even if the PHP check were bypassed — the opposite of the
`delivery_initials` situation, where the PHP check was the only wall.

---

## Placeholder image (ITEM 5)

`MealsDB_Apetito_Placeholder::get_or_create(): int` reads option
`mealsdb_apetito_placeholder_id`; if present and the attachment still exists, reuse; otherwise
sideload the bundled `assets/images/photo-coming-soon.png` into the media library once
(`wp_insert_attachment` + `wp_generate_attachment_metadata`) and store the ID. Never duplicated
per product. Set as `_thumbnail_id` on creation; record `_mealsdb_placeholder_image = 1` so an
"awaiting photo" list is a meta query. Clearing that meta happens when a real photo is uploaded
(out of scope to build the upload flow; the meta contract is defined here).

---

## Audit mode (ITEM 7, read-only)

`MealsDB_Apetito_Audit` iterates existing products whose SKU is a 5-digit code, fetches
(cache-backed) + parses each, and diffs `case_size` / `allergen_flags` / `dietary_tags` against
live Apetito data. Lists drift. **No writes.**

Paced at **one request/second**, driven by a **resumable cursor** (chunked AJAX, same shape as
the data-ops recursive re-post), so it is never a 163-request burst; a re-run inside the 7-day
cache window is served entirely from cache. Rendered as a second section on the puller page.
Useful because `case_size` feeds the PO pallet optimiser and a stale value silently mis-orders
freight.

---

## Security & gating

- **Cap:** `edit_product` (with `manage_woocommerce` fallback), matching the product-tab
  convention.
- **Nonces:** `mealsdb_apetito` for fetch + audit; `mealsdb_apetito_create` for create (a write
  gets its own nonce, per the codebase convention).
- **Rate-limit buckets:** new `apetito_fetch` (30/hour) on fetch and each audit request (the
  "don't hammer Apetito's public site" concern); create under existing `settings_modify`
  (20/hour). The audit additionally self-paces at 1 request/second.
- **Every external call:** 10s timeout, descriptive User-Agent identifying Meals & More,
  validated code before URL build (never interpolate raw input), structured failure on
  non-200/timeout/empty. **No bulk crawl anywhere** — the audit is the only multi-code path and
  it is paced, resumable, and cache-backed.

---

## Error handling

- **Fetcher / Parser never throw to the page.** Fetcher returns `{ok:false, reason}` on
  non-200 / timeout / empty and **never parses a non-200 body** (an unknown code is a 500 with
  a stack trace — premise 8). The `reason` is our own clean message ("no product found for
  {code}"); Apetito's raw error body / server path is never surfaced to the operator. Parser
  catches DOM issues into per-field `ok:false`.
- **Create** wraps `\Throwable` → `WP_Error` / `wp_send_json_error`. If the product is created
  but a later step (thumbnail/meta) fails, the result is a reviewable Draft; log the outcome as
  `degraded` rather than reporting success (per the codebase's swallow-but-don't-pretend rule).
- **No parse failure ever writes a blank or guessed value** — absent fields require operator
  entry on the same form.

---

## Testing (TDD; all network-free; fixtures committed)

Fixtures live under `tests/fixtures/apetito/`. **No test hits the network** — the suite must
pass when Apetito is down or has redesigned.

**Parser (pure — the core):**
1. Parse `12212` fixture → names (en+fr), category `Individual Complete Meals` +
   subcategory `Poultry`, `code_on_page` 12212, 4 allergens (Eggs/Milk/Soy/Sulphites) from
   `.alergenslist`, diet tags from `.dietrycodings`, serving `330g`, pack `12 x 330g`,
   `portions_per_case` 12.
2. Parse `12217` fixture (different allergen count; Vegan in `.dietrycodings`) → a second page
   shape.
3. Allergens block removed → `allergens` `ok:false` with reason, **no write**, no exception.
4. Renamed heading (`Portions Per Case`) → `portions_per_case` `ok:false` with reason; other
   fields still `ok`.
5. HTML-entity decode: French name with `&#xE9;`/`&#xE0;` → `é`/`à` (12212's French name is
   `Poulet à la sauce crémeuse aux champignons`).
6. `display:none` English span still parsed (hidden ≠ absent) — 12212's EN span carries
   `style="display:none;"`.
7. `code_on_page` mismatch (page code ≠ requested code) is surfaced.

**Duplicate guard:**
8. SKU `12111` present (product 2725) → blocked; message names 2725.
9. SKU `12212` no match → allowed.

**Fetcher:**
10. Code validation rejects `abc`, `1211`, `../etc/passwd`, `12111'` (`^[0-9]{5}$`).
11. Non-200 → structured failure; body is **not** parsed and Apetito's error text is not echoed;
    form still usable for manual entry. Use the `99999-notfound.html` fixture served as HTTP
    500 (the real unknown-code shape) with a mocked `wp_remote_get`.
12. Cache hit → no second request (mock).

**Creator:**
13. `post_title` = `{name} #{code}`; `sku` = `{code}`.
14. Created product is `draft` and `meals_products.is_published = 0` (via display-sync).
15. Creator does **not** write `product_type` / `taxable` (categories drive them).

**Placeholder:**
16. Attachment created once and reused on a second pull (option set, no duplicate attachment).

**Audit:**
17. Drift detected when `case_size` ≠ parsed portions; no row when equal.
18. Cursor resumability (process picks up from offset; no burst).

Baseline is **158 pass / 4 baseline fails** (2 dompdf-mbstring, 2 po-task).

**Fixtures (in place, 2026-09-09):** `tests/fixtures/apetito/12212.html` (16KB, full poultry
meal), `12217.html` (15KB, Vegan diet tag, different allergen count), and `99999-notfound.html`
(the real HTTP-500 unknown-code body) are committed and verified against the selectors above.
The two synthetic variants (allergens-block-removed, heading-renamed) will be derived from
`12212.html` during the build.

---

## Acceptance criteria

- No network call in the test suite.
- No parse failure ever results in a written blank or guessed value.
- `price`, and the WC categories that drive `product_type` / `taxable`, are never set without
  operator input; the Creator never writes `product_type` / `taxable` directly.
- Duplicate code blocks creation; test 8 proves it with the real 12111 case, keyed on `sku`.
- Products are created as **Draft** (`is_published = 0`).
- One placeholder attachment, reused.
- Fetches are cached (7-day transient) and rate-limited; no bulk crawl exists anywhere in the
  feature; the audit is paced (1/sec) and resumable.

---

## Operator verification (staging)

1. Pull **12212** → "Chicken with Creamy Mushroom Sauce", case 12, allergens
   Eggs/Milk/Soy/Sulphites. Set price, pick categories, create → product is **Draft** with the
   placeholder image; preview showed the derived type/taxable before creation.
2. Pull **12111** → blocked, message names Spaghetti Bolognese #12111 (product 2725).
3. Pull **99999** (nonexistent) → clean "not found"; form still usable for manual entry.
4. Pull **12212** again → served from cache, no second request.
5. Confirm the created product does **not** appear in Quick Order or a draft PO while Draft;
   publish it, and confirm it then appears in both.
6. Run the audit against a product with a knowingly stale `case_size` → drift is listed; no
   writes occur.

---

## Out of scope

- Any automatic or scheduled sync — every write is an operator action.
- Updating existing products (ITEM 7 reports drift; it does not fix).
- Price, tax class, or WooCommerce-category inference.
- Building the real-photo upload flow (only the `_mealsdb_placeholder_image` meta contract is
  defined here).
- Anything behind Apetito's `Login`. Public pages only.
