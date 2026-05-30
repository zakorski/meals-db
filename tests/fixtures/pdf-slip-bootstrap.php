<?php
/**
 * Shared test bootstrap for the Phase T PDF slip tests.
 *
 * Sets up:
 *   - WP function stubs (defined()/option getters/has_term)
 *   - WC_Order / WC_Order_Item_Product stubs
 *   - A configurable wpdb stub that records calls and returns canned
 *     responses
 *   - A configurable MealsDB_Delivery_Slip_Generator stub so tests can
 *     hand a fixed clients/orders dataset to the PDF generator without
 *     touching the real query path
 *
 * Tests include this file once and then drive the generator directly.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

if (!defined('OBJECT')) { define('OBJECT', 'OBJECT'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!defined('MEALS_DB_KEY')) {
    define('MEALS_DB_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
}
if (!defined('MEALS_DB_PLUGIN_DIR')) {
    define('MEALS_DB_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
}

require_once dirname(__DIR__, 2) . '/includes/class-autoloader.php';
MealsDB_Autoloader::register(dirname(__DIR__, 2) . '/');

$_pdf_slip_composer = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (file_exists($_pdf_slip_composer)) {
    require_once $_pdf_slip_composer;
}
unset($_pdf_slip_composer);

// -----------------------------------------------------------------
// WP function stubs
// -----------------------------------------------------------------

if (!isset($GLOBALS['_pdf_slip_options'])) {
    $GLOBALS['_pdf_slip_options'] = [];
}
if (!isset($GLOBALS['_pdf_slip_terms'])) {
    // Map: product_id => [ category_term_id, ... ]
    $GLOBALS['_pdf_slip_terms'] = [];
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['_pdf_slip_options'][$name] ?? $default;
    }
}

if (!function_exists('has_term')) {
    function has_term($term, $taxonomy, $object_id = null) {
        $terms = $GLOBALS['_pdf_slip_terms'][$object_id] ?? [];
        return in_array((int) $term, array_map('intval', $terms), true);
    }
}

// -----------------------------------------------------------------
// wpdb stub
// -----------------------------------------------------------------

if (!class_exists('wpdb')) {
    class wpdb {
        public string $prefix = 'wp_';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';

        /** @var callable|null */
        public $get_var_handler = null;

        /** @var callable|null */
        public $get_results_handler = null;

        public function prepare($query, ...$args) {
            // Stash the raw query so handlers can inspect it.
            $this->_last_query = $query;
            $this->_last_args  = $args;
            return $query;
        }

        public function get_var($query, $x = 0, $y = 0) {
            if (is_callable($this->get_var_handler)) {
                return ($this->get_var_handler)($query, $this->_last_args ?? []);
            }
            return null;
        }

        public function get_results($query, $output = OBJECT) {
            if (is_callable($this->get_results_handler)) {
                return ($this->get_results_handler)($query, $this->_last_args ?? []);
            }
            return [];
        }

        public function query($query) { return 0; }
        public function get_row($query, $output = OBJECT) { return null; }
        public function get_col($query, $x = 0) { return []; }

        public $_last_query = '';
        public array $_last_args = [];
    }
}

// -----------------------------------------------------------------
// WC_Order / WC_Order_Item_Product stubs
// -----------------------------------------------------------------

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product {
        public int $product_id;
        public string $name;
        public int $quantity;
        public float $subtotal;
        public function __construct(int $pid, string $name, int $qty, float $sub) {
            $this->product_id = $pid;
            $this->name = $name;
            $this->quantity = $qty;
            $this->subtotal = $sub;
        }
        public function get_product_id(): int { return $this->product_id; }
        public function get_name(): string { return $this->name; }
        public function get_quantity(): int { return $this->quantity; }
        public function get_subtotal(): float { return $this->subtotal; }
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order {
        public int $id = 0;
        public array $items = [];
        public float $subtotal = 0.0;
        public float $tax = 0.0;
        public float $total = 0.0;
        public string $payment_method = 'cash';
        public string $customer_note = '';
        public array $meta = [];
        public ?string $display_number = null;

        public function add(WC_Order_Item_Product $item): void { $this->items[] = $item; }
        public function get_items(): array { return $this->items; }
        public function get_subtotal(): float { return $this->subtotal; }
        public function get_total_tax(): float { return $this->tax; }
        public function get_total(): float { return $this->total; }
        public function get_payment_method(): string { return $this->payment_method; }
        public function get_customer_note(): string { return $this->customer_note; }
        public function get_meta($key, $single = false) { return $this->meta[$key] ?? ''; }
        public function get_date_created() { return null; }
        public function get_order_number(): string {
            return $this->display_number !== null ? $this->display_number : (string) $this->id;
        }
    }
}

