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
            // would bury the real edits. The composite "<cid>:<field>" name is
            // what MealsDB_Logger::redact_value now unpacks to fingerprint a
            // PII field's old/new (INV-DRAFT-2 logger change) — so a name/ID
            // edit never lands as cleartext in the audit log.
            if ($old !== $validated) {
                MealsDB_Logger::log(
                    'invoice_draft_edit',
                    $draft_id,
                    $client_id . ':' . $field,
                    is_scalar($old) ? (string) $old : wp_json_encode($old),
                    is_scalar($validated) ? (string) $validated : wp_json_encode($validated)
                );
            }

            wp_send_json_success([
                'field'   => $field,
                'value'   => $validated,
                'changed' => ($old !== $validated),
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
     * Lock + audit a draft. INV-DRAFT-2 stops here: it does NOT return a
     * downloadable invoice — per-pipeline CSV/PDF serialization is INV-DRAFT-3.
     * The UI refreshes into the read-only grid; the download affordance is
     * deliberately absent until there is real output to download.
     */
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

            // finalize() returns the placeholder row map on success (INV-DRAFT-1)
            // or null on refusal/lost-race. We deliberately do NOT stream that
            // return value as a file — it is not the real serialized invoice yet
            // (INV-DRAFT-3). We only confirm the lock landed.
            $result = MealsDB_Invoice_Draft::finalize($draft_id);
            if ($result === null) {
                wp_send_json_error(['message' => __('Could not finalize (already finalized or a concurrent change — reload).', 'meals-db')]);
                return;
            }

            wp_send_json_success([
                'finalized' => true,
                'draft_id'  => $draft_id,
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Invoice_Draft AJAX] finalize failed: ' . $e->getMessage());
            wp_send_json_error(['message' => __('Unable to finalize draft. Please try again.', 'meals-db')]);
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
            $int = (int) $raw;
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
        if (preg_match('/(cents|rate|contribution|delivery_fee|fee|cost|basic|tax|amount|price|total|subtotal|allowance)/', $f)) {
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
     * Allowed SDNB service-zone codes. Mirrors
     * MealsDB_Ajax_Invoice::allowed_sdnb_zones (same filter) so the draft
     * generator and the legacy direct-download accept the same zones.
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
