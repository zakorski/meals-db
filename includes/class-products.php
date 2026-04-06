<?php
/**
 * Manage Meals DB product metadata stored in the external database.
 */

class MealsDB_Products {
    private const TABLE = MealsDB_Tables::PRODUCTS;

    /**
     * Create the meals_products table within the external Meals DB.
     */
    public static function install_table(): void {
        $conn = MealsDB_DB::get_connection();

        if (!MealsDB_DB::is_mysqli($conn)) {
            error_log('[MealsDB Products] Unable to establish database connection.');
            return;
        }

        $table = MealsDB_DB::get_table_name(self::TABLE);
        $table = str_replace('`', '``', $table);

        $charset_sql = 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $charset   = $conn->character_set_name();
        $collation = method_exists($conn, 'get_charset') ? $conn->get_charset() : null;

        if (!empty($charset)) {
            $collation_name = 'utf8mb4_unicode_ci';

            if (is_object($collation) && property_exists($collation, 'collation') && !empty($collation->collation)) {
                $collation_name = $collation->collation;
            }

            $charset_sql = sprintf('DEFAULT CHARSET=%s COLLATE=%s', $charset, $collation_name);
        }

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            wc_product_id INT NOT NULL UNIQUE,
            product_name VARCHAR(200) NOT NULL DEFAULT '',
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            image_url VARCHAR(500) NULL,
            sku VARCHAR(100) NULL,
            category_data JSON NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            product_type ENUM('meal','side') NOT NULL DEFAULT 'meal',
            taxable TINYINT(1) NOT NULL DEFAULT 0,
            main_ingredient VARCHAR(40) NOT NULL DEFAULT '',
            dietary_tags JSON NULL,
            allergen_flags JSON NULL,
            case_size INT NOT NULL DEFAULT 1,
            unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_is_published (is_published),
            KEY idx_product_name (product_name)
        ) ENGINE=InnoDB {$charset_sql};";

