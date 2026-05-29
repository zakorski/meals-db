# Directive INV-DRAFT-1 — Invoice Draft Foundation (schema + draft service)

**Status:** ready to implement
**Series:** INV-DRAFT (this is directive 1 of 3)
  1. **INV-DRAFT-1 — schema + draft service** ← *this directive*
  2. INV-DRAFT-2 — review/edit admin UI + per-field audit logging
  3. INV-DRAFT-3 — finalize wiring per pipeline (VAC first, then SDNB legacy, then SDNB new portal)

**Depends on:** nothing new. Reuses QW-2 (draft payload encryption), LB-3 (finalized-month
immutability), STR-LOG (audit-log boundary). All shipped and verified in the current tree (v1.0.398).

**Goal of the series:** insert a **draft → review/edit → finalize → output** stage into the
government-invoicing pipelines so the operator (Janet) sees "what the system would send,"
edits any client's row in-browser with every edit audited, and only then emits the final
CSV/PDF. Built ONCE on the seam all three pipelines already share, so VAC and both SDNB
formats ride the same mechanism (no per-pipeline duplication).

**Goal of THIS directive:** build the persistence + service layer only. No UI yet. At the end
of this directive a draft can be created from any pipeline, stored encrypted, listed, read
back, and finalized (frozen) — all exercised by tests, not yet by a screen.

---

## Why this seam

All three generators in `class-invoice-generator.php` converge on ONE function before they
serialize to their format-specific CSV/PDF:

- `generate_sdnb_legacy()`   → calls `get_phase2_billing_data()` (line ~608)
- `generate_sdnb_new_portal()` → calls `get_phase2_billing_data()` (line ~844)
- `generate_vac_csv()`        → calls `get_phase2_billing_data()` (line ~959)

`get_phase2_billing_data($client_rows, $billing_month)` returns per-client billing rows of the
shape (verified, lines ~95–102):

```
'allocated_mains', 'allocated_sides', 'allocated_tax_sides', 'allocated_nontax_sides',
'resolved_rate', 'contribution_cents', 'basic_cents', 'tax_cents'   (+ client identity fields)
```

**The draft IS this row set, captured before serialization.** Janet edits these rows; on
finalize, each pipeline's EXISTING serializer runs over the (possibly-edited) rows. We do not
touch the serializers, the CSV-injection guard (QW-3), or the VAC PDF coordinate mapping —
they keep working exactly as verified.

---

## PRE-FLIGHT VERIFICATION (run before writing code; STOP if any check fails)

```bash
cd <plugin-root>

# 1. The shared seam exists and is called by all three pipelines.
grep -n "function get_phase2_billing_data" includes/services/class-invoice-generator.php   # expect ~158
grep -n "get_phase2_billing_data(" includes/services/class-invoice-generator.php           # expect 3 call sites (~608, ~844, ~959)
#   STOP if the function is gone or the call count changed — the seam moved; re-read before proceeding.

# 2. QW-2 payload helpers exist (we will EXTRACT them to a shared home).
grep -n "function encode_draft_payload\|function decode_draft_payload" includes/class-client-form.php
#   encode_draft_payload is currently PRIVATE (line ~1181). This directive moves the shared
#   logic to MealsDB_Encryption (see Step 2). STOP if these helpers are absent — QW-2 not present.

# 3. LB-3 finalize machinery exists (finalize reuses it; does NOT reinvent).
grep -n "function finalize_month\|is_finalized" includes/services/class-allocation-engine.php  # expect ~504, ~341
#   STOP if finalize_month is gone — the immutability anchor moved.

# 4. Existing drafts table pattern to mirror.
grep -n "DRAFTS" includes/class-tables.php   # expect meals_drafts (~13)

# 5. Encryption entry points for the shared payload helper.
grep -n "function encrypt(\|function safe_decrypt(" includes/class-encryption.php   # expect ~142, ~249
```

---

## STEP 1 — New table: `meals_invoice_drafts`

Add to `MealsDB_Tables` (`includes/class-tables.php`):

