# Packing Slips — Remove Merge, Combine Cover into Packer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete the doc-3 upload/rasterize/combine (merge) machinery entirely, and fold the Doc 1 cover sheet into the Doc 2 packer as page 1, exposed behind one "Packing Slips" download. Final per-row actions become **Packing Slips | Doc 4 (driver) | Cancel**.

**Architecture:** The cover (Doc 1) and packer slips (Doc 2) are both produced on-demand by dompdf from the persisted batch. We render BOTH into a SINGLE dompdf document — cover body first (with a page break after), then the existing per-order slip pages — so pagination is inherently continuous and no PDF-concatenation dependency is added. The total page count is derived from the LIVE packer slip count (not the batch snapshot) so the combined document numbers consistently. Everything related to doc-3 upload, validation, and the doc-4-over-doc-3 merge is deleted: the merge service, two AJAX handlers, two batch-service store methods, three schema columns, and two JS handlers. Doc 4 (driver), batch generation/persistence, the `meals_slip_batches` table, Cancel, and audit logging are untouched.

**Tech Stack:** PHP 8.2 / WordPress (HPOS), dompdf (vendored), jQuery, bespoke `php tests/test-*.php` harness (no PHPUnit).

**Source directive:** `directives/DIRECTIVE-slips-remove-merge-combine-cover.md`

**Three deviations from the directive (decided during assessment, baked into the tasks below):**
1. The directive omits **`tests/test-ajax-slip-batch.php`** and **`tests/test-slip-batch.php`**, both of which exercise methods being deleted. Tasks 3 and 5 update them.
2. **Page-count divergence:** Doc 1's count is the persisted snapshot; Doc 2 re-queries live orders. In ONE document a mismatch shows as wrong "Page X of Y". Task 1 derives the combined total `Y` from the LIVE slip count.
3. **`download_url()` strips underscores** from `which` (`/[^a-z0-9]/`), so `which='packing_slips'` would yield `mealsdb_slip_download_packingslips`. Task 2 widens the regex to `/[^a-z0-9_]/` so the action name matches the directive's `mealsdb_slip_download_packing_slips`.

---

## File Structure

| File | Change |
|---|---|
| `includes/services/class-slip-pdf-generator.php` | Extract `doc1_body_html()`; add `generate_packing_slips_combined()` + dompdf-free `render_packing_slips_combined_html()`. |
| `includes/ajax/class-ajax-slip-batch.php` | Add `download_packing_slips()`; remove `download_doc1/doc2/merged`, `upload_doc3`, `combine`, their `add_action`s, `MAX_UPLOAD_BYTES`, `sniff_pdf`, `scratch_copy`; widen `download_url()` regex; rewrite header doc. |
| `includes/services/class-slip-merge.php` | **Delete.** |
| `includes/services/class-slip-batch.php` | Remove `store_doc3/store_merged`; drop doc3/merged columns from SELECT/list; drop `has_doc3/has_merged`; strip doc3/merged unlinks from `cancel()`; remove now-dead storage helpers + status constants. |
| `includes/class-schema.php` | Remove `doc3_path`, `doc3_page_count`, `merged_path`; simplify `status` ENUM declaration. |
| `assets/js/slip-batch.js` | Delete upload + combine handlers; drop `i18n.uploading`/`combining` usage. |
| `includes/admin/class-slip-batch-page.php` | Replace row buttons with Packing Slips / Doc 4 / Cancel; drop upload span, combine button, merged link, `has_*`; drop `uploading`/`combining` i18n keys; fix intro text. |
| `tests/test-slip-merge-rasterize-failed.php` | **Delete.** |
| `tests/test-slip-merge-validate.php` | **Delete.** |
| `tests/test-ajax-slip-batch.php` | Remove `MealsDB_Slip_Merge` stub, UP-1, CB-1; add DL-1 (download_url action name). |
| `tests/test-slip-batch.php` | Remove T-6/T-7/T-8 (store_doc3/store_merged), the `has_*` asserts in T-5, the file asserts in T-9, and the now-unused `make_tmp_pdf` helper. |
| `tests/test-slip-midland-render.php` | Add CMB-1: combined-render structure assertions. |

**Note on schema migration:** Removing columns from the canonical schema is documentation-only for existing installs. `MealsDB_Schema_Sync` is additive-only (it never drops columns), so existing installs keep `doc3_path`/`doc3_page_count`/`merged_path` as unused dead weight; new installs never get them. This is acceptable per the directive (A4) — do NOT write a destructive migration. **No `MEALS_DB_VERSION` bump is needed** (there is no additive change to apply).

---

## Task 1: Combined Packing-Slips generator (cover + packer in one dompdf document)

