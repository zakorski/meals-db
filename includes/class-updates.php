<?php
/**
 * Handles integration with the Meals DB Git repository.
 *
 * Provides helper routines that allow the admin UI to check for plugin
 * updates, pull the latest changes, and run any schema maintenance tasks
 * required by the plugin.
 */

defined('ABSPATH') || exit;

class MealsDB_Updates {

    private const REPOSITORY_OWNER = 'zakorski';
    private const REPOSITORY_NAME = 'meals-db';

    /**
     * Return diagnostic details about the current Git repository state.
     *
     * @return array|WP_Error
     */
    public static function check_for_updates() {
        // Service-layer capability re-check (defense in depth, layer 3). Checking
        // for / deploying plugin code is a manage_options operation — a baseline
        // (manage_woocommerce) caller, or any future non-AJAX caller, must not
        // reach the git/release machinery.
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return new WP_Error('mealsdb_forbidden', __('You do not have permission to check for updates.', 'meals-db'));
        }

        $currentVersion = defined('MEALS_DB_VERSION') ? MEALS_DB_VERSION : '';

        if (!self::is_git_repository()) {
            $latestRelease = self::get_remote_release_information();
            if (is_wp_error($latestRelease)) {
                return $latestRelease;
            }

            $latestVersion = $latestRelease['version'];
            $hasUpdates = $latestVersion !== ''
                && $currentVersion !== ''
                && version_compare(self::normalize_version($latestVersion), self::normalize_version($currentVersion), '>');

            $defaultMessage = $hasUpdates
                ? sprintf(
                    /* translators: %s: Meals DB latest version number */
                    __('A new version of Meals DB (%s) is available on GitHub. Download the latest release to update.', 'meals-db'),
                    $latestVersion
                )
                : __('Meals DB is up to date.', 'meals-db');

            if ($latestVersion === '') {
                $defaultMessage = !empty($latestRelease['message'])
                    ? $latestRelease['message']
                    : __('No releases or tags are published for Meals DB on GitHub. Unable to determine whether updates are available.', 'meals-db');
            }

            return [
                'current_version' => $currentVersion,
                'latest_version'  => $latestVersion,
                'has_updates'     => $hasUpdates,
                'message'         => $defaultMessage,
                'repository_url'  => self::get_repository_url(),
                'release_url'     => !empty($latestRelease['url']) ? $latestRelease['url'] : self::get_repository_url(),
                'can_auto_update' => !empty($latestRelease['zipball_url']),
                'latest_tag'      => !empty($latestRelease['tag']) ? $latestRelease['tag'] : '',
                'download_url'    => !empty($latestRelease['zipball_url']) ? $latestRelease['zipball_url'] : '',
            ];
        }

        $branch = self::get_current_branch();
        if (is_wp_error($branch)) {
            return $branch;
        }

        $statusOutput = self::run_git_command(['status', '--porcelain']);
        if (is_wp_error($statusOutput)) {
            return $statusOutput;
        }
        $isDirty = trim($statusOutput) !== '';

        $fetchResult = self::run_git_command(['fetch', 'origin', $branch]);
        if (is_wp_error($fetchResult)) {
            return $fetchResult;
        }

        $localCommit = self::run_git_command(['rev-parse', 'HEAD']);
        if (is_wp_error($localCommit)) {
            return $localCommit;
        }

        $remoteCommit = self::run_git_command(['rev-parse', 'origin/' . $branch]);
        if (is_wp_error($remoteCommit)) {
            return $remoteCommit;
        }

        $hasUpdates = trim($localCommit) !== trim($remoteCommit);

        $message = $hasUpdates
            ? __('Updates are available for the Meals DB plugin.', 'meals-db')
            : __('Meals DB is up to date.', 'meals-db');

