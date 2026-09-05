<?php
/**
 * SDNB cross-pipeline invoice coverage check (decision 2026-07-31).
 *
 * The three SDNB invoices (legacy Moncton, legacy Sussex, new portal) are
 * generated as separate drafts from separate client-selection queries. Nothing
 * else guarantees those selections PARTITION the month's billable SDNB
 * clients: a client whose use_legacy_billing flag flips between two
 * generations can land on two invoices (or none), and a legacy client with a
 * zone outside M/S silently falls out of both zone invoices. This check is
 * the deliberately-cheap alternative to unifying the pipelines behind one
 * monolithic artifact (considered and rejected — the draft/finalize layer
 * costs outweighed the benefit; see docs/superpowers/specs and the memory
 * note sdnb-invoice-trunk-decision).
 *
 * It WARNS, never blocks: draft generation always proceeds, warnings surface
 * on the draft page response and as ONE aggregated degraded event on the
 * operational trunk (STR-LOG: an attempt/outcome, not a committed change).
 *
 * "Billable attribution" = allocated mains or sides for the month.
 * Contribution alone does NOT count (operator ruling 2026-07-30 — such
 * clients appear on no invoice, so they need no coverage).
 *
 * Pattern 7: check_month() swallows its own \Throwable and returns [] — a
 * broken coverage check must never break invoice generation.
 */
defined('ABSPATH') || exit;

class MealsDB_Invoice_Coverage {

    /**
     * Run the coverage check for a billing month. Returns a list of warnings:
     * each ['type' => unroutable|overlap|drift|missing|stale,
     *       'client_id' => int, 'message' => string].
     * Records one aggregated degraded event when any warning exists.
     */
    public static function check_month(string $billing_month): array {
        try {
            if (!preg_match('/^\d{4}-\d{2}$/', $billing_month)) {
                return [];
            }
            $warnings = self::evaluate(
                self::expected_partition($billing_month),
                self::draft_memberships($billing_month)
            );
            if (!empty($warnings) && class_exists('MealsDB_Event_Log')) {
                $by_type = [];
                foreach ($warnings as $w) {
                    $by_type[$w['type']][] = $w['client_id'];
                }
                MealsDB_Event_Log::record([
                    'severity'  => 'warning',
                    'category'  => 'billing',
                    'subsystem' => 'invoice_coverage',
                    'event'     => 'sdnb_coverage.gap',
                    'outcome'   => MealsDB_Event_Log::OUTCOME_DEGRADED,
                    'message'   => count($warnings) . ' SDNB pipeline-coverage warning(s) for '
                        . $billing_month . ' — clients appearing on zero or two invoices, or in a draft'
                        . ' that no longer matches their routing. See context for client ids.',
                    'context'   => ['billing_month' => $billing_month, 'warnings' => $by_type],
                ]);
            }
            return $warnings;
        } catch (\Throwable $e) {
            if (class_exists('MealsDB_Logger')) {
                MealsDB_Logger::error('[MealsDB Invoice_Coverage] check_month failed: ' . $e->getMessage());
            }
            return [];
        }
    }

    /**
     * Pure partition logic (unit-tested directly).
     *
     * @param array<int,?string>      $expected    client_id => pipeline key
     *        ('sdnb_legacy:M' | 'sdnb_legacy:S' | 'sdnb_new_portal'), or null
     *        when the client is unroutable (legacy with a zone outside M/S).
     * @param array<string,array<int>> $memberships pipeline key => client_ids
     *        in the LATEST generated draft for that pipeline. A pipeline with
     *        no draft yet is ABSENT from the map (not an empty list) — absence
     *        must not produce 'missing' warnings (generating the first of the
     *        three drafts would otherwise flag every other pipeline's client).
     * @return array<int,array{type:string,client_id:int,message:string}>
     */
    public static function evaluate(array $expected, array $memberships): array {
        $warnings = [];

        // Where does each client actually sit right now?
        $seen_in = []; // client_id => [pipeline keys]
        foreach ($memberships as $key => $client_ids) {
            foreach ($client_ids as $cid) {
                $seen_in[(int) $cid][] = (string) $key;
            }
        }

        foreach ($expected as $cid => $key) {
            $cid = (int) $cid;
            // Directive 6 (ITEM 3): name the client beside the id so the warning
            // reads "Client #352 (Patricia LeBlanc) …" — no follow-up lookup to
            // find out who #352 is. Blank name falls back to the bare id.
            $who = class_exists('MealsDB_Clients')
                ? MealsDB_Clients::format_id_with_name($cid)
                : ('#' . $cid);
            if ($key === null) {
                $warnings[] = [
                    'type'      => 'unroutable',
                    'client_id' => $cid,
                    'message'   => sprintf(
                        /* translators: %s: client id and name, e.g. "#352 (Patricia LeBlanc)" */
                        __('Client %s is on legacy billing but their delivery zone is not M or S — they will appear on NO legacy invoice.', 'meals-db'),
                        $who
                    ),
                ];
                continue;
            }
            $in = $seen_in[$cid] ?? [];
            if (count($in) > 1) {
                $warnings[] = [
                    'type'      => 'overlap',
                    'client_id' => $cid,
                    'message'   => sprintf(
                        /* translators: 1: client id and name, 2: comma-separated pipeline list */
                        __('Client %1$s appears in more than one SDNB draft for this month (%2$s) — they would be billed twice.', 'meals-db'),
                        $who,
                        implode(', ', $in)
                    ),
                ];
            } elseif (count($in) === 1 && $in[0] !== $key) {
                $warnings[] = [
                    'type'      => 'drift',
                    'client_id' => $cid,
                    'message'   => sprintf(
                        /* translators: 1: client id and name, 2: draft pipeline, 3: expected pipeline */
                        __('Client %1$s sits in the %2$s draft but now routes to %3$s — regenerate or fix the client before finalizing.', 'meals-db'),
                        $who,
                        $in[0],
                        $key
                    ),
                ];
            } elseif (empty($in) && array_key_exists($key, $memberships)) {
                $warnings[] = [
                    'type'      => 'missing',
                    'client_id' => $cid,
                    'message'   => sprintf(
                        /* translators: 1: client id and name, 2: pipeline */
                        __('Client %1$s has billable meals this month but is missing from the %2$s draft — it may predate their allocation; regenerate it.', 'meals-db'),
                        $who,
                        $key
                    ),
                ];
            }
        }

        // In a draft but carrying no attribution any more: the draft is stale
        // (deallocated / rebuilt away since generation).
        foreach ($seen_in as $cid => $keys) {
            if (!array_key_exists($cid, $expected)) {
                $warnings[] = [
                    'type'      => 'stale',
                    'client_id' => (int) $cid,
                    'message'   => sprintf(
                        /* translators: 1: client id, 2: pipeline */
                        __('Client #%1$d is in the %2$s draft but no longer has billable meals this month — the draft may be stale.', 'meals-db'),
                        (int) $cid,
                        implode(', ', $keys)
                    ),
                ];
            }
        }

        return $warnings;
    }

