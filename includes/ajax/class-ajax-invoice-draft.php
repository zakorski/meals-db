<?php
/**
 * AJAX handlers for the invoice draft review/edit UI (directive INV-DRAFT-2).
 *
 * Three endpoints — generate, per-field edit, finalize — each carrying the
 * full plugin guard stack, in this order:
 *   1. nonce        (check_ajax_referer, fail-closed)
 *   2. capability   (manage_options — tighter than the baseline plugin cap,
 *                    matching the Event Log page, because the review grid
 *                    exposes decrypted client PII; do NOT loosen it)
 *   3. rate limit   (a write bucket; reads are not a thing here)
 *   4. validation   (server-side, per-field semantics — this is government
 *                    billing data; never trust the grid)
 *   5. act + JSON   (success/error; never a bare 500 — outer catch(\Throwable))
 *
 * The audit story lives in edit_draft_field: a committed change to billing
 * data → the AUDIT log (meals_audit_log), per the STR-LOG boundary, NOT the
 * operational trunk — one row per actually-changed field (old→new, user,
 * time). No-ops, refusals, and validation failures write NO audit row.
 *
 * Serialization of a finalized draft (the real CSV/PDF + download) is
 * INV-DRAFT-3; this directive only locks + audits. See finalize_draft.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Ajax_Invoice_Draft {

    /** One nonce action covers all three draft endpoints (each still verifies it). */
    public const NONCE_ACTION = 'mealsdb_invoice_draft_nonce';

    /**
     * Separate nonce action for the finalized-invoice DOWNLOAD (INV-DRAFT-3
     * Step 3). It is its own context because the download is an admin-post
     * file stream (a GET link the page renders), not one of the three AJAX
     * mutations above — a distinct action category, so a distinct nonce.
     */
    public const DOWNLOAD_NONCE_ACTION = 'mealsdb_download_finalized_invoice';

    /**
     * Ceilings for server-side value validation. A single editable billing
     * value should never plausibly exceed these — they catch fat-fingers
     * (the directive's "999999") before they reach a government invoice.
     */
    private const MAX_DOLLARS = 100000;   // $100k on any single money cell
    private const MAX_COUNT    = 100000;  // meals/sides count on any single cell
    private const MAX_TEXT_LEN = 255;     // identity free-text cell length cap

    public static function init(): void {
        add_action('wp_ajax_mealsdb_generate_draft',   [__CLASS__, 'generate_draft']);
        add_action('wp_ajax_mealsdb_edit_draft_field', [__CLASS__, 'edit_draft_field']);
        add_action('wp_ajax_mealsdb_finalize_draft',   [__CLASS__, 'finalize_draft']);
        add_action('wp_ajax_mealsdb_unfinalize_draft', [__CLASS__, 'unfinalize_draft']);
        // The download is an admin-post handler (it streams a file, not JSON).
        add_action('admin_post_mealsdb_download_finalized_invoice', [__CLASS__, 'download_finalized']);
    }

    // -----------------------------------------------------------------
    // 2a — generate_draft
    // -----------------------------------------------------------------

    /**
     * Build a fresh draft from a pipeline + period. ALWAYS creates a NEW draft
     * (operator decision #4) — never mutates an existing one — so the list
     * keeps the full history. An empty result (no eligible clients yet) is a
     * valid zero-row draft, not an error.
     */
    public static function generate_draft(): void {
        if (!self::guard('client_modify')) {
            return; // guard() already emitted the JSON error.
        }

        try {
            $pipeline = sanitize_text_field(wp_unslash($_POST['pipeline'] ?? ''));
            $start    = sanitize_text_field(wp_unslash($_POST['period_start'] ?? ''));
            $end      = sanitize_text_field(wp_unslash($_POST['period_end'] ?? ''));
            $zone     = sanitize_text_field(wp_unslash($_POST['zone'] ?? ''));

            if (!in_array($pipeline, self::pipelines(), true)) {
                wp_send_json_error(['message' => __('Unknown pipeline.', 'meals-db')]);
                return;
            }
            if (!self::valid_date($start) || !self::valid_date($end)) {
                wp_send_json_error(['message' => __('Invalid date format. Use YYYY-MM-DD.', 'meals-db')]);
                return;
            }
            if (strtotime($start) > strtotime($end)) {
                wp_send_json_error(['message' => __('Start date must be on or before end date.', 'meals-db')]);
                return;
            }

            $params = [];
            switch ($pipeline) {
                case MealsDB_Invoice_Draft::PIPELINE_VAC:
                    $rows = MealsDB_Invoice_Generator::build_vac_draft_rows($start, $end);
                    break;

                case MealsDB_Invoice_Draft::PIPELINE_SDNB_LEGACY:
                    $zone_canonical = strtoupper(trim($zone));
                    if ($zone_canonical === '' || !in_array($zone_canonical, self::allowed_zones(), true)) {
                        wp_send_json_error(['message' => __('Unknown or missing SDNB zone.', 'meals-db')]);
                        return;
                    }
                    $params = ['zone' => $zone_canonical];
                    $rows   = MealsDB_Invoice_Generator::build_sdnb_legacy_draft_rows($zone_canonical, $start, $end);
                    break;

                case MealsDB_Invoice_Draft::PIPELINE_SDNB_NEW:
                    $rows = MealsDB_Invoice_Generator::build_sdnb_new_portal_draft_rows($start, $end);
                    break;

                default:
                    // Unreachable (guarded above), but keep the switch total.
                    wp_send_json_error(['message' => __('Unknown pipeline.', 'meals-db')]);
                    return;
            }

            $billing_month = substr($start, 0, 7);
            // create() already audits "invoice_draft_created" — do NOT double-log here.
            $draft_id = MealsDB_Invoice_Draft::create(
                $pipeline, $billing_month, $start, $end, is_array($rows) ? $rows : [], $params
            );

            if ($draft_id <= 0) {
                wp_send_json_error(['message' => __('Could not create draft (see Event Log).', 'meals-db')]);
                return;
            }

            wp_send_json_success([
                'draft_id'  => $draft_id,
                'row_count' => is_array($rows) ? count($rows) : 0,
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Invoice_Draft AJAX] generate failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to generate draft. Please contact an administrator.', 'meals-db')]);
        }
    }

    // -----------------------------------------------------------------
    // 2b — edit_draft_field (the audit-critical endpoint)
    // -----------------------------------------------------------------

    /**
     * Apply + audit a single field edit on a draft. Validation is by field
     * semantics; the value is going onto a government invoice. One audit row
     * per ACTUAL change (old→new), and none on a no-op / refusal / validation
     * failure.
     */
    public static function edit_draft_field(): void {
        if (!self::guard('invoice_draft_edit')) {
            return;
        }

        try {
            $draft_id  = isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0;
            // client_id is the assoc-array KEY in the payload, which JSON
            // round-trips as a numeric string — keep it a string, but it must
            // look like a positive integer id.
            $client_id = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));
            $field     = sanitize_text_field(wp_unslash($_POST['field'] ?? ''));
            // Raw, UNSANITIZED here on purpose: validate_value() decides the
            // per-field sanitization (numeric normalize vs sanitize_text_field).
            $raw_value = wp_unslash($_POST['new_value'] ?? '');

            if ($draft_id <= 0 || $client_id === '' || !ctype_digit($client_id) || $field === '') {
                wp_send_json_error(['message' => __('Missing or malformed edit parameters.', 'meals-db')]);
                return;
            }

            // Load the draft to (a) refuse a finalized one early, and (b) read
            // the field's GENERATED baseline so we normalize the new value to
            // the field's existing representation (cents-int vs dollars-float).
            $draft = MealsDB_Invoice_Draft::get($draft_id);
            if ($draft === null) {
                wp_send_json_error(['message' => __('Draft not found.', 'meals-db')]);
                return;
            }
            if (($draft['status'] ?? '') !== 'draft') {
                wp_send_json_error(['message' => __('Draft already finalized — reload.', 'meals-db')]);
                return;
            }

            $current = (isset($draft['payload']['current']) && is_array($draft['payload']['current']))
                ? $draft['payload']['current'] : [];
            if (!isset($current[$client_id]) || !is_array($current[$client_id])
                || !array_key_exists($field, $current[$client_id])) {
                // Don't let the UI invent a column the row doesn't have.
                wp_send_json_error(['message' => __('Unknown field for this draft.', 'meals-db')]);
                return;
            }

            // Some *_cents fields are stored in cents but EDITED as dollars on
            // the grid (SDNB contribution — directive SDNB scope D3; the input
            // carries data-edit-as="dollars" and the JS posts edit_as=dollars).
            // Convert dollars→cents BEFORE validation so the int-baseline money
            // path stores the right magnitude. to_cents rounds half-up.
            $edit_as           = sanitize_text_field(wp_unslash($_POST['edit_as'] ?? ''));
            $edited_as_dollars = ($edit_as === 'dollars' && substr($field, -6) === '_cents');
            if ($edited_as_dollars && is_numeric($raw_value)) {
                $raw_value = (string) MealsDB_Money::to_cents($raw_value);
            }

            // Baseline guides numeric representation; fall back to the current
            // value's type if no generated baseline exists for the field.
            $baseline = $draft['payload']['generated'][$client_id][$field]
                ?? $current[$client_id][$field];

            $validated = self::validate_value($field, $raw_value, $baseline);
            if ($validated instanceof WP_Error) {
                wp_send_json_error(['message' => $validated->get_error_message()]);
                return;
            }

            // Apply via the service. It re-checks status server-side (defense in
            // depth) and returns the OLD value on success, false on refusal.
            $old = MealsDB_Invoice_Draft::edit_field($draft_id, $client_id, $field, $validated);
            if ($old === false) {
                wp_send_json_error(['message' => __('Could not apply edit (draft may have been finalized — reload).', 'meals-db')]);
                return;
            }

            // Audit ONLY an actual change (decision #3 + T-4): logging no-ops
            // would bury the real edits.
            //
            // Redaction scope (verified, not assumed — directive PII note): the
            // composite "<cid>:<field>" name is what MealsDB_Logger::redact_value
            // now unpacks (INV-DRAFT-2 logger change) so an edit to a field IN
            // its SENSITIVE_FIELDS list — the encrypted PII (individual_id,
            // vet_health_card, requisition_id), addresses, emails, phones — is
            // fingerprinted rather than stored as cleartext. It does NOT cover
            // first_name/last_name: those are not encrypted at rest and are
            // deliberately absent from SENSITIVE_FIELDS, so a name edit is
            // logged in cleartext here — exactly as the existing sync_override
            // audit path (class-sync-mutate.php) already records name changes.
            // The audit log is itself manage_options-gated, so this is the
            // plugin's standing decision, not a new exposure. If names should
            // ever be fingerprinted, add them to SENSITIVE_FIELDS (one place,
            // plugin-wide) rather than scrubbing here.
            if ($old !== $validated) {
                MealsDB_Logger::log(
                    'invoice_draft_edit',
                    $draft_id,
                    $client_id . ':' . $field,
                    is_scalar($old) ? (string) $old : wp_json_encode($old),
                    is_scalar($validated) ? (string) $validated : wp_json_encode($validated)
                );
            }

            // Live recompute (INVOICE-DRAFT-SPREADSHEET 3b): derive the
            // pipeline's read-only money cells from the UPDATED row and return
            // them so the grid refreshes in place — the SINGLE source of truth
            // is the per-pipeline compute fn (here via the page's
            // derived_display), NEVER recomputed in JS and NEVER persisted into
            // `current` (finalize re-derives from the same fn). Apply the saved
            // value to the in-memory row rather than re-decrypting the draft.
            $updated_row          = $current[$client_id];
            $updated_row[$field]  = $validated;
            $derived = (class_exists('MealsDB_Invoice_Draft_Page'))
                ? MealsDB_Invoice_Draft_Page::derived_display((string) ($draft['pipeline'] ?? ''), $updated_row)
                : [];

            // value_display is the string the grid puts back in the input box.
            // For a dollars-edited cents field it's the dollar form (so the cell
            // doesn't flip to a raw cents integer); otherwise it's the stored
            // value verbatim.
            $value_display = is_scalar($validated) ? (string) $validated : '';
            if ($edited_as_dollars && is_int($validated)) {
                $value_display = number_format($validated / 100, 2, '.', '');
            } elseif (is_float($validated)) {
                // Dollars-stored money fields (VAC bill_rate/fold_amount/
                // fold_hst) hold a rounded float; (string) 9.0 => '9', which
                // flips the grid cell out of the 2dp form it first rendered in
                // (number_format(...,2)). Keep 2dp so the input stays '9.00'
                // after a save. Cosmetic only — the stored value is unchanged.
                $value_display = number_format((float) $validated, 2, '.', '');
            }

            wp_send_json_success([
                'field'         => $field,
                'value'         => $validated,
                'value_display' => $value_display,
                'changed'       => ($old !== $validated),
                'derived'       => $derived, // VAC: [field=>str]; SDNB: ['lines'=>[…]]; [] when none
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Invoice_Draft AJAX] edit failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to save edit. Please try again.', 'meals-db')]);
        }
    }

    // -----------------------------------------------------------------
    // 2c — finalize_draft
    // -----------------------------------------------------------------

    /**
     * Lock + audit a draft AND serialize its output (INV-DRAFT-3). finalize()
     * now freezes the months, serializes `current` per pipeline, and persists
     * the exact bytes (encrypted) on the draft. The JS reloads the page into
     * the read-only grid, where the Download links (Step 3) appear. We return
     * the set of available formats so the caller can surface them immediately.
     */
    /**
     * Un-finalize a finalized draft (directive INV-2). Reverses the one-way
     * finalize lock: restores the draft to editable `draft` status and clears
     * the per-client-month finalized locks. manage_options + nonce + rate limit
     * (the shared guard spine) PLUS a required, audited reason string.
     *
     * SHARED-LOCK CONFIRMATION (PR #418 review): is_finalized is shared per
     * client-month, so more than one finalized invoice can depend on the same
     * lock. When others do, we do NOT silently clear it: on the first call
     * (cascade absent) we return requires_confirmation with a message naming the
     * shared client(s), month, and the other invoice(s); the operator confirms
     * and the JS re-posts with cascade=1, which un-finalizes them all together.
     */
    public static function unfinalize_draft(): void {
        if (!self::guard('settings_modify')) {
            return; // guard() already emitted the JSON error.
        }

        try {
            $draft_id = isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0;
            $reason   = isset($_POST['reason']) ? sanitize_text_field(wp_unslash((string) $_POST['reason'])) : '';
            $cascade  = !empty($_POST['cascade']);
            if ($draft_id <= 0) {
                wp_send_json_error(['message' => __('Missing draft id.', 'meals-db')]);
                return;
            }
            // The reason is REQUIRED — every un-finalize is audited with it.
            if (trim($reason) === '') {
                wp_send_json_error(['message' => __('A reason is required to un-finalize.', 'meals-db')]);
                return;
            }

            // Shared-lock gate: if other finalized invoices cover any of this
            // draft's clients for the same month, surface a confirmation before
            // clearing the shared lock. Continuing (cascade) un-finalizes them all.
            if (!$cascade) {
                $conflicts = MealsDB_Invoice_Draft::get_unfinalize_conflicts($draft_id);
                if (!empty($conflicts)) {
                    wp_send_json_success([
                        'requires_confirmation' => true,
                        'message'               => self::compose_unfinalize_conflict_message($conflicts),
                    ]);
                    return;
                }
            }

            $ok = MealsDB_Invoice_Draft::unfinalize($draft_id, $reason, $cascade);
            if (!$ok) {
                wp_send_json_error(['message' => __('Could not un-finalize (draft not found, not finalized, or changed — reload).', 'meals-db')]);
                return;
            }

            wp_send_json_success([
                'message' => __('Invoice un-finalized. It is editable again — you can edit it or regenerate.', 'meals-db'),
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Invoice_Draft AJAX] unfinalize failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to un-finalize draft. Please try again.', 'meals-db')]);
        }
    }

    /**
     * Build the operator-facing confirmation naming the shared client(s), the
     * month, and the other finalized invoice(s) that would be swept along.
     * (manage_options-only audience, like the rest of the draft UI — naming the
     * client is consistent with the grid's existing decrypted-PII exposure.)
     */
    private static function compose_unfinalize_conflict_message(array $conflicts): string {
        $invoices = [];
        $clients  = []; // client_id => label (dedup across siblings)
        foreach ($conflicts as $c) {
            $invoices[] = sprintf('#%d (%s, %s)', (int) $c['draft_id'], (string) $c['pipeline'], (string) $c['billing_month']);
            foreach ((array) ($c['shared_clients'] ?? []) as $cid => $name) {
                $cid = (int) $cid;
                $clients[$cid] = ($name !== '') ? sprintf('%s (#%d)', $name, $cid) : sprintf('#%d', $cid);
            }
        }
        $client_list = array_values($clients);
        $max         = 6;
        $extra       = count($client_list) > $max ? (count($client_list) - $max) : 0;
        if ($extra > 0) {
            $client_list = array_slice($client_list, 0, $max);
        }
        $client_str = implode(', ', $client_list) . ($extra > 0 ? sprintf(' +%d more', $extra) : '');

        return sprintf(
            /* translators: 1: other finalized invoice list, 2: shared client list */
            __('Another finalized invoice — %1$s — also covers shared client(s): %2$s. Continuing will UN-FINALIZE BOTH invoices so the month can be rebuilt. Continue?', 'meals-db'),
            implode(', ', $invoices),
            $client_str
        );
    }

    public static function finalize_draft(): void {
        if (!self::guard('settings_modify')) {
            return;
        }

        try {
            $draft_id = isset($_POST['draft_id']) ? absint($_POST['draft_id']) : 0;
            if ($draft_id <= 0) {
                wp_send_json_error(['message' => __('Missing draft id.', 'meals-db')]);
                return;
            }

            // finalize() returns the structured output map on success, or null
            // on refusal / lost-race / serialization failure.
            $result = MealsDB_Invoice_Draft::finalize($draft_id);
            if ($result === null) {
                wp_send_json_error(['message' => __('Could not finalize (already finalized, a concurrent change, or output could not be produced — reload).', 'meals-db')]);
                return;
            }

            $formats = (isset($result['files']) && is_array($result['files']))
                ? array_keys($result['files']) : [];

            wp_send_json_success([
                'finalized' => true,
                'draft_id'  => $draft_id,
                'formats'   => $formats,
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Invoice_Draft AJAX] finalize failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to finalize draft. Please try again.', 'meals-db')]);
        }
    }

    // -----------------------------------------------------------------
    // 3 — download_finalized (admin-post file stream, INV-DRAFT-3 Step 3)
    // -----------------------------------------------------------------

    /**
     * Stream a finalized draft's captured artifact (CSV, or VAC PDF). This is
     * an admin-post handler, NOT an AJAX one: it emits a file, not JSON.
     *
     * Guard order mirrors the AJAX spine but for a GET download:
     *   1. capability  (manage_options — same tight audience; the artifact is
     *                   decrypted client/veteran PII)
     *   2. nonce       (DOWNLOAD_NONCE_ACTION, in the link the page rendered)
     *   3. rate limit  (a READ bucket — this is a download of an already-
     *                   finalized, immutable artifact, not a mutation)
     *
     * The bytes are the EXACT ones captured at finalize time (Step 2) — never
     * regenerated here, so a finalized federal invoice is immutable. The CSV
     * was produced through MealsDB_CSV (QW-3 formula-injection guard) at
     * finalize; we do not re-touch it.
     */
    public static function download_finalized(): void {
        // 1. Capability.
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'meals-db'), 403);
        }
        // 2. Nonce (in the GET link).
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, self::DOWNLOAD_NONCE_ACTION)) {
            wp_die(esc_html__('Invalid or expired download link.', 'meals-db'), 403);
        }
        // 3. Rate limit (read bucket — fail-open for reads if backend down).
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit('quick_order_read')) {
            wp_die(esc_html__('Rate limit exceeded. Please try again later.', 'meals-db'), 429);
        }

        $draft_id = isset($_GET['draft_id']) ? absint($_GET['draft_id']) : 0;
        $which    = isset($_GET['which']) ? sanitize_key((string) $_GET['which']) : 'csv';
        if ($draft_id <= 0) {
            wp_die(esc_html__('Missing draft id.', 'meals-db'), 400);
        }
        if (!in_array($which, ['csv', 'pdf'], true)) {
            $which = 'csv';
        }

        try {
            // get_finalized_output() returns null unless the draft exists AND is
            // finalized — so this fails closed on a draft-status (T-A5) or
            // missing draft.
            $output = MealsDB_Invoice_Draft::get_finalized_output($draft_id);
            if ($output === null || empty($output['files']) || !is_array($output['files'])) {
                wp_die(esc_html__('No finalized output available for this draft.', 'meals-db'), 404);
            }

            $file = $output['files'][$which] ?? null;
            if (!is_array($file)) {
                wp_die(esc_html__('Requested format is not available for this invoice.', 'meals-db'), 404);
            }

            // Resolve the bytes: CSV is stored as a string; PDF as base64.
            if ($which === 'pdf') {
                $bytes = isset($file['b64']) ? base64_decode((string) $file['b64'], true) : false;
                if ($bytes === false) {
                    wp_die(esc_html__('The PDF could not be read.', 'meals-db'), 500);
                }
            } else {
                $bytes = (string) ($file['content'] ?? '');
            }

            $mime     = isset($file['mime']) ? (string) $file['mime'] : 'application/octet-stream';
            $filename = isset($file['filename']) ? (string) $file['filename'] : ('invoice-' . $draft_id . '.' . $which);
            // Defang the filename for the header (no path / CR-LF injection).
            $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

            nocache_headers();
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($bytes));
            echo $bytes; // raw artifact bytes — already CSV-safe (QW-3) / binary PDF.
            exit;
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Invoice_Draft AJAX] download failed: ' . $e->getMessage());
            wp_die(esc_html__('Unable to download the invoice. Please contact an administrator.', 'meals-db'), 500);
        }
    }

    // -----------------------------------------------------------------
    // Guards + validation helpers
    // -----------------------------------------------------------------

    /**
     * The shared guard spine (T-9): nonce → capability → rate limit, in that
     * order, each failing CLOSED with a JSON error. Returns true only if all
     * three pass — the caller proceeds to validate + act; on false it returns
     * immediately (this method already sent the error).
     */
    private static function guard(string $rate_bucket): bool {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'meals-db')]);
            return false;
        }
        // manage_options (not the baseline plugin cap): the draft grid exposes
        // DECRYPTED client PII — names, individual_id, vet_health_card,
        // addresses — so the audience is kept as tight as the Event Log page.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'meals-db')]);
            return false;
        }
        if (class_exists('MealsDB_Rate_Limiter')
            && !MealsDB_Rate_Limiter::check_rate_limit($rate_bucket)) {
            wp_send_json_error(['message' => __('Rate limit exceeded. Please try again later.', 'meals-db')], 429);
            return false;
        }
        return true;
    }

    /**
     * Validate + normalize an edited value against the field's semantics,
     * using the field's existing (generated baseline) type as the guide for
     * money representation (cents-int vs dollars-float). Returns the stored
     * value, or a WP_Error the caller turns into a JSON error.
     *
     * @param string $field    The (bare) field name.
     * @param mixed  $raw       The raw posted value.
     * @param mixed  $baseline  The generated baseline value (type guide).
     * @return mixed|WP_Error
     */
    private static function validate_value(string $field, $raw, $baseline) {
        $category = self::classify_field($field);

        if ($category === 'count') {
            // Non-negative integer (meals / sides counts).
            if (!is_scalar($raw) || !is_numeric($raw) || (float) $raw != floor((float) $raw)) {
                return new WP_Error('bad_count', __('Value must be a non-negative whole number.', 'meals-db'));
            }
            // Derive the int from the numeric VALUE, not a raw (int) cast:
            // (int) '1e3' stops at 'e' and yields 1, silently storing a wrong
            // count on a government invoice. round() maps scientific/edge forms
            // to their intended magnitude; plain integers are unaffected.
            $int = (int) round((float) $raw);
            if ($int < 0 || $int > self::MAX_COUNT) {
                return new WP_Error('bad_count', __('Value is out of the allowed range.', 'meals-db'));
            }
            return $int;
        }

        if ($category === 'money') {
            if (!is_scalar($raw) || !is_numeric($raw)) {
                return new WP_Error('bad_money', __('Value must be a number.', 'meals-db'));
            }
            $num = (float) $raw;
            if ($num < 0) {
                return new WP_Error('bad_money', __('Value must be a non-negative number.', 'meals-db'));
            }
            // The baseline type tells us the stored representation: an int
            // baseline is cents (store cents), a float baseline is dollars
            // (store dollars). The ceiling is applied on the dollar-equivalent
            // either way, so a fat-fingered 999999 is rejected regardless.
            $stored_as_cents = is_int($baseline);
            $dollars = $stored_as_cents ? ($num / 100) : $num;
            if ($dollars > self::MAX_DOLLARS) {
                return new WP_Error('bad_money', __('Value is implausibly large — check it.', 'meals-db'));
            }
            return $stored_as_cents ? (int) round($num) : round($num, 2);
        }

        // Free-text identity field. sanitize_text_field for storage; the
        // CSV/PDF serializer (INV-DRAFT-3) routes through MealsDB_CSV::cell()
        // (QW-3), so do NOT re-implement formula-injection guarding here.
        if (!is_scalar($raw)) {
            return new WP_Error('bad_text', __('Value must be text.', 'meals-db'));
        }
        $text = sanitize_text_field((string) $raw);
        if (strlen($text) > self::MAX_TEXT_LEN) {
            return new WP_Error('bad_text', __('Value is too long.', 'meals-db'));
        }
        return $text;
    }

    /**
     * Classify a field by name into count / money / text. Count patterns are
     * checked FIRST so "allocated_tax_sides" reads as a count, while
     * "tax_cents" falls through to money.
     */
    private static function classify_field(string $field): string {
        $f = strtolower($field);
        if (preg_match('/(mains|sides|count|weeks|quantity|_qty)/', $f)) {
            return 'count';
        }
        // 'hst' is listed explicitly so VAC's fold_hst (the "(includes HST)"
        // figure, INV-DRAFT-3) classifies as money — it has no 'tax' substring.
        if (preg_match('/(cents|rate|contribution|delivery_fee|fee|cost|basic|tax|hst|amount|price|total|subtotal|allowance|fold)/', $f)) {
            return 'money';
        }
        return 'text';
    }

    /** The three pipeline identifiers a draft can target. */
    private static function pipelines(): array {
        return [
            MealsDB_Invoice_Draft::PIPELINE_VAC,
            MealsDB_Invoice_Draft::PIPELINE_SDNB_LEGACY,
            MealsDB_Invoice_Draft::PIPELINE_SDNB_NEW,
        ];
    }

    /**
     * Allowed SDNB service-zone codes. This used to mirror the old
     * MealsDB_Ajax_Invoice::allowed_sdnb_zones (removed in INV-3 when the
     * direct-download invoice page was consolidated away); the same
     * 'mealsdb_allowed_sdnb_zones' filter is preserved so the zone set is
     * unchanged for callers that hooked it.
     */
    private static function allowed_zones(): array {
        $defaults = ['M', 'S'];
        if (!function_exists('apply_filters')) {
            return $defaults;
        }
        $zones = apply_filters('mealsdb_allowed_sdnb_zones', $defaults);
        if (!is_array($zones) || empty($zones)) {
            return $defaults;
        }
        $clean = [];
        foreach ($zones as $z) {
            if (is_string($z) && $z !== '') {
                $clean[] = strtoupper(trim($z));
            }
        }
        return !empty($clean) ? array_values(array_unique($clean)) : $defaults;
    }

    /** Strict Y-m-d validation (same shape as the sibling invoice endpoint). */
    private static function valid_date(string $date): bool {
        if ($date === '') {
            return false;
        }
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}
