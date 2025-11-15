<?php
/**
 * Contains write operations used to mutate data during Meals DB synchronization.
 */

class MealsDB_Sync_Mutate {
    /**
     * Update a WordPress user with the provided field values.
     *
     * @param int                         $user_id Identifier of the WordPress user to update.
     * @param array<string, mixed>        $fields  Associative array of field names and values to persist.
     *
     * @return bool True on success, false on failure.
     */
    public function update_wp_user(int $user_id, array $fields): bool {
        return false;
    }

    /**
     * Update a Meals DB client with the provided field values.
     *
     * @param int                         $client_id Identifier of the Meals DB client to update.
     * @param array<string, mixed>        $fields    Associative array of field names and values to persist.
     *
     * @return bool True on success, false on failure.
     */
    public function update_meals_client(int $client_id, array $fields): bool {
        return false;
    }

    /**
     * Create a Meals DB client record with the provided field values.
     *
     * @param array<string, mixed> $fields Associative array of field names and values for the new client.
     *
     * @return int|false The created client ID on success, or false on failure.
     */
    public function create_meals_client(array $fields): int|false {
        return false;
    }

    /**
     * Resolve a synchronization conflict using the provided descriptor.
     *
     * @param array<string, mixed> $conflict Conflict metadata describing the resolution to apply.
     *
     * @return bool True when the conflict has been resolved, false otherwise.
     */
    public function resolve_conflict(array $conflict): bool {
        return false;
    }
}
