<?php
/**
 * Meals DB Delivery Initials Validator
 *
 * Centralized validation for delivery initials across:
 * - Add new client form
 * - Edit client form
 * - CSV import process
 *
 * Implements address-based duplicate checking - allows multiple clients
 * to share the same delivery initials IF they are being delivered to
 * the same physical address.
 *
 * @package MealsDB
 * @since 1.0.223
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class MealsDB_Initials_Validator
 */
class MealsDB_Initials_Validator {

	/**
	 * Profane/inappropriate initials to block
	 *
	 * @var array
	 */
	private static $blocked_initials = array(
		'ASS', 'SEX', 'TIT', 'CUM', 'FAG', 'GAY', 'GOD', 'NIG',
		'WTF', 'XXX', 'KKK', 'FUK',
	);

	/**
	 * Validate delivery initials
	 *
	 * @param string          $initials The initials to validate.
	 * @param array|object    $client_data Client data including address fields.
	 * @param int|null        $current_client_id Client ID when editing (exclude from duplicate check).
	 * @return array ['valid' => bool, 'error' => string, 'shared' => bool, 'sharing_with' => array]
	 */
	public static function validate($initials, $client_data, $current_client_id = null) {
		// Convert to array if object
		if (is_object($client_data)) {
			$client_data = (array) $client_data;
		}

		// Step 1: Format validation - must be exactly 3 letters
		$initials_upper = strtoupper(trim($initials));
		if (!preg_match('/^[A-Z]{3}$/', $initials_upper)) {
			return array(
				'valid'   => false,
				'error'   => __('Initials must be exactly 3 letters (no numbers or symbols)', 'meals-db'),
				'shared'  => false,
			);
		}

		// Step 2: Profanity check
		if (self::is_profane($initials_upper)) {
			return array(
				'valid'   => false,
				'error'   => __('These initials are not allowed', 'meals-db'),
				'shared'  => false,
			);
		}

		// Step 3: Check if initials are already in use
		$existing_clients = self::get_clients_with_initials($initials_upper);

		// Remove current client from check (when editing)
		if ($current_client_id) {
			$existing_clients = array_filter($existing_clients, function($client) use ($current_client_id) {
				return (int) $client['id'] !== (int) $current_client_id;
			});
		}

		// If no other clients use these initials, we're good
		if (empty($existing_clients)) {
			return array(
				'valid'   => true,
				'shared'  => false,
			);
		}

		// Step 4: If initials ARE in use, check if delivery addresses match
		$new_address = self::normalize_delivery_address($client_data);

		// Check if we have a valid address to compare
		if (self::is_address_empty($new_address)) {
			// No valid address provided - cannot validate address-based sharing
			$names = array_map(function($c) {
				return trim($c['first_name'] . ' ' . $c['last_name']);
			}, $existing_clients);

			return array(
				'valid'   => false,
				'error'   => sprintf(
					__('Initials already in use by: %s. Please provide a delivery address to verify if sharing is allowed.', 'meals-db'),
					implode(', ', $names)
				),
				'shared'  => false,
			);
		}

		// Compare addresses with existing clients
		$sharing_with = array();
		$different_addresses = array();

		foreach ($existing_clients as $existing_client) {
			$existing_address = self::normalize_delivery_address($existing_client);

			if (self::addresses_match($new_address, $existing_address)) {
				// Same address - initials can be shared
				$sharing_with[] = $existing_client;
			} else {
				// Different address - conflict
				$different_addresses[] = $existing_client;
			}
		}

		// If ALL existing clients with these initials are at the same address, allow sharing
		if (empty($different_addresses)) {
			return array(
				'valid'       => true,
				'shared'      => true,
				'sharing_with' => $sharing_with,
			);
		}

		// Different addresses - initials must be unique
		$names = array_map(function($c) {
			return trim($c['first_name'] . ' ' . $c['last_name']);
		}, $different_addresses);

		return array(
			'valid'   => false,
			'error'   => sprintf(
				__('Initials already in use by: %s at different address(es)', 'meals-db'),
				implode(', ', $names)
			),
			'shared'  => false,
		);
	}

