<?php
/**
 * Contains comparison helpers for Meals DB synchronization routines.
 */

class MealsDB_Sync_Compare {
    /**
     * Detect mismatches between WordPress users and Meals DB clients.
     *
     * @param array<int, WP_User>                 $wp_users          Pre-fetched WordPress user data.
     * @param array<int, array<int, array<string, mixed>>> $clients_by_wp_id Meals DB clients grouped by WordPress ID.
     * @param array<int, array<string, mixed>>    $clients_without_id Meals DB clients without a WordPress ID.
     * @param array<int, bool>                    $staff_wp_ids      WordPress user IDs that should be ignored.
     *
     * @return array<int, array<string, mixed>> Collection of mismatch descriptors.
     */
    public function detect_mismatches(array $wp_users, array $clients_by_wp_id, array $clients_without_id, array $staff_wp_ids): array {
        $mismatches = [];
        $remaining_clients = $clients_by_wp_id;

        foreach ($wp_users as $woo_user) {
            if (!$woo_user instanceof WP_User) {
                continue;
            }

            $wp_id = (int) $woo_user->ID;

            if (isset($remaining_clients[$wp_id])) {
                foreach ($remaining_clients[$wp_id] as $client) {
                    $diffs = $this->compare_fields($client, $woo_user);

                    if (!empty($diffs)) {
                        $mismatches[] = [
                            'type'         => 'field_mismatch',
                            'client_id'    => $client['id'] ?? 0,
                            'woo_user_id'  => $wp_id,
                            'fields'       => $diffs,
                            'allow_sync'   => true,
                            'notice'       => '',
                            'meals_client' => $client,
                            'wp_user'      => $this->extract_user_snapshot($woo_user),
                        ];
                    }
                }

                unset($remaining_clients[$wp_id]);
            } elseif (!isset($staff_wp_ids[$wp_id])) {
                $conflict = $this->build_wordpress_only_conflict($woo_user);

                if ($conflict !== null) {
                    $mismatches[] = $conflict;
                }
            }
        }

        if (!empty($remaining_clients)) {
            foreach ($remaining_clients as $clients) {
                foreach ($clients as $client) {
                    $conflict = $this->build_meals_only_conflict($client, true);

                    if ($conflict !== null) {
                        $mismatches[] = $conflict;
                    }
                }
            }
        }

        foreach ($clients_without_id as $client) {
            $conflict = $this->build_meals_only_conflict($client, false);

            if ($conflict !== null) {
                $mismatches[] = $conflict;
            }
        }

        return $mismatches;
    }

    /**
     * Filter a list of mismatches against configured ignore rules.
     *
     * @param array<int, array<string, mixed>> $mismatches Detected mismatches awaiting filtering.
     * @param array<string, bool>              $ignored    Ignore rules represented by hashed keys.
     *
     * @return array<int, array<string, mixed>> Filtered mismatch collection.
     */
    public function filter_ignored(array $mismatches, array $ignored): array {
        if (empty($ignored)) {
            return $mismatches;
        }

        $filtered = [];

        foreach ($mismatches as $conflict) {
            if (!isset($conflict['fields']) || !is_array($conflict['fields'])) {
                $filtered[] = $conflict;
                continue;
            }

            $kept_fields = [];

            foreach ($conflict['fields'] as $field => $values) {
                $field_key  = $this->sanitize_ignore_value($field);
                $source_val = $this->sanitize_ignore_value($values['meals_db'] ?? '');
                $target_val = $this->sanitize_ignore_value($values['woocommerce'] ?? '');
                $ignore_key = $this->build_ignore_key($field_key, $source_val, $target_val);

                if (isset($ignored[$ignore_key])) {
                    continue;
                }

                $kept_fields[$field] = $values;
            }

            if (!empty($kept_fields)) {
                $conflict['fields'] = $kept_fields;
                $filtered[] = $conflict;
            }
        }

        return $filtered;
    }

