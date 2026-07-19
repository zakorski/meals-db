# Doc 2 Row Chunking Implementation Plan (Packing Slips follow-up)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Chunk a Midland Doc 2 packer slip's item rows across multiple pages — but ONLY when the flowed content would bleed into a standard 0.5in bottom printing margin — while keeping the Doc 4 driver-sheet overlay page-aligned via blank continuation pages.

**Architecture:** All pagination math is pure PHP computed at render time (dompdf cannot page-break inside the absolutely-positioned `.d2-flow` container). A pure, unit-tested `doc2_chunk_sizes()` returns row counts per page from conservative geometry constants; a slip that fits above the margin returns a single chunk and renders exactly as today. Chunked orders repeat the header lines with a "(continued)" marker; totals + notes render only on the last chunk; the calibrated divider renders only on the FIRST chunk page — because Doc 4 is printed on top of the physical Doc 2 sheets, and its download now recomputes the same per-order page counts (same live `build_slips()` data both downloads already use) to pad blank pages behind continuations, keeping driver text on the correct sheet.

**Tech Stack:** Pure PHP + dompdf-free HTML renderers, standalone test convention (`php tests/test-*.php`; dompdf itself is live-only — no mbstring locally).

**Base branch: `fix/packing-slip-layout` (PR #470) — this stacks on the flow-container fix. The PR opens with that base; GitHub retargets to main when #470 merges.**

**Reference facts (verified against the code 2026-07-19, at PR #470 tip fc48ace):**
- `includes/services/class-slip-pdf-generator.php`: `.d2-flow` at top 1.26in inside an 8.5in-tall `overflow:hidden` page; single-line rows at 11pt (post-#470 percent columns); totals `margin-top:0.12in` 10pt; notes `margin-top:0.10in` 10pt over 6.9in. `render_doc2_page($slip, $n, $m, $page_x, $page_y, $is_last)` renders ONE page per order; `render_packing_slips_combined_html()` computes `$y = 1 + count($slips)` and `$page_x = $n + 1`. The "KNOWN LIMIT" paragraph in the `.d2-flow` CSS comment (added in fc48ace) describes the no-pagination limit this plan removes — it must be updated.
- `generate_doc4_driver_blocks(array $doc4_orders)` renders one `doc4-page` per persisted block and pipes straight into `render_with_dompdf()` (not HTML-testable as-is — Task 2 splits out a `render_doc4_html()`).
- `includes/ajax/class-ajax-slip-batch.php::download_doc4()` (~line 169) has the full `$batch` row (zone_name, delivery_date, orders) — it can ask the generator for live page counts.
- Doc-2 slips are built LIVE at download time (`build_slips()`); doc-4 blocks are persisted positionally at batch creation. The 1:1 positional pairing is an existing assumption; chunk counts derive from the same live ordering.
- Slip array shape (`build_slips()`): `initials, zone, order_number, delivery_date, items[] (sku, qty, product_name, category), total_items, total_mains, total_sides, additional_notes`.
- Test conventions: `tests/test-slip-midland-render.php` — `ABSPATH` + `get_option` stub + autoloader, `chk`/`chk_true`/`chk_contains` helpers, `call_priv()` via Reflection with `newInstanceWithoutConstructor()`. `render_doc2_page` is called ONLY from `render_packing_slips_combined_html` (and the midland test only exercises the combined path with 0-item slips → signature change to `render_doc2_page` breaks nothing).
- Execution rules that stand: subagents never `git checkout <commit>` (use `git show`); nothing under `directives/` staged; local baseline: 2 PDF tests fail (mbstring/imagick).

**Geometry (the single source for the math below):** capacity = 8.5 − 1.26 − 0.5 = **6.74in**. Row (incl. header row) = **0.23in**. Tail = totals **0.31in** + (notes: **0.10in** margin + **0.18in**/line, ~110 chars/line over 6.9in at 10pt). Derived: max single-page rows (no notes) = 26; full continuation page = 28 rows; last-page rows (no notes) = 26.

---

### Task 0: Create the feature branch

**Files:** none

- [ ] **Step 1: Branch from the PR #470 tip**

```bash
cd /mnt/fastssd/meals-db && git checkout fix/packing-slip-layout && git pull --ff-only && git checkout -b feat/doc2-row-chunking && git log --oneline -2
```

Expected: tip is fc48ace ("fix(slips): percent column widths…"). Do NOT branch from main — this stacks on #470.

---

### Task 1: Chunk math (TDD)

**Files:**
- Test: `tests/test-slip-doc2-chunking.php` (create)
- Modify: `includes/services/class-slip-pdf-generator.php` (constants + 3 methods)

- [ ] **Step 1: Write the failing test**

Create `tests/test-slip-doc2-chunking.php` with exactly this content:

```php
<?php
/**
 * Tests for Doc 2 row chunking (packing-slip pagination follow-up).
 * dompdf-FREE: pins the pure chunk math and the chunked HTML the
 * renderers emit; the PDF itself is verified on the live host.
 *
 *   NL-*   doc2_notes_lines — conservative wrapped-line estimate
 *   CH-*   doc2_chunk_sizes — single page whenever content clears the
 *          0.5in print margin (chunking must never touch a fitting slip);
 *          greedy full pages + tail-reserved last page otherwise
 *   HTML-* chunked combined document: page counts, continued markers,
 *          totals/notes on last chunk only, divider on first chunk only,
 *          continuous global numbering
 *   D4-*   doc-4 blank padding keeps the physical overlay page-aligned
 *
 * Run: php tests/test-slip-doc2-chunking.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

$GLOBALS['TEST_OPTIONS'] = [];
if (!function_exists('get_option')) {
    function get_option($name, $default = false) { return $GLOBALS['TEST_OPTIONS'][$name] ?? $default; }
}

require_once __DIR__ . '/../includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__) . '/');

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; return; }
    $failures[] = sprintf("FAIL: %s\n  expected: %s\n  actual:   %s",
        $label, var_export($exp, true), var_export($got, true));
}
function chk_true($c, $l) { chk((bool) $c, true, $l); }
function chk_contains($hay, $needle, $l) { chk_true(strpos($hay, $needle) !== false, $l . " (contains '$needle')"); }

// Reach private instance methods without wiring the constructor deps.
$ref = new ReflectionClass('MealsDB_Slip_PDF_Generator');
$gen = $ref->newInstanceWithoutConstructor();
function call_priv($gen, $method, ...$args) {
    $m = new ReflectionMethod('MealsDB_Slip_PDF_Generator', $method);
    $m->setAccessible(true);
    return $m->invoke($gen, ...$args);
}

// ===========================================================================
// NL — notes line estimate.
// ===========================================================================
chk(MealsDB_Slip_PDF_Generator::doc2_notes_lines(''), 0, 'NL-1 empty => 0');
chk(MealsDB_Slip_PDF_Generator::doc2_notes_lines('   '), 0, 'NL-2 whitespace => 0');
chk(MealsDB_Slip_PDF_Generator::doc2_notes_lines('short note'), 1, 'NL-3 one short line');
chk(MealsDB_Slip_PDF_Generator::doc2_notes_lines("a\nb"), 2, 'NL-4 explicit newlines counted');
chk(MealsDB_Slip_PDF_Generator::doc2_notes_lines(str_repeat('x', 250)), 3, 'NL-5 250 chars wraps to 3 est. lines');

// ===========================================================================
// CH — chunk sizes. Geometry: capacity 6.74in, row 0.23in, totals 0.31in,
// notes 0.10in + 0.18in/line. Derived: 26 single-page rows (no notes),
// 28-row full pages, 26-row last page.
// ===========================================================================
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(0, 0), [0], 'CH-1 empty order => single page');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(12, 0), [12], 'CH-2 typical order => single page');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(26, 0), [26], 'CH-3 boundary: 26 rows still clears the margin');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(27, 0), [26, 1], 'CH-4 one row over => chunk engages');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(60, 0), [28, 28, 4], 'CH-5 three pages, full 28-row middles');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(24, 3), [24], 'CH-6 notes shrink the single-page budget: 24+3 lines fits');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(25, 3), [24, 1], 'CH-7 notes push one row over');
chk(MealsDB_Slip_PDF_Generator::doc2_chunk_sizes(5, 40), [5, 0], 'CH-8 degenerate giant notes => tail-only last page');

// ===========================================================================
// Report.
// ===========================================================================
if (!empty($failures)) {
    fwrite(STDERR, "\n" . implode("\n", $failures) . "\n\n");
    fwrite(STDERR, sprintf("%d passed, %d failed\n", $passed, count($failures)));
    exit(1);
}
fwrite(STDOUT, sprintf("OK: %d checks passed\n", $passed));
exit(0);
```

(The HTML-* and D4-* sections named in the header are ADDED IN TASK 2 — this file grows; the header lists the final coverage so it's written once.)

- [ ] **Step 2: Run it — expect failure**

`php tests/test-slip-doc2-chunking.php` → fatal `Call to undefined method MealsDB_Slip_PDF_Generator::doc2_notes_lines()`.

- [ ] **Step 3: Implement constants + math**

In `includes/services/class-slip-pdf-generator.php`, directly after the `DOC4_BLOCK_WIDTH_IN` constant block, add:

```php
    // ----------------------------------------------------------------- //
    //  Doc 2 pagination (row chunking). dompdf cannot page-break inside
    //  the absolutely-positioned .d2-flow container, so pagination is
    //  computed HERE, in PHP, from conservative geometry estimates.
    //  Chunking engages ONLY when the flowed content would cross into the
    //  bottom print margin — a slip that fits renders exactly as before.
    // ----------------------------------------------------------------- //

    /** Letter-landscape page height and the .d2-flow content top. */
    private const DOC2_PAGE_HEIGHT_IN = 8.5;
    private const DOC2_CONTENT_TOP_IN = 1.26;

    /** Standard bottom printing margin — the bleed threshold. */
    public const DOC2_PRINT_MARGIN_IN = 0.5;

    /** Single-line 11pt row (incl. 1pt padding + collapsed borders). */
    private const DOC2_ROW_IN = 0.23;

    /** Totals block: 0.12in margin + ~0.19in of 10pt line. */
    private const DOC2_TOTALS_IN = 0.31;

    /** Notes block: 0.10in margin + ~0.18in per 10pt line. */
    private const DOC2_NOTES_MARGIN_IN = 0.10;
    private const DOC2_NOTES_LINE_IN   = 0.18;

    /** ~125 chars fit 6.9in at 10pt; 110 keeps the estimate conservative. */
    private const DOC2_NOTES_CHARS_PER_LINE = 110;
```

Then, directly before `render_doc2_page()`'s doc-comment (the "DOC 2 — packer slip page renderer" banner), add:

```php
    /**
     * Conservative wrapped-line estimate for the Additional Notes block.
     * Public + pure for unit tests.
     */
    public static function doc2_notes_lines(string $notes): int {
        $notes = trim($notes);
        if ($notes === '') {
            return 0;
        }
        $lines = 0;
        foreach (explode("\n", $notes) as $segment) {
            $len = function_exists('mb_strlen') ? mb_strlen($segment) : strlen($segment);
            $lines += max(1, (int) ceil($len / self::DOC2_NOTES_CHARS_PER_LINE));
        }
        return $lines;
    }

    /**
     * Row counts per doc-2 page for one order. Returns [$item_count]
     * (single page — NO chunking) whenever header + rows + totals + notes
     * clear the bottom print margin: chunking must never alter a slip
     * that already fits. Otherwise: greedy 28-row full pages, with the
     * tail (totals + notes) reserved space on the last page. Degenerate
     * giant notes (tail alone exceeds a page) yield a final 0-row page —
     * the notes themselves are not paginated.
     *
     * Public + pure for unit tests.
     *
     * @return array<int,int>
     */
    public static function doc2_chunk_sizes(int $item_count, int $notes_lines): array {
        $capacity = self::DOC2_PAGE_HEIGHT_IN - self::DOC2_CONTENT_TOP_IN - self::DOC2_PRINT_MARGIN_IN;
        $header   = self::DOC2_ROW_IN;
        $tail     = self::DOC2_TOTALS_IN
            + ($notes_lines > 0 ? self::DOC2_NOTES_MARGIN_IN + $notes_lines * self::DOC2_NOTES_LINE_IN : 0);

        if ($header + $item_count * self::DOC2_ROW_IN + $tail <= $capacity) {
            return [$item_count];
        }

        $full = max(1, (int) floor(($capacity - $header) / self::DOC2_ROW_IN));
        $last = (int) floor(($capacity - $header - $tail) / self::DOC2_ROW_IN);

        $sizes     = [];
        $remaining = $item_count;
        // Keep at least one row for the tail page when the tail leaves room
        // for any — a bare totals/notes page is reserved for the degenerate
        // giant-notes case only.
        $reserve = $last >= 1 ? 1 : 0;
        while ($remaining > max(0, $last)) {
            $take = max(1, min($full, $remaining - $reserve));
            $sizes[]    = $take;
            $remaining -= $take;
        }
        $sizes[] = $remaining;
        return $sizes;
    }

    /**
     * Doc-2 page count per slip, positionally matching $slips. Shared by
     * the combined renderer (global page numbering) and the doc-4
     * download (blank-page padding for overlay alignment).
     *
     * @param array<int,array> $slips
     * @return array<int,int>
     */
    private static function doc2_counts_for_slips(array $slips): array {
        $counts = [];
        foreach ($slips as $slip) {
            $items_n  = is_array($slip['items'] ?? null) ? count($slip['items']) : 0;
            $notes    = self::doc2_notes_lines((string) ($slip['additional_notes'] ?? ''));
            $counts[] = count(self::doc2_chunk_sizes($items_n, $notes));
        }
        return $counts;
    }
```

- [ ] **Step 4: Run the test — expect `OK: 13 checks passed`.**

- [ ] **Step 5: Lint, regression, commit**

```bash
php -l includes/services/class-slip-pdf-generator.php
php tests/test-slip-doc2-chunking.php && php tests/test-slip-midland-render.php && php tests/test-ajax-slip-batch.php
git add tests/test-slip-doc2-chunking.php includes/services/class-slip-pdf-generator.php
git commit -m "feat(slips): doc-2 chunk math — engage only past the 0.5in print margin (TDD)"
```

Expected: `OK: 13`, `PASS — 35`, `PASS — 21`.

---

### Task 2: Chunked renderers + Doc 4 alignment + AJAX wiring

**Files:**
- Modify: `includes/services/class-slip-pdf-generator.php` (render_doc2_page split, combined renderer, doc-4 HTML split, CSS comment)
- Modify: `includes/ajax/class-ajax-slip-batch.php` (`download_doc4`)
- Test: `tests/test-slip-doc2-chunking.php` (append HTML-* and D4-* sections)

- [ ] **Step 1: Append the failing HTML tests**

In `tests/test-slip-doc2-chunking.php`, directly before the `// Report.` banner, add:

```php
// ===========================================================================
// HTML — chunked combined document. 30 single-line items, no notes
// => sizes [28, 2] => 2 doc2 pages; with the cover, y = 3.
// ===========================================================================
$mk_item = static function (int $i): array {
    return ['sku' => 'SKU' . $i, 'qty' => 1, 'product_name' => 'Product ' . $i, 'category' => 'Main'];
};
$big_slip = [
    'initials' => 'AAA', 'zone' => 'Zone 1', 'order_number' => '#900',
    'delivery_date' => 'July 19, 2026',
    'items' => array_map($mk_item, range(1, 30)),
    'total_items' => 30, 'total_mains' => 30, 'total_sides' => 0,
    'additional_notes' => 'Ring the back doorbell',
];
$chunk_batch = [
    'order_count' => 1,
    'orders'      => [['initials' => 'AAA', 'take_from_hold' => false]],
    'created_at'  => '2026-07-19 12:00:00',
];
$html = call_priv($gen, 'render_packing_slips_combined_html', 'Moncton Downtown', '2026-07-19', $chunk_batch, [$big_slip]);

chk(substr_count($html, '<div class="doc2-page'), 2, 'HTML-1 one 30-item order => two packer pages');
chk_contains($html, 'Page 1 of 3', 'HTML-2 cover counts chunk pages');
chk_contains($html, 'Page 2 of 3', 'HTML-3 first chunk page number');
chk_contains($html, 'Page 3 of 3', 'HTML-4 second chunk page number');
chk(substr_count($html, '(continued)'), 1, 'HTML-5 exactly one continued marker');
chk(substr_count($html, 'Total Items: 30'), 1, 'HTML-6 totals once, on the last chunk only');
chk(substr_count($html, 'Ring the back doorbell'), 1, 'HTML-7 notes once, on the last chunk only');
chk(substr_count($html, '<div class="d2-divider">'), 1, 'HTML-8 divider on the FIRST chunk page only (doc-4 overlay target)');
// Row split 28 + 2: the second doc2 page carries exactly 2 item rows.
$second_page = substr($html, strrpos($html, '<div class="doc2-page'));
chk(substr_count($second_page, '<td class="d2-sku">'), 2, 'HTML-9 second page has the remaining 2 rows');
chk_contains($second_page, 'Product 30', 'HTML-10 last item lands on the last page');
// Order position is repeated on every chunk page.
chk(substr_count($html, 'Order 1 of 1'), 2, 'HTML-11 position line on both chunk pages');

// A small slip must render EXACTLY one page with no markers (no chunking).
$small = ['items' => array_map($mk_item, range(1, 3)), 'total_items' => 3] + $big_slip;
$html2 = call_priv($gen, 'render_packing_slips_combined_html', 'Moncton Downtown', '2026-07-19', $chunk_batch, [$small]);
chk(substr_count($html2, '<div class="doc2-page'), 1, 'HTML-12 fitting slip => single page');
chk(substr_count($html2, '(continued)'), 0, 'HTML-13 fitting slip => no continued marker');
chk_contains($html2, 'Page 2 of 2', 'HTML-14 fitting slip numbering unchanged');

// ===========================================================================
// D4 — blank padding keeps the overlay page-aligned. Counts [2, 1]:
// order 1 spans two doc-2 pages, so doc 4 emits block, blank, block.
// ===========================================================================
$d4_orders = [
    ['client_name' => 'First Client', 'street' => '1 First St'],
    ['client_name' => 'Second Client', 'street' => '2 Second St'],
];
$d4 = call_priv($gen, 'render_doc4_html', $d4_orders, [2, 1]);
chk(substr_count($d4, '<div class="doc4-page'), 3, 'D4-1 three pages for counts [2,1]');
chk(substr_count($d4, '<div class="d4-block">'), 2, 'D4-2 exactly two driver blocks');
chk_true(strpos($d4, 'First Client') < strpos($d4, 'Second Client'), 'D4-3 block order preserved');
// The middle page is the blank spacer: split on pages, page 2 has no block.
$pages = explode('<div class="doc4-page', $d4);
chk_true(strpos($pages[2], 'd4-block') === false, 'D4-4 continuation spacer page is empty');
// Last page carries no trailing break.
chk_true(strpos($pages[3], 'd4-break') === false, 'D4-5 no trailing break after last page');
// Default counts (all 1) preserve today's one-page-per-order output.
$d4_plain = call_priv($gen, 'render_doc4_html', $d4_orders, []);
chk(substr_count($d4_plain, '<div class="doc4-page'), 2, 'D4-6 no counts => unchanged one page per order');
```

- [ ] **Step 2: Run — expect failure** (`render_doc4_html` undefined / HTML-1 count mismatch). Non-zero exit.

- [ ] **Step 3: Split render_doc2_page into order-pages + per-page primitive**

In `includes/services/class-slip-pdf-generator.php`, REPLACE the entire `render_doc2_page()` method with these two methods (the doc-comment banner above it stays):

```php
    /**
     * All doc-2 pages for ONE order. A fitting order is a single page,
     * byte-equivalent to the pre-chunking output. A chunked order repeats
     * the header lines with "(continued)" on follow-on pages; totals +
     * notes render on the LAST chunk only; the calibrated divider renders
     * on the FIRST chunk only — doc 4 is printed on top of the physical
     * doc-2 sheets, and its driver block must land on (and only on) each
     * order's first page. $first_page_x is the order's first global page
     * number; the caller advances by the chunk count.
     */
    private function render_doc2_order_pages(array $slip, int $n, int $m, int $first_page_x, int $page_y, bool $is_last_order): string {
        $items = is_array($slip['items'] ?? null) ? $slip['items'] : [];
        $sizes = self::doc2_chunk_sizes(
            count($items),
            self::doc2_notes_lines((string) ($slip['additional_notes'] ?? ''))
        );

        $total  = count($sizes);
        $out    = '';
        $offset = 0;
        foreach ($sizes as $ci => $take) {
            $chunk   = array_slice($items, $offset, $take);
            $offset += $take;
            $is_first_chunk = ($ci === 0);
            $is_last_chunk  = ($ci === $total - 1);
            $out .= $this->render_doc2_page(
                $slip,
                $chunk,
                $n,
                $m,
                $first_page_x + $ci,
                $page_y,
                $is_first_chunk,
                $is_last_chunk,
                $is_last_order && $is_last_chunk
            );
        }
        return $out;
    }

    private function render_doc2_page(
        array $slip,
        array $chunk_items,
        int $n,
        int $m,
        int $page_x,
        int $page_y,
        bool $is_first_chunk,
        bool $is_last_chunk,
        bool $is_last_page
    ): string {
        $initials      = self::esc((string) ($slip['initials'] ?? ''));
        $zone          = self::esc((string) ($slip['zone'] ?? ''));
        $order_number  = self::esc((string) ($slip['order_number'] ?? ''));
        $delivery_date = self::esc((string) ($slip['delivery_date'] ?? ''));

        $items_html = '';
        foreach ($chunk_items as $item) {
            $items_html .= '<tr>'
                . '<td class="d2-sku">' . self::esc((string) $item['sku']) . '</td>'
                . '<td class="d2-qty">' . (int) $item['qty'] . '</td>'
                . '<td class="d2-name">' . self::esc((string) $item['product_name']) . '</td>'
                . '<td class="d2-cat">' . self::esc((string) $item['category']) . '</td>'
                . '</tr>';
        }

        $position = "Order {$n} of {$m}" . ($is_first_chunk ? '' : ' (continued)');

        $tail_html = '';
        if ($is_last_chunk) {
            // Totals wording corrected per directive: Total Mains / Total Sides.
            $totals = sprintf(
                'Total Items: %d | Total Mains: %d | Total Sides: %d',
                (int) ($slip['total_items'] ?? 0),
                (int) ($slip['total_mains'] ?? 0),
                (int) ($slip['total_sides'] ?? 0)
            );
            $tail_html = '<div class="d2-totals">' . $totals
                . '<span class="d2-page">Page ' . $page_x . ' of ' . $page_y . '</span></div>';

            $notes = (string) ($slip['additional_notes'] ?? '');
            if ($notes !== '') {
                $tail_html .= '<div class="d2-notes"><span class="d2-notes-label">Additional Notes:</span> '
                    . nl2br(self::esc($notes)) . '</div>';
            }
        } else {
            // Continuation pages still show their global page number.
            $tail_html = '<div class="d2-totals"><span class="d2-page">Page ' . $page_x . ' of ' . $page_y . '</span></div>';
        }

        // Divider only where doc 4's overlay lands (first chunk page).
        $divider_html = $is_first_chunk ? '<div class="d2-divider"></div>' : '';

        $page_class = 'doc2-page' . ($is_last_page ? '' : ' d2-break');

        return <<<HTML
<div class="{$page_class}">
    <div class="d2-name-line">Name: {$initials}</div>
    <div class="d2-zone-order">{$zone} - Order {$order_number}</div>
    <div class="d2-delivery">Delivery Date: {$delivery_date}</div>
    <div class="d2-position">{$position}</div>
    <div class="d2-flow">
        <table class="d2-items">
            <thead>
                <tr><th class="d2-sku">SKU</th><th class="d2-qty">Qty</th><th class="d2-name">Product</th><th class="d2-cat">Category</th></tr>
            </thead>
            <tbody>{$items_html}</tbody>
        </table>
        {$tail_html}
    </div>
    {$divider_html}
</div>
HTML;
    }
```

- [ ] **Step 4: Combined renderer uses chunk counts**

In `render_packing_slips_combined_html()`, replace the body from the `$count = count($slips);` line through the end of the `foreach` loop with:

```php
        $count  = count($slips);
        $counts = self::doc2_counts_for_slips($slips);
        $y      = 1 + array_sum($counts); // cover (1) + every chunk page.

        // Cover reflects the LIVE count; break after it only if slips follow.
        $cover_batch = $batch;
        $cover_batch['order_count'] = $count;
        $body = $this->doc1_body_html($zone_name, $delivery_date, $cover_batch, $count > 0);

        $page_x = 2; // cover is page 1.
        foreach ($slips as $i => $slip) {
            $n             = $i + 1; // order N within the zone batch
            $is_last_order = ($i === $count - 1);
            $body   .= $this->render_doc2_order_pages($slip, $n, $count, $page_x, $y, $is_last_order);
            $page_x += $counts[$i];
        }
```

(Keep the `$css` line above and the final `return` line as they are.)

- [ ] **Step 5: Doc 4 — HTML split + blank padding + public counts**

REPLACE the entire `generate_doc4_driver_blocks()` method with:

```php
    /**
     * Render the saved doc 4 driver blocks as standalone landscape pages —
     * the block alone at the calibrated right-region position, NO item
     * table and NO divider (this is the print-on-top manual fallback; the
     * physical slip it overlays already carries the divider).
     *
     * $page_counts (positional, from doc2_page_counts()) pads BLANK pages
     * behind each block so the doc-4 page sequence mirrors the doc-2
     * order pages: a chunked order occupies several physical sheets, and
     * the driver block must land on the FIRST of them only. Empty/absent
     * counts preserve the one-page-per-order output.
     *
     * @param array<int,array> $doc4_orders persisted, positional driver blocks
     * @param array<int,int>   $page_counts doc-2 pages per order, positional
     */
    public function generate_doc4_driver_blocks(array $doc4_orders, array $page_counts = []): string {
        return $this->render_with_dompdf($this->render_doc4_html($doc4_orders, $page_counts));
    }

    /** The doc-4 document HTML (dompdf-free, unit-testable). */
    private function render_doc4_html(array $doc4_orders, array $page_counts): string {
        $css    = $this->midland_doc_css();
        $orders = array_values($doc4_orders);
        $count  = count($orders);

        $body = '';
        foreach ($orders as $i => $order) {
            $pages_for_order = max(1, (int) ($page_counts[$i] ?? 1));
            $is_last_order   = ($i === $count - 1);
            $block           = self::driver_block_inner_html(is_array($order) ? $order : []);

            for ($p = 0; $p < $pages_for_order; $p++) {
                $is_last_page = $is_last_order && ($p === $pages_for_order - 1);
                $page_class   = 'doc4-page' . ($is_last_page ? '' : ' d4-break');
                // Block on the order's FIRST page only; the rest are blank
                // spacers that keep the manual re-feed sheet-aligned.
                $inner = ($p === 0) ? '<div class="d4-block">' . $block . '</div>' : '';
                $body .= "<div class=\"{$page_class}\">{$inner}</div>";
            }
        }
        if ($body === '') {
            $body = '<div class="doc4-page"><div class="d2-empty">No driver blocks in this batch.</div></div>';
        }

        return "<!DOCTYPE html>\n<html><head><meta charset=\"UTF-8\"><style>{$css}</style></head><body>{$body}</body></html>";
    }

    /**
     * Live doc-2 page count per order for a zone + date, positionally
     * matching the batch's persisted doc-4 blocks (both derive from the
     * same build_slips() ordering). Used by the doc-4 download so the
     * overlay stays sheet-aligned with a chunked doc 2.
     *
     * @return array<int,int>
     */
    public function doc2_page_counts(string $zone_name, string $delivery_date): array {
        $clients = $this->client_query->get_clients_for_zones([$zone_name]);
        $orders  = $this->fetch_orders_for_clients($clients, $delivery_date, $delivery_date);
        $slips   = $this->build_slips($orders, $clients, false);
        return self::doc2_counts_for_slips($slips);
    }
```

- [ ] **Step 6: Wire the doc-4 download**

In `includes/ajax/class-ajax-slip-batch.php`, `download_doc4()`, replace the `$pdf = $generator->generate_doc4_driver_blocks(...)` call with:

```php
            // Chunked doc-2 orders span multiple physical sheets; pass the
            // live per-order page counts so doc 4 pads blank pages and the
            // manual overlay lands on each order's FIRST sheet.
            $pdf = $generator->generate_doc4_driver_blocks(
                is_array($batch['orders'] ?? null) ? $batch['orders'] : [],
                $generator->doc2_page_counts(
                    (string) ($batch['zone_name'] ?? ''),
                    (string) ($batch['delivery_date'] ?? '')
                )
            );
```

- [ ] **Step 7: Update the superseded CSS comment**

In `midland_doc_css()`, the `.d2-flow` comment block ends with a `KNOWN LIMIT:` paragraph (added in fc48ace) saying doc 2 has no item pagination. Replace that paragraph (keep the rest of the comment) with:

```
   Row chunking (doc2_chunk_sizes) splits an order across pages when the
   flowed content would cross the DOC2_PRINT_MARGIN_IN band; a fitting
   slip renders on one page exactly as before. Remaining limit: the
   NOTES block itself is never split — pathological multi-hundred-char
   notes can still push past the margin on the final page. */
```

- [ ] **Step 8: Run all tests, lint, commit**

```bash
php -l includes/services/class-slip-pdf-generator.php && php -l includes/ajax/class-ajax-slip-batch.php
php tests/test-slip-doc2-chunking.php
php tests/test-slip-midland-render.php
php tests/test-ajax-slip-batch.php
git add tests/test-slip-doc2-chunking.php includes/services/class-slip-pdf-generator.php includes/ajax/class-ajax-slip-batch.php
git commit -m "feat(slips): chunk doc-2 rows across pages past the print margin; doc-4 pads blanks to stay overlay-aligned"
```

Expected: `OK: 33 checks passed` (13 + 20), `PASS — 35` (midland test unchanged: 0-item slips fit → single pages), `PASS — 21`.

---

### Task 3: Full-suite verification and PR (stacked on #470)

**Files:** none

- [ ] **Step 1: Full suite**

```bash
for f in tests/test-*.php; do php "$f" >/dev/null 2>&1 || echo "FAIL: $f"; done
```

Expected: only the 2 known PDF baseline failures.

- [ ] **Step 2: Push and open the PR against the #470 branch**

```bash
git push -u origin feat/doc2-row-chunking
gh pr create --base fix/packing-slip-layout --head feat/doc2-row-chunking --title "feat(slips): Doc 2 row chunking past the print margin, Doc 4 overlay stays aligned" --body "$(cat <<'EOF'
## Summary
Follow-up to #470. A Doc 2 packer slip now splits its item rows across pages — but ONLY when the flowed content (table + totals + notes) would bleed into a standard **0.5in bottom printing margin**; any slip that fits renders exactly as before (asserted byte-behavior in tests).

- Pure, unit-tested chunk math (`doc2_chunk_sizes`): 26 single-line rows fit one page (no notes); chunked orders use 28-row full pages with totals + notes reserved on the last page; notes shrink the budget via a conservative line estimate
- Chunk pages repeat the order header with "(continued)" and their own global page number; totals/notes render once, on the last chunk; the calibrated divider renders only on each order's FIRST page
- **Doc 4 stays physically aligned:** the driver sheets are printed on top of the Doc 2 stack, so the doc-4 download now recomputes the same live per-order page counts and pads blank spacer pages behind chunked orders — the driver block always lands on the order's first sheet
- Global page numbering (cover "Page 1 of Y" + every chunk page) reflects the true page total
- Remaining limit (documented): the notes block itself is never split — pathological multi-hundred-char notes can still pass the margin on the final page

## Test plan
- [x] `php tests/test-slip-doc2-chunking.php` — 33 checks (math boundaries incl. 26/27 threshold, notes budget, degenerate giant notes; chunked-HTML markers/numbering/divider placement; doc-4 blank padding + unchanged default)
- [x] `php tests/test-slip-midland-render.php` — 35 checks (fitting slips byte-unchanged); `php tests/test-ajax-slip-batch.php` — 21 checks
- [ ] Live render: a 30+ item order chunks with correct page numbers and no margin bleed; a normal order is pixel-identical to #470; print Doc 2 + overlay Doc 4 on a chunked batch — driver text lands on each order's first sheet, spacer pages blank

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Merge on request; base auto-retargets to main when #470 merges. CI owns version bumps.
