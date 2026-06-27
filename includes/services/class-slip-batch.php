<?php
/**
 * Midland packing-slip batch service (directive 04, Midland packing documents).
 *
 * Persistence + service layer for the two-phase packer/driver workflow:
 *   generate (save doc 4 payloads) → upload doc 3 → combine (merge) → [cancel].
 * Pure persistence/service: NO HTML, NO AJAX (those are unit 05/06).
 *
 * Modeled directly on MealsDB_Invoice_Draft — same disciplines:
 *   - QW-2: fail CLOSED — the doc4_payload carries decrypted client PII
 *     (name/address/phone), so it is encrypted at rest via
 *     MealsDB_Encryption::encode_payload(); a failure to encrypt is a refusal
 *     to persist, never a plaintext fallback.
 *   - STR-LOG boundary: a committed artifact (batch created) is audited by the
 *     AJAX layer (unit 05); an attempt/failure (encryption failed) goes to the
 *     operational trunk here.
 *   - Fail-safe: every public method swallows its own \Throwable and returns a
 *     sentinel (0 / null / false / '') — it never propagates out.
 *
 * UNLIKE invoice drafts (which keep their finalized artifact encrypted IN the
 * DB), the doc 3 scan and the merged output are PDFs that can run to many MB
 * once 300-DPI backgrounds are composited, so they live on DISK under a
 * web-inaccessible upload subdir (mealsdb-slips/) — the table stores only the
 * paths. Those files are PII-bearing once rendered, hence the deny-by-default
 * directory guard (Pattern 15) and 0600 permissions.
 */
defined('ABSPATH') || exit;

class MealsDB_Slip_Batch {

    /** Payload schema version, for forward migration of the JSON shape. */
    private const PAYLOAD_SCHEMA = 1;

    /** Upload subdir (under wp_upload_dir()['basedir']) for all slip files. */
    private const STORAGE_SUBDIR = 'mealsdb-slips';

    public const STATUS_GENERATED     = 'generated';
    public const STATUS_DOC3_UPLOADED = 'doc3_uploaded';
    public const STATUS_COMBINED      = 'combined';

    /**
     * Create a batch from the per-order doc 4 payloads. The caller (unit 05's
     * generate handler) supplies already-built, positional driver-block arrays
     * — one element per order, in the SAME order doc 2 is emitted. Returns the
     * batch_id, or 0 on failure (never throws).
     *
     * @param string             $zone_name     delivery zone for this batch
     * @param string             $delivery_date 'Y-m-d'
     * @param array<int,array>   $doc4_payloads ordered list of driver blocks
     * @return int batch_id, or 0 on failure.
     */
    public static function create(string $zone_name, string $delivery_date, array $doc4_payloads): int {
        try {
            $zone_name = trim($zone_name);
            if ($zone_name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
                return 0;
            }

            // Re-index to a clean 0..N-1 list so the positional pairing with
            // doc 3 pages (element N ↔ page N+1) is unambiguous at merge time.
            $orders  = array_values($doc4_payloads);
            $payload = [
                'schema' => self::PAYLOAD_SCHEMA,
                'orders' => $orders,
            ];

            $encoded = MealsDB_Encryption::encode_payload($payload);
            if ($encoded === false) {
                // QW-2 fail-closed: no plaintext PII at rest. Surface as a
                // degraded operational event (STR-LOG: attempt/failure → trunk),
                // then bail without inserting.
                if (class_exists('MealsDB_Event_Log')) {
                    MealsDB_Event_Log::record([
                        'severity'  => 'error',
                        'category'  => 'slip_batch',
                        'subsystem' => 'slip_batch',
                        'event'     => 'create.encrypt_failed',
                        'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                        'message'   => 'Slip batch not created: doc4 payload encryption failed.',
                    ]);
                }
                return 0;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);
            $now   = gmdate('Y-m-d H:i:s');

            $ok = $wpdb->insert($table, [
                'zone_name'     => $zone_name,
                'delivery_date' => $delivery_date,
                'order_count'   => count($orders),
                'doc4_payload'  => $encoded,
                'status'        => self::STATUS_GENERATED,
                'created_by'    => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ], ['%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s']);

            if ($ok === false) {
                return 0;
            }

            return (int) $wpdb->insert_id;
        } catch (\Throwable $e) {
            self::log_error('create', $e);
            return 0;
        }
    }

