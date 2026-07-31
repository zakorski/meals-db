<?php
/**
 * Admin page: MealsDB → Weekly Order Audit (Task 7 of the Weekly Order Audit
 * track).
 *
 * The operator-facing review surface over the audit snapshots that
 * MealsDB_Order_Audit builds and MealsDB_Ajax_Order_Audit mutates. Two views,
 * switched by query param (mirroring the Invoice Draft page):
 *   - list (default): every audit + a "Create audit draft" control that pulls a
 *     week's delivered orders into a new snapshot.
 *   - detail (?audit_id=N): a per-order review grid over the snapshot's
 *     `current` rows. Each row is confirmed as-delivered or edited with adjusted
 *     quantities + a note. A finalized audit renders read-only.
 *
 * Capability: the baseline plugin capability (manage_woocommerce by default) —
 * this grid shows client names + item counts, the SAME exposure as packing
 * slips, NOT the decrypted-ID PII that pushed the Invoice Draft page to
 * manage_options. The AJAX guard gates on the identical capability
 * (MealsDB_Ajax_Order_Audit::guard); keep the two in agreement.
 *
 * XSS discipline: server-rendered, every dynamic value escaped at emission
 * (esc_html / esc_attr / esc_url). Item names and notes are operator/client
 * data and are always escaped. The interactive create/confirm/edit/finalize JS
 * lives in an enqueued assets/js file (per the codebase's "no inline <script>
 * > 20 lines" rule).
 *
 * Mains/Sides display (deliberate, see the grid renderer): the cells always
 * show the SNAPSHOT counts. The snapshot did NOT store a per-item main/side
 * category, so an edited quantity cannot be reclassified into new mains/sides
 * totals without the product ids — recomputing here would LIE. Instead an
 * edited row shows a "Δ" marker next to its counts (numbers were adjusted) and
 * the per-item detail lives in the editor. Honest over precise.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Order_Audit_Page {

    public const PAGE_SLUG = 'mealsdb-order-audit';

    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu'], 22);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }

    public static function register_menu(): void {
        add_submenu_page(
            'mealsdb',
            __('Weekly Order Audit', 'meals-db'),
            __('Weekly Order Audit', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function enqueue_scripts($hook): void {
        // Submenu hook suffix is "<parent>_page_<slug>". Only load on our page.
        if (!is_string($hook) || strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        wp_enqueue_script(
            'mealsdb-order-audit-js',
            plugins_url('assets/js/order-audit.js', dirname(dirname(__FILE__))),
            ['jquery'],
            defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : false,
            true
        );

        wp_localize_script('mealsdb-order-audit-js', 'mealsdbOrderAudit', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce(MealsDB_Ajax_Order_Audit::NONCE_ACTION),
            // The page URL with a trailing '&audit_id=' so the JS can jump into
            // the detail view of a freshly created audit without rebuilding the
            // query string client-side.
            'detailUrlBase' => admin_url('admin.php?page=' . self::PAGE_SLUG . '&audit_id='),
            'i18n'          => [
                'confirmFinalize' => __('Save this weekly audit? It becomes read-only.', 'meals-db'),
                'promptUnfinalize' => __('Enter a reason to reopen this audit (required — it is audited):', 'meals-db'),
                'confirmDelete'   => __('Delete this draft audit? This cannot be undone.', 'meals-db'),
                'errorGeneric'    => __('Something went wrong. Please try again.', 'meals-db'),
                'ofResolved'      => __('of', 'meals-db'),
            ],
        ]);
    }

    public static function render(): void {
        // Pattern 1, view layer: re-enforce the capability at the top of the
        // page. A caller that reaches an AJAX endpoint never came through here.
        MealsDB_Permissions::enforce();

        $audit_id = isset($_GET['audit_id']) ? absint($_GET['audit_id']) : 0;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Weekly Order Audit', 'meals-db') . '</h1>';

        if ($audit_id > 0) {
            self::render_detail_view($audit_id);
        } else {
            self::render_list_view();
        }

        echo '</div>';
    }

    // -----------------------------------------------------------------
    // List view
    // -----------------------------------------------------------------

    private static function render_list_view(): void {
        // Default the picker to the Monday of the last COMPLETED week, in site
        // timezone. 'monday last week' resolves to the Monday of the previous
        // calendar week for every weekday (verified incl. Monday/Sunday).
        $tz             = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $today          = new DateTimeImmutable('now', $tz);
        $default_monday = $today->modify('monday last week')->format('Y-m-d');

        echo '<h2>' . esc_html__('Create a weekly audit', 'meals-db') . '</h2>';
        echo '<div style="margin:8px 0;">';
        echo '<label>' . esc_html__('Week starting (Monday)', 'meals-db') . ' ';
        echo '<input type="date" id="oa-week-start" value="' . esc_attr($default_monday) . '" /></label> ';
        echo '<button type="button" class="button button-primary" id="oa-create">'
            . esc_html__('Create audit draft', 'meals-db') . '</button>';
        echo ' <span id="oa-create-msg" style="margin-left:8px;"></span>';
        echo '</div>';
        echo '<p class="description">'
            . esc_html__('Pick the Monday of the week to audit. The week runs Monday–Sunday.', 'meals-db')
            . '</p>';

        $audits = MealsDB_Order_Audit::list_audits();

        echo '<h2>' . esc_html__('Audits', 'meals-db') . '</h2>';
        echo '<p class="description">' . esc_html__('Times are UTC.', 'meals-db') . '</p>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Week', 'Status', 'Progress', 'Created (UTC)', ''] as $h) {
            echo '<th>' . esc_html__($h, 'meals-db') . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($audits)) {
            echo '<tr><td colspan="5"><em>' . esc_html__('No audits yet.', 'meals-db') . '</em></td></tr>';
        }

        $base = admin_url('admin.php?page=' . self::PAGE_SLUG);
        foreach ($audits as $row) {
            $aid       = (int) ($row['audit_id'] ?? 0);
            $status    = (string) ($row['status'] ?? '');
            $row_count = (int) ($row['row_count'] ?? 0);
            $resolved  = (int) ($row['confirmed_count'] ?? 0) + (int) ($row['edited_count'] ?? 0);
            $open      = esc_url(add_query_arg('audit_id', $aid, $base));

            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['week_start'] ?? '') . ' – ' . (string) ($row['week_end'] ?? '')) . '</td>';
            echo '<td>' . self::status_badge($status) . '</td>';
            echo '<td>' . esc_html(sprintf(
                /* translators: 1: resolved rows, 2: total rows */
                __('%1$d / %2$d resolved', 'meals-db'),
                $resolved,
                $row_count
            )) . '</td>';
            echo '<td>' . esc_html((string) ($row['created_at'] ?? '')) . '</td>';
            echo '<td><a href="' . $open . '">' . esc_html__('Open', 'meals-db') . '</a>';
            if ($status === MealsDB_Order_Audit::STATUS_DRAFT && $aid > 0) {
                echo ' | <a href="#" class="oa-delete" data-audit-id="' . esc_attr((string) $aid) . '">'
                    . esc_html__('Delete', 'meals-db') . '</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // -----------------------------------------------------------------
    // Detail view
    // -----------------------------------------------------------------

    private static function render_detail_view(int $audit_id): void {
        $base = admin_url('admin.php?page=' . self::PAGE_SLUG);
        echo '<p><a href="' . esc_url($base) . '">&larr; ' . esc_html__('Back to all audits', 'meals-db') . '</a></p>';

        $audit = MealsDB_Order_Audit::get($audit_id);
        if ($audit === null) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('Audit not found.', 'meals-db')
                . '</p></div>';
            return;
        }

        $status    = (string) ($audit['status'] ?? '');
        $editable  = ($status === MealsDB_Order_Audit::STATUS_DRAFT);
        $row_count = (int) ($audit['row_count'] ?? 0);
        $resolved  = (int) ($audit['confirmed_count'] ?? 0) + (int) ($audit['edited_count'] ?? 0);
        $rows      = (isset($audit['payload']['current']) && is_array($audit['payload']['current']))
            ? $audit['payload']['current'] : [];

        printf(
            '<h2>%s %s – %s %s</h2>',
            esc_html__('Week', 'meals-db'),
            esc_html((string) ($audit['week_start'] ?? '')),
            esc_html((string) ($audit['week_end'] ?? '')),
            self::status_badge($status)
        );

        // Sort rows for a stable read: delivery date, then client name.
        usort($rows, static function ($a, $b) {
            $da = (string) ($a['delivery_date'] ?? '');
            $db = (string) ($b['delivery_date'] ?? '');
            if ($da !== $db) {
                return strcmp($da, $db);
            }
            return strcmp((string) ($a['client_name'] ?? ''), (string) ($b['client_name'] ?? ''));
        });

        echo '<table class="widefat striped" id="oa-grid" data-audit-id="' . esc_attr((string) $audit_id) . '">';
        echo '<thead><tr>';
        foreach (['Client', 'Delivery date', 'Order #', 'Mains', 'Sides', 'Status', 'Note', 'Actions'] as $h) {
            echo '<th>' . esc_html__($h, 'meals-db') . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="8"><em>'
                . esc_html__('No delivered orders were found for this week.', 'meals-db')
                . '</em></td></tr>';
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            self::render_row($row, $editable);
        }

        echo '</tbody></table>';

        // Progress + finalize / unfinalize controls.
        echo '<p id="oa-progress" data-row-count="' . esc_attr((string) $row_count) . '">'
            . esc_html(self::progress_text($resolved, $row_count)) . '</p>';

        if ($editable) {
            $disabled = ($row_count > 0 && $resolved === $row_count) ? '' : ' disabled';
            echo '<p><button type="button" id="oa-finalize" class="button button-primary"' . $disabled . '>'
                . esc_html__('Save weekly audit', 'meals-db') . '</button></p>';
        } else {
            $fin_at = (string) ($audit['finalized_at'] ?? '');
            $fin_by = self::user_label($audit['finalized_by'] ?? null);
            echo '<div class="notice notice-info inline"><p>'
                . esc_html(sprintf(
                    /* translators: 1: UTC timestamp, 2: user label */
                    __('Finalized %1$s by %2$s.', 'meals-db'),
                    $fin_at,
                    $fin_by
                ))
                . '</p></div>';
            echo '<p><button type="button" id="oa-unfinalize" class="button">'
                . esc_html__('Unfinalize', 'meals-db') . '</button></p>';
        }
    }

    /**
     * Render one order row plus (draft only) its initially-hidden editor row.
     */
    private static function render_row(array $row, bool $editable): void {
        $order_id   = (int) ($row['order_id'] ?? 0);
        $rstatus    = (string) ($row['audit_status'] ?? MealsDB_Order_Audit::ROW_PENDING);
        $note       = (string) ($row['note'] ?? '');
        $mains      = (int) ($row['mains_count'] ?? 0);
        $sides      = (int) ($row['sides_count'] ?? 0);
        $items      = (isset($row['items']) && is_array($row['items'])) ? $row['items'] : [];
        $edited     = (isset($row['edited_items']) && is_array($row['edited_items'])) ? $row['edited_items'] : [];
        $is_edited  = ($rstatus === MealsDB_Order_Audit::ROW_EDITED && !empty($edited));

        echo '<tr class="oa-row" data-order-id="' . esc_attr((string) $order_id) . '">';
        echo '<td>' . esc_html((string) ($row['client_name'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) ($row['delivery_date'] ?? '')) . '</td>';
        echo '<td>' . esc_html((string) $order_id) . '</td>';
        // Mains/Sides: snapshot counts always. A Δ marker (via the oa-delta
        // span, toggled by JS on save) flags that per-item quantities were
        // adjusted — we cannot re-derive mains/sides without product categories.
        echo '<td>' . esc_html((string) $mains)
            . '<span class="oa-delta"' . ($is_edited ? '' : ' style="display:none;"') . ' title="'
            . esc_attr__('Quantities adjusted', 'meals-db') . '"> &Delta;</span></td>';
        echo '<td>' . esc_html((string) $sides) . '</td>';
        echo '<td class="oa-status">' . esc_html(self::row_status_label($rstatus)) . '</td>';
        echo '<td class="oa-note-cell">';
        if ($note !== '') {
            echo '<span class="dashicons dashicons-edit-page" title="' . esc_attr($note) . '"></span>';
        }
        echo '</td>';
        echo '<td>';
        if ($editable) {
            $confirmed = ($rstatus === MealsDB_Order_Audit::ROW_CONFIRMED);
            echo '<button type="button" class="button oa-confirm" aria-pressed="' . ($confirmed ? 'true' : 'false') . '">'
                . esc_html($confirmed ? __('✓ Confirmed', 'meals-db') : __('✓ Confirm', 'meals-db'))
                . '</button> ';
            echo '<button type="button" class="button oa-edit" title="'
                . esc_attr__('Adjust quantities / add note', 'meals-db') . '">'
                . '<span class="dashicons dashicons-edit"></span></button>';
        }
        echo '</td>';
        echo '</tr>';

        if (!$editable) {
            return;
        }

        // Editor row — one number input per item, a note field, and controls.
        echo '<tr class="oa-editor-row" data-order-id="' . esc_attr((string) $order_id)
            . '" style="display:none;"><td colspan="8">';
        echo '<div class="oa-editor-items">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $item_key = (int) ($item['item_key'] ?? 0);
            $qty      = (int) ($item['qty'] ?? 0);
            $value    = array_key_exists($item_key, $edited) ? (int) $edited[$item_key] : $qty;
            echo '<label style="display:block;margin:4px 0;">';
            echo esc_html((string) ($item['product_name'] ?? '')) . ' ';
            echo '<input type="number" min="0" class="oa-qty" data-item-key="' . esc_attr((string) $item_key) . '" '
                . 'value="' . esc_attr((string) $value) . '" />';
            echo ' <span class="description">' . esc_html(sprintf(
                /* translators: %d: quantity delivered */
                __('(delivered: %d)', 'meals-db'),
                $qty
            )) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<label style="display:block;margin:6px 0;">' . esc_html__('Note', 'meals-db') . '<br />';
        echo '<textarea class="oa-note" maxlength="' . esc_attr((string) MealsDB_Order_Audit::MAX_NOTE_LEN) . '" '
            . 'rows="2" style="width:100%;max-width:480px;">' . esc_textarea($note) . '</textarea>';
        echo '</label>';
        echo '<p>';
        echo '<button type="button" class="button button-primary oa-editor-save">'
            . esc_html__('Save', 'meals-db') . '</button> ';
        echo '<button type="button" class="button oa-editor-revert">'
            . esc_html__('Revert to pending', 'meals-db') . '</button> ';
        echo '<button type="button" class="button oa-editor-cancel">'
            . esc_html__('Cancel', 'meals-db') . '</button>';
        echo '</p>';
        echo '</td></tr>';
    }

    // -----------------------------------------------------------------
    // Small helpers
    // -----------------------------------------------------------------

    /** "{resolved} of {row_count} orders resolved" — kept in sync with the JS. */
    private static function progress_text(int $resolved, int $row_count): string {
        return sprintf(
            /* translators: 1: resolved rows, 2: total rows */
            __('%1$d of %2$d orders resolved', 'meals-db'),
            $resolved,
            $row_count
        );
    }

    /** A draft/finalized status badge (pre-escaped span). */
    private static function status_badge(string $status): string {
        $label = ($status === MealsDB_Order_Audit::STATUS_FINALIZED)
            ? __('Finalized', 'meals-db')
            : __('Draft', 'meals-db');
        $color = ($status === MealsDB_Order_Audit::STATUS_FINALIZED) ? '#00a32a' : '#8a6d3b';
        return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;background:'
            . esc_attr($color) . ';">' . esc_html($label) . '</span>';
    }

    /** Row audit_status → display label. */
    private static function row_status_label(string $rstatus): string {
        switch ($rstatus) {
            case MealsDB_Order_Audit::ROW_CONFIRMED:
                return __('Confirmed', 'meals-db');
            case MealsDB_Order_Audit::ROW_EDITED:
                return __('Edited', 'meals-db');
            default:
                return __('Pending', 'meals-db');
        }
    }

    /** Resolve a user id to a display label, falling back to the raw id. */
    private static function user_label($user_id): string {
        $uid = (int) $user_id;
        if ($uid <= 0) {
            return '#' . $uid;
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