if (!isset($GLOBALS['_pdf_slip_wc_orders'])) {
    $GLOBALS['_pdf_slip_wc_orders'] = [];
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($id) {
        $id = (int) $id;
        return $GLOBALS['_pdf_slip_wc_orders'][$id] ?? null;
    }
}

// -----------------------------------------------------------------
// Fake MealsDB_Delivery_Slip_Generator subclass for tests.
// -----------------------------------------------------------------

if (!class_exists('PdfSlipFakeClientQuery')) {
    class PdfSlipFakeClientQuery extends MealsDB_Delivery_Slip_Generator {
        public array $clients_for_date = [];
        public array $clients_for_driver = [];
        public array $clients_for_zones = [];
        public array $clients_for_zones_driver = [];
        public array $orders = [];

        public function __construct() {
            // Skip parent constructor — no real wpdb wiring needed.
        }

        public function get_clients_for_delivery_date(string $delivery_date): array {
            return $this->clients_for_date;
        }
        public function get_clients_for_driver_slips(string $delivery_date): array {
            return $this->clients_for_driver;
        }
        public function get_clients_for_zones(array $zone_names): array {
            return $this->clients_for_zones;
        }
        public function get_clients_for_zones_driver(array $zone_names): array {
            return $this->clients_for_zones_driver;
        }
        public function get_orders_for_date(array $wp_user_ids, string $delivery_date): array {
            return $this->orders;
        }
        public function get_orders_for_delivery_date(array $clients, string $delivery_date): array {
            // Tests hand a fixed dataset; the delivery-basis filtering itself
            // is covered by tests/test-slips-delivery-date.php.
            return $this->orders;
        }
        public function get_orders_for_range(array $wp_user_ids, string $start_date, string $end_date): array {
            return $this->orders;
        }
    }
}

// -----------------------------------------------------------------
// Tiny assert helpers shared by every test.
// -----------------------------------------------------------------

if (!isset($GLOBALS['_pdf_slip_failures'])) {
    $GLOBALS['_pdf_slip_failures'] = [];
    $GLOBALS['_pdf_slip_passed'] = 0;
}

if (!function_exists('pdf_slip_assert_equal')) {
    function pdf_slip_assert_equal($expected, $actual, string $label) {
        if ($expected === $actual) {
            $GLOBALS['_pdf_slip_passed']++;
            return;
        }
        $GLOBALS['_pdf_slip_failures'][] = sprintf(
            "FAIL: %s\n  expected: %s\n  actual:   %s",
            $label,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

if (!function_exists('pdf_slip_assert_true')) {
    function pdf_slip_assert_true($actual, string $label) {
        pdf_slip_assert_equal(true, (bool) $actual, $label);
    }
}

if (!function_exists('pdf_slip_finish')) {
    function pdf_slip_finish() {
        if (!empty($GLOBALS['_pdf_slip_failures'])) {
            fwrite(STDERR, "\n" . implode("\n", $GLOBALS['_pdf_slip_failures']) . "\n\n");
            fwrite(STDERR, sprintf("%d passed, %d failed\n",
                $GLOBALS['_pdf_slip_passed'],
                count($GLOBALS['_pdf_slip_failures'])
            ));
            exit(1);
        }
        fwrite(STDOUT, sprintf("%d passed\n", $GLOBALS['_pdf_slip_passed']));
    }
}

/**
 * Build a slip via the generator's private build_slips() so each test
 * can exercise a single contract without round-tripping the full
 * pipeline.
 *
 * @return array<int, array<string, mixed>>
 */
if (!function_exists('pdf_slip_build_slips')) {
    function pdf_slip_build_slips(array $orders, array $clients, bool $include_driver): array {
        $client_query = new PdfSlipFakeClientQuery();
        $generator = new MealsDB_Slip_PDF_Generator(
            $client_query,
            new MealsDB_Collection_Calculator()
        );
        $method = new ReflectionMethod(MealsDB_Slip_PDF_Generator::class, 'build_slips');
        $method->setAccessible(true);
        return $method->invoke($generator, $orders, $clients, $include_driver);
    }
}

/**
 * Reset stub state between tests if a test file re-uses globals.
 */
if (!function_exists('pdf_slip_reset_globals')) {
    function pdf_slip_reset_globals(): void {
        $GLOBALS['_pdf_slip_options']   = [];
        $GLOBALS['_pdf_slip_terms']     = [];
        $GLOBALS['_pdf_slip_wc_orders'] = [];
    }
}

global $wpdb;
$wpdb = new wpdb();
