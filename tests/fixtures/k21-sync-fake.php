<?php
/**
 * Shared fake WordPress + $wpdb for the K21 sync tests
 * (test-sync-conflicts-k21.php, test-sync-conflict-view-k21.php).
 *
 * One in-memory $wpdb holds meals_clients, meals_sync_conflicts, staff links
 * and legacy ignore rules, answers the queries the reconciler, the conflict
 * store and the conflict view issue (matched by SQL substring), and records
 * any write to client data in $client_writes so a test can assert that a
 * detection or dismissal path wrote nothing. WordPress writes are recorded in
 * $GLOBALS['wp_writes'].
 */
if (!function_exists('__')) { function __(string $t, string $d = 'default') { return $t; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $f = 0, $d = 512) { return json_encode($v, $f, $d); } }
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code; public $message;
        public function __construct($c = '', $m = '') { $this->code = $c; $this->message = $m; }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!class_exists('WP_User')) {
    class WP_User {
        public $ID = 0; public $first_name = ''; public $last_name = ''; public $user_email = ''; public $roles = [];
        public function __construct($id = 0) { $this->ID = $id; }
    }
}

// --- WordPress user store ----------------------------------------------------
$GLOBALS['users'] = [];   // id => WP_User
$GLOBALS['meta']  = [];   // "id|key" => value
$GLOBALS['wp_writes'] = [];
function get_userdata($id) { return $GLOBALS['users'][(int) $id] ?? false; }
function get_user_meta($id, $key, $single = false) { return $GLOBALS['meta']["$id|$key"] ?? ''; }
function cache_users($ids) {}
function get_current_user_id() { return (int) ($GLOBALS['current_user_id'] ?? 0); }
// Permission stubs: MealsDB_Permissions::can_access_plugin() reads these.
$GLOBALS['cap_ok'] = true;
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return (bool) ($GLOBALS['cap_ok'] ?? true); } }
if (!function_exists('current_user_can')) { function current_user_can($cap) { return (bool) ($GLOBALS['cap_ok'] ?? true); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value, ...$args) { return $value; } }
function update_meta_cache($type, $ids) { return true; }
function update_user_meta($id, $k, $v) {
    $GLOBALS['wp_writes'][] = ['meta', $id, $k, $v];
    $GLOBALS['meta']["$id|$k"] = $v;
    $GLOBALS['syncing_during_meta_write'][] = class_exists('MealsDB_Sync', false) ? MealsDB_Sync::is_syncing() : null;
    return true;
}
// $GLOBALS['emails_taken']: addresses another user already owns, so a
// user_email change collides the way WordPress's uniqueness check does.
$GLOBALS['emails_taken'] = [];
function wp_update_user($d) {
    $GLOBALS['wp_writes'][] = ['user', $d];
    $GLOBALS['email_filter_active_during_update'] = has_filter('send_email_change_email');
    // K21 Test 28: a resolution write must run with the sync hooks suppressed.
    $GLOBALS['syncing_during_update'] = class_exists('MealsDB_Sync', false) ? MealsDB_Sync::is_syncing() : null;
    if (isset($d['user_email']) && in_array(strtolower($d['user_email']), $GLOBALS['emails_taken'], true)) {
        return new WP_Error('existing_user_email', 'Sorry, that email address is already used!');
    }
    $u = $GLOBALS['users'][(int) $d['ID']] ?? null;
    if ($u) {
        foreach ($d as $k => $v) { if ($k !== 'ID') { $u->$k = $v; } }
    }
    return $d['ID'];
}
$GLOBALS['filters'] = [];
function add_filter($tag, $cb, $prio = 10, $args = 1) { $GLOBALS['filters'][$tag][] = $cb; return true; }
function remove_filter($tag, $cb, $prio = 10) {
    $GLOBALS['filters'][$tag] = array_values(array_filter($GLOBALS['filters'][$tag] ?? [], static fn ($x) => $x !== $cb));
    return true;
}
function has_filter($tag) { return !empty($GLOBALS['filters'][$tag]); }
function metadata_exists($type, $id, $key) { return array_key_exists("$id|$key", $GLOBALS['meta']); }
// Options: shadow mode explicitly OFF unless a test flips it; date/time
// formats for the template; the zone delivery schedule for delivery_day.
$GLOBALS['options'] = [
    'mealsdb_settings' => ['shadow_mode' => '0'],
    'date_format' => 'Y-m-d', 'time_format' => 'H:i',
    'mealsdb_zone_delivery_schedule' => ['Zone 1' => ['day' => 'Tuesday'], 'Zone 3' => ['day' => 'Thursday']],
];
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; } }