    /**
     * Attempt to match a Meals DB client to a WordPress user by comparing name fields.
     *
     * @param array<string, mixed> $client Client record under evaluation.
     * @param array<int, mixed>    $users  Candidate WordPress users or snapshots to examine.
     *
     * @return array<string, mixed>|null Matching user information, or null if none found.
     */
    public function match_by_name(array $client, array $users): ?array {
        $target_first = $this->normalize_name($client['first_name'] ?? '');
        $target_last  = $this->normalize_name($client['last_name'] ?? '');

        if ($target_first === '' || $target_last === '') {
            return null;
        }

        foreach ($users as $user) {
            $user_first = '';
            $user_last  = '';
            $snapshot   = null;

            if ($user instanceof WP_User) {
                $user_first = $this->normalize_name(isset($user->first_name) ? (string) $user->first_name : '');
                $user_last  = $this->normalize_name(isset($user->last_name) ? (string) $user->last_name : '');
                $snapshot   = $this->extract_user_snapshot($user);
            } elseif (is_array($user)) {
                $user_first = $this->normalize_name($user['first_name'] ?? '');
                $user_last  = $this->normalize_name($user['last_name'] ?? '');
                $snapshot   = $user;
            }

            if ($snapshot === null) {
                continue;
            }

            if ($target_first === $user_first && $target_last === $user_last) {
                return $snapshot;
            }
        }

        return null;
    }

    /**
     * Attempt to match a Meals DB client to a WordPress user by comparing phone fields.
     *
     * @param array<string, mixed> $client Client record under evaluation.
     * @param array<int, mixed>    $users  Candidate WordPress users or snapshots to examine.
     *
     * @return array<string, mixed>|null Matching user information, or null if none found.
     */
    public function match_by_phone(array $client, array $users): ?array {
        $target_phone = $this->normalize_phone($client['phone_primary'] ?? '');

        if ($target_phone === '') {
            return null;
        }

        foreach ($users as $user) {
            $user_phone = '';
            $snapshot   = null;

            if ($user instanceof WP_User) {
                $user_phone = $this->normalize_phone((string) get_user_meta($user->ID, 'billing_phone', true));
                $snapshot   = $this->extract_user_snapshot($user);
            } elseif (is_array($user)) {
                $user_phone = $this->normalize_phone($user['phone'] ?? '');
                $snapshot   = $user;
            }

            if ($snapshot === null) {
                continue;
            }

            if ($user_phone !== '' && $target_phone !== '') {
                $comparison_length = min(7, strlen($target_phone));
                $target_tail = substr($target_phone, -$comparison_length);
                $user_tail = substr($user_phone, -$comparison_length);

                if ($target_tail !== '' && $target_tail === $user_tail) {
                    return $snapshot;
                }
            }
        }

        return null;
    }

