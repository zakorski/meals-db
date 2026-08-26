<?php
/**
 * Canonical Meals DB schema definitions and helpers.
 */
defined('ABSPATH') || exit;

class MealsDB_Schema {
    /**
     * Return canonical schema definitions keyed by base table name.
     *
     * Foreign key definitions are kept as metadata only for reporting and dependency
     * awareness; sync routines never execute them directly.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_canonical_schema(): array {
        return [
            MealsDB_Tables::CLIENTS => [
                'table'       => MealsDB_Tables::CLIENTS,
                'engine'      => 'InnoDB',
                'columns'     => [
                    'client_id'                     => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'wp_user_id'                    => 'BIGINT UNSIGNED NOT NULL',
                    'client_type'                  => "ENUM('Private','SDNB','Veteran') NOT NULL",
                    'first_name'                   => 'VARCHAR(100) NOT NULL',
                    'last_name'                    => 'VARCHAR(100) NOT NULL',
                    'client_email'                 => 'VARCHAR(255) NULL',
                    'active'                       => 'TINYINT(1) NOT NULL DEFAULT 1',
                    // Widened 20 -> 100 (DIRECTIVE phone-columns-and-insert-logging).
                    // The source billing_phone usermeta holds free text, not bare
                    // numbers ("506-204-7747 or 1-506-345-0237 (sister Diane)", 45
                    // chars). At VARCHAR(20) the migration INSERT failed with
                    // "value may be too long" and phase 1 created zero clients. All
                    // four widened together — the same free-text pattern lives in the
                    // alternate-contact meta, so widening only the two that fail today
                    // guarantees a repeat. Pure widening = SAFE drift (auto-applies via
                    // online DDL); do NOT bundle a NULL/default/rename change here or it
                    // reclassifies RISKY and is silently withheld (cf. the v558 blocker).
                    'client_phone_1'               => 'VARCHAR(100) NULL',
                    'client_phone_2'               => 'VARCHAR(100) NULL',
                    'alternate_contact_name'       => 'VARCHAR(255) NULL',
                    'alternate_contact_phone_1'    => 'VARCHAR(100) NULL',
                    'alternate_contact_phone_2'    => 'VARCHAR(100) NULL',
                    'alternate_contact_email'      => 'VARCHAR(255) NULL',
                    'do_not_call_client_phone'     => 'BOOLEAN NOT NULL DEFAULT 0',
                    'payment_method'               => 'VARCHAR(50) NULL',
                    'open_date'                    => 'DATE NULL',
                    'birth_date'                   => 'DATE NULL',
                    'gender'                       => 'VARCHAR(10) NULL',
                    'individual_id'                => 'VARCHAR(500) NULL',
                    'individual_id_index'          => 'CHAR(64) NULL',
                    'assigned_worker_name'         => 'VARCHAR(255) NULL',
                    'assigned_worker_email'        => 'VARCHAR(255) NULL',
                    'vendor_number'                => 'VARCHAR(50) NULL',
                    'service_center_charged'       => 'VARCHAR(255) NULL',
                    'service_id'                   => 'VARCHAR(50) NULL',
                    'sdnb_service_request_id'      => 'VARCHAR(50) NULL',
                    'requisition_id'               => 'VARCHAR(500) NULL',
                    'requisition_id_index'         => 'CHAR(64) NULL',
                    'requisition_period'           => 'VARCHAR(50) NULL',
                    'meal_type'                    => 'VARCHAR(50) NULL',
                    'service_name_zone'            => 'VARCHAR(10) NULL',
                    'service_commence_date'        => 'DATE NULL',
                    'expected_termination_date'    => 'DATE NULL',
                    'termination_date'             => 'DATE NULL',
                    'initial_renewal_termination_date' => 'DATE NULL',
                    'most_recent_renewal_termination_date' => 'DATE NULL',
                    'notes_to_service_provider'    => 'TEXT NULL',
                    'units'                        => 'INT NULL',
                    'allowance_mains'              => 'INT NULL',
                    'allowance_sides'              => 'INT NULL',
                    'client_contribution'          => 'DECIMAL(10,2) NULL',
                    'vet_health_card'              => 'VARCHAR(500) NULL',
                    'vet_health_card_index'        => 'CHAR(64) NULL',
                    'use_legacy_billing'           => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'default_rate_id'              => 'BIGINT UNSIGNED NULL',
                    'required_start_date'          => 'DATE NULL',
                    'delivery_day'                 => 'VARCHAR(50) NULL',
                    'delivery_area_name'           => 'VARCHAR(255) NULL',
                    'delivery_area_zone'           => 'VARCHAR(50) NULL',
                    'ordering_contact_method'      => 'VARCHAR(50) NULL',
                    'ordering_frequency'           => 'INT NULL',
                    'delivery_frequency'           => 'INT NULL',
                    'freezer_capacity'             => 'VARCHAR(50) NULL',
                    'delivery_fee'                 => 'DECIMAL(10,2) NULL',
                    'diet_concerns'                => 'TEXT NULL',
                    'customer_comments'            => 'TEXT NULL',
                    'delivery_initials'            => "VARCHAR(3) NOT NULL DEFAULT ''",
                    'delivery_initials_index'      => 'CHAR(64) NULL',
                    'street_name'                  => 'VARCHAR(255) NULL',
                    'city'                         => 'VARCHAR(255) NULL',
                    'province'                     => 'VARCHAR(10) NULL',
                    'postal_code'                  => 'VARCHAR(10) NULL',
                    'delivery_street_name'         => 'VARCHAR(255) NULL',
                    'delivery_city'                => 'VARCHAR(255) NULL',
                    'delivery_province'            => 'VARCHAR(10) NULL',
                    'delivery_postal_code'         => 'VARCHAR(10) NULL',
                    'next_order_date'              => 'DATE NULL',
                    'next_delivery_date'           => 'DATE NULL',
                    'last_delivery_date'           => 'DATE NULL',
                ],
                'primary_key' => ['client_id'],
                'indexes'     => [
                    [
                        'name'    => 'client_type',
                        'type'    => 'INDEX',
                        'columns' => ['client_type'],
                    ],
                    [
                        'name'    => 'delivery_initials_index',
                        'type'    => 'INDEX',
                        'columns' => ['delivery_initials'],
                    ],
                    [
                        'name'    => 'wp_user_id',
                        'type'    => 'INDEX',
                        'columns' => ['wp_user_id'],
                    ],
                ],
            ],
            MealsDB_Tables::PRODUCTS => [
                'table'   => MealsDB_Tables::PRODUCTS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'             => 'INT AUTO_INCREMENT',
                    'wc_product_id'  => 'INT NOT NULL',
                    'product_name'   => "VARCHAR(200) NOT NULL DEFAULT ''",
                    'price'          => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'image_url'      => 'VARCHAR(500) NULL',
                    'sku'            => 'VARCHAR(100) NULL',
                    'category_data'  => 'JSON NULL',
                    'is_published'   => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'product_type'   => "ENUM('meal','side','fee','other') NOT NULL DEFAULT 'meal'",
                    // Category-derived cache (dessert/muffin => taxable; meals
                    // never taxed). The allocation rebuilder reads it for HST
                    // side counts, so the COLUMN stays even though the operator-
                    // facing "Taxable" checkbox + `taxable_overridden` override
                    // flag were removed (DIRECTIVE ITEM 3). Purely derived — the
                    // display sync re-derives it from categories on every save.
                    'taxable'        => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'main_ingredient'=> "VARCHAR(40) NOT NULL DEFAULT ''",
                    'dietary_tags'   => 'JSON NULL',
                    'allergen_flags' => 'JSON NULL',
                    'case_size'      => 'INT NOT NULL DEFAULT 1',
                    'buffer'         => 'INT NOT NULL DEFAULT 0',
                    'unit_cost'      => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'last_updated'   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes'     => [
                    [
                        'name'    => 'wc_product_id',
                        'type'    => 'UNIQUE',
                        'columns' => ['wc_product_id'],
                    ],
                    [
                        'name'    => 'idx_is_published',
                        'type'    => 'INDEX',
                        'columns' => ['is_published'],
                    ],
                    [
                        'name'    => 'idx_product_name',
                        'type'    => 'INDEX',
                        'columns' => ['product_name'],
                    ],
                ],
            ],
            MealsDB_Tables::CLIENT_RATES => [
                'table'   => MealsDB_Tables::CLIENT_RATES,
                'engine'  => 'InnoDB',
                'columns' => [
                    'rate_id'        => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'client_id'      => 'BIGINT UNSIGNED NOT NULL',
                    'label'          => 'VARCHAR(100) NOT NULL',
                    'rate'           => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
                    'is_default'     => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'effective_date' => 'DATE NULL',
                    'created_at'     => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['rate_id'],
                'indexes'     => [
                    [
                        'name'    => 'idx_client_id',
                        'type'    => 'INDEX',
                        'columns' => ['client_id'],
                    ],
                    [
                        'name'    => 'idx_is_default',
                        'type'    => 'INDEX',
                        'columns' => ['client_id', 'is_default'],
                    ],
                ],
                // NOTE: Referential integrity for client_id is enforced
                // at the PHP layer via MealsDB_Clients_Repository and
                // related services. Database-level FK constraints are
                // intentionally NOT used — see STRUCT-3 in the v1.0.346
                // audit. A previous version carried foreign_keys
                // metadata here but it was never applied at install
                // time (the generate_create_table_sql flag defaulted to
                // false everywhere), leaving the schema in a misleading
                // "documented but not enforced" half-state.
            ],
            MealsDB_Tables::STAFF => [
                'table'   => MealsDB_Tables::STAFF,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'                => 'INT UNSIGNED AUTO_INCREMENT',
                    'wordpress_user_id' => 'BIGINT UNSIGNED NULL',
                    'first_name'        => 'VARCHAR(191) NOT NULL',
                    'last_name'         => 'VARCHAR(191) NOT NULL',
                    'email'             => 'VARCHAR(191) NOT NULL',
                    'phone'             => 'VARCHAR(50) NULL',
                    'created_at'        => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
                    'updated_at'        => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
            ],
            MealsDB_Tables::DRAFTS => [
                'table'   => MealsDB_Tables::DRAFTS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'         => 'INT UNSIGNED AUTO_INCREMENT',
                    'data'       => 'LONGTEXT NOT NULL',
                    'created_by' => 'BIGINT UNSIGNED NULL',
                    'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes'     => [
                    [
                        'name'    => 'idx_created_by',
                        'type'    => 'INDEX',
                        'columns' => ['created_by'],
                    ],
                ],
            ],
            MealsDB_Tables::AUDIT_LOG => [
                'table'   => MealsDB_Tables::AUDIT_LOG,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'            => 'INT UNSIGNED AUTO_INCREMENT',
                    'user_id'       => 'BIGINT UNSIGNED NULL',
                    'action'        => 'VARCHAR(100) NOT NULL',
                    'target_id'     => 'BIGINT UNSIGNED NULL',
                    'field_changed' => 'VARCHAR(191) NULL',
                    'old_value'     => 'TEXT NULL',
                    'new_value'     => 'TEXT NULL',
                    'source'        => "VARCHAR(100) NOT NULL DEFAULT 'mealsdb'",
                    'created_at'    => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes'     => [
                    [
                        'name'    => 'idx_user_id',
                        'type'    => 'INDEX',
                        'columns' => ['user_id'],
                    ],
                    [
                        'name'    => 'idx_target_id',
                        'type'    => 'INDEX',
                        'columns' => ['target_id'],
                    ],
                ],
            ],
            MealsDB_Tables::IGNORED_CONFLICTS => [
                'table'   => MealsDB_Tables::IGNORED_CONFLICTS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'           => 'INT UNSIGNED AUTO_INCREMENT',
                    'field_name'   => 'VARCHAR(191) NOT NULL',
                    'source_value' => 'TEXT NULL',
                    'target_value' => 'TEXT NULL',
                    'ignored_by'   => 'BIGINT UNSIGNED NULL',
                    'created_at'   => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes'     => [
                    [
                        'name'    => 'idx_field_name',
                        'type'    => 'INDEX',
                        'columns' => ['field_name'],
                    ],
                    [
                        'name'    => 'idx_ignored_by',
                        'type'    => 'INDEX',
                        'columns' => ['ignored_by'],
                    ],
                ],
            ],
            MealsDB_Tables::CLIENT_ALLOCATIONS => [
                'table'   => MealsDB_Tables::CLIENT_ALLOCATIONS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'                => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'client_id'         => 'BIGINT UNSIGNED NOT NULL',
                    'billing_month'     => 'CHAR(7) NOT NULL',
                    'permitted_mains'   => 'INT NOT NULL DEFAULT 0',
                    'permitted_sides'   => 'INT NOT NULL DEFAULT 0',
                    'used_mains'        => 'INT NOT NULL DEFAULT 0',
                    'used_sides'        => 'INT NOT NULL DEFAULT 0',
                    'used_tax_sides'    => 'INT NOT NULL DEFAULT 0',
                    'used_nontax_sides' => 'INT NOT NULL DEFAULT 0',
                    'overage_mains'     => 'INT NOT NULL DEFAULT 0',
                    'overage_sides'     => 'INT NOT NULL DEFAULT 0',
                    'is_finalized'      => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'contribution_applied'  => 'TINYINT(1) NOT NULL DEFAULT 0',
                    'contribution_order_id' => 'BIGINT UNSIGNED NULL',
                    'finalized_at'      => 'DATETIME NULL',
                    'created_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'updated_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes' => [
                    [
                        'name'    => 'idx_client_month',
                        'type'    => 'UNIQUE',
                        'columns' => ['client_id', 'billing_month'],
                    ],
                    [
                        'name'    => 'idx_billing_month',
                        'type'    => 'INDEX',
                        'columns' => ['billing_month'],
                    ],
                ],
            ],
            MealsDB_Tables::DELIVERY_ALLOCATIONS => [
                'table'   => MealsDB_Tables::DELIVERY_ALLOCATIONS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'                => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'client_id'         => 'BIGINT UNSIGNED NOT NULL',
                    'wc_order_id'       => 'BIGINT UNSIGNED NOT NULL',
                    'order_date'        => 'DATE NOT NULL',
                    'delivery_date'     => 'DATE NOT NULL',
                    'billing_month'     => 'CHAR(7) NOT NULL',
                    'mains_count'       => 'INT NOT NULL DEFAULT 0',
                    'sides_count'       => 'INT NOT NULL DEFAULT 0',
                    'tax_sides_count'   => 'INT NOT NULL DEFAULT 0',
                    'nontax_sides_count'=> 'INT NOT NULL DEFAULT 0',
                    'coverage_start'    => 'DATE NOT NULL',
                    'coverage_end'      => 'DATE NOT NULL',
                    'created_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'updated_at'        => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes' => [
                    [
                        'name'    => 'idx_client_billing',
                        'type'    => 'INDEX',
                        'columns' => ['client_id', 'billing_month'],
                    ],
                    [
                        'name'    => 'idx_order',
                        'type'    => 'INDEX',
                        'columns' => ['wc_order_id'],
                    ],
                    [
                        'name'    => 'idx_delivery_date',
                        'type'    => 'INDEX',
                        'columns' => ['delivery_date'],
                    ],
                ],
            ],
            MealsDB_Tables::SCHEDULE_RULES => [
                'table'   => MealsDB_Tables::SCHEDULE_RULES,
                'engine'  => 'InnoDB',
                'columns' => [
                    'rule_id'          => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'name'             => 'VARCHAR(255) NOT NULL',
                    'task_type'        => 'VARCHAR(100) NOT NULL',
                    'spawn_type'       => "ENUM('fixed','query') NOT NULL DEFAULT 'fixed'",
                    'recurrence'       => 'JSON NOT NULL',
                    'query_criteria'   => 'JSON NULL',
                    'payload_template' => 'JSON NOT NULL',
                    'tags'             => 'JSON NULL',
                    'assignee_role'    => 'VARCHAR(50) NULL',
                    'is_active'        => 'TINYINT(1) NOT NULL DEFAULT 1',
                    'next_run_at'      => 'DATETIME NULL',
                    'last_run_at'      => 'DATETIME NULL',
                    'created_at'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'updated_at'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['rule_id'],
                'indexes' => [
                    [
                        'name'    => 'idx_active_next_run',
                        'type'    => 'INDEX',
                        'columns' => ['is_active', 'next_run_at'],
                    ],
                    [
                        'name'    => 'idx_task_type',
                        'type'    => 'INDEX',
                        'columns' => ['task_type'],
                    ],
                ],
            ],
            MealsDB_Tables::TASKS => [
                'table'   => MealsDB_Tables::TASKS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'task_id'             => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'task_type'           => 'VARCHAR(100) NOT NULL',
                    'status'              => "ENUM('pending','in_progress','deferred','completed','skipped','abandoned') NOT NULL DEFAULT 'pending'",
                    'next_run_date'       => 'DATE NOT NULL',
                    'payload'             => 'JSON NOT NULL',
                    'source_rule_id'      => 'BIGINT UNSIGNED NULL',
                    'parent_task_id'      => 'BIGINT UNSIGNED NULL',
                    'related_entity_type' => 'VARCHAR(50) NULL',
                    'related_entity_id'   => 'BIGINT UNSIGNED NULL',
                    'assignee_role'       => 'VARCHAR(50) NULL',
                    'urgency'             => "ENUM('routine','follow_up','escalated') NOT NULL DEFAULT 'routine'",
                    'tags'                => 'JSON NULL',
                    'deferral_count'      => 'INT NOT NULL DEFAULT 0',
                    // Spawn idempotency key (directive MAJ-7). Deterministic
                    // identity of a rule-spawned task:
                    //   '<rule_id>:<entity_id|->:<next_run_date>:<task_type>'
                    // (the literal '-' stands in for a NULL related_entity_id
                    // so SPAWN_FIXED tasks dedup on a stable NON-NULL key — a
                    // composite UNIQUE over the nullable related_entity_id
                    // column would NOT, because MySQL treats every NULL as
                    // distinct). NULL here is deliberate: manually-created
                    // tasks leave it NULL and are NEVER deduped (an operator
                    // raising two ad-hoc tasks is legitimate), and MySQL UNIQUE
                    // ignores NULLs, so any number of NULL rows coexist. Only
                    // rule-spawned tasks set it; see MealsDB_Task_Rules::build_spawn_key.
                    'spawn_key'           => 'VARCHAR(191) NULL',
                    'completed_at'        => 'DATETIME NULL',
                    'completed_by'        => 'BIGINT UNSIGNED NULL',
                    'created_at'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'updated_at'          => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['task_id'],
                'indexes' => [
                    [
                        'name'    => 'idx_status_date',
                        'type'    => 'INDEX',
                        'columns' => ['status', 'next_run_date'],
                    ],
                    [
                        'name'    => 'idx_assignee_role',
                        'type'    => 'INDEX',
                        'columns' => ['assignee_role'],
                    ],
                    [
                        'name'    => 'idx_related',
                        'type'    => 'INDEX',
                        'columns' => ['related_entity_type', 'related_entity_id'],
                    ],
                    [
                        'name'    => 'idx_source_rule',
                        'type'    => 'INDEX',
                        'columns' => ['source_rule_id'],
                    ],
                    [
                        'name'    => 'idx_parent',
                        'type'    => 'INDEX',
                        'columns' => ['parent_task_id'],
                    ],
                    // Spawn idempotency (directive MAJ-7). The unique key is
                    // the REAL dedup guarantee — a re-run or overlapping cron
                    // pass that re-spawns the same (rule, entity, date, type)
                    // is rejected by the database, not by hoping the
                    // spawn->advance timing lines up. create_task treats the
                    // resulting duplicate-key error as an idempotent no-op.
                    //
                    // UPGRADE-PATH CAVEAT (verified against class-schema-sync,
                    // same as uniq_dedup above): this index is created only on
                    // the FRESH-INSTALL CREATE TABLE path. MealsDB_Schema_Sync's
                    // additive upgrade adds the spawn_key COLUMN to an existing
                    // table but does NOT add composite indexes from this array,
                    // so an already-deployed install needs a manual
                    //   ALTER TABLE <prefix>meals_tasks
                    //     ADD UNIQUE KEY uniq_spawn_key (spawn_key);
                    // to activate dedup. Until then create_task's insert
                    // succeeds for duplicates (no worse than pre-MAJ-7). The
                    // launch is a fresh install (operator confirmed no live
                    // data), so CREATE TABLE applies it cleanly.
                    [
                        'name'    => 'uniq_spawn_key',
                        'type'    => 'UNIQUE',
                        'columns' => ['spawn_key'],
                    ],
                ],
                // NOTE: Referential integrity for source_rule_id and
                // parent_task_id is enforced at the PHP layer via
                // MealsDB_Task_Engine / MealsDB_Task_Rules. Database
                // FK constraints intentionally not used — see STRUCT-3
                // in the v1.0.346 audit.
            ],
            MealsDB_Tables::PURCHASE_ORDERS => [
                'table'   => MealsDB_Tables::PURCHASE_ORDERS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'po_id'            => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'po_number'        => 'VARCHAR(50) NOT NULL',
                    'supplier'         => 'VARCHAR(100) NULL',
                    'placed_date'      => 'DATE NULL',
                    'expected_arrival' => 'DATE NULL',
                    'arrival_date'     => 'DATE NULL',
                    'status'           => "ENUM('planned','placed','arrived','counted','reconciled','cancelled','accepted') NOT NULL DEFAULT 'planned'",
                    'items'            => 'JSON NULL',
                    'notes'            => 'TEXT NULL',
                    'reconciled_at'    => 'DATETIME NULL',
                    // --- PO draft workflow (2026-07 spec; 'accepted' added 2026-08).
                    // 'accepted' is added as a SAFE, auto-applied ENUM drift. 'counted'
                    // is retained UNUSED on purpose: bundling its removal with the
                    // 'accepted' addition reclassified the whole column change as RISKY
                    // and withheld BOTH (v558 ITEM 1 — the accepted migration silently
                    // never applied, coercing writes to ''). Remove 'counted' later as a
                    // standalone operator-confirmed risky change, never bundled. payload
                    // IS NULL marks a legacy task-created PO (read-only in the new UI).
                    'payload'          => 'LONGTEXT NULL',
                    'edit_count'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'created_by'       => 'BIGINT UNSIGNED NULL',
                    'approved_by'      => 'BIGINT UNSIGNED NULL',
                    'approved_at'      => 'DATETIME NULL',
                    'accepted_by'      => 'BIGINT UNSIGNED NULL',
                    'accepted_at'      => 'DATETIME NULL',
                    'received_by'      => 'BIGINT UNSIGNED NULL',
                    'received_at'      => 'DATETIME NULL',
                    'reconciled_by'    => 'BIGINT UNSIGNED NULL',
                    'created_at'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    'updated_at'       => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['po_id'],
                'indexes' => [
                    [
                        'name'    => 'uniq_po_number',
                        'type'    => 'UNIQUE',
                        'columns' => ['po_number'],
                    ],
                    [
                        'name'    => 'idx_status',
                        'type'    => 'INDEX',
                        'columns' => ['status'],
                    ],
                    [
                        'name'    => 'idx_expected_arrival',
                        'type'    => 'INDEX',
                        'columns' => ['expected_arrival'],
                    ],
                ],
            ],
            // Central operational event-log trunk (directive STR-LOG).
            // Collapses the former meals_job_log + meals_hook_log. The
            // job-lifecycle columns (started_at / completed_at /
            // duration_seconds / records_*) are kept FIRST-CLASS and
            // indexable — NOT buried in `context` — so hang-detection and
            // duration survive the collapse. Most rows are immutable point
            // events; the few job rows are mutable (start() inserts
            // outcome='running', finish()/fail() UPDATE the same row).
            // The `degraded` outcome is the point of the exercise: it lets
            // a caller say "I continued but swallowed a problem" instead of
            // reporting a clean success — see directive §"degraded".
            MealsDB_Tables::EVENT_LOG => [
                'table'   => MealsDB_Tables::EVENT_LOG,
                'engine'  => 'InnoDB',
                'columns' => [
                    'log_id'            => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'occurred_at'       => 'DATETIME NOT NULL',
                    'severity'          => "ENUM('debug','info','notice','warning','error','critical') NOT NULL DEFAULT 'info'",
                    'category'          => 'VARCHAR(50) NOT NULL',
                    'subsystem'         => 'VARCHAR(100) NULL',
                    'event'             => 'VARCHAR(150) NOT NULL',
                    'outcome'           => "ENUM('succeeded','failed','degraded','running') NOT NULL DEFAULT 'succeeded'",
                    'message'           => 'TEXT NULL',
                    'context'           => 'JSON NULL',
                    'entity_type'       => 'VARCHAR(30) NULL',
                    'entity_id'         => 'BIGINT UNSIGNED NULL',
                    'correlation_id'    => 'VARCHAR(40) NULL',
                    'user_id'           => 'BIGINT UNSIGNED NULL',
                    // Job-lifecycle columns (NULL for non-job events).
                    'started_at'        => 'DATETIME NULL',
                    'completed_at'      => 'DATETIME NULL',
                    'duration_seconds'  => 'INT UNSIGNED NULL',
                    'records_processed' => 'INT UNSIGNED NULL',
                    'records_updated'   => 'INT UNSIGNED NULL',
                    'records_skipped'   => 'INT UNSIGNED NULL',
                    'records_errored'   => 'INT UNSIGNED NULL',
                ],
                'primary_key' => ['log_id'],
                'indexes' => [
                    [
                        'name'    => 'idx_occurred',
                        'type'    => 'INDEX',
                        'columns' => ['occurred_at'],
                    ],
                    [
                        'name'    => 'idx_severity',
                        'type'    => 'INDEX',
                        'columns' => ['severity'],
                    ],
                    [
                        'name'    => 'idx_category',
                        'type'    => 'INDEX',
                        'columns' => ['category'],
                    ],
                    [
                        'name'    => 'idx_outcome',
                        'type'    => 'INDEX',
                        'columns' => ['outcome'],
                    ],
                    [
                        'name'    => 'idx_event',
                        'type'    => 'INDEX',
                        'columns' => ['event'],
                    ],
                    [
                        'name'    => 'idx_entity',
                        'type'    => 'INDEX',
                        'columns' => ['entity_type', 'entity_id'],
                    ],
                    [
                        'name'    => 'idx_correlation',
                        'type'    => 'INDEX',
                        'columns' => ['correlation_id'],
                    ],
                    // Hang detection: find stale 'running' rows quickly.
                    [
                        'name'    => 'idx_running',
                        'type'    => 'INDEX',
                        'columns' => ['outcome', 'occurred_at'],
                    ],
                ],
            ],

            MealsDB_Tables::CLIENT_MONTH_DIRTY => [
                'table'   => MealsDB_Tables::CLIENT_MONTH_DIRTY,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'             => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'client_id'      => 'BIGINT UNSIGNED NOT NULL',
                    'billing_month'  => 'CHAR(7) NOT NULL',
                    'marked_at'      => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                ],
                'primary_key' => ['id'],
                'indexes' => [
                    [
                        'name'    => 'uq_client_month',
                        'type'    => 'UNIQUE',
                        'columns' => ['client_id', 'billing_month'],
                    ],
                ],
            ],

            MealsDB_Tables::ALLOCATION_ERRORS => [
                'table'   => MealsDB_Tables::ALLOCATION_ERRORS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'id'             => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'client_id'      => 'BIGINT UNSIGNED NOT NULL',
                    'billing_month'  => 'CHAR(7) NOT NULL',
                    'wc_order_id'    => 'BIGINT UNSIGNED NULL',
                    'error_type'     => "VARCHAR(64) NOT NULL DEFAULT 'multi_month_spillover'",
                    'mains_unplaced' => 'INT NOT NULL DEFAULT 0',
                    'sides_unplaced' => 'INT NOT NULL DEFAULT 0',
                    'message'        => 'TEXT NULL',
                    'created_at'     => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                    // Dedup bookkeeping (directive MAJ-2). The nightly
                    // rebuilder re-processes dirty months, so an unresolved
                    // spillover on the same order used to write a fresh row
                    // every run. The writer now upserts on the dedup key
                    // below; occurrence_count tracks how many times the same
                    // error recurred, last_seen_at drives retention (prune by
                    // last_seen, NOT first_seen — a long-running recurring
                    // error has an old first_seen but is still active).
                    // NULL/defaulted so the additive schema sync can add them
                    // to existing rows without a data migration (STR-11).
                    'occurrence_count' => 'INT UNSIGNED NOT NULL DEFAULT 1',
                    'first_seen_at'    => 'DATETIME NULL',
                    'last_seen_at'     => 'DATETIME NULL',
                ],
                'primary_key' => ['id'],
                'indexes' => [
                    [
                        'name'    => 'idx_client_month',
                        'type'    => 'INDEX',
                        'columns' => ['client_id', 'billing_month'],
                    ],
                    [
                        'name'    => 'idx_created_at',
                        'type'    => 'INDEX',
                        'columns' => ['created_at'],
                    ],
                    // Dedup key (directive MAJ-2). The natural identity of an
                    // allocation error is (client, month, order, type); a
                    // repeat must UPDATE not INSERT (the upsert in
                    // log_spillover_error relies on this UNIQUE key firing ON
                    // DUPLICATE KEY). error_type is currently always
                    // 'multi_month_spillover' (one writer, one type), but is
                    // in the key so future error types dedup correctly without
                    // a schema change.
                    //
                    // UPGRADE-PATH CAVEAT (verified against class-schema-sync):
                    // this index is created only on the FRESH-INSTALL CREATE
                    // TABLE path (generate_create_table_sql emits the indexes
                    // array). MealsDB_Schema_Sync's additive upgrade adds the
                    // three columns above to an EXISTING table but does NOT add
                    // composite indexes from this array — so an
                    // already-deployed install needs a manual
                    //   ALTER TABLE <prefix>meals_allocation_errors
                    //     ADD UNIQUE KEY uniq_dedup
                    //       (client_id, billing_month, wc_order_id, error_type);
                    // to activate dedup. Until that index exists the upsert
                    // degrades to plain INSERT (no worse than the pre-MAJ-2
                    // behaviour). The launch is a fresh install (operator
                    // confirmed no live data), so CREATE TABLE applies it
                    // cleanly. A populated install with existing dupes must
                    // first collapse them to one row per key (directive MAJ-2
                    // Step 1) or the ADD UNIQUE will fail on the duplicates.
                    [
                        'name'    => 'uniq_dedup',
                        'type'    => 'UNIQUE',
                        'columns' => ['client_id', 'billing_month', 'wc_order_id', 'error_type'],
                    ],
                ],
            ],

            // Invoice draft staging (directive INV-DRAFT-1). One row per
            // generated draft. `payload` is the encrypted snapshot+working
            // copy of the per-client billing rows (see Step 3 of the
            // directive) — it carries client/veteran PII (names, health-card #,
            // individual_id), hence encryption at rest, like the client drafts.
            MealsDB_Tables::INVOICE_DRAFTS => [
                'table'   => MealsDB_Tables::INVOICE_DRAFTS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'draft_id'      => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    'pipeline'      => "ENUM('vac','sdnb_legacy','sdnb_new_portal') NOT NULL",
                    // Period the invoice covers. period_start/end are the
                    // user-typed Y-m-d the generator already takes;
                    // billing_month is substr(start,0,7) — the same key
                    // get_phase2_billing_data and finalize_month use.
                    'billing_month' => 'CHAR(7) NOT NULL',
                    'period_start'  => 'DATE NOT NULL',
                    'period_end'    => 'DATE NOT NULL',
                    // Pipeline params that aren't the period (e.g. SDNB legacy
                    // zone). Small JSON; NOT PII. Lets finalize re-invoke the
                    // right serializer.
                    'params'        => 'JSON NULL',
                    // 'superseded' is reserved for a future "mark replaced"
                    // affordance — nothing writes it yet (regenerate creates a
                    // NEW draft; operator decision #4). Declared now because
                    // STR-11 schema-sync cannot alter an ENUM in place later.
                    'status'        => "ENUM('draft','finalized','superseded') NOT NULL DEFAULT 'draft'",
                    // Encrypted payload: BOTH the immutable generated snapshot
                    // and the editable current working copy (see INV-DRAFT-1
                    // Step 3 for the JSON shape).
                    'payload'       => 'LONGTEXT NOT NULL',
                    'row_count'     => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'edit_count'    => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'created_by'    => 'BIGINT UNSIGNED NULL',
                    'created_at'    => 'DATETIME NOT NULL',
                    'finalized_by'  => 'BIGINT UNSIGNED NULL',
                    'finalized_at'  => 'DATETIME NULL',
                    // INV-DRAFT-3 Step 2: the EXACT serialized artifact captured
                    // at finalize time (encrypted via encode_payload, like the
                    // payload — it carries the same PII; the VAC PDF is binary,
                    // so it is base64'd inside the encrypted blob). A finalized
                    // government invoice is immutable — the download endpoint
                    // streams these bytes rather than regenerating, which could
                    // drift if any input changed. Additive column: Schema_Sync
                    // ADDS it on upgrade (STR-11 — it cannot ALTER, but an add
                    // is exactly what it does).
                    'finalized_output' => 'LONGTEXT NULL',
                ],
                'primary_key' => ['draft_id'],
                'indexes' => [
                    [
                        'name'    => 'idx_pipeline_month',
                        'type'    => 'INDEX',
                        'columns' => ['pipeline', 'billing_month'],
                    ],
                    [
                        'name'    => 'idx_status',
                        'type'    => 'INDEX',
                        'columns' => ['status'],
                    ],
                    [
                        'name'    => 'idx_created',
                        'type'    => 'INDEX',
                        'columns' => ['created_at'],
                    ],
                ],
            ],

            // Midland packing-slip batches (directive 01). One row per generated
            // batch (zone + delivery date). `doc4_payload` is the encrypted JSON
            // array of per-order driver blocks (name/address/phone/collect),
            // positional — element N is the driver block for order N, in the
            // same order doc 2 (the packer slips) emits them. It
            // carries client PII, hence encryption at rest like the invoice
            // draft payload. Additive table — STR-11 schema-sync ADDS it; it
            // cannot ALTER an ENUM later, so the status set is declared up front
            // (now a single 'generated' value). Mirrors INVOICE_DRAFTS.
            MealsDB_Tables::SLIP_BATCHES => [
                'table'   => MealsDB_Tables::SLIP_BATCHES,
                'engine'  => 'InnoDB',
                'columns' => [
                    'batch_id'        => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    // Identity: one batch per zone + delivery date.
                    'zone_name'       => 'VARCHAR(100) NOT NULL',
                    'delivery_date'   => 'DATE NOT NULL',
                    'order_count'     => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    // Encrypted JSON array of doc 4 driver-block payloads, one
                    // per order, in the SAME positional order doc 2 was emitted.
                    'doc4_payload'    => 'LONGTEXT NOT NULL',
                    // Only 'generated' is ever written (cancel() hard-deletes
                    // the row). Schema_Sync is additive-only and cannot ALTER an
                    // existing ENUM, so installs created before the status set
                    // was narrowed to one value keep the old 3-value ENUM with
                    // the dead values unused — harmless.
                    'status'          => "ENUM('generated') NOT NULL DEFAULT 'generated'",
                    'created_by'      => 'BIGINT UNSIGNED NULL',
                    'created_at'      => 'DATETIME NOT NULL',
                    'updated_at'      => 'DATETIME NOT NULL',
                ],
                'primary_key' => ['batch_id'],
                'indexes' => [
                    [
                        'name'    => 'idx_zone_date',
                        'type'    => 'INDEX',
                        'columns' => ['zone_name', 'delivery_date'],
                    ],
                    [
                        'name'    => 'idx_status',
                        'type'    => 'INDEX',
                        'columns' => ['status'],
                    ],
                    [
                        'name'    => 'idx_created',
                        'type'    => 'INDEX',
                        'columns' => ['created_at'],
                    ],
                ],
            ],

            // Weekly order audit (spec 2026-07-30). One row per audited
            // Mon–Sun week. `payload` is the encrypted {generated, current}
            // snapshot of the week's delivered orders (client names = PII,
            // hence encryption at rest like the invoice-draft payload).
            // One-audit-per-week is enforced in the SERVICE
            // (MealsDB_Order_Audit::find_by_week before insert), not by a
            // UNIQUE index — Schema_Sync is additive-only and its index
            // support is exercised only with plain INDEX entries; a service
            // check also lets create() surface the existing audit instead
            // of erroring. Additive table — STR-11 schema-sync ADDS it.
            MealsDB_Tables::ORDER_AUDITS => [
                'table'   => MealsDB_Tables::ORDER_AUDITS,
                'engine'  => 'InnoDB',
                'columns' => [
                    'audit_id'          => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    // Monday and Sunday of the audited week.
                    'week_start'        => 'DATE NOT NULL',
                    'week_end'          => 'DATE NOT NULL',
                    'status'            => "ENUM('draft','finalized') NOT NULL DEFAULT 'draft'",
                    'payload'           => 'LONGTEXT NOT NULL',
                    'row_count'         => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'confirmed_count'   => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'edited_count'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
                    'created_by'        => 'BIGINT UNSIGNED NULL',
                    'created_at'        => 'DATETIME NOT NULL',
                    'finalized_by'      => 'BIGINT UNSIGNED NULL',
                    'finalized_at'      => 'DATETIME NULL',
                    'unfinalized_at'    => 'DATETIME NULL',
                    'unfinalize_reason' => 'VARCHAR(500) NULL',
                ],
                'primary_key' => ['audit_id'],
                'indexes' => [
                    [
                        'name'    => 'idx_week_start',
                        'type'    => 'INDEX',
                        'columns' => ['week_start'],
                    ],
                    [
                        'name'    => 'idx_status',
                        'type'    => 'INDEX',
                        'columns' => ['status'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Retrieve the schema definition for a specific table key.
     */
    public static function get_table_schema(string $table): ?array {
        $schemas = self::get_canonical_schema();

        return $schemas[$table] ?? null;
    }

