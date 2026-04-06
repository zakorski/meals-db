<?php
/**
 * Helper for loading product metadata from the external Meals DB.
 */

class MealsDB_Products_Loader {

    /**
     * @deprecated No longer used. Products are now loaded per-category via
     *             MealsDB_Quick_Order_Products::get_products_by_category().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function load_all_products(): array {
        return [];
    }

    /**
     * Determine whether a client can order a product based on allergens.
     */
    public static function client_can_order(array $client_allergens, array $product_allergens): bool {
        $client = array_filter(array_map('strval', $client_allergens));
        $product = array_filter(array_map('strval', $product_allergens));

        if (empty($client) || empty($product)) {
            return true;
        }

        return empty(array_intersect($client, $product));
    }

    /**
     * Fetch product metadata for multiple product IDs in a single query.
     *
     * @param array<int, int> $product_ids
     *
     * @return array<int, array<string, mixed>> Keyed by product ID.
     */
    public static function batch_get_product_data(array $product_ids): array {
        if (empty($product_ids)) {
            return [];
        }

        $conn = MealsDB_DB::get_connection();
        if (!MealsDB_DB::is_mysqli($conn)) {
            return [];
        }

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $table = str_replace('`', '``', $table);

        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $sql = "SELECT wc_product_id, product_type, taxable, main_ingredient, dietary_tags, allergen_flags, case_size, unit_cost, last_updated FROM `{$table}` WHERE wc_product_id IN ({$placeholders})";

        $stmt = $conn->prepare($sql);
        if (!MealsDB_DB::is_mysqli_stmt($stmt)) {
            return [];
        }

        $types = str_repeat('i', count($product_ids));
        $stmt->bind_param($types, ...$product_ids);

        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }

        $rows = [];

        if (method_exists($stmt, 'get_result')) {
            $result = $stmt->get_result();
            if (MealsDB_DB::is_mysqli_result($result)) {
                while ($row = $result->fetch_assoc()) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $id = (int) $row['wc_product_id'];
                    $rows[$id] = [
                        'wc_product_id'   => $id,
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

                $result->free();
            }
        }

        $stmt->close();

        return $rows;
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

}