**Files:**
- Modify: `includes/services/class-slip-pdf-generator.php` (refactor `render_doc1_html` ~968–1035; add new methods after `generate_doc2_packer_by_zones`/`render_doc2_page` ~1124)
- Test: `tests/test-slip-midland-render.php`

- [ ] **Step 1: Extract the cover body into `doc1_body_html()`**

Replace the existing `render_doc1_html()` (the method starting `private function render_doc1_html(string $zone_name, string $delivery_date, array $batch): string {` at ~968 and ending with its `return "<!DOCTYPE html>...";`) with these TWO methods. The cover markup is byte-identical to today except the page `<div>` class gains an optional ` d2-break` when `$page_break` is true:

```php
    private function render_doc1_html(string $zone_name, string $delivery_date, array $batch): string {
        $css  = $this->midland_doc_css();
        $body = $this->doc1_body_html($zone_name, $delivery_date, $batch, false);
        return "<!DOCTYPE html>\n<html><head><meta charset=\"UTF-8\"><style>{$css}</style></head><body>{$body}</body></html>";
    }

    /**
     * The DOC 1 cover BODY fragment (no <html> wrapper), so the combined
     * Packing-Slips document can place it as page 1 ahead of the doc 2 slips.
     * Standalone doc 1 passes $page_break=false (a lone page needs no break);
     * the combined doc passes true so a page break separates the cover from the
     * first packer slip. Page numbering ("Page 1 of {1+order_count}") is
     * unchanged — the caller sets order_count to the count it wants reflected.
     */
    private function doc1_body_html(string $zone_name, string $delivery_date, array $batch, bool $page_break = false): string {
        $orders      = is_array($batch['orders'] ?? null) ? $batch['orders'] : [];
        $order_count = (int) ($batch['order_count'] ?? count($orders));
        $created_at  = (string) ($batch['created_at'] ?? '');

        $zone_number  = $this->resolve_zone_number($zone_name);
        $zone_title   = $zone_number !== null ? 'Zone ' . $zone_number : self::esc($zone_name);
        $delivery_lbl = self::esc($this->format_long_date($delivery_date));

        // Initials line: delivery_initials of orders flagged take_from_hold at
        // generation, joined " | ". NONE when none qualify (operator decision
        // 2026-06-26 — explicit "none", not a blank line). Always from the
        // persisted snapshot, even in the combined doc.
        $initials = [];
        foreach ($orders as $o) {
            if (!empty($o['take_from_hold'])) {
                $ini = trim((string) ($o['initials'] ?? ''));
                if ($ini !== '') {
                    $initials[] = $ini;
                }
            }
        }
        $initials_line = empty($initials) ? 'NONE' : implode(' | ', array_map([self::class, 'esc'], $initials));

        $exported_line = self::esc($this->format_export_timestamp($created_at));

        $legend_rows  = $this->build_legend_rows();
        $legend_html  = '';
        foreach ($legend_rows as $r) {
            $legend_html .= '<tr>'
                . '<td>' . self::esc($r['zone']) . '</td>'
                . '<td>' . self::esc($r['weekday']) . '</td>'
                . '<td>' . self::esc($r['area']) . '</td>'
                . '</tr>';
        }
        if ($legend_html === '') {
            $legend_html = '<tr><td colspan="3" class="legend-empty">(delivery schedule not configured)</td></tr>';
        }

        $page_y = 1 + $order_count; // cover is page 1; one page per order after.
        $brk    = $page_break ? ' d2-break' : '';

        return <<<HTML
<div class="doc1-page{$brk}">
    <div class="d1-zone">{$zone_title}</div>
    <div class="d1-date">Delivery Date: {$delivery_lbl}</div>
    <div class="d1-gap"></div>
    <div class="d1-hold-label">ORDERS - TAKE FROM HOLD</div>
    <div class="d1-initials">{$initials_line}</div>
    <div class="d1-gap"></div>
    <table class="d1-legend">
        <thead>
            <tr><th colspan="3" class="legend-title">LEGEND: DELIVERY SCHEDULE FOR PACKING</th></tr>
            <tr><th>ZONE #</th><th>WEEKDAY</th><th>AREA</th></tr>
        </thead>
        <tbody>{$legend_html}</tbody>
    </table>
    <div class="d1-exported">Orders Exported {$exported_line}</div>
    <div class="d1-gap"></div>
    <div class="d1-count">{$order_count} Orders</div>
    <div class="d1-footer">Page 1 of {$page_y}</div>
</div>
HTML;
    }
```

- [ ] **Step 2: Add the combined generator + its dompdf-free renderer**

Insert these two methods immediately AFTER `render_doc2_page(...)` (which ends at ~1124, just before the `// DOC 4` banner comment):

