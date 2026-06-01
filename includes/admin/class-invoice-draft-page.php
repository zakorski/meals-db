<?php
/**
 * Admin page: MealsDB → Invoice Drafts (directive INV-DRAFT-2).
 *
 * The screen the operator actually uses to review/edit a generated invoice
 * before finalizing it. Two views, switched by query param (mirroring the
 * Event Log page's tab pattern):
 *   - list (default): all drafts for the chosen filters + a "Generate draft"
 *     form. Generating ALWAYS makes a NEW draft (operator decision #4).
 *   - review (?draft_id=N): a key-driven editable grid over the draft's
 *     `current` rows. Each editable field shows a "was:" hint when it differs
 *     from the generated baseline. A finalized draft renders read-only.
 *
 * Capability: manage_options — the same tight audience the Event Log page
 * uses, because this review grid renders DECRYPTED client PII (names,
 * individual_id, vet_health_card, addresses). That is stricter than the
 * baseline plugin cap the generate-invoice surface uses, and intentionally so;
 * do NOT loosen it.
 *
 * XSS discipline: server-rendered, every cell escaped at emission (esc_html /
 * esc_attr), matching the Event Log page. The interactive edit/generate/
 * finalize JS lives in an enqueued assets/js file (per the codebase's
 * "no inline <script> > 20 lines" rule — the directive's "no build step" is
 * satisfied by a plain enqueued .js, no bundler involved).
 *
 * Finalize (INV-DRAFT-3): finalizing locks + audits the draft AND serializes
 * the per-pipeline artifact, captured (encrypted) on the draft. A finalized
 * draft's review view then offers Download CSV (and, for VAC, Download PDF)
 * via the admin-post stream endpoint.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Invoice_Draft_Page {

    public const PAGE_SLUG = 'mealsdb_invoice_drafts';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 22);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'mealsdb',
            __('Invoice Drafts', 'meals-db'),
            __('Invoice Drafts', 'meals-db'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue_scripts($hook): void {
        // Submenu hook suffix is "<parent>_page_<slug>". Only load on our page.
        if (!is_string($hook) || strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        // Shared on-page notice helper (directive GUI-NOTICES) — supplies
        // window.MealsDBNotice for the draft grid's validation/finalize messages.
        $notice_handle = MealsDB_Admin_UI::register_notice_script();

        wp_enqueue_script(
            'mealsdb-invoice-draft-js',
            plugins_url('assets/js/invoice-draft.js', dirname(dirname(__FILE__))),
            ['jquery', $notice_handle],
            defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : false,
            true
        );

        wp_add_inline_script(
            'mealsdb-invoice-draft-js',
            'window.mealsdbInvoiceDraft = ' . wp_json_encode([
                'ajaxUrl'  => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce(MealsDB_Ajax_Invoice_Draft::NONCE_ACTION),
                'pageUrl'  => admin_url('admin.php?page=' . self::PAGE_SLUG),
                'i18n'     => [
                    'saving'      => __('Saving…', 'meals-db'),
                    'saved'       => __('Saved', 'meals-db'),
                    'genericErr'  => __('Something went wrong. Please try again.', 'meals-db'),
                    'confirmFin'  => __('Finalize this draft? Finalized drafts are read-only.', 'meals-db'),
                    'confirmUnfin' => __('Un-finalize this invoice? It will become editable again — you can edit it or regenerate.', 'meals-db'),
                    'reasonPrompt' => __('Enter a reason for un-finalizing (required — it is audited):', 'meals-db'),
                    'reasonRequired' => __('A reason is required to un-finalize.', 'meals-db'),
                ],
            ]) . ';',
            'before'
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'meals-db'));
        }

        $draft_id = isset($_GET['draft_id']) ? absint($_GET['draft_id']) : 0;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Meals DB — Invoice Drafts', 'meals-db') . '</h1>';

        if ($draft_id > 0) {
            self::render_review_view($draft_id);
        } else {
            self::render_list_view();
        }

        echo '</div>';
    }

    // -----------------------------------------------------------------
    // 1a — list view
    // -----------------------------------------------------------------

    private static function render_list_view(): void {
        $filters = [
            'pipeline'      => isset($_GET['f_pipeline']) ? sanitize_text_field((string) $_GET['f_pipeline']) : '',
            'billing_month' => isset($_GET['f_month']) ? sanitize_text_field((string) $_GET['f_month']) : '',
            'status'        => isset($_GET['f_status']) ? sanitize_text_field((string) $_GET['f_status']) : '',
        ];

        self::render_generate_form();

        // Filter form (GET — server-rendered list, like the Event Log page).
        $base = admin_url('admin.php?page=' . self::PAGE_SLUG);
        echo '<h2>' . esc_html__('Drafts', 'meals-db') . '</h2>';
        echo '<form method="get" style="margin:8px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '" />';
        echo '<label>' . esc_html__('Pipeline', 'meals-db') . ' ';
        self::pipeline_select('f_pipeline', $filters['pipeline'], true);
        echo '</label> ';
        echo '<label>' . esc_html__('Month', 'meals-db')
            . ' <input type="text" name="f_month" placeholder="YYYY-MM" value="' . esc_attr($filters['billing_month']) . '" /></label> ';
        echo '<label>' . esc_html__('Status', 'meals-db') . ' <select name="f_status">';
        echo '<option value="">' . esc_html__('Any', 'meals-db') . '</option>';
        foreach (['draft', 'finalized', 'superseded'] as $st) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($st),
                selected($filters['status'], $st, false),
                esc_html(ucfirst($st))
            );
        }
        echo '</select></label> ';
        submit_button(__('Filter', 'meals-db'), 'secondary', '', false);
        echo '</form>';

        $rows = MealsDB_Invoice_Draft::list(array_filter($filters, static function ($v) {
            return $v !== '';
        }));

        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Pipeline', 'Period', 'Month', 'Status', 'Rows', 'Edits', 'Created by', 'Created (UTC)', 'Finalized by', 'Finalized (UTC)', ''] as $h) {
            echo '<th>' . esc_html__($h, 'meals-db') . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="11"><em>' . esc_html__('No drafts match.', 'meals-db') . '</em></td></tr>';
        }

        foreach ($rows as $row) {
            $did    = (int) ($row['draft_id'] ?? 0);
            $status = (string) ($row['status'] ?? '');
            $review = esc_url($base . '&draft_id=' . $did);

            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['pipeline'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['period_start'] ?? '') . ' → ' . (string) ($row['period_end'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['billing_month'] ?? '')) . '</td>';
            echo '<td>' . esc_html($status) . '</td>';
            echo '<td>' . esc_html((string) (int) ($row['row_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) (int) ($row['edit_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html(self::user_label($row['created_by'] ?? null)) . '</td>';
            echo '<td>' . esc_html((string) ($row['created_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html(self::user_label($row['finalized_by'] ?? null)) . '</td>';
            echo '<td>' . esc_html((string) ($row['finalized_at'] ?? '')) . '</td>';
            echo '<td><a href="' . $review . '">' . esc_html__('Review', 'meals-db') . '</a>';
            if ($status === 'draft' && $did > 0) {
                echo ' | <a href="#" class="mealsdb-draft-finalize" data-draft-id="' . esc_attr((string) $did) . '">'
                    . esc_html__('Finalize', 'meals-db') . '</a>';
            }
            if ($status === 'finalized' && $did > 0) {
                // Directive INV-2: audited, admin-only reversal of the finalize lock.
                echo ' | <a href="#" class="mealsdb-draft-unfinalize" data-draft-id="' . esc_attr((string) $did) . '">'
                    . esc_html__('Un-finalize', 'meals-db') . '</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_generate_form(): void {
        echo '<h2>' . esc_html__('Generate a draft', 'meals-db') . '</h2>';
        echo '<p class="description">'
            . esc_html__('Generating always creates a NEW draft — it never changes an existing one.', 'meals-db')
            . '</p>';
        echo '<div id="mealsdb-draft-generate" style="margin:8px 0;">';
        echo '<label>' . esc_html__('Pipeline', 'meals-db') . ' ';
        self::pipeline_select('gen_pipeline', '', false);
        echo '</label> ';
        echo '<label class="mealsdb-gen-zone" style="display:none;">' . esc_html__('Zone', 'meals-db')
            . ' <input type="text" id="gen_zone" placeholder="M" size="3" /></label> ';
        echo '<label>' . esc_html__('Start', 'meals-db') . ' <input type="date" id="gen_start" /></label> ';
        echo '<label>' . esc_html__('End', 'meals-db') . ' <input type="date" id="gen_end" /></label> ';
        echo '<button type="button" class="button button-primary" id="mealsdb-draft-generate-btn">'
            . esc_html__('Generate draft', 'meals-db') . '</button>';
        echo ' <span id="mealsdb-draft-generate-msg" style="margin-left:8px;"></span>';
        echo '</div>';
    }

    // -----------------------------------------------------------------
    // 1b — review / edit view
    // -----------------------------------------------------------------

    private static function render_review_view(int $draft_id): void {
        $draft = MealsDB_Invoice_Draft::get($draft_id);

        $base = admin_url('admin.php?page=' . self::PAGE_SLUG);
        echo '<p><a href="' . esc_url($base) . '">&larr; ' . esc_html__('Back to all drafts', 'meals-db') . '</a></p>';

        if ($draft === null) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Draft not found, or its payload could not be decrypted.', 'meals-db')
                . '</p></div>';
            return;
        }

        $status    = (string) ($draft['status'] ?? '');
        $editable  = ($status === 'draft');
        $current   = (isset($draft['payload']['current']) && is_array($draft['payload']['current']))
            ? $draft['payload']['current'] : [];
        $generated = (isset($draft['payload']['generated']) && is_array($draft['payload']['generated']))
            ? $draft['payload']['generated'] : [];

        printf(
            '<h2>%s <code>#%d</code> — %s / %s (%s)</h2>',
            esc_html__('Draft', 'meals-db'),
            (int) $draft_id,
            esc_html((string) ($draft['pipeline'] ?? '')),
            esc_html((string) ($draft['billing_month'] ?? '')),
            esc_html($status)
        );

        if (!$editable) {
            echo '<div class="notice notice-info inline"><p>'
                . esc_html__('This draft is finalized and is shown read-only.', 'meals-db')
                . '</p></div>';
        }

        echo '<p>' . esc_html__('Edits so far:', 'meals-db')
            . ' <strong id="mealsdb-draft-edit-count">' . esc_html((string) (int) ($draft['edit_count'] ?? 0)) . '</strong></p>';

        if (empty($current)) {
            echo '<p><em>' . esc_html__('This draft has no rows (no eligible clients / allocations for the period yet).', 'meals-db') . '</em></p>';
            return;
        }

        // Key-driven columns: union of all row keys in first-seen order. This
        // is the move that keeps the grid from forking per pipeline — VAC vs
        // SDNB row shapes (and INV-DRAFT-3's fold fields) render for free.
        $columns = [];
        foreach ($current as $row) {
            if (is_array($row)) {
                foreach (array_keys($row) as $k) {
                    $columns[$k] = true;
                }
            }
        }
        $columns = array_keys($columns);

        echo '<table class="widefat striped" id="mealsdb-draft-grid" data-draft-id="' . esc_attr((string) $draft_id) . '">';
        echo '<thead><tr>';
        foreach ($columns as $col) {
            echo '<th>' . esc_html(self::humanize($col)) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($current as $client_id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = (string) $client_id;
            echo '<tr>';
            foreach ($columns as $col) {
                $val     = $row[$col] ?? null;
                $gen_val = $generated[$client_id][$col] ?? null;
                self::render_cell($cid, $col, $val, $gen_val, $editable);
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($editable) {
            // Finalize button. INV-DRAFT-3: finalize now LOCKS + audits AND
            // serializes the per-pipeline artifact, which becomes downloadable
            // below once the page reloads into the read-only view.
            echo '<p style="margin-top:12px;">';
            echo '<button type="button" class="button button-primary mealsdb-draft-finalize" data-draft-id="'
                . esc_attr((string) $draft_id) . '">' . esc_html__('Finalize draft', 'meals-db') . '</button>';
            echo ' <span class="description">'
                . esc_html__('Finalizing locks the draft (read-only) and produces the downloadable invoice file.', 'meals-db')
                . '</span>';
            echo '</p>';
        } else {
            // Finalized: offer the captured artifact(s) for download (Step 3).
            self::render_download_links($draft_id, (string) ($draft['pipeline'] ?? ''));

            // Directive INV-2: an audited, admin-only un-finalize. Reverses the
            // one-way finalize lock so a draft finalized in error (e.g. against
            // an empty products table) can be edited or regenerated without raw
            // SQL. The JS prompts for a required reason and POSTs it; the AJAX
            // handler re-checks manage_options + nonce + the non-empty reason.
            echo '<p style="margin-top:12px;">';
            echo '<button type="button" class="button mealsdb-draft-unfinalize" data-draft-id="'
                . esc_attr((string) $draft_id) . '">' . esc_html__('Un-finalize', 'meals-db') . '</button>';
            echo ' <span class="description">'
                . esc_html__('Un-finalizing makes this invoice editable again (clears the finalized lock). Audited with a reason.', 'meals-db')
                . '</span>';
            echo '</p>';
        }
    }

    /**
     * Render the download affordance for a finalized draft (INV-DRAFT-3 Step
     * 3). CSV for every pipeline; VAC additionally offers the Blue Cross PDF.
     * Each link carries the dedicated download nonce; the admin-post handler
     * re-checks capability + nonce + rate limit and streams the EXACT bytes
     * captured at finalize time. A VAC PDF that could not be generated (no
     * dompdf in the environment) returns a clean 404 from the handler.
     */
    private static function render_download_links(int $draft_id, string $pipeline): void {
        if (!class_exists('MealsDB_Ajax_Invoice_Draft')) {
            return;
        }
        $base  = admin_url('admin-post.php');
        $nonce = wp_create_nonce(MealsDB_Ajax_Invoice_Draft::DOWNLOAD_NONCE_ACTION);

        $link = function (string $which, string $label) use ($base, $nonce, $draft_id): string {
            $url = add_query_arg([
                'action'   => 'mealsdb_download_finalized_invoice',
                'draft_id' => $draft_id,
                'which'    => $which,
                'nonce'    => $nonce,
            ], $base);
            return '<a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        };

        echo '<p style="margin-top:12px;">';
        echo $link('csv', __('Download CSV', 'meals-db'));
        if ($pipeline === MealsDB_Invoice_Draft::PIPELINE_VAC) {
            echo ' ' . $link('pdf', __('Download PDF', 'meals-db'));
        }
        echo '</p>';
    }

    /**
     * Render one grid cell. Editable scalar fields become an input carrying
     * data-client-id / data-field (the save JS keys off these). Non-scalar
     * values, and every cell on a finalized draft, render read-only.
     */
    private static function render_cell(string $client_id, string $field, $value, $generated_value, bool $editable): void {
        $is_scalar = is_scalar($value) || $value === null;
        $display   = $is_scalar ? (string) $value : wp_json_encode($value);

        echo '<td>';
        if ($editable && $is_scalar) {
            echo '<input type="text" class="mealsdb-draft-cell" '
                . 'data-client-id="' . esc_attr($client_id) . '" '
                . 'data-field="' . esc_attr($field) . '" '
                . 'value="' . esc_attr($display) . '" style="width:100%;box-sizing:border-box;" />';
        } else {
            echo '<span>' . esc_html($display) . '</span>';
        }

        // "was:" hint when the current value differs from the generated baseline.
        if ($generated_value !== null && (is_scalar($generated_value)) && (string) $generated_value !== $display) {
            echo '<div class="mealsdb-draft-was" style="font-size:11px;color:#777;">'
                . esc_html__('was:', 'meals-db') . ' ' . esc_html((string) $generated_value) . '</div>';
        }
        echo '</td>';
    }

    // -----------------------------------------------------------------
    // Small render helpers
    // -----------------------------------------------------------------

    private static function pipeline_select(string $name, string $selected, bool $with_any): void {
        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '">';
        if ($with_any) {
            echo '<option value="">' . esc_html__('Any', 'meals-db') . '</option>';
        }
        $options = [
            MealsDB_Invoice_Draft::PIPELINE_VAC         => __('VAC', 'meals-db'),
            MealsDB_Invoice_Draft::PIPELINE_SDNB_LEGACY => __('SDNB legacy', 'meals-db'),
            MealsDB_Invoice_Draft::PIPELINE_SDNB_NEW    => __('SDNB new portal', 'meals-db'),
        ];
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($selected, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    /** "allocated_tax_sides" → "Allocated Tax Sides". */
    private static function humanize(string $key): string {
        return ucwords(str_replace('_', ' ', $key));
    }

    /** Resolve a user id to a display label, falling back to the raw id. */
    private static function user_label($user_id): string {
        $uid = (int) $user_id;
        if ($uid <= 0) {
            return '—';
        }
        if (function_exists('get_userdata')) {
            $u = get_userdata($uid);
            if ($u && !empty($u->display_name)) {
                return $u->display_name . ' (#' . $uid . ')';
            }
        }
        return '#' . $uid;
    }
}
