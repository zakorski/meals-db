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

        $envBackup = self::maybe_backup_env_file();
        if (is_wp_error($envBackup)) {
            return $envBackup;
        }

        $statusOutput = self::run_git_command(['status', '--porcelain']);
        if (is_wp_error($statusOutput)) {
            self::maybe_restore_env_file($envBackup);
            return $statusOutput;
        }

        $remainingChanges = self::filter_status_lines($statusOutput);
        if (!empty($remainingChanges)) {
            self::maybe_restore_env_file($envBackup);

            return new WP_Error(
                'mealsdb_git_dirty',
                __('The plugin directory has uncommitted changes. Commit or stash them before updating.', 'meals-db')
            );
        }

        $envPreparation = self::prepare_env_file_for_pull($envBackup);
        if (is_wp_error($envPreparation)) {
            self::maybe_restore_env_file($envBackup);

            return $envPreparation;
        }

        $branch = self::get_current_branch();
        if (is_wp_error($branch)) {
            return $branch;
        }

        $pullResult = self::run_git_command(['pull', '--ff-only', 'origin', $branch]);
        if (is_wp_error($pullResult)) {
            self::maybe_restore_env_file($envBackup);

            return $pullResult;
        }

        $restoreResult = self::maybe_restore_env_file($envBackup);
        if (is_wp_error($restoreResult)) {
            return $restoreResult;
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

    /**
     * Extract the plugin's .env path.
     *
     * @return string
     */
    private static function get_env_path(): string {
        return self::get_plugin_dir() . '.env';
    }

    /**
     * Back up the .env file if it exists so we can restore it after pulling updates.
     *
     * @return array|null|WP_Error
     */
    private static function maybe_backup_env_file() {
        $envPath = self::get_env_path();

        if (!file_exists($envPath)) {
            return null;
        }

        $contents = file_get_contents($envPath);
        if ($contents === false) {
            return new WP_Error(
                'mealsdb_env_unreadable',
                __('Unable to read the existing .env file before updating.', 'meals-db')
            );
        }

        $perms = @fileperms($envPath);

        return [
            'path'      => $envPath,
            'contents'  => $contents,
            'perms'     => $perms,
            'existed'   => true,
            'temp_path' => null,
        ];
    }

    /**
     * Restore a previously backed-up .env file.
     *
     * @param array|null $backup
     *
     * @return true|WP_Error
     */
    private static function maybe_restore_env_file($backup) {
        if (empty($backup) || !is_array($backup)) {
            return true;
        }

        $path = $backup['path'];

        if (!empty($backup['temp_path'])) {
            if (file_exists($path) && !@unlink($path)) {
                return new WP_Error(
                    'mealsdb_env_restore_failed',
                    __('Unable to remove the temporary .env file after updating.', 'meals-db')
                );
            }

            if (!@rename($backup['temp_path'], $path)) {
                return new WP_Error(
                    'mealsdb_env_restore_failed',
                    __('Unable to restore the .env file after updating.', 'meals-db')
                );
            }

            if (!empty($backup['perms'])) {
                @chmod($path, $backup['perms'] & 0777);
            }

            return true;
        }

        $result = file_put_contents($path, $backup['contents']);

        if ($result === false) {
            return new WP_Error(
                'mealsdb_env_restore_failed',
                __('Unable to restore the .env file after updating.', 'meals-db')
            );
        }

        if (!empty($backup['perms'])) {
            @chmod($path, $backup['perms'] & 0777);
        }

        return true;
    }

    /**
     * Remove status entries that refer to the environment file.
     *
     * @param string $statusOutput
     *
     * @return array
     */
    private static function filter_status_lines(string $statusOutput): array {
        $lines = preg_split('/\r?\n/', trim($statusOutput));
        $lines = array_filter($lines);

        $remaining = [];

        foreach ($lines as $line) {
            $path = trim(substr($line, 3));

            if ($path === '.env' || substr($path, -5) === '/.env') {
                continue;
            }

            $remaining[] = $line;
        }

        return $remaining;
    }

    /**
     * Determine if the .env file is tracked and make sure Git will not overwrite it.
     *
     * @param array|null $backup
     *
     * @return true|WP_Error
     */
    private static function prepare_env_file_for_pull(&$backup) {
        if (empty($backup) || !is_array($backup)) {
            return true;
        }

        $isTracked = self::is_env_tracked();
        if (is_wp_error($isTracked)) {
            return $isTracked;
        }

        if ($isTracked) {
            $checkoutResult = self::run_git_command(['checkout', '--', '.env']);
            if (is_wp_error($checkoutResult)) {
                return $checkoutResult;
            }
        } else {
            $tempPath = self::get_temporary_env_path($backup['path']);

            if (!@rename($backup['path'], $tempPath)) {
                return new WP_Error(
                    'mealsdb_env_protect_failed',
                    __('Unable to move the .env file out of the way before updating.', 'meals-db')
                );
            }

            $backup['temp_path'] = $tempPath;
        }

        return true;
    }

    /**
     * Generate a unique temporary path for the .env backup.
     *
     * @param string $originalPath
     *
     * @return string
     */
    private static function get_temporary_env_path(string $originalPath): string {
        $counter = 0;
        $base = $originalPath . '.mealsdb-backup';
        $candidate = $base;

        while (file_exists($candidate)) {
            $counter++;
            $candidate = $base . '-' . $counter;
        }

        return $candidate;
    }

    /**
     * Check if Git tracks the .env file.
     *
     * @return bool|WP_Error
     */
    private static function is_env_tracked() {
        $result = self::run_git_command(['ls-files', '--stage', '.env']);
        if (is_wp_error($result)) {
            return $result;
        }

        return trim($result) !== '';
    }
}