```php
    // ----------------------------------------------------------------- //
    //  COMBINED — cover (page 1) + packer slips, one document.
    // ----------------------------------------------------------------- //

    /**
     * Render ONE PDF = doc 1 cover (page 1) followed by the doc 2 packer slips.
     * Both are produced by dompdf from the same batch, so we emit them into a
     * SINGLE document — no PDF-concatenation dependency. The cover's "N Orders"
     * and the global "Page X of Y" are derived from the LIVE packer slip count
     * (not the batch snapshot) so the combined document numbers consistently
     * even if orders changed since generation. The take-from-hold initials line
     * still comes from the persisted snapshot ($batch['orders']).
     *
     * @param array $batch decoded batch: ['orders'=>array, 'created_at'=>UTC, ...]
     */
    public function generate_packing_slips_combined(string $zone_name, string $delivery_date, array $batch): string {
        $clients = $this->client_query->get_clients_for_zones([$zone_name]);
        $orders  = $this->fetch_orders_for_clients($clients, $delivery_date, $delivery_date);
        $slips   = $this->build_slips($orders, $clients, false);
        $html    = $this->render_packing_slips_combined_html($zone_name, $delivery_date, $batch, $slips);
        return $this->render_with_dompdf($html);
    }

    /**
     * The combined document HTML (dompdf-free, unit-testable). Cover body first
     * with a page break after it (only when slips follow, so a zero-order batch
     * doesn't emit a trailing blank page), then each packer slip page.
     *
     * @param array<int,array> $slips live packer slips from build_slips()
     */
    private function render_packing_slips_combined_html(string $zone_name, string $delivery_date, array $batch, array $slips): string {
        $css   = $this->midland_doc_css();
        $count = count($slips);
        $y     = 1 + $count; // cover (1) + one page per order.

        // Cover reflects the LIVE count; break after it only if slips follow.
        $cover_batch = $batch;
        $cover_batch['order_count'] = $count;
        $body = $this->doc1_body_html($zone_name, $delivery_date, $cover_batch, $count > 0);

        foreach ($slips as $i => $slip) {
            $n       = $i + 1;       // order N within the zone batch
            $page_x  = $n + 1;       // global page # (cover is page 1)
            $is_last = ($i === $count - 1);
            $body   .= $this->render_doc2_page($slip, $n, $count, $page_x, $y, $is_last);
        }

        return "<!DOCTYPE html>\n<html><head><meta charset=\"UTF-8\"><style>{$css}</style></head><body>{$body}</body></html>";
    }
```

- [ ] **Step 3: Write the failing test (CMB-1) in `tests/test-slip-midland-render.php`**

Add this block immediately before the final report line (the `echo "\n=== Midland renderers ...` line near the bottom). It uses the file's existing `call_priv()` helper and `chk*` harness:

```php
// ===========================================================================
// CMB-1 — combined Packing-Slips HTML: cover page 1 + continuous numbering.
// ===========================================================================
$cmb_batch = [
    'orders'     => [
        ['initials' => 'AAA', 'take_from_hold' => true],
        ['initials' => 'BBB', 'take_from_hold' => false],
    ],
    'created_at' => '2026-06-30 12:00:00',
];
$cmb_slip = [
    'initials' => 'AAA', 'zone' => 'Zone 1', 'order_number' => '#100',
    'delivery_date' => 'June 30, 2026', 'items' => [],
    'total_items' => 0, 'total_mains' => 0, 'total_sides' => 0, 'additional_notes' => '',
];
$two_slips = [$cmb_slip, ['initials' => 'BBB'] + $cmb_slip];
$html = call_priv($gen, 'render_packing_slips_combined_html', 'Moncton Downtown', '2026-06-30', $cmb_batch, $two_slips);

chk(substr_count($html, 'doc1-page'), 1, 'CMB-1: exactly one cover page');
chk(substr_count($html, 'doc2-page'), 2, 'CMB-1: two packer pages');
chk_true(strpos($html, '<div class="doc1-page d2-break">') !== false, 'CMB-1: cover breaks before first slip');
chk_true(strpos($html, 'Page 1 of 3') !== false, 'CMB-1: cover stamped "Page 1 of 3"');
chk_true(strpos($html, 'Page 2 of 3') !== false, 'CMB-1: first slip "Page 2 of 3"');
chk_true(strpos($html, 'Page 3 of 3') !== false, 'CMB-1: last slip "Page 3 of 3"');
chk_true(strpos($html, '2 Orders') !== false, 'CMB-1: cover count reflects live slip count');
// Last page must NOT carry a trailing page break (no blank trailing page).
$last = strrpos($html, 'doc2-page');
chk_true(strpos($html, 'doc2-page d2-break', $last) === false, 'CMB-1: last slip has no trailing break');

// Zero-order edge: cover only, no trailing break.
$html0 = call_priv($gen, 'render_packing_slips_combined_html', 'Moncton Downtown', '2026-06-30', $cmb_batch, []);
chk_true(strpos($html0, '<div class="doc1-page">') !== false, 'CMB-1: zero-order cover has no break');
chk_true(strpos($html0, 'Page 1 of 1') !== false, 'CMB-1: zero-order cover "Page 1 of 1"');
```