    /**
     * Expected pipeline per billable SDNB client for the month, straight from
     * current data: active SDNB clients joined to the allocation summary,
     * kept only when mains or sides were allocated. Routing mirrors the
     * generators' own selection queries (use_legacy_billing + zone).
     *
     * @return array<int,?string> client_id => pipeline key or null.
     */
    private static function expected_partition(string $billing_month): array {
        global $wpdb;
        $clients = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENTS);
        $alloc   = MealsDB_DB::get_table_name(MealsDB_Tables::CLIENT_ALLOCATIONS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT c.client_id, c.use_legacy_billing, c.delivery_area_zone
             FROM `{$clients}` c
             INNER JOIN `{$alloc}` a
                     ON a.client_id = c.client_id AND a.billing_month = %s
             WHERE c.client_type = %s AND c.active = 1 AND c.wp_user_id > 0
               AND (a.used_mains > 0 OR a.used_sides > 0)",
            $billing_month,
            'SDNB'
        ), ARRAY_A);

        $expected = [];
        foreach ((array) $rows as $row) {
            $cid = (int) ($row['client_id'] ?? 0);
            if ($cid <= 0) { continue; }
            if ((int) ($row['use_legacy_billing'] ?? 1) === 1) {
                $zone = strtoupper(trim((string) ($row['delivery_area_zone'] ?? '')));
                $expected[$cid] = in_array($zone, ['M', 'S'], true) ? 'sdnb_legacy:' . $zone : null;
            } else {
                $expected[$cid] = 'sdnb_new_portal';
            }
        }
        return $expected;
    }

    /**
     * Client ids in the LATEST SDNB draft per pipeline key for the month.
     * Legacy drafts are keyed per zone (from the draft's stored params);
     * list() returns newest-first, so the first draft seen per key wins —
     * matching "regenerate creates a NEW draft" (older ones are superseded
     * in practice even though the status column doesn't say so).
     *
     * @return array<string,array<int>> pipeline key => client_ids.
     */
    private static function draft_memberships(string $billing_month): array {
        $memberships = [];
        $drafts = MealsDB_Invoice_Draft::list(['billing_month' => $billing_month]);
        foreach ($drafts as $meta) {
            $pipeline = (string) ($meta['pipeline'] ?? '');
            if ($pipeline !== MealsDB_Invoice_Draft::PIPELINE_SDNB_LEGACY
                && $pipeline !== MealsDB_Invoice_Draft::PIPELINE_SDNB_NEW) {
                continue;
            }
            $draft = MealsDB_Invoice_Draft::get((int) ($meta['draft_id'] ?? 0));
            if ($draft === null) {
                continue; // undecryptable draft — get() already logged it
            }
            $key = $pipeline === MealsDB_Invoice_Draft::PIPELINE_SDNB_LEGACY
                ? 'sdnb_legacy:' . strtoupper(trim((string) ($draft['params']['zone'] ?? 'M')))
                : 'sdnb_new_portal';
            if (array_key_exists($key, $memberships)) {
                continue; // an older draft for a key we already have — skip
            }
            $memberships[$key] = array_map('intval', array_keys((array) ($draft['payload']['current'] ?? [])));
        }
        return $memberships;
    }
}
