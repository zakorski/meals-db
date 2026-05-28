<?php
/**
 * Canonical table definitions for the external Meals DB.
 */
defined('ABSPATH') || exit;

class MealsDB_Tables
{
    public const CLIENTS = 'meals_clients';
    public const PRODUCTS = 'meals_products';
    public const CLIENT_RATES = 'meals_client_rates';
    public const STAFF = 'meals_staff';
    public const DRAFTS = 'meals_drafts';
    public const AUDIT_LOG = 'meals_audit_log';
    public const IGNORED_CONFLICTS = 'meals_ignored_conflicts';
    public const CLIENT_ALLOCATIONS = 'meals_client_allocations';
    public const DELIVERY_ALLOCATIONS = 'meals_delivery_allocations';
    public const SCHEDULE_RULES = 'meals_schedule_rules';
    public const TASKS = 'meals_tasks';
    public const PURCHASE_ORDERS = 'meals_purchase_orders';
    public const JOB_LOG = 'meals_job_log';
    public const HOOK_LOG = 'meals_hook_log';
    public const CLIENT_MONTH_DIRTY = 'meals_client_month_dirty';
    public const ALLOCATION_ERRORS = 'meals_allocation_errors';

    /**
     * Retrieve all canonical table names.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::CLIENTS,
            self::PRODUCTS,
            self::CLIENT_RATES,
            self::STAFF,
            self::DRAFTS,
            self::AUDIT_LOG,
            self::IGNORED_CONFLICTS,
            self::CLIENT_ALLOCATIONS,
            self::DELIVERY_ALLOCATIONS,
            self::SCHEDULE_RULES,
            self::TASKS,
            self::PURCHASE_ORDERS,
            self::JOB_LOG,
            self::HOOK_LOG,
            self::CLIENT_MONTH_DIRTY,
            self::ALLOCATION_ERRORS,
        ];
    }
}