// --- Fake wpdb ---------------------------------------------------------------
class wpdb {
    public $prefix = 'wp_'; public $usermeta = 'wp_usermeta'; public $users = 'wp_users';
    public $last_error = ''; public $insert_id = 0;
    public array $clients = [];     // client rows
    public array $conflicts = [];   // conflict_id => row
    public array $staff = [];       // wp ids in meals_staff
    public array $ignored = [];     // legacy ignore rows
    public array $events = [];
    public array $client_writes = [];
    public array $audit = [];
    public bool $fail_client_update = false;
    public bool $fail_staff = false;
    public bool $fail_live = false;
    private int $next_id = 1;

    public function prepare($q, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        return ['sql' => $q, 'args' => $args];
    }
    private static function split($q): array {
        return is_array($q) ? [$q['sql'], $q['args']] : [(string) $q, []];
    }
    private function tracked(array $r): bool {
        return in_array($r['client_type'], ['SDNB', 'Veteran', 'Private'], true) && (int) $r['active'] === 1;
    }
    public function get_col($q) {
        [$sql, $args] = self::split($q);
        if (strpos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            return ['wp_user_id'];
        }
        if (strpos($sql, 'SELECT client_id FROM') !== false) {
            return array_map(static fn ($r) => $r['client_id'],
                array_filter($this->clients, static fn ($r) => (int) $r['wp_user_id'] === (int) $args[0]));
        }
        if (strpos($sql, 'GROUP BY wp_user_id ORDER BY MIN(first_seen_at)') !== false) {
            $limit = (int) $args[count($args) - 2]; $offset = (int) $args[count($args) - 1];
            $rows = $this->conflict_filter($args[0], count($args) === 4 ? $args[1] : null);
            $first = [];
            foreach ($rows as $r) {
                $u = (int) $r['wp_user_id'];
                $first[$u] = isset($first[$u]) ? min($first[$u], $r['first_seen_at']) : $r['first_seen_at'];
            }
            uksort($first, static fn ($a, $b) => [$first[$a], $a] <=> [$first[$b], $b]);
            return array_slice(array_keys($first), $offset, $limit);
        }
        if (strpos($sql, 'SELECT DISTINCT wp_user_id') !== false) {
            [$cursor, $limit] = $args;
            $ids = [];
            foreach ($this->clients as $r) {
                if ($this->tracked($r) && (int) $r['wp_user_id'] > $cursor) { $ids[(int) $r['wp_user_id']] = true; }
            }
            $ids = array_keys($ids); sort($ids);
            return array_slice($ids, 0, $limit);
        }
        return [];
    }
    public function get_results($q, $o = null) {
        [$sql, $args] = self::split($q);
        if (strpos($sql, 'meals_sync_conflicts') !== false && strpos($sql, 'AND wp_user_id IN') !== false) {
            if ($this->fail_live) { $this->last_error = 'table missing'; return null; }
            return array_values(array_filter($this->conflicts, static fn ($r) =>
                in_array($r['status'], ['open', 'dismissed'], true) && in_array((int) $r['wp_user_id'], $args, true)));
        }
        if (strpos($sql, 'SELECT status, COUNT(DISTINCT wp_user_id)') !== false) {
            if ($this->fail_live) { return null; }
            $acc = [];
            foreach ($this->conflicts as $r) {
                if (in_array($r['status'], ['open', 'dismissed'], true)) { $acc[$r['status']][(int) $r['wp_user_id']] = true; }
            }
            $out = [];
            foreach ($acc as $st => $users) { $out[] = ['status' => $st, 'n' => count($users)]; }
            return $out;
        }
        if (strpos($sql, 'SELECT field, COUNT(*) AS n') !== false) {
            $n = [];
            foreach ($this->conflict_filter($args[0], null) as $r) { $n[$r['field']] = ($n[$r['field']] ?? 0) + 1; }
            $out = [];
            foreach ($n as $f => $c) { $out[] = ['field' => $f, 'n' => $c]; }
            return $out;
        }
        if (strpos($sql, 'meals_sync_conflicts') !== false && strpos($sql, "status IN ('open', 'dismissed')") !== false) {
            if ($this->fail_live) { $this->last_error = 'table missing'; return null; }
            return array_values(array_filter($this->conflicts, static fn ($r) => in_array($r['status'], ['open', 'dismissed'], true)));
        }
        if (strpos($sql, 'SHOW COLUMNS') !== false && strpos($sql, 'meals_clients') !== false) {
            $cols = ['client_id' => true];
            foreach ($this->clients as $r) { foreach (array_keys($r) as $c) { $cols[$c] = true; } }
            return array_map(static fn ($c) => ['Field' => $c], array_keys($cols));
        }
        if (strpos($sql, 'SHOW COLUMNS') !== false && strpos($sql, 'meals_staff') !== false) {
            return [['Field' => 'wordpress_user_id']];
        }
        if (strpos($sql, 'AS wordpress_user_id FROM') !== false) {
            if ($this->fail_staff) { $this->last_error = 'staff query failed'; return null; }
            return array_map(static fn ($id) => ['wordpress_user_id' => $id], $this->staff);
        }
        if (strpos($sql, 'field_name, source_value, target_value') !== false) {
            return $this->ignored;
        }
        if (strpos($sql, 'WHERE wp_user_id IN') !== false) {
            $rows = array_values(array_filter($this->clients, fn ($r) => $this->tracked($r) && in_array((int) $r['wp_user_id'], $args, true)));
            usort($rows, static fn ($a, $b) => [$a['wp_user_id'], $a['client_id']] <=> [$b['wp_user_id'], $b['client_id']]);
            return $rows;
        }
        return [];
    }
    public function insert($table, $data, $f = null) {
        if (strpos($table, 'meals_event_log') !== false) {
            $this->events[] = $data; $this->insert_id = count($this->events); return 1;
        }
        if (strpos($table, 'meals_sync_conflicts') !== false) {
            if (isset($data['open_dedup'])) {
                foreach ($this->conflicts as $r) {
                    if (($r['open_dedup'] ?? null) === $data['open_dedup']) { $this->last_error = 'Duplicate entry'; return false; }
                }
            }
            $id = $this->next_id++;
            $this->conflicts[$id] = $data + ['conflict_id' => $id, 'resolution' => null, 'resolved_at' => null,
                'resolved_by' => null, 'dismissed_pair_hash' => null];
            return 1;
        }
        $this->client_writes[] = ['insert', $table, $data];
        return 1;
    }
    public function update($table, $data, $where, $f = null, $wf = null) {
        if (strpos($table, 'meals_event_log') !== false) { return 1; }
        if (strpos($table, 'meals_sync_conflicts') !== false) {
            $id = (int) $where['conflict_id'];
            if (!isset($this->conflicts[$id])) { return false; }
            $this->conflicts[$id] = array_merge($this->conflicts[$id], $data);
            return 1;
        }
        $this->client_writes[] = ['update', $table, $data];
        return 1;
    }
    public function query($q) {
        [$sql, $args] = self::split($q);
        if (strpos($sql, "SET status = 'superseded'") !== false) {
            [$now, $uid] = $args; $n = 0;
            foreach ($this->conflicts as $id => $r) {
                if ((int) $r['wp_user_id'] === $uid && in_array($r['status'], ['open', 'dismissed'], true)) {
                    $this->conflicts[$id] = array_merge($r, ['status' => 'superseded', 'resolution' => 'relinked', 'resolved_at' => $now, 'open_dedup' => null]);
                    $n++;
                }
            }
            return $n;
        }
        if (strpos($sql, 'meals_audit_log') !== false) {
            // MealsDB_Logger::log(): user_id, action, target_id, field, old, new, source, created_at.
            $this->audit[] = ['action' => $args[1], 'target_id' => $args[2], 'field' => $args[3],
                'old' => $args[4], 'new' => $args[5], 'source' => $args[6]];
            return 1;
        }
        if (preg_match('/^UPDATE `[^`]*meals_clients` SET (.+) WHERE `client_id` = %d$/s', trim($sql), $m)) {
            if ($this->fail_client_update) { $this->last_error = 'forced failure'; return false; }
            preg_match_all('/`([a-z0-9_]+)` = %s/', $m[1], $cols);
            $id = (int) end($args);
            foreach ($this->clients as &$row) {
                if ((int) $row['client_id'] === $id) {
                    foreach ($cols[1] as $i => $col) { $row[$col] = $args[$i]; }
                }
            }
            unset($row);
            $this->client_writes[] = ['update', $id, array_combine($cols[1], array_slice($args, 0, count($cols[1])))];
            return 1;
        }
        if (preg_match('/^\s*(UPDATE|INSERT|DELETE|REPLACE)/i', $sql) && strpos($sql, 'meals_event_log') === false) {
            $this->client_writes[] = ['query', $sql];
        }
        return 0;
    }
    public function get_var($q) {
        [$sql, $args] = self::split($q);
        if (strpos($sql, 'SELECT COUNT(DISTINCT wp_user_id) FROM') !== false && strpos($sql, 'meals_sync_conflicts') !== false) {
            $users = [];
            foreach ($this->conflict_filter($args[0], $args[1] ?? null) as $r) { $users[(int) $r['wp_user_id']] = true; }
            return (string) count($users);
        }
        return null;
    }
    private function conflict_filter(string $status, ?string $field): array {
        return array_values(array_filter($this->conflicts, static fn ($r) =>
            $r['status'] === $status && ($field === null || $r['field'] === $field)));
    }
    public function get_row($q, $o = null) { return null; }

    // Test helpers.
    public function event_names(): array { return array_column($this->events, 'event'); }
    public function live(): array {
        $out = [];
        foreach ($this->conflicts as $r) {
            if (in_array($r['status'], ['open', 'dismissed'], true)) { $out[$r['wp_user_id'] . '|' . $r['field']] = $r; }
        }
        return $out;
    }
}

