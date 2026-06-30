# Directive — Two minor GUI fixes flagged by v475 browser testing

Scope: two small, low-risk fixes surfaced during GUI testing. Neither is a logic/data bug; both are
UX/messaging clarity. Do NOT change the merge math, the upload validation rule, or the rasterization
pipeline behavior — only the items below.

OUT OF SCOPE (do not "fix"): the local test box's missing PDF rasterizer. The merge failed in testing
because that environment has no working Imagick/Ghostscript (every PDF rasterized to 0 pages). That is an
ENVIRONMENT configuration issue, not a plugin defect — the plugin failed safely and logged it. Do not
alter working merge code to compensate. (Live host has Imagick; verify its ImageMagick policy.xml allows
PDF, separately, as ops — not part of this directive.)

---

## FIX 1 — "Uploading…" status sometimes does not clear after a successful Doc 3 upload
**Symptom (from testing):** after a Doc 3 upload that actually succeeds, the inline "Uploading…" row
message occasionally stays on screen; state is correct on reload. A stuck spinner, not a failed upload.

**File:** `assets/js/slip-batch.js`, the Upload Doc 3 handler (~lines 89–120).

**Cause:** the row message is set to "Uploading…" before the AJAX call (~line 89). It is cleared with
`rowMsg($row, '')` ONLY inside the `success && data.valid` branch (~line 99). If the response is a
success-but-not-valid shape, an unexpected payload, or any path that doesn't hit that exact branch, the
"Uploading…" text is never cleared. The `.always()` block only resets the file input — it does not clear
the row message.

**Fix:** clear the transient "Uploading…" message in `.always()` so it is ALWAYS removed once the request
settles, then let each branch set its own final message (error text, or empty on success). Concretely:
- In `.always(function(){ ... })`, before/after the existing `input.value = '';`, add a guard that clears
  the row message IF it still reads the "Uploading…" string — so a real error message set by the `else`
  or `.fail()` branch is not wiped, but a stuck "Uploading…" always is.
- Simplest robust approach: have the success/`else`/`fail` branches each set their final `rowMsg` as they
  already do, and in `.always()` do: if the current row message equals the "Uploading…" text, clear it.
  ```js
  }).always(function () {
      // Clear the transient "Uploading…" indicator if no branch replaced it.
      var $m = $row.find('.mealsdb-slip-row-msg');
      var uploading = (i18n.uploading || 'Uploading…');
      if ($m.text() === uploading) { $m.text(''); }
      input.value = '';
  });
  ```
This guarantees the spinner text never persists past request completion, without clobbering a legitimate
error message.

**Verify:** upload a valid Doc 3 → "Uploading…" disappears, Combine enables, no stuck text. Upload an
invalid one → the error message shows (not "Uploading…"). Force a server error → error message shows.

---

## FIX 2 — Distinguish "rasterizer unavailable / no pages" from a genuine page-count mismatch
**Symptom (from testing):** when the rasterizer produces nothing, `combine()` reports
"Doc 3 rasterized to 0 page(s); batch has 19 order(s)" under the event `combine.page_count_mismatch`.
That message blames a page-count mismatch when the real cause is "the rasterizer produced no pages at
all" (e.g. Imagick/Ghostscript unavailable or PDF policy blocked). Misleading for whoever reads the log
or the on-screen error.

**File:** `includes/services/class-slip-merge.php`, `combine()` (~lines 94–110), with `rasterize_doc3()`
(~line 138) already logging `rasterize.no_imagick` separately.

**Fix:** in `combine()`, after calling `rasterize_doc3()`, branch on the THREE distinct cases instead of
treating all non-matches as a page-count mismatch:
1. **Zero pages produced** (`count($bg_paths) === 0`): the rasterizer failed/produced nothing. Log + return
   an honest reason, e.g. event `combine.rasterize_failed`, message: "Could not rasterize Doc 3 — the
   server's PDF image tool may be unavailable. (No pages were produced.)" This is the case the test hit.
2. **Page count > 0 but ≠ order count:** the genuine mismatch — keep the existing
   `combine.page_count_mismatch` message.
3. **Match:** proceed to `render_overlay_pdf()` as now.

```php
$bg_paths = self::rasterize_doc3($doc3_path);
$got = count($bg_paths);
$want = count($orders);
if ($got === 0) {
    if (class_exists('MealsDB_Event_Log')) {
        MealsDB_Event_Log::record([
            'severity'  => 'error',
            'category'  => 'slip_batch',
            'subsystem' => 'slip_merge',
            'event'     => 'combine.rasterize_failed',
            'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
            'message'   => 'Could not rasterize Doc 3 (no pages produced) — the server PDF image tool may be unavailable.',
        ]);
    }
    return '';
}
if ($got !== $want) {
    // ... existing combine.page_count_mismatch log, unchanged ...
    return '';
}
return self::render_overlay_pdf($bg_paths, $orders);
```

**Surface it to the user:** wherever the AJAX combine handler turns a failed merge into the on-screen
message ("The merge failed (see Event Log)."), make the user-facing message reflect the distinct reason
when available — e.g. "The merge failed: Doc 3 could not be processed on the server (PDF image tool
unavailable). You can still print Doc 4 and combine manually." Check
`includes/ajax/class-ajax-slip-batch.php` combine handler and pass through the specific reason if the
merge service can return it (consider having `combine()` return a small result object or set a
last-error the handler can read, rather than a bare ''). Keep it graceful: the standalone Doc 4 download
is the documented fallback — the message should point the user to it.

**Verify:**
- With a working rasterizer: matching page count merges; wrong page count still says page-count mismatch.
- With the rasterizer unavailable (or simulate by forcing rasterize_doc3 to return []): the log shows
  `combine.rasterize_failed` (NOT page_count_mismatch), and the on-screen message names the real cause
  and points to the manual Doc 4 fallback.

---

## Global checks
```
php -l includes/services/class-slip-merge.php
php -l includes/ajax/class-ajax-slip-batch.php
# (JS) load the Packing Slips page; exercise upload success/invalid/error paths.
php tests/test-*.php
```
Do not change: upload page-count validation logic, the merge compositing/overlay math, rasterization DPI
or orientation handling. These two fixes are messaging/state-clearing only.