	/**
	 * Generate unique initials for a client
	 *
	 * Attempts to create initials based on name, checking against:
	 * - Profanity list
	 * - Existing clients (allows if same address)
	 *
	 * @param string       $first_name Client's first name.
	 * @param string       $last_name Client's last name.
	 * @param array|object $client_data Client address data.
	 * @return string|false 3-letter initials or false if unable to generate.
	 */
	public static function generate($first_name, $last_name, $client_data = array()) {
		// Convert to array if object
		if (is_object($client_data)) {
			$client_data = (array) $client_data;
		}

		$first = strtoupper(substr(trim($first_name), 0, 1));
		$last = strtoupper(substr(trim($last_name), 0, 3));

		// Try various patterns based on name
		$patterns = array();

		// Pattern 1: First + First 2 of Last (e.g., "John Smith" -> "JSM")
		if (strlen($last) >= 2) {
			$patterns[] = $first . substr($last, 0, 2);
		}

		// Pattern 2: First + Last 2 of Last (e.g., "John Smith" -> "JTH")
		if (strlen($last) >= 2) {
			$patterns[] = $first . substr($last, -2);
		}

		// Pattern 3: First 2 of First + First of Last (e.g., "John Smith" -> "JOS")
		$first_name_upper = strtoupper(trim($first_name));
		if (strlen($first_name_upper) >= 2) {
			$patterns[] = substr($first_name_upper, 0, 2) . substr($last, 0, 1);
		}

		// Pattern 4: First + Middle of Last (if long enough)
		if (strlen($last) >= 3) {
			$patterns[] = $first . $last[1] . $last[2];
		}

		// Try each pattern
		foreach ($patterns as $pattern) {
			if (strlen($pattern) === 3) {
				$validation = self::validate($pattern, $client_data, null);
				if ($validation['valid']) {
					return $pattern;
				}
			}
		}

		// If all patterns failed, generate random initials. random_int is
		// unbiased and CSPRNG-backed; rand() is biased and slow. Pre-fetch
		// the in-use initials set once so the random search skips
		// already-taken codes without issuing one DB query per attempt.
		$existing = self::get_all_existing_initials();
		$max_attempts = 100;
		for ($i = 0; $i < $max_attempts; $i++) {
			$random = chr(random_int(65, 90)) . chr(random_int(65, 90)) . chr(random_int(65, 90));
			if (isset($existing[$random])) {
				continue;
			}
			$validation = self::validate($random, $client_data, null);
			if ($validation['valid']) {
				return $random;
			}
		}

		// Unable to generate
		return false;
	}

	/**
	 * Normalize delivery address for comparison
	 *
	 * Uses delivery address fields if present, otherwise falls back to primary address.
	 * Handles both form field names and database column names.
	 *
	 * @param array $client_data Client data with address fields.
	 * @return array Normalized address array.
	 */
	private static function normalize_delivery_address($client_data) {
		// Use delivery address if present, otherwise use primary address
		// Handle both form field names (address_street_name) and DB column names (street_name)

		// Street name (now contains full address including street number and unit)
		$street_name = !empty($client_data['delivery_address_street_name'])
			? $client_data['delivery_address_street_name']
			: (!empty($client_data['delivery_street_name'])
				? $client_data['delivery_street_name']
				: (!empty($client_data['address_street_name'])
					? $client_data['address_street_name']
					: ($client_data['street_name'] ?? '')));

		// City
		$city = !empty($client_data['delivery_address_city'])
			? $client_data['delivery_address_city']
			: (!empty($client_data['delivery_city'])
				? $client_data['delivery_city']
				: (!empty($client_data['address_city'])
					? $client_data['address_city']
					: ($client_data['city'] ?? '')));

		// Postal code
		$postal = !empty($client_data['delivery_address_postal_code'])
			? $client_data['delivery_address_postal_code']
			: (!empty($client_data['delivery_postal_code'])
				? $client_data['delivery_postal_code']
				: (!empty($client_data['address_postal_code'])
					? $client_data['address_postal_code']
					: ($client_data['postal_code'] ?? '')));

		return array(
			'street_name'   => trim(strtolower((string) $street_name)),
			'city'          => trim(strtolower((string) $city)),
			'postal'        => self::normalize_postal($postal),
		);
	}