```php
public const INVOICE_DRAFTS = 'meals_invoice_drafts';
```
…and add `self::INVOICE_DRAFTS` to the `all()` array (so install/uninstall manage it; this is
the additive-only schema-sync path — STR-11 — which is correct here: we only ADD).

Add the canonical schema to `MealsDB_Schema::get_canonical_schema()`
(`includes/class-schema.php`), modeled on the existing log/draft tables:

```php
MealsDB_Tables::INVOICE_DRAFTS => [
    'table'  => MealsDB_Tables::INVOICE_DRAFTS,
    'engine' => 'InnoDB',
    'columns' => [
        'draft_id'        => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
        'pipeline'        => "ENUM('vac','sdnb_legacy','sdnb_new_portal') NOT NULL",
        // Period the invoice covers. start/end are the user-typed Y-m-d the
        // generator already takes; billing_month is substr(start,0,7) — the
        // same key get_phase2_billing_data and finalize_month use.
        'billing_month'   => 'CHAR(7) NOT NULL',            // 'YYYY-MM'
        'period_start'    => 'DATE NOT NULL',
        'period_end'      => 'DATE NOT NULL',
        // Pipeline params that aren't the period (e.g. SDNB legacy zone).
        // Small JSON; NOT PII. Lets finalize re-invoke the right serializer.
        'params'          => 'JSON NULL',
        'status'          => "ENUM('draft','finalized','superseded') NOT NULL DEFAULT 'draft'",
        // The encrypted payload: BOTH the generated snapshot and the current
        // edited state (see Step 3 for the JSON shape). Encrypted because it
        // contains client/veteran PII (names, health-card #, individual_id).
        'payload'         => 'LONGTEXT NOT NULL',
        'row_count'       => 'INT UNSIGNED NOT NULL DEFAULT 0',  // # client rows, for the list view
        'edit_count'      => 'INT UNSIGNED NOT NULL DEFAULT 0',  // # field edits applied, for the list view
        'created_by'      => 'BIGINT UNSIGNED NULL',
        'created_at'      => 'DATETIME NOT NULL',
        'finalized_by'    => 'BIGINT UNSIGNED NULL',
        'finalized_at'    => 'DATETIME NULL',
    ],
    'primary_key' => ['draft_id'],
    'indexes' => [
        ['name' => 'idx_pipeline_month', 'type' => 'INDEX', 'columns' => ['pipeline', 'billing_month']],
        ['name' => 'idx_status',         'type' => 'INDEX', 'columns' => ['status']],
        ['name' => 'idx_created',        'type' => 'INDEX', 'columns' => ['created_at']],
    ],
],
```

**Note on `status='superseded'`:** per the operator's #4 decision, regenerating creates a NEW
draft and never mutates an old one. We do NOT auto-supersede — Janet can view ALL drafts. The
`superseded` value exists for an optional future "mark this draft replaced" affordance; for now
nothing writes it. (Documenting it in the ENUM now avoids a schema change later — STR-11 can't
alter an ENUM in place.)

**uninstall.php:** `meals_invoice_drafts` is in `MealsDB_Tables::all()`, so the existing drop
loop handles it. No literal-name special-casing needed (unlike the retired log tables).

---

## STEP 2 — Promote the draft payload helpers to a shared home

The QW-2 fail-closed encryption logic currently lives PRIVATE in `class-client-form.php`
(`encode_draft_payload` line ~1181, `decode_draft_payload` ~1207). The invoice draft needs the
same discipline (encrypt PII at rest, refuse plaintext fallback). Do NOT copy-paste it — that
re-creates the dual-maintenance trap. Extract the shared logic to `MealsDB_Encryption`:

Add to `includes/class-encryption.php`:

