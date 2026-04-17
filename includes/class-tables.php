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
        ];
    }
}
