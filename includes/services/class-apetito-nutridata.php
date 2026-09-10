<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Fetch a single Apetito Nutridata page. One request per operator action,
 * cached 7 days. Behaves like a person reading a public website: validated
 * code, descriptive UA, 10s timeout, NO bulk crawl.
 *
 * An unknown code returns HTTP 500 with an ASP.NET stack trace (see
 * tests/fixtures/apetito/99999-notfound.html), so we treat ANY non-200 as a
 * structured failure, never parse the body, and never surface Apetito's raw
 * error text (which leaks their server path) to the operator.
 */
class MealsDB_Apetito_Nutridata {

    private const BASE = 'https://my.apetito.ca/nutridata/details/';
    private const TTL  = 7 * DAY_IN_SECONDS; // product data changes per menu cycle
    private const CODE_RE = '/^[0-9]{5}$/';

    public static function is_valid_code(string $code): bool {
        return (bool) preg_match(self::CODE_RE, $code);
    }

    public static function cache_key(string $code): string {
        return 'mealsdb_apetito_' . $code;
    }

    /**
     * @return array ['ok'=>true,'html'=>string,'cached'=>bool] | ['ok'=>false,'reason'=>string]
     */
    public static function fetch(string $code): array {
        if (!self::is_valid_code($code)) {
            return ['ok' => false, 'reason' => __('Enter a 5-digit Apetito code.', 'meals-db')];
        }

        $cached = get_transient(self::cache_key($code));
        if (is_string($cached) && $cached !== '') {
            return ['ok' => true, 'html' => $cached, 'cached' => true];
        }

        $response = wp_remote_get(self::BASE . $code, [
            'timeout'    => 10,
            'user-agent' => 'Meals & More NB product tool (WordPress; +https://mealsandmorenb.ca)',
            'headers'    => ['Accept' => 'text/html'],
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'reason' => sprintf(
                /* translators: %s: apetito item code */
                __('Could not reach Apetito for code %s. Try again, or enter the item manually.', 'meals-db'), $code)];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            // Do NOT parse or echo the body — an unknown code is a 500 with a
            // stack trace exposing Apetito's server path.
            return ['ok' => false, 'reason' => sprintf(
                /* translators: %s: apetito item code */
                __('No product found for code %s on Apetito (or the site is unavailable). Check the code, or enter the item manually.', 'meals-db'), $code)];
        }

        $body = (string) wp_remote_retrieve_body($response);
        if (trim($body) === '') {
            return ['ok' => false, 'reason' => sprintf(
                /* translators: %s: apetito item code */
                __('Apetito returned an empty page for code %s.', 'meals-db'), $code)];
        }

        set_transient(self::cache_key($code), $body, self::TTL);
        return ['ok' => true, 'html' => $body, 'cached' => false];
    }
}
