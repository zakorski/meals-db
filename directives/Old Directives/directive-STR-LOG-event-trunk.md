# Directive STR-LOG: Central event-log trunk (Option A — collapse job_log + hook_log into `meals_event_log`)

**Audit reference:** recon-11 (the three logging systems; the "report success after failure" theme reframed as a caller problem), recon-14 §5 theme #1, STR findings. Operator decision: full collapse (Option A), built and verified BEFORE the shadow launch (timeline moved to accommodate; accuracy > timeliness per Q2).
**Severity:** STRUCTURAL (operator-elevated to pre-launch). **Scope:** LARGE — new table + new class + collapse of two loggers + dashboard + retention rewrite + SMTP digest + catch-site sweep. **Risk:** MEDIUM, contained by the dual-write→verify→cutover sequence below.

**Hard boundary (non-negotiable):** `meals_audit_log` is OUT OF SCOPE and stays a separate table. The audit log is a compliance artifact (append-only, long/legally-mandated retention, deliberate PII-fingerprinting via `redact_value`, sensitive-to-read). The trunk is operational (aggressively pruned, freely queried). They may share the dashboard (separate tabs) but never the table. Rule of thumb: an *attempt/outcome* → trunk; a *committed change to a data record* → audit log.

---

## Complete reference inventory (swept against the live code — this is the spine of the collapse)

Everything that touches the two tables. The migration is tractable because the calls are concentrated.

### Table-name + schema references
- `includes/class-tables.php` — `JOB_LOG` / `HOOK_LOG` constants (L21–22) and the table-list array (L46–47).
- `includes/class-schema.php` — table definitions (`JOB_LOG` L474–509, `HOOK_LOG` L510–~535).
- `includes/class-log-retention.php` — `HOOK_LOG_DAYS=90` (L26), `JOB_LOG_DAYS=365` (L27), prune calls (L48–55).

### Logger class definitions (become the facades / get retired)
- `includes/class-job-logger.php` — writes to `JOB_LOG` (L39, 111, 127, 154, 172, 188, 284). Public surface: `start`, `finish`, `fail`, `heartbeat`, `last_success`, `recent_runs`, `latest_in_window`, `_reset_started_cache`.
- `includes/class-hook-logger.php` — writes to `HOOK_LOG` (L49, 100, 118, 150, 169). Public surface: `record`, `count_in_window`, `count_by_outcome`, `last_fire`, `trailing_window_counts`; constants `OUTCOME_PROCESSED/SKIPPED/ERRORED`.

### Call sites — WRITERS (re-pointed via the facades, so these lines DON'T change)
- **Job_Logger writers (5 files, 20 calls):** `class-allocation-hooks.php` (start/heartbeat/finish/fail, L306–348), `class-daily-report.php` (L84/89/95), `class-log-retention.php` (L45/59/65), `class-sync.php` (L376/396/515/524), `class-task-cron.php` (L33/42/50).
- **Hook_Logger writers (2 files, 33 calls):** `class-allocation-hooks.php` (the `process_*_hook` helpers + OUTCOME_* constants, L49–294), `class-sync.php` (L174–362).

### Call sites — READERS (these MUST be re-expressed against the new schema)
- **Job_Logger readers:** `class-cron-status-page.php` L99 (`recent_runs`), `class-daily-report.php` L218/228 (`latest_in_window`, `last_success`).
- **Hook_Logger readers:** `class-daily-report.php` L272/274/291 (`count_by_outcome`, `trailing_window_counts`, `last_fire`).

