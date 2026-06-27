# Directive STR-9 + STR-10 — Manifest versions (trivial) + crypto hardening (gated)

**Status:** mixed. STR-9 is a do-now trivial fix. STR-10 is REDUCED to two items (the other two
were already fixed — see below), and both remaining ones are crypto-FORMAT migrations with a real
re-index/re-encrypt cost — NOT quick wins. Recommendation: ship STR-9 now; treat STR-10a/b as a
deliberate, harness-driven hardening pass, very likely POST-launch.
**Verified at:** v1.0.418.

---

## STR-10 SCOPE CORRECTION (verify-first — two of four were already done)

- **STR-10c — `reencrypt_legacy` cursor gap: ALREADY FIXED.** `MealsDB_Encryption_Migrator::
  reencrypt_legacy()` now selects `WHERE col IS NOT NULL AND col <> '' ORDER BY client_id ASC
  LIMIT batch_size`, with a comment explaining it's structured so repeated calls CONVERGE to a
  clean "no more legacy" termination (plus a `FAILURE_THRESHOLD` circuit breaker). The "rows past
  batch_size never re-encrypt" concern does not apply. No action.
- **STR-10d — `install()` continues past CREATE failure: EFFECTIVELY ADDRESSED.** The upgrade path
  (`mealsdb_maybe_upgrade_schema`) is version-gated, lock-guarded, and has stale-lock recovery; it
  no longer blindly marks schema current independent of outcome. No action (re-confirm during the
  STR-8 migration dry-run, since that exercises the same install path).

So STR-10 remaining = **just 10a (unsalted index hash) and 10b (shared cipher/MAC key).**

---

## PART 1 — STR-9: manifest version strings (DO NOW, trivial, no behavior)

The plugin declares versions far below the real target. Real target: PHP 8.2 / WP 7.0 (and the
codebase USES 8.x features — typed properties, `match`, `\Throwable` in cron handlers).

Current (verified):
- `meals-db-main.php:12` — `Requires PHP: 7.4`
- `meals-db-main.php:13` — `Requires at least: 5.8`
- `composer.json:7` — `"php": ">=7.4"`

Change to the real floor. Suggested:
- `Requires PHP: 8.1` (or 8.2 if you want to pin to the deploy target; 8.1 is a safe floor for the
  language features in use)
- `Requires at least: 6.0` (WP; set to your true minimum)
- `Tested up to:` — add it, set to the WP version you actually run (7.0).
- `composer.json` — `"php": ">=8.1"`.

This is metadata honesty: it stops WP from activating the plugin on an interpreter too old to run
it (where it would fatal at runtime instead of refusing cleanly at activation). No tests needed
beyond confirming the suite still runs; pure header/manifest change.

---

## PART 2 — STR-10a + STR-10b: crypto hardening (GATED — read the cost first)

Both are legitimate defense-in-depth, and both are **format migrations**, not edits. The data
isn't live yet (pre-launch), which is the ONE window where these are cheapest — but they still
carry real lock-yourself-out risk if done wrong. Decision up front: **do these as a deliberate
hardening pass driven by `MealsDB_Encryption_Migrator`, with a full re-index/re-encrypt, ideally
after the system is proven in shadow but BEFORE real PII volume accumulates.** If shadow will
carry real PII, do them before shadow. The point is they need the migrator harness and a
verification pass — never a bare code edit.

### STR-10a — salt the index hash (`create_index`)
Today (line ~374): `return hash('sha256', strtolower(trim($plaintext)));` — unsalted. A DB leak
exposes these to offline dictionary attack (government IDs have low entropy / known formats).

Fix: make it a keyed HMAC, not a bare hash:
```php
public static function create_index(string $plaintext): string {
    $key = self::get_index_key(); // derived from the master key (see 10b), distinct from cipher key
    return hash_hmac('sha256', strtolower(trim($plaintext)), $key);
}
```
**Migration cost (the catch):** every existing `*_index` column value becomes invalid the moment
this changes, because the hash output changes. ALL index-based lookups
(`find_*_by_*_index`, dedup checks, the `create_index` call sites) break until every encrypted row
is re-indexed. So this MUST be paired with a migrator pass that recomputes every `*_index` column
from the decrypted source value. Sequence:
1. Add `create_index_v2` (HMAC) alongside the old `create_index`; do NOT remove the old yet.
2. Extend `MealsDB_Encryption_Migrator` with a re-index pass: for each row, decrypt the source,
   recompute the index with v2, write it. Same batched/ORDER BY/circuit-breaker discipline the
   reencrypt pass already uses.
3. Flip lookups to v2 only after the re-index completes (a version flag, like the cipher's
   legacy-format handling).
