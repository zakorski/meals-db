<?php
/**
 * Provides data retrieval methods used during Meals DB synchronization workflows.
 */

class MealsDB_Sync_Query {
    /**
     * Retrieve WordPress user records that participate in synchronization.
     *
     * @return array<int, array<string, mixed>> List of WordPress users represented as associative arrays.
     */
    public function get_wp_users(): array {
        return [];
    }

    /**
     * Retrieve Meals DB client records required for synchronization.
     *
     * @return array<int, array<string, mixed>> List of Meals DB clients represented as associative arrays.
     */
    public function get_meals_clients(): array {
        return [];
    }

    /**
     * Retrieve conflict definitions that should be ignored during comparison.
     *
     * @return array<int, array<string, mixed>> List of ignore rules or conflict identifiers.
     */
    public function get_ignored_conflicts(): array {
        return [];
    }

    /**
     * Retrieve Meals DB or WordPress draft records that affect synchronization decisions.
     *
     * @return array<int, array<string, mixed>> List of drafts considered during synchronization.
     */
    public function get_drafts(): array {
        return [];
    }
}
