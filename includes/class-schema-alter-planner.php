<?php
/**
 * Schema ALTER planner — risk classifier for column drift (audit H7, slice 1).
 *
 * MealsDB_Schema_Sync detects column mismatches (expected canonical definition
 * vs the actual INFORMATION_SCHEMA row) but deliberately never issues
 * `ALTER ... MODIFY` — auto-rewriting a column type on a live billing DB is
 * riskier than the drift. This classifier is the first, DB-free step toward
 * safe automation: it tags each mismatch by RISK so the caller can auto-apply
 * value-preserving changes and route lossy/structural ones through an explicit
 * preview + typed-confirmation tool.
 *
 * Tiers (per the operator decisions in audit-2026-08/SCOPE-schema-sync-alter.md):
 *   - 'safe'  : value-preserving. Widen VARCHAR/CHAR/TEXT, INT -> BIGINT, add an
 *               ENUM value (superset), relax NOT NULL -> NULL, change a DEFAULT.
 *   - 'risky' : could lose data or fail, OR is money. Narrow, remove an ENUM
 *               value, tighten to NOT NULL, change type family, change signedness,
 *               and ANY DECIMAL/NUMERIC change (decision 4: money columns are
 *               always manual, even a widening).
 *
 * A NULL -> NOT NULL tightening is 'risky' with needs_null_check=true, so the
 * executor knows to run the "0 NULL rows" pre-flight before applying.
 *
 * Pure — no DB, no WP. Unit-tested in tests/test-schema-alter-planner.php.
 *
 * Author: Fishhorn Design
 * Licensed under the GNU General Public License v3.0 or later.
 */

defined('ABSPATH') || exit;

class MealsDB_Schema_Alter_Planner {

    public const TIER_SAFE  = 'safe';
    public const TIER_RISKY = 'risky';

    /** INT storage families, ordered by capacity (smaller number = narrower). */
    private const INT_SIZES = [
        'tinyint' => 1, 'smallint' => 2, 'mediumint' => 3,
        'int' => 4, 'integer' => 4, 'bigint' => 5,
    ];

    /** TEXT storage families, ordered by capacity. */
    private const TEXT_SIZES = [
        'tinytext' => 1, 'text' => 2, 'mediumtext' => 3, 'longtext' => 4,
    ];

    private const CHAR_TYPES = ['varchar', 'char', 'binary', 'varbinary'];

    /**
     * Classify a single column mismatch.
     *
     * @param string               $expected_definition Canonical column definition,
     *                                                   constraints already stripped
     *                                                   (e.g. "VARCHAR(80) NOT NULL DEFAULT ''").
     * @param array<string,mixed>  $actual_column       INFORMATION_SCHEMA row:
     *                                                   column_type, is_nullable, column_default, extra.
     * @return array{tier:string, reason:string, needs_null_check:bool}
     */
    public static function classify(string $expected_definition, array $actual_column): array {
        $expected = self::parse_expected($expected_definition);
        $actual   = self::parse_actual($actual_column);

        $reasons          = [];
        $tier             = self::TIER_SAFE;
        $needs_null_check = false;

        // --- type ---------------------------------------------------------
        $type_result = self::classify_type($expected['type'], $actual['type']);
        if ($type_result['tier'] === self::TIER_RISKY) {
            $tier = self::TIER_RISKY;
        }
        if ($type_result['reason'] !== '') {
            $reasons[] = $type_result['reason'];
        }

        // --- nullability --------------------------------------------------
        if ($actual['nullable'] && !$expected['nullable']) {
            // NULL -> NOT NULL: could fail if existing NULLs. Executor must probe.
            $tier             = self::TIER_RISKY;
            $needs_null_check = true;
            $reasons[]        = 'tightens NULL -> NOT NULL (needs a 0-NULL-rows check)';
        } elseif (!$actual['nullable'] && $expected['nullable']) {
            $reasons[] = 'relaxes NOT NULL -> NULL';
        }

        // --- auto_increment (structural) ----------------------------------
        if ($expected['auto_increment'] !== $actual['auto_increment']) {
            $tier      = self::TIER_RISKY;
            $reasons[] = 'changes AUTO_INCREMENT';
        }

        // --- default (metadata-only; never risky on its own) --------------
        if ($expected['default'] !== $actual['default']) {
            $reasons[] = 'changes DEFAULT';
        }

        if (empty($reasons)) {
            $reasons[] = 'no material change detected';
        }

        return [
            'tier'             => $tier,
            'reason'           => implode('; ', $reasons),
            'needs_null_check' => $needs_null_check,
        ];
    }

