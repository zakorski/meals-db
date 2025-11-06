<?php
/**
 * Lightweight GitHub-based update checker compatible with the Plugin Update Checker interface.
 */

namespace {
    if (!class_exists('MealsDBGithubVcsApi')) {
        class MealsDBGithubVcsApi {
            private $checker;
            private $releaseAssets = false;

            public function __construct($checker) {
                $this->checker = $checker;
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
            private $branch = 'main';
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
                }
            }

            public function setBranch($branch) {
                if (!empty($branch)) {
                    $this->branch = $branch;
                }
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

                $package = '';
                if ($this->vcsApi->releaseAssetsEnabled() && !empty($data['assets'][0]['browser_download_url'])) {
                    $package = $data['assets'][0]['browser_download_url'];
                } elseif (!empty($data['zipball_url'])) {
                    $package = $data['zipball_url'];
                }

                return [
                    'version' => $version,
                    'package' => $package,
                    'zipball' => !empty($data['zipball_url']) ? $data['zipball_url'] : '',
                    'body'    => !empty($data['body']) ? $data['body'] : '',
                ];
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
                return preg_replace('/[^0-9.]/', '', (string) $version);
            }
        }
    }
}

namespace YahnisElsts\PluginUpdateChecker\v5 {
    class PucFactory {
        public static function buildUpdateChecker($metadataUrl, $pluginFile, $slug) {
            return new \MealsDBGithubUpdateChecker($metadataUrl, $pluginFile, $slug);
        }
    }
}
