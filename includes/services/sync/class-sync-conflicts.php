<?php
/**
 * Persistence for sync conflicts (directive K21 ITEM 8).
 *
 * A conflict is one (wp_user_id, field) where the WordPress account and its
 * active MealsDB client(s) hold different non-blank values. The sync writes
 * nothing for that field; an operator resolves it.
 *
 * Invariants:
 *   - ONE live row (open or dismissed) per (wp_user_id, field), enforced by
 *     the UNIQUE open_dedup column (NULL once the row is closed).
 *   - Each observation REPLACES the stored values (the newest wins), so a
 *     misspelt surname seen on three nights, or across two linked clients, is
 *     one row showing the latest values — never three.
 *   - A dismissal holds only while values_hash is unchanged; any change
 *     reopens the row.
 *   - A change in WHICH clients are linked to the account supersedes the old
 *     row (resolution 'relinked') and starts a fresh one: the old values
 *     described a different set of records.
 *
 * Integration with meals_ignored_conflicts (the pre-K21 dashboard's ignore
 * rules): this store DELEGATES to it, read-only. When a conflict's
 * (field, MealsDB value, WP value) matches a legacy ignore rule, the row is
 * recorded as dismissed. New dismissals are made here (ITEM 9 "Leave both"),
 * not in the legacy table; it is retired when ITEM 9 replaces the dashboard.
 *
 * Every method is fail-safe: a DB error is logged and reported to the caller
 * as a sentinel, never thrown (CLAUDE.md Pattern 7).
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

final class MealsDB_Sync_Conflicts {

    public const STATUS_OPEN          = 'open';
    public const STATUS_RESOLVED      = 'resolved';
    public const STATUS_DISMISSED     = 'dismissed';
    public const STATUS_AUTO_RESOLVED = 'auto_resolved';
    public const STATUS_SUPERSEDED    = 'superseded';

    /** record() outcomes. */
    public const R_INSERTED         = 'inserted';
    public const R_INSERTED_IGNORED = 'inserted_dismissed';
    public const R_UPDATED          = 'updated';
    public const R_STILL_DISMISSED  = 'still_dismissed';
    public const R_REOPENED         = 'reopened';
    public const R_DISMISSED_LEGACY = 'dismissed_legacy';
    public const R_RELINKED         = 'relinked';
    public const R_ERROR            = 'error';

    /** @var wpdb|object */
    private $db;

    private string $table;

    /** @param wpdb|object|null $db Injected for tests; defaults to global $wpdb. */
    public function __construct($db = null) {
        global $wpdb;
        $this->db    = $db ?? $wpdb;
        $this->table = str_replace('`', '``', MealsDB_DB::get_table_name(MealsDB_Tables::SYNC_CONFLICTS));
    }

    public static function dedup_key(int $wp_user_id, string $field): string {
        return $wp_user_id . '|' . $field;
    }

    /**
     * sha256 over the field and the RAW values (not normalised), with the
     * client set included. Any change to either side — or to which clients
     * are linked — changes the hash.
     *
     * @param array<int|string, string|null> $meals_values client_id => raw value
     */
    public static function values_hash(string $field, string $wp_value, array $meals_values): string {
        return hash('sha256', $field . "\0" . $wp_value . "\0" . self::encode_meals_values($meals_values));
    }

    /** @param array<int|string, string|null> $meals_values */
    public static function encode_meals_values(array $meals_values): string {
        $normalised = [];
        foreach ($meals_values as $client_id => $value) {
            $normalised[(string) (int) $client_id] = (string) $value;
        }
        ksort($normalised, SORT_NUMERIC);
        return (string) json_encode($normalised, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Every live (open or dismissed) row, keyed by open_dedup. Returns null
     * on a DB error so the caller can abort rather than treat "couldn't read"
     * as "no conflicts" (which would duplicate every row on the next write).
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function load_live(): ?array {
        $rows = $this->db->get_results(
            "SELECT * FROM `{$this->table}` WHERE status IN ('open', 'dismissed')",
            ARRAY_A
        );
        if (!is_array($rows)) {
            $this->log_db_error('load_live');
            return null;
        }
        $live = [];
        foreach ($rows as $row) {
            if (!empty($row['open_dedup'])) {
                $live[(string) $row['open_dedup']] = $row;
            }
        }
        return $live;
    }

    /**
     * Record one observed conflict. $live is the current live row for this
     * (wp_user_id, field), or null.
     *
     * @param array<string, mixed>|null      $live
     * @param array<int|string, string|null> $meals_values client_id => raw value
     * @param bool $legacy_ignored a meals_ignored_conflicts rule matches these values
     * @return string one of the R_* constants
     */
    public function record(?array $live, int $wp_user_id, string $field, string $wp_value, array $meals_values, string $now, bool $legacy_ignored): string {
        $hash = self::values_hash($field, $wp_value, $meals_values);

        if ($live !== null && !self::same_client_set($live, $meals_values)) {
            // The linked clients changed (relink, new link, deactivation):
            // the old row described different records. Close it and start
            // over rather than letting a dismissal carry across.
            if (!$this->close($live, self::STATUS_SUPERSEDED, 'relinked', $now)) {
                return self::R_ERROR;
            }
            $result = $this->insert($wp_user_id, $field, $wp_value, $meals_values, $hash, $now, $legacy_ignored);
            return $result === self::R_ERROR ? self::R_ERROR : self::R_RELINKED;
        }

        if ($live === null) {
            return $this->insert($wp_user_id, $field, $wp_value, $meals_values, $hash, $now, $legacy_ignored);
        }

        $id = (int) $live['conflict_id'];
        $values = [
            'wp_value'     => $wp_value,
            'meals_values' => self::encode_meals_values($meals_values),
            'values_hash'  => $hash,
            'last_seen_at' => $now,
        ];

        if ($live['status'] === self::STATUS_DISMISSED) {
            if (hash_equals((string) $live['dismissed_pair_hash'], $hash)) {
                return $this->update($id, ['last_seen_at' => $now]) ? self::R_STILL_DISMISSED : self::R_ERROR;
            }
            if ($legacy_ignored) {
                // Values changed, but a legacy ignore rule covers the new pair.
                return $this->update($id, $values + ['dismissed_pair_hash' => $hash])
                    ? self::R_STILL_DISMISSED : self::R_ERROR;
            }
            // Values changed since the dismissal: it no longer applies.
            return $this->update($id, $values + [
                'status'              => self::STATUS_OPEN,
                'resolution'          => null,
                'resolved_at'         => null,
                'resolved_by'         => null,
                'dismissed_pair_hash' => null,
            ]) ? self::R_REOPENED : self::R_ERROR;
        }

        if ($legacy_ignored) {
            return $this->update($id, $values + [
                'status'              => self::STATUS_DISMISSED,
                'resolution'          => 'dismissed',
                'resolved_at'         => $now,
                'dismissed_pair_hash' => $hash,
            ]) ? self::R_DISMISSED_LEGACY : self::R_ERROR;
        }

        return $this->update($id, $values) ? self::R_UPDATED : self::R_ERROR;
    }

    /**
     * Close a live row. Clears open_dedup so the (wp_user_id, field) slot is
     * free for a future conflict.
     *
     * @param array<string, mixed> $live
     */
    public function close(array $live, string $status, string $resolution, string $now): bool {
        return $this->update((int) $live['conflict_id'], [
            'status'      => $status,
            'resolution'  => $resolution,
            'resolved_at' => $now,
            'open_dedup'  => null,
        ]);
    }

    /**
     * Supersede every live row for an account (its WP user was deleted or
     * its link changed). Returns the number of rows closed, or -1 on error.
     */
    public function supersede_account(int $wp_user_id, string $now): int {
        $result = $this->db->query($this->db->prepare(
            "UPDATE `{$this->table}`
                SET status = 'superseded', resolution = 'relinked', resolved_at = %s, open_dedup = NULL
              WHERE wp_user_id = %d AND status IN ('open', 'dismissed')",
            $now,
            $wp_user_id
        ));
        if ($result === false) {
            $this->log_db_error('supersede_account');
            return -1;
        }
        return (int) $result;
    }

    /**
     * Live (open/dismissed) rows for the given accounts, grouped by
     * wp_user_id then field. Null on a DB error.
     *
     * @param int[] $wp_user_ids
     * @return array<int, array<string, array<string, mixed>>>|null
     */
    public function live_for_accounts(array $wp_user_ids): ?array {
        $ids = array_values(array_filter(array_map('intval', $wp_user_ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM `{$this->table}` WHERE status IN ('open', 'dismissed') AND wp_user_id IN ({$placeholders})",
            ...$ids
        ), ARRAY_A);
        if (!is_array($rows)) {
            $this->log_db_error('live_for_accounts');
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['wp_user_id']][(string) $row['field']] = $row;
        }
        return $out;
    }

    /**
     * Operator dismissal ("Leave both", K21 ITEM 9). Stores the values the
     * operator saw and holds the dismissal while they stay unchanged. The
     * caller has already verified $hash against freshly read values.
     *
     * @param array<string, mixed>           $live
     * @param array<int|string, string|null> $meals_values
     */
    public function dismiss(array $live, string $wp_value, array $meals_values, string $hash, int $user_id, string $now): bool {
        return $this->update((int) $live['conflict_id'], [
            'status'              => self::STATUS_DISMISSED,
            'wp_value'            => $wp_value,
            'meals_values'        => self::encode_meals_values($meals_values),
            'values_hash'         => $hash,
            'dismissed_pair_hash' => $hash,
            'resolution'          => 'dismissed',
            'resolved_by'         => $user_id > 0 ? $user_id : null,
            'resolved_at'         => $now,
            'last_seen_at'        => $now,
        ]);
    }

    /**
     * Operator resolution (K21 ITEM 10): the chosen value was written to
     * every other side and the account now agrees. Closes the row.
     *
     * @param array<string, mixed> $live
     * @param string $resolution 'wp' | 'client:<id>'
     */
    public function resolve(array $live, string $resolution, int $user_id, string $now): bool {
        return $this->update((int) $live['conflict_id'], [
            'status'      => self::STATUS_RESOLVED,
            'resolution'  => $resolution,
            'resolved_by' => $user_id > 0 ? $user_id : null,
            'resolved_at' => $now,
            'open_dedup'  => null,
        ]);
    }

    /**
     * Undo a dismissal: the row is open again and the next run treats it as
     * an ordinary conflict.
     *
     * @param array<string, mixed> $live
     */
    public function undismiss(array $live): bool {
        return $this->update((int) $live['conflict_id'], [
            'status'              => self::STATUS_OPEN,
            'dismissed_pair_hash' => null,
            'resolution'          => null,
            'resolved_by'         => null,
            'resolved_at'         => null,
        ]);
    }

    /** @param array<int|string, string|null> $meals_values */
    private function insert(int $wp_user_id, string $field, string $wp_value, array $meals_values, string $hash, string $now, bool $dismissed): string {
        $data = [
            'wp_user_id'    => $wp_user_id,
            'field'         => $field,
            'wp_value'      => $wp_value,
            'meals_values'  => self::encode_meals_values($meals_values),
            'values_hash'   => $hash,
            'status'        => $dismissed ? self::STATUS_DISMISSED : self::STATUS_OPEN,
            'first_seen_at' => $now,
            'last_seen_at'  => $now,
            'open_dedup'    => self::dedup_key($wp_user_id, $field),
        ];
        if ($dismissed) {
            $data['resolution']          = 'dismissed';
            $data['resolved_at']         = $now;
            $data['dismissed_pair_hash'] = $hash;
        }
        if ($this->db->insert($this->table, $data) === false) {
            // Most likely a UNIQUE(open_dedup) collision with a concurrent
            // writer. The row the other writer created is the live one; the
            // next run updates it. Report, don't throw.
            $this->log_db_error('insert');
            return self::R_ERROR;
        }
        return $dismissed ? self::R_INSERTED_IGNORED : self::R_INSERTED;
    }

    /** @param array<string, mixed> $data */
    private function update(int $conflict_id, array $data): bool {
        if ($this->db->update($this->table, $data, ['conflict_id' => $conflict_id]) === false) {
            $this->log_db_error('update');
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed>           $live
     * @param array<int|string, string|null> $meals_values
     */
    public static function same_client_set(array $live, array $meals_values): bool {
        $stored = json_decode((string) ($live['meals_values'] ?? ''), true);
        if (!is_array($stored)) {
            return false;
        }
        $a = array_map('intval', array_keys($stored));
        $b = array_map('intval', array_keys($meals_values));
        sort($a);
        sort($b);
        return $a === $b;
    }

    private function log_db_error(string $op): void {
        $error = is_object($this->db) && isset($this->db->last_error) ? (string) $this->db->last_error : '';
        // No values in the message: conflict rows carry client PII.
        MealsDB_Logger::error(sprintf('[MealsDB Sync Conflicts] %s failed: %s', $op, $error !== '' ? $error : 'unknown'));
    }
}
