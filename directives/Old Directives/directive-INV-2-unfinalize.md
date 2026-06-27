# Directive INV-2 (SURGICAL, v446) — Un-finalize an invoice (audited, manage_options)

## HOW TO EXECUTE — READ FIRST
- Multiple edits across 4 files (engine, draft service, AJAX, page+JS). `read` each file, find the
  EXACT verbatim FIND, apply. Do NOT regenerate methods. If a FIND doesn't match, STOP and report.
- This is BILLING-INTEGRITY code — un-finalize reverses the one-way finalize lock. Implement exactly
  as specified; do not add shortcuts. Every un-finalize MUST be audited with a reason.

**Why:** finalize is deliberately one-way (locks the draft + sets is_finalized=1 on each client-month
so the rebuilder won't touch a submitted invoice). Tonight showed a SETUP mistake (invoices finalized
at zero against an empty products table) was recoverable only by raw SQL, because no un-finalize
exists. This adds an audited, admin-only un-finalize.

**Behavior (operator-confirmed):** un-finalize (1a) requires a typed confirmation + a REASON string,
logged to the audit trail. (1b) After un-finalizing, the draft returns to editable `draft` status
(payload preserved) AND the client-month locks are cleared — so the user can EITHER edit the restored
draft OR regenerate fresh; both are available. (1c) manage_options only.

**Reverse of finalize (mirror exactly):** finalize did: serialize→encrypt output, set
is_finalized=1/finalized_at per client-month (engine->finalize_month), set draft
status=finalized + finalized_by/at + finalized_output, audit. Un-finalize must: clear
is_finalized=0/finalized_at=NULL per client-month (new engine->unfinalize_month), set draft
status=draft + clear finalized_by/at/output, audit WITH REASON.

---

## EDIT 1 — add unfinalize_month to the allocation engine (mirror of finalize_month)
**File:** `includes/services/class-allocation-engine.php`
**FIND (verbatim — the existing finalize_month, to insert the reverse AFTER it):**
```
    public function finalize_month(int $client_id, string $billing_month): bool {
        $allocations_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);

        $updated = $this->wpdb->update(
            $allocations_table,
            ['is_finalized' => 1, 'finalized_at' => current_time('mysql')],
            ['client_id' => $client_id, 'billing_month' => $billing_month],
            ['%d', '%s'],
            ['%d', '%s']
        );

        return $updated !== false && $updated > 0;
```
**ACTION:** find the closing `}` of that method and INSERT this new method immediately after it:
```php

    /**
     * Reverse of finalize_month: clears the finalized lock on a client-month so
     * the rebuilder can recompute it again. Used ONLY by the audited un-finalize
     * flow (MealsDB_Invoice_Draft::unfinalize). Returns true if the row was
     * updated (or was already not finalized).
     */
    public function unfinalize_month(int $client_id, string $billing_month): bool {
        $allocations_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);

        $updated = $this->wpdb->update(
            $allocations_table,
            ['is_finalized' => 0, 'finalized_at' => null],
            ['client_id' => $client_id, 'billing_month' => $billing_month],
            ['%d', '%s'],
            ['%d', '%s']
        );

        return $updated !== false;
    }
```

---

## EDIT 2 — add unfinalize() to the draft service
**File:** `includes/services/class-invoice-draft.php`
**FIND (verbatim — the start of finalize(), to insert unfinalize BEFORE it):**
```
    public static function finalize(int $draft_id) {
        try {
            $draft = self::get($draft_id);
```
**INSERT IMMEDIATELY BEFORE that `public static function finalize` line:**
```php
    /**
     * Reverse a finalize: restore the draft to editable `draft` status and clear
     * the per-client-month finalized locks, so it can be edited or regenerated.
     * Audited WITH a reason. manage_options is enforced at the AJAX boundary.
     *
     * @param int    $draft_id
     * @param string $reason  Operator-supplied reason (audited; required).
     * @return bool  true on success, false if not found / not finalized / failed.
     */
    public static function unfinalize(int $draft_id, string $reason): bool {
        try {
            $draft = self::get($draft_id);
            if ($draft === null) {
                return false;
            }
            if (($draft['status'] ?? '') !== 'finalized') {
                // Only a finalized draft can be un-finalized.
                return false;
            }

            $payload       = $draft['payload'];
            $current       = (isset($payload['current']) && is_array($payload['current']))
                ? $payload['current'] : [];
            $billing_month = (string) ($draft['billing_month'] ?? '');

            // 1) Clear the per-client-month finalized locks (reverse of the
            //    finalize_month loop). Mirror finalize's client set (payload current).
            if ($billing_month !== '' && class_exists('MealsDB_Allocation_Engine')) {
                $engine = new MealsDB_Allocation_Engine();
                foreach (array_keys($current) as $client_id) {
                    $cid = (int) $client_id;
                    if ($cid > 0) {
                        $engine->unfinalize_month($cid, $billing_month);
                    }
                }
            }

            // 2) Restore the draft row to editable `draft`, clearing the
            //    finalized metadata + the captured output. Guarded WHERE keeps
            //    the transition atomic (only a still-finalized row flips).
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS);
            $updated = $wpdb->update(
                $table,
                [
                    'status'           => 'draft',
                    'finalized_by'     => null,
                    'finalized_at'     => null,
                    'finalized_output' => null,
                ],
                ['draft_id' => $draft_id, 'status' => 'finalized'],
                ['%s', '%d', '%s', '%s'],
                ['%d', '%s']
            );
            if ($updated === false || (int) $updated === 0) {
                // Lost race / already changed — treat as refusal.
                return false;
            }

            // 3) Audit WITH the reason (committed artifact change → audit log).
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log(
                    'invoice_draft_unfinalized',
                    $draft_id,
                    'reason',
                    'finalized',
                    (string) $reason
                );
            }

            return true;
        } catch (\Throwable $e) {
            self::log_error('unfinalize', $e);
            return false;
        }
    }

```
**NOTE:** mirror the existing error-handling style in this class (`self::log_error(...)` is used by
edit_field/finalize — confirm the exact helper name when you read the file; if it's named
differently, use the same helper the sibling methods use).

