# DIRECTIVE K12 — Apetito Item Puller

> **STATUS: DONE — merged 2026-09-10 (PR #546, v1.0.578).** All items (1–7) implemented via
> brainstorm → spec → plan → subagent-driven TDD. Spec:
> `docs/superpowers/specs/2026-09-09-apetito-item-puller-design.md`; plan:
> `docs/superpowers/plans/2026-09-09-apetito-item-puller.md`. 6 new network-free test files
> (54 assertions) against committed real fixtures; suite 164 pass / 4 baseline fail.
>
> **Two directive corrections baked in during design (both strengthen the feature):**
> - ITEM 4 dup guard keys on **`sku`** (= the Apetito code; 160/163 verified) + WooCommerce's
>   native SKU-uniqueness, **not** a `post_title` regex — the `#{code}` title convention is real
>   operator data but nothing in code parses it.
> - ITEM 3 `product_type`/`taxable` are **never written directly** — they are purely
>   category-derived by `class-product-display-sync`; the creator sets categories and
>   read-merges the parsed fields so the derived values are preserved.
> - ITEM 2 note: an unknown code returns **HTTP 500 + ASP.NET stack trace** (not 404), so the
>   fetcher treats any non-200 as a structured failure, never parses the body, and never echoes
>   Apetito's error to the operator. Diet tags are a stable class hook (`.dietrycodings`), not
>   heading-anchored.
>
> **Still pending (needs live WooCommerce — cannot be unit-tested):** the "Operator verification
> (staging)" checklist below. Best done after K10 (#544) is confirmed on staging, since K12
> depends on it.

**New feature.** Create a product from an Apetito item code by fetching the public Nutridata
page, pre-filling what can be derived, and letting the operator confirm the rest.

**Severity:** MED — quality-of-life, but the duplicate-code guard (ITEM 4) prevents a real
billing/packing failure
**Depends on:** v1.0.576 (K10). Independent of K11.
**Requested by:** Zak, 2026-09-09, after the Apetito Sept/Oct menu change

---

## Why

Adding a product means typing a name, case size, allergens and diet tags by hand from an email.
Apetito publishes all of it at `https://my.apetito.ca/nutridata/details/{code}` — public, no
login. Six new items arrive this menu cycle and this recurs every cycle.

The 2026-09-09 email also shows the failure this prevents: Janet listed **12111 "Chicken and
harvest vegetable stew"**, but Apetito's own catalogue lists 12111 as **Spaghetti Bolognese**,
which already exists as product 2725. Creating it would have produced two products sharing the
code that the packer, the PO and the slip all key from.

---

## Scope

**In:** an admin screen that takes a code, fetches, parses, previews, and on confirmation
creates a WooCommerce product plus its `meals_products` row.

**Out:** bulk import, scheduled sync, automatic updates to existing products, price, tax class.
See ITEM 7 for the audit-only extension.

---

## ITEM 1 — Fetcher

New service `includes/services/class-apetito-nutridata.php`.

- `fetch(string $code): array` — GET `https://my.apetito.ca/nutridata/details/{code}` via
  `wp_remote_get()` with a 10s timeout and a descriptive User-Agent identifying Meals & More.
- **Cache** each successful fetch in a transient (`mealsdb_apetito_{code}`) for 7 days.
  Product data changes per menu cycle, not per hour.
- **One request per operator action.** No bulk crawling, no loops over code ranges. This is
  Apetito's public website, not an API they have offered us; behave like a person reading it.
- Codes are `^[0-9]{5}$`. Validate before building the URL — never interpolate raw input.
- Non-200, timeout, or empty body → a structured failure, never an exception that reaches the
  page.

---

## ITEM 2 — Parser, and how it must fail

Parse from the rendered page:

| Field | Source on the page |
|---|---|
| `name_en`, `name_fr` | `<h1>` — English then French, run together |
| `category`, `subcategory` | the "Individual Complete Meals \| Poultry Code: 12212" line |
| `allergens[]` | list under `### Allergens` |
| `diet_tags[]` | list under `### Diet Coding` |
| `serving_size` | under `### Serving Size` (e.g. `330g`) |
| `pack_size` | under `### Pack Size` (e.g. `12 x 330g`) |
| `portions_per_case` | under `### Portions Per Case` (e.g. `12`) |

**This is HTML scraped from headings. It will break when Apetito redesigns.**

Treat every field as required-or-report. If a heading is missing or a value fails to parse,
the field comes back explicitly absent with a reason, and the preview screen shows it as
**"could not read — enter manually"**. The operator then fills it in on the same form.

**Never write a blank or a guessed value into the product record.** Silent blanks are the
failure mode of K10 ITEM 1 (`sanitize_email` returning `''`) and K11 ITEM 2 (`??` not catching
empty strings) — a parse miss must be loud on screen, not quietly persisted.

The `<h1>` runs the two names together with no separator ("Chicken with Creamy Mushroom Sauce
Poulet à la sauce crémeuse aux champignons"). Split on the French heading if the page markup
provides one; if it does not, present the whole string and let the operator trim. Do not guess
a split point by language detection.

---

## ITEM 3 — Field mapping

Auto-filled, operator-editable:

- `post_title` → `{name_en} #{code}` — matches the existing convention exactly
  (`Raisin Bran Muffin #8009`), which the packer, the PO and the slips all rely on
- `case_size` → `portions_per_case`
- `allergen_flags` → JSON array of the allergen list
- `dietary_tags` → JSON array of the diet coding list
- `main_ingredient` → the Apetito subcategory (Poultry / Beef / Vegetarian). `VARCHAR(40)`,
  so truncate-with-warning rather than silently

**Operator must choose, never inferred:**

- **`price`** — not published
- **`product_type`** (`meal` / `side`) — drives allowance counting. Default from the Apetito
  category as a *suggestion* only: "Individual Complete Meals" → `meal`, "Soups"/"Desserts" →
  `side`. Show the suggestion, require confirmation.
- **`taxable`** — drives HST on the invoice
- **WooCommerce product categories** — your taxonomy, not Apetito's, and
  `class-product-display-sync.php` derives `product_type`/`taxable` from these on save

Do not let Apetito's taxonomy silently determine anything that reaches an invoice.

---

## ITEM 4 — Duplicate guard (the point of the feature)

Before the preview renders, check the code against existing products by the trailing
`#{code}` in `post_title` and against `meals_products`.

If a match exists, **block creation** and show:

> Apetito lists 12111 as **Spaghetti Bolognese**. You already have **Spaghetti Bolognese
> #12111** (product 2725, published). Codes must be unique — the packing slip, the purchase
> order and the delivery slip all identify items by this number.

Also warn (do not block) when the code is new but Apetito's name closely matches an existing
product title — that is the "same dish, new code" case, which is legitimate but worth a look.

---

## ITEM 5 — Placeholder image

- On first use, create one media-library attachment, "Photo coming soon", and store its ID in
  an option. Reuse it forever; never duplicate it per product.
- Set as `_thumbnail_id` on creation.
- Record `_mealsdb_placeholder_image = 1` on the product so a "products awaiting photos" list
  is a meta query rather than an image comparison.
- When a real photo is uploaded later, clear that meta.

---

## ITEM 6 — Screen and flow

Under **Meals Database**, or as a button on the WooCommerce product list.

1. Operator enters a code → **Fetch**
2. Preview: everything parsed, everything missing flagged, duplicate check result
3. Operator sets price, `product_type`, `taxable`, categories
4. **Create product** — a confirm dialog via `window.MealsDBConfirm`, consistent with every
   other creation action, with **focus on Cancel** per K10 ITEM 4
5. Product is created as **Draft**, never published. The operator reviews and publishes.
6. Log an event (`apetito_pull.created`) with code, product ID and which fields were manual

Creating as Draft matters: `is_published = 0` keeps it off Quick Order and out of the PO
forecast until someone has deliberately published it.

---

## ITEM 7 — Audit mode (build only if ITEM 1–6 land cleanly)

A read-only report comparing existing products against current Apetito data — case size,
allergens, diet tags — and listing drift. No writes. Useful because `case_size` feeds the PO
pallet optimiser and a stale value silently mis-orders freight.

Rate-limit it: one code per second, resumable, never a burst of 163 requests.

---

## Out of scope

- Any automatic or scheduled sync. Every write is an operator action.
- Updating existing products. ITEM 7 reports; it does not fix.
- Price, tax class, or WooCommerce category inference.
- Anything behind Apetito's `Login` link. Public pages only.

---

## Tests

1. Parser against a saved fixture of 12212 → name, `case_size` 12, 4 allergens, 6 diet tags.
2. Parser against a fixture with `### Allergens` removed → field reported absent, **no write**.
3. Parser against a fixture with a changed heading → reported absent, no exception.
4. Duplicate guard: 12111 with product 2725 present → blocked, message names 2725.
5. Duplicate guard: 12212 with no match → allowed.
6. `post_title` format is `{name} #{code}`.
7. Non-200 → structured failure, form still usable for manual entry.
8. Code validation rejects `abc`, `1211`, `../etc/passwd`, `12111'`.
9. Created product is `draft` and `is_published = 0`.
10. Placeholder attachment is created once and reused on a second pull.

Baseline is 158 pass / 4 baseline fails (2 dompdf-mbstring, 2 po-task).

Fixtures must be saved copies of real pages, committed to the repo. **No test may hit the
network** — the suite has to pass when Apetito is down or has redesigned.

---

## Acceptance criteria

- No network call in the test suite.
- No parse failure ever results in a written blank or guessed value.
- `price`, `product_type`, `taxable` and categories are never set without operator input.
- Duplicate code blocks creation; test 4 proves it with the real 12111 case.
- Products are created as Draft.
- One placeholder attachment, reused.
- Fetches are cached and rate-limited; no bulk crawl exists anywhere in the feature.

---

## Operator verification (staging)

1. Pull **12212** → "Chicken with Creamy Mushroom Sauce", case 12, allergens Eggs/Milk/Soy/
   Sulphites. Set price and type, create. Product is Draft with the placeholder image.
2. Pull **12111** → blocked, message names Spaghetti Bolognese #12111 (2725).
3. Pull **99999** (nonexistent) → clean "not found", form still usable.
4. Pull 12212 again → served from cache, no second request.
5. Confirm the created product does **not** appear in Quick Order or a draft PO while Draft;
   publish it, and confirm it then appears in both.