        return [
            'branch'          => $branch,
            'current_commit'  => trim($localCommit),
            'remote_commit'   => trim($remoteCommit),
            'has_updates'     => $hasUpdates,
            'is_dirty'        => $isDirty,
            'message'         => $message,
            'repository_url'  => self::get_repository_url(),
            'current_version' => $currentVersion,
            'can_auto_update' => true,
            'latest_tag'      => '',
            'download_url'    => '',
        ];
    }

    /**
     * Pull the latest changes from the configured remote.
     *
     * @return array|WP_Error
     */
    public static function pull_updates() {
        // Service-layer capability re-check (defense in depth, layer 3). This
        // deploys code over the live plugin dir (git pull / release zip) — it
        // MUST require manage_options regardless of how it was reached.
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return new WP_Error('mealsdb_forbidden', __('You do not have permission to update the plugin.', 'meals-db'));
        }

        if (self::is_git_repository()) {
            $statusOutput = self::run_git_command(['status', '--porcelain']);
            if (is_wp_error($statusOutput)) {
                return $statusOutput;
            }

            if (trim($statusOutput) !== '') {
                return new WP_Error(
                    'mealsdb_git_dirty',
                    __('The plugin directory has uncommitted changes. Commit or stash them before updating.', 'meals-db')
                );
            }

            $branch = self::get_current_branch();
            if (is_wp_error($branch)) {
                return $branch;
            }

            $pullResult = self::run_git_command(['pull', '--ff-only', 'origin', $branch]);
            if (is_wp_error($pullResult)) {
                return $pullResult;
            }

            return [
                'branch'  => $branch,
                'output'  => $pullResult,
                'message' => __('Meals DB has been updated to the latest commit.', 'meals-db'),
            ];
        }

        $releaseInfo = self::get_remote_release_information();
        if (is_wp_error($releaseInfo)) {
            return $releaseInfo;
        }

        if (empty($releaseInfo['zipball_url'])) {
            return new WP_Error(
                'mealsdb_release_download_missing',
                __('Unable to locate a downloadable archive for the latest Meals DB release.', 'meals-db')
            );
        }

        $currentVersion = defined('MEALS_DB_VERSION') ? self::normalize_version(MEALS_DB_VERSION) : '';
        $latestVersion = !empty($releaseInfo['version']) ? self::normalize_version($releaseInfo['version']) : '';

        if ($latestVersion !== '' && $currentVersion !== '' && version_compare($latestVersion, $currentVersion, '<=')) {
            return new WP_Error(
                'mealsdb_no_updates',
                __('Meals DB is already up to date.', 'meals-db')
            );
        }

        $result = self::apply_release_update($releaseInfo);
        if (is_wp_error($result)) {
            return $result;
        }

        if (!isset($result['version']) && $latestVersion !== '') {
            $result['version'] = $latestVersion;
        }

        return $result;
    }

    /**
     * Run the database migration/maintenance routine.
     *
     * @return array
     */
    public static function run_database_maintenance() {
        // Service-layer capability re-check (defense in depth, layer 3). install()
        // runs DDL (and DROPs for defunct tables); it is a manage_options
        // operation, not a baseline one.
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return new WP_Error('mealsdb_forbidden', __('You do not have permission to update the database.', 'meals-db'));
        }

        if (!class_exists('MealsDB_Installer')) {
            require_once MEALS_DB_PLUGIN_DIR . 'includes/install-schema.php';
        }

        // Serialize against the admin_init auto-upgrade via the SAME install
        // lock, so a manual run can't race a concurrent upgrade (install()
        // continues past individual CREATE failures — two at once can corrupt
        // the build). If the lock is held, report busy rather than piling on.
        if (function_exists('mealsdb_acquire_install_lock') && !mealsdb_acquire_install_lock()) {
            return new WP_Error(
                'mealsdb_install_busy',
                __('A database update is already running. Please try again shortly.', 'meals-db')
            );
        }

        try {
            MealsDB_Installer::install();
        } catch (\Throwable $e) {
            // U11-schema-2: install() now THROWS on a real CREATE / schema-sync
            // failure (so the admin_init auto-upgrade skips the version bump and
            // retries). This user-facing endpoint must keep its documented
            // array|WP_Error contract rather than let a fatal escape to the AJAX
            // layer — convert the throw to a WP_Error so update_database()
            // renders a clean "update failed" response.
            error_log('[MealsDB Updates] Database maintenance failed: ' . $e->getMessage());
            return new WP_Error(
                'mealsdb_schema_install_failed',
                __('The database update did not complete. It will be retried automatically; check the error log for details.', 'meals-db')
            );
        } finally {
            if (function_exists('mealsdb_release_install_lock')) {
                mealsdb_release_install_lock();
            }
        }

        return [
            'message' => __('Database schema has been checked and updated.', 'meals-db'),
        ];
    }

    /**
     * Ensure every WooCommerce product has a corresponding Meals DB record.
     *
     * @return array<string, mixed>|WP_Error
     */
    public static function fetch_products_from_woocommerce() {
        // Service-layer capability re-check (defense in depth, layer 3). This
        // INSERTs rows into the meals products table; its three sibling methods
        // (check_for_updates / pull_updates / run_database_maintenance) all carry
        // this guard, but this one had none — only a function_exists() check. The
        // sole current caller (class-ajax-sync.php) gates with nonce +
        // can_access_plugin() + rate limit, but a future WP-CLI/REST caller that
        // bypasses the AJAX handler must not reach the write ungated (U12-bootstrap-4).
        // Baseline tier (can_access_plugin) because this is product CRUD, not a
        // manage_options operation like the siblings.
        if (!is_user_logged_in()
            || !class_exists('MealsDB_Permissions')
            || !MealsDB_Permissions::can_access_plugin()) {
            return new WP_Error(
                'mealsdb_forbidden',
                __('You do not have permission to synchronize products.', 'meals-db')
            );
        }

        if (!function_exists('wc_get_products')) {
            return new WP_Error(
                'mealsdb_woocommerce_missing',
                __('WooCommerce is not available.', 'meals-db')
            );
        }

        global $wpdb;

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::PRODUCTS);

        $existing_rows = $wpdb->get_results("SELECT wc_product_id FROM `{$table}`", ARRAY_A);

        if (!is_array($existing_rows)) {
            return new WP_Error(
                'mealsdb_products_query_failed',
                __('Unable to read existing products from the plugin table.', 'meals-db')
            );
        }

        $existing_ids = [];
        foreach ($existing_rows as $row) {
            if (isset($row['wc_product_id'])) {
                $existing_ids[] = (int) $row['wc_product_id'];
            }
        }

        $product_args = [
            'status' => ['publish', 'private', 'draft'],
            'limit'  => -1,
            'return' => 'ids',
        ];

        if (function_exists('apply_filters')) {
            $product_args = apply_filters('mealsdb_fetch_products_args', $product_args);
        }

        $woo_product_ids = wc_get_products($product_args);
        if (!is_array($woo_product_ids)) {
            return new WP_Error(
                'mealsdb_woocommerce_query_failed',
                __('Unable to retrieve WooCommerce products.', 'meals-db')
            );
        }

        $woo_product_ids = array_values(array_unique(array_map('intval', array_filter($woo_product_ids, 'is_numeric'))));
        $existing_ids     = array_values(array_unique(array_map('intval', $existing_ids)));

        $missing_ids = array_diff($woo_product_ids, $existing_ids);

        $created = 0;

        if (!empty($missing_ids)) {
            foreach ($missing_ids as $product_id) {
                $product_id = (int) $product_id;

                $sql = $wpdb->prepare(
                    "INSERT INTO `{$table}` (wc_product_id, product_type, taxable, main_ingredient, dietary_tags, allergen_flags, case_size, unit_cost)
                    VALUES (%d, 'meal', 0, '', NULL, NULL, 1, 0.00)
                    ON DUPLICATE KEY UPDATE wc_product_id = wc_product_id",
                    $product_id
                );

                if ($wpdb->query($sql) !== false) {
                    $created++;
                }
            }
        }

        $message = $created > 0
            ? sprintf(
                _n('Added %d missing product from WooCommerce.', 'Added %d missing products from WooCommerce.', $created, 'meals-db'),
                $created
            )
            : __('All WooCommerce products already exist in the plugin table.', 'meals-db');

        return [
            'message'             => $message,
            'created'             => $created,
            'missing_count'       => count($missing_ids),
            'woocommerce_count'   => count($woo_product_ids),
            'existing_count'      => count($existing_ids),
        ];
    }

    /**
     * Determine if the plugin directory is a Git repository.
     *
     * @return bool
     */
    private static function is_git_repository(): bool {
        return is_dir(self::get_plugin_dir() . '.git');
    }

    /**
     * Retrieve the GitHub repository URL.
     */
    private static function get_repository_url(): string {
        return sprintf('https://github.com/%s/%s', self::REPOSITORY_OWNER, self::REPOSITORY_NAME);
    }

    /**
     * Get the directory for the plugin root.
     *
     * @return string
     */
    private static function get_plugin_dir(): string {
        return trailingslashit(dirname(MEALS_DB_PLUGIN_FILE));
    }

    /**
     * Determine the currently checked-out branch.
     *
     * @return string|WP_Error
     */
    private static function get_current_branch() {
        $branch = self::run_git_command(['rev-parse', '--abbrev-ref', 'HEAD']);
        if (is_wp_error($branch)) {
            return $branch;
        }

        $branch = trim($branch);

        if ($branch === 'HEAD' || $branch === '') {
            return new WP_Error(
                'mealsdb_git_detached',
                __('Unable to determine the current Git branch. Ensure the repository is not in a detached HEAD state.', 'meals-db')
            );
        }

        return $branch;
    }

    /**
     * Execute a Git command inside the plugin directory.
     *
     * @param array $arguments
     *
     * @return string|WP_Error
     */
    private static function run_git_command(array $arguments) {
        $gitBinary = (string) apply_filters('mealsdb_git_binary', 'git');

        // Reject anything other than a bare 'git' or an absolute path that
        // points at a real executable. escapeshellcmd() does NOT make a
        // command-name argument-safe; whitespace and shell metacharacters
        // can still leak through, so a filter that returns
        // 'git --upload-pack=evil' would otherwise be interpreted as
        // 'git --upload-pack=evil' (a flag, not a binary).
        $allowed = $gitBinary === 'git'
            || (preg_match('#^/[A-Za-z0-9._/-]+$#', $gitBinary) && is_executable($gitBinary));
        if (!$allowed) {
            return new WP_Error(
                'mealsdb_git_binary',
                __('Refusing to invoke an unrecognised git binary.', 'meals-db')
            );
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = escapeshellarg($gitBinary);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $process = proc_open($command, $descriptorSpec, $pipes, self::get_plugin_dir());

        if (!is_resource($process)) {
            return new WP_Error(
                'mealsdb_git_process',
                __('Failed to run Git command.', 'meals-db')
            );
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return new WP_Error(
                'mealsdb_git_error',
                sprintf(
                    /* translators: %s: Git error output */
                    __('Git command failed: %s', 'meals-db'),
                    trim($stderr) !== '' ? trim($stderr) : __('Unknown error', 'meals-db')
                ),
                [
                    'stderr' => trim($stderr),
                    'stdout' => trim($stdout),
                    'exit'   => $exitCode,
                ]
            );
        }

        return trim($stdout);
    }

    /**
     * Download and install the latest GitHub release when Git is unavailable.
     *
     * @param array $releaseInfo
     *
     * @return array|WP_Error
     */
    private static function apply_release_update(array $releaseInfo) {
        $zipUrl = !empty($releaseInfo['zipball_url']) ? $releaseInfo['zipball_url'] : '';
        if ($zipUrl === '') {
            return new WP_Error(
                'mealsdb_release_download_missing',
                __('Unable to locate a downloadable archive for the latest Meals DB release.', 'meals-db')
            );
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tempFile = download_url($zipUrl, 300, '', self::get_github_download_headers());
        if (is_wp_error($tempFile)) {
            return new WP_Error(
                'mealsdb_release_download',
                __('Unable to download the latest Meals DB release from GitHub.', 'meals-db'),
                ['error' => $tempFile->get_error_message()]
            );
        }

        // Verify archive integrity before unpacking. Without this check, a
        // network attacker between the WP server and GitHub (or a
        // compromised release-publishing account) can ship malicious code
        // that is then copied straight into the live plugin and executed
        // on the next request. We require either:
        //   1. an explicit SHA256 from the release notes,
        //   2. a SHA256 supplied by the mealsdb_release_expected_sha256
        //      filter (e.g. from a pinned constant), or
        //   3. an explicit MEALSDB_ALLOW_UNVERIFIED_RELEASE opt-in for
        //      back-compat with releases that haven't yet started
        //      publishing checksums.
        $expectedSha = isset($releaseInfo['sha256']) && is_string($releaseInfo['sha256'])
            ? strtolower($releaseInfo['sha256'])
            : '';
        $expectedSha = (string) apply_filters(
            'mealsdb_release_expected_sha256',
            $expectedSha,
            $releaseInfo
        );

        if ($expectedSha !== '') {
            $actualSha = hash_file('sha256', $tempFile);
            if (!is_string($actualSha) || !hash_equals($expectedSha, strtolower($actualSha))) {
                self::cleanup_temp_artifacts($tempFile, null);
                return new WP_Error(
                    'mealsdb_release_checksum',
                    __('Downloaded Meals DB archive failed checksum verification. Update aborted.', 'meals-db'),
                    [
                        'expected' => $expectedSha,
                        'actual'   => is_string($actualSha) ? strtolower($actualSha) : '',
                    ]
                );
            }
        } elseif (!defined('MEALSDB_ALLOW_UNVERIFIED_RELEASE') || !MEALSDB_ALLOW_UNVERIFIED_RELEASE) {
            self::cleanup_temp_artifacts($tempFile, null);
            return new WP_Error(
                'mealsdb_release_unverified',
                __('Meals DB release is not signed with a SHA-256 checksum. Set MEALSDB_ALLOW_UNVERIFIED_RELEASE to true in wp-config.php to allow unverified updates, or have the publisher add a "SHA256: <hex>" line to the release notes.', 'meals-db')
            );
        }

        $tempDir = self::create_temp_dir(dirname($tempFile));
        if (is_wp_error($tempDir)) {
            self::cleanup_temp_artifacts($tempFile, null);
            return $tempDir;
        }

        $unzipResult = unzip_file($tempFile, $tempDir);
        if (is_wp_error($unzipResult)) {
            self::cleanup_temp_artifacts($tempFile, $tempDir);
            return new WP_Error(
                'mealsdb_release_unzip',
                __('Failed to extract the Meals DB update archive.', 'meals-db'),
                ['error' => $unzipResult->get_error_message()]
            );
        }

        $sourceDir = self::determine_update_source_dir($tempDir);
        if (is_wp_error($sourceDir)) {
            self::cleanup_temp_artifacts($tempFile, $tempDir);
            return $sourceDir;
        }

        global $wp_filesystem;
        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!WP_Filesystem()) {
            self::cleanup_temp_artifacts($tempFile, $tempDir);
            return new WP_Error(
                'mealsdb_filesystem',
                __('Unable to initialize the WordPress filesystem API.', 'meals-db')
            );
        }

        $copyResult = copy_dir(trailingslashit($sourceDir), self::get_plugin_dir(), ['.env']);
        if (is_wp_error($copyResult)) {
            self::cleanup_temp_artifacts($tempFile, $tempDir);
            return $copyResult;
        }

        self::cleanup_temp_artifacts($tempFile, $tempDir);

        $message = !empty($releaseInfo['version'])
            ? sprintf(__('Meals DB has been updated to version %s.', 'meals-db'), $releaseInfo['version'])
            : __('Meals DB has been updated from the latest release.', 'meals-db');

        $logLines = [];
        if (!empty($releaseInfo['url'])) {
            $logLines[] = sprintf(__('Release: %s', 'meals-db'), $releaseInfo['url']);
        }
        if (!empty($releaseInfo['tag'])) {
            $logLines[] = sprintf(__('Tag: %s', 'meals-db'), $releaseInfo['tag']);
        }

        return [
            'message' => $message,
            'output'  => implode("\n", array_filter($logLines)),
            'version' => !empty($releaseInfo['version']) ? $releaseInfo['version'] : '',
        ];
    }

    /**
     * Create a unique temporary directory for the update process.
     *
     * @param string $baseDir
     *
     * @return string|WP_Error
     */
    private static function create_temp_dir(string $baseDir) {
        $directory = trailingslashit($baseDir) . 'mealsdb-update-' . uniqid('', true);

        if (!wp_mkdir_p($directory)) {
            return new WP_Error(
                'mealsdb_temp_dir',
                __('Unable to create a temporary directory for the update.', 'meals-db')
            );
        }

        return $directory;
    }

    /**
     * Determine the directory within the extracted archive that contains the plugin files.
     *
     * @param string $extractionRoot
     *
     * @return string|WP_Error
     */
    private static function determine_update_source_dir(string $extractionRoot) {
        $entries = scandir($extractionRoot);
        if ($entries === false) {
            return new WP_Error(
                'mealsdb_release_empty',
                __('The update archive did not contain any files.', 'meals-db')
            );
        }

        $contents = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $contents[] = $entry;
        }

        if (empty($contents)) {
            return new WP_Error(
                'mealsdb_release_empty',
                __('The update archive did not contain any files.', 'meals-db')
            );
        }

        if (count($contents) === 1) {
            $singlePath = trailingslashit($extractionRoot) . $contents[0];
            if (is_dir($singlePath)) {
                return $singlePath;
            }
        }

        return $extractionRoot;
    }

    /**
     * Remove temporary files and directories created during the update.
     */
    private static function cleanup_temp_artifacts(?string $file, ?string $directory): void {
        if ($file && file_exists($file)) {
            @unlink($file);
        }

        if ($directory && is_dir($directory)) {
            self::delete_path($directory);
        }
    }

    /**
     * Recursively delete a file or directory.
     */
    private static function delete_path(string $path): void {
        if (!file_exists($path)) {
            return;
        }

        if (is_dir($path)) {
            $items = scandir($path);
            if ($items === false) {
                @rmdir($path);
                return;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                self::delete_path($path . DIRECTORY_SEPARATOR . $item);
            }

            @rmdir($path);
            return;
        }

        @unlink($path);
    }

    /**
     * Fetch metadata about the latest release or tag from GitHub.
     *
     * @return array|WP_Error
     */
    private static function get_remote_release_information() {
        $releaseUrl = sprintf('%s/releases/latest', self::get_repository_api_base());
        $response = wp_remote_get($releaseUrl, self::get_github_request_args());

        if (is_wp_error($response)) {
            return new WP_Error(
                'mealsdb_github_request',
                __('Unable to contact the Meals DB GitHub repository.', 'meals-db'),
                ['error' => $response->get_error_message()]
            );
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status === 404) {
            return self::get_remote_latest_tag();
        }

        if ($status !== 200) {
            return new WP_Error(
                'mealsdb_github_http',
                __('GitHub responded with an unexpected status when checking for updates.', 'meals-db'),
                ['status' => $status, 'body' => $body]
            );
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new WP_Error(
                'mealsdb_github_json',
                __('Received invalid data from GitHub when checking for updates.', 'meals-db')
            );
        }

        $tagName = !empty($data['tag_name']) && is_string($data['tag_name']) ? $data['tag_name'] : '';
        if ($tagName === '') {
            return self::get_remote_latest_tag();
        }

        return [
            'version'     => self::normalize_version($tagName),
            'tag'         => $tagName,
            'url'         => !empty($data['html_url']) ? $data['html_url'] : self::get_repository_url(),
            'zipball_url' => self::get_codeload_zip_url($tagName),
            'sha256'      => self::extract_sha256_from_release_body(
                isset($data['body']) && is_string($data['body']) ? $data['body'] : ''
            ),
        ];
    }

    /**
     * Pull a "SHA256: <hex>" line out of GitHub release notes, if present.
     *
     * Releases that include this line lock the auto-updater to a specific
     * archive checksum; without it, the updater falls back to a less-safe
     * "trust HTTPS only" path that requires the explicit
     * MEALSDB_ALLOW_UNVERIFIED_RELEASE constant to opt in.
     *
     * Public + static so the bundled update-checker shim
     * (MealsDBGithubUpdateChecker::extractSha256FromBody) can delegate here
     * instead of maintaining a second, drift-prone copy of the same regex
     * (audit U12-bootstrap-6).
     */
    public static function extract_sha256_from_release_body(string $body): string {
        if ($body === '') {
            return '';
        }
        if (!preg_match('/sha-?256[\s:=]+([a-f0-9]{64})/i', $body, $m)) {
            return '';
        }
        return strtolower($m[1]);
    }

    /**
     * Retrieve the latest tag information from GitHub.
     *
     * @return array|WP_Error
     */
    private static function get_remote_latest_tag() {
        $tagsUrl = sprintf('%s/tags?per_page=1', self::get_repository_api_base());
        $response = wp_remote_get($tagsUrl, self::get_github_request_args());

        if (is_wp_error($response)) {
            return new WP_Error(
                'mealsdb_github_request',
                __('Unable to contact the Meals DB GitHub repository.', 'meals-db'),
                ['error' => $response->get_error_message()]
            );
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return new WP_Error(
                'mealsdb_github_http',
                __('GitHub responded with an unexpected status when checking for updates.', 'meals-db'),
                ['status' => $status]
            );
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return new WP_Error(
                'mealsdb_github_json',
                __('Received invalid data from GitHub when checking for updates.', 'meals-db')
            );
        }

        if (empty($data)) {
            return [
                'version'     => '',
                'url'         => self::get_repository_url(),
                'message'     => __('GitHub does not have any releases or tags published for Meals DB yet.', 'meals-db'),
                'tag'         => '',
                'zipball_url' => '',
            ];
        }

        $tag = $data[0];

        $tagName = isset($tag['name']) && is_string($tag['name']) ? $tag['name'] : '';

        if ($tagName === '') {
            return [
                'version'     => '',
                'url'         => self::get_repository_url(),
                'message'     => __('GitHub does not have any releases or tags published for Meals DB yet.', 'meals-db'),
                'tag'         => '',
                'zipball_url' => '',
            ];
        }

        return [
            'version'     => self::normalize_version($tagName),
            'tag'         => $tagName,
            'url'         => sprintf('%s/releases/tag/%s', self::get_repository_url(), rawurlencode($tagName)),
            'zipball_url' => self::get_codeload_zip_url($tagName),
            'sha256'      => '',
        ];
    }

    /**
     * Normalize a semantic version string by trimming whitespace and removing any leading "v".
     *
     * This is the ONE canonical normalize used by both GitHub-release stacks:
     * the admin "check for updates" flow (this class) and the native WP updater
     * (MealsDBGithubUpdateChecker::normalizeVersion delegates here). It only
     * strips a leading "v"/"V" and keeps any pre-release suffix (e.g.
     * "-beta1"), which version_compare() understands — unlike a strip-all
     * approach that would turn "v1.0.0-beta1" into "1.0.01" (a HIGHER version
     * than 1.0.0) and make the two updaters disagree (audit U12-bootstrap-6).
     *
     * Public + static so the update-checker shim can consume it.
     */
    public static function normalize_version(string $version): string {
        $normalized = trim($version);
        if ($normalized === '') {
            return '';
        }

        return ltrim($normalized, "vV");
    }

    /**
     * Build the base GitHub API URL for the repository.
     */
    private static function get_repository_api_base(): string {
        return sprintf('https://api.github.com/repos/%s/%s', self::REPOSITORY_OWNER, self::REPOSITORY_NAME);
    }

    /**
     * Build request arguments for GitHub API calls.
     */
    private static function get_github_request_args(): array {
        $userAgent = 'MealsDB-Plugin';
        if (defined('MEALS_DB_VERSION') && MEALS_DB_VERSION !== '') {
            $userAgent .= '/' . MEALS_DB_VERSION;
        }

        return [
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => $userAgent,
            ],
            'timeout' => 15,
        ];
    }

    /**
     * Build headers for downloading binary assets from GitHub.
     */
    private static function get_github_download_headers(): array {
        $args = self::get_github_request_args();
        $headers = isset($args['headers']) && is_array($args['headers']) ? $args['headers'] : [];
        $headers['Accept'] = 'application/octet-stream';

        return $headers;
    }

    /**
     * Build the codeload URL for a release/tag archive.
     */
    private static function get_codeload_zip_url(string $ref): string {
        return sprintf(
            'https://codeload.github.com/%s/%s/zip/%s',
            self::REPOSITORY_OWNER,
            self::REPOSITORY_NAME,
            rawurlencode($ref)
        );
    }
}
