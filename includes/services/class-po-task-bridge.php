<?php
/**
 * Bridge: PO draft workflow → task system (spec 2026-07-10 task-integration §2).
 *
 * Listens to the mealsdb_po_* lifecycle hooks and keeps the task dashboard in
 * sync: approve spawns a confirm-arrival task, receive closes it and spawns a
 * reconcile task, un-approve/reconcile close whatever is open. The PO service
 * stays task-free; this class is the ONLY place the two systems meet.
 *
 * Auto-close uses skip_task with an explanatory note — never a synthesized
 * complete_task — because the audit log, not the task record, is the system
 * of record for what happened (spec §2 "auto-close rule").
 *
 * Every handler swallows \Throwable (Pattern 7): a task problem must never
 * break the PO action that triggered it. Failures surface as degraded events
 * on the Event Log dashboard.
 *
 * @package MealsDB
 */

defined('ABSPATH') || exit;

class MealsDB_PO_Task_Bridge {

    /** Non-terminal task statuses — "still on the dashboard". */
    private const OPEN_STATUSES = ['pending', 'in_progress', 'deferred'];

    /** Fallback confirm-task lag when approval carried no expected arrival. */
    private const DEFAULT_ARRIVAL_LAG_DAYS = 7;

    /** Reconcile-task lag after receiving (mirrors the legacy +7 count lag). */
    private const RECONCILE_LAG_DAYS = 7;

    public static function init(): void {
        add_action('mealsdb_po_approved',   [__CLASS__, 'on_approved'], 10, 2);
        add_action('mealsdb_po_unapproved', [__CLASS__, 'on_unapproved'], 10, 2);
        add_action('mealsdb_po_received',   [__CLASS__, 'on_received'], 10, 1);
        add_action('mealsdb_po_reconciled', [__CLASS__, 'on_reconciled'], 10, 1);
    }

