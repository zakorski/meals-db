<?php
/**
 * Dashboard panel — active SDNB / Veteran clients with no default rate
 * (directive K22 ITEM 4). Included from views/dashboard.php.
 *
 * WHY: a client with no meals_client_rates default row bills at the PROGRAM
 * rate (resolve_rate_for_order's fallback). For SDNB that is the primary
 * main + side tier, which over-bills a main-only client. The nightly rate
 * pass fixes every client whose basic_cost is usable; whoever is still listed
 * here needs the operator, and should be found here rather than on an
 * invoice.
 *
 * Read-only: no form, no nonce. Access is the including view's
 * MealsDB_Permissions::enforce().
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

if (!class_exists('MealsDB_Client_Rates')) {
    return;
}

$mealsdb_unrated = MealsDB_Client_Rates::get_unrated_billed_clients();

if ($mealsdb_unrated === []) {
    return;
}

$mealsdb_unrated_reasons = [
    MealsDB_Client_Rates::STATUS_NO_BASIC_COST      => __('No basic_cost on the WordPress user (missing, empty or zero).', 'meals-db'),
    MealsDB_Client_Rates::STATUS_INVALID_BASIC_COST => sprintf(
        /* translators: 1: lowest accepted rate, 2: highest accepted rate */
        __('basic_cost is not a plausible rate (accepted: %1$s to %2$s).', 'meals-db'),
        MealsDB_Money::format(MealsDB_Client_Rates::MIN_RATE_CENTS),
        MealsDB_Money::format(MealsDB_Client_Rates::MAX_RATE_CENTS)
    ),
    'pending'                                       => __('basic_cost is valid — the nightly sync will create the rate on its next run.', 'meals-db'),
];
$mealsdb_unrated_edit_base = admin_url('admin.php?page=mealsdb-clients&tab=list&action=edit&client_id=');
?>
<div class="mealsdb-dashboard-unrated" style="margin:16px 0; padding:12px 16px; background:#fff; border:1px solid #ccd0d4; border-left:4px solid #d63638;">
    <h3 style="margin:0 0 8px;"><?php esc_html_e('Billed clients with no rate', 'meals-db'); ?></h3>
    <?php if ($mealsdb_unrated === null) : ?>
        <p style="margin:0; color:#d63638;">
            <?php esc_html_e('The list of unrated clients could not be loaded (staff list or client query failed). Unrated clients may exist.', 'meals-db'); ?>
        </p>
    <?php else : ?>
        <p style="margin:0 0 8px;">
            <?php echo esc_html(sprintf(
                /* translators: %d: number of clients */
                _n(
                    '%d active SDNB / Veteran client has no default rate and is billing at the program primary rate — the wrong tier for a main-only client.',
                    '%d active SDNB / Veteran clients have no default rate and are billing at the program primary rate — the wrong tier for main-only clients.',
                    count($mealsdb_unrated),
                    'meals-db'
                ),
                count($mealsdb_unrated)
            )); ?>
        </p>
        <table class="widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Client', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Program', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Reason', 'meals-db'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mealsdb_unrated as $mealsdb_unrated_row) :
                    $mealsdb_unrated_id   = (int) $mealsdb_unrated_row['client_id'];
                    $mealsdb_unrated_name = $mealsdb_unrated_row['name'] !== ''
                        ? $mealsdb_unrated_row['name']
                        /* translators: %d: Meals DB client id */
                        : sprintf(__('Client #%d', 'meals-db'), $mealsdb_unrated_id);
                ?>
                    <tr data-client="<?php echo esc_attr((string) $mealsdb_unrated_id); ?>" data-reason="<?php echo esc_attr($mealsdb_unrated_row['reason']); ?>">
                        <td>
                            <a href="<?php echo esc_url($mealsdb_unrated_edit_base . $mealsdb_unrated_id); ?>"><?php echo esc_html($mealsdb_unrated_name); ?></a>
                            <small>(<?php echo esc_html('#' . $mealsdb_unrated_id); ?>)</small>
                        </td>
                        <td><?php echo esc_html($mealsdb_unrated_row['client_type']); ?></td>
                        <td><?php echo esc_html($mealsdb_unrated_reasons[$mealsdb_unrated_row['reason']] ?? $mealsdb_unrated_row['reason']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