        if (!$conn->query($sql)) {
            error_log('[MealsDB Products] Failed creating ' . MealsDB_Tables::PRODUCTS . ' table: ' . $conn->error);
        }
    }

    /**
     * Retrieve product metadata for a WooCommerce product.
     *
     * @param int $product_id WooCommerce product ID.
     *
     * @return array<string, mixed>
     */
    public static function get_product_data(int $product_id): array {
        $defaults = self::get_default_row($product_id);

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return $defaults;
        }

        $table = MealsDB_DB::get_table_name(self::TABLE);
        $table = str_replace('`', '``', $table);

        $sql = "SELECT wc_product_id, product_type, taxable, main_ingredient, dietary_tags, allergen_flags, case_size, unit_cost, last_updated FROM `{$table}` WHERE wc_product_id = ? LIMIT 1";
        $stmt = $conn->prepare($sql);

        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return $defaults;
        }

        $stmt->bind_param('i', $product_id);

        if (!$stmt->execute()) {
            $stmt->close();
            return $defaults;
        }

        $row = null;

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if (MealsDB_DB::is_mysqli_result($result)) {
                $row = $result->fetch_assoc();
                $result->free();
            }
        } else {
            $statement_row = [
                'wc_product_id'   => null,
                'product_type'    => null,
                'taxable'         => null,
                'main_ingredient' => null,
                'dietary_tags'    => null,
                'allergen_flags'  => null,
                'case_size'       => null,
                'unit_cost'       => null,
                'last_updated'    => null,
            ];

            $bound = $stmt->bind_result(
                $statement_row['wc_product_id'],
                $statement_row['product_type'],
                $statement_row['taxable'],
                $statement_row['main_ingredient'],
                $statement_row['dietary_tags'],
                $statement_row['allergen_flags'],
                $statement_row['case_size'],
                $statement_row['unit_cost'],
                $statement_row['last_updated']
            );

            if ($bound && $stmt->fetch()) {
                $row = $statement_row;
            }
        }

        $stmt->close();

        if (!is_array($row)) {
            return $defaults;
        }

        return [
            'wc_product_id'   => (int) $row['wc_product_id'],
            'product_type'    => in_array($row['product_type'], ['meal', 'side'], true) ? (string) $row['product_type'] : 'meal',
            'taxable'         => (int) $row['taxable'],
            'main_ingredient' => (string) $row['main_ingredient'],
            'dietary_tags'    => self::decode_json_field($row['dietary_tags']),
            'allergen_flags'  => self::decode_json_field($row['allergen_flags']),
            'case_size'       => (int) $row['case_size'],
            'unit_cost'       => number_format((float) $row['unit_cost'], 2, '.', ''),
            'last_updated'    => $row['last_updated'],
        ];
    }

    /**
     * Save product metadata to the Meals DB.
     *
     * @param int   $product_id WooCommerce product ID.
     * @param array $data       Associative array of product data.
     *
     * @return bool True on success, false otherwise.
     */
    public static function save_product_data(int $product_id, array $data): bool {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return false;
        }

        $prepared = self::prepare_row_data($product_id, $data);

        $table = MealsDB_DB::get_table_name(self::TABLE);
        $table = str_replace('`', '``', $table);

        $sql = "INSERT INTO `{$table}` (wc_product_id, product_type, taxable, main_ingredient, dietary_tags, allergen_flags, case_size, unit_cost)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    product_type = VALUES(product_type),
                    taxable = VALUES(taxable),
                    main_ingredient = VALUES(main_ingredient),
                    dietary_tags = VALUES(dietary_tags),
                    allergen_flags = VALUES(allergen_flags),
                    case_size = VALUES(case_size),
                    unit_cost = VALUES(unit_cost)";

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return false;
        }

        $stmt->bind_param(
            'isisssid',
            $prepared['wc_product_id'],
            $prepared['product_type'],
            $prepared['taxable'],
            $prepared['main_ingredient'],
            $prepared['dietary_tags'],
            $prepared['allergen_flags'],
            $prepared['case_size'],
            $prepared['unit_cost']
        );

        $result = $stmt->execute();
        $stmt->close();

        return (bool) $result;
    }

    /**
     * Get default product data structure for a WooCommerce product.
     *
     * @param int $product_id
     *
     * @return array<string, mixed>
     */
    private static function get_default_row(int $product_id): array {
        return [
            'wc_product_id'   => $product_id,
            'product_type'    => 'meal',
            'taxable'         => 0,
            'main_ingredient' => '',
            'dietary_tags'    => [],
            'allergen_flags'  => [],
            'case_size'       => 1,
            'unit_cost'       => '0.00',
            'last_updated'    => null,
        ];
    }

    /**
     * Prepare row data for insertion or update.
     *
     * @param int   $product_id
     * @param array $data
     *
     * @return array<string, mixed>
     */
    private static function prepare_row_data(int $product_id, array $data): array {
        $defaults = self::get_default_row($product_id);
        $merged   = array_merge($defaults, $data);

        $product_type = in_array($merged['product_type'], ['meal', 'side'], true)
            ? (string) $merged['product_type']
            : 'meal';

        $dietary_tags   = self::encode_json_field($merged['dietary_tags']);
        $allergen_flags = self::encode_json_field($merged['allergen_flags']);

        $taxable   = (int) (!empty($merged['taxable']));
        $case_size = isset($merged['case_size']) ? (int) $merged['case_size'] : 1;
        if ($case_size < 1) {
            $case_size = 1;
        }

        $unit_cost = isset($merged['unit_cost']) && is_numeric($merged['unit_cost'])
            ? (float) $merged['unit_cost']
            : 0.00;

        return [
            'wc_product_id'   => (int) $product_id,
            'product_type'    => $product_type,
            'taxable'         => $taxable,
            'main_ingredient' => isset($merged['main_ingredient']) ? (string) $merged['main_ingredient'] : '',
            'dietary_tags'    => $dietary_tags,
            'allergen_flags'  => $allergen_flags,
            'case_size'       => $case_size,
            'unit_cost'       => $unit_cost,
        ];
    }

    /**
     * Update display-only fields cached from WooCommerce.
     *
     * This upserts the row so it works even if the product has no prior meals_products entry.
     *
     * @param int                          $product_id WooCommerce product ID.
     * @param array<string, mixed>         $display    Display fields: product_name, price, image_url, sku, category_data, is_published.
     *
     * @return bool True on success.
     */
    public static function update_display_fields(int $product_id, array $display): bool {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return false;
        }

        $table = MealsDB_DB::get_table_name(self::TABLE);
        $table = str_replace('`', '``', $table);

        $product_name = isset($display['product_name']) ? mb_substr((string) $display['product_name'], 0, 200) : '';
        $price        = isset($display['price']) ? (float) $display['price'] : 0.00;
        $image_url    = isset($display['image_url']) ? mb_substr((string) $display['image_url'], 0, 500) : null;
        $sku          = isset($display['sku']) ? mb_substr((string) $display['sku'], 0, 100) : null;
        $is_published = isset($display['is_published']) ? (int) (bool) $display['is_published'] : 1;

        $category_data = null;
        if (isset($display['category_data']) && is_array($display['category_data'])) {
            $category_data = json_encode($display['category_data']);
        }

        $sql = "INSERT INTO `{$table}` (wc_product_id, product_name, price, image_url, sku, category_data, is_published, product_type, taxable, main_ingredient, case_size, buffer, unit_cost)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'meal', 0, '', 1, 0, 0.00)
                ON DUPLICATE KEY UPDATE
                    product_name = VALUES(product_name),
                    price = VALUES(price),
                    image_url = VALUES(image_url),
                    sku = VALUES(sku),
                    category_data = VALUES(category_data),
                    is_published = VALUES(is_published)";

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return false;
        }

        $stmt->bind_param(
            'isdsssi',
            $product_id,
            $product_name,
            $price,
            $image_url,
            $sku,
            $category_data,
            $is_published
        );

        $result = $stmt->execute();
        $stmt->close();

        return (bool) $result;
    }

    /**
     * Toggle the is_published flag for a product.
     *
     * @param int  $product_id   WooCommerce product ID.
     * @param bool $is_published Whether the product is published.
     *
     * @return bool True on success.
     */
    public static function set_published(int $product_id, bool $is_published): bool {
        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return false;
        }

        $table = MealsDB_DB::get_table_name(self::TABLE);
        $table = str_replace('`', '``', $table);

        $sql  = "UPDATE `{$table}` SET is_published = ? WHERE wc_product_id = ?";
        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return false;
        }

        $flag = $is_published ? 1 : 0;
        $stmt->bind_param('ii', $flag, $product_id);
        $result = $stmt->execute();
        $stmt->close();

        return (bool) $result;
    }

    /**
     * Decode a JSON field into an array.
     *
     * @param string|null $value
     *
     * @return array<int|string, mixed>
     */
    private static function decode_json_field($value): array {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Encode a value as JSON if it is an array, otherwise return null.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private static function encode_json_field($value): ?string {
        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
