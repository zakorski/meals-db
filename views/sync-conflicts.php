<?php
/**
 * A/B sync conflict view (directive K21 ITEM 9).
 *
 * One card per WordPress account with conflicts: WordPress and every active
 * linked MealsDB client side by side. Values are read live and shown RAW.
 * Choices are collected per card and applied in one request per account by
 * assets/js/sync-conflicts.js (through MealsDBConfirm, focus on Cancel).
 *
 * This stage enables "Leave both" (dismiss) and its undo only. The Keep
 * choices and the Use-all shortcuts render disabled until ITEM 10 ships the
 * resolution writes — they are shown so the operator sees the final layout.
 */
defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

$mealsdb_field  = isset($_GET['conflict_field']) ? sanitize_key(wp_unslash((string) $_GET['conflict_field'])) : '';
$mealsdb_status = isset($_GET['conflict_status']) ? sanitize_key(wp_unslash((string) $_GET['conflict_status'])) : 'open';
$mealsdb_paged  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
$mealsdb_status = $mealsdb_status === 'dismissed' ? 'dismissed' : 'open';

$mealsdb_data = MealsDB_Sync_Conflict_View::page($mealsdb_field, $mealsdb_status, $mealsdb_paged);

$mealsdb_base_url = admin_url('admin.php?page=mealsdb-clients&tab=sync&view=conflicts');
$mealsdb_last_scan = class_exists('MealsDB_Job_Logger') ? MealsDB_Job_Logger::last_success('sync_reconcile_detect') : null;

// Returns ESCAPED HTML: the raw value, or a muted "(blank)" marker so an
// empty side is never mistaken for a rendering gap.
$mealsdb_display = static function (string $value): string {
    if (trim($value) === '') {
        return '<span class="mealsdb-conflict-blank">' . esc_html__('(blank)', 'meals-db') . '</span>';
    }
    return esc_html($value);
};
$mealsdb_when = static function (?string $utc): string {
    if ($utc === null || $utc === '') {
        return __('never', 'meals-db');
    }
    $ts = strtotime($utc . ' UTC');
    return $ts ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts) : $utc;
};
$mealsdb_state_labels = [
    MealsDB_Sync_Reconciler::O_EQUAL    => __('Match', 'meals-db'),
    MealsDB_Sync_Reconciler::O_BLANK    => __('Both blank', 'meals-db'),
    MealsDB_Sync_Reconciler::O_FILL     => __('One side blank: will be filled', 'meals-db'),
    MealsDB_Sync_Reconciler::O_CONFLICT => __('Differs (no open conflict yet)', 'meals-db'),
];