- [ ] **Step 4: Run the test — expect FAIL first (method missing), then PASS after Steps 1–2 land**

Run: `php tests/test-slip-midland-render.php`
Expected: `PASS — <n> checks` (the new CMB-1 checks included). If you ran the test before adding the methods, it fails with a `ReflectionException` on `render_packing_slips_combined_html`.

- [ ] **Step 5: Lint + commit**

```bash
php -l includes/services/class-slip-pdf-generator.php
php tests/test-slip-midland-render.php
git add includes/services/class-slip-pdf-generator.php tests/test-slip-midland-render.php
git commit -m "feat(slips): combined cover+packer generator (one dompdf document)

Extract doc1_body_html; add generate_packing_slips_combined +
render_packing_slips_combined_html. Total page count derives from the live
packer slip count so the single document numbers continuously."
```

---

## Task 2: New `download_packing_slips` AJAX handler; widen `download_url`; drop doc1/doc2 downloads

**Files:**
- Modify: `includes/ajax/class-ajax-slip-batch.php`
- Test: `tests/test-ajax-slip-batch.php`

- [ ] **Step 1: Replace the `download_doc1` + `download_doc2` methods with one `download_packing_slips`**

Delete both `download_doc1()` (~292–309) and `download_doc2()` (~311–324) and put this single method in their place:

```php
    public static function download_packing_slips(): void {
        $batch = self::download_guard();
        try {
            $generator = self::make_pdf_generator();
            $pdf = $generator->generate_packing_slips_combined(
                (string) ($batch['zone_name'] ?? ''),
                (string) ($batch['delivery_date'] ?? ''),
                [
                    'order_count' => (int) ($batch['order_count'] ?? 0),
                    'orders'      => is_array($batch['orders'] ?? null) ? $batch['orders'] : [],
                    'created_at'  => (string) ($batch['created_at'] ?? ''),
                ]
            );
            self::stream_pdf($pdf, self::filename($batch, 'packing-slips'));
        } catch (\Throwable $e) {
            self::fail_die($e);
        }
    }
```

- [ ] **Step 2: Update the download `add_action` registrations**

In `init()` (~57–61), replace the doc1/doc2 lines with the single combined one. Leave `download_doc4` and `download_merged` lines for now (merged is removed in Task 3). The file-streams block should read:

```php
        // File streams (GET download links).
        add_action('wp_ajax_mealsdb_slip_download_packing_slips', [self::class, 'download_packing_slips']);
        add_action('wp_ajax_mealsdb_slip_download_doc4',          [self::class, 'download_doc4']);
        add_action('wp_ajax_mealsdb_slip_download_merged',        [self::class, 'download_merged']);
```

- [ ] **Step 3: Widen `download_url()` so `packing_slips` keeps its underscore**

In `download_url()` (~424) change the regex from `/[^a-z0-9]/` to `/[^a-z0-9_]/`:

```php
        $action = 'mealsdb_slip_download_' . preg_replace('/[^a-z0-9_]/', '', $which);
```

This makes `download_url($id, 'packing_slips')` build `...download_packing_slips` (matching Step 2). Existing `doc4`/`merged` are unaffected (no underscores).

- [ ] **Step 4: Add DL-1 to `tests/test-ajax-slip-batch.php`**

Add this block right before the `// ---- cleanup temp upload root ----` comment near the bottom:

```php
// ===========================================================================
// DL-1 — download_url builds the combined-download action (underscore kept).
// ===========================================================================
reset_env();
$url = MealsDB_Ajax_Slip_Batch::download_url(5, 'packing_slips');
chk_true(strpos($url, 'action=mealsdb_slip_download_packing_slips') !== false, 'DL-1 packing_slips action name');
$url4 = MealsDB_Ajax_Slip_Batch::download_url(5, 'doc4');
chk_true(strpos($url4, 'action=mealsdb_slip_download_doc4') !== false, 'DL-1 doc4 action unchanged');
```

> Note: `download_url` uses `add_query_arg`; the test stubs already define WP shims. If `add_query_arg` is not stubbed in this test, add a minimal shim next to the other `function_exists` guards near the top:
> ```php
> if (!function_exists('add_query_arg')) {
>     function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
> }
> if (!function_exists('admin_url')) { function admin_url($p = '') { return 'http://t/wp-admin/' . $p; } }
> if (!function_exists('wp_create_nonce')) { function wp_create_nonce($a = '') { return 'nonce'; } }
> ```

- [ ] **Step 5: Lint, run test, commit**

