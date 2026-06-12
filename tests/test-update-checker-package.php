<?php
/**
 * SEC follow-up — the bundled GitHub update-checker shim must:
 *   (1) select the plugin's release asset by NAME (a .zip, preferring the
 *       slug), not positionally by assets[0] — otherwise a non-plugin asset
 *       attached first to a release is installed as the plugin; and
 *   (2) verify the downloaded package against a SHA-256 before WP installs it,
 *       refusing an unsigned release unless MEALSDB_ALLOW_UNVERIFIED_RELEASE.
 *
 * This covers the pure decision helpers (selectPackageUrl, extractSha256FromBody,
 * evaluateChecksum); the upgrader_pre_download wiring around them is thin.
 *
 * Run: php tests/test-update-checker-package.php
 */
if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }

require_once __DIR__ . '/../plugin-update-checker/plugin-update-checker.php';

$failures = []; $passed = 0;
function chk($got, $exp, $label) {
    global $failures, $passed;
    if ($got === $exp) { $passed++; }
    else { $failures[] = "$label: expected " . var_export($exp, true) . " got " . var_export($got, true); }
}

// ---------------------------------------------------------------------------
// [PKG-1] Asset selection ignores a non-plugin first asset and picks the .zip.
// ---------------------------------------------------------------------------
$data = ['assets' => [
    ['name' => 'SHA256SUMS.txt',     'browser_download_url' => 'https://x/sums.txt'],
    ['name' => 'meals-db-1.2.3.zip', 'browser_download_url' => 'https://x/meals-db.zip'],
], 'zipball_url' => 'https://x/zipball'];
chk(MealsDBGithubUpdateChecker::selectPackageUrl($data, true), 'https://x/meals-db.zip',
    '[PKG-1] picks the plugin .zip, not the first (non-zip) asset');

// [PKG-2] Prefer the slug-named zip even if another .zip is present first.
$data2 = ['assets' => [
    ['name' => 'extras.zip',      'browser_download_url' => 'https://x/extras.zip'],
    ['name' => 'meals-db.zip',    'browser_download_url' => 'https://x/meals-db.zip'],
], 'zipball_url' => 'https://x/zipball'];
chk(MealsDBGithubUpdateChecker::selectPackageUrl($data2, true), 'https://x/meals-db.zip',
    '[PKG-2] prefers the slug-named zip over an unrelated zip');

// [PKG-3] No usable asset → fall back to zipball_url.
$data3 = ['assets' => [
    ['name' => 'notes.txt', 'browser_download_url' => 'https://x/notes.txt'],
], 'zipball_url' => 'https://x/zipball'];
chk(MealsDBGithubUpdateChecker::selectPackageUrl($data3, true), 'https://x/zipball',
    '[PKG-3] falls back to zipball when no .zip asset exists');

// [PKG-4] Assets disabled → zipball.
chk(MealsDBGithubUpdateChecker::selectPackageUrl($data, false), 'https://x/zipball',
    '[PKG-4] zipball when release assets are disabled');

// ---------------------------------------------------------------------------
// [SHA] extractSha256FromBody pulls a SHA256 line; absent → ''.
// ---------------------------------------------------------------------------
$sha = str_repeat('a', 64);
chk(MealsDBGithubUpdateChecker::extractSha256FromBody("Release notes\nSHA256: {$sha}\nthanks"),
    $sha, '[SHA] extracts the SHA-256 from the release body');
chk(MealsDBGithubUpdateChecker::extractSha256FromBody('no checksum here'), '',
    '[SHA] absent checksum yields empty string');

// ---------------------------------------------------------------------------
// [CHK] evaluateChecksum decision matrix.
// ---------------------------------------------------------------------------
$a = str_repeat('a', 64);
$b = str_repeat('b', 64);
chk(MealsDBGithubUpdateChecker::evaluateChecksum($a, $a, false), 'ok',        '[CHK] matching sha → ok');
chk(MealsDBGithubUpdateChecker::evaluateChecksum($a, $b, false), 'mismatch',  '[CHK] differing sha → mismatch');
chk(MealsDBGithubUpdateChecker::evaluateChecksum($a, '', false), 'unverified','[CHK] no expected sha, not opted in → unverified (blocked)');
chk(MealsDBGithubUpdateChecker::evaluateChecksum($a, '', true),  'ok',        '[CHK] no expected sha but opted in → ok');
chk(MealsDBGithubUpdateChecker::evaluateChecksum($a, 'not-a-hash', false), 'mismatch',
    '[CHK] malformed expected sha → mismatch (fail closed)');

echo "Ran " . ($passed + count($failures)) . " checks: {$passed} passed, " . count($failures) . " failed\n";
foreach ($failures as $f) echo "FAIL: $f\n";
exit(empty($failures) ? 0 : 1);