```php
/**
 * Encode an array as an encrypted, at-rest-safe payload (QW-2 discipline:
 * fail CLOSED — never persist PII as plaintext). Returns false on failure.
 * Shared by client-form drafts and invoice drafts.
 */
public static function encode_payload(array $data) {
    $json = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);
    if (!is_string($json)) {
        return false;
    }
    try {
        return self::encrypt($json);   // self:: — we ARE the encryption class now
    } catch (\Throwable $e) {
        error_log('[MealsDB] Payload not saved: encryption failed (' . $e->getMessage()
            . '); refusing plaintext fallback.');
        return false;
    }
}

/**
 * Decode a payload written by encode_payload(), tolerating legacy plaintext
 * JSON (client-form drafts written before encryption existed). Returns null
 * if neither path yields an array.
 */
public static function decode_payload(string $stored): ?array {
    if ($stored === '') {
        return null;
    }
    $trimmed = ltrim($stored);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    $decrypted = self::safe_decrypt($stored);
    if (is_string($decrypted) && $decrypted !== '' && $decrypted !== $stored) {
        $decoded = json_decode($decrypted, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return null;
}
```

Then make `class-client-form.php` delegate to the shared helpers (keep its existing method
names/signatures so its callers don't change — this mirrors the facade approach from STR-LOG):

```php
// encode_draft_payload(): replace body with:
return MealsDB_Encryption::encode_payload($data);   // (keeps the existing class_exists guard above it if present)

// decode_draft_payload(): replace body with:
return MealsDB_Encryption::decode_payload($stored);
```

**Behavioral-parity note:** `class-client-form.php`'s `encode_draft_payload` has an extra
`class_exists('MealsDB_Encryption')` guard (refuses if the encryption class is entirely
absent). Inside `MealsDB_Encryption` itself that guard is meaningless (we're in the class).
Keep the client-form wrapper's guard if it exists, so behavior there is unchanged. This is a
verify-parity point — see test T-7.

---

## STEP 3 — The payload shape (the heart of the design)

`payload` (encrypted) decodes to:

```php
[
  'schema'    => 1,                       // payload schema version, for forward migration
  'generated' => [                        // immutable snapshot of what the system produced
     '<client_id>' => [ ...phase2 row + identity fields... ],
     ...
  ],
  'current'   => [                        // editable working copy; starts === generated
     '<client_id>' => [ ...same shape, values may be edited... ],
     ...
  ],
]
```

- **`generated`** is written once at draft creation and NEVER mutated. It is the "system said X"
  half of the audit story and the baseline every per-field edit diffs against.
- **`current`** is what Janet edits (INV-DRAFT-2). On finalize, the serializers run over
  `current`.
- Storing both in ONE encrypted blob (rather than two columns or two rows) keeps the
  encrypt/decrypt atomic and the QW-2 discipline single-pass — consistent with the persistent
  -storage guidance to combine data updated together into one key.

Each per-client row stores **the full phase-2 row plus the identity fields the serializer
needs** (last_name, first_name, individual_id/health_card, service ids, zone, etc.), so finalize
can serialize WITHOUT re-querying — the draft is self-contained. (Self-containment matters: if
finalize re-queried, an edit Janet made to, say, a name correction would be silently overwritten
by the DB value. The draft is authoritative at finalize — operator decision #1: she can edit
everything.)

**VAC-specific editable extras:** the VAC fold/HST values (the hand-work — see the Jan-2025
invoice analysis) are derived during VAC serialization, not part of the raw phase-2 row. For
the VAC pipeline, the row must additionally carry the fields Janet adjusts so they are editable
and auditable. INV-DRAFT-3 (VAC finalize) defines exactly which VAC fields; THIS directive just
guarantees the payload row is an open associative array that can hold pipeline-specific keys
(do not over-constrain it to the phase-2 keys only).

---

## STEP 4 — The draft service: `MealsDB_Invoice_Draft`

New file `includes/services/class-invoice-draft.php` (autoloads from `includes/services`).
Pure persistence/service layer — no HTML, no AJAX (those are INV-DRAFT-2).

```php
class MealsDB_Invoice_Draft {

    public const PIPELINE_VAC            = 'vac';
    public const PIPELINE_SDNB_LEGACY    = 'sdnb_legacy';
    public const PIPELINE_SDNB_NEW       = 'sdnb_new_portal';

    private const PIPELINES = [self::PIPELINE_VAC, self::PIPELINE_SDNB_LEGACY, self::PIPELINE_SDNB_NEW];

    /**
     * Create a draft from a set of phase-2 client rows. Caller (the generator
     * adapter, Step 5) supplies already-decrypted, serializer-ready rows keyed
     * by client_id. Returns draft_id, or 0 on failure (never throws).
     *
     * @param array<string,array> $rows  client_id => row
     * @param array $params              pipeline params minus the period (e.g. ['zone'=>'M'])
     */
    public static function create(string $pipeline, string $billing_month,
                                  string $period_start, string $period_end,
                                  array $rows, array $params = []): int {
        if (!in_array($pipeline, self::PIPELINES, true)) {
            return 0;
        }
        $payload = [
            'schema'    => 1,
            'generated' => $rows,
            'current'   => $rows,   // starts identical
        ];
        $encoded = MealsDB_Encryption::encode_payload($payload);
        if ($encoded === false) {
            // QW-2 fail-closed: no plaintext PII at rest. Surface as a degraded
            // operational event (STR-LOG), then bail.
            if (class_exists('MealsDB_Event_Log')) {
                MealsDB_Event_Log::record([
                    'severity'  => 'error', 'category' => 'billing',
                    'subsystem' => 'invoice_draft', 'event' => 'create.encrypt_failed',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'   => 'Invoice draft not created: payload encryption failed.',
                ]);
            }
            return 0;
        }

        global $wpdb;
        $table = MealsDB_DB::get_table_name(MealsDB_Tables::INVOICE_DRAFTS);
        $ok = $wpdb->insert($table, [
            'pipeline'      => $pipeline,
            'billing_month' => $billing_month,
            'period_start'  => $period_start,
            'period_end'    => $period_end,
            'params'        => wp_json_encode($params),
            'status'        => 'draft',
            'payload'       => $encoded,
            'row_count'     => count($rows),
            'edit_count'    => 0,
            'created_by'    => function_exists('get_current_user_id') ? (int) get_current_user_id() : null,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ], ['%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%s']);
        if ($ok === false) {
            return 0;
        }
        $draft_id = (int) $wpdb->insert_id;

        // Audit: a draft was created (committed billing artifact → audit log,
        // NOT the operational trunk — STR-LOG boundary).
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::log('invoice_draft_created', $draft_id, 'pipeline', '', $pipeline);
        }
        return $draft_id;
    }

    /** Load + decrypt a draft. Returns null if missing or undecryptable. */
    public static function get(int $draft_id): ?array { /* SELECT, decode_payload, return assoc with meta + payload */ }

    /** List drafts (newest first), optionally filtered by pipeline/month/status. Meta only — does NOT decrypt payloads (list view doesn't need PII). */
    public static function list(array $filters = []): array { /* SELECT meta columns; never returns payload */ }

    /**
     * Apply a single field edit to current[client_id][field]. Returns the
     * old value (for the caller to audit) or null on no-op/failure. Bumps
     * edit_count. Refuses if status !== 'draft'. (INV-DRAFT-2 calls this and
     * writes the audit row — this method does NOT audit, so the UI controls
     * the action/field naming.) Validation of the value is the CALLER's job
     * (INV-DRAFT-2 server-side validation); this method stores what it's given.
     */
    public static function edit_field(int $draft_id, string $client_id, string $field, $new_value) { /* ... */ }

    /**
     * Finalize: freeze the draft and hand its `current` rows to the pipeline's
     * serializer. Reuses LB-3 immutability — see Step 6. Returns the serialized
     * output (CSV string / PDF bytes) or null on failure. Refuses if already
     * finalized.
     */
    public static function finalize(int $draft_id) { /* ... see Step 6 ... */ }
}
```

Implement `get`, `list`, `edit_field` fully (straightforward `$wpdb` + `decode_payload`).
`edit_field` must: load → decode → guard `status==='draft'` → set `current[$client_id][$field]`
→ re-`encode_payload` → `UPDATE payload, edit_count=edit_count+1` → return the prior value.
All writes wrapped so a failure returns false/null and never throws (fail-safe, like the loggers).

---

## STEP 5 — Generator adapters (create-draft entry, no behavior change to existing output)

We do NOT modify the three `generate_*` methods' existing return contracts (the AJAX endpoints
in `class-ajax-invoice.php` still call them and get CSV/PDF — unchanged). Instead add, to
`class-invoice-generator.php`, three thin "build rows for draft" methods that run the SAME
query + `get_phase2_billing_data` + decryption the generators do, but RETURN the per-client row
map instead of serializing:

```php
public static function build_vac_draft_rows($start_date, $end_date): array { ... }
public static function build_sdnb_legacy_draft_rows($zone, $start_date, $end_date): array { ... }
public static function build_sdnb_new_portal_draft_rows($start_date, $end_date): array { ... }
```

Each is essentially the top half of its corresponding `generate_*` (through row assembly),
factored so BOTH the draft-row builder and the existing generator use it. **Refactor, don't
fork:** extract the shared "query → phase2 → assemble rows" body into a private helper that the
existing `generate_*` ALSO calls, so there is exactly one code path producing the rows. (This is
the same anti-duplication discipline as Step 2.) If a clean extraction is too invasive for one
pipeline in this directive, it is acceptable to extract VAC only now (VAC is INV-DRAFT-3's first
finalize target) and do SDNB extraction in INV-DRAFT-3 — but note it explicitly in the code so
the half-done state is visible.

These builders are what INV-DRAFT-2's "Generate draft" button will call, then pass to
`MealsDB_Invoice_Draft::create()`.

---

## STEP 6 — Finalize, wired to LB-3 immutability (do not reinvent the freeze)

`MealsDB_Invoice_Draft::finalize($draft_id)`:

1. Load draft; refuse if `status !== 'draft'`.
2. Serialize `current` rows through the pipeline's existing serializer. **INV-DRAFT-1 stubs
   this** — wire the actual per-pipeline serialization in INV-DRAFT-3. For now, finalize may
   serialize VAC only (or return the row set) and the SDNB branches throw a
   `not-yet-wired` guard. The point of doing finalize's SKELETON here is the freeze semantics
   below, which the schema and service must support from day one.
3. **Freeze using LB-3:** for every client in the draft, call
   `$engine->finalize_month($client_id, $billing_month)` (the SAME method LB-3/LB-1 already
   use). This is the critical reuse: it sets `is_finalized=1` on the allocation rows, so a
   later allocation rebuild CANNOT silently recompute a month whose invoice has been finalized
   — exactly the LB-1/LB-3 guarantee, now also protecting hand-edited invoice drafts. Do NOT
   build a parallel freeze flag on the draft table for this purpose; the allocation-month
   finalize IS the source of truth for "this month's billing is locked."
4. Set draft `status='finalized'`, `finalized_by`, `finalized_at`.
5. Audit: `MealsDB_Logger::log('invoice_draft_finalized', $draft_id, 'status', 'draft', 'finalized')`.
6. Return the serialized output.

**Interaction to call out explicitly (LB-1/LB-3 lesson):** because Janet can edit `current`
freely (operator decision #1, including counts that diverge from the allocation engine), the
finalized draft — not the engine — is authoritative for what was billed. Finalizing the
allocation month (step 3) ensures the engine won't later "correct" those numbers. If a month is
ALREADY finalized when finalize runs (e.g. a prior draft for the same month was finalized),
finalize must still succeed for THIS draft's output but MUST NOT error on the
already-finalized `finalize_month` call — `finalize_month` is idempotent (re-setting
is_finalized=1 is a no-op); confirm this in T-6.

---

## TESTS (new file `tests/test-invoice-draft.php`, in-memory $wpdb stub like the others)

- **T-1 create→get round-trips:** `create()` returns id>0; `get()` returns the same rows in
  both `generated` and `current`; meta fields (pipeline, month, row_count) correct.
- **T-2 payload is encrypted at rest:** the raw stored `payload` column does NOT contain a
  plaintext client name that was in the rows (assert the cleartext is absent from the column);
  `get()` recovers it. (Mirrors the QW-2 discipline.)
- **T-3 fail-closed:** with an encryption stub that throws, `create()` returns 0 and writes NO
  row (assert table empty) — never persists plaintext.
- **T-4 edit_field:** edits `current[cid][field]`, leaves `generated[cid][field]` unchanged
  (the baseline survives), returns the prior value, bumps `edit_count`. A second edit to the
  same field returns the FIRST edit's value as "old" (diffs against current, not generated —
  confirm this is the intended audit semantics with the UI directive; T-4 pins it).
- **T-5 edit refused after finalize:** finalize a draft, then `edit_field()` returns false and
  does not change the payload.
- **T-6 finalize freezes the month + is idempotent:** finalize calls `finalize_month` for each
  client; calling finalize on a month already finalized does not throw; draft status→finalized.
- **T-7 shared-helper parity (QW-2):** `MealsDB_Encryption::encode_payload`/`decode_payload`
  round-trip an array; `decode_payload` still reads a legacy plaintext-JSON string (the
  client-form back-compat path) — so promoting the helper didn't regress client-form drafts.
- **T-8 list does not leak PII:** `list()` returns meta only; assert the returned structures
  contain no decrypted payload / no client name.
- **T-9 unknown pipeline rejected:** `create('bogus', ...)` returns 0.

Run: `php tests/test-invoice-draft.php` plus the FULL suite to confirm no regression —
especially `test-client-form*` (Step 2 touched its draft helpers) and any
`test-invoice*`/`test-vac*`/`test-phase2*` (Step 5 refactor touched the generator's row
assembly). Requires `php-mbstring`+`php-gd` for the PDF tests (environmental — see CI note).

**Acceptance:** new test file green; full suite still 63/63 (now 64 files) green; the three
existing AJAX invoice endpoints still emit byte-identical CSV/PDF for an unedited run (the
refactor in Step 5 must be output-preserving — add a characterization check if practical:
generate via the old path vs. build_rows→create→finalize-with-no-edits and diff the CSV).

---

## OUT OF SCOPE (explicitly deferred)

- **The review/edit UI** and its AJAX endpoints, nonces, capability gate, server-side value
  validation, per-field audit writes → **INV-DRAFT-2**.
- **Per-pipeline finalize serialization** for SDNB legacy + new portal, and the exact VAC
  editable fold/HST fields → **INV-DRAFT-3**.
- **The Definitions page** (editable rate values $9.50 / $11.14 / $4.25 / $13.75). Separate
  work-stream; the draft layer reads whatever rates the generators currently use. When
  Definitions lands, the generators source rates from it and drafts inherit that automatically
  (drafts store resolved values, so a later rate change does NOT retro-alter an existing draft —
  a desirable property for government billing).
- **Changing what VAC actually bills** (mains-only vs. the current code's separate side lines).
  That correctness question rides in INV-DRAFT-3 / the VAC rework, informed by the Jan-2025
  invoice analysis. THIS directive is mechanism only.

---

## ACCEPTANCE CRITERIA (this directive)

1. `meals_invoice_drafts` table created via canonical schema; in `Tables::all()`; dropped by
   uninstall's existing loop.
2. `MealsDB_Encryption::encode_payload/decode_payload` exist; `class-client-form.php` delegates
   to them; client-form draft tests still pass (no regression).
3. `MealsDB_Invoice_Draft` service: create / get / list / edit_field / finalize implemented,
   fail-safe (never throws out), QW-2 fail-closed on encryption failure.
4. `payload` is encrypted at rest and carries both `generated` (immutable) and `current`
   (editable) row maps.
5. Finalize reuses `finalize_month` (LB-3) — no parallel freeze flag for month-locking.
6. Generator row-builders exist for the draft path without altering existing
   generator output (characterization diff clean for an unedited run).
7. New test file green; full suite green (with mbstring/gd present).
8. Draft create + finalize each write an audit-log row; encryption failure writes a degraded
   event to the trunk. (STR-LOG boundary respected: committed artifact → audit log;
   attempt/failure → trunk.)