```bash
php -l includes/ajax/class-ajax-slip-batch.php
php tests/test-ajax-slip-batch.php
git add includes/ajax/class-ajax-slip-batch.php tests/test-ajax-slip-batch.php
git commit -m "feat(slips): single Packing Slips download (cover+packer); drop doc1/doc2

Add download_packing_slips streaming the combined PDF; widen download_url to
keep the packing_slips underscore so the action name matches."
```

---

## Task 3: Remove the merge AJAX machinery (upload/combine/download_merged) + helpers

**Files:**
- Modify: `includes/ajax/class-ajax-slip-batch.php`
- Test: `tests/test-ajax-slip-batch.php`

- [ ] **Step 1: Delete the three handler methods**

Delete `upload_doc3()` (~117–196), `combine()` (~198–246), and `download_merged()` (~339–351) in full.

- [ ] **Step 2: Remove their `add_action` registrations**

In `init()`, delete these three lines:

```php
        add_action('wp_ajax_mealsdb_slip_upload_doc3',    [self::class, 'upload_doc3']);
        add_action('wp_ajax_mealsdb_slip_combine',        [self::class, 'combine']);
        add_action('wp_ajax_mealsdb_slip_download_merged',        [self::class, 'download_merged']);
```

- [ ] **Step 3: Remove the now-unused upload constant + helpers**

Delete the `MAX_UPLOAD_BYTES` class constant (~46–47), and the `sniff_pdf()` (~458–467) and `scratch_copy()` (~469–485) private helpers — they were only called by `upload_doc3`. Verify with grep before deleting:

```bash
grep -n "MAX_UPLOAD_BYTES\|sniff_pdf\|scratch_copy\|storage_dir" includes/ajax/class-ajax-slip-batch.php
```
Expected after deletion: no matches (each was upload-only; `scratch_copy` called `MealsDB_Slip_Batch::storage_dir('tmp')`, which Task 5 also removes).

- [ ] **Step 4: Rewrite the file header doc block**

The top doc comment (~1–37) lists `mealsdb_slip_upload_doc3`, `mealsdb_slip_combine`, `download_doc1`, `download_doc2`, `download_merged` and "combine" semantics. Rewrite the endpoint list to reflect reality:

```php
 *   JSON mutations (POST):
 *     mealsdb_slip_generate_batch  → generate a batch (persist doc 4 payloads)
 *     mealsdb_slip_cancel          → hard-delete the batch
 *     mealsdb_slip_list            → history rows (table refresh)
 *
 *   File streams (GET link, nonce in URL):
 *     mealsdb_slip_download_packing_slips → cover (page 1) + packer slips, one PDF
 *     mealsdb_slip_download_doc4          → standalone driver blocks (manual overlay)
```
Drop the line about "combine → composite doc 4 onto doc 3" and the "doc 4 / the merged output expose DECRYPTED PII" wording — keep the PII caution but phrase it against the packing-slips/doc-4 downloads.

- [ ] **Step 5: Update `tests/test-ajax-slip-batch.php` — remove the merge stub and dead cases**

1. Delete the `MealsDB_Slip_Merge` stub class (~84–89).
2. Delete the **UP-1** section (~212–227) and the **CB-1** section (~229–236).
3. In the header doc comment (~11–12), delete the `UP-1` and `CB-1` lines.

- [ ] **Step 6: Lint, run test, commit**

```bash
php -l includes/ajax/class-ajax-slip-batch.php
php tests/test-ajax-slip-batch.php
git add includes/ajax/class-ajax-slip-batch.php tests/test-ajax-slip-batch.php
git commit -m "refactor(slips): delete doc3 upload + combine + merged-download AJAX

Remove upload_doc3/combine/download_merged handlers, their actions, the upload
size cap, and the PDF-sniff/scratch-copy helpers. Update tests + header doc."
```

---

## Task 4: Delete the merge service and its tests

**Files:**
- Delete: `includes/services/class-slip-merge.php`, `tests/test-slip-merge-rasterize-failed.php`, `tests/test-slip-merge-validate.php`

- [ ] **Step 1: Confirm nothing live still references the merge service**

```bash
grep -rn "MealsDB_Slip_Merge\|class-slip-merge" includes/ tests/ meals-db-main.php assets/
```
Expected matches now: only a COMMENT in `includes/services/class-slip-batch.php` (inside `store_doc3`, removed in Task 5). No `require`/`use`/call sites in live code. If anything else appears (e.g. an autoload/require line in `meals-db-main.php`), note it and remove it in this task.

- [ ] **Step 2: Delete the three files**

```bash
git rm includes/services/class-slip-merge.php tests/test-slip-merge-rasterize-failed.php tests/test-slip-merge-validate.php
```

- [ ] **Step 3: Verify the autoloader needs no change**

