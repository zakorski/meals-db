# Full-codebase review — 2026-06-10

Scope: every PHP/JS file outside `vendor/` (~68k lines), reviewed by nine parallel subsystem passes
(bootstrap/permissions, schema/DB, clients/encryption, AJAX layer, allocation/billing, sync,
reports/CSV/slips, views/admin/JS, tasks/products/migration, observability/quick-order).
Known issues already documented in CLAUDE.md (MAJ-1, STR-1/6/7/10, recon-01/02, the FIXED LB/QW
directives) are not re-reported except where a fix was found incomplete.

Severity: **C** = fix before relying on the affected subsystem; **H** = high; **M** = medium; **L** = low.

---

## 1. Security — gating and hardening

### 1.1 [C] Self-update endpoints deploy code at baseline capability
`includes/ajax/class-ajax-sync.php:175-194` (`mealsdb_run_update`) and `:151` (`check_updates`) run
`MealsDB_Updates::pull_updates()` — a `git pull` or release-zip install over the live plugin dir —
gated only by the general `mealsdb_nonce` + `can_access_plugin()` (manage_woocommerce) +
`sync_operations` (100/hr). `class-updates.php` has **zero** internal `current_user_can` re-checks
(layer 3 absent). A shop-manager-tier user can deploy whatever is on origin/main = code execution.
Fix: `manage_options` (arguably `update_plugins` too) in handler **and** service, dedicated nonce,
`migration_destructive` bucket.

### 1.2 [C] `mealsdb_update_database` runs the installer at baseline, unthrottled, lock-bypassing
`class-ajax-sync.php:214-224` → `run_database_maintenance()` (`class-updates.php:185-195`) →
`MealsDB_Installer::install()`. Baseline cap, **no rate limit** (only handler in the file without one),
and it bypasses the `mealsdb_install_lock` serialization — two concurrent calls run `install()`
simultaneously, the exact case the lock exists to prevent, and `