    /**
     * Load + decrypt a batch. Returns null if missing or undecryptable.
     *
     * @return array|null Meta columns plus a decoded 'orders' key (the ordered
     *                    list of doc 4 driver blocks). Never returns ciphertext.
     */
    public static function get(int $batch_id): ?array {
        try {
            if ($batch_id <= 0) {
                return null;
            }

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT batch_id, zone_name, delivery_date, order_count, doc4_payload,
                        doc3_path, doc3_page_count, merged_path, status,
                        created_by, created_at, updated_at
                 FROM `{$table}` WHERE batch_id = %d",
                $batch_id
            ), ARRAY_A);

            if (!is_array($row) || empty($row)) {
                return null;
            }

            $payload = MealsDB_Encryption::decode_payload((string) ($row['doc4_payload'] ?? ''));
            if ($payload === null) {
                // Undecryptable / corrupt payload — surface as missing rather
                // than handing back ciphertext.
                return null;
            }

            // Replace the raw encrypted column with the decoded order list.
            unset($row['doc4_payload']);
            $row['orders'] = (isset($payload['orders']) && is_array($payload['orders']))
                ? array_values($payload['orders'])
                : [];

            return $row;
        } catch (\Throwable $e) {
            self::log_error('get', $e);
            return null;
        }
    }

    /**
     * List batches (newest first), optionally filtered by zone / delivery date /
     * status. Meta only — does NOT decrypt or return the doc 4 payload, so the
     * history view never touches PII. has_doc3 / has_merged are derived booleans
     * for the table's per-row action state.
     *
     * @param array $filters ['zone_name'=>..., 'delivery_date'=>..., 'status'=>...]
     * @return array<int,array> meta rows.
     */
    public static function list_batches(array $filters = []): array {
        try {
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);

            $where  = [];
            $params = [];
            if (!empty($filters['zone_name'])) {
                $where[]  = 'zone_name = %s';
                $params[] = (string) $filters['zone_name'];
            }
            if (!empty($filters['delivery_date'])) {
                $where[]  = 'delivery_date = %s';
                $params[] = (string) $filters['delivery_date'];
            }
            if (!empty($filters['status'])) {
                $where[]  = 'status = %s';
                $params[] = (string) $filters['status'];
            }

            // doc4_payload is deliberately NOT selected — list view needs no PII.
            $sql = "SELECT batch_id, zone_name, delivery_date, order_count,
                           doc3_path, doc3_page_count, merged_path, status,
                           created_by, created_at, updated_at
                    FROM `{$table}`";
            if (!empty($where)) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY created_at DESC, batch_id DESC';

            $prepared = !empty($params) ? $wpdb->prepare($sql, ...$params) : $sql;
            $rows     = $wpdb->get_results($prepared, ARRAY_A);
            if (!is_array($rows)) {
                return [];
            }

            // Derive the action-state booleans the UI needs without exposing
            // the raw paths' contents.
            foreach ($rows as &$r) {
                $r['has_doc3']   = !empty($r['doc3_path']);
                $r['has_merged'] = !empty($r['merged_path']);
            }
            unset($r);

            return $rows;
        } catch (\Throwable $e) {
            self::log_error('list_batches', $e);
            return [];
        }
    }

    /**
     * Store an uploaded (and already-validated) doc 3 PDF for a batch.
     *
     * The AJAX layer (unit 05) is responsible for the upload security checks
     * (PDF MIME sniff, size cap) and for computing $page_count via
     * MealsDB_Slip_Merge::validate_doc3() BEFORE calling this — this method only
     * persists a file it is told is good. Replaceable: a new upload overwrites
     * the previous one and deletes the old file (latest scan wins).
     *
     * @param int    $batch_id
     * @param string $tmp_pdf_path absolute path to the validated temp PDF
     * @param int    $page_count   pages in the PDF (== order_count, per validate)
     * @return bool true on success.
     */
    public static function store_doc3(int $batch_id, string $tmp_pdf_path, int $page_count): bool {
        try {
            $batch = self::get($batch_id);
            if ($batch === null) {
                return false;
            }
            if (!is_string($tmp_pdf_path) || $tmp_pdf_path === '' || !is_readable($tmp_pdf_path)) {
                return false;
            }

            $dir = self::protected_dir('doc3');
            if ($dir === null) {
                return false;
            }

            // Random destination filename (Pattern 15) — never trust/keep the
            // client's name. Keyed by batch for operator traceability on disk.
            $dest = trailingslashit($dir) . 'batch-' . $batch_id . '-' . self::random_token() . '.pdf';

            if (!self::move_file($tmp_pdf_path, $dest)) {
                return false;
            }
            @chmod($dest, 0600);

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);
            $updated = $wpdb->update(
                $table,
                [
                    'doc3_path'       => $dest,
                    'doc3_page_count' => $page_count,
                    'status'          => self::STATUS_DOC3_UPLOADED,
                    'updated_at'      => gmdate('Y-m-d H:i:s'),
                ],
                ['batch_id' => $batch_id],
                ['%s', '%d', '%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                // DB write failed — don't orphan the file we just moved in.
                self::delete_file_quietly($dest);
                return false;
            }

            // Row updated: now it is safe to drop any PRIOR doc 3 file (the
            // replace case). Done after the successful UPDATE so a failed write
            // never deletes the file still referenced by the row.
            $old = (string) ($batch['doc3_path'] ?? '');
            if ($old !== '' && $old !== $dest) {
                self::delete_file_quietly($old);
            }

            return true;
        } catch (\Throwable $e) {
            self::log_error('store_doc3', $e);
            return false;
        }
    }

    /**
     * Persist the merged finished PDF bytes for a batch. Stable filename
     * (batch-<id>.pdf) so a re-combine overwrites in place (latest wins).
     * Returns the absolute path on success, or '' on failure.
     *
     * @param int    $batch_id
     * @param string $pdf_bytes raw PDF content
     * @return string path on success, '' on failure.
     */
    public static function store_merged(int $batch_id, string $pdf_bytes): string {
        try {
            if ($batch_id <= 0 || !is_string($pdf_bytes) || $pdf_bytes === '') {
                return '';
            }
            if (self::get($batch_id) === null) {
                return '';
            }

            $dir = self::protected_dir('merged');
            if ($dir === null) {
                return '';
            }

            $dest = trailingslashit($dir) . 'batch-' . $batch_id . '.pdf';
            if (file_put_contents($dest, $pdf_bytes) === false) {
                return '';
            }
            @chmod($dest, 0600);

            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);
            $updated = $wpdb->update(
                $table,
                [
                    'merged_path' => $dest,
                    'status'      => self::STATUS_COMBINED,
                    'updated_at'  => gmdate('Y-m-d H:i:s'),
                ],
                ['batch_id' => $batch_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                self::delete_file_quietly($dest);
                return '';
            }

            return $dest;
        } catch (\Throwable $e) {
            self::log_error('store_merged', $e);
            return '';
        }
    }

    /**
     * Hard-delete a batch: remove the row AND its doc 3 / merged files from
     * disk. Operator decision (the directive): cancel is a permanent delete;
     * they regenerate from scratch if needed. The audit log entry is written by
     * the AJAX layer (unit 05), which owns the request context. Returns true if
     * the row was deleted.
     */
    public static function cancel(int $batch_id): bool {
        try {
            if ($batch_id <= 0) {
                return false;
            }

            $batch = self::get($batch_id);
            // Even if decrypt failed (get() → null), still attempt to delete the
            // row by id so a corrupt batch can be cleared; fetch paths directly.
            global $wpdb;
            $table = MealsDB_DB::get_table_name(MealsDB_Tables::SLIP_BATCHES);

            if ($batch === null) {
                $paths = $wpdb->get_row($wpdb->prepare(
                    "SELECT doc3_path, merged_path FROM `{$table}` WHERE batch_id = %d",
                    $batch_id
                ), ARRAY_A);
                $doc3   = is_array($paths) ? (string) ($paths['doc3_path'] ?? '') : '';
                $merged = is_array($paths) ? (string) ($paths['merged_path'] ?? '') : '';
            } else {
                $doc3   = (string) ($batch['doc3_path'] ?? '');
                $merged = (string) ($batch['merged_path'] ?? '');
            }

            $deleted = $wpdb->delete($table, ['batch_id' => $batch_id], ['%d']);
            if ($deleted === false || (int) $deleted === 0) {
                return false;
            }

            // Row is gone — clear the files (after the row, so a failed delete
            // never strands a row pointing at a removed file).
            if ($doc3 !== '') {
                self::delete_file_quietly($doc3);
            }
            if ($merged !== '') {
                self::delete_file_quietly($merged);
            }

            return true;
        } catch (\Throwable $e) {
            self::log_error('cancel', $e);
            return false;
        }
    }

    // ------------------------------------------------------------------ //
    //  Storage helpers
    // ------------------------------------------------------------------ //

    /**
     * Public accessor for a protected storage subdir (e.g. 'tmp'), so the merge
     * engine (unit 03) writes its scratch backgrounds under the SAME
     * deny-by-default root rather than re-implementing the guard logic. Returns
     * the absolute path or null if it cannot be created/secured.
     */
    public static function storage_dir(string $sub): ?string {
        return self::protected_dir($sub);
    }

    /**
     * Resolve (and lazily create + protect) a subdir under the slip storage
     * root. Returns the absolute path, or null if it cannot be created/secured.
     *
     * The root and every subdir get a deny-by-default `.htaccess` and an empty
     * `index.html` (Pattern 15): these files carry decrypted client PII once
     * rendered, so they must NOT be web-served even though they live under
     * wp-content/uploads. Dirs are 0700.
     *
     * @param string $sub one of 'doc3', 'merged', 'tmp'
     */
    private static function protected_dir(string $sub): ?string {
        $sub = preg_replace('/[^a-z0-9_]/i', '', $sub);
        if ($sub === '' || $sub === null) {
            return null;
        }

        if (!function_exists('wp_upload_dir')) {
            return null;
        }
        $uploads = wp_upload_dir();
        if (!is_array($uploads) || empty($uploads['basedir'])) {
            return null;
        }

        $root = trailingslashit($uploads['basedir']) . self::STORAGE_SUBDIR;
        if (!self::ensure_protected_dir($root)) {
            return null;
        }

        $path = trailingslashit($root) . $sub;
        if (!self::ensure_protected_dir($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Create $dir if needed and drop the deny guards. Idempotent. Returns false
     * only if the directory cannot be made writable.
     */
    private static function ensure_protected_dir(string $dir): bool {
        if (!is_dir($dir)) {
            if (!function_exists('wp_mkdir_p') || !wp_mkdir_p($dir)) {
                return false;
            }
            @chmod($dir, 0700);
        }
        if (!is_writable($dir)) {
            return false;
        }

        $htaccess = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess)) {
            // Works on both Apache 2.2 (Deny) and 2.4 (Require) — mirrors how WP
            // core protects sensitive upload subdirs.
            @file_put_contents(
                $htaccess,
                "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
        }
        $index = trailingslashit($dir) . 'index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }

        return true;
    }

    /**
     * Move a file into place, preferring an atomic rename and falling back to
     * copy+unlink across filesystem boundaries (the PHP upload tmpdir is often
     * on a different mount than wp-content). Returns true on success.
     */
    private static function move_file(string $src, string $dest): bool {
        if (@rename($src, $dest)) {
            return true;
        }
        if (@copy($src, $dest)) {
            @unlink($src);
            return true;
        }
        return false;
    }

    /** Best-effort delete; never throws, never warns. */
    private static function delete_file_quietly(string $path): void {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /** Short random hex token for unguessable destination filenames. */
    private static function random_token(): string {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            // random_bytes can only fail without a CSPRNG; fall back to WP's.
            return function_exists('wp_generate_password')
                ? substr(preg_replace('/[^a-f0-9]/i', '', md5(wp_generate_password(32, false))), 0, 16)
                : substr(md5((string) getmypid() . (string) memory_get_usage()), 0, 16);
        }
    }

    /**
     * Internal: breadcrumb a swallowed exception. Fail-safe — never throws.
     */
    private static function log_error(string $op, \Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB Slip_Batch] ' . $op . ' failed: ' . $e->getMessage());
        } else {
            error_log('[MealsDB Slip_Batch] ' . $op . ' failed: ' . $e->getMessage());
        }
    }
}
