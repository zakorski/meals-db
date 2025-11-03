<?php
/**
 * Handles integration with the Meals DB Git repository.
 *
 * Provides helper routines that allow the admin UI to check for plugin
 * updates, pull the latest changes, and run any schema maintenance tasks
 * required by the plugin.
 */

class MealsDB_Updates {

    private const REPOSITORY_OWNER = 'zakorski';
    private const REPOSITORY_NAME = 'meals-db';

    /**
     * Return diagnostic details about the current Git repository state.
     *
     * @return array|WP_Error
     */
    public static function check_for_updates() {
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

            $message = $hasUpdates
                ? sprintf(
                    /* translators: %s: Meals DB latest version number */
                    __('A new version of Meals DB (%s) is available on GitHub. Download the latest release to update.', 'meals-db'),
                    $latestVersion
                )
                : __('Meals DB is up to date.', 'meals-db');

            return [
                'current_version' => $currentVersion,
                'latest_version'  => $latestVersion,
                'has_updates'     => $hasUpdates,
                'message'         => $message,
                'repository_url'  => self::get_repository_url(),
                'release_url'     => $latestRelease['url'],
                'can_auto_update' => false,
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
        ];
    }

    /**
     * Pull the latest changes from the configured remote.
     *
     * @return array|WP_Error
     */
    public static function pull_updates() {
        if (!self::is_git_repository()) {
            return new WP_Error(
                'mealsdb_git_missing',
                __('This plugin directory is not a Git repository. Updates cannot be applied.', 'meals-db')
            );
        }

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

    /**
     * Run the database migration/maintenance routine.
     *
     * @return array
     */
    public static function run_database_maintenance() {
        if (!class_exists('MealsDB_Installer')) {
            require_once MEALS_DB_PLUGIN_DIR . 'includes/install-schema.php';
        }

        MealsDB_Installer::install();

        return [
            'message' => __('Database schema has been checked and updated.', 'meals-db'),
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
        $gitBinary = apply_filters('mealsdb_git_binary', 'git');

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = escapeshellcmd($gitBinary);
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

        if (empty($data['tag_name'])) {
            return self::get_remote_latest_tag();
        }

        return [
            'version' => self::normalize_version($data['tag_name']),
            'url'     => !empty($data['html_url']) ? $data['html_url'] : self::get_repository_url(),
        ];
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
        if (!is_array($data) || empty($data[0]['name'])) {
            return new WP_Error(
                'mealsdb_github_json',
                __('GitHub did not return any release or tag data.', 'meals-db')
            );
        }

        $tag = $data[0];

        $tagName = is_string($tag['name']) ? $tag['name'] : '';

        return [
            'version' => self::normalize_version($tagName),
            'url'     => sprintf('%s/releases/tag/%s', self::get_repository_url(), rawurlencode($tagName)),
        ];
    }

    /**
     * Normalize a semantic version string by trimming whitespace and removing any leading "v".
     */
    private static function normalize_version(string $version): string {
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
}