The plugin uses `MealsDB_Autoloader` (class-name → file mapping). Deleting the class file is sufficient; there is no manual require to remove unless Step 1 found one. Confirm:

```bash
grep -rn "slip-merge\|Slip_Merge" meals-db-main.php includes/class-autoloader.php
```
Expected: no matches.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore(slips): delete MealsDB_Slip_Merge service and its tests"
```

---

## Task 5: Batch service — drop store_doc3/store_merged, doc3/merged columns, file handling

**Files:**
- Modify: `includes/services/class-slip-batch.php`
- Test: `tests/test-slip-batch.php`

- [ ] **Step 1: Delete the two store methods**

Delete `store_doc3()` (~218–290) and `store_merged()` (~292–345) in full.

- [ ] **Step 2: Drop doc3/merged columns from `get()`**

In `get()` (~117), change the SELECT column list to drop `doc3_path, doc3_page_count, merged_path`:

```php
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT batch_id, zone_name, delivery_date, order_count, doc4_payload,
                        status, created_by, created_at, updated_at
                 FROM `{$table}` WHERE batch_id = %d",
                $batch_id
            ), ARRAY_A);
```

- [ ] **Step 3: Drop doc3/merged columns and `has_*` flags from `list_batches()`**

In `list_batches()` (~167), change the SELECT and delete the `has_doc3`/`has_merged` derivation loop:

```php
            // doc4_payload is deliberately NOT selected — list view needs no PII.
            $sql = "SELECT batch_id, zone_name, delivery_date, order_count,
                           status, created_by, created_at, updated_at
                    FROM `{$table}`";
```
Then delete this whole block (~203–209):
```php
            // Derive the action-state booleans the UI needs without exposing
            // the raw paths' contents.
            foreach ($rows as &$r) {
                $r['has_doc3']   = !empty($r['doc3_path']);
                $r['has_merged'] = !empty($r['merged_path']);
            }
            unset($r);
```
So the method returns `$rows` straight after the `if (!is_array($rows)) { return []; }` guard.

- [ ] **Step 4: Strip doc3/merged file handling from `cancel()`**

Replace the body of `cancel()` (~354–397) so it only deletes the row (no path fetch, no unlinks):

```php
    public static function cancel(int $batch_id): bool {
        try {
            if ($batch_id <= 0) {
                return false;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);

            $deleted = $wpdb->delete($table, ['batch_id' => $batch_id], ['%d']);
            if ($deleted === false || (int) $deleted === 0) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            self::log_error('cancel', $e);
            return false;
        }
    }
```

- [ ] **Step 5: Remove now-dead status constants and storage helpers**

Delete the two unreachable status constants (~38–39):
```php
    public const STATUS_DOC3_UPLOADED = 'doc3_uploaded';
    public const STATUS_COMBINED      = 'combined';
```
(Keep `STATUS_GENERATED`.)

Then verify the on-disk storage helpers are now unused and delete them: `storage_dir()`, `protected_dir()`, `ensure_protected_dir()`, `move_file()`, `delete_file_quietly()`, `random_token()`, and the `STORAGE_SUBDIR` constant. Confirm first:

```bash
grep -rn "storage_dir\|protected_dir\|ensure_protected_dir\|move_file\|delete_file_quietly\|random_token\|STORAGE_SUBDIR" includes/ ajax/ assets/ 2>/dev/null
```
Expected: matches ONLY inside `class-slip-batch.php` itself (their own definitions, now unreferenced after Steps 1–4 and Task 3's `scratch_copy` removal). If any OTHER file references them, do NOT delete that helper — note the caller. Delete the unreferenced helpers and the `STORAGE_SUBDIR` const. Also update the class header doc (~5–25) to drop the "upload doc 3 → combine (merge)" workflow description and the doc-3/merged on-disk paragraph.

- [ ] **Step 6: Update `tests/test-slip-batch.php`**

1. **T-5** (~197–209): delete the two `has_*` asserts and replace with absence checks:
```php
chk_true(!isset($list[0]['has_doc3']) && !isset($list[0]['has_merged']),
    'T-5: list no longer exposes has_doc3/has_merged');
```
2. **Delete T-6, T-7, T-8 in full** (~211–243) — they exercise `store_doc3`/`store_merged`.
3. **T-9** (~245–251): the batch from T-5 (`$id`) still exists; rewrite to assert row deletion only (no files):
```php
// ===========================================================================
// T-9 — cancel hard-deletes the row.
// ===========================================================================
chk_true(MealsDB_Slip_Batch::cancel($id), 'T-9: cancel returns true');
chk_true(!isset($w->rows[$id]), 'T-9: row removed');
```
4. Delete the now-unused `make_tmp_pdf()` helper (~162–170) — verify with `grep -n make_tmp_pdf tests/test-slip-batch.php` first; it should have no remaining callers after T-6/T-7 are gone.
5. In the header doc comment, delete the `T-6`, `T-8`, `T-9 ... files` descriptions (and fix T-9's wording to "hard-deletes the row").

- [ ] **Step 7: Lint, run test, commit**

```bash
php -l includes/services/class-slip-batch.php
php tests/test-slip-batch.php
git add includes/services/class-slip-batch.php tests/test-slip-batch.php
git commit -m "refactor(slips): drop doc3/merged persistence from batch service

