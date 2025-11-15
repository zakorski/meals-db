<?php
/**
 * Provides access to Meals DB configuration values.
 */

class MealsDB_Config {
    /**
     * Retrieve the Meals DB host.
     */
    public function get_db_host(): ?string {
        return $this->resolve_value('MEALSDB_HOST', 'MEALS_DB_HOST');
    }

    /**
     * Retrieve the Meals DB user.
     */
    public function get_db_user(): ?string {
        return $this->resolve_value('MEALSDB_USER', 'MEALS_DB_USER');
    }

    /**
     * Retrieve the Meals DB password.
     */
    public function get_db_password(): ?string {
        return $this->resolve_value('MEALSDB_PASSWORD', 'MEALS_DB_PASS');
    }

    /**
     * Retrieve the Meals DB name.
     */
    public function get_db_name(): ?string {
        return $this->resolve_value('MEALSDB_NAME', 'MEALS_DB_NAME');
    }

    /**
     * Determine if the Meals DB connection details are fully configured.
     */
    public static function is_db_configured(): bool {
        $config = new self();

        $host = $config->get_db_host();
        $user = $config->get_db_user();
        $pass = $config->get_db_password();
        $name = $config->get_db_name();

        return $host !== null && $host !== ''
            && $user !== null && $user !== ''
            && $pass !== null && $pass !== ''
            && $name !== null && $name !== '';
    }

    /**
     * Resolve a configuration value from environment variables or constants.
     */
    private function resolve_value(string $env_key, string $constant_name): ?string {
        $env_value = getenv($env_key);

        if ($env_value !== false) {
            $env_value = (string) $env_value;

            if ($env_value !== '') {
                return $env_value;
            }
        }

        if (defined($constant_name)) {
            $value = constant($constant_name);

            if (is_string($value) || is_numeric($value)) {
                $value = (string) $value;
            }

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
