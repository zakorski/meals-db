# Directive GUI-NOTICES — Replace informational `alert()` popups with on-page notices

**Status:** ready to implement. Bounded, low-risk UX improvement.
**Scope:** the **20 informational** `alert()` / `window.alert()` calls (errors + successes). The
**7 `confirm()`** dialogs are explicitly OUT of scope and left as native dialogs (see "Out of
scope" — this is a deliberate decision, not an omission).
**Severity:** UX / polish. Validation and error LOGIC already work; this only changes how messages
are presented. Also resolves the test-agent "can't read the popup" problem for informational
messages. Not launch-blocking; worth doing before daily use.
**Verified at:** v1.0.422.

---

## WHY (the finding)

Much of the admin JS announces errors and successes via native `window.alert()` — disruptive, not
tied to the field, inaccessible to screen readers, vanishes on dismiss, and blocks the page. The
client form already uses clean INLINE notices; the app is inconsistent. A reusable on-page notice
renderer (`showNotice`) already exists in `admin.js` — but as a page-local closure bound to one
specific `$status` element, so it can't simply be called from other files. This directive
generalizes it into a shared helper and routes the 20 informational alerts through it.

---

## THE 20 INFORMATIONAL ALERT SITES (exact, verified)

```
admin-migration.js:22, 26, 126     (3) — "Error: " + msg / msg
admin.js:64, 67, 237, 240,
        272, 275, 304, 328, 331,
        416                          (10) — errors + "Synced: "+field success + "Invalid link request."
client-actions.js:104, 125, 129     (3) — delete/error messages
client-initials.js:393              (1) — "Please validate initials before saving."
invoice-draft.js:128, 132, 141      (3) — draft edit/finalize errors (incl. the
                                          "Value must be a non-negative number" the test agent
                                          couldn't read, surfaced via resp.data.message)
```
Total: **20.** (The 7 `confirm()` sites — admin-migration.js:79,223; admin.js:311;
invoice-draft.js:115; settings.js:169,247,285 — are NOT touched.)

---

## PRE-FLIGHT VERIFICATION

```bash
cd <plugin-root>
# Confirm the 20 informational alerts and the 7 confirms are still where this directive says.
grep -rn "alert(\|window.alert" assets/js/*.js | grep -v "confirm(" | grep -v "//" | wc -l   # expect 20
grep -rn "confirm(" assets/js/*.js | grep -v "//" | wc -l                                     # expect 7 (leave these)
# The existing (page-local) showNotice to generalize.
grep -n "const showNotice\|notice-error\|\$status" assets/js/admin.js | head
# How scripts are enqueued (to add a shared util as a dependency).
grep -rn "wp_enqueue_script\|wp_register_script" includes/ --include=*.php | grep -i "admin\|invoice-draft\|client\|settings\|migration" | head
```

---

## STEP 1 — Generalize `showNotice` into a SHARED helper

The current `showNotice` (admin.js ~513) is a closure that writes to a page-specific `$status`
element. Other pages (client list, invoice drafts) have no `$status`, so it can't be reused as-is.
Create a standalone helper that finds-or-creates its own target.

New file `assets/js/meals-notice.js`:
```javascript
(function (w, $) {
    // Renders a dismissible on-page notice. Finds a container in priority order,
    // else injects one at the top of the plugin page wrap.
    function mealsNotice(level, message, opts) {
        opts = opts || {};
        var cls = { success:'notice-success', error:'notice-error',
                    warning:'notice-warning', info:'notice-info' }[level] || 'notice-info';
        // Preferred explicit target, else a known status region, else the WP page heading.
        var $target = opts.$target
            || $('#mealsdb-notice-region').first()
            || $('.mealsdb-status').first();
        var $wrap = ($target && $target.length) ? $target : $('.wrap').first();
        // Build the notice (WP admin notice styling) with an aria-live region for accessibility.
        var $n = $('<div>', { 'class': 'notice ' + cls + ' is-dismissible mealsdb-notice',
                              'role': 'status', 'aria-live': 'polite' })
                   .append($('<p>').text(message));
        if (opts.$target && opts.$target.length) { opts.$target.empty().append($n).show(); }
        else { $wrap.prepend($n); }
        // Auto-dismiss successes after a few seconds; keep errors until dismissed.
        if (level === 'success' || level === 'info') {
            w.setTimeout(function () { $n.fadeOut(300, function () { $(this).remove(); }); }, 4000);
        }
        // Clicking anywhere on an error notice (or its dismiss button) removes it.
        $n.on('click', function () { $(this).remove(); });
        return $n;
    }
    w.MealsDBNotice = mealsNotice;            // global accessor for all plugin scripts
})(window, jQuery);
```
Enqueue `meals-notice.js` and make every plugin admin script that uses notices DEPEND on it
(`wp_enqueue_script(..., ['jquery','meals-db-notice'], ...)`), so `window.MealsDBNotice` is always
available. Refactor admin.js's internal `showNotice` to delegate to `MealsDBNotice` (keep its
existing `$status` as the `opts.$target` so the migration page looks unchanged).