Remove store_doc3/store_merged, the doc3/merged columns from SELECT/list, the
has_* flags, cancel's file unlinks, and the now-dead storage helpers + status
constants. cancel() is now a pure row delete. Update tests."
```

---

## Task 6: Schema — remove doc3/merged columns; simplify status ENUM

**Files:**
- Modify: `includes/class-schema.php` (~763–785)

- [ ] **Step 1: Remove the three column definitions and tidy the ENUM**

In the `MealsDB_Tables::SLIP_BATCHES` definition, delete these column lines (~775–780):

```php
                    // Uploaded doc 3 scan: path to the stored PDF under
                    // wp_upload_dir()/mealsdb-slips/doc3/ (protected dir).
                    'doc3_path'       => 'TEXT NULL',
                    'doc3_page_count' => 'INT UNSIGNED NULL',
                    // Merged finished output: path under mealsdb-slips/merged/.
                    'merged_path'     => 'TEXT NULL',
```

And change the `status` line (~781) to the only value the code ever writes, with an updated comment:

```php
                    // Only 'generated' is ever written (cancel() hard-deletes
                    // the row). Schema_Sync is additive-only and cannot ALTER an
                    // existing ENUM, so installs predating the merge removal keep
                    // the old 3-value ENUM with the dead values unused — harmless.
                    'status'          => "ENUM('generated') NOT NULL DEFAULT 'generated'",
```

- [ ] **Step 2: Lint**

Run: `php -l includes/class-schema.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add includes/class-schema.php
git commit -m "chore(schema): drop doc3_path/doc3_page_count/merged_path from slip_batches

Canonical-only removal (additive-only sync leaves existing installs' dead
columns in place, harmless). Narrow status ENUM to the single written value."
```

---

## Task 7: JS — delete upload + combine handlers

**Files:**
- Modify: `assets/js/slip-batch.js`

- [ ] **Step 1: Delete the upload + combine handler blocks**

Remove three event handlers in full:
- The `'.mealsdb-slip-upload-btn'` click proxy (~71–74).
- The `'.mealsdb-slip-doc3-file'` change handler (~76–131) — the whole `$.ajax` upload block including its `.always()` uploading-clear.
- The `'.mealsdb-slip-combine-btn'` click handler (~133–168).

Keep the generate handler (~42–69) and the cancel handler (~170–195). After removal, the `$(document).ready` body contains only generate + cancel.

- [ ] **Step 2: Confirm no dangling references to removed i18n keys**

```bash
grep -n "uploading\|combining\|combine\|doc3\|upload" assets/js/slip-batch.js
```
Expected: no matches (the file header comment also mentions "upload the returned doc 3 scan, combine (merge)" — update that comment to describe only generate/download/cancel).

- [ ] **Step 3: Commit**

```bash
git add assets/js/slip-batch.js
git commit -m "refactor(slips): remove doc3 upload + combine handlers from slip-batch.js"
```

---

## Task 8: Admin row — Packing Slips | Doc 4 | Cancel

**Files:**
- Modify: `includes/admin/class-slip-batch-page.php`

- [ ] **Step 1: Replace the per-row action markup**

In `render_row()` replace the locals + actions cell (~163–205). Delete the `$has_doc3`/`$has_merged` locals (~163–164) and replace the entire `<td class="mealsdb-slip-actions">…</td>` block (~179–206) with:

```php
        echo '<td class="mealsdb-slip-actions">';

        // Combined cover + packer slips, then the driver sheets (manual overlay).
        echo '<a class="button" href="' . esc_url($dl('packing_slips')) . '">' . esc_html__('Packing Slips', 'meals-db') . '</a> ';
        echo '<a class="button" href="' . esc_url($dl('doc4')) . '">' . esc_html__('Doc 4 (driver)', 'meals-db') . '</a> ';

        // Cancel (confirm popup in JS).
        echo '<button type="button" class="button mealsdb-slip-cancel-btn">' . esc_html__('Cancel', 'meals-db') . '</button>';

        echo ' <span class="mealsdb-slip-row-msg" style="margin-left:6px;"></span>';
        echo '</td>';
        echo '</tr>';
