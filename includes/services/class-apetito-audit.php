<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Read-only drift audit: compares stored meals_products fields against the
 * current Apetito page. NO writes. The walk is a resumable cursor driven by the
 * AJAX layer, one code per call, cache-backed — never a burst of 163 requests.
 */
class MealsDB_Apetito_Audit {

    /**
     * Pure: fields that differ between the stored row and freshly parsed values.
     * @return array<int, array{field:string,stored:mixed,apetito:mixed}>
     */
    public static function diff(array $stored, array $parsed): array {
        $out = [];

        $stored_case = (int) ($stored['case_size'] ?? 0);
        $live_case   = (int) ($parsed['portions_per_case'] ?? 0);
        if ($live_case > 0 && $live_case !== $stored_case) {
            $out[] = ['field' => 'case_size', 'stored' => $stored_case, 'apetito' => $live_case];
        }

        $sa = self::norm_set($stored['allergen_flags'] ?? []);
        $la = self::norm_set($parsed['allergens'] ?? []);
        if ($sa !== $la) {
            $out[] = ['field' => 'allergen_flags', 'stored' => $sa, 'apetito' => $la];
        }

        $sd = self::norm_set($stored['dietary_tags'] ?? []);
        $ld = self::norm_set($parsed['diet_tags'] ?? []);
        if ($sd !== $ld) {
            $out[] = ['field' => 'dietary_tags', 'stored' => $sd, 'apetito' => $ld];
        }

        return $out;
    }

    /** Order-insensitive, de-duplicated string set for comparison. */
    private static function norm_set($value): array {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) { $value = []; }
        $value = array_values(array_unique(array_map('strval', $value)));
        sort($value);
        return $value;
    }

    /**
     * The ordered list of Apetito codes to audit (published products whose SKU
     * is a 5-digit code). The AJAX layer walks this by offset, one per call.
     * @return string[]
     */
    public static function codes(): array {
        global $wpdb;
        if (!$wpdb) { return []; }
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);
        $rows = $wpdb->get_col("SELECT sku FROM `{$table}` WHERE sku REGEXP '^[0-9]{5}$' ORDER BY sku ASC");
        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    /**
     * Audit ONE code (used per cursor tick). Cache-backed fetch; never writes.
     * @return array{code:string,ok:bool,drift?:array,reason?:string}
     */
    public static function audit_one(string $code): array {
        $res = MealsDB_Apetito_Nutridata::fetch($code);
        if (empty($res['ok'])) {
            return ['code' => $code, 'ok' => false, 'reason' => $res['reason']];
        }
        $parsed = MealsDB_Apetito_Parser::parse($res['html'], $code);
        $values = [];
        foreach ($parsed['fields'] as $k => $f) { if (!empty($f['ok'])) { $values[$k] = $f['value']; } }

        $existing = MealsDB_Apetito_Product_Creator::find_by_sku($code);
        $row = $existing ? MealsDB_Products::get_product_data((int) $existing['wc_product_id']) : [];
        return ['code' => $code, 'ok' => true, 'drift' => self::diff($row, $values)];
    }
}
