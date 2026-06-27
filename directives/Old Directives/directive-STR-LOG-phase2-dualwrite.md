# Directive STR-LOG / Phase 2 — Dual-write + parity verification

**Parent:** `directive-STR-LOG-event-trunk.md`. **Prerequisite:** Phase 1 complete — `meals_event_log` table exists, `MealsDB_Event_Log::record()` + the job-lifecycle helpers (`start_job`/`finish_job`/`fail_job`/`heartbeat`) exist with all five inherited disciplines, and the read-only dashboard renders from the trunk.
**Goal of this phase:** make `MealsDB_Job_Logger` and `MealsDB_Hook_Logger` write to BOTH their existing tables AND the trunk, then **prove the trunk faithfully reproduces every old-table row** before any reads are cut over (Phase 3) or any old writes are removed (Phase 4). The old tables remain authoritative throughout Phase 2 — a trunk bug here is caught while the truth is still in `job_log`/`hook_log`.

> **Why this phase is the safety valve (and why it's a real gate, not a formality):** the QW-1 fix taught us that the static read of a path can miss runtime behavior (the chunked recursive sync). Phase 2 is where the equivalent surprise surfaces for logging — e.g. the job logger's in-memory duration cache, or context-merge-on-update, behaving differently when mirrored to the trunk. Do NOT advance to Phase 3 until the parity report is clean. If parity reveals a trunk-design gap, fix it here while the old tables still hold ground truth.

---

## What the loggers actually do (the parity contract Phase 2 must satisfy)

Phase 2 mirrors these behaviors into the trunk. Each is a parity assertion later.

### `MealsDB_Job_Logger` (mutable lifecycle row)
- `start($job_name, $context)` → INSERT `status='running'`, `started_at`=UTC now, encoded context; returns `log_id`; **caches `started_at` in the in-memory `$started_unix[$log_id]` map** so finish/fail can compute duration without a SELECT.
- `finish($id, $stats)` → UPDATE `status='success'`, `completed_at`=now, `duration_seconds`=now−started (from the cache), the four `records_*` counters mapped from `$stats`, and **non-counter stats merged into the `context` JSON** (via `extract_extra_context` + `encode_context($extra, $log_id)` which RE-READS and merges the existing context).
- `fail($id, $msg, $stats)` → same as finish but `status='failure'`, `error_message`=sanitized, AND a separate `MealsDB_Logger::error()` line to the PHP log.
- `heartbeat($id, $stats)` → UPDATE only the `records_*` counters, status unchanged (so the daily report can spot a hung `running` row that is/ isn't making progress).
- Context: `wp_json_encode(..., depth 10)`, hard cap **16384 bytes** → replaced with `{truncated:true, orig_size:N}`.
- Readers: `last_success` (latest `completed_at` where status=success), `recent_runs` (ORDER BY `started_at` DESC, limit capped 1–500), `latest_in_window` (most recent any-status row with `started_at >= since`).

### `MealsDB_Hook_Logger` (immutable point event)
- `record($hook, $target_type, $target_id, $context, $outcome, $error)` → single INSERT, no SELECTs, no updates. `fired_at`=UTC now. `outcome` validated to `processed|skipped|errored` (invalid → `processed`). `target_type` trimmed to **20 chars**. Context `wp_json_encode(..., depth 6)`, kept only if **≤ 4096 bytes** (silently dropped if larger — note the DIFFERENT cap from job logger). Insert failure → swallowed to `error_log`, never thrown.
- Readers: `count_in_window`, `count_by_outcome` (grouped within a UTC window), `last_fire`, `trailing_window_counts`.

### The trunk mapping (from the parent directive)
- Job `status` → trunk `outcome`: `running→running`, `success→succeeded`, `failure→failed`. (A `timeout` status, if ever set, → `failed`.)
- Hook `outcome` → trunk `outcome`: `processed→succeeded`, `skipped→succeeded` (keep the skip reason in context), `errored→degraded` (it was caught/swallowed — this is the whole point of the `degraded` value).
- Job rows: `category='job'`, `event=$job_name`, lifecycle columns populated.
- Hook rows: `category='hook'`, `event=$hook_name`, `entity_type=$target_type`, `entity_id=$target_id`.

---

## Step 1 — Add dual-write to the two loggers (additive; old behavior untouched)

The principle: **the existing old-table writes stay exactly as they are.** You ADD a trunk emit alongside them, wrapped so a trunk failure can never affect the old write or the caller. Do NOT yet convert the loggers into facades — that's Phase 4. Right now they do their normal thing *plus* mirror to the trunk.

### Job logger

In `start()`, after the successful old-table insert and `$log_id` capture, mirror to the trunk and remember the trunk row id alongside the cached start time:

```php
        $log_id = (int) $wpdb->insert_id;
        self::$started_unix[$log_id] = $now;

        // STR-LOG Phase 2: mirror to the trunk. Never let a trunk failure
        // affect the authoritative job_log write or the caller.
        self::$trunk_id[$log_id] = self::dual_start($job_name, $context, $started);

        return $log_id;
```

Add a `private static $trunk_id = [];` map and a guarded helper:

```php
    /** STR-LOG Phase 2 dual-write. Returns the trunk log_id or 0; never throws. */
    private static function dual_start(string $job_name, array $context, string $started_utc): int {
        try {
            if (!class_exists('MealsDB_Event_Log')) {
                return 0;
            }
            return (int) MealsDB_Event_Log::start_job([
                'category'   => 'job',
                'event'      => $job_name,
                'started_at' => $started_utc,
                'context'    => $context,
            ]);
        } catch (\Throwable $e) {
            // Mirror failure must be invisible to the caller. The old table is
            // still authoritative in Phase 2.
            MealsDB_Logger::error('[Job Logger] trunk mirror start failed: ' . $e->getMessage());
            return 0;
        }
    }
```

In `update_row()` (the shared finish/fail path), after the existing `$wpdb->update(...)`, mirror the terminal state and then clear BOTH caches:

```php
        $wpdb->update($table, $update, ['log_id' => $log_id], $formats, ['%d']);

        // STR-LOG Phase 2: mirror the terminal state to the trunk row.
        self::dual_finish($log_id, $status, $error_message, $stats, $duration);

        unset(self::$started_unix[$log_id], self::$trunk_id[$log_id]);
```

```php
    private static function dual_finish(int $log_id, string $status, ?string $error_message, array $stats, ?int $duration): void {
        try {
            if (!class_exists('MealsDB_Event_Log')) {
                return;
            }
            $trunk_id = self::$trunk_id[$log_id] ?? 0;
            if ($trunk_id <= 0) {
                return; // start() mirror failed; nothing to update
            }
            $outcome = $status === 'success' ? 'succeeded' : 'failed';
            if ($status === 'failure') {
                MealsDB_Event_Log::fail_job($trunk_id, $error_message, $stats, $duration);
            } else {
                MealsDB_Event_Log::finish_job($trunk_id, $outcome, $stats, $duration);
            }
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[Job Logger] trunk mirror finish failed: ' . $e->getMessage());
        }
    }
```

`heartbeat()` similarly mirrors counter updates to the trunk row id (guarded the same way). Keep the existing old-table `heartbeat` update exactly as is; add a guarded `MealsDB_Event_Log::heartbeat($trunk_id, $stats)` after it.

> **Parity nuance — duration:** the trunk must compute/store the SAME `duration_seconds` the old path does. The old path uses the in-memory `$started_unix` cache (now−started). Pass that computed `$duration` into `dual_finish` (as above) rather than letting the trunk recompute from its own row — otherwise a millisecond of clock drift between the two writes produces off-by-one duration mismatches that the parity check would (correctly) flag. Reuse the already-computed value.

> **Parity nuance — context merge:** the old `finish()` merges non-counter stats into the EXISTING context (re-reading the row). The trunk's `finish_job` must do the same merge against the trunk row, or context parity fails. Mirror the merge semantics, not just the final write.

### Hook logger

`record()` is a single insert with no lifecycle, so the mirror is one guarded call after the existing insert:

```php
        $result = $wpdb->insert($table, $data, $formats);
        if ($result === false) {
            MealsDB_Logger::error('[Hook Logger] insert failed for ' . $hook_name . ': ' . $wpdb->last_error);
        }

        // STR-LOG Phase 2: mirror to the trunk (guarded; never throws).
        self::dual_record($hook_name, $target_type, $target_id, $context, $outcome, $error_message);
```

```php
    private static function dual_record(string $hook_name, ?string $target_type, ?int $target_id, array $context, string $outcome, ?string $error_message): void {
        try {
            if (!class_exists('MealsDB_Event_Log')) {
                return;
            }
            // Hook outcome → trunk outcome. errored → degraded (it was caught).
            $map = [
                self::OUTCOME_PROCESSED => 'succeeded',
                self::OUTCOME_SKIPPED   => 'succeeded',
                self::OUTCOME_ERRORED   => 'degraded',
            ];
            MealsDB_Event_Log::record([
                'severity'    => $outcome === self::OUTCOME_ERRORED ? 'warning' : 'info',
                'category'    => 'hook',
                'event'       => $hook_name,
                'outcome'     => $map[$outcome] ?? 'succeeded',
                'entity_type' => ($target_type !== null && $target_type !== '') ? substr($target_type, 0, 30) : null,
                'entity_id'   => ($target_id !== null && $target_id > 0) ? $target_id : null,
                'message'     => $error_message,                 // sanitized inside record()
                'context'     => self::merge_skip_reason($context, $outcome),
            ]);
        } catch (\Throwable $e) {
            MealsDB_Logger::error('[Hook Logger] trunk mirror failed for ' . $hook_name . ': ' . $e->getMessage());
        }
    }
```

> **Parity nuance — `skipped`:** the old hook log distinguishes `skipped` from `processed`; the trunk maps both to `succeeded`. To avoid LOSING that distinction (parity must be reversible), stash the original hook outcome in context when it's `skipped` (`merge_skip_reason` adds `['hook_outcome' => 'skipped']`). The parity check then maps old `skipped` → trunk `succeeded` + `context.hook_outcome=skipped`.

---

## Step 2 — Run a representative workload (generate rows in both tables)

On staging, exercise every writer so both tables fill with comparable rows:

1. **Jobs:** trigger each cron job once — `wp cron event run mealsdb_nightly_allocation_sync mealsdb_nightly_sync mealsdb_nightly_task_sync mealsdb_daily_report mealsdb_log_retention` — including at least one that FAILS (force an error) and one that runs long enough to `heartbeat`.
2. **Hooks:** create, trash, and delete an order; create/update a client; run a sync — exercising `processed`, `skipped`, and `errored` outcomes.
3. Let it produce a few dozen rows per table minimum, spanning all outcomes and at least one context-truncation case (pass an oversized context to a job).

---

## Step 3 — The parity verification (the gate)

Build a one-off verification script `tests/verify-trunk-parity.php` (or a `wp eval-file`) that proves, row-for-row, that every old-table row has a faithful trunk counterpart. This is the gate to Phase 3.

### Job parity — for each `meals_job_log` row, find its trunk row (`category='job'`, matched by event+started_at) and assert:
- `status → outcome`: success→succeeded, failure→failed, running→running.
- `started_at`, `completed_at` equal (UTC, to the second).
- `duration_seconds` equal (this is the off-by-one risk — see the duration nuance).
- `records_processed/updated/skipped/errored` equal.
- `error_message` equal (both run through `sanitize_for_log` — confirm same sanitized output).
- `context`: same merged keys/values (decode both JSONs and compare; account for the truncation sentinel if size>16K).
- Truncation parity: a row truncated in job_log (`{truncated:true}`) is truncated in the trunk too.

### Hook parity — for each `meals_hook_log` row, find its trunk row (`category='hook'`, matched by event+fired_at) and assert:
- `outcome → trunk outcome`: processed→succeeded, skipped→succeeded(+`context.hook_outcome=skipped`), errored→degraded.
- `fired_at` → `occurred_at` equal.
- `target_type/target_id` → `entity_type/entity_id` equal (note hook trims to 20, trunk to 30 — values ≤20 are unaffected; flag any >20).
- `error_message` equal.
- `context` equal (note the DIFFERENT cap: hook 4096/depth6 vs trunk's 16384/depth10 — a context that FIT in hook log must fit identically in the trunk; assert byte-for-byte for ≤4096 cases).

### Reader parity — call each old reader and its trunk-query equivalent over the same data, assert identical results:
- `Job_Logger::last_success($job)` vs trunk `WHERE category='job' AND event=$job AND outcome='succeeded' ORDER BY completed_at DESC LIMIT 1`.
- `recent_runs($job, $n)` — same rows, same order (`started_at DESC`).
- `latest_in_window($job, $since)` — same row.
- `Hook_Logger::count_by_outcome($hook, $start, $end)` — counts match AFTER mapping (trunk degraded == hook errored; trunk succeeded == hook processed+skipped, so reconstruct skipped from `context.hook_outcome`).
- `count_in_window`, `last_fire`, `trailing_window_counts` — equal.

### Output
The script prints, per category, `N rows checked, M mismatches` and lists every mismatch with both values. **Clean = zero mismatches across all three sections.** Any mismatch is a Phase-2 blocker — fix the trunk/mirror, re-run, do not advance.

> **Expected, acceptable "mismatches" to encode as passes:** the `skipped→succeeded+context` reconstruction and the hook/trunk outcome remap are intentional transforms, not failures — the script must apply the mapping before comparing, not compare raw values. The count_by_outcome reconstruction is the trickiest; get it right or it'll throw false positives.

---

## Step 4 — Soak (optional but recommended given the moved timeline)

Leave dual-write running for a short soak window (a day or two of real staging cron cycles) and re-run the parity script at the end. This catches anything the one-shot workload missed — a job that only the real nightly schedule exercises, a hook firing pattern the manual test didn't reproduce. Given the launch moved to accommodate this, the soak is cheap insurance and directly addresses the "static read misses runtime behavior" lesson from QW-1.

---

## Out of scope for Phase 2 (explicitly)
- **Do NOT cut over any reads** — daily-report, cron-status-page still read the OLD tables. That's Phase 3, and only after parity is clean.
- **Do NOT remove any old-table writes** or convert the loggers to pure facades — that's Phase 4.
- **Do NOT touch retention** — `MealsDB_Log_Retention` still prunes the old tables only; the trunk's retention is Phase 4. (Be aware: in Phase 2 the trunk grows unpruned. For a short phase that's fine; if the soak runs long, prune the trunk manually or note it.)
- **Do NOT touch the audit log.**

## Acceptance criteria (Phase 2)
- [ ] Both loggers dual-write: old-table write UNCHANGED, trunk mirror added, mirror wrapped so a trunk failure never affects the old write or the caller (guarded try/catch, falls back to `error_log`).
- [ ] Duration is computed ONCE (old path) and passed into the trunk mirror — no recompute, no drift mismatch.
- [ ] Context-merge semantics mirrored (finish/heartbeat merge into existing trunk context, matching the old behavior).
- [ ] `skipped` distinction preserved in trunk context so parity is reversible.
- [ ] Representative workload run; both tables populated across all outcomes incl. a failure, a heartbeat, and a truncation case.
- [ ] `verify-trunk-parity.php` reports ZERO mismatches across job-write, hook-write, and reader-equivalence sections (with the intentional outcome-remap applied before comparison).
- [ ] (Recommended) Soak window run; parity re-verified clean afterward.
- [ ] Old tables remain authoritative — no reads or writes removed in this phase.

## Exit → Phase 3
Only when the parity report is clean (and ideally soak-confirmed) do you proceed to Phase 3 (cut the readers over to the trunk). If parity surfaced a trunk-design gap, it was fixed here while the old tables still held ground truth — which is exactly what this phase exists to guarantee.