    /**
     * Classify a type change alone.
     *
     * @return array{tier:string, reason:string}
     */
    private static function classify_type(array $e, array $a): array {
        $eb = $e['base'];
        $ab = $a['base'];

        // Decision 4: any DECIMAL/NUMERIC change is money — always manual.
        if (in_array($eb, ['decimal', 'numeric'], true) || in_array($ab, ['decimal', 'numeric'], true)) {
            if ($eb !== $ab || $e['args'] !== $a['args']) {
                return ['tier' => self::TIER_RISKY, 'reason' => 'DECIMAL/money change (always manual)'];
            }
            return ['tier' => self::TIER_SAFE, 'reason' => ''];
        }

        // Same base family.
        if ($eb === $ab) {
            if (in_array($eb, self::CHAR_TYPES, true)) {
                $el = (int) $e['args'];
                $al = (int) $a['args'];
                if ($el < $al) {
                    return ['tier' => self::TIER_RISKY, 'reason' => sprintf('narrows %s(%d) -> (%d)', $eb, $al, $el)];
                }
                return ['tier' => self::TIER_SAFE, 'reason' => $el > $al ? sprintf('widens %s(%d) -> (%d)', $eb, $al, $el) : ''];
            }
            if (in_array($eb, ['enum', 'set'], true)) {
                // Any value present today must still exist (no removal/rename).
                $removed = array_diff($a['values'], $e['values']);
                if (!empty($removed)) {
                    return ['tier' => self::TIER_RISKY, 'reason' => 'removes ENUM value(s): ' . implode(',', $removed)];
                }
                $added = array_diff($e['values'], $a['values']);
                return ['tier' => self::TIER_SAFE, 'reason' => !empty($added) ? 'adds ENUM value(s): ' . implode(',', $added) : ''];
            }
            // Same base int/other: only signedness matters (display width ignored).
            if ($e['unsigned'] !== $a['unsigned']) {
                return ['tier' => self::TIER_RISKY, 'reason' => 'changes signedness'];
            }
            return ['tier' => self::TIER_SAFE, 'reason' => ''];
        }

        // INT family widen/narrow.
        if (isset(self::INT_SIZES[$eb], self::INT_SIZES[$ab])) {
            if ($e['unsigned'] !== $a['unsigned']) {
                return ['tier' => self::TIER_RISKY, 'reason' => 'changes signedness'];
            }
            if (self::INT_SIZES[$eb] < self::INT_SIZES[$ab]) {
                return ['tier' => self::TIER_RISKY, 'reason' => sprintf('narrows %s -> %s', $ab, $eb)];
            }
            return ['tier' => self::TIER_SAFE, 'reason' => sprintf('widens %s -> %s', $ab, $eb)];
        }

        // TEXT family widen/narrow.
        if (isset(self::TEXT_SIZES[$eb], self::TEXT_SIZES[$ab])) {
            if (self::TEXT_SIZES[$eb] < self::TEXT_SIZES[$ab]) {
                return ['tier' => self::TIER_RISKY, 'reason' => sprintf('narrows %s -> %s', $ab, $eb)];
            }
            return ['tier' => self::TIER_SAFE, 'reason' => sprintf('widens %s -> %s', $ab, $eb)];
        }

        // Anything else (varchar<->int, date<->datetime, ...) is a type change.
        return ['tier' => self::TIER_RISKY, 'reason' => sprintf('changes type %s -> %s', $ab, $eb)];
    }

