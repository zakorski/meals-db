<?php
/**
 * Handles integration with the Meals DB Git repository.
 *
 * Provides helper routines that allow the admin UI to check for plugin
 * updates, pull the latest changes, and run any schema maintenance tasks
 * required by the plugin.
 */

class MealsDB_Updates {

    /**
     * Return diagnostic details about the current Git repository state.
     *
     * @return array|WP_Error
     */
    public static function check_for_updates() {
        if (!self::is_git_repository()) {
            return new WP_Error(
                'mealsdb_git_missing',
                __('This plugin directory is not a Git repository. Updates cannot be checked.', 'meals-db')
            );
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
}