    /** Approve → spawn the confirm-arrival task (deduped by open-task query). */
    public static function on_approved($po_id, $expected_arrival = null): void {
        try {
            $po_id  = (int) $po_id;
            $engine = new MealsDB_Task_Engine();
            if (!empty(self::open_tasks($engine, $po_id, MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID))) {
                return; // already queued
            }
            $po = (new MealsDB_Purchase_Orders())->get($po_id);
            if ($po === null) {
                return;
            }
            $due = (is_string($expected_arrival) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected_arrival))
                ? $expected_arrival
                : gmdate('Y-m-d', strtotime('+' . self::DEFAULT_ARRIVAL_LAG_DAYS . ' days'));

            $engine->create_task([
                'task_type'           => MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID,
                'payload'             => [
                    'po_number'        => (string) ($po['po_number'] ?? ''),
                    'supplier'         => (string) ($po['supplier'] ?? ''),
                    'expected_arrival' => $due,
                ],
                'next_run_date'       => $due,
                'related_entity_type' => 'po',
                'related_entity_id'   => $po_id,
                'assignee_role'       => 'warehouse',
                'urgency'             => MealsDB_Task_Engine::URGENCY_ROUTINE,
            ]);
        } catch (\Throwable $e) {
            self::degrade('po_bridge.approved_failed', (int) $po_id, $e);
        }
    }

    /** Un-approve → close whatever is open; a later re-approve spawns fresh. */
    public static function on_unapproved($po_id, $reason = ''): void {
        try {
            $po_id  = (int) $po_id;
            $engine = new MealsDB_Task_Engine();
            $note   = 'PO un-approved: ' . (string) $reason;
            foreach ([MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID, MealsDB_Task_Type_PO_Reconcile::TYPE_ID] as $type) {
                foreach (self::open_tasks($engine, $po_id, $type) as $task) {
                    $engine->skip_task((int) $task['task_id'], $note);
                }
            }
        } catch (\Throwable $e) {
            self::degrade('po_bridge.unapproved_failed', (int) $po_id, $e);
        }
    }

    /** Receive → close the confirm task, spawn the reconcile task. */
    public static function on_received($po_id): void {
        try {
            $po_id  = (int) $po_id;
            $engine = new MealsDB_Task_Engine();
            $po     = (new MealsDB_Purchase_Orders())->get_with_payload($po_id);
            if ($po === null) {
                return;
            }
            $note = sprintf('Done on the PO page (received, PO %s).', (string) ($po['po_number'] ?? $po_id));
            foreach (self::open_tasks($engine, $po_id, MealsDB_Task_Type_PO_Confirm_Arrival::TYPE_ID) as $task) {
                $engine->skip_task((int) $task['task_id'], $note);
            }

            if (!empty(self::open_tasks($engine, $po_id, MealsDB_Task_Type_PO_Reconcile::TYPE_ID))) {
                return; // reconcile already queued
            }
            // Rows in CASES for the task form, from the workflow payload
            // (ordered rows only — zero-case rows were never ordered).
            $rows = [];
            if (is_array($po['payload'])) {
                foreach ($po['payload']['current'] as $row) {
                    $cases = (int) ($row['cases'] ?? 0);
                    if ($cases <= 0) {
                        continue;
                    }
                    $rows[] = [
                        'sku'           => (string) $row['sku'],
                        'product_name'  => (string) ($row['product_name'] ?? ''),
                        'ordered_cases' => $cases,
                    ];
                }
            }
            $engine->create_task([
                'task_type'           => MealsDB_Task_Type_PO_Reconcile::TYPE_ID,
                'payload'             => [
                    'po_number' => (string) ($po['po_number'] ?? ''),
                    'rows'      => $rows,
                ],
                'next_run_date'       => gmdate('Y-m-d', strtotime('+' . self::RECONCILE_LAG_DAYS . ' days')),
                'related_entity_type' => 'po',
                'related_entity_id'   => $po_id,
                'assignee_role'       => 'warehouse',
                'urgency'             => MealsDB_Task_Engine::URGENCY_ROUTINE,
            ]);
        } catch (\Throwable $e) {
            self::degrade('po_bridge.received_failed', (int) $po_id, $e);
        }
    }

    /** Reconciled → close the reconcile task. */
    public static function on_reconciled($po_id): void {
        try {
            $po_id  = (int) $po_id;
            $engine = new MealsDB_Task_Engine();
            $po     = (new MealsDB_Purchase_Orders())->get($po_id);
            $note   = sprintf('Done on the PO page (reconciled, PO %s).', (string) ($po['po_number'] ?? $po_id));
            foreach (self::open_tasks($engine, $po_id, MealsDB_Task_Type_PO_Reconcile::TYPE_ID) as $task) {
                $engine->skip_task((int) $task['task_id'], $note);
            }
        } catch (\Throwable $e) {
            self::degrade('po_bridge.reconciled_failed', (int) $po_id, $e);
        }
    }

    /** @return array<int, array<string, mixed>> open tasks of $type linked to the PO */
    private static function open_tasks(MealsDB_Task_Engine $engine, int $po_id, string $type): array {
        return $engine->query_tasks([
            'task_type'           => $type,
            'related_entity_type' => 'po',
            'related_entity_id'   => $po_id,
            'status'              => self::OPEN_STATUSES,
        ]);
    }

    private static function degrade(string $event, int $po_id, \Throwable $e): void {
        if (class_exists('MealsDB_Logger')) {
            MealsDB_Logger::error('[MealsDB PO Task Bridge] ' . $event . ': ' . $e->getMessage());
        }
        if (class_exists('MealsDB_Event_Log')) {
            MealsDB_Event_Log::record([
                'severity'  => 'error',
                'category'  => 'task',
                'subsystem' => 'po_task_bridge',
                'event'     => $event,
                'outcome'   => 'degraded',
                'message'   => $e->getMessage(),
                'context'   => ['po_id' => $po_id],
            ]);
        }
    }
}
