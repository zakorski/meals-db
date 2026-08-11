<?php
/**
 * Meals DB Delivery Initials Validator
 *
 * Centralized validation for delivery initials across:
 * - Add new client form
 * - Edit client form
 * - CSV import process
 *
 * Delivery initials are a GENERATED unique code (a 3-letter space). They are
 * GLOBALLY UNIQUE — period (operator decision, directive GUI-INITIALS). An
 * earlier model treated initials as a person's actual initials and allowed two
 * clients to share a code if they were delivered to the same physical address;
 * that same-address exception has been removed. The hard UNIQUE index on
 * `delivery_initials` is the source of truth and the application now agrees
 * with it.
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
	 * Validate delivery initials.
	 *
	 * Initials are globally unique: any code already used by ANOTHER client is
	 * invalid, full stop. There is no same-address sharing exception.
	 *
	 * @param string       $initials The initials to validate.
	 * @param array|object $client_data Accepted for backward compatibility with
	 *                                  callers that still pass client/address
	 *                                  fields; no longer used (the sharing
	 *                                  exception it fed was removed).
	 * @param int|null     $current_client_id Client ID when editing (exclude from duplicate check).
	 * @return array ['valid' => bool, 'error' => string, 'shared' => false]
	 */
	public static function validate($initials, $client_data = array(), $current_client_id = null) {
		// $client_data is intentionally ignored — see the class docblock. It is
		// kept in the signature so existing callers don't have to change.
		unset($client_data);

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

		// Step 3: Global uniqueness. Any OTHER client already holding this code
		// makes it invalid — there is no address escape hatch.
		$existing_clients = self::get_clients_with_initials($initials_upper);

		// FAIL CLOSED on an unverifiable lookup (DB error). Previously the failed
		// query returned array() -> empty() -> 'valid: true', so a transient DB
		// error could pass a duplicate code through this app-level check. Treat
		// it as "could not verify" and reject, matching exists_in_db().
		if ($existing_clients === false) {
			return array(
				'valid'  => false,
				'error'  => __('Could not verify initials right now — please try again.', 'meals-db'),
				'shared' => false,
			);
		}

		// Remove current client from check (when editing)
		if ($current_client_id) {
			$existing_clients = array_filter($existing_clients, function($client) use ($current_client_id) {
				return (int) $client['id'] !== (int) $current_client_id;
			});
		}

		if (empty($existing_clients)) {
			return array(
				'valid'   => true,
				'shared'  => false,
			);
		}

		$names = array_map(function($c) {
			return trim($c['first_name'] . ' ' . $c['last_name']);
		}, $existing_clients);

		return array(
			'valid'   => false,
			'error'   => sprintf(
				__('Initials already in use by: %s', 'meals-db'),
				implode(', ', $names)
			),
			'shared'  => false,
		);
	}

	/**
	 * Existence primitive for the uniqueness gate: is this code held by any
	 * OTHER client? Fails CLOSED (returns true) when the lookup can't be
	 * verified, so a transient DB error can't report a taken code as available.
	 *
	 * This is the single source of truth the backward-compat wrapper
	 * MealsDB_Initials::exists_in_db() now delegates to — previously a SECOND
	 * hand-written fail-closed query on the same gate, exactly the kind of
	 * duplicate that drifts (audit T8). The input is passed through as-is (NOT
	 * upper-cased) to preserve the wrapper's prior behaviour, which relied on
	 * the column's case-insensitive collation for a match.
	 *
	 * @param string   $initials          The code to check.
	 * @param int|null $exclude_client_id Client to exclude (when editing).
	 * @return bool True if taken (or unverifiable — fail closed), false if free.
	 */
	public static function initials_exist(string $initials, ?int $exclude_client_id = null): bool {
		$existing = self::get_clients_with_initials($initials);
		if ($existing === false) {
			// Unverifiable lookup (DB error) — fail closed.
			return true;
		}
		if ($exclude_client_id !== null) {
			$existing = array_filter($existing, static function ($c) use ($exclude_client_id) {
				return (int) $c['id'] !== (int) $exclude_client_id;
			});
		}
		return !empty($existing);
	}

	/**
	 * Generate unique initials for a client
	 *
	 * Attempts to create initials based on name, checking against:
	 * - Profanity list
	 * - Existing clients (must be globally unique)
	 *
	 * @param string       $first_name Client's first name.
	 * @param string       $last_name Client's last name.
	 * @param array|object $client_data Unused (kept for signature compatibility).
	 * @return string|false 3-letter initials or false if unable to generate.
	 */
	public static function generate($first_name, $last_name, $client_data = array()) {
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
				$validation = self::validate($pattern, array(), null);
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
			$validation = self::validate($random, array(), null);
			if ($validation['valid']) {
				return $random;
			}
		}

		// Unable to generate after $max_attempts random tries. The caller
		// surfaces this as a clear on-page "could not find an unused code,
		// retry" message — at ~17,500 usable combinations this only realistically
		// happens when the namespace is nearly full, which is itself worth
		// surfacing as an early warning.
		return false;
	}

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
		// esc_sql() doubles quote characters but does nothing for the
		// backtick that delimits identifiers, so using it on a table
		// name is the wrong helper — the interpolation is safe today
		// only because the table name comes from the MealsDB_Tables
		// constants, not user input. Use the same backtick-doubling
		// escape the rest of the codebase uses for identifiers.
		$clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
		$escaped_table = str_replace('`', '``', $clients_table);
		$rows = $wpdb->get_col(sprintf("SELECT delivery_initials FROM `%s` WHERE delivery_initials <> '' AND delivery_initials IS NOT NULL", $escaped_table));
		$set = [];
		if (is_array($rows)) {
			foreach ($rows as $r) {
				$set[strtoupper(trim((string) $r))] = true;
			}
		}
		return $set;
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
	 * @return array|false Array of client data (id, first_name, last_name), or
	 *                     false when the lookup could not be executed (DB error)
	 *                     so callers can fail closed.
	 */
	private static function get_clients_with_initials($initials) {
		global $wpdb;

		$clients_table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
		$sql = $wpdb->prepare(
			"SELECT
				client_id as id,
				first_name,
				last_name
			FROM `{$clients_table}`
			WHERE delivery_initials = %s",
			$initials
		);

		$results = $wpdb->get_results($sql, ARRAY_A);
		if (!is_array($results)) {
			error_log('[MealsDB] Failed to execute initials lookup query: ' . ($wpdb->last_error ?: 'unknown error'));
			// FAIL CLOSED: signal an unverifiable lookup with a distinct sentinel
			// (false) instead of array(). Returning array() made validate() see
			// empty($existing_clients) and report the code AVAILABLE on a DB
			// error, so a transient failure during save could pass a DUPLICATE
			// through the app-level check (only the hard UNIQUE index backstops
			// it). This matches the sibling MealsDB_Initials::exists_in_db(),
			// which also fails closed on $wpdb error.
			return false;
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
