# QA Review Findings

## ✅ Bootstrap and configuration loading
- The plugin guards against direct access, defines versioned constants, and registers activation hooks before loading its core cla
ss map so execution order remains deterministic. 【F:meals-db.php†L1-L86】
- `.env` loading skips comments, strips optional quotes, and pushes values into both `$_ENV` and `getenv()` for downstream depend
encies. 【F:includes/class-env.php†L12-L50】
- Database bootstrap centralizes connection pooling, disables mysqli error spam while attempting the connection, and restores the
 previous report mode even when constructors throw. Credentials must be present in the `.env`. 【F:includes/class-db.php†L12-L57】

## ✅ Cryptography prerequisites
- AES helpers enforce the expected `base64:` prefix, raise exceptions on malformed keys/payloads, and use random IVs so duplicate
 plaintexts never share ciphertext. 【F:includes/class-encryption.php†L12-L69】
- Deterministic hashes are derived from sanitized, case-insensitive values to support uniqueness checks without weakening the AES
 encryption of stored columns. 【F:includes/class-client-form.php†L236-L255】【F:includes/class-client-form.php†L812-L839】

## ✅ Client intake validation and storage
- Validation prunes transport-only keys, rejects unknown fields, and performs format checks for postal codes, phones, required fi
elds, and unique identifiers before persistence. 【F:includes/class-client-form.php†L94-L164】【F:includes/class-client-form.php†L50
8-L565】
- Saving sanitizes payloads, encrypts sensitive columns, backfills deterministic index columns, normalizes dates, and uses prepared statements for inserts. Logging covers encryption/SQL failures. 【F:includes/class-client-form.php†L166-L267】【F:includes/class
-client-form.php†L566-L699】
- Unique constraint enforcement reuses the deterministic hashes to flag duplicates even when encrypted storage randomizes the row
 values. 【F:includes/class-client-form.php†L432-L504】

## ✅ Draft workflow resilience
- Draft persistence supports create/update flows with JSON encoding, affected-row checks, and existence verification to avoid sil
ently discarding records. 【F:includes/class-client-form.php†L269-L360】
- Draft deletion validates IDs, checks affected rows, and logs missing records so operators can diagnose stale entries. 【F:includ
es/class-client-form.php†L331-L360】
- The admin drafts view decodes payloads defensively, escapes all output, and funnels resumes back through the validated intake f
orm. 【F:views/drafts.php†L1-L125】

## ✅ Sync dashboard and AJAX endpoints
- Mismatch discovery decrypts identifiers, filters ignored combinations via sanitized hashes, and wraps DB failures in `WP_Error`
 objects for UI messaging. 【F:includes/class-sync.php†L12-L131】【F:includes/class-sync.php†L132-L217】
- Single-field pushes respect capability checks, sanitize inputs, and audit successful overrides. Ignore toggles and draft AJAX ro
utes follow the same nonce/capability pattern. 【F:includes/class-ajax.php†L12-L120】【F:includes/class-sync.php†L218-L297】

## ✅ Admin surfaces and assets
- Menu registration and tab routing enforce capabilities, escape tab URLs/labels, and limit template includes to known tabs. 【F:i
ncludes/class-admin-ui.php†L12-L73】【F:views/partials/tabs.php†L1-L18】
- The add-client and sync dashboards escape dynamic content, reuse shared status messaging, and expose the same nonce used by the
 AJAX layer. 【F:views/add-client.php†L1-L104】【F:views/dashboard.php†L1-L124】
- Draft and ignored mismatch tables render sanitized cells and issue AJAX deletes/unignores with freshly generated nonces. 【F:vie
ws/drafts.php†L22-L165】【F:views/ignored.php†L1-L170】
- Admin JavaScript wires form helpers, AJAX calls, and input masks without leaking sensitive data into the DOM. 【F:assets/js/admi
n.js†L1-L119】

## ✅ Schema lifecycle
- Installer ensures column/index presence, applies charset/collation negotiated with MySQL, and creates the deterministic index s
caffolding required by the validator. 【F:includes/install-schema.php†L12-L118】
- Uninstall script reuses the runtime bootstrap to load credentials, refuses to run without `.env`, and drops plugin-owned tables
 only when retention is disabled. 【F:uninstall.php†L1-L52】

## ⚠️ Follow-up considerations
- AES key length is implicitly trusted after the base64 decode. Adding an explicit 32-byte length check would give operators fast
er feedback on misconfigured secrets and ensure OpenSSL cannot silently truncate keys. 【F:includes/class-encryption.php†L12-L46】
- Draft timestamps fall back to the Unix epoch if `created_at` is null/invalid; a defensive check could replace the display value
 with a localized “Unknown” label instead of showing 1970-01-01. 【F:views/drafts.php†L65-L97】

## Automated tests
- `php tests/test-client-form.php`
