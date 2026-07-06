<?php
/**
 * Lightweight GitHub-based update checker compatible with the Plugin Update Checker interface.
 */

namespace {
    if (!class_exists('MealsDBGithubVcsApi')) {
        class MealsDBGithubVcsApi {
            private $releaseAssets = false;

            // The parent checker is accepted for Plugin Update Checker API
            // shape compatibility but not stored — this shim only exposes the
            // release-asset toggle below, which never needs a back-reference.
            public function __construct($checker) {
            }

            public function enableReleaseAssets() {
                $this->releaseAssets = true;
                return $this;
            }

            public function releaseAssetsEnabled() {
                return $this->releaseAssets;
            }
        }

        class MealsDBGithubUpdateChecker {
            private $metadataUrl;
            private $pluginFile;
            private $slug;
            private $pluginBasename;
            private $vcsApi;

            public function __construct($metadataUrl, $pluginFile, $slug) {
                $this->metadataUrl = rtrim($metadataUrl, '/');
                $this->pluginFile = $pluginFile;
                $this->slug = $slug;
                $this->pluginBasename = plugin_basename($this->pluginFile);
                $this->vcsApi = new MealsDBGithubVcsApi($this);

                if (function_exists('add_filter')) {
                    add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdates']);
                    add_filter('plugins_api', [$this, 'pluginsApi'], 10, 3);
                    // SHA-256 gate for the native "update now" flow.
                    add_filter('upgrader_pre_download', [$this, 'verifyDownload'], 10, 4);
                }
            }

            public function setBranch($branch) {
                // No-op: this shim only supports release-based updates —
                // fetchLatestRelease() always queries `releases/latest`, so a
                // configured branch has no effect on where updates come from.
                // The setter is retained purely for Plugin Update Checker API
                // compatibility with the meals-db-main.php caller.
            }

            public function getVcsApi() {
                return $this->vcsApi;
            }

            public function checkForUpdates($transient) {
                if (empty($transient->checked) || !function_exists('wp_remote_get')) {
                    return $transient;
                }

                $currentVersion = isset($transient->checked[$this->pluginBasename])
                    ? $transient->checked[$this->pluginBasename]
                    : null;

                if (!$currentVersion) {
                    $currentVersion = $this->getCurrentVersion();
                }

                $release = $this->fetchLatestRelease();
                if (!$release || empty($release['version'])) {
                    return $transient;
                }

                $remoteVersion = $release['version'];
                if ($currentVersion && version_compare($this->normalizeVersion($remoteVersion), $this->normalizeVersion($currentVersion), '<=')) {
                    return $transient;
                }

                $update = (object) [
                    'slug'        => $this->slug,
                    'plugin'      => $this->pluginBasename,
                    'new_version' => $remoteVersion,
                    'url'         => $this->metadataUrl,
                    'package'     => !empty($release['package']) ? $release['package'] : '',
                ];

                $transient->response[$this->pluginBasename] = $update;
                return $transient;
            }

            public function pluginsApi($result, $action, $args) {
                if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) {
                    return $result;
                }

                $release = $this->fetchLatestRelease();
                if (!$release) {
                    return $result;
                }

                $info = (object) [
                    'name'          => $this->slug,
                    'slug'          => $this->slug,
                    'version'       => $release['version'],
                    'homepage'      => $this->metadataUrl,
                    'download_link' => !empty($release['package']) ? $release['package'] : $release['zipball'],
                    'sections'      => [
                        'description' => !empty($release['body']) ? $release['body'] : 'Automatic update via GitHub releases.',
                    ],
                ];

                return $info;
            }

            private function fetchLatestRelease() {
                $repoParts = $this->parseRepository();
                if (!$repoParts) {
                    return null;
                }

                $url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', $repoParts['owner'], $repoParts['repo']);
                $args = [
                    'headers' => [
                        'Accept'     => 'application/vnd.github+json',
                        'User-Agent' => 'Meals-Database-Update-Checker',
                    ],
                    'timeout' => 15,
                ];

                $response = wp_remote_get($url, $args);
                if (is_wp_error($response)) {
                    return null;
                }

                $code = wp_remote_retrieve_response_code($response);
                if ($code !== 200) {
                    return null;
                }

                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                if (!is_array($data)) {
                    return null;
                }

                $version = '';
                if (!empty($data['tag_name'])) {
                    $version = ltrim($data['tag_name'], 'v');
                } elseif (!empty($data['name'])) {
                    $version = ltrim($data['name'], 'v');
                }

                // Select the package by ASSET NAME, not positionally. A release
                // that attaches a non-plugin asset first (a SHA256SUMS.txt, a
                // screenshot) would otherwise be installed as the plugin.
                $package = self::selectPackageUrl($data, $this->vcsApi->releaseAssetsEnabled());

                return [
                    'version' => $version,
                    'package' => $package,
                    'zipball' => !empty($data['zipball_url']) ? $data['zipball_url'] : '',
                    'body'    => !empty($data['body']) ? $data['body'] : '',
                    // Expected archive checksum, if the release notes carry one.
                    'sha256'  => self::extractSha256FromBody(!empty($data['body']) ? (string) $data['body'] : ''),
                ];
            }

            /**
             * Choose the release package URL by asset name (a .zip, preferring
             * the plugin slug) rather than trusting assets[0]. Falls back to the
             * GitHub zipball when no suitable asset is present or assets are off.
             *
             * @param array $data         Decoded GitHub "latest release" JSON.
             * @param bool  $assetsEnabled Whether release assets are preferred.
             * @return string Package URL, or '' if none.
             */
            public static function selectPackageUrl(array $data, $assetsEnabled) {
                if ($assetsEnabled && !empty($data['assets']) && is_array($data['assets'])) {
                    $firstZip = '';
                    foreach ($data['assets'] as $asset) {
                        if (!is_array($asset)) {
                            continue;
                        }
                        $name = isset($asset['name']) ? (string) $asset['name'] : '';
                        $url  = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
                        if ($url === '' || !preg_match('/\.zip$/i', $name)) {
                            continue;
                        }
                        // Strongly prefer an asset that names the plugin.
                        if (stripos($name, 'meals-db') !== false) {
                            return $url;
                        }
                        if ($firstZip === '') {
                            $firstZip = $url;
                        }
                    }
                    if ($firstZip !== '') {
                        return $firstZip;
                    }
                }
                return !empty($data['zipball_url']) ? (string) $data['zipball_url'] : '';
            }

            /**
             * Pull a "SHA256: <hex>" line out of GitHub release notes.
             *
             * Delegates to the ONE canonical implementation
             * (MealsDB_Updates::extract_sha256_from_release_body) so the two
             * GitHub-release stacks can no longer drift apart (audit
             * U12-bootstrap-6). Falls back to the identical regex inline when
             * this shim is loaded standalone — the autoloader (and thus
             * MealsDB_Updates) is not registered under the update-checker unit
             * test, which requires only this file.
             *
             * @param string $body
             * @return string lowercase 64-hex, or ''.
             */
            public static function extractSha256FromBody($body) {
                if (class_exists('MealsDB_Updates')) {
                    return \MealsDB_Updates::extract_sha256_from_release_body(is_string($body) ? $body : '');
                }
                if (!is_string($body) || $body === '') {
                    return '';
                }
                if (!preg_match('/sha-?256[\s:=]+([a-f0-9]{64})/i', $body, $m)) {
                    return '';
                }
                return strtolower($m[1]);
            }

            /**
             * Decide whether a downloaded archive may be installed.
             *
             * @param string $actualSha    SHA-256 of the downloaded file.
             * @param string $expectedSha  Expected SHA-256 (from release notes /
             *                              the mealsdb_release_expected_sha256 filter).
             * @param bool   $allowUnverified  MEALSDB_ALLOW_UNVERIFIED_RELEASE.
             * @return string 'ok' | 'mismatch' | 'unverified'.
             */
            public static function evaluateChecksum($actualSha, $expectedSha, $allowUnverified) {
                $expectedSha = strtolower(trim((string) $expectedSha));
                if ($expectedSha !== '') {
                    if (preg_match('/^[a-f0-9]{64}$/', $expectedSha)
                        && is_string($actualSha)
                        && hash_equals($expectedSha, strtolower($actualSha))) {
                        return 'ok';
                    }
                    return 'mismatch';
                }
                return $allowUnverified ? 'ok' : 'unverified';
            }

            /**
             * Resolve the expected SHA-256 for the current latest release: the
             * release-notes value, overridable by the mealsdb_release_expected_sha256
             * filter (e.g. a pinned constant for publisher-compromise protection).
             *
             * @return string lowercase 64-hex, or ''.
             */
            private function expectedSha256() {
                $release = $this->fetchLatestRelease();
                $sha = (is_array($release) && !empty($release['sha256'])) ? strtolower((string) $release['sha256']) : '';
                if (function_exists('apply_filters')) {
                    $sha = (string) apply_filters('mealsdb_release_expected_sha256', $sha, is_array($release) ? $release : []);
                }
                return preg_match('/^[a-f0-9]{64}$/', $sha) ? $sha : '';
            }

            /**
             * upgrader_pre_download gate: for THIS plugin only, download the
             * package and verify its SHA-256 before WordPress installs it. The
             * bundled "update now" flow previously fed the package straight into
             * core's updater with no integrity check (the checksum gate in
             * MealsDB_Updates only covered the separate no-git fallback path).
             *
             * Returns the verified local file (WP installs from it), a WP_Error
             * (aborts the update), or the unchanged $reply for other plugins.
             *
             * @param mixed  $reply
             * @param string $package
             * @param object $upgrader
             * @param array  $hookExtra
             * @return mixed
             */
            public function verifyDownload($reply, $package = '', $upgrader = null, $hookExtra = []) {
                // Let a prior filter's decision stand, and only handle our plugin.
                if ($reply !== false) {
                    return $reply;
                }
                $isOurs = is_array($hookExtra) && isset($hookExtra['plugin'])
                    && $hookExtra['plugin'] === $this->pluginBasename;
                if (!$isOurs || !is_string($package) || $package === '') {
                    return $reply;
                }

                if (!function_exists('download_url')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }

                $tmp = download_url($package);
                if (is_wp_error($tmp)) {
                    return $tmp;
                }

                $actualSha = hash_file('sha256', $tmp);
                $allowUnverified = defined('MEALSDB_ALLOW_UNVERIFIED_RELEASE') && MEALSDB_ALLOW_UNVERIFIED_RELEASE;
                $status = self::evaluateChecksum(
                    is_string($actualSha) ? $actualSha : '',
                    $this->expectedSha256(),
                    $allowUnverified
                );

                if ($status === 'ok') {
                    return $tmp; // verified (or explicitly opted out) — WP installs from here
                }

                @unlink($tmp);
                if ($status === 'mismatch') {
                    return new \WP_Error(
                        'mealsdb_puc_checksum',
                        __('The Meals DB update archive failed SHA-256 verification. Update aborted.', 'meals-db')
                    );
                }
                return new \WP_Error(
                    'mealsdb_puc_unverified',
                    __('The Meals DB release is not signed with a SHA-256 checksum. Add a "SHA256: <hex>" line to the release notes, or set MEALSDB_ALLOW_UNVERIFIED_RELEASE to true in wp-config.php to allow unverified updates.', 'meals-db')
                );
            }

            private function parseRepository() {
                $parts = wp_parse_url($this->metadataUrl);
                if (empty($parts['path'])) {
                    return null;
                }

                $path = trim($parts['path'], '/');
                $segments = explode('/', $path);
                if (count($segments) < 2) {
                    return null;
                }

                return [
                    'owner' => $segments[0],
                    'repo'  => $segments[1],
                ];
            }

            private function getCurrentVersion() {
                if (!function_exists('get_file_data')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                $data = get_file_data($this->pluginFile, ['Version' => 'Version'], 'plugin');
                return isset($data['Version']) ? $data['Version'] : null;
            }

            private function normalizeVersion($version) {
                // ONE normalize semantics, shared with
                // MealsDB_Updates::normalize_version(), so this native WP
                // updater and the admin "check for updates" flow give the SAME
                // newer-than answer. This method previously stripped ALL
                // non-[0-9.] characters, turning a pre-release tag like
                // v1.0.0-beta1 into "1.0.01" (a HIGHER version than 1.0.0),
                // while MealsDB_Updates only ltrim()'d the leading "v" and kept
                // "-beta1" (which version_compare() reads as a pre-release) —
                // so the two updaters disagreed. Real meals-db tags are plain
                // "vX.Y.Z", for which both approaches are identical, so this
                // change is behaviour-neutral for actual releases and only
                // fixes the pre-release edge case. Delegate to the canonical
                // implementation; the autoloader has long since registered by
                // the time this hook fires, with an identical-semantics inline
                // fallback for standalone loads (e.g. unit tests).
                if (class_exists('MealsDB_Updates')) {
                    return \MealsDB_Updates::normalize_version((string) $version);
                }
                return ltrim(trim((string) $version), 'vV');
            }
        }
    }
}

namespace YahnisElsts\PluginUpdateChecker\v5 {
    // Guard this facade: it squats the REAL Plugin Update Checker v5 namespace,
    // which many WP/WooCommerce plugins bundle. Declaring it unconditionally
    // fatals with "Cannot declare class ... already in use" (admin WSOD) when a
    // genuine PUC v5 co-tenant loads first. class_exists(..., false) — no
    // autoload — lets a real PucFactory win when present (it can update this
    // plugin from GitHub perfectly well) and only installs this thin facade
    // over MealsDBGithubUpdateChecker when nothing else has claimed the name.
    //
    // Caveat: if meals-db loads FIRST, a later genuine PUC v5 (which guards its
    // own declaration the same way) will skip its own and other plugins'
    // buildUpdateChecker() calls will route through OUR checker. The complete
    // fix is to call \MealsDBGithubUpdateChecker directly from the caller and
    // drop this facade entirely (see the require in meals-db-main.php); that
    // caller is outside this file's scope, so here we remove the fatal only.
    if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory', false)) {
        class PucFactory {
            public static function buildUpdateChecker($metadataUrl, $pluginFile, $slug) {
                return new \MealsDBGithubUpdateChecker($metadataUrl, $pluginFile, $slug);
            }
        }
    }
}
