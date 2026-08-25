<?php
/**
 * Slip data provider — client + order queries for the Phase T PDF
 * pipeline.
 *
 * Class name retained as MealsDB_Delivery_Slip_Generator for binary
 * compatibility with the new MealsDB_Slip_PDF_Generator constructor
 * signature documented in directives/phase-T-pdf-slips.md. The four
 * screen-rendered slip generators that used to live here (packing,
 * picking, delivery, driver, plus their _by_zones counterparts) were
 * retired in Phase T.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_Delivery_Slip_Generator {

    /**
     * @var MealsDB_WC_Order_Query
     */
    private $order_query;

    public function __construct(MealsDB_WC_Order_Query $order_query) {
        $this->order_query = $order_query;
    }

    /**
     * Get clients scheduled for delivery on the given date.
     *
     * Matches by day-of-week against the client's delivery_day column.
     * Returns the lighter-weight columns required by the packer
     * pipeline (no encrypted PII).
     *
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_delivery_date(string $delivery_date): array {
        global $wpdb;

        // strtotime() falls back to "now" on unparseable input, so a
        // malformed $delivery_date (e.g. "2026-13-01" or a typo) would
        // silently return clients scheduled for today instead of
        // erroring. Require strict Y-m-d format up front.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
            return [];
        }
        $ts = strtotime($delivery_date);
        if ($ts === false) {
            return [];
        }

        $day_lower = strtolower(date('l', $ts));

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        // delivery_day is needed by delivery_occurrence_for_order (MAJ-6) to
        // map each candidate order to its intended delivery occurrence.
        // delivery_frequency is still carried on the row for backward
        // compatibility but no longer affects the occurrence calculation under
        // the following-week rule.
        //
        // Override owners (Section D rule 11): a client whose order was
        // manually overridden onto this date must be selected even when the
        // date is not their delivery_day weekday — for a Saturday override
        // NO client matches by weekday, so without this clause the
        // overridden order could never reach a slip at all.
        [$where, $params] = self::client_where_with_override_owners(
            $this->order_query->get_user_ids_with_delivery_date_override($delivery_date, $delivery_date),
            $day_lower
        );
        $sql = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    client_type, delivery_fee, payment_method,
                    delivery_day, delivery_frequency
             FROM `{$table}`
             {$where}",
            ...$params
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $clients = [];
        foreach ($rows as $row) {
            $uid = (int) $row['wp_user_id'];
            $clients[$uid] = $row;
        }

        return $clients;
    }

    /**
     * Get clients with full PII for driver delivery slips.
     *
     * Includes first_name, last_name, phone, delivery_fee,
     * payment_method, client_contribution, and client_type — all
     * needed for the driver-facing slip.
     *
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_driver_slips(string $delivery_date): array {
        global $wpdb;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date)) {
            return [];
        }
        $ts = strtotime($delivery_date);
        if ($ts === false) {
            return [];
        }

        $day_lower = strtolower(date('l', $ts));

        $table = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        // delivery_day is read by delivery_occurrence_for_order (MAJ-6) to
        // compute each order's intended occurrence. delivery_frequency is still
        // selected for backward compatibility but no longer drives the result
        // under the following-week rule.
        // delivery_postal_code / client_phone_2 / alternate_contact_name /
        // alternate_contact_phone_1 / alternate_contact_phone_2 feed
        // MealsDB_Slip_PDF_Generator::build_driver_block (the Midland doc-4
        // driver block). Previously unselected, so postal, secondary phone and
        // the alternate contact rendered blank on the slip and its persisted
        // batch snapshot (U06-slips-1); the skip-empty renderer hid it. None
        // are in ENCRYPTED_CLIENT_COLUMNS, so no decrypt step is needed.
        // Override owners join by user ID regardless of weekday (Section D
        // rule 11) — same clause as get_clients_for_delivery_date().
        [$where, $params] = self::client_where_with_override_owners(
            $this->order_query->get_user_ids_with_delivery_date_override($delivery_date, $delivery_date),
            $day_lower
        );
        $sql = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    first_name, last_name, client_phone_1, delivery_fee,
                    client_contribution, payment_method, client_type,
                    delivery_postal_code, client_phone_2, alternate_contact_name,
                    alternate_contact_phone_1, alternate_contact_phone_2,
                    delivery_day, delivery_frequency
             FROM `{$table}`
             {$where}",
            ...$params
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $clients = [];
        foreach ($rows as $row) {
            $uid = (int) $row['wp_user_id'];

            // first_name / last_name are stored plaintext on modern
            // installs but historical rows may still carry the legacy
            // ciphertext. safe_decrypt is tolerant of either.
            if (!empty($row['first_name'])) {
                $row['first_name'] = MealsDB_Encryption::safe_decrypt($row['first_name']);
            }
            if (!empty($row['last_name'])) {
                $row['last_name'] = MealsDB_Encryption::safe_decrypt($row['last_name']);
            }

            $clients[$uid] = $row;
        }

        return $clients;
    }

    /**
     * Build the client WHERE clause + prepare() params for a slip date,
     * optionally widened to include override owners by wp_user_id
     * (Section D rule 11). With no owners this reduces to the original
     * weekday-only clause — the query shape for the non-override case is
     * unchanged.
     *
     * @param int[]  $override_uids WP user IDs owning overridden orders on the date.
     * @param string $day_lower     Lowercase full weekday name of the slip date.
     * @return array{0: string, 1: array<int, int|string>} [WHERE sql, params]
     */
    private static function client_where_with_override_owners(array $override_uids, string $day_lower): array {
        $override_uids = array_values(array_filter(array_map('intval', $override_uids)));
        if (empty($override_uids)) {
            return [
                'WHERE active = 1 AND wp_user_id > 0 AND LOWER(delivery_day) = %s',
                [$day_lower],
            ];
        }
        $placeholders = implode(',', array_fill(0, count($override_uids), '%d'));
        return [
            "WHERE active = 1 AND wp_user_id > 0
               AND (LOWER(delivery_day) = %s OR wp_user_id IN ({$placeholders}))",
            array_merge([$day_lower], $override_uids),
        ];
    }

    /**
     * Get active clients in the specified zones (for zone-based mode).
     *
     * @param array $zone_names Zone names to include (e.g. ['Zone 1', 'Zone 3']).
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_zones(array $zone_names): array {
        global $wpdb;

        if (empty($zone_names)) {
            return [];
        }

        $table        = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $placeholders = implode(',', array_fill(0, count($zone_names), '%s'));

        // delivery_day is required so the zone/range slip can map each order
        // to its delivery occurrence via delivery_occurrence_for_order
        // (GUI-SLIP-RANGE); omitting it would leave the occurrence filter with
        // no weekday and silently drop every order. delivery_frequency is still
        // carried on the row for backward compatibility but no longer affects
        // the occurrence calculation under the following-week rule.
        $sql = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    client_type, delivery_fee, payment_method,
                    delivery_day, delivery_frequency
             FROM `{$table}`
             WHERE active = 1 AND wp_user_id > 0 AND delivery_area_name IN ({$placeholders})",
            ...$zone_names
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $clients = [];
        foreach ($rows as $row) {
            $uid = (int) $row['wp_user_id'];
            $clients[$uid] = $row;
        }

        return $clients;
    }

    /**
     * Get clients with full PII for driver slips, filtered by zone.
     *
     * @param array $zone_names Zone names to include.
     * @return array<int, array<string, mixed>> Keyed by wp_user_id.
     */
    public function get_clients_for_zones_driver(array $zone_names): array {
        global $wpdb;

        if (empty($zone_names)) {
            return [];
        }

        $table        = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $placeholders = implode(',', array_fill(0, count($zone_names), '%s'));

        // delivery_day drives the delivery-occurrence filter for the zone/range
        // slip (GUI-SLIP-RANGE). delivery_frequency is still selected for
        // backward compatibility but no longer affects the result under the
        // following-week rule.
        // delivery_postal_code / client_phone_2 / alternate_contact_name /
        // alternate_contact_phone_1 / alternate_contact_phone_2 feed
        // MealsDB_Slip_PDF_Generator::build_driver_block (the Midland doc-4
        // driver block). Previously unselected, so postal, secondary phone and
        // the alternate contact rendered blank on the slip and its persisted
        // batch snapshot (U06-slips-1); the skip-empty renderer hid it. None
        // are in ENCRYPTED_CLIENT_COLUMNS, so no decrypt step is needed.
        $sql = $wpdb->prepare(
            "SELECT client_id, wp_user_id, delivery_initials, delivery_area_zone,
                    delivery_area_name, delivery_city, delivery_street_name,
                    first_name, last_name, client_phone_1, delivery_fee,
                    client_contribution, payment_method, client_type,
                    delivery_postal_code, client_phone_2, alternate_contact_name,
                    alternate_contact_phone_1, alternate_contact_phone_2,
                    delivery_day, delivery_frequency
             FROM `{$table}`
             WHERE active = 1 AND wp_user_id > 0 AND delivery_area_name IN ({$placeholders})",
            ...$zone_names
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $clients = [];
        foreach ($rows as $row) {
            $uid = (int) $row['wp_user_id'];

            if (!empty($row['first_name'])) {
                $row['first_name'] = MealsDB_Encryption::safe_decrypt($row['first_name']);
            }
            if (!empty($row['last_name'])) {
                $row['last_name'] = MealsDB_Encryption::safe_decrypt($row['last_name']);
            }

            $clients[$uid] = $row;
        }

        return $clients;
    }

    /**
     * Fetch WC HPOS orders (with items) for a single delivery date, on the
     * DELIVERY basis (MAJ-6).
     *
     * The old single-date path (get_orders_for_date) filters on the order's
     * CREATION date, so an order placed ahead of its delivery day landed on
     * the wrong day's packer/driver slip. This method instead maps each
     * candidate order to its intended delivery occurrence — computed from the
     * client's stored delivery_day + delivery_frequency via
     * delivery_occurrence_for_order() — and keeps only the orders whose
     * occurrence equals $delivery_date.
     *
     * It deliberately does NOT reuse MealsDB_WC_Order_Query::get_orders_for_users
     * as the FILTER (that method's creation-date basis is correct and is still
     * used by reports/reconciliation); it only borrows it to fetch the
     * candidate window, then re-buckets in PHP.
     *
     * @param array<int, array<string, mixed>> $clients       Clients keyed by wp_user_id
     *                                                         (must carry delivery_day;
     *                                                         delivery_frequency is carried
     *                                                         but no longer affects occurrence).
     * @param string                           $delivery_date Y-m-d slip date.
     * @return array<int, array<string, mixed>> Orders (with items) due on $delivery_date.
     */
    public function get_orders_for_delivery_date(array $clients, string $delivery_date): array {
        // Single-date is just the degenerate range [D, D]. Both slip paths
        // share get_orders_for_delivery_range() so the occurrence filter can
        // never drift between them again (that divergence was GUI-SLIP-RANGE:
        // MAJ-6 fixed the single-date path but left the range path on the raw
        // creation-date query).
        return $this->get_orders_for_delivery_range($clients, $delivery_date, $delivery_date);
    }

    /**
     * Fetch WC HPOS orders (with items) whose DELIVERY occurrence falls within
     * [$start_date, $end_date] inclusive (GUI-SLIP-RANGE, generalising MAJ-6).
     *
     * This is the shared selection used by BOTH slip paths — the single-date
     * slip (range [D, D]) and the by-zone/date-range slip. The semantics are
     * "orders DELIVERED within the range," NOT "orders CREATED within the
     * range": a packer/driver slip is about what ships on a given day, so an
     * order placed ahead of its delivery day must land on the slip for the day
     * it is delivered, regardless of when it was created.
     *
     * The previous by-zone path (get_orders_for_range) selected on
     * date_created_gmt with no occurrence filter, so a range slip pulled every
     * order CREATED in the window and printed each order's own (scattered)
     * delivery date — the pre-MAJ-6 bug, still live on the range path. This
     * routes the range path through the same delivery_occurrence_for_order()
     * test the single-date path uses.
     *
     * @param array<int, array<string, mixed>> $clients    Clients keyed by wp_user_id
     *                                                      (must carry delivery_day;
     *                                                      delivery_frequency is carried
     *                                                      but no longer affects occurrence).
     * @param string                           $start_date Y-m-d range start (inclusive).
     * @param string                           $end_date   Y-m-d range end (inclusive).
     * @return array<int, array<string, mixed>> Orders (with items) delivered in the range.
     */
    public function get_orders_for_delivery_range(array $clients, string $start_date, string $end_date): array {
        if (empty($clients)) {
            return [];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            return [];
        }
        if ($start_date > $end_date) {
            return [];
        }

        // DIRECTIVE delivery-date-next-week-rule: an order delivered on D was
        // created during the PRECEDING calendar week (Monday-based). The latest
        // that creation week's Monday can be is D - offset - 7, where offset is
        // the delivery weekday's distance from Monday (0 for Mon … 6 for Sun).
        // The worst case is a Sunday delivery (offset = 6): prior Monday = D - 13.
        // We use a fixed 14-day lookback so the window is delivery-day-agnostic
        // and never under-counts. frequency no longer drives the window width
        // (frequency has no effect on occurrence under the new rule).
        // The per-order occurrence filter below is authoritative; a generous
        // window only costs a few extra candidate rows.
        $window_start = gmdate(
            'Y-m-d',
            strtotime($start_date . ' -14 days UTC')
        );

        $candidates = $this->order_query->get_orders_with_items_for_users(
            array_keys($clients),
            $window_start,
            $end_date
        );

        // Override-aware selection (delivery-date-override directive,
        // Section D). Rule 9: an operator-set _delivery_date meta decides
        // which slip the order belongs to — "meta wins, occurrence
        // otherwise". Rule 10: overridden orders are fetched by a meta
        // query, because an override can move delivery arbitrarily far
        // from the creation date, outside any widened creation window.
        $override_rows = $this->order_query->get_orders_with_items_for_users_by_delivery_date(
            array_keys($clients),
            $start_date,
            $end_date
        );

        $by_id        = [];
        $override_map = [];
        foreach ($candidates as $order) {
            $by_id[(int) ($order['order_id'] ?? 0)] = $order;
        }
        foreach ($override_rows as $order) {
            $oid = (int) ($order['order_id'] ?? 0);
            $val = (string) ($order['delivery_date_override'] ?? '');
            if ($oid > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $override_map[$oid] = $val;
            }
            $by_id[$oid] = $order;
        }

        if (empty($by_id)) {
            return [];
        }

        // A creation-window candidate may be overridden OUT of this range
        // (its meta isn't in $override_rows — that query is range-bound),
        // in which case it must LEAVE its computed-occurrence slip. Look
        // up the remaining candidates' overrides in one query.
        $unknown = array_diff(array_keys($by_id), array_keys($override_map));
        if (!empty($unknown)) {
            foreach ($this->order_query->get_delivery_date_overrides(array_values($unknown)) as $oid => $val) {
                $override_map[(int) $oid] = (string) $val;
            }
        }

        $matched = [];
        foreach ($by_id as $oid => $order) {
            $uid    = (int) ($order['wp_user_id'] ?? 0);
            $client = $clients[$uid] ?? null;
            if ($client === null) {
                continue;
            }

            // Rule 9: a well-formed override is authoritative for slip
            // MEMBERSHIP, not just the printed header. In range → selected
            // on the override date; out of range → excluded outright (it
            // moved to another day's slip). Either way the occurrence math
            // below is never consulted, so an order can appear on exactly
            // one slip date.
            $override = $override_map[$oid] ?? '';
            if ($override !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $override)) {
                if ($override >= $start_date && $override <= $end_date) {
                    $order['delivery_occurrence'] = $override;
                    $matched[] = $order;
                }
                continue;
            }

            $created    = (string) ($order['date_created_gmt'] ?? '');
            $occurrence = self::delivery_occurrence_for_order($created, $client);
            // Y-m-d strings compare correctly with lexical >=/<=.
            if ($occurrence !== null && $occurrence >= $start_date && $occurrence <= $end_date) {
                // Carry the computed occurrence onto the order so the slip
                // prints the DELIVERY date, not the creation date. Without
                // this, an order-ahead order (created 2026-05-15, delivered
                // 2026-05-28) is correctly INCLUDED by the filter above but
                // resolve_delivery_date() falls back to date_created_gmt and
                // prints 2026-05-15 — re-introducing out-of-range dates on the
                // slip after we just filtered them out. An explicit
                // _delivery_date order meta still wins over this computed
                // value (see resolve_delivery_date()).
                $order['delivery_occurrence'] = $occurrence;
                $matched[] = $order;
            }
        }

        return $matched;
    }

    /**
     * Map an order to the delivery occurrence it belongs to (MAJ-6).
     *
     * DIRECTIVE delivery-date-next-week-rule: the occurrence is always the
     * client's delivery weekday in the calendar week FOLLOWING the order
     * creation date (Monday-based ISO weeks). Delegates to
     * MealsDB_Date_Calculator::next_week_delivery_date().
     *
     * delivery_frequency is deliberately NOT read. A parameter that no longer
     * affects the result is how the next reader reintroduces the old
     * snap-within-week + roll-by-frequency bug. Frequency may still be stored
     * on the client row but is irrelevant to occurrence calculation.
     *
     * @param string               $order_created_date Y-m-d or 'Y-m-d H:i:s'.
     * @param array<string, mixed> $client             Must carry delivery_day (string, any case).
     * @return string|null Y-m-d occurrence, or null when the delivery day is
     *                     blank/unknown (order falls out of every slip).
     */
    public static function delivery_occurrence_for_order(string $order_created_date, array $client): ?string {
        $created = substr($order_created_date, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $created)) {
            return null;
        }
        $delivery_day = isset($client['delivery_day']) ? (string) $client['delivery_day'] : '';

        // DIRECTIVE delivery-date-next-week-rule: delivery defaults to the
        // client's delivery weekday in the calendar week FOLLOWING the order
        // date. Frequency is deliberately NOT read here — a parameter that no
        // longer affects the result is how the next reader reintroduces the old
        // snap-within-week + roll-by-frequency bug. Blank/unknown day -> null
        // (order falls out of every slip; "blank means blank").
        return MealsDB_Date_Calculator::next_week_delivery_date($created, $delivery_day);
    }

}