**Accessibility note:** the `role="status"` + `aria-live="polite"` is the reason inline notices beat
`alert()` for screen readers — keep it.

---

## STEP 2 — Replace the 20 informational alerts

Mechanical, one per site. Map by intent:
- error message → `MealsDBNotice('error', <msg>)`
- success message (e.g. `'Synced: ' + field`) → `MealsDBNotice('success', <msg>)`
- the validation message in invoice-draft.js:128 (`resp.data.message`) → `MealsDBNotice('error', (resp && resp.data && resp.data.message) || i18n.genericErr)` — and ideally render it NEAR the edited cell if a target is available (pass `opts.$target` = the cell's row), so the "non-negative number" error appears at the field, not just top-of-page.
- For files with an `errorCb` pattern (admin-migration.js:22/26/126), route the `else` branch through `MealsDBNotice('error', ...)` instead of `alert`.

Preserve message TEXT exactly (don't reword user-facing strings here; that's a separate i18n
concern). The only change is the delivery mechanism.

**invoice-draft.js specifically** (the dev may already be in this file for F3/F5 and the cell-edit
flow): the 3 alerts here are the highest-value to convert because the draft grid is where Janet
does per-field edits and hits validation most. Rendering the error at the cell/row is a real UX win.

---

## STEP 3 — Minimal styling
Reuse WP's built-in `.notice`/`.notice-error`/etc. classes (already styled by core admin CSS), so
little/no new CSS is needed. If a plugin notice region (`#mealsdb-notice-region`) is added to page
templates for consistent placement, style it minimally. Don't build a custom toast framework — WP
admin notices are the native, expected pattern.

---

## TESTS / VERIFICATION

JS UI behavior isn't covered by the PHPUnit suite, so verification is primarily the **GUI re-test**:
- After this ships, the Phase 1-R agent should find that validation errors (e.g. the draft
  "non-negative number" case) now appear as READABLE on-page text, not an unreadable native alert.
  Update expectation: those become directly readable rather than behavior-inferred.
- Manual smoke: trigger one error and one success in each touched file (a failed save, a successful
  sync) and confirm an on-page notice appears (and successes auto-dismiss, errors persist).
- Confirm the 7 `confirm()` dialogs STILL appear as native confirms (unchanged) — a regression check
  that scope wasn't overrun.
- Full PHPUnit suite still green (no PHP touched, but run it to be safe).

---

## ACCEPTANCE CRITERIA

1. A shared `MealsDBNotice(level, message, opts)` helper exists, enqueued as a dependency of the
   plugin admin scripts; admin.js's `showNotice` delegates to it.
2. All 20 informational `alert()`/`window.alert()` sites render on-page notices instead; message
   text unchanged; errors persist, successes auto-dismiss.
3. The invoice-draft validation messages render at/near the edited field where practical.
4. The 7 `confirm()` dialogs are UNCHANGED (still native).
5. Notices use `role="status"`/`aria-live` for accessibility.
6. GUI re-test: previously-unreadable informational popups are now readable on-page; full PHP suite green.

---

## OUT OF SCOPE (deliberate)

- **The 7 `confirm()` dialogs stay native.** Decision: four guard destructive operations (real
  migration write, bulk deactivate, reset migration, refill columns) where a hard blocking
  "are you sure?" is APPROPRIATE friction; converting them means restructuring synchronous
  confirm-gated handlers into async callback flows — higher risk for marginal benefit. The test-agent
  readability concern for these is already handled by the re-test's behavior-based pass criteria
  (the agent confirms the action's EFFECT, not the dialog text). Revisit only if a later UX pass
  wants in-page confirm modals broadly.
- **Rewording any user-facing message** — mechanism only; text preserved.
- **A custom toast/notification framework** — use native WP admin notices.
