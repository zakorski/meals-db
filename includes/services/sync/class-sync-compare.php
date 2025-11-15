<?php
/**
 * Contains comparison helpers for Meals DB synchronization routines.
 */

class MealsDB_Sync_Compare {
    /**
     * Detect mismatches between WordPress users and Meals DB clients.
     *
     * @param array<int, array<string, mixed>> $wp_users      Pre-fetched WordPress user data.
     * @param array<int, array<string, mixed>> $meals_clients Pre-fetched Meals DB client data.
     *
     * @return array<int, array<string, mixed>> Collection of mismatch descriptors.
     */
    public function detect_mismatches(array $wp_users, array $meals_clients): array {
        return [];
    }

    /**
     * Filter a list of mismatches against configured ignore rules.
     *
     * @param array<int, array<string, mixed>> $mismatches Detected mismatches awaiting filtering.
     * @param array<int, array<string, mixed>> $ignored    Ignore rules or conflict metadata.
     *
     * @return array<int, array<string, mixed>> Filtered mismatch collection.
     */
    public function filter_ignored(array $mismatches, array $ignored): array {
        return [];
    }

    /**
     * Attempt to match a Meals DB client to a WordPress user by comparing name fields.
     *
     * @param array<string, mixed>              $client Client record under evaluation.
     * @param array<int, array<string, mixed>> $users  Candidate WordPress users to examine.
     *
     * @return array<string, mixed>|null Matching user information, or null if none found.
     */
    public function match_by_name(array $client, array $users): ?array {
        return null;
    }

    /**
     * Attempt to match a Meals DB client to a WordPress user by comparing phone fields.
     *
     * @param array<string, mixed>              $client Client record under evaluation.
     * @param array<int, array<string, mixed>> $users  Candidate WordPress users to examine.
     *
     * @return array<string, mixed>|null Matching user information, or null if none found.
     */
    public function match_by_phone(array $client, array $users): ?array {
        return null;
    }
}