    /**
     * Parse an actual INFORMATION_SCHEMA column row.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function parse_actual(array $row): array {
        return [
            'type'           => self::parse_type((string) ($row['column_type'] ?? '')),
            'nullable'       => strtoupper((string) ($row['is_nullable'] ?? '')) === 'YES',
            'default'        => self::normalize_default($row['column_default'] ?? null),
            'auto_increment' => stripos((string) ($row['extra'] ?? ''), 'auto_increment') !== false,
        ];
    }

    /**
     * Parse a canonical (constraint-stripped) column definition string.
     *
     * @return array<string,mixed>
     */
    private static function parse_expected(string $definition): array {
        $trimmed = trim($definition);

        // Mask parenthesised content (ENUM lists, DECIMAL args) so an attribute
        // keyword inside them can't be misread as a column attribute.
        $masked = preg_replace_callback('/\(([^)]*)\)/', static function ($m) {
            return '(' . str_repeat('_', strlen($m[1])) . ')';
        }, $trimmed) ?? $trimmed;
        $masked_lower = strtolower($masked);

        $keywords = [' not null', ' null', ' default', ' auto_increment', ' on update', ' comment'];
        $cut = strlen($trimmed);
        foreach ($keywords as $kw) {
            $pos = strpos($masked_lower, $kw);
            if ($pos !== false && $pos < $cut) {
                $cut = $pos;
            }
        }

        $type_str       = substr($trimmed, 0, $cut);
        $nullable       = strpos($masked_lower, 'not null') === false;
        $auto_increment = strpos($masked_lower, 'auto_increment') !== false;

        $default = null;
        $dpos = strpos($masked_lower, 'default');
        if ($dpos !== false) {
            $rest = trim(substr($trimmed, $dpos + strlen('default')));
            $sp   = strpos($rest, ' ');
            $default = self::normalize_default($sp !== false ? substr($rest, 0, $sp) : $rest);
        }

        return [
            'type'           => self::parse_type($type_str),
            'nullable'       => $nullable,
            'default'        => $default,
            'auto_increment' => $auto_increment,
        ];
    }

    /**
     * Parse a type string ("varchar(40)", "decimal(10,2)", "int unsigned",
     * "enum('a','b')", "BOOLEAN") into base + args (+ enum values, signedness).
     *
     * @return array{base:string, args:?string, unsigned:bool, values:array<int,string>}
     */
    private static function parse_type(string $type): array {
        $t = strtolower(trim($type));
        $unsigned = strpos($t, 'unsigned') !== false;
        $t = trim(preg_replace('/\b(unsigned|zerofill)\b/', '', $t));

        preg_match('/^([a-z]+)\s*(?:\((.*)\))?/s', $t, $m);
        $base = $m[1] ?? $t;
        $args = isset($m[2]) ? trim($m[2]) : null;

        // BOOLEAN/BOOL are stored as tinyint(1) — fold so they compare equal.
        if ($base === 'boolean' || $base === 'bool') {
            $base = 'tinyint';
            $args = '1';
        }

        $values = [];
        if (($base === 'enum' || $base === 'set') && $args !== null && $args !== '') {
            foreach (str_getcsv($args, ',', "'") as $v) {
                $values[] = trim($v);
            }
        }

        return ['base' => $base, 'args' => $args, 'unsigned' => $unsigned, 'values' => $values];
    }

    /**
     * Normalize a default for comparison (strip quotes, fold NULL and
     * CURRENT_TIMESTAMP), mirroring MealsDB_Schema_Sync.
     */
    private static function normalize_default($value): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value, "'\"");
        if (strcasecmp($value, 'null') === 0) {
            return null;
        }
        $upper = strtoupper($value);
        if ($upper === 'CURRENT_TIMESTAMP' || $upper === 'CURRENT_TIMESTAMP()') {
            return 'CURRENT_TIMESTAMP';
        }
        return $value;
    }
}