	/**
	 * Normalize postal code
	 *
	 * Removes spaces, dashes, and converts to lowercase.
	 *
	 * @param string $postal Postal code.
	 * @return string Normalized postal code.
	 */
	private static function normalize_postal($postal) {
		// Remove spaces, dashes, convert to lowercase
		return strtolower(preg_replace('/[\s\-]/', '', (string) $postal));
	}

	/**
	 * Check if two addresses match
	 *
	 * All fields must match for addresses to be considered the same.
	 *
	 * @param array $addr1 First normalized address.
	 * @param array $addr2 Second normalized address.
	 * @return bool True if addresses match.
	 */
	private static function addresses_match($addr1, $addr2) {
		return $addr1['street_name'] === $addr2['street_name']
			&& $addr1['city'] === $addr2['city']
			&& $addr1['postal'] === $addr2['postal'];
	}

	/**
	 * Check if address is empty
	 *
	 * An address is considered empty if it lacks essential fields.
	 *
	 * @param array $address Normalized address.
	 * @return bool True if address is empty.
	 */
	/**
	 * Bulk-load all initials currently in use, keyed for O(1) lookup.
	 *
	 * @return array<string, true>
	 */
	private static function get_all_existing_initials(): array {
		global $wpdb;
		if (!$wpdb) {
			return [];
		}
		$clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
		$rows = $wpdb->get_col(sprintf("SELECT delivery_initials FROM `%s` WHERE delivery_initials <> '' AND delivery_initials IS NOT NULL", esc_sql($clients_table)));
		$set = [];
		if (is_array($rows)) {
			foreach ($rows as $r) {
				$set[strtoupper(trim((string) $r))] = true;
			}
		}
		return $set;
	}

	private static function is_address_empty($address) {
		// Require street_name + city + postal for a "non-empty"
		// address. Allowing a missing postal would let two unrelated
		// houses on the same street+city share initials despite having
		// different postals; treating that as "empty" forces the
		// uniqueness check to assign independent initials.
		//
		// NOTE: the key is `postal`, not `postal_code`, because
		// normalize_delivery_address() returns the normalised array
		// with that shorter key. The original code checked
		// `$address['postal_code']` which never existed on a
		// normalised array, so is_address_empty() always returned
		// true and address-based initials sharing was silently dead:
		// every legitimately-shared household received the "please
		// provide a delivery address" error even when they had one.
		return empty($address['street_name'])
			|| empty($address['city'])
			|| empty($address['postal']);
	}

	/**
	 * Check if initials are profane
	 *
	 * @param string $initials Initials to check.
	 * @return bool True if profane.
	 */
	private static function is_profane($initials) {
		return in_array(strtoupper($initials), self::$blocked_initials, true);
	}

	/**
	 * Get all clients with specific initials
	 *
	 * @param string $initials Initials to search for.
	 * @return array Array of client data.
	 */
	private static function get_clients_with_initials($initials) {
		global $wpdb;

		$clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
		$sql = $wpdb->prepare(
			"SELECT
				client_id as id,
				first_name,
				last_name,
				delivery_initials,
				delivery_street_name,
				delivery_city,
				delivery_postal_code,
				street_name,
				city,
				postal_code
			FROM `{$clients_table}`
			WHERE delivery_initials = %s",
			$initials
		);

		$results = $wpdb->get_results($sql, ARRAY_A);
		if (!is_array($results)) {
			error_log('[MealsDB] Failed to execute initials lookup query: ' . ($wpdb->last_error ?: 'unknown error'));
			return array();
		}

		return $results;
	}

	/**
	 * Get blocked initials list
	 *
	 * @return array List of blocked initials.
	 */
	public static function get_blocked_initials() {
		return self::$blocked_initials;
	}
}