    /**
     * Generate a CREATE TABLE statement for a canonical schema.
     *
     * HISTORY: A previous signature accepted $include_foreign_keys to
     * optionally emit ALTER TABLE ADD CONSTRAINT clauses. The flag
     * defaulted to false everywhere it was called from, so no FK
     * constraint ever made it into the database — the foreign_keys
     * metadata was documentation only (STRUCT-3 in the v1.0.346 audit).
     * The metadata and the parameter have both been removed; referential
     * integrity is enforced at the PHP layer (see
     * MealsDB_Clients_Repository and the cascading service methods).
     */
    public static function generate_create_table_sql(array $schema): string {
        $table_name = MealsDB_DB::get_table_name($schema['table']);
        $table_name = str_replace('`', '``', $table_name);

        $parts = [];
        foreach ($schema['columns'] as $name => $definition) {
            $parts[] = sprintf('`%s` %s', $name, $definition);
        }

        if (!empty($schema['primary_key'])) {
            $primary_keys = array_map(static function ($column) {
                return str_replace('`', '``', (string) $column);
            }, (array) $schema['primary_key']);

            $parts[] = sprintf('PRIMARY KEY (`%s`)', implode('`,`', $primary_keys));
        }

        if (!empty($schema['indexes']) && is_array($schema['indexes'])) {
            foreach ($schema['indexes'] as $index) {
                $parts[] = self::build_index_definition($index);
            }
        }

        $charset_sql = self::build_charset_collation_sql();
        $engine      = $schema['engine'] ?? 'InnoDB';

        return sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (%s) ENGINE=%s %s;',
            $table_name,
            implode(',', $parts),
            $engine,
            $charset_sql
        );
    }

    /**
     * Fetch the primary key columns for a canonical table, if defined.
     *
     * @return string[]
     */
    public static function get_primary_key_columns(string $table): array {
        $schemas = self::get_canonical_schema();

        if (!isset($schemas[$table])) {
            return [];
        }

        $primary_keys = $schemas[$table]['primary_key'] ?? [];

        if (empty($primary_keys)) {
            return [];
        }

        return array_map(static function ($column): string {
            return (string) $column;
        }, (array) $primary_keys);
    }

    /**
     * Retrieve the singular primary key column for a canonical table.
     *
     * Returns null for tables without a defined primary key or with a composite key.
     */
    public static function get_primary_key_column(string $table): ?string {
        $primary_keys = self::get_primary_key_columns($table);

        if (count($primary_keys) !== 1) {
            return null;
        }

        return $primary_keys[0];
    }

    /**
     * Build consistent charset/collation SQL using the active connection.
     */
    public static function build_charset_collation_sql(): string {
        global $wpdb;

        $charset = $wpdb->charset ?: 'utf8mb4';
        $collate = $wpdb->collate ?: 'utf8mb4_unicode_ci';

        return sprintf('DEFAULT CHARSET=%s COLLATE=%s', $charset, $collate);
    }

    /**
     * Build an index definition snippet.
     */
    public static function build_index_definition(array $index): string {
        $type = strtoupper($index['type'] ?? 'INDEX');
        $name = $index['name'] ?? '';
        $cols = array_map(static function ($col) {
            return sprintf('`%s`', $col);
        }, $index['columns'] ?? []);

        $type_sql = $type === 'UNIQUE' ? 'UNIQUE KEY' : 'KEY';

        return sprintf('%s `%s` (%s)', $type_sql, $name, implode(',', $cols));
    }

}
