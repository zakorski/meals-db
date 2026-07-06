<?php
/**
 * Contains comparison helpers for Meals DB synchronization routines.
 */

defined('ABSPATH') || exit;

class MealsDB_Sync_Compare {

    /**
     * Cache of WP user lists, keyed by the query object that produced
     * them. Static so multiple MealsDB_Sync_Compare instances in the
     * same request (dashboard + a downstream reconciliation job, for
     * example) share the cache instead of each re-fetching thousands
     * of WP users from the same underlying query.
     *
     * Keyed by spl_object_id($query). To protect against PHP recycling
     * that ID after the original object is freed (which would hand a
     * stale user list to a new, unrelated query object), pin the
     * referenced query in $pinned_queries so it stays live for the
     * whole request.
     *
     * @var array<int, array<int, WP_User>>
     */
    private static $wp_users_cache = [];

    /**
     * @var array<int, MealsDB_Sync_Query>
     */
    private static $pinned_queries = [];

    /**
     * Retrieve and compare Meals DB and WordPress data to identify mismatches.
     *
     * @return array|WP_Error
     */
    public function get_mismatches(MealsDB_Sync_Query $query) {
        $clients = $query->get_meals_clients();
        if (is_wp_error($clients)) {
            return $clients;
        }

        $ignored_keys = $query->get_ignored_conflicts();
        if (is_wp_error($ignored_keys)) {
            return $ignored_keys;
        }

        $staff_wp_ids = $query->get_staff_wordpress_ids();
        if (is_wp_error($staff_wp_ids)) {
            return $staff_wp_ids;
        }

        $wp_users = $query->get_wp_users();

        $mismatches = $this->detect_mismatches(
            $wp_users,
            $clients['by_wp_id'] ?? [],
            $clients['without_wp_id'] ?? [],
            is_array($staff_wp_ids) ? $staff_wp_ids : []
        );

        $mismatches = $this->filter_ignored($mismatches, is_array($ignored_keys) ? $ignored_keys : []);

        return $this->attach_suggested_matches($mismatches, $query);
    }

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
                            'client_id'    => $client['client_id'] ?? 0,
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
                $field_key  = MealsDB_Sync::sanitize_ignore_value($field);
                $source_val = MealsDB_Sync::sanitize_ignore_value($values['meals_db'] ?? '');
                $target_val = MealsDB_Sync::sanitize_ignore_value($values['woocommerce'] ?? '');
                $ignore_key = MealsDB_Sync::build_ignore_key($field_key, $source_val, $target_val);

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
     * Enrich mismatches with suggested WordPress customer links for unlinked clients.
     *
     * @param array<int, array<string, mixed>> $mismatches
     *
     * @return array<int, array<string, mixed>>
     */
    private function attach_suggested_matches(array $mismatches, MealsDB_Sync_Query $query): array {
        foreach ($mismatches as $index => $mismatch) {
            $client = $mismatch['meals_client'] ?? null;

            if (!is_array($client)) {
                continue;
            }

            $has_linked_user = !empty($mismatch['wp_user']);

            if ($has_linked_user) {
                continue;
            }

            $matches = $query->find_candidate_wc_matches_for_client($client);

            if (is_wp_error($matches)) {
                continue;
            }

            if (!empty($matches)) {
                $mismatches[$index]['suggested_matches'] = $matches;
            }
        }

        return $mismatches;
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
     * Find probable WordPress user matches for a Meals DB client.
     *
     * @param array<string, mixed> $client
     *
     * @return array<int, array<string, mixed>>
     */
    public function find_probable_matches_for_client(array $client, MealsDB_Sync_Query $query): array {
        $key = spl_object_id($query);
        if (!isset(self::$wp_users_cache[$key])) {
            // Pin the query object for the rest of the request so PHP
            // can't recycle its object id after the caller's only ref
            // goes out of scope — without this pin, a new query object
            // could hash to the same id and inherit the wrong cached
            // user list.
            self::$pinned_queries[$key] = $query;
            self::$wp_users_cache[$key] = $query->get_wp_users();
        }

        return $this->find_probable_matches($client, self::$wp_users_cache[$key]);
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
            'first_name'    => $woo_user->first_name,
            'last_name'     => $woo_user->last_name,
            'client_email'  => $woo_user->user_email,
            'phone_primary' => get_user_meta($woo_user->ID, 'billing_phone', true),
        ];

        foreach ($map as $field => $woo_value) {
            $plugin_value = $client[$field] ?? '';

            if ($field === 'phone_primary') {
                // Phone comparison was previously just mb_strtolower +
                // trim, which would flag every (506) 555-0100 vs
                // 5065550100 pair as a mismatch even though they're
                // the same number. Route through normalize_phone() to
                // strip formatting, drop a leading NANP '1', and
                // compare by the last 10 digits.
                $plugin_norm = $this->normalize_phone((string) $plugin_value);
                $woo_norm    = $this->normalize_phone((string) $woo_value);
            } else {
                // Use mb_strtolower so non-ASCII names (Î, é, etc.)
                // compare by Unicode case rather than byte-equality.
                $plugin_norm = trim(function_exists('mb_strtolower') ? mb_strtolower((string) $plugin_value, 'UTF-8') : strtolower((string) $plugin_value));
                $woo_norm    = trim(function_exists('mb_strtolower') ? mb_strtolower((string) $woo_value, 'UTF-8') : strtolower((string) $woo_value));
            }

            if ($plugin_norm !== $woo_norm) {
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
            'client_id'    => $client['client_id'] ?? 0,
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
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower(trim($value), 'UTF-8')
            : strtolower(trim($value));

        if (function_exists('remove_accents')) {
            $normalized = remove_accents($normalized);
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized ?? '');

        return trim((string) $normalized);
    }

    /**
     * Canonicalise a phone number so two formats of the same number
     * compare equal.
     *
     *   - Strip every non-digit character (parens, spaces, dashes, "+").
     *   - Drop a leading NANP country-code '1' when the residue is
     *     exactly 11 digits. "+1 5065550100" → "5065550100".
     *   - Keep at most the last 10 digits. Tolerates extension tails
     *     ("…x123") and best-effort matches international numbers
     *     the user typed with a longer country code.
     *
     * Previously this was just preg_replace('/\D+/', '', $value), so
     * "(506) 555-0100" and "5065550100" normalised to different
     * lengths and the downstream compare_fields() flagged them as
     * mismatches on every dashboard render.
     */
    private function normalize_phone(string $value): string {
        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === null || $digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && strpos($digits, '1') === 0) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }
        return $digits;
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

        // Score is a sum of non-negative additions with a fixed ceiling
        // (first/last name 40 each, phone 60, email 20 = 160 max), so it
        // can never go negative or exceed that ceiling. No clamp needed;
        // the >=50 threshold in find_probable_matches operates on a 0-160
        // range.
        return $score;
    }

    // sanitize_ignore_value() / build_ignore_key() were duplicated here and
    // in MealsDB_Sync_Query. Both must produce byte-identical md5 keys or
    // filter_ignored() (above) silently stops suppressing ignored conflicts,
    // so they now live in one place: MealsDB_Sync::sanitize_ignore_value() /
    // ::build_ignore_key(). See that facade for the full rationale.
}
