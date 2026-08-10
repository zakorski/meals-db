<?php
/**
 * Admin menu & tab routing for Meals DB plugin.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Admin_UI {

    /**
     * Canonical Meals DB submenu order (spec 2026-07-16 §menu). The pages
     * register across six classes at admin_menu priorities 10–23; rather
     * than re-prioritising them all, reorder_submenu() sorts the finished
     * $submenu['mealsdb'] array by this slug list on a late hook. The
     * toggle-governed tail (Rate Definitions, Data Ops, Migration) only
     * appears here when the advanced-tools toggle is on.
     */
    private const MENU_ORDER = [
        'mealsdb',                    // Home (the top-level's own entry)
        'mealsdb_quick_order',
        'mealsdb-clients',
        'mealsdb-tasks',
        'mealsdb-packing-slips',
        'mealsdb-purchase-orders',
        'mealsdb-invoices',
        'mealsdb-reports',
        'meals-db-staff',
        'mealsdb_cron_status',
        'mealsdb_event_log',
        'mealsdb-settings',
        'mealsdb_rate_definitions',
        'mealsdb-data-ops',
        'mealsdb-migration',
    ];

    /**
     * Sort WP submenu entries ([0]=title, [1]=cap, [2]=slug, …) into
     * MENU_ORDER. Unknown/slugless entries sort after every known slug,
     * keeping their original relative order — a future page can never
     * vanish because this list lags behind. Pure, for unit testing.
     */
    public static function order_submenu_items(array $items): array {
        $rank  = array_flip(self::MENU_ORDER);
        $after = count(self::MENU_ORDER);

        $decorated = [];
        foreach (array_values($items) as $i => $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            $decorated[] = [
                'rank' => $rank[$slug] ?? $after,
                'idx'  => $i,
                'item' => $item,
            ];
        }
        usort($decorated, static function (array $a, array $b): int {
            return ($a['rank'] <=> $b['rank']) ?: ($a['idx'] <=> $b['idx']);
        });

        return array_column($decorated, 'item');
    }

    /**
     * admin_menu@999: apply MENU_ORDER to the live submenu. Runs after
     * every registration (latest is priority 23) and after the
     * advanced-tools visibility resolution.
     */
    public function reorder_submenu(): void {
        global $submenu;
        if (isset($submenu['mealsdb']) && is_array($submenu['mealsdb'])) {
            $submenu['mealsdb'] = self::order_submenu_items($submenu['mealsdb']);
        }
    }

    /**
     * Shared instance for registering hooks without relying on global state.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Initialize the admin UI: menu + routing.
     */
    public static function init(): void {
        self::instance()->register_hooks();
    }

    /**
     * Retrieve the shared instance.
     */
    public static function instance(): self {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register the shared on-page notice helper (directive GUI-NOTICES).
     *
     * `meals-notice.js` exposes window.MealsDBNotice — the single renderer that
     * the plugin's admin scripts use INSTEAD of native window.alert() for
     * informational errors/successes. It is registered (not unconditionally
     * enqueued) so that any script declaring it as a dependency pulls it in
     * automatically; WP loads a registered dependency on demand. Static + guarded
     * so the three separate enqueue contexts (main admin, migration page, invoice
     * draft page) can each call it without double-registering.
     *
     * @return string The registered script handle, for use as a dependency.
     */
    public static function register_notice_script(): string {
        $handle = 'meals-db-notice';
        if (!wp_script_is($handle, 'registered')) {
            $notice_path    = MEALS_DB_PLUGIN_DIR . 'assets/js/meals-notice.js';
            $notice_version = file_exists($notice_path) ? filemtime($notice_path) : MEALS_DB_VERSION;
            wp_register_script(
                $handle,
                MEALS_DB_PLUGIN_URL . 'assets/js/meals-notice.js',
                ['jquery'],
                $notice_version,
                true
            );
        }

        return $handle;
    }

    /**
     * Register the shared report-utils helper (window.MealsDBReport: esc, fmt,
     * csvCell, csvRow, exportCsv, showStatus) and return its handle, so any
     * admin page can declare it as a dependency. Extracted view scripts that
     * reuse the shared escaper / CSV export depend on this handle instead of
     * hand-rolling their own copies.
     */
    public static function register_report_utils_script(): string {
        $handle = 'mealsdb-report-utils';
        if (!wp_script_is($handle, 'registered')) {
            $path    = MEALS_DB_PLUGIN_DIR . 'assets/js/report-utils.js';
            $version = file_exists($path) ? filemtime($path) : MEALS_DB_VERSION;
            wp_register_script(
                $handle,
                MEALS_DB_PLUGIN_URL . 'assets/js/report-utils.js',
                ['jquery'],
                $version,
                true
            );
        }

        return $handle;
    }

    /**
     * Enqueue the extracted per-tab view scripts on the main plugin page.
     *
     * Each of these was previously a large inline <script> block inside the
     * matching view (CLAUDE.md bans inline logic blocks > 20 lines). The view
     * now emits a <script type="application/json"> data island and the enqueued
     * file reads it by element id, so nothing here needs wp_add_inline_script.
     * Gated per tab/action so only the visible view's script loads.
     */
    private function enqueue_tab_view_scripts(string $tab, string $action): void {
        $enqueue = static function (string $slug, array $extra_deps = []): void {
            $path = MEALS_DB_PLUGIN_DIR . 'assets/js/' . $slug . '.js';
            if (!file_exists($path)) {
                return;
            }
            wp_enqueue_script(
                'mealsdb-view-' . $slug,
                MEALS_DB_PLUGIN_URL . 'assets/js/' . $slug . '.js',
                array_merge(['jquery'], $extra_deps),
                filemtime($path),
                true
            );
        };

        switch ($tab) {
            case 'add':
                // The Add tab hosts the resume-a-draft panel (spec §3);
                // its delete/confirm behaviour lives in drafts.js.
                $enqueue('drafts');
                break;
            case 'ignored':
                $enqueue('ignored');
                break;
            case 'po_admin':
                // report-utils supplies csvRow/exportCsv for the detail-page
                // CSV export (Pattern 14 injection guard lives there).
                $enqueue('purchase-orders', [self::register_report_utils_script()]);
                $po_css = MEALS_DB_PLUGIN_DIR . 'assets/css/purchase-orders.css';
                if (file_exists($po_css)) {
                    wp_enqueue_style(
                        'mealsdb-purchase-orders',
                        MEALS_DB_PLUGIN_URL . 'assets/css/purchase-orders.css',
                        [],
                        filemtime($po_css)
                    );
                }
                break;
            case 'tasks':
                if ($action === 'detail') {
                    // task-form.js (handle mealsdb-task-form) is already enqueued
                    // for the tasks tab; task-detail.js renders through it.
                    $enqueue('task-detail', ['mealsdb-task-form']);
                } elseif ($action === 'rules') {
                    $enqueue('task-rules');
                } else {
                    $enqueue('tasks-list');
                }
                break;
        }
    }

    /**
     * Register admin hooks for menus and assets.
     */
    public function register_hooks(): void {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'redirect_legacy_quick_order_slug']);
        add_action('admin_init', [$this, 'redirect_retired_tabs']);
        add_action('admin_menu', [$this, 'reorder_submenu'], 999);
        add_filter('woocommerce_admin_order_actions', [$this, 'add_quick_order_clone_action'], 10, 2);
        add_filter('woocommerce_admin_order_preview_actions', [$this, 'add_quick_order_clone_preview_action'], 10, 2);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_quick_order_clone_button']);
        // Manual delivery-date override on the regular WC order-edit
        // screen (delivery-date-override directive, Section B). The
        // process hook fires for HPOS order saves with (order_id, order).
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_delivery_date_field']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_delivery_date_field'], 10, 2);
    }

    /**
     * Register the Meals DB menu and subpage.
     */
    public function register_menu(): void {
        if (!MealsDB_Permissions::can_access_plugin()) {
            return;
        }

        $page_title = 'Meals DB';
        $menu_title = 'Meals DB';
        $menu_slug  = 'mealsdb';
        $capability = MealsDB_Permissions::required_capability();
        $callback   = array('MealsDB_Admin_UI', 'render_main_page');

        add_menu_page(
            $page_title,
            $menu_title,
            $capability,
            $menu_slug,
            $callback,
            'dashicons-clipboard',
            30
        );

        // Re-register the parent's auto-cloned first submenu entry so it
        // reads "Home" (spec 2026-07-16 §menu) instead of repeating the
        // parent title. Same slug as the parent — WP replaces the clone.
        add_submenu_page(
            'mealsdb',
            $page_title,
            __('Home', 'meals-db'),
            $capability,
            'mealsdb',
            $callback
        );

        add_submenu_page(
            'mealsdb',
            __('Staff Directory', 'meals-db'),
            __('Staff Directory', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'meals-db-staff',
            ['MealsDB_Staff', 'render_admin_page']
        );

        add_submenu_page(
            'mealsdb',
            __('Quick Order', 'meals-db'),
            __('Quick Order', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb_quick_order',
            ['MealsDB_Quick_Order_UI', 'render_quick_order_page']
        );

        // PR 3 (spec 2026-07-16): the main page's tabs become dedicated
        // pages. Visual order comes from reorder_submenu(), not from
        // registration order.
        add_submenu_page(
            'mealsdb',
            __('Clients', 'meals-db'),
            __('Clients', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb-clients',
            ['MealsDB_Admin_UI', 'render_clients_page']
        );

        add_submenu_page(
            'mealsdb',
            __('Tasks', 'meals-db'),
            __('Tasks', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb-tasks',
            ['MealsDB_Admin_UI', 'render_tasks_page']
        );

        add_submenu_page(
            'mealsdb',
            __('Purchase Orders', 'meals-db'),
            __('Purchase Orders', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb-purchase-orders',
            ['MealsDB_Admin_UI', 'render_po_page']
        );

        // Settings view self-gates manage_options; register the menu entry
        // at the same tier so non-admins don't see a dead link.
        add_submenu_page(
            'mealsdb',
            __('Settings', 'meals-db'),
            __('Settings', 'meals-db'),
            'manage_options',
            'mealsdb-settings',
            ['MealsDB_Admin_UI', 'render_settings_page']
        );

        add_submenu_page(
            'mealsdb',
            __('Reports', 'meals-db'),
            __('Reports', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb-reports',
            ['MealsDB_Admin_UI', 'render_reports_page']
        );

        // Parent is toggle-dependent (advanced-tools visibility): 'mealsdb'
        // when shown, '' (registered but menu-less) when hidden.
        $parent = class_exists('MealsDB_Advanced_Tools')
            ? MealsDB_Advanced_Tools::menu_parent()
            : 'mealsdb';

        add_submenu_page(
            $parent,
            __('Data Ops', 'meals-db'),
            __('Data Ops', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'mealsdb-data-ops',
            ['MealsDB_Admin_UI', 'render_data_ops_page']
        );

    }

    /**
     * Redirect legacy Quick Order slug requests to the new slug.
     */
    public function redirect_legacy_quick_order_slug(): void {
        if (!isset($_GET['page'])) {
            return;
        }

        $page = $_GET['page'];
        if (function_exists('wp_unslash')) {
            $page = wp_unslash($page);
        }

        if ($page === 'meals-db-quick-order') {
            $args = $_GET;
            $args['page'] = 'mealsdb_quick_order';

            if (function_exists('wp_safe_redirect')) {
                wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
            } else {
                wp_redirect(add_query_arg($args, admin_url('admin.php')));
            }

            exit;
        }

        if ($page === 'meals-db') {
            $args = $_GET;
            $args['page'] = 'mealsdb';

            if (function_exists('wp_safe_redirect')) {
                wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
            } else {
                wp_redirect(add_query_arg($args, admin_url('admin.php')));
            }

            exit;
        }
    }

    /**
     * Legacy-URL map for the retired ?page=mealsdb&tab=… URLs (spec
     * 2026-07-16 §6; PR 3 dissolved the main page's tabs into dedicated
     * pages). Takes the request's full query-arg array and returns the
     * replacement admin URL with every extra scalar arg preserved
     * (client_id, po_id, task_id, paged, search, filters…), or null when
     * the request is not a retired-tab URL. Pure — no superglobal reads,
     * no redirect — so it is unit-testable. The PR 2 (string,string)
     * signature could not express arg preservation; this is the redesign
     * its review called for.
     */
    public static function retired_tab_target(array $query): ?string {
        $page = isset($query['page']) && is_string($query['page']) ? $query['page'] : '';
        $tab  = isset($query['tab']) && is_string($query['tab']) ? strtolower($query['tab']) : '';
        if ($page !== 'mealsdb' || $tab === '') {
            return null;
        }

        // tab => [new page slug, forced args appended after the preserved
        // extras]. The bare mealsdb slug (no tab) renders Home — no row.
        $map = [
            'sync'     => ['mealsdb-clients', ['tab' => 'sync']],
            'add'      => ['mealsdb-clients', ['tab' => 'add']],
            'clients'  => ['mealsdb-clients', ['tab' => 'list']],
            'drafts'   => ['mealsdb-clients', ['tab' => 'add']],
            'ignored'  => ['mealsdb-clients', ['tab' => 'sync', 'view' => 'ignored']],
            'slips'    => ['mealsdb-packing-slips', []],
            'po'       => ['mealsdb-purchase-orders', []],
            'po_admin' => ['mealsdb-purchase-orders', []],
            'tasks'    => ['mealsdb-tasks', []],
            'settings' => ['mealsdb-settings', []],
        ];
        if (!isset($map[$tab])) {
            return null;
        }

        [$new_page, $forced] = $map[$tab];
        $args = $query;
        unset($args['page'], $args['tab']);
        $args = array_merge($args, $forced);

        $url = 'admin.php?page=' . $new_page;
        foreach ($args as $key => $value) {
            if (!is_scalar($value)) {
                continue; // ?ids[]=… can't be preserved through this builder
            }
            $url .= '&' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        return admin_url($url);
    }

    public function redirect_retired_tabs(): void {
        if (!isset($_GET['page'], $_GET['tab'])) {
            return;
        }

        $query = $_GET;
        if (function_exists('wp_unslash')) {
            $query = wp_unslash($query);
        }

        $target = self::retired_tab_target(is_array($query) ? $query : []);
        if ($target === null) {
            return;
        }

        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($target);
        } else {
            wp_redirect($target);
        }
        exit;
    }

    /**
     * Add the "Clone to Quick Order" action to WooCommerce orders list.
     *
     * @param array     $actions Existing actions for the order.
     * @param WC_Order  $order   Order instance provided by WooCommerce.
     */
    public function add_quick_order_clone_action(array $actions, $order): array {
        $order_id = $this->validate_order_id($order);
        if ($order_id <= 0 || !MealsDB_Permissions::can_access_plugin()) {
            return $actions;
        }

        $url = $this->build_quick_order_clone_url($order_id);

        $actions['mealsdb_clone_quick_order'] = [
            'url'    => $url,
            'name'   => __('Clone to Quick Order', 'meals-db'),
            'action' => 'mealsdb-clone-quick-order',
        ];

        return $actions;
    }

    /**
     * Add the clone action to the order preview modal.
     *
     * @param array    $actions Existing actions for the preview.
     * @param WC_Order $order   Order instance.
     */
    public function add_quick_order_clone_preview_action(array $actions, $order): array
    {
        $order_id = $this->validate_order_id($order);
        if ($order_id <= 0 || !MealsDB_Permissions::can_access_plugin()) {
            return $actions;
        }

        $url = $this->build_quick_order_clone_url($order_id);

        $actions['mealsdb_clone_quick_order'] = [
            'title' => __('Clone to Quick Order', 'meals-db'),
            'url'   => $url,
            'class' => 'mealsdb-clone-quick-order',
        ];

        return $actions;
    }

    /**
     * Render the clone button on the order details screen.
     *
     * @param WC_Order $order Order instance.
     */
    public function render_quick_order_clone_button($order): void
    {
        $order_id = $this->validate_order_id($order);
        if ($order_id <= 0 || !MealsDB_Permissions::can_access_plugin()) {
            return;
        }

        $url = $this->build_quick_order_clone_url($order_id);

        printf(
            '<p class="mealsdb-clone-quick-order"><a class="button" href="%s">%s</a></p>',
            esc_url($url),
            esc_html__('Clone to Quick Order', 'meals-db')
        );
    }

    /**
     * Render the manual delivery-date override field on the WC order-edit
     * screen (delivery-date-override directive, Section B.5). Pre-filled
     * from the order's _delivery_date meta; blank means "computed
     * occurrence" (the normal cadence-derived slip date). A stored value
     * that is off-day or in the past shows the advisory warning inline —
     * soft-warn only, the save is never blocked.
     *
     * @param WC_Order $order Order instance.
     */
    public function render_delivery_date_field($order): void
    {
        $order_id = $this->validate_order_id($order);
        if ($order_id <= 0 || !MealsDB_Permissions::can_access_plugin()) {
            return;
        }

        $wc_order = is_object($order) && is_a($order, 'WC_Order') ? $order : wc_get_order($order_id);
        if (!$wc_order) {
            return;
        }

        $stored  = MealsDB_Delivery_Date_Advisor::sanitize_ymd((string) $wc_order->get_meta('_delivery_date', true));
        $warning = '';
        if ($stored !== '') {
            $warning = MealsDB_Delivery_Date_Advisor::warning_for(
                $stored,
                MealsDB_Delivery_Date_Advisor::expected_day_for_wp_user((int) $wc_order->get_customer_id())
            );
        }

        wp_nonce_field('mealsdb_delivery_date_save', 'mealsdb_delivery_date_nonce');
        ?>
        <p class="form-field form-field-wide mealsdb-delivery-date-override">
            <label for="mealsdb_delivery_date"><?php esc_html_e('Delivery date (Meals DB)', 'meals-db'); ?></label>
            <input type="date"
                   name="mealsdb_delivery_date"
                   id="mealsdb_delivery_date"
                   value="<?php echo esc_attr($stored); ?>" />
            <span class="description">
                <?php esc_html_e('Overrides the delivery date for THIS order only (slips select on it). Clear to revert to the computed schedule date. Does not change the client\'s recurring cadence.', 'meals-db'); ?>
            </span>
            <?php if ($warning !== '') : ?>
                <span class="description" style="color:#996800;"><?php echo esc_html($warning); ?></span>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Persist the delivery-date override from the order-edit screen
     * (directive Section B.6): valid date → write _delivery_date; field
     * cleared → DELETE the meta so the order reverts to the computed
     * occurrence; malformed input or an unchanged value → leave the
     * stored meta alone. Guarded by nonce + edit_shop_orders; changes
     * are audit-logged (a committed change to a data record — the
     * Pattern 6 boundary).
     *
     * @param int   $order_id WC order ID.
     * @param mixed $posted   Post object / order (unused; meta comes from $_POST).
     */
    public function save_delivery_date_field($order_id, $posted = null): void
    {
        // The nonce field only exists when our render ran on this screen;
        // absent means some other save context — never touch the meta.
        $nonce = isset($_POST['mealsdb_delivery_date_nonce'])
            ? sanitize_text_field(wp_unslash((string) $_POST['mealsdb_delivery_date_nonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'mealsdb_delivery_date_save')) {
            return;
        }
        if (!current_user_can('edit_shop_orders') || !MealsDB_Permissions::can_access_plugin()) {
            return;
        }

        $order_id = (int) $order_id;
        $wc_order = $order_id > 0 && function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$wc_order) {
            return;
        }

        $raw = array_key_exists('mealsdb_delivery_date', $_POST)
            ? sanitize_text_field(wp_unslash((string) $_POST['mealsdb_delivery_date']))
            : null;
        $existing = MealsDB_Delivery_Date_Advisor::sanitize_ymd((string) $wc_order->get_meta('_delivery_date', true));

        $decision = MealsDB_Delivery_Date_Advisor::resolve_action($raw, $existing);
        if ($decision['action'] === 'noop') {
            return;
        }

        try {
            if ($decision['action'] === 'set') {
                $wc_order->update_meta_data('_delivery_date', $decision['value']);
            } else {
                $wc_order->delete_meta_data('_delivery_date');
            }
            $wc_order->save();

            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::log(
                    'order_delivery_date_override',
                    $order_id,
                    '_delivery_date',
                    $existing !== '' ? $existing : null,
                    $decision['action'] === 'set' ? $decision['value'] : null
                );
            }

            // The override changes which billing month this order's meals land
            // in, but nothing else marks that dirty — so without this the
            // rebuilder never re-runs and the move never materialises (a limb of
            // audit-2026-08 B04). Mark BOTH the order's existing allocation
            // month(s) — so the OLD placement is rebuilt away — and its newly
            // resolved month (the override month for a 'set', the computed month
            // for a 'delete'). Marking is cheap + idempotent; the actual rebuild
            // is deferred to the event-sourced dirty sweep (nightly / invoice).
            if (class_exists('MealsDB_Allocation_Engine')) {
                $engine = new MealsDB_Allocation_Engine();
                $engine->mark_order_months_dirty($order_id); // existing rows' month(s)
                $engine->allocate_order($order_id);          // newly-resolved month
            }
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[MealsDB Admin UI] delivery date save failed: ' . $e->getMessage());
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'    => 'error',
                    'category'    => 'quick_order',
                    'subsystem'   => 'admin_ui',
                    'event'       => 'delivery_date_override.save_failed',
                    'outcome'     => 'degraded',
                    'message'     => $e->getMessage(),
                    'entity_type' => 'wc_order',
                    'entity_id'   => $order_id,
                ]);
            }
        }
    }

    /**
     * Build a Quick Order clone URL for the provided order ID.
     */
    private function build_quick_order_clone_url(int $order_id): string
    {
        return add_query_arg(
            [
                'page'        => 'mealsdb_quick_order',
                'clone_order' => $order_id,
            ],
            admin_url('admin.php')
        );
    }

    /**
     * Validate and sanitise a WooCommerce order ID from multiple sources.
     *
     * @param mixed $order The order object or numeric ID.
     */
    private function validate_order_id($order): int
    {
        if (is_object($order) && is_a($order, 'WC_Order')) {
            $order = $order->get_id();
        }

        if (!is_numeric($order)) {
            return 0;
        }

        $order_id = (int) $order;
        return $order_id > 0 ? $order_id : 0;
    }

    /**
     * Enqueue admin scripts and styles for Meals DB screens.
     *
     * @param string $hook
     */
    public function enqueue_assets(string $hook): void {
        // WP derives these admin-page hook suffixes deterministically: the
        // top-level page is toplevel_page_{menu_slug} and every submenu hook is
        // {sanitize_title(parent MENU TITLE)}_page_{submenu_slug}. The parent
        // menu title 'Meals DB' sanitises to 'meals-db', so the submenu prefix
        // is 'meals-db_page_' (NOT 'mealsdb_page_', which would key off the
        // slug). Each of these is the single hook WP can actually emit for the
        // page; the former alternate spellings never matched.
        $is_main_page        = ($hook === 'toplevel_page_mealsdb');
        $is_staff_page       = ($hook === 'meals-db_page_meals-db-staff');
        $is_quick_order_page = ($hook === 'meals-db_page_mealsdb_quick_order');
        $is_reports_page     = ($hook === 'meals-db_page_mealsdb-reports');
        // Data Ops is toggle-governed: its hook suffix depends on the
        // advanced-tools toggle ('meals-db_page_{slug}' when visible,
        // 'admin_page_{slug}' when hidden) — accept both.
        $is_data_ops_page    = in_array($hook, ['meals-db_page_mealsdb-data-ops', 'admin_page_mealsdb-data-ops'], true);

        // PR 3 (spec 2026-07-16): the main page's tabs live on dedicated
        // pages now. Each new hook is translated back into the legacy
        // $tab/$action vocabulary below, so the per-view asset blocks are
        // unchanged.
        $is_clients_page  = ($hook === 'meals-db_page_mealsdb-clients');
        $is_tasks_page    = ($hook === 'meals-db_page_mealsdb-tasks');
        $is_settings_page = ($hook === 'meals-db_page_mealsdb-settings');
        $is_po_page       = ($hook === 'meals-db_page_mealsdb-purchase-orders');

        if (!$is_main_page && !$is_staff_page && !$is_quick_order_page && !$is_reports_page && !$is_data_ops_page
            && !$is_clients_page && !$is_tasks_page && !$is_settings_page && !$is_po_page) {
            return;
        }

        $style_path = MEALS_DB_PLUGIN_DIR . 'assets/css/admin.css';
        $style_version = file_exists($style_path) ? filemtime($style_path) : MEALS_DB_VERSION;
        wp_enqueue_style(
            'mealsdb-admin',
            MEALS_DB_PLUGIN_URL . 'assets/css/admin.css',
            [],
            $style_version
        );

        if ($is_staff_page) {
            return;
        }

        if ($is_quick_order_page) {
            $quick_order_style_path = MEALS_DB_PLUGIN_DIR . 'assets/css/quick-order.css';
            $quick_order_style_version = file_exists($quick_order_style_path) ? filemtime($quick_order_style_path) : MEALS_DB_VERSION;

            wp_enqueue_style(
                'mealsdb-quick-order',
                MEALS_DB_PLUGIN_URL . 'assets/css/quick-order.css',
                ['mealsdb-admin'],
                $quick_order_style_version
            );

            $quick_order_script_path = MEALS_DB_PLUGIN_DIR . 'assets/js/quick-order.js';
            $quick_order_script_version = file_exists($quick_order_script_path) ? filemtime($quick_order_script_path) : MEALS_DB_VERSION;

            wp_enqueue_script(
                'mealsdb-quick-order',
                MEALS_DB_PLUGIN_URL . 'assets/js/quick-order.js',
                ['jquery'],
                $quick_order_script_version,
                true
            );

            $clone_order_id = MealsDB_Quick_Order_UI::get_requested_clone_order_id();
            $tax_settings   = $this->get_quick_order_tax_settings();
            $client_type    = $this->get_quick_order_client_type();

            // Use wp_add_inline_script + wp_json_encode instead of wp_localize_script:
            // wp_localize_script coerces booleans, integers, and floats into
            // strings (a legacy behaviour retained for back-compat). Our tax
            // rate is a float and taxableTypes is a nested array that the
            // quick-order JS consumes as structured data, so we need the real
            // JSON types to travel through untouched.
            $quick_order_data = [
                'ajaxUrl'       => admin_url('admin-ajax.php'),
                'cloneOrderId'  => $clone_order_id,
                'nonce'         => wp_create_nonce('mealsdb_nonce'),
                'nonces'        => [
                    'createOrder'    => wp_create_nonce('mealsdb_quick_order_create_order'),
                    'cloneOrder'     => wp_create_nonce('mealsdb_nonce'),
                ],
                'messages'      => [
                    'cloneLoading' => __('Loading products from the selected order…', 'meals-db'),
                    'cloneLoaded'  => __('Products from the selected order have been added to Quick Order.', 'meals-db'),
                    'cloneFailed'  => __('Unable to load products from the selected order.', 'meals-db'),
                    'cloneNoItems' => __('The selected order does not contain any products that can be cloned.', 'meals-db'),
                ],
                'tax'           => [
                    'rate'          => $tax_settings['rate'],
                    'taxableTypes'  => $tax_settings['taxable_types'],
                ],
                'clientType'   => $client_type,
            ];
            wp_add_inline_script(
                'mealsdb-quick-order',
                'window.mealsdbQuickOrder = ' . wp_json_encode($quick_order_data) . ';',
                'before'
            );

            return;
        }

        // Reports page: read-only report scripts (shared report-utils +
        // each report's JS). Handlers unchanged; only the host page moved.
        if ($is_reports_page) {
            $this->enqueue_reports_page_scripts();
            return;
        }

        // Data Ops page: the settings JS (private backfills, enrich, product
        // sync, delivery-day backfill) plus the migration/updates JS (DB sync,
        // allowance/address/allocation backfills, fetch products).
        if ($is_data_ops_page) {
            $this->enqueue_data_ops_page_scripts();
            return;
        }

        // Home (spec 2026-07-16 §1–2): title, quick actions, and the PR 4
        // dashboard widgets are all server-rendered — admin.css only, no JS.
        if ($is_main_page) {
            return;
        }

        $tab = $_GET['tab'] ?? '';
        if (function_exists('wp_unslash')) {
            $tab = wp_unslash($tab);
        }
        if (function_exists('sanitize_key')) {
            $tab = sanitize_key($tab);
        } else {
            $tab = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $tab));
        }

        $action = $_GET['action'] ?? '';
        if (function_exists('wp_unslash')) {
            $action = wp_unslash($action);
        }
        if (function_exists('sanitize_key')) {
            $action = sanitize_key($action);
        } else {
            $action = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $action));
        }

        // Translate the dedicated pages into the legacy tab identities the
        // asset blocks below and the two dispatch helpers are keyed on.
        if ($is_clients_page) {
            if ($tab === 'add') {
                // keep 'add'
            } elseif ($tab === 'sync') {
                $view = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
                $tab  = ($view === 'ignored') ? 'ignored' : 'sync';
            } else {
                $tab = 'clients'; // list (default) + action=edit
            }
        } elseif ($is_tasks_page) {
            $tab = 'tasks';
        } elseif ($is_settings_page) {
            $tab = 'settings';
        } elseif ($is_po_page) {
            $tab = 'po_admin';
        }

        if ($tab === 'add' || ($tab === 'clients' && $action === 'edit')) {
            $client_style_path = MEALS_DB_PLUGIN_DIR . 'assets/css/client-form.css';
            $client_style_version = file_exists($client_style_path) ? filemtime($client_style_path) : MEALS_DB_VERSION;
            wp_enqueue_style(
                'mealsdb-client-form',
                MEALS_DB_PLUGIN_URL . 'assets/css/client-form.css',
                [],
                $client_style_version
            );
        }

        // Shared on-page notice helper (directive GUI-NOTICES). Registered here so
        // the admin scripts below can declare it as a dependency; it supplies
        // window.MealsDBNotice in place of native alert() popups.
        $notice_handle = self::register_notice_script();

        $script_path = MEALS_DB_PLUGIN_DIR . 'assets/js/admin.js';
        $script_version = file_exists($script_path) ? filemtime($script_path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-admin',
            MEALS_DB_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'jquery-ui-datepicker', $notice_handle],
            $script_version,
            true
        );

        if ($tab === 'tasks') {
            $task_form_path = MEALS_DB_PLUGIN_DIR . 'assets/js/task-form.js';
            $task_form_version = file_exists($task_form_path) ? filemtime($task_form_path) : MEALS_DB_VERSION;
            wp_enqueue_script(
                'mealsdb-task-form',
                MEALS_DB_PLUGIN_URL . 'assets/js/task-form.js',
                [],
                $task_form_version,
                true
            );
        }

        $client_actions_path = MEALS_DB_PLUGIN_DIR . 'assets/js/client-actions.js';
        $client_actions_version = file_exists($client_actions_path) ? filemtime($client_actions_path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-client-actions',
            MEALS_DB_PLUGIN_URL . 'assets/js/client-actions.js',
            ['jquery', 'mealsdb-admin', $notice_handle],
            $client_actions_version,
            true
        );

        $initials_script_path = MEALS_DB_PLUGIN_DIR . 'assets/js/client-initials.js';
        $initials_script_version = file_exists($initials_script_path) ? filemtime($initials_script_path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-client-initials',
            MEALS_DB_PLUGIN_URL . 'assets/js/client-initials.js',
            ['jquery', 'mealsdb-admin', $notice_handle],
            $initials_script_version,
            true
        );

        // WP-user anchor (Validate + Pull Data) — only on the Add/Edit Client
        // views, where the WP-User-ID field renders. Directive GUI-F3F5-v2.
        if ($tab === 'add' || ($tab === 'clients' && $action === 'edit')) {
            $wp_user_script_path = MEALS_DB_PLUGIN_DIR . 'assets/js/client-wp-user.js';
            $wp_user_script_version = file_exists($wp_user_script_path) ? filemtime($wp_user_script_path) : MEALS_DB_VERSION;
            wp_enqueue_script(
                'mealsdb-client-wp-user',
                MEALS_DB_PLUGIN_URL . 'assets/js/client-wp-user.js',
                ['jquery', 'mealsdb-admin', $notice_handle],
                $wp_user_script_version,
                true
            );

            $wp_user_data = [
                'ajaxUrl'  => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('mealsdb_nonce'),
                'messages' => [
                    'enterId'        => __('Enter a positive WordPress User ID.', 'meals-db'),
                    'validating'     => __('Validating…', 'meals-db'),
                    'validated'      => __('Confirmed:', 'meals-db'),
                    'notFound'       => __('No WordPress user with that ID.', 'meals-db'),
                    'alreadyLinked'  => __('already linked to client #', 'meals-db'),
                    // Shown when the WP user is linked to the client currently being edited: a
                    // correct, expected self-link, so the wording reassures rather than alarms.
                    'alreadyLinkedSelf' => __('already linked to this client', 'meals-db'),
                    'validateFirst'  => __('Validate the WordPress User ID before pulling data.', 'meals-db'),
                    'pulling'        => __('Loading data from the WordPress user…', 'meals-db'),
                    'populated'      => __('Populated', 'meals-db'),
                    'populatedFields' => __('fields from WP user', 'meals-db'),
                    'fieldsLower'    => __('fields.', 'meals-db'),
                    'reviewSave'     => __('review and save.', 'meals-db'),
                    'pullFailed'     => __('Unable to load data from the WordPress user.', 'meals-db'),
                    'requiredOnSave' => __('A WordPress User ID is required. Use Validate to confirm it.', 'meals-db'),
                    'error'          => __('An unexpected error occurred. Please try again.', 'meals-db'),
                ],
            ];
            wp_add_inline_script(
                'mealsdb-client-wp-user',
                'window.mealsdbWpUser = ' . wp_json_encode($wp_user_data) . ';',
                'before'
            );

            $zone_day_path = MEALS_DB_PLUGIN_DIR . 'assets/js/client-zone-day.js';
            wp_enqueue_script(
                'mealsdb-client-zone-day',
                MEALS_DB_PLUGIN_URL . 'assets/js/client-zone-day.js',
                ['jquery'],
                file_exists($zone_day_path) ? filemtime($zone_day_path) : MEALS_DB_VERSION,
                true
            );
        }

        $mealsdb_data = [
            'nonce'   => wp_create_nonce('mealsdb_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ];
        wp_add_inline_script(
            'mealsdb-admin',
            'window.mealsdb = ' . wp_json_encode($mealsdb_data) . ';',
            'before'
        );

        $client_actions_data = [
            'activateLabel'      => __('Activate', 'meals-db'),
            'deactivateLabel'    => __('Deactivate', 'meals-db'),
            'genericError'       => __('An unexpected error occurred. Please try again.', 'meals-db'),
            'toggleError'        => __('Unable to update the client status.', 'meals-db'),
            'deleteError'        => __('Unable to delete the client.', 'meals-db'),
            'deleteSuccess'      => __('Client deleted successfully.', 'meals-db'),
            'emptyState'         => __('No clients found for the selected criteria.', 'meals-db'),
        ];
        wp_add_inline_script(
            'mealsdb-admin',
            'window.mealsdbClientActions = ' . wp_json_encode($client_actions_data) . ';',
            'before'
        );

        $initials_data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'generate' => wp_create_nonce('mealsdb_generate_initials'),
                'validate' => wp_create_nonce('mealsdb_validate_initials'),
            ],
            'messages' => [
                'success'       => __('Initials are valid.', 'meals-db'),
                'invalid'       => __('These initials are invalid or already in use.', 'meals-db'),
                'required'      => __('Please validate the initials before submitting.', 'meals-db'),
                'empty'         => __('Enter initials before validating.', 'meals-db'),
                'error'         => __('An unexpected error occurred. Please try again.', 'meals-db'),
                'generateError' => __('Unable to generate initials. Please try again.', 'meals-db'),
                'validating'    => __('Validating initials…', 'meals-db'),
            ],
        ];
        wp_add_inline_script(
            'mealsdb-client-initials',
            'window.mealsdbInitials = ' . wp_json_encode($initials_data) . ';',
            'before'
        );

        // Allocation-history widget only renders inside render_client_form()
        // when editing an existing client. Gate the enqueue to the same
        // condition so other client-list / add-client views do not pull in
        // the script needlessly.
        if ($tab === 'clients' && $action === 'edit') {
            $allocation_history_client_id = isset($_GET['client_id']) ? absint(wp_unslash($_GET['client_id'])) : 0;
            if ($allocation_history_client_id > 0) {
                $allocation_history_path    = MEALS_DB_PLUGIN_DIR . 'assets/js/client-allocation-history.js';
                $allocation_history_version = file_exists($allocation_history_path) ? filemtime($allocation_history_path) : MEALS_DB_VERSION;
                wp_register_script(
                    'mealsdb-client-allocation-history',
                    MEALS_DB_PLUGIN_URL . 'assets/js/client-allocation-history.js',
                    ['jquery', 'mealsdb-admin'],
                    $allocation_history_version,
                    true
                );
                $allocation_history_data = [
                    'clientId' => $allocation_history_client_id,
                    'ajaxUrl'  => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('mealsdb_nonce'),
                    'i18n'     => [
                        'noHistory'         => __('No allocation history found.', 'meals-db'),
                        'loadFailed'        => __('Failed to load allocation history.', 'meals-db'),
                        'loadingDetails'    => __('Loading details...', 'meals-db'),
                        'noDeliveryDetails' => __('No delivery details available.', 'meals-db'),
                        'statusFinalized'   => __('Finalized', 'meals-db'),
                        'statusOpen'        => __('Open', 'meals-db'),
                        'colDeliveryDate'   => __('Delivery Date', 'meals-db'),
                        'colOrderNumber'    => __('Order #', 'meals-db'),
                        'colMains'          => __('Mains', 'meals-db'),
                        'colSides'          => __('Sides', 'meals-db'),
                    ],
                ];
                wp_add_inline_script(
                    'mealsdb-client-allocation-history',
                    'window.mealsdbAllocationHistory = ' . wp_json_encode($allocation_history_data) . ';',
                    'before'
                );
                wp_enqueue_script('mealsdb-client-allocation-history');
            }
        }

        // Per-tab report-page scripts. Each was previously an inline
        // <script> block inside the matching view; extracting to real
        // files means they can be cached, evaluated under a strict CSP,
        // and share a single implementation of the CSV-quoting rules
        // via assets/js/report-utils.js.
        $this->enqueue_report_scripts($tab);

        // Extracted per-tab view scripts (drafts, ignored,
        // tasks list/detail/rules) — replaces the inline
        // <script> blocks those views used to carry.
        $this->enqueue_tab_view_scripts($tab, $action);
    }

    /**
     * Enqueue scripts for the Reports submenu page. Mirrors the per-tab
     * enqueue the reports used as tabs, just keyed to the new page. Loads
     * the shared report-utils plus all three report scripts (the page
     * sub-tabs switch between them client-side / via ?sub=).
     */
    private function enqueue_reports_page_scripts(): void {
        // Fee Reconciliation, Order Errors, Spillover, and Private Sales each
        // have a dedicated JS file enqueued below with report-utils. Private
        // Sales reads its nonce + i18n from a JSON data island its view emits
        // (window.mealsdb is not present on the Reports page), so it takes no
        // wp_add_inline_script here.
        wp_enqueue_script('jquery');

        $report_utils_path    = MEALS_DB_PLUGIN_DIR . 'assets/js/report-utils.js';
        $report_utils_version = file_exists($report_utils_path) ? filemtime($report_utils_path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-report-utils',
            MEALS_DB_PLUGIN_URL . 'assets/js/report-utils.js',
            ['jquery'],
            $report_utils_version,
            true
        );

        // Private Sales: data comes from the view's JSON island, not inline
        // config — so it is enqueued here rather than in the $bundles loop.
        $private_sales_path = MEALS_DB_PLUGIN_DIR . 'assets/js/private-sales.js';
        if (file_exists($private_sales_path)) {
            wp_enqueue_script(
                'mealsdb-view-private-sales',
                MEALS_DB_PLUGIN_URL . 'assets/js/private-sales.js',
                ['jquery', 'mealsdb-report-utils'],
                filemtime($private_sales_path),
                true
            );
        }

        $bundles = [
            'mealsdb-fee-reconciliation' => [
                'file' => 'assets/js/fee-reconciliation.js',
                'cfg'  => 'mealsdbFeeReconciliation',
                'data' => [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce('mealsdb_nonce'),
                    // edit-client.php reads $_GET['client_id'] (matching the
                    // canonical add_query_arg('client_id', ...) link builder in
                    // views/view-clients.php and the allocation-history enqueue
                    // above). '&id=' left every fee-reconciliation client link on
                    // "Invalid client specified." — use client_id.
                    'editUrl' => admin_url('admin.php?page=mealsdb-clients&tab=list&action=edit&client_id='),
                ],
            ],
            'mealsdb-order-errors' => [
                'file' => 'assets/js/order-errors.js',
                'cfg'  => 'mealsdbOrderErrors',
                'data' => [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce('mealsdb_nonce'),
                    // HPOS-exclusive site: order edit screens live at
                    // admin.php?page=wc-orders&action=edit&id=, NOT the legacy
                    // post.php?post= URL (which only reaches the editor via WC's
                    // COT redirect shim — the CLAUDE.md HPOS rule says not to
                    // rely on that). order-errors.js appends the order id.
                    'editUrl' => admin_url('admin.php?page=wc-orders&action=edit&id='),
                ],
            ],
            'mealsdb-spillover-report' => [
                'file' => 'assets/js/spillover-report.js',
                'cfg'  => 'mealsdbSpilloverReport',
                'data' => [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce'   => wp_create_nonce('mealsdb_nonce'),
                ],
            ],
        ];

        foreach ($bundles as $handle => $spec) {
            $path = MEALS_DB_PLUGIN_DIR . $spec['file'];
            if (!file_exists($path)) {
                continue;
            }
            wp_enqueue_script(
                $handle,
                MEALS_DB_PLUGIN_URL . $spec['file'],
                ['jquery', 'mealsdb-report-utils'],
                filemtime($path),
                true
            );
            wp_add_inline_script(
                $handle,
                'window.' . $spec['cfg'] . ' = ' . wp_json_encode($spec['data']) . ';',
                'before'
            );
        }
    }

    /**
     * Enqueue scripts for the Data Ops submenu page.
     *
     * The DB-sync / allowance / address / allocation / fetch-products
     * behaviour is INLINE in views/data-ops.php (relocated from the old
     * updates view) and self-contained — it uses the global `ajaxurl` and
     * generates its own nonces, so it only needs jQuery on the page.
     *
     * The private-backfill / deactivation / enrich / product-sync /
     * delivery-day behaviour lives in settings.js and needs its
     * window.mealsdbSettings config object.
     */
    private function enqueue_data_ops_page_scripts(): void {
        wp_enqueue_script('jquery');

        $path    = MEALS_DB_PLUGIN_DIR . 'assets/js/settings.js';
        $version = file_exists($path) ? filemtime($path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-settings',
            MEALS_DB_PLUGIN_URL . 'assets/js/settings.js',
            ['jquery'],
            $version,
            true
        );
        $data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'settings' => wp_create_nonce('mealsdb_settings_nonce'),
                'general'  => wp_create_nonce('mealsdb_nonce'),
            ],
        ];
        wp_add_inline_script(
            'mealsdb-settings',
            'window.mealsdbSettings = ' . wp_json_encode($data) . ';',
            'before'
        );
    }

    /**
     * Enqueue the settings-tab JS for the main admin page.
     *
     * Keeps the dispatch out of the main enqueue_assets body. The Fee
     * Reconciliation and Order Errors reports formerly handled here moved to
     * the Reports submenu (enqueue_reports_page_scripts); the main page has no
     * fees/errors tab, so only the settings tab remains live.
     */
    private function enqueue_report_scripts(string $tab): void {
        if ($tab !== 'settings') {
            return;
        }

        $path    = MEALS_DB_PLUGIN_DIR . 'assets/js/settings.js';
        $version = file_exists($path) ? filemtime($path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-settings',
            MEALS_DB_PLUGIN_URL . 'assets/js/settings.js',
            ['jquery'],
            $version,
            true
        );
        // Two nonces: one for the settings AJAX surface, one for
        // the general mealsdb AJAX surface (backfill, product sync).
        $data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'settings' => wp_create_nonce('mealsdb_settings_nonce'),
                'general'  => wp_create_nonce('mealsdb_nonce'),
            ],
        ];
        wp_add_inline_script(
            'mealsdb-settings',
            'window.mealsdbSettings = ' . wp_json_encode($data) . ';',
            'before'
        );
    }

    /**
     * Render the Reports submenu page. Sub-tabs select among the
     * read-only reports relocated here from the old tabbed cards.
     */
    public static function render_reports_page() {
        MealsDB_Permissions::enforce();

        $sub = isset($_GET['sub']) ? sanitize_key(wp_unslash((string) $_GET['sub'])) : 'fees';
        $subtabs = [
            'fees'      => __('Fee Reconciliation', 'meals-db'),
            'privates'  => __('Private Sales', 'meals-db'),
            'errors'    => __('Order Errors', 'meals-db'),
            'spillover' => __('Over-Allowance Spill', 'meals-db'),
        ];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Reports', 'meals-db') . '</h1>';
        self::render_subnav('mealsdb-reports', $subtabs, $sub);
        echo '<div class="mealsdb-tab-content">';
        switch ($sub) {
            case 'privates':
                include MealsDB_Plugin::path('views/private-sales.php');
                break;
            case 'errors':
                include MealsDB_Plugin::path('views/order-errors.php');
                break;
            case 'spillover':
                include MealsDB_Plugin::path('views/spillover-report.php');
                break;
            case 'fees':
            default:
                include MealsDB_Plugin::path('views/fee-reconciliation.php');
                break;
        }
        echo '</div></div>';
    }

    /**
     * Clients page (spec 2026-07-16 §3): List (with edit), Add (with the
     * resume-a-draft panel), and Sync (with Ignored Conflicts as a
     * view=ignored sub-view). The views are the former main-page tabs,
     * unchanged.
     */
    public static function render_clients_page(): void {
        MealsDB_Permissions::enforce();

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'list';
        $subtabs = [
            'list' => __('Client List', 'meals-db'),
            'add'  => __('Add Client', 'meals-db'),
            'sync' => __('WooCommerce Sync', 'meals-db'),
        ];
        if (!isset($subtabs[$tab])) {
            $tab = 'list';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Clients', 'meals-db') . '</h1>';
        self::render_subnav('mealsdb-clients', $subtabs, $tab, 'tab');
        echo '<div class="mealsdb-tab-content">';
        switch ($tab) {
            case 'add':
                include MealsDB_Plugin::path('views/partials/drafts-panel.php');
                include MealsDB_Plugin::path('views/add-client.php');
                break;

            case 'sync':
                $view = isset($_GET['view']) ? sanitize_key(wp_unslash((string) $_GET['view'])) : '';
                if ($view === 'ignored') {
                    include MealsDB_Plugin::path('views/ignored.php');
                } else {
                    include MealsDB_Plugin::path('views/dashboard.php');
                }
                break;

            case 'list':
            default:
                $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
                if ($action === 'edit') {
                    include MealsDB_Plugin::path('views/edit-client.php');
                } else {
                    include MealsDB_Plugin::path('views/view-clients.php');
                }
                break;
        }
        echo '</div></div>';
    }

    /** Tasks page: list / detail / rules — the former tasks tab, unchanged. */
    public static function render_tasks_page(): void {
        MealsDB_Permissions::enforce();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Tasks', 'meals-db') . '</h1>';
        echo '<div class="mealsdb-tab-content">';
        $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
        if ($action === 'detail') {
            include MealsDB_Plugin::path('views/task-detail.php');
        } elseif ($action === 'rules') {
            include MealsDB_Plugin::path('views/task-rules.php');
        } else {
            include MealsDB_Plugin::path('views/tasks-list.php');
        }
        echo '</div></div>';
    }

    /** Settings page — the former settings tab, unchanged (view self-gates manage_options). */
    public static function render_settings_page(): void {
        MealsDB_Permissions::enforce();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Meals DB Settings', 'meals-db') . '</h1>';
        include MealsDB_Plugin::path('views/settings.php');
        echo '</div>';
    }

    /** Purchase Orders page — the former po_admin tab, unchanged. */
    public static function render_po_page(): void {
        MealsDB_Permissions::enforce();

        echo '<div class="wrap">';
        include MealsDB_Plugin::path('views/purchase-orders.php');
        echo '</div>';
    }

    /**
     * Render the Data Ops submenu page. Hosts every data-mutating
     * operation relocated here from the old Settings and Updates cards.
     * The handlers are unchanged; only their host page moved.
     */
    public static function render_data_ops_page() {
        MealsDB_Permissions::enforce();

        // POST handlers relocated from render_main_page (schema update,
        // force rebuild). Delete-non-admin-users was removed entirely.
        //
        // Results are accumulated into $notices_html and echoed inline below,
        // NOT registered on the 'admin_notices' hook. This method is the
        // add_submenu_page render callback, which WP invokes via
        // do_action($page_hook) AFTER admin-header.php has already fired
        // 'admin_notices'/'all_admin_notices'. An add_action('admin_notices',
        // ...) here bound too late and never executed — the operator dropped
        // and recreated every plugin table and saw zero feedback, including the
        // force-rebuild failure report that exists precisely because CREATE
        // errors are otherwise silent (recon-01), and permission-denied
        // WP_Errors from the manage_options service checks, so a
        // baseline-capability user saw the op silently "succeed".
        $notices_html = '';

        if (isset($_POST['mealsdb_action']) && $_POST['mealsdb_action'] === 'update_schema') {
            check_admin_referer('mealsdb_update_schema', 'mealsdb_update_schema_nonce');
            require_once MEALS_DB_PLUGIN_DIR . 'includes/class-schema-sync.php';
            $result = MealsDB_Schema_Sync::run_full_sync();
            if (is_wp_error($result)) {
                $notices_html .= '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $notices_html .= '<div class="notice notice-success"><p>' . esc_html__('Database schema updated successfully.', 'meals-db') . '</p></div>';
            }
        }

        if (isset($_POST['mealsdb_action']) && $_POST['mealsdb_action'] === 'force_rebuild') {
            check_admin_referer('mealsdb_force_rebuild', 'mealsdb_force_rebuild_nonce');
            require_once MEALS_DB_PLUGIN_DIR . 'includes/class-schema-rebuild.php';
            $confirmation = isset($_POST['mealsdb_rebuild_confirm'])
                ? sanitize_text_field(wp_unslash((string) $_POST['mealsdb_rebuild_confirm']))
                : '';
            $result = MealsDB_Schema_Rebuild::run($confirmation);
            if (is_wp_error($result)) {
                $notices_html .= '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
            } else {
                $notices_html .= MealsDB_Admin_UI::render_force_rebuild_summary($result);
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Data Ops', 'meals-db') . '</h1>';
        // Echo the POST-handler notices here, inside the render callback, so
        // they actually reach the page (see the hook-timing note above). Each
        // fragment was escaped at build time (esc_html / esc_html__ /
        // render_force_rebuild_summary, which returns pre-escaped HTML), exactly
        // as the previous admin_notices closures emitted it.
        echo $notices_html;
        echo '<div class="mealsdb-tab-content">';
        include MealsDB_Plugin::path('views/data-ops.php');
        echo '</div></div>';
    }

    /**
     * Render a simple sub-tab nav bar for the new submenu pages.
     */
    private static function render_subnav(string $page, array $subtabs, string $active, string $param = 'sub'): void {
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($subtabs as $slug => $label) {
            $url = admin_url('admin.php?page=' . $page . '&' . $param . '=' . $slug);
            $cls = 'nav-tab' . ($slug === $active ? ' nav-tab-active' : '');
            printf('<a href="%s" class="%s">%s</a>', esc_url($url), esc_attr($cls), esc_html($label));
        }
        echo '</h2>';
    }

    /**
     * Home — the plugin's landing page (spec 2026-07-16 §1). PR 4 filled
     * the shell with the dashboard widgets — see views/home.php. The tab
     * router that lived here is gone: every tab is a dedicated page now,
     * and redirect_retired_tabs() catches old ?tab= URLs before render.
     */
    public static function render_main_page() {
        MealsDB_Permissions::enforce();

        include MealsDB_Plugin::path('views/home.php');
    }

    /**
     * Render a structured summary for destructive rebuild results.
     *
     * @param array<string, mixed> $result
     */
    public static function render_force_rebuild_summary(array $result): string {
        $dropped       = $result['dropped'] ?? [];
        $drop_errors   = $result['drop_errors'] ?? [];
        $created       = $result['created'] ?? [];
        $create_errors = $result['create_errors'] ?? [];

        ob_start();
        ?>
        <div class="notice notice-warning">
            <p><strong><?php echo esc_html__('External Meals DB Force Rebuild completed.', 'meals-db'); ?></strong></p>
            <p class="description"><?php echo esc_html__('All Meals DB tables were dropped and recreated using the canonical schema.', 'meals-db'); ?></p>
            <ul>
                <li><strong><?php echo esc_html__('Tables dropped:', 'meals-db'); ?></strong> <?php echo esc_html(implode(', ', $dropped)); ?></li>
                <?php if (!empty($drop_errors)) : ?>
                    <li>
                        <strong><?php echo esc_html__('Drop failures:', 'meals-db'); ?></strong>
                        <ul>
                            <?php foreach ($drop_errors as $error) : ?>
                                <li><?php echo esc_html(($error['table'] ?? 'Unknown table') . ' — ' . ($error['error'] ?? 'Unknown error')); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endif; ?>
                <li><strong><?php echo esc_html__('Tables created:', 'meals-db'); ?></strong> <?php echo esc_html(implode(', ', $created)); ?></li>
                <?php if (!empty($create_errors)) : ?>
                    <li>
                        <strong><?php echo esc_html__('Create failures:', 'meals-db'); ?></strong>
                        <ul>
                            <?php foreach ($create_errors as $error) : ?>
                                <li><?php echo esc_html(($error['table'] ?? 'Unknown table') . ' — ' . ($error['error'] ?? 'Unknown error')); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Render the client form using a single-page, multi-column layout.
     *
     * @param array $args
     */
    public static function render_client_form(array $args = []): void {
        $defaults = [
            'form_mode'         => 'add',
            'submit_label'      => __('Submit', 'meals-db'),
            'show_draft_button' => false,
            'resumed_draft_id'  => 0,
            'client_id'         => 0,
            'form_values'       => [],
        ];

        $args = array_merge($defaults, $args);

        $form_mode = $args['form_mode'] === 'edit' ? 'edit' : 'add';
        // 'submit_label' has a non-empty default and the sole caller
        // (views/partials/client-form.php) always passes an explicit,
        // mode-aware label, so a fallback ternary here never governed.
        $submit_label = (string) $args['submit_label'];
        $show_draft_button = (bool) $args['show_draft_button'];
        $resumed_draft_id = intval($args['resumed_draft_id']);
        $client_id = intval($args['client_id']);
        $form_values = is_array($args['form_values']) ? $args['form_values'] : [];

        // Normalize all enum/select field values for case-insensitive matching with UI elements
        $normalize_field_value = static function (string $field_name, $value): string {
            if ($value === null || $value === '') {
                return '';
            }

            $value = trim((string) $value);

            // Special case handling for client_type (SDNB must stay uppercase)
            if ($field_name === 'client_type') {
                $upper = strtoupper($value);
                if ($upper === 'SDNB') {
                    return 'SDNB';
                }
                return ucfirst(strtolower($value));
            }

            // Map of field names to their expected UI case format
            $field_formats = [
                'gender' => 'title',                // Male, Female, Other
                'requisition_period' => 'lower',    // day, week, month

                'ordering_contact_method' => 'upper', // AUTO-RENEW, BULK EMAIL, PHONE
                'payment_method' => 'title',        // Cheque, etc.
            ];

            $format = $field_formats[$field_name] ?? 'keep';

            switch ($format) {
                case 'upper':
                    return strtoupper($value);
                case 'lower':
                    return strtolower($value);
                case 'title':
                    return ucfirst(strtolower($value));
                case 'keep':
                default:
                    return $value;
            }
        };

        $client_type = $normalize_field_value('client_type', $form_values['client_type'] ?? '');

        $zone_schedule = class_exists('MealsDB_Zone_Day') ? MealsDB_Zone_Day::schedule() : [];
        $ordering_contact_method_options = MealsDB_Client_Form::get_allowed_options('ordering_contact_method');

        $format_enum_option_label = static function (string $value): string {
            $label = ucwords(strtolower($value));
            return str_ireplace(['Am', 'Pm'], ['AM', 'PM'], $label);
        };

        $alt_contact_name = $form_values['alt_contact_name'] ?? '';
        $alt_contact_first = '';
        $alt_contact_last = '';
        if (!empty($alt_contact_name)) {
            $name_parts = preg_split('/\s+/', trim((string) $alt_contact_name), 2);
            $alt_contact_first = $name_parts[0] ?? '';
            $alt_contact_last = $name_parts[1] ?? '';
        }

        $delivery_address_fields = [
            'delivery_address_street_number',
            'delivery_address_street_name',
            'delivery_address_unit',
            'delivery_address_city',
            'delivery_address_province',
            'delivery_address_postal',
        ];
        $delivery_address_enabled = false;
        foreach ($delivery_address_fields as $field_key) {
            if (!empty($form_values[$field_key])) {
                $delivery_address_enabled = true;
                break;
            }
        }

        $alt_contact_enabled = (
            !empty($alt_contact_first) ||
            !empty($alt_contact_last) ||
            !empty($form_values['alt_contact_phone_primary'] ?? '') ||
            !empty($form_values['alt_contact_phone_secondary'] ?? '') ||
            !empty($form_values['alt_contact_email'] ?? '')
        );

        $delivery_initials_value = $form_values['delivery_initials'] ?? '';
        $ordering_contact_method_value = $normalize_field_value('ordering_contact_method', $form_values['ordering_contact_method'] ?? '');
        $gender_value = $normalize_field_value('gender', $form_values['gender'] ?? '');
        $requisition_period_value = $normalize_field_value('requisition_period', $form_values['requisition_period'] ?? '');
        $form_classes = ['mealsdb-client-form'];
        if ($client_type !== '') {
            $form_classes[] = 'mealsdb-client-type-selected';
        }
        $form_class_attr = implode(' ', $form_classes);

        $client = $form_values;

        $identity_fields = [
            static function (array $client) use ($client_type) {
                ?>
                <tr>
                    <th><label for="client_type"><?php esc_html_e('Client Type *', 'meals-db'); ?></label></th>
                    <td>
                        <?php $current_type = $client_type; ?>
                        <select name="client_type" id="client_type" required data-base-required="1">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <option value="SDNB" <?php selected($current_type, 'SDNB'); ?>>SDNB</option>
                            <option value="Veteran" <?php selected($current_type, 'Veteran'); ?>>Veteran</option>
                            <option value="Private" <?php selected($current_type, 'Private'); ?>>Private</option>
                        </select>
                        <p class="description"><?php esc_html_e('Changing this selection updates which sections are shown below.', 'meals-db'); ?></p>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                // WordPress User ID moves to second position (directly after Client Type) per
                // directive GUI-IDENTITY-ORDER. Validate / Pull Data buttons, ids, and required
                // status are unchanged — only the row's position in the group changed.
                ?>
                <tr data-client-type="sdnb,veteran,private" data-required-for="sdnb,veteran,private">
                    <th>
                        <label for="wordpress_user_id"><?php esc_html_e('WordPress User ID *', 'meals-db'); ?></label>
                        <span class="description"><?php esc_html_e('Every client links to an existing WordPress user. Enter the ID, then Validate to confirm the person and (optionally) Pull Data to auto-fill the form.', 'meals-db'); ?></span>
                    </th>
                    <td>
                        <div class="mealsdb-wp-user-tools">
                            <input type="number" name="wordpress_user_id" id="wordpress_user_id" class="regular-text" min="1" step="1" required data-base-required="1" value="<?php echo esc_attr($client['wordpress_user_id'] ?? ''); ?>" />
                            <div class="mealsdb-wp-user-buttons">
                                <button type="button" class="button" id="mealsdb-validate-wp-user"><?php esc_html_e('Validate', 'meals-db'); ?></button>
                                <button type="button" class="button" id="mealsdb-pull-wp-user" disabled><?php esc_html_e('Pull Data', 'meals-db'); ?></button>
                            </div>
                            <div id="wp-user-validation-status"></div>
                            <div class="mealsdb-wp-user-message" aria-live="polite"></div>
                        </div>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) use ($client_id) {
                // Read-only display of the auto-increment primary key (directive
                // GUI-IDENTITY-ORDER). Intentionally NOT an input or named field: the hidden
                // client_id input on the form (emitted only in edit mode) is the sole carrier on
                // the save path, so the PK can never be POSTed or user-altered here. On Add
                // ($client_id === 0) a muted placeholder keeps the row's position consistent with
                // the Edit form.
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label><?php esc_html_e('Client ID', 'meals-db'); ?></label></th>
                    <td>
                        <?php
                        if ($client_id > 0) {
                            echo '<span class="mealsdb-client-id-display">#' . esc_html((string) $client_id) . '</span>';
                        } else {
                            echo '<span class="description">' . esc_html__('(assigned when saved)', 'meals-db') . '</span>';
                        }
                        ?>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="first_name"><?php esc_html_e('First Name *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="first_name" id="first_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['first_name'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="last_name"><?php esc_html_e('Last Name *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="last_name" id="last_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['last_name'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="client_email"><?php esc_html_e('Client Email', 'meals-db'); ?></label></th>
                    <td><input type="email" name="client_email" id="client_email" class="regular-text" data-base-required="1" value="<?php echo esc_attr($client['client_email'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private" data-required-for="sdnb,veteran,private">
                    <th><label for="open_date"><?php esc_html_e('Open Date *', 'meals-db'); ?></label></th>
                    <td><input type="date" name="open_date" id="open_date" class="mealsdb-datepicker" data-base-required="1" value="<?php echo esc_attr($client['open_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="birth_date"><?php esc_html_e('Date of Birth', 'meals-db'); ?></label></th>
                    <td><input type="date" name="birth_date" id="birth_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['birth_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) use ($gender_value) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><?php esc_html_e('Gender', 'meals-db'); ?></th>
                    <td>
                        <label><input type="radio" name="gender" value="Male" <?php checked($gender_value, 'Male'); ?> /> <?php esc_html_e('Male', 'meals-db'); ?></label>
                        <label><input type="radio" name="gender" value="Female" <?php checked($gender_value, 'Female'); ?> /> <?php esc_html_e('Female', 'meals-db'); ?></label>
                        <label><input type="radio" name="gender" value="Other" <?php checked($gender_value, 'Other'); ?> /> <?php esc_html_e('Other', 'meals-db'); ?></label>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="allowance_mains"><?php esc_html_e('Mains Allowance', 'meals-db'); ?></label></th>
                    <td>
                        <input type="number" name="allowance_mains" id="allowance_mains" min="0" class="regular-text" value="<?php echo esc_attr($client['allowance_mains'] ?? ''); ?>" />
                        <p class="description"><?php esc_html_e('Number of main meals allowed per billing period (per requisition period).', 'meals-db'); ?></p>
                    </td>
                </tr>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="allowance_sides"><?php esc_html_e('Sides Allowance', 'meals-db'); ?></label></th>
                    <td>
                        <input type="number" name="allowance_sides" id="allowance_sides" min="0" class="regular-text" value="<?php echo esc_attr($client['allowance_sides'] ?? ''); ?>" />
                        <p class="description"><?php esc_html_e('Number of side items allowed per billing period (per requisition period).', 'meals-db'); ?></p>
                    </td>
                </tr>
                <?php
            },
        ];

        $contact_fields = [
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="phone_primary"><?php esc_html_e('Phone Number *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="phone_primary" id="phone_primary" class="regular-text phone-mask" placeholder="(555)-555-5555" required data-base-required="1" value="<?php echo esc_attr($client['phone_primary'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="phone_secondary"><?php esc_html_e('Second Phone Number', 'meals-db'); ?></label></th>
                    <td><input type="text" name="phone_secondary" id="phone_secondary" class="regular-text phone-mask" placeholder="(555)-555-5555" value="<?php echo esc_attr($client['phone_secondary'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="do_not_call_client_phone"><?php esc_html_e("Do Not Call Client's Phone", 'meals-db'); ?></label></th>
                    <td>
                        <?php // Hidden '0' fallback: without it an unchecked box posts nothing and update() leaves the column at 1 — the flag could never be turned off from the form. ?>
                        <input type="hidden" name="do_not_call_client_phone" value="0" />
                        <label><input type="checkbox" name="do_not_call_client_phone" id="do_not_call_client_phone" value="1" <?php checked($client['do_not_call_client_phone'] ?? '0', '1'); ?> /> <?php esc_html_e('Call alternate contact instead', 'meals-db'); ?></label>
                    </td>
                </tr>
                <?php
            },
            '__after' => static function () use ($alt_contact_enabled, $alt_contact_name, $alt_contact_first, $alt_contact_last, $form_values) {
                ?>
                <h4><?php esc_html_e('Alternate Contact', 'meals-db'); ?></h4>
                <p><label><input type="checkbox" id="alternate-contact-toggle" <?php checked($alt_contact_enabled); ?> /> <?php esc_html_e('Add alternate contact', 'meals-db'); ?></label></p>
                <div id="alternate-contact-fields" class="mealsdb-collapsible" <?php if (!$alt_contact_enabled) { echo 'style="display:none;"'; } ?>>
                    <input type="hidden" name="alt_contact_name" id="alt_contact_name" value="<?php echo esc_attr($alt_contact_name); ?>" />
                    <table class="form-table">
                        <tr>
                            <th><label for="alt_contact_first_name"><?php esc_html_e('First Name', 'meals-db'); ?></label></th>
                            <td><input type="text" id="alt_contact_first_name" class="regular-text" value="<?php echo esc_attr($alt_contact_first); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="alt_contact_last_name"><?php esc_html_e('Last Name', 'meals-db'); ?></label></th>
                            <td><input type="text" id="alt_contact_last_name" class="regular-text" value="<?php echo esc_attr($alt_contact_last); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="alt_contact_phone_primary"><?php esc_html_e('Phone Number', 'meals-db'); ?></label></th>
                            <td><input type="text" name="alt_contact_phone_primary" id="alt_contact_phone_primary" class="regular-text phone-mask" placeholder="(555)-555-5555" value="<?php echo esc_attr($form_values['alt_contact_phone_primary'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="alt_contact_phone_secondary"><?php esc_html_e('Second Phone Number', 'meals-db'); ?></label></th>
                            <td><input type="text" name="alt_contact_phone_secondary" id="alt_contact_phone_secondary" class="regular-text phone-mask" placeholder="(555)-555-5555" value="<?php echo esc_attr($form_values['alt_contact_phone_secondary'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="alt_contact_email"><?php esc_html_e('Contact Email', 'meals-db'); ?></label></th>
                            <td><input type="email" name="alt_contact_email" id="alt_contact_email" class="regular-text" value="<?php echo esc_attr($form_values['alt_contact_email'] ?? ''); ?>" /></td>
                        </tr>
                    </table>
                </div>
                <?php
            },
        ];

        $address_fields = [
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_street_name"><?php esc_html_e('Address *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_street_name" id="address_street_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['address_street_name'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_city"><?php esc_html_e('City *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_city" id="address_city" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['address_city'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_province"><?php esc_html_e('Province *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_province" id="address_province" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['address_province'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_postal"><?php esc_html_e('Postal Code *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_postal" id="address_postal" class="regular-text postal-mask" maxlength="6" placeholder="A1A1A1" required data-base-required="1" value="<?php echo esc_attr($client['address_postal'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            '__after' => static function () use ($delivery_address_enabled, $form_values) {
                ?>
                <h4><?php esc_html_e('Delivery Address', 'meals-db'); ?></h4>
                <p><label><input type="checkbox" id="delivery-address-toggle" <?php checked($delivery_address_enabled); ?> /> <?php esc_html_e('Delivery address different from home address', 'meals-db'); ?></label></p>
                <div id="delivery-address-fields" class="mealsdb-collapsible" <?php if (!$delivery_address_enabled) { echo 'style="display:none;"'; } ?>>
                    <table class="form-table">
                        <tr>
                            <th><label for="delivery_address_street_name"><?php esc_html_e('Delivery Address', 'meals-db'); ?></label></th>
                            <td><input type="text" name="delivery_address_street_name" id="delivery_address_street_name" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_street_name'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="delivery_address_city"><?php esc_html_e('City', 'meals-db'); ?></label></th>
                            <td><input type="text" name="delivery_address_city" id="delivery_address_city" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_city'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="delivery_address_province"><?php esc_html_e('Province', 'meals-db'); ?></label></th>
                            <td><input type="text" name="delivery_address_province" id="delivery_address_province" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_province'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="delivery_address_postal"><?php esc_html_e('Postal Code', 'meals-db'); ?></label></th>
                            <td><input type="text" name="delivery_address_postal" id="delivery_address_postal" class="regular-text postal-mask" maxlength="6" placeholder="A1A1A1" value="<?php echo esc_attr($form_values['delivery_address_postal'] ?? ''); ?>" /></td>
                        </tr>
                    </table>
                </div>
                <?php
            },
        ];

        $service_delivery_fields = [
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="payment_method"><?php esc_html_e('Payment Method *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="payment_method" id="payment_method" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['payment_method'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="required_start_date"><?php esc_html_e('Required Start Date *', 'meals-db'); ?></label></th>
                    <td><input type="date" name="required_start_date" id="required_start_date" class="mealsdb-datepicker" required data-base-required="1" value="<?php echo esc_attr($client['required_start_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="delivery_fee"><?php esc_html_e('Delivery Fee', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_fee" id="delivery_fee" class="regular-text" value="<?php echo esc_attr($client['delivery_fee'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) use ($zone_schedule) {
                $zone = trim((string) ($client['delivery_area_name'] ?? ''));
                $cfg  = $zone_schedule[$zone] ?? null;
                if ($cfg !== null) {
                    $display = $cfg['day'] . ($cfg['label'] !== '' ? ' — ' . $cfg['label'] : '');
                } elseif ($zone !== '') {
                    $display = __('⚠ zone not in schedule', 'meals-db');
                } else {
                    $display = '—';
                }
                ?>
                <tr>
                    <th><?php esc_html_e('Delivery Day', 'meals-db'); ?></th>
                    <td>
                        <span id="mealsdb-zone-day-display"><?php echo esc_html($display); ?></span>
                        <p class="description"><?php esc_html_e('Determined by the delivery zone (Settings → Zone Delivery Schedule). Not directly editable.', 'meals-db'); ?></p>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) use ($zone_schedule) {
                $current = trim((string) ($client['delivery_area_name'] ?? ''));
                $known   = $current !== '' && isset($zone_schedule[$current]);
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_area_name"><?php esc_html_e('Delivery Area Name *', 'meals-db'); ?></label></th>
                    <td>
                        <select name="delivery_area_name" id="delivery_area_name" class="regular-text" required data-base-required="1">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <?php if ($current !== '' && !$known) : ?>
                                <?php // Legacy value not in the schedule: keep it selected-but-flagged so an
                                      // untouched record isn't corrupted, but editing forces a real choice. ?>
                                <option value="<?php echo esc_attr($current); ?>" selected>⚠ <?php echo esc_html($current); ?> <?php esc_html_e('(not in schedule)', 'meals-db'); ?></option>
                            <?php endif; ?>
                            <?php foreach (array_keys($zone_schedule) as $zone_name) : ?>
                                <option value="<?php echo esc_attr($zone_name); ?>" <?php selected($current, $zone_name); ?>><?php echo esc_html($zone_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($current !== '' && !$known) : ?>
                            <p class="description" style="color:#b32d2e;">
                                <?php esc_html_e('This zone is no longer in the delivery schedule. Select a current zone before saving — the form will not save with the old value.', 'meals-db'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_area_zone"><?php esc_html_e('Delivery Area Zone *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_area_zone" id="delivery_area_zone" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['delivery_area_zone'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) use ($ordering_contact_method_options, $format_enum_option_label, $ordering_contact_method_value) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="ordering_contact_method"><?php esc_html_e('Ordering Contact Method *', 'meals-db'); ?></label></th>
                    <td>
                        <select name="ordering_contact_method" id="ordering_contact_method" required data-base-required="1">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <?php foreach ($ordering_contact_method_options as $option) : ?>
                                <?php $label = $format_enum_option_label($option); ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($ordering_contact_method_value, strtoupper($option)); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="ordering_frequency"><?php esc_html_e('Ordering Frequency *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="ordering_frequency" id="ordering_frequency" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['ordering_frequency'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_frequency"><?php esc_html_e('Delivery Frequency *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_frequency" id="delivery_frequency" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['delivery_frequency'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
        ];

        $case_management_fields = [
            // Typed-array form: render_field_group emits each entry as
            // name="esc_attr(value)". The bare-string form is no longer
            // accepted (kses does not sanitise a bare attribute fragment).
            '__attributes' => ['data-client-type' => 'sdnb,veteran'],
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="assigned_social_worker"><?php esc_html_e('Social Worker Name', 'meals-db'); ?></label></th>
                    <td><input type="text" name="assigned_social_worker" id="assigned_social_worker" class="regular-text" value="<?php echo esc_attr($client['assigned_social_worker'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="social_worker_email"><?php esc_html_e('Social Worker Email Address', 'meals-db'); ?></label></th>
                    <td><input type="email" name="social_worker_email" id="social_worker_email" class="regular-text" value="<?php echo esc_attr($client['social_worker_email'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) use ($requisition_period_value) {
                ?>
                <tr>
                    <th><label for="requisition_period"><?php esc_html_e('Requisition Period', 'meals-db'); ?></label></th>
                    <td>
                        <select name="requisition_period" id="requisition_period">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <option value="day" <?php selected(strtolower($requisition_period_value), 'day'); ?>><?php esc_html_e('Daily', 'meals-db'); ?></option>
                            <option value="week" <?php selected(strtolower($requisition_period_value), 'week'); ?>><?php esc_html_e('Weekly', 'meals-db'); ?></option>
                            <option value="month" <?php selected(strtolower($requisition_period_value), 'month'); ?>><?php esc_html_e('Monthly', 'meals-db'); ?></option>
                        </select>
                    </td>
                </tr>
                <?php
            },
        ];

        $requisition_fields = [
            '__attributes' => ['data-client-type' => 'sdnb'],
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="service_commence_date"><?php esc_html_e('Service Commence Date', 'meals-db'); ?></label></th>
                    <td><input type="date" name="service_commence_date" id="service_commence_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['service_commence_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="termination_date"><?php esc_html_e('Termination Date', 'meals-db'); ?></label></th>
                    <td><input type="date" name="termination_date" id="termination_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['termination_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
        ];

        $sdnb_program_fields = [
            '__attributes' => ['data-client-type' => 'sdnb'],
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="use_legacy_billing"><?php esc_html_e('New Portal', 'meals-db'); ?></label></th>
                    <td>
                        <?php
                        // Drives the SDNB invoice-pipeline split (use_legacy_billing:
                        // 0 = new-portal CSV, 1 = legacy zone-based CSV). Checked
                        // means NEW PORTAL, so the checkbox posts '0' and the hidden
                        // input supplies '1' for the unchecked state — without it an
                        // unchecked box would post nothing and the column could never
                        // be switched back to legacy. New clients default to the new
                        // portal (operator decision 2026-07-30); the DB default of 1
                        // only covers rows that predate this field.
                        ?>
                        <input type="hidden" name="use_legacy_billing" value="1" />
                        <label><input type="checkbox" name="use_legacy_billing" id="use_legacy_billing" value="0" <?php checked($client['use_legacy_billing'] ?? '0', '0'); ?> /> <?php esc_html_e('Invoice through the new SDNB portal (unchecked = legacy zone-based invoice)', 'meals-db'); ?></label>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="client_contribution"><?php esc_html_e('Client Contributions', 'meals-db'); ?></label></th>
                    <td><input type="text" name="client_contribution" id="client_contribution" class="regular-text" value="<?php echo esc_attr($client['client_contribution'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="individual_id"><?php esc_html_e('Individual ID', 'meals-db'); ?></label></th>
                    <td><input type="text" name="individual_id" id="individual_id" class="regular-text" value="<?php echo esc_attr($client['individual_id'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="service_center_charged"><?php esc_html_e('Service Center Charged', 'meals-db'); ?></label></th>
                    <td><input type="text" name="service_center_charged" id="service_center_charged" class="regular-text" value="<?php echo esc_attr($client['service_center_charged'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="vendor_number"><?php esc_html_e('Vendor #', 'meals-db'); ?></label></th>
                    <td><input type="text" name="vendor_number" id="vendor_number" class="regular-text" value="<?php echo esc_attr($client['vendor_number'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="service_id"><?php esc_html_e('Service ID', 'meals-db'); ?></label></th>
                    <td><input type="text" name="service_id" id="service_id" class="regular-text" value="<?php echo esc_attr($client['service_id'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="requisition_id"><?php esc_html_e('Requisition ID', 'meals-db'); ?></label></th>
                    <td><input type="text" name="requisition_id" id="requisition_id" class="regular-text" value="<?php echo esc_attr($client['requisition_id'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
        ];

        $veteran_fields = [
            '__attributes' => ['data-client-type' => 'veteran'],
            static function (array $client) {
                ?>
                <tr data-required-for="veteran">
                    <th><label for="vet_health_card"><?php esc_html_e('Veteran Health Identification Card # *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="vet_health_card" id="vet_health_card" class="regular-text" data-base-required="1" value="<?php echo esc_attr($client['vet_health_card'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
        ];

        $delivery_notes_fields = [
            static function (array $client) use ($delivery_initials_value) {
                ?>
                <tr data-client-type="sdnb,veteran,private" data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_initials"><?php esc_html_e('Initials for Delivery *', 'meals-db'); ?></label></th>
                    <td>
                        <div class="mealsdb-initials-tools">
                            <input type="text" name="delivery_initials" id="delivery_initials" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($delivery_initials_value); ?>" />
                            <div class="mealsdb-initials-buttons">
                                <button type="button" class="button mealsdb-initials-generate" id="mealsdb-generate-initials"><?php esc_html_e('Generate', 'meals-db'); ?></button>
                                <button type="button" class="button mealsdb-initials-validate" id="mealsdb-validate-initials"><?php esc_html_e('Validate', 'meals-db'); ?></button>
                            </div>
                            <div id="initials-validation-status"></div>
                            <div class="mealsdb-initials-message" aria-live="polite"></div>
                        </div>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="diet_concerns"><?php esc_html_e('Dietary Concerns', 'meals-db'); ?></label></th>
                    <td><textarea name="diet_concerns" id="diet_concerns" rows="4" class="large-text"><?php echo esc_textarea($client['diet_concerns'] ?? ''); ?></textarea></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="client_comments"><?php esc_html_e('Customer Comments', 'meals-db'); ?></label></th>
                    <td><textarea name="client_comments" id="client_comments" rows="4" class="large-text"><?php echo esc_textarea($client['client_comments'] ?? ''); ?></textarea></td>
                </tr>
                <?php
            },
            '__after' => static function () use ($submit_label, $show_draft_button) {
                ?>
                <div class="mealsdb-form-actions">
                    <button type="submit" class="button button-primary"><?php echo esc_html($submit_label); ?></button>
                    <?php if ($show_draft_button) : ?>
                        <button type="button" id="mealsdb-save-draft" class="button button-secondary"><?php esc_html_e('Save to Draft', 'meals-db'); ?></button>
                    <?php endif; ?>
                </div>
                <?php
            },
        ];
        ?>
        <form method="post" id="mealsdb-client-form" class="<?php echo esc_attr($form_class_attr); ?>">
            <?php wp_nonce_field('mealsdb_nonce', 'mealsdb_nonce_field'); ?>
            <?php if ($client_id > 0 && $form_mode === 'edit') : ?>
                <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>" />
            <?php endif; ?>

            <?php if ($show_draft_button && $resumed_draft_id > 0) : ?>
                <input type="hidden" name="draft_id" value="<?php echo esc_attr($resumed_draft_id); ?>" />
            <?php endif; ?>

            <div class="mealsdb-form-columns">
                <div class="mealsdb-column col-1">
                    <?php
                    self::render_field_group(__('Identity', 'meals-db'), $identity_fields, $client);
                    self::render_field_group(__('Contact Information', 'meals-db'), $contact_fields, $client);
                    ?>
                </div>

                <div class="mealsdb-column col-2">
                    <?php
                    self::render_field_group(__('Address', 'meals-db'), $address_fields, $client);
                    self::render_field_group(__('Service & Delivery', 'meals-db'), $service_delivery_fields, $client);
                    self::render_field_group(__('Case Management', 'meals-db'), $case_management_fields, $client);
                    self::render_field_group(__('Requisition Details (SDNB)', 'meals-db'), $requisition_fields, $client);
                    ?>
                </div>

                <div class="mealsdb-column col-3">
                    <?php
                    self::render_field_group(__('SDNB Program Details', 'meals-db'), $sdnb_program_fields, $client);
                    self::render_field_group(__('Veteran Details', 'meals-db'), $veteran_fields, $client);
                    self::render_field_group(__('Delivery Initials & Notes', 'meals-db'), $delivery_notes_fields, $client);
                    ?>
                </div>
            </div>
        </form>

        <?php if ($form_mode === 'edit' && $client_id > 0) : ?>
            <div id="mealsdb-client-allocation-history" style="margin-top: 20px;">
                <h3><?php esc_html_e('Allocation History', 'meals-db'); ?></h3>
                <table class="wp-list-table widefat fixed striped" id="mealsdb-allocation-history-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Month', 'meals-db'); ?></th>
                            <th><?php esc_html_e('Mains Allowed', 'meals-db'); ?></th>
                            <th><?php esc_html_e('Mains Used', 'meals-db'); ?></th>
                            <th><?php esc_html_e('Mains Overage', 'meals-db'); ?></th>
                            <th><?php esc_html_e('Sides Allowed', 'meals-db'); ?></th>
                            <th><?php esc_html_e('Sides Used', 'meals-db'); ?></th>
                            <th><?php esc_html_e('Sides Overage', 'meals-db'); ?></th>
                            <th><?php esc_html_e('Status', 'meals-db'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="8"><?php esc_html_e('Loading...', 'meals-db'); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <?php
            // The allocation-history widget JS lives in
            // assets/js/client-allocation-history.js and is enqueued by
            // self::enqueue_assets() on the edit-client view. Config and
            // i18n strings travel via window.mealsdbAllocationHistory.
            ?>
        <?php endif; ?>
        <?php
        // Zone→day map for the live read-only display (client-zone-day.js).
        // JSON island per the plugin pattern — JSON_HEX_* makes it <script>-safe.
        echo '<script type="application/json" id="mealsdb-zone-day-data">'
            . wp_json_encode($zone_schedule, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . '</script>';
    }

    /**
     * Render a list of probable WordPress user matches for an unlinked Meals DB client.
     *
     * @param array<string, mixed> $client
     */
    public static function render_unlinked_client_matches(array $client): void
    {
        echo '<div class="mealsdb-possible-matches">';

        if (!class_exists('MealsDB_Sync')) {
            echo '<p class="description">' . esc_html__('No likely matches', 'meals-db') . '</p>';
            echo '</div>';
            return;
        }

        $matches = MealsDB_Sync::find_probable_matches_for_client($client);

        if (empty($matches)) {
            echo '<p class="description">' . esc_html__('No likely matches', 'meals-db') . '</p>';
            echo '</div>';
            return;
        }

        $match_count = count($matches);
        echo '<h4>';
        printf(
            esc_html__('Possible Matches Found (%d)', 'meals-db'),
            $match_count
        );
        echo '</h4>';

        echo '<table class="widefat fixed striped mealsdb-matches-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Email', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Phone', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Confidence', 'meals-db') . '</th>';
        echo '<th>' . esc_html__('Actions', 'meals-db') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $client_id = isset($client['id']) ? (int) $client['id'] : 0;

        foreach ($matches as $match) {
            $wp_user_id = isset($match['wp_user_id']) ? (int) $match['wp_user_id'] : 0;
            $wp_user = is_array($match['wp_user'] ?? null) ? $match['wp_user'] : [];

            $first = trim((string) ($wp_user['first_name'] ?? ''));
            $last = trim((string) ($wp_user['last_name'] ?? ''));
            $display_name = trim($first . ' ' . $last);

            if ($display_name === '' && !empty($wp_user['display_name'])) {
                $display_name = (string) $wp_user['display_name'];
            }

            if ($display_name === '') {
                $display_name = sprintf(__('User #%d', 'meals-db'), $wp_user_id);
            }

            $email = (string) ($wp_user['email'] ?? '');
            $phone = (string) ($wp_user['phone'] ?? '');
            $score = isset($match['score']) ? (int) $match['score'] : 0;
            $score = max(0, min(200, $score));
            $confidence = round(($score / 200) * 100);

            echo '<tr>';
            echo '<td>' . esc_html($display_name) . '</td>';
            echo '<td>' . ($email !== '' ? esc_html($email) : '&mdash;') . '</td>';
            echo '<td>' . ($phone !== '' ? esc_html($phone) : '&mdash;') . '</td>';
            echo '<td>' . esc_html($confidence . '%') . '</td>';
            echo '<td>';
            if ($client_id > 0 && $wp_user_id > 0) {
                $button_attrs = sprintf(
                    'class="button button-secondary mealsdb-link-user" data-client-id="%d" data-wp-user-id="%d" data-wp-user-name="%s"',
                    $client_id,
                    $wp_user_id,
                    esc_attr($display_name)
                );
                echo '<button type="button" ' . $button_attrs . '>' . esc_html__('Link to This User', 'meals-db') . '</button>';
            } else {
                echo '<span class="description">' . esc_html__('Link unavailable', 'meals-db') . '</span>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    /**
     * Retrieve the Quick Order tax settings.
     */
    private function get_quick_order_tax_settings(): array
    {
        $settings = [
            'rate'          => $this->resolve_quick_order_tax_rate(),
            'taxable_types' => ['PRIVATE'],
        ];

        if (function_exists('apply_filters')) {
            $settings = apply_filters('mealsdb_quick_order_tax_settings', $settings);
        }

        $rate = isset($settings['rate']) ? (float) $settings['rate'] : 0.0;
        if ($rate < 0) {
            $rate = 0.0;
        }

        if ($rate > 1 && $rate <= 100) {
            $rate /= 100;
        }

        if ($rate > 1) {
            $rate = 1.0;
        }

        $taxable_types = $settings['taxable_types'] ?? ['PRIVATE'];
        if (!is_array($taxable_types)) {
            $taxable_types = [];
        }

        $normalised_types = [];
        foreach ($taxable_types as $type) {
            $clean = $this->sanitise_client_type_value($type);
            if ($clean !== '') {
                $normalised_types[] = strtoupper($clean);
            }
        }

        if (empty($normalised_types)) {
            $normalised_types = ['PRIVATE'];
        } else {
            $normalised_types = array_values(array_unique($normalised_types));
        }

        return [
            'rate'          => $rate,
            'taxable_types' => $normalised_types,
        ];
    }

    /**
     * Determine the WooCommerce base tax rate for Quick Order calculations.
     */
    private function resolve_quick_order_tax_rate(): float
    {
        if (!class_exists('WC_Tax')) {
            return 0.0;
        }

        try {
            $rates = \WC_Tax::get_rates('');
            if (!is_array($rates) || empty($rates)) {
                return 0.0;
            }

            $first_rate = reset($rates);
            if (!is_array($first_rate) || !isset($first_rate['rate'])) {
                return 0.0;
            }

            $rate = (float) $first_rate['rate'];
            return $rate > 0 ? $rate / 100 : 0.0;
        } catch (Throwable $e) {
            error_log('[MealsDB Admin UI] Failed reading WC tax rate: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Determine the client type to seed into the Quick Order UI.
     */
    private function get_quick_order_client_type(): string
    {
        $client_type = '';

        if (isset($_GET['client_type'])) {
            $client_type = $this->sanitise_client_type_value(wp_unslash($_GET['client_type']));
        }

        $client_id = isset($_GET['client_id']) ? absint($_GET['client_id']) : 0;

        if ($client_type === '' && $client_id <= 0) {
            $clone_order_id = MealsDB_Quick_Order_UI::get_requested_clone_order_id();
            if ($clone_order_id > 0 && function_exists('wc_get_order')) {
                $order = wc_get_order($clone_order_id);
                if ($order instanceof WC_Order) {
                    $meta_client_id = (int) $order->get_meta('mealsdb_client_id');
                    if ($meta_client_id > 0) {
                        $client_id = $meta_client_id;
                    }
                }
            }
        }

        if ($client_type === '' && $client_id > 0) {
            $client_type = $this->fetch_quick_order_client_type($client_id);
        }

        if (function_exists('apply_filters')) {
            $client_type = apply_filters('mealsdb_quick_order_client_type', $client_type, $client_id);
        }

        return $this->sanitise_client_type_value($client_type);
    }

    /**
     * Fetch the client type from Meals DB for the given client ID.
     */
    private function fetch_quick_order_client_type(int $client_id): string
    {
        if ($client_id <= 0 || !class_exists('MealsDB_Clients_Repository')) {
            return '';
        }

        try {
            $repository = new MealsDB_Clients_Repository();
            $record      = $repository->get_client_by_id($client_id);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[MealsDB Admin UI] fetch_quick_order_client_type(%d) failed: %s',
                $client_id,
                $e->getMessage()
            ));
            return '';
        }

        if (!is_array($record)) {
            return '';
        }

        $type = $record['client_type'] ?? ($record['customer_type'] ?? '');

        return $this->sanitise_client_type_value($type);
    }

    /**
     * Normalise client type strings for downstream use.
     *
     * @param mixed $value Raw client type value.
     */
    private function sanitise_client_type_value($value): string
    {
        if ($value === null) {
            return '';
        }

        if (!is_string($value)) {
            $value = (string) $value;
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('sanitize_text_field')) {
            $value = sanitize_text_field($value);
        } else {
            $value = preg_replace('/[^A-Za-z0-9 _\-]/', '', $value);
        }

        return $value;
    }

    /**
     * Render a field group within the client form.
     *
     * @param string $group_name
     * @param array  $fields
     * @param array  $client
     */
    private static function render_field_group(string $group_name, array $fields, array $client): void
    {
        if (empty($fields)) {
            return;
        }

        $before     = $fields['__before'] ?? '';
        $after      = $fields['__after'] ?? '';
        $attributes = $fields['__attributes'] ?? '';
        unset($fields['__before'], $fields['__after'], $fields['__attributes']);

        // __attributes MUST be the typed-array form (e.g.
        // ['data-client-type' => 'sdnb']). Each entry is emitted as
        // name="esc_attr(value)" with the name reduced to an HTML-attribute
        // charset, so a caller can never break out of the attribute or inject
        // an event handler through a value.
        //
        // A free-form STRING is deliberately NOT rendered. The previous code
        // ran the string through wp_kses_data and claimed that made it safe
        // for "future callers that might feed user-controlled data through" —
        // that claim was WRONG. wp_kses_data only filters <tag> constructs; a
        // bare attribute fragment like onmouseover="alert(1)" contains no tag,
        // passes through unchanged, and would be concatenated live into the
        // <div> below. All in-file callers now pass the array form, so any
        // non-array value is dropped rather than echoed unsanitised.
        if (is_array($attributes)) {
            $attributes_html = '';
            foreach ($attributes as $attr_name => $attr_value) {
                if (!is_string($attr_name) || $attr_name === '') {
                    continue;
                }
                $attr_name = preg_replace('/[^A-Za-z0-9_:-]/', '', $attr_name);
                if ($attr_name === '') {
                    continue;
                }
                $attributes_html .= ' ' . $attr_name . '="' . esc_attr((string) $attr_value) . '"';
            }
            $attributes = $attributes_html;
        } else {
            $attributes = '';
        }

        echo '<div class="mealsdb-section"' . $attributes . '>';
        echo '<h3>' . esc_html($group_name) . '</h3>';

        // Accept only callables for __before/__after renderers. Strings
        // are rejected rather than echoed verbatim; rendering hand-built
        // HTML is the caller's job to do safely.
        if (is_callable($before)) {
            $before($client);
        }

        echo '<table class="form-table">';
        foreach ($fields as $field_renderer) {
            if (is_callable($field_renderer)) {
                $field_renderer($client);
            }
            // Non-callable entries are silently skipped: if a caller
            // wants to inject raw HTML they must wrap it in a closure
            // so the XSS-safety review happens at the call site.
        }
        echo '</table>';

        if (is_callable($after)) {
            $after($client);
        }

        echo '</div>';
    }
}