    /**
     * Find probable WordPress user matches for a Meals DB client based on similarity scoring.
     *
     * @param array<string, mixed> $client
     * @param array<int, WP_User>  $wp_users
     *
     * @return array<int, array<string, mixed>>
     */
    public function find_probable_matches(array $client, array $wp_users): array {
        $matches = [];

        foreach ($wp_users as $wp_user) {
            if (!$wp_user instanceof WP_User) {
                continue;
            }

            $score = $this->compute_similarity_score($client, $wp_user);

            if ($score >= 50) {
                $matches[] = [
                    'score'      => $score,
                    'wp_user'    => $this->extract_user_snapshot($wp_user),
                    'wp_user_id' => (int) $wp_user->ID,
                ];
            }
        }

        usort($matches, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($matches, 0, 5);
    }

    /**
     * Compare Meals DB record and Woo user fields.
     *
     * @param array<string, mixed> $client
     * @return array<string, array<string, mixed>>
     */
    private function compare_fields(array $client, WP_User $woo_user): array {
        $mismatches = [];

        $map = [
            'first_name'     => $woo_user->first_name,
            'last_name'      => $woo_user->last_name,
            'client_email'   => $woo_user->user_email,
            'phone_primary'  => get_user_meta($woo_user->ID, 'billing_phone', true),
            'address_postal' => get_user_meta($woo_user->ID, 'billing_postcode', true),
        ];

        foreach ($map as $field => $woo_value) {
            $plugin_value = $client[$field] ?? '';

            if (trim(strtolower((string) $plugin_value)) !== trim(strtolower((string) $woo_value))) {
                $mismatches[$field] = [
                    'meals_db'    => $plugin_value,
                    'woocommerce' => $woo_value,
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Build a conflict entry for a WordPress user that does not exist in Meals DB.
     */
    private function build_wordpress_only_conflict(WP_User $woo_user): ?array {
        $no_meals_message = __('No Meals DB client is linked to this WordPress user.', 'meals-db');

        $fields = [
            'wordpress_user_id' => [
                'meals_db'    => $no_meals_message,
                'woocommerce' => (string) $woo_user->ID,
            ],
            'first_name' => [
                'meals_db'    => $no_meals_message,
                'woocommerce' => isset($woo_user->first_name) ? (string) $woo_user->first_name : '',
            ],
            'last_name' => [
                'meals_db'    => $no_meals_message,
                'woocommerce' => isset($woo_user->last_name) ? (string) $woo_user->last_name : '',
            ],
            'client_email' => [
                'meals_db'    => $no_meals_message,
                'woocommerce' => isset($woo_user->user_email) ? (string) $woo_user->user_email : '',
            ],
        ];

        return [
            'type'         => 'wordpress_only',
            'client_id'    => 0,
            'woo_user_id'  => (int) $woo_user->ID,
            'fields'       => $fields,
            'allow_sync'   => false,
            'notice'       => __('No Meals DB client record matches this WordPress user.', 'meals-db'),
            'meals_client' => null,
            'wp_user'      => $this->extract_user_snapshot($woo_user),
        ];
    }

    /**
     * Build a conflict entry for a Meals DB client without a matching WordPress user record.
     *
     * @param array<string, mixed> $client
     */
    private function build_meals_only_conflict(array $client, bool $has_wordpress_id): ?array {
        $wp_id = $client['wordpress_user_id'] ?? 0;

        if ($has_wordpress_id) {
            $notice = __('The linked WordPress user could not be found.', 'meals-db');
            $woo_message = __('No WordPress user exists with this ID.', 'meals-db');
            $meals_value = (string) $wp_id;
        } else {
            $notice = __('This Meals DB client does not have a linked WordPress user ID.', 'meals-db');
            $woo_message = __('This client is not linked to a WordPress user ID.', 'meals-db');
            $meals_value = __('(not set)', 'meals-db');
        }

        $no_wp_data_message = $woo_message;

        $fields = [
            'wordpress_user_id' => [
                'meals_db'    => $meals_value,
                'woocommerce' => $woo_message,
            ],
            'first_name' => [
                'meals_db'    => isset($client['first_name']) ? (string) $client['first_name'] : '',
                'woocommerce' => $no_wp_data_message,
            ],
            'last_name' => [
                'meals_db'    => isset($client['last_name']) ? (string) $client['last_name'] : '',
                'woocommerce' => $no_wp_data_message,
            ],
            'client_email' => [
                'meals_db'    => isset($client['client_email']) ? (string) $client['client_email'] : '',
                'woocommerce' => $no_wp_data_message,
            ],
        ];

        return [
            'type'         => 'meals_only',
            'client_id'    => $client['id'] ?? 0,
            'woo_user_id'  => $has_wordpress_id ? (int) $wp_id : 0,
            'fields'       => $fields,
            'allow_sync'   => false,
            'notice'       => $notice,
            'meals_client' => $client,
            'wp_user'      => null,
        ];
    }

    /**
     * Create a lightweight snapshot of a WordPress user for display purposes.
     */
    private function extract_user_snapshot(WP_User $woo_user): array {
        return [
            'id'           => (int) $woo_user->ID,
            'first_name'   => isset($woo_user->first_name) ? (string) $woo_user->first_name : '',
            'last_name'    => isset($woo_user->last_name) ? (string) $woo_user->last_name : '',
            'email'        => isset($woo_user->user_email) ? (string) $woo_user->user_email : '',
            'display_name' => isset($woo_user->display_name) ? (string) $woo_user->display_name : '',
            'phone'        => (string) get_user_meta($woo_user->ID, 'billing_phone', true),
        ];
    }

    /**
     * Normalize a human-readable name string for comparison purposes.
     */
    private function normalize_name(string $value): string {
        $normalized = strtolower(trim($value));

        if (function_exists('remove_accents')) {
            $normalized = remove_accents($normalized);
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized ?? '');

        return trim((string) $normalized);
    }

    /**
     * Normalize a phone number by stripping non-digit characters.
     */
    private function normalize_phone(string $value): string {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Compute a similarity score between a Meals DB client and a WordPress user.
     *
     * @param array<string, mixed> $client
     */
    private function compute_similarity_score(array $client, WP_User $wp_user): int {
        $score = 0;

        $client_first = $this->normalize_name($client['first_name'] ?? '');
        $client_last  = $this->normalize_name($client['last_name'] ?? '');
        $client_phone = $this->normalize_phone($client['phone_primary'] ?? '');
        $client_email = isset($client['client_email']) ? strtolower(trim((string) $client['client_email'])) : '';

        $wp_first = $this->normalize_name(isset($wp_user->first_name) ? (string) $wp_user->first_name : '');
        $wp_last  = $this->normalize_name(isset($wp_user->last_name) ? (string) $wp_user->last_name : '');
        $wp_phone = $this->normalize_phone((string) get_user_meta($wp_user->ID, 'billing_phone', true));
        $wp_email = isset($wp_user->user_email) ? strtolower(trim((string) $wp_user->user_email)) : '';

        if ($client_first !== '' && $client_first === $wp_first) {
            $score += 40;
        } elseif ($client_first !== '' && $wp_first !== '' && levenshtein($client_first, $wp_first) <= 2) {
            $score += 25;
        }

        if ($client_last !== '' && $client_last === $wp_last) {
            $score += 40;
        } elseif ($client_last !== '' && $wp_last !== '' && levenshtein($client_last, $wp_last) <= 2) {
            $score += 25;
        }

        if ($client_phone !== '' && $wp_phone !== '') {
            $client_last7 = strlen($client_phone) >= 7 ? substr($client_phone, -7) : $client_phone;
            $wp_last7     = strlen($wp_phone) >= 7 ? substr($wp_phone, -7) : $wp_phone;

            if (strlen($client_last7) === 7 && strlen($wp_last7) === 7 && $client_last7 === $wp_last7) {
                $score += 60;
            } else {
                $client_last4 = strlen($client_phone) >= 4 ? substr($client_phone, -4) : $client_phone;
                $wp_last4     = strlen($wp_phone) >= 4 ? substr($wp_phone, -4) : $wp_phone;

                if (strlen($client_last4) === 4 && strlen($wp_last4) === 4 && $client_last4 === $wp_last4) {
                    $score += 20;
                }
            }
        }

        if ($client_email !== '' && $wp_email !== '') {
            $client_user = explode('@', $client_email, 2)[0] ?? '';
            $wp_user_part = explode('@', $wp_email, 2)[0] ?? '';

            if ($client_user !== '' && $client_user === $wp_user_part) {
                $score += 20;
            }
        }

        if ($score < 0) {
            $score = 0;
        } elseif ($score > 200) {
            $score = 200;
        }

        return $score;
    }

    /**
     * Normalize ignore values before hashing.
     *
     * @param mixed $value
     */
    private function sanitize_ignore_value($value): string {
        if (!is_scalar($value)) {
            $value = '';
        }

        $value = (string) $value;

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim($value);
    }

    /**
     * Build the lookup key used for ignored conflicts.
     */
    private function build_ignore_key(string $field, string $source, string $target): string {
        return md5($field . '|' . $source . '|' . $target);
    }
}
