<?php
/**
 * Dashboard panel — active clients whose WordPress customer_group is Extra
 * Mural (directive K23 ITEM 5). Included from views/dashboard.php.
 *
 * WHY: Extra Mural is billed separately, not to SDNB (confirmed 2026-11-03),
 * and Meals DB has no client type for it. These clients were classified
 * before that was known and still sit as SDNB (billed to SDNB) or Private
 * (charged the full order total). K23 changes none of them automatically;
 * each needs an operator decision, and this panel is where they are found.
 *
 * Read-only: no form, no nonce. Access is the including view's
 * MealsDB_Permissions::enforce().
 *
 * @package MealsDB
 */
defined('ABSPATH') || exit;

if (!class_exists('MealsDB_Extra_Mural_Clients')) {
    return;
}

$mealsdb_em_clients = MealsDB_Extra_Mural_Clients::get_active_clients();

if ($mealsdb_em_clients === []) {
    return;
}

$mealsdb_em_edit_base = admin_url('admin.php?page=mealsdb-clients&tab=list&action=edit&client_id=');
?>
<div class="mealsdb-dashboard-extra-mural" style="margin:16px 0; padding:12px 16px; background:#fff; border:1px solid #ccd0d4; border-left:4px solid #dba617;">
    <h3 style="margin:0 0 8px;"><?php esc_html_e('Extra Mural clients', 'meals-db'); ?></h3>
    <?php if ($mealsdb_em_clients === null) : ?>
        <p style="margin:0; color:#d63638;">
            <?php esc_html_e('The list of Extra Mural clients could not be loaded. Extra Mural clients may exist.', 'meals-db'); ?>
        </p>
    <?php else : ?>
        <p style="margin:0 0 8px;">
            <?php echo esc_html(sprintf(
                /* translators: %d: number of clients */
                _n(
                    '%d active client belongs to Extra Mural, which is billed separately and which Meals DB does not bill. The client type shown is how Meals DB bills them today. It has not been changed automatically and needs a decision.',
                    '%d active clients belong to Extra Mural, which is billed separately and which Meals DB does not bill. The client type shown is how Meals DB bills each of them today. None has been changed automatically; each needs a decision.',
                    count($mealsdb_em_clients),
                    'meals-db'
                ),
                count($mealsdb_em_clients)
            )); ?>
        </p>
        <table class="widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Client', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Current client type', 'meals-db'); ?></th>
                    <th><?php esc_html_e('WordPress group', 'meals-db'); ?></th>
                    <th><?php esc_html_e('Last delivery', 'meals-db'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mealsdb_em_clients as $mealsdb_em_row) :
                    $mealsdb_em_id   = (int) $mealsdb_em_row['client_id'];
                    $mealsdb_em_name = $mealsdb_em_row['name'] !== ''
                        ? $mealsdb_em_row['name']
                        /* translators: %d: Meals DB client id */
                        : sprintf(__('Client #%d', 'meals-db'), $mealsdb_em_id);
                ?>
                    <tr data-client="<?php echo esc_attr((string) $mealsdb_em_id); ?>">
                        <td>
                            <a href="<?php echo esc_url($mealsdb_em_edit_base . $mealsdb_em_id); ?>"><?php echo esc_html($mealsdb_em_name); ?></a>
                            <small>(<?php echo esc_html('#' . $mealsdb_em_id); ?>)</small>
                        </td>
                        <td><?php echo esc_html($mealsdb_em_row['client_type']); ?></td>
                        <td><?php echo esc_html($mealsdb_em_row['customer_group']); ?></td>
                        <td><?php echo $mealsdb_em_row['last_delivery'] !== '' ? esc_html($mealsdb_em_row['last_delivery']) : esc_html__('None recorded', 'meals-db'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