---

## EDIT 3 — add the AJAX endpoint
**File:** `includes/ajax/class-ajax-invoice-draft.php`
**FIND (verbatim):**
```
        add_action('wp_ajax_mealsdb_finalize_draft',   [__CLASS__, 'finalize_draft']);
```
**REPLACE WITH:**
```
        add_action('wp_ajax_mealsdb_finalize_draft',   [__CLASS__, 'finalize_draft']);
        add_action('wp_ajax_mealsdb_unfinalize_draft', [__CLASS__, 'unfinalize_draft']);
```
**THEN** add the handler method. FIND (verbatim — the start of the finalize handler, to insert the
new handler BEFORE it):
```
    public static function finalize_draft(): void {
```
**INSERT IMMEDIATELY BEFORE it:**
```php
    /**
     * Un-finalize a finalized draft. manage_options + nonce + a required reason.
     */
    public static function unfinalize_draft(): void {
        check_ajax_referer('mealsdb_invoice_draft', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'meals-db')]);
        }
        $draft_id = isset($_POST['draft_id']) ? (int) $_POST['draft_id'] : 0;
        $reason   = isset($_POST['reason']) ? sanitize_text_field(wp_unslash((string) $_POST['reason'])) : '';
        if ($draft_id <= 0) {
            wp_send_json_error(['message' => __('Missing draft id.', 'meals-db')]);
        }
        if (trim($reason) === '') {
            wp_send_json_error(['message' => __('A reason is required to un-finalize.', 'meals-db')]);
        }
        $ok = MealsDB_Invoice_Draft::unfinalize($draft_id, $reason);
        if (!$ok) {
            wp_send_json_error(['message' => __('Could not un-finalize (draft not found, not finalized, or changed — reload).', 'meals-db')]);
        }
        wp_send_json_success([
            'message' => __('Invoice un-finalized. It is editable again — you can edit it or regenerate.', 'meals-db'),
        ]);
    }

```
**NOTE:** match the EXACT nonce action string this class uses for its other endpoints (read the
finalize_draft handler — it will call `check_ajax_referer('<action>', 'nonce')`; use the SAME action
string, not the placeholder `mealsdb_invoice_draft` if it differs).

---

## EDIT 4 — add the Un-finalize button + JS on the page (finalized review view)
**File:** `includes/admin/class-invoice-draft-page.php` and its JS (the script enqueued for this page;
find the handle in enqueue_scripts and the matching assets/js file).
- On the FINALIZED review view (where it currently shows "This draft is finalized and is shown
  read-only."), ADD an "Un-finalize" button (and/or a list-row "Un-finalize" action mirroring the
  existing "Finalize" link at the list view).
- The button handler (JS): prompt for a REASON (a required text prompt / small modal), confirm the
  action ("Un-finalize this invoice? It will become editable again."), then POST to
  `action=mealsdb_unfinalize_draft` with `draft_id`, `reason`, and the page nonce. On success, reload
  the view (the draft now renders editable). Mirror the existing finalize JS handler's structure
  (nonce var, ajaxurl, success reload) — read it and copy its shape.
- Pass a localized confirm/prompt string via wp_localize_script alongside the existing `confirmFin`
  (e.g. `confirmUnfin` = "Un-finalize this invoice? Provide a reason; it will become editable
  again.").

**Keep it minimal:** reason capture can be a `window.prompt()` for v1 (the AJAX enforces non-empty),
upgraded to a modal later. Do not build elaborate UI; the integrity logic is server-side.

---

## VERIFICATION
```bash
cd <plugin-root>
grep -n "unfinalize_month" includes/services/class-allocation-engine.php
grep -n "function unfinalize" includes/services/class-invoice-draft.php
grep -n "mealsdb_unfinalize_draft\|function unfinalize_draft" includes/ajax/class-ajax-invoice-draft.php
php tests/test-*.php   # green
```
**Manual (staging — use the test-finalized Nov/Dec data):**
- Open a FINALIZED draft → "Un-finalize" button present. Click → prompted for a reason → enter one →
  confirm. Success message says it's editable again.
- Verify the draft is now `draft` status (editable), and the client-months' is_finalized is back to 0
  (re-run a rebuild for that month → it now recomputes instead of being skipped).
- Verify the audit log has an `invoice_draft_unfinalized` entry with the reason.
- Try un-finalize with an EMPTY reason → rejected ("A reason is required").
- Confirm a non-admin (if testable) is rejected by the manage_options check.

## DO NOT
- Do not allow un-finalize without a reason (server enforces it; keep that).
- Do not delete the finalized_output without flipping status (the guarded UPDATE does both atomically).
- Do not auto-rebuild inside unfinalize() — clearing the lock is enough; the user rebuilds/regenerates
  or edits next. (Keeps unfinalize a pure state-reversal.)
- Do not weaken finalize's one-way design elsewhere — this adds a SEPARATE, audited, admin-gated
  reversal; finalize itself is unchanged.