$mealsdb_island = [
    'ajaxUrl'  => admin_url('admin-ajax.php'),
    'nonce'    => wp_create_nonce('mealsdb_sync_conflicts'),
    'messages' => [
        'nothingChosen' => __('Choose an action for at least one field first.', 'meals-db'),
        'confirmTitle'  => __('Apply choices for this account?', 'meals-db'),
        'confirmIntro'  => __('These choices will be applied:', 'meals-db'),
        'confirmLabel'  => __('Apply', 'meals-db'),
        'cancelLabel'   => __('Cancel', 'meals-db'),
        'dismiss'       => __('Leave both', 'meals-db'),
        'undismiss'     => __('Undo dismissal', 'meals-db'),
        'applying'      => __('Applying…', 'meals-db'),
        'failed'        => __('The choices could not be applied.', 'meals-db'),
        'partial'       => __('Some choices were not applied:', 'meals-db'),
        'done'          => __('Applied. Reload to see the updated queue.', 'meals-db'),
    ],
];
?>
<div class="mealsdb-sync-conflicts">
    <h2><?php esc_html_e('Sync Conflicts', 'meals-db'); ?></h2>

    <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=mealsdb-clients&tab=sync')); ?>">&larr; <?php esc_html_e('Back to WooCommerce Sync', 'meals-db'); ?></a>
    </p>

    <p class="description">
        <?php esc_html_e('Each card is one WordPress account and its linked Meals DB client(s). A field is in conflict when two non-blank values differ; the sync writes nothing to that field until it is resolved here. Values are shown exactly as stored.', 'meals-db'); ?>
        <br />
        <?php echo esc_html(sprintf(
            /* translators: %s: date and time of the last nightly conflict scan */
            __('Last scan: %s. The queue is refreshed by the nightly sync.', 'meals-db'),
            $mealsdb_when($mealsdb_last_scan)
        )); ?>
    </p>

    <div class="notice notice-info inline">
        <p><?php esc_html_e('Choosing which value to keep is not switched on yet. You can review every conflict now and use "Leave both" for differences that are expected (for example, a client in two programs).', 'meals-db'); ?></p>
    </div>

    <?php if (MealsDB_Shadow_Mode::is_enabled()) : ?>
        <div class="notice notice-warning inline">
            <p><?php esc_html_e('Shadow mode is on: writes to WordPress are suppressed, so any choice that would change a WordPress value is disabled.', 'meals-db'); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($mealsdb_data['error'] !== null) : ?>
        <div class="notice notice-error inline"><p><?php echo esc_html($mealsdb_data['error']); ?></p></div>
    <?php else : ?>

    <form method="get" class="mealsdb-conflict-filters">
        <input type="hidden" name="page" value="mealsdb-clients" />
        <input type="hidden" name="tab" value="sync" />
        <input type="hidden" name="view" value="conflicts" />
        <label>
            <?php esc_html_e('Show', 'meals-db'); ?>
            <select name="conflict_status">
                <option value="open" <?php selected($mealsdb_status, 'open'); ?>>
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of accounts */
                        __('Open (%d accounts)', 'meals-db'),
                        $mealsdb_data['status_counts']['open'] ?? 0
                    )); ?>
                </option>
                <option value="dismissed" <?php selected($mealsdb_status, 'dismissed'); ?>>
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of accounts */
                        __('Dismissed (%d accounts)', 'meals-db'),
                        $mealsdb_data['status_counts']['dismissed'] ?? 0
                    )); ?>
                </option>
            </select>
        </label>
        <label>
            <?php esc_html_e('Field', 'meals-db'); ?>
            <select name="conflict_field">
                <option value=""><?php esc_html_e('All fields', 'meals-db'); ?></option>
                <?php foreach (MealsDB_Sync_Registry::two_way_columns() as $mealsdb_col) :
                    $mealsdb_n = (int) ($mealsdb_data['field_counts'][$mealsdb_col] ?? 0);
                    if ($mealsdb_n === 0 && $mealsdb_col !== $mealsdb_field) {
                        continue;
                    } ?>
                    <option value="<?php echo esc_attr($mealsdb_col); ?>" <?php selected($mealsdb_field, $mealsdb_col); ?>>
                        <?php echo esc_html(MealsDB_Sync_Registry::label($mealsdb_col) . ' (' . $mealsdb_n . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="button"><?php esc_html_e('Filter', 'meals-db'); ?></button>
    </form>

    <?php if ($mealsdb_data['cards'] === []) : ?>
        <div class="notice notice-success inline">
            <p><?php esc_html_e('No conflicts match this filter.', 'meals-db'); ?></p>
        </div>
    <?php endif; ?>

    <?php foreach ($mealsdb_data['cards'] as $mealsdb_card) :
        $mealsdb_client_ids = array_keys($mealsdb_card['clients']); ?>
        <div class="mealsdb-conflict-card" data-wp-user-id="<?php echo esc_attr((string) $mealsdb_card['wp_user_id']); ?>">
            <div class="mealsdb-conflict-card__head">
                <strong>
                    <?php if ($mealsdb_card['user_found']) : ?>
                        <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . (int) $mealsdb_card['wp_user_id'])); ?>">
                            <?php echo esc_html(sprintf(
                                /* translators: 1: WordPress display name, 2: WordPress user ID */
                                __('WordPress: %1$s (user #%2$d)', 'meals-db'),
                                $mealsdb_card['user_label'] !== '' ? $mealsdb_card['user_label'] : __('(no name)', 'meals-db'),
                                (int) $mealsdb_card['wp_user_id']
                            )); ?>
                        </a>
                    <?php else : ?>
                        <?php echo esc_html(sprintf(
                            /* translators: %d: WordPress user ID */
                            __('WordPress user #%d no longer exists', 'meals-db'),
                            (int) $mealsdb_card['wp_user_id']
                        )); ?>
                    <?php endif; ?>
                </strong>
                <?php foreach ($mealsdb_card['clients'] as $mealsdb_cid => $mealsdb_client) : ?>
                    <span class="mealsdb-conflict-card__client">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mealsdb-clients&tab=list&action=edit&client_id=' . (int) $mealsdb_cid)); ?>">
                            <?php echo esc_html(sprintf(
                                /* translators: 1: client ID, 2: client name, 3: client type */
                                __('Client #%1$d: %2$s (%3$s)', 'meals-db'),
                                (int) $mealsdb_cid,
                                $mealsdb_client['name'],
                                $mealsdb_client['client_type']
                            )); ?>
                        </a>
                    </span>
                <?php endforeach; ?>
            </div>

            <table class="widefat striped mealsdb-conflict-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Field', 'meals-db'); ?></th>
                        <th scope="col"><?php esc_html_e('WordPress', 'meals-db'); ?></th>
                        <?php foreach ($mealsdb_client_ids as $mealsdb_cid) : ?>
                            <th scope="col"><?php echo esc_html(sprintf(__('Client #%d', 'meals-db'), (int) $mealsdb_cid)); ?></th>
                        <?php endforeach; ?>
                        <th scope="col"><?php esc_html_e('Choice', 'meals-db'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($mealsdb_card['conflicts'] as $mealsdb_row) :
                    $mealsdb_c = $mealsdb_row['conflict'];
                    $mealsdb_name = 'mealsdb-choice-' . (int) $mealsdb_c['id']; ?>
                    <tr class="mealsdb-conflict-row<?php echo $mealsdb_c['stale'] ? ' is-stale' : ''; ?>"
                        data-conflict-id="<?php echo esc_attr((string) $mealsdb_c['id']); ?>"
                        data-values-hash="<?php echo esc_attr($mealsdb_c['hash']); ?>"
                        data-field-label="<?php echo esc_attr($mealsdb_row['label']); ?>">
                        <th scope="row">
                            <?php echo esc_html($mealsdb_row['label']); ?>
                            <?php if ($mealsdb_c['status'] === 'dismissed') : ?>
                                <span class="mealsdb-conflict-badge"><?php esc_html_e('Dismissed', 'meals-db'); ?></span>
                            <?php endif; ?>
                            <br /><small><?php echo esc_html(sprintf(
                                /* translators: %s: date first seen */
                                __('since %s', 'meals-db'),
                                $mealsdb_when($mealsdb_c['first_seen_at'])
                            )); ?></small>
                        </th>
                        <td class="mealsdb-conflict-value"><?php echo $mealsdb_display($mealsdb_row['wp']); ?></td>
                        <?php foreach ($mealsdb_client_ids as $mealsdb_cid) : ?>
                            <td class="mealsdb-conflict-value"><?php echo $mealsdb_display((string) ($mealsdb_row['meals'][$mealsdb_cid] ?? '')); ?></td>
                        <?php endforeach; ?>
                        <td class="mealsdb-conflict-choice">
                            <?php if ($mealsdb_c['stale']) : ?>
                                <em><?php esc_html_e('Changed since the last scan. It will update on the next nightly run.', 'meals-db'); ?></em>
                            <?php else : ?>
                                <label><input type="radio" name="<?php echo esc_attr($mealsdb_name); ?>" value="" checked="checked" /> <?php esc_html_e('Decide later', 'meals-db'); ?></label><br />
                                <label class="is-disabled" title="<?php esc_attr_e('Not switched on yet', 'meals-db'); ?>"><input type="radio" name="<?php echo esc_attr($mealsdb_name); ?>" value="keep:wp" disabled="disabled" /> <?php esc_html_e('Keep WordPress', 'meals-db'); ?></label><br />
                                <?php foreach ($mealsdb_client_ids as $mealsdb_cid) : ?>
                                    <label class="is-disabled" title="<?php esc_attr_e('Not switched on yet', 'meals-db'); ?>"><input type="radio" name="<?php echo esc_attr($mealsdb_name); ?>" value="<?php echo esc_attr('keep:client:' . (int) $mealsdb_cid); ?>" disabled="disabled" /> <?php echo esc_html(sprintf(__('Keep client #%d', 'meals-db'), (int) $mealsdb_cid)); ?></label><br />
                                <?php endforeach; ?>
                                <?php if ($mealsdb_c['status'] === 'dismissed') : ?>
                                    <label><input type="radio" name="<?php echo esc_attr($mealsdb_name); ?>" value="undismiss" /> <?php esc_html_e('Undo dismissal', 'meals-db'); ?></label>
                                <?php else : ?>
                                    <label><input type="radio" name="<?php echo esc_attr($mealsdb_name); ?>" value="dismiss" /> <?php esc_html_e('Leave both', 'meals-db'); ?></label>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($mealsdb_card['others'] !== []) : ?>
                <details class="mealsdb-conflict-others">
                    <summary><?php echo esc_html(sprintf(
                        /* translators: %d: number of fields */
                        __('%d other fields', 'meals-db'),
                        count($mealsdb_card['others'])
                    )); ?></summary>
                    <table class="widefat striped mealsdb-conflict-table">
                        <tbody>
                        <?php foreach ($mealsdb_card['others'] as $mealsdb_row) : ?>
                            <tr>
                                <th scope="row"><?php echo esc_html($mealsdb_row['label']); ?></th>
                                <td class="mealsdb-conflict-value"><?php echo $mealsdb_display($mealsdb_row['wp']); ?></td>
                                <?php foreach ($mealsdb_client_ids as $mealsdb_cid) : ?>
                                    <td class="mealsdb-conflict-value"><?php echo $mealsdb_display((string) ($mealsdb_row['meals'][$mealsdb_cid] ?? '')); ?></td>
                                <?php endforeach; ?>
                                <td><?php echo esc_html($mealsdb_state_labels[$mealsdb_row['state']] ?? $mealsdb_row['state']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($mealsdb_card['derived'] as $mealsdb_row) : ?>
                            <tr class="mealsdb-conflict-derived">
                                <th scope="row"><?php echo esc_html($mealsdb_row['label']); ?></th>
                                <td>&mdash;</td>
                                <?php foreach ($mealsdb_client_ids as $mealsdb_cid) : ?>
                                    <td class="mealsdb-conflict-value"><?php echo $mealsdb_display((string) ($mealsdb_row['meals'][$mealsdb_cid] ?? '')); ?></td>
                                <?php endforeach; ?>
                                <td><?php echo esc_html(sprintf(
                                    /* translators: %s: source field label */
                                    __('Read-only: follows %s', 'meals-db'),
                                    $mealsdb_row['source']
                                )); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>

            <div class="mealsdb-conflict-card__actions">
                <button type="button" class="button" disabled="disabled" title="<?php esc_attr_e('Not switched on yet', 'meals-db'); ?>"><?php esc_html_e('Use all WordPress', 'meals-db'); ?></button>
                <?php if (count($mealsdb_client_ids) === 1) : ?>
                    <button type="button" class="button" disabled="disabled" title="<?php esc_attr_e('Not switched on yet', 'meals-db'); ?>"><?php esc_html_e('Use all Meals DB', 'meals-db'); ?></button>
                <?php endif; ?>
                <button type="button" class="button button-primary mealsdb-conflict-apply"><?php esc_html_e('Apply choices for this account', 'meals-db'); ?></button>
                <span class="mealsdb-conflict-status" role="status" aria-live="polite"></span>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($mealsdb_data['pages'] > 1) :
        $mealsdb_page_args = ['conflict_status' => $mealsdb_status];
        if ($mealsdb_field !== '') {
            $mealsdb_page_args['conflict_field'] = $mealsdb_field;
        } ?>
        <p class="mealsdb-conflict-pages">
            <?php echo esc_html(sprintf(
                /* translators: 1: current page, 2: total pages, 3: total accounts */
                __('Page %1$d of %2$d (%3$d accounts)', 'meals-db'),
                $mealsdb_data['page'],
                $mealsdb_data['pages'],
                $mealsdb_data['total_accounts']
            )); ?>
            <?php if ($mealsdb_data['page'] > 1) : ?>
                <a class="button" href="<?php echo esc_url(add_query_arg($mealsdb_page_args + ['paged' => $mealsdb_data['page'] - 1], $mealsdb_base_url)); ?>"><?php esc_html_e('Previous', 'meals-db'); ?></a>
            <?php endif; ?>
            <?php if ($mealsdb_data['page'] < $mealsdb_data['pages']) : ?>
                <a class="button" href="<?php echo esc_url(add_query_arg($mealsdb_page_args + ['paged' => $mealsdb_data['page'] + 1], $mealsdb_base_url)); ?>"><?php esc_html_e('Next', 'meals-db'); ?></a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php endif; ?>
</div>
<script type="application/json" id="mealsdb-sync-conflicts-data"><?php echo wp_json_encode($mealsdb_island, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
