<?php
/**
 * Resume-a-draft panel — Add Client tab (spec 2026-07-16 §3). The former
 * top-level Drafts tab, demoted to a collapsed <details> above the add
 * form: visible only when the operator has saved drafts (the list is
 * owner-scoped, so the count must be too), rendering the existing
 * views/drafts.php list inside.
 */

defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

global $wpdb;
$mealsdb_drafts_table = MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS);
$mealsdb_draft_count  = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `{$mealsdb_drafts_table}` WHERE created_by = %d",
    get_current_user_id()
));

if ($mealsdb_draft_count > 0) : ?>
<?php // Stay open across the panel's own pagination links (?paged=N). ?>
<details id="mealsdb-drafts-panel" style="margin-bottom:16px;"<?php echo isset($_GET['paged']) ? ' open' : ''; ?>>
    <summary style="cursor:pointer;"><strong>
        <?php echo esc_html(sprintf(
            /* translators: %d: number of the operator's saved client drafts */
            __('Resume a saved draft (%d)', 'meals-db'),
            $mealsdb_draft_count
        )); ?>
    </strong></summary>
    <?php include MealsDB_Plugin::path('views/drafts.php'); ?>
</details>
<?php endif; ?>