4. Retire the old `create_index`.
Pre-launch with little/no data, steps 1-4 collapse to "change it + run the migrator once" — but
the migrator pass is still REQUIRED so it's correct if there's ANY existing data.

### STR-10b — separate cipher and MAC keys
Today: `openssl_encrypt(..., $key, ...)` (line ~157) and `hash_hmac('sha256', ..., $key, ...)`
(line ~170) use the SAME `$key`. Low severity (encrypt-then-MAC with one key isn't catastrophic),
but best practice is distinct subkeys so a weakness in one construction can't interact with the
other.

Fix: derive two subkeys from the master via HKDF (or two labeled HMACs):
```php
private static function cipher_key(): string { return hash_hmac('sha256', 'cipher', self::master_key(), true); }
private static function mac_key(): string    { return hash_hmac('sha256', 'mac',    self::master_key(), true); }
```
Use `cipher_key()` for `openssl_encrypt` and `mac_key()` for the HMAC. The index key (10a) is a
THIRD derived subkey (`'index'`).
**Migration cost:** changing the MAC key invalidates the HMAC over every existing ciphertext — so
existing values fail `hash_equals` verification on read. The decrypt path ALREADY has a
legacy-format branch (the "min 49 bytes / new format with HMAC" check at line ~190); STR-10b adds
a dimension to that: legacy values were MAC'd with the old (shared) key. So the migrator's
reencrypt pass must read legacy values with the OLD key derivation and rewrite them with the new
split keys. This dovetails with the existing `reencrypt_legacy` — extend it to treat
shared-key-MAC values as a legacy format to convert.

### Why 10a and 10b go together
Both are subkey-derivation changes off the master key, and both need the SAME migrator
re-encrypt/re-index pass. Doing them in one hardening pass means ONE walk over the encrypted rows
(decrypt → re-encrypt with split keys → recompute v2 index → write), one verification, one
version flag flip. Doing them separately means two passes over the same data. Bundle them.

---

## TESTS

**STR-9:** none beyond confirming the suite still runs (metadata only).

**STR-10a/b** (extend `tests/test-encryption.php` + the migrator test):
- T-1 round-trip with split keys: encrypt→decrypt works with distinct cipher/mac keys.
- T-2 tamper detection still fires: a flipped byte fails the (new mac-key) HMAC.
- T-3 index HMAC: `create_index_v2` is deterministic, differs from the old bare hash, and matches
  for equal normalized inputs.
- T-4 legacy-format read: a value encrypted under the OLD shared key still decrypts via the legacy
  branch (no data lock-out during transition).
- T-5 migrator re-encrypt+re-index: a legacy row is converted to split-key format AND its index is
  recomputed to v2; the pass is idempotent (second run = no-op) and honors the circuit breaker.
- T-6 lookups work post-migration: `find_*_by_index` succeeds against v2 indexes after the pass.

Full suite green; the encryption + migrator tests are the regression-sensitive ones.

---

## ACCEPTANCE CRITERIA

1. (STR-9) Manifest declares the real PHP/WP floor in `meals-db-main.php` + `composer.json`; suite
   still green.
2. (STR-10a) `create_index` is a keyed HMAC under a derived index subkey; every existing `*_index`
   recomputed by a migrator pass; lookups work post-migration.
3. (STR-10b) cipher and MAC use distinct derived subkeys; existing values read via the legacy
   branch and are converted by the SAME migrator pass.
4. No data lock-out at any point (legacy-format read path preserved until conversion completes).
5. The re-encrypt/re-index pass is batched, ordered, idempotent, circuit-broken (reuses the
   existing migrator discipline).
6. Tests T-1..T-6 green; full suite green.

---

## RECOMMENDATION / SEQUENCING

- **STR-9: now.** Five-minute change, removes a real "activates on too-old PHP then fatals" trap.
- **STR-10a/b: deliberate hardening pass, gated on the data situation.** If shadow carries real
  PII → before shadow. If shadow is synthetic/low-PII → after shadow, before hard-launch PII
  volume. Either way: via the migrator harness, with the re-index/re-encrypt pass and T-1..T-6, not
  a bare edit. These are the LAST crypto-format change you want to make after data accumulates, so
  the pre-launch window is the right time — but they are not "trivial" and should not be rushed in
  alongside STR-9 as if they were.

---

## OUT OF SCOPE

- STR-10c / STR-10d — already handled (see scope correction).
- Master-key SOURCING (wp-config constant vs wp_options) — that's the QW-2 / LB-era concern,
  already addressed (fail-closed, constant-preferred); this directive only changes how SUBKEYS are
  derived from whatever master key is configured.
- Rotating the master key itself — a separate operational procedure, not this directive.