```

This drops the Doc 1, Doc 2, Upload-Doc-3 span, Combine button, and Download-merged link. Also update the `render_row` doc comment (~151–155) to drop "upload / combine" from the actions description.

- [ ] **Step 2: Drop the dead i18n keys from the localize block**

In `enqueue_scripts()` delete the `'uploading'` and `'combining'` entries from the `i18n` array (~71–72), leaving `working`, `genericErr`, `pickZone`, `confirmCancel`.

- [ ] **Step 3: Fix the intro/help text**

In `render_generate_form()` (~99–101) change the description so it no longer implies a later scan-combine step:

```php
        echo '<p class="description">'
            . esc_html__('Generates and saves the packer slips (with cover) and the driver sheets for one zone and delivery date, for manual handling.', 'meals-db')
            . '</p>';
```

- [ ] **Step 4: Lint**

Run: `php -l includes/admin/class-slip-batch-page.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-slip-batch-page.php
git commit -m "feat(slips): row actions are Packing Slips | Doc 4 | Cancel

Drop Doc1/Doc2/Upload-Doc3/Combine/Download-merged from the batch row; remove
uploading/combining i18n; rewrite the intro text."
```

---

## Task 9: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Lint every changed PHP file**

```bash
for f in includes/services/class-slip-pdf-generator.php includes/ajax/class-ajax-slip-batch.php includes/services/class-slip-batch.php includes/class-schema.php includes/admin/class-slip-batch-page.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 2: Grep for any surviving merge references**

```bash
grep -rn "MealsDB_Slip_Merge\|download_merged\|upload_doc3\|store_doc3\|store_merged\|doc3_path\|merged_path\|has_doc3\|has_merged\|\bcombine\b" includes/ assets/ tests/
```
Expected: NO matches. (Comments and dead method names should all be gone.)

- [ ] **Step 3: Run the full test suite**

```bash
for t in tests/test-*.php; do php "$t" >/tmp/t.out 2>&1 && echo "PASS $t" || { echo "FAIL $t"; cat /tmp/t.out; }; done
```
Expected: every test PASS, EXCEPT the two known-baseline PDF failures from missing local `mbstring`/`imagick` (see memory `local-cli-no-mbstring-imagick.md`). The two deleted `test-slip-merge-*.php` files must be ABSENT (not failing). Confirm `tests/test-slip-merge-*.php` no longer exists:
```bash
ls tests/test-slip-merge-* 2>&1   # expected: No such file or directory
```

- [ ] **Step 4: Manual browser verification (live host — PDF rendering needs mbstring/imagick unavailable locally)**

On the live/staging WordPress (per memory, dompdf paths are live-only):
- Open **Meals DB → Packing Slips**. Each batch row shows exactly **Packing Slips | Doc 4 (driver) | Cancel** — no Upload, no Combine, no merged link.
- Click **Packing Slips** → one PDF: page 1 is the cover (Zone / date / TAKE FROM HOLD / legend / "N Orders" / "Page 1 of Y"); pages 2..Y are the packer slips in order with continuous "Page X of Y"; each slip's right region is blank with the divider. 📷
- Click **Doc 4 (driver)** → driver blocks, one per order, paired to the same batch.
- Generate a new batch and Cancel one → row disappears; confirm an audit row was written (Event Log → audit tab: `slip_batch_cancelled`).
- Browser console: no JS errors interacting with the page.

- [ ] **Step 5: Final confirmation commit (if any doc/cleanup remains)**

If the working tree is clean (all changes already committed per-task), nothing to do. Otherwise:
```bash
git add -A && git commit -m "chore(slips): finalize merge-removal cleanup"
```

---

## Self-Review Notes (author)

- **Spec coverage:** Directive PART A (A1 merge AJAX → Task 3; A2 merge service+tests → Task 4; A3 batch service → Task 5; A4 schema → Task 6; A5 JS → Task 7) and PART B (B1 combined generator → Task 1; B2 AJAX → Task 2; B3 admin row → Task 8) are each mapped. The "What must NOT change" set (Doc 4 payload + pairing, Doc 4 download, Cancel + audit, history list, `manage_options` guard, doc-2 layout/divider) is preserved — no task touches `generate_doc4_driver_blocks`, `download_doc4`, `render_doc2_page`'s markup, the audit calls, or the nonce/cap guards.
- **Directive gaps closed:** `test-ajax-slip-batch.php` (Task 3) and `test-slip-batch.php` (Task 5) — not named in the directive but broken by it. Page-count divergence (Task 1, live count). `download_url` underscore stripping (Task 2).
- **Type/name consistency:** new public method `generate_packing_slips_combined(string,string,array): string`; private `render_packing_slips_combined_html(string,string,array,array): string`; private `doc1_body_html(string,string,array,bool): string`; AJAX `download_packing_slips()`; `which` value `'packing_slips'` → action `mealsdb_slip_download_packing_slips`; filename kind `'packing-slips'`. These match across Tasks 1, 2, and 8.