### Tests
- `tests/test-job-logger.php`, `tests/test-hook-logger.php` (the loggers' own tests — rewrite against the trunk).
- `tests/test-daily-report.php` (mocks `wp_meals_hook_log` / `wp_meals_job_log` SQL strings — L110/129 — update to the trunk table).
- `tests/test-log-retention.php` (asserts pruning of both tables — L126/137/148 — update).
- `tests/test-allocation-hooks-swallow.php` L35 (minimal `$wpdb` so `record()` doesn't fatal — keep working).

### Lifecycle gap (capture while here)
- **Neither `meals-db-main.php` deactivation NOR `uninstall.php` references these tables** (sweep returned nothing). Consistent with recon-01's note that uninstall.php lags. The NEW `meals_event_log` table MUST be added to uninstall.php's table-drop list (and confirm the audit/job/hook tables' uninstall handling while you're there).

> **Re-run the sweep at the end** (acceptance criterion below): `grep -rn "JOB_LOG\|HOOK_LOG\|meals_job_log\|meals_hook_log\|MealsDB_Job_Logger\|MealsDB_Hook_Logger" --include=*.php . | grep -v /vendor/` must return ONLY intentional references (the retired-class facades if kept as shims, or zero if fully removed). Nothing dangling.

---

## The schema — `meals_event_log`

Collapse means the job lifecycle columns become first-class trunk columns (NULL for non-job events) so they stay indexable — NOT buried in `context`. The mutable-job-row tension (a job is `running` then flips to `success`) is handled by keeping `start()` returning a row id and `finish()/fail()` UPDATEing that row — the trunk supports both immutable point events (most rows) and the few mutable job-lifecycle rows.

```
log_id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
occurred_at     DATETIME NOT NULL                 -- UTC (gmdate)
severity        ENUM('debug','info','notice','warning','error','critical') NOT NULL DEFAULT 'info'
category        VARCHAR(50) NOT NULL              -- 'job' | 'hook' | 'allocation' | 'billing' | 'sync' | 'migration' | 'security' | 'general'
subsystem       VARCHAR(100) NULL                 -- 'allocation_rebuilder', 'invoice_generator', ...
event           VARCHAR(150) NOT NULL             -- machine key: 'nightly_sync.dirty_rebuilt', 'woocommerce_new_order'
outcome         ENUM('succeeded','failed','degraded','running') NOT NULL DEFAULT 'succeeded'
message         TEXT NULL                         -- human-readable, PII-SCRUBBED
context         JSON NULL                         -- structured detail, PII-SCRUBBED, size-capped 16KB / depth 10
entity_type     VARCHAR(30) NULL                  -- 'client'|'order'|'rule'|'user'
entity_id       BIGINT UNSIGNED NULL
correlation_id  VARCHAR(40) NULL                  -- ties a whole run/request together
user_id         BIGINT UNSIGNED NULL
-- job-lifecycle columns (NULL for non-job events) — preserved from job_log so hang-detection & duration survive:
started_at        DATETIME NULL
completed_at      DATETIME NULL
duration_seconds  INT UNSIGNED NULL
records_processed INT UNSIGNED NULL
records_updated   INT UNSIGNED NULL
records_skipped   INT UNSIGNED NULL
records_errored   INT UNSIGNED NULL

INDEX idx_occurred (occurred_at)
INDEX idx_severity (severity)
INDEX idx_category (category)
INDEX idx_outcome (outcome)
INDEX idx_event (event)
INDEX idx_entity (entity_type, entity_id)
INDEX idx_correlation (correlation_id)
INDEX idx_running (outcome, occurred_at)   -- hang detection: find stale 'running' rows
```

### The `outcome = degraded` value — the point of the whole exercise
`job_log`'s old status set was `running/success/failure/timeout` — there was no way to say "I finished but swallowed a problem," which is exactly how the nightly-resum bug reported success (recon-03/14 theme #1). The trunk adds **`degraded`** = "continued, but something went wrong" (swallowed exception, partial result, re-sum that found nothing, CREATE that failed but we pressed on). It does NOT prevent a careless caller from writing `succeeded` — but it turns "silently lied" into "explicitly chose," which is greppable and code-reviewable. The dashboard default view keys on `outcome IN ('failed','degraded')`. **This column is mandatory, not optional.**

---

## The facade — `MealsDB_Event_Log`

One write path:
```php
MealsDB_Event_Log::record([
    'severity' => 'error', 'category' => 'allocation', 'subsystem' => 'allocation_rebuilder',
    'event' => 'rebuild.dirty_month', 'outcome' => 'degraded',
    'message' => 'Dirty month could not be materialised',
    'context' => ['client_id' => 42, 'month' => '2026-05'],
    'entity_type' => 'client', 'entity_id' => 42, 'correlation_id' => $run_id,
]);
```
Plus job-lifecycle helpers that map onto the same table: `start_job()` (INSERT outcome=running, returns log_id), `finish_job($id, $stats)`, `fail_job($id, $msg, $stats)`, `heartbeat($id, $stats)`.

**`MealsDB_Job_Logger` and `MealsDB_Hook_Logger` become thin facades** delegating to `MealsDB_Event_Log`, preserving their EXACT existing method signatures (`start/finish/fail/heartbeat/last_success/recent_runs/latest_in_window`; `record/count_in_window/count_by_outcome/last_fire/trailing_window_counts` + the OUTCOME_* constants). This is why the 53 writer call sites DON'T change — they keep calling the facade. The facades translate to trunk rows:
- `Job_Logger::start($name,$ctx)` → `record(category:'job', event:$name, outcome:'running', started_at:now, context:$ctx)`, returns log_id.
- `Job_Logger::finish/fail/heartbeat` → UPDATE that row (outcome, completed_at, duration, records_*).
- `Hook_Logger::record(...)` → `record(category:'hook', event:$hook, outcome:map(processed→succeeded, skipped→succeeded[+note], errored→degraded), entity_type/id, context)`.
  - Note the mapping: hook `errored` → trunk `degraded` (it was caught/swallowed), hook `skipped` → `succeeded` (intentional no-op; keep the skip reason in context).
- The reader methods become trunk queries (`last_success`, `recent_runs`, `latest_in_window`, `count_by_outcome`, `trailing_window_counts`, `last_fire`) — re-expressed against `meals_event_log` filtering by `category`+`event`.

---

## Non-negotiable inherited disciplines (from the audit's praised strengths — regressing any is a bug)

1. **PII scrub at WRITE time.** Run `MealsDB_Logger::sanitize_for_log()` on `message` and a recursive scrubber on `context` before insert. NEVER at display time. A central sink with raw PII is a worse leak than the scattered status quo. (recon-06/11.)
2. **UTC everywhere** — `gmdate('Y-m-d H:i:s')`. (CLAUDE.md §11.)
3. **Context cap** — 16KB serialized, depth 10 (carried from job_logger). One runaway trace must not bloat the table.
4. **Fail-safe writes** — `record()` wraps its own INSERT in try/catch; on failure it falls back to `error_log()` and returns. A logging failure must NEVER escalate into the failure of the thing being logged (checkout, cron, AJAX).
5. **Retention that protects what matters** — rewrite `MealsDB_Log_Retention` to prune `meals_event_log` by severity+age (debug/info short; warning/error/critical longer), but NEVER prune an `outcome='running'` row (hang detection) and never an unresolved `degraded`/`failed` you haven't aged out. Give it real bounds (the MAJ-2 lesson: no unbounded tables). Keep `MAX_ROWS_PER_PASS` lock-awareness.

---

## The dashboard (WP-internal, no external surface — operator requirement)

- A `manage_options`-gated admin submenu page (`MealsDB_Permissions` tier). **No public REST route. No externally reachable endpoint.** If it uses admin-ajax for filtering, gate with the standard three layers (nonce + capability + rate-limit).
- **Two tabs:** "Events & Errors" (the trunk) and "Audit Trail" (`meals_audit_log`, read-only) — same page, separate tables, honoring the boundary.
- Trunk tab default view: `outcome IN ('failed','degraded')`, last 72h, newest first. Filters: severity, category, subsystem, date range, entity (`entity_type`+`entity_id` → "all events for client 42"), `correlation_id` (→ one full run/request as a thread), free-text on `event`/`message`.
- Server-rendered, `esc_html` on every field (the view layer is XSS-clean — keep it). Any CSV export routes through `MealsDB_CSV::cell()` (inherits the QW-3 negative-money fix).
- A "job runs" view that uses the lifecycle columns (`started_at`/`duration_seconds`/`records_*`/`running`) so the cron-status-page (currently reading `recent_runs`) keeps its rich job view — now sourced from the trunk.

---

## SMTP digest (operator wants reports/processes tied in)

- A scheduled sweep job (`mealsdb_event_digest`, e.g. daily ~05:00 after the daily report) that queries `WHERE outcome IN ('failed','degraded') AND occurred_at > {last_run}` and, if non-empty, emails a **scrubbed** digest via `wp_mail()`.
- **Out of the hot path** — sending happens only in the scheduled sweep, NEVER synchronously inside `record()`. A mail hiccup must not stall checkout/cron.
- The digest itself is PII-scrubbed (it leaves the server). Severity threshold configurable (default: error+critical, plus a daily degraded summary count).
- Logs its own run to the trunk (`category:'job', event:'event_digest'`).

---

## Migration sequence (do NOT big-bang — this is how Option A stays safe)

**Phase 1 — Build the sink (additive, touches nothing existing).**
Create `meals_event_log` (schema + Tables constant + uninstall drop), `MealsDB_Event_Log::record()` + job helpers with all 5 disciplines, and the read-only dashboard reading the trunk. Verify in isolation. Nothing else changes yet.

**Phase 2 — Dual-write + verify.**
Make `Job_Logger`/`Hook_Logger` write to BOTH their existing tables AND the trunk (via the facade delegating but old table writes retained). Run for a verification window (or replay representative jobs/hooks on staging). Confirm every old-table row has a faithful trunk counterpart and the dashboard/daily-report reads match the old reads. This is the safety valve: a trunk bug here is caught while the old tables are still authoritative.

**Phase 3 — Cutover reads.**
Re-point the READER call sites (daily-report L218/228/272/274/291; cron-status-page L99) to the trunk. Verify the daily report and cron-status page render identically from the trunk.

**Phase 4 — Collapse writes + retire tables.**
Stop writing the old tables (facades now write only the trunk). Rewrite `MealsDB_Log_Retention` for the trunk. Drop `meals_job_log`/`meals_hook_log` from the schema + Tables list (and add a one-time migration to drop the physical tables, or leave them empty and document). Decide: keep `Job_Logger`/`Hook_Logger` as permanent thin facades (lowest call-site churn — recommended) OR remove them and update all 53 writer sites. **Recommended: keep as facades** — the 53 sites stay untouched, the collapse is invisible to callers, and the sweep stays clean.

**Phase 5 — The catch-site sweep (the payoff).**
Go through the `catch (\Throwable $e) { ...; return false/null; }` swallow sites across the subsystems (allocation, sync, billing, migration, tasks) and add an explicit `MealsDB_Event_Log::record(outcome:'degraded', ...)` inside each catch. This is purely additive and is what makes the silent-success class finally visible. Do it last because it's safe and incremental.

---

## Testing

- **Trunk unit tests** (`tests/test-event-log.php`, new): record() writes all fields; PII scrubbing on message+context; 16KB/depth cap enforced; fail-safe (a throwing `$wpdb` doesn't propagate); UTC timestamps; `degraded` outcome stored.
- **Facade-equivalence tests:** rewrite `test-job-logger.php` / `test-hook-logger.php` against the trunk; assert the facade methods produce the same observable behavior (start→running row, finish→succeeded+duration, fail→failed, hook errored→degraded).
- **Reader-equivalence:** `test-daily-report.php` and the cron-status reads return the same shape from the trunk (update the mocked SQL strings).
- **Retention:** `test-log-retention.php` updated — prunes the trunk by severity/age, NEVER prunes `running`, bounded per pass.
- **Dashboard authz:** `manage_options` enforced; no unauthenticated path; output escaped.
- **Digest:** sweep selects only failed/degraded since last run; scrubbed; out-of-path; logs its own run.

---

## Out of scope
- `meals_audit_log` — stays separate (the hard boundary). Do not merge, do not change its retention.
- The LB-fixed code paths — only ADD a `degraded` log line inside existing catch blocks (Phase 5); do not alter LB logic.
- VAC/billing math, allocation logic — unchanged; the trunk only observes.

## Acceptance criteria
- [ ] `meals_event_log` created with the `degraded` outcome and the preserved job-lifecycle columns (indexable, not in JSON); added to uninstall.php.
- [ ] `MealsDB_Event_Log::record()` + job helpers, with ALL five inherited disciplines (PII-scrub-on-write, UTC, 16KB/depth cap, fail-safe write, retention-aware).
- [ ] `Job_Logger`/`Hook_Logger` are thin facades over the trunk; the 53 writer call sites are UNCHANGED.
- [ ] All reader sites (daily-report ×5, cron-status ×1) re-expressed against the trunk and render identically.
- [ ] `MealsDB_Log_Retention` rewritten for the trunk; never prunes `running`; bounded.
- [ ] WP-internal dashboard: `manage_options`, no external surface, two tabs (trunk + audit), default failed/degraded view, escaped output, correlation_id threading.
- [ ] SMTP digest: scheduled, out-of-path, scrubbed, failed/degraded only.
- [ ] Phase 5 catch-site sweep: swallowed exceptions across the subsystems log `degraded`.
- [ ] **Final reference sweep clean:** `grep -rn "JOB_LOG\|HOOK_LOG\|meals_job_log\|meals_hook_log" --include=*.php . | grep -v /vendor/` returns only intentional references; old tables dropped from schema + Tables list; nothing dangling.
- [ ] CLAUDE.md §6 (logging) rewritten: one trunk, the facades, the audit-log boundary, the `degraded` convention.

## Boundary restated
The audit log (`meals_audit_log`) is the one logging table that is NOT collapsed. Operational events → trunk (prune freely, query freely). Committed changes to data records → audit log (append-only, long retention, PII-fingerprinted). The dashboard shows both; the schema keeps them apart.
