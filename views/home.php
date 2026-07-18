<?php
/**
 * Home — the plugin's landing page (spec 2026-07-16 §1–2, PR 4). A cheap,
 * server-rendered "today" overview: quick actions, an alerts strip
 * (failed/degraded trunk events in the last 24h + the operator's own
 * unfinished client drafts), today's delivery zones with prefilled batch
 * links, and the shared tasks widget. Deliberately no JS and no expensive
 * queries — the DB compare is never auto-run here.
 */

defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

global $wpdb;

// ---------------------------------------------------------------------
// Quick actions (unchanged from the PR 3 shell).
// ---------------------------------------------------------------------
// Packing Slips is manage_options — used to gate both the quick action
// and the per-zone batch links below (no 403-dangling links for
// baseline-capability users).
$mealsdb_can_slips = current_user_can('manage_options');

$mealsdb_home_actions = [
    [admin_url('admin.php?page=mealsdb-clients&tab=add'), __('New Client', 'meals-db')],
    [admin_url('admin.php?page=mealsdb_quick_order'), __('Quick Order', 'meals-db')],
];
if ($mealsdb_can_slips) {
    $mealsdb_home_actions[] = [admin_url('admin.php?page=mealsdb-packing-slips'), __("Today's Slips", 'meals-db')];
}
$mealsdb_home_actions[] = [admin_url('admin.php?page=mealsdb-tasks'), __('Tasks', 'meals-db')];
$mealsdb_home_actions[] = [admin_url('admin.php?page=mealsdb-clients'), __('Clients', 'meals-db')];

// ---------------------------------------------------------------------
// Alerts strip.
// ---------------------------------------------------------------------
$mealsdb_home_alerts = [];

// Failed/degraded trunk events, last 24h. occurred_at is written with
// gmdate() (UTC) so the window is computed in UTC too. The Event Log
// page is manage_options — suppress the alert entirely below that tier
// rather than dangling a link into a 403.
if (current_user_can('manage_options') && class_exists('MealsDB_Event_Log')) {
    $mealsdb_event_table = MealsDB_DB::get_table_name(MealsDB_Tables::EVENT_LOG);
    $mealsdb_event_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$mealsdb_event_table}`
          WHERE outcome IN ('failed','degraded') AND occurred_at >= %s",
        gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
    ));
    if ($mealsdb_event_count > 0) {
        $mealsdb_home_alerts[] = [
            'url'   => admin_url('admin.php?page=mealsdb_event_log'),
            'label' => sprintf(
                /* translators: %d: failed/degraded operational events in the last 24 hours */
                _n('%d failed or degraded event in the last 24h', '%d failed or degraded events in the last 24h', $mealsdb_event_count, 'meals-db'),
                $mealsdb_event_count
            ),
        ];
    }
}

// The operator's own unfinished client drafts — owner-scoped, matching
// the drafts list and the Add-tab resume panel.
$mealsdb_drafts_table = MealsDB_DB::get_table_name(MealsDB_Tables::DRAFTS);
$mealsdb_draft_count  = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM `{$mealsdb_drafts_table}` WHERE created_by = %d",
    get_current_user_id()
));
if ($mealsdb_draft_count > 0) {
    $mealsdb_home_alerts[] = [
        'url'   => admin_url('admin.php?page=mealsdb-clients&tab=add'),
        'label' => sprintf(
            /* translators: %d: the operator's saved client-form drafts */
            _n('%d unfinished client form', '%d unfinished client forms', $mealsdb_draft_count, 'meals-db'),
            $mealsdb_draft_count
        ),
    ];
}

// ---------------------------------------------------------------------
// Today's deliveries (site timezone, like the tasks widget).
// ---------------------------------------------------------------------
try {
    $mealsdb_home_now  = new DateTimeImmutable('now', wp_timezone());
    $mealsdb_today_name = $mealsdb_home_now->format('l');
    $mealsdb_today_date = $mealsdb_home_now->format('Y-m-d');
} catch (Throwable $e) {
    $mealsdb_today_name = gmdate('l');
    $mealsdb_today_date = gmdate('Y-m-d');
}
$mealsdb_today_zones = class_exists('MealsDB_Zone_Day')
    ? MealsDB_Zone_Day::zones_for_day(MealsDB_Zone_Day::schedule(), $mealsdb_today_name)
    : [];
?>
<div class="wrap">
    <h1><?php esc_html_e('Meals DB', 'meals-db'); ?></h1>

    <p class="mealsdb-home-actions" style="margin-top:16px;">
        <?php foreach ($mealsdb_home_actions as $mealsdb_home_action) : ?>
            <a class="button button-hero" style="margin:0 8px 8px 0;" href="<?php echo esc_url($mealsdb_home_action[0]); ?>"><?php echo esc_html($mealsdb_home_action[1]); ?></a>
        <?php endforeach; ?>
    </p>

    <?php if (!empty($mealsdb_home_alerts)) : ?>
        <div class="mealsdb-home-alerts" style="margin:16px 0; padding:12px 16px; background:#fff; border:1px solid #ccd0d4; border-left:4px solid #d63638;">
            <h3 style="margin:0 0 8px;"><?php esc_html_e('Needs attention', 'meals-db'); ?></h3>
            <ul style="margin:0; list-style:disc; padding-left:20px;">
                <?php foreach ($mealsdb_home_alerts as $mealsdb_home_alert) : ?>
                    <li><a href="<?php echo esc_url($mealsdb_home_alert['url']); ?>"><?php echo esc_html($mealsdb_home_alert['label']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="mealsdb-home-deliveries" style="margin:16px 0; padding:12px 16px; background:#fff; border:1px solid #ccd0d4; border-left:4px solid #00a32a;">
        <h3 style="margin:0 0 8px;">
            <?php echo esc_html(sprintf(
                /* translators: %s: weekday name */
                __("Today's Deliveries (%s)", 'meals-db'),
                $mealsdb_today_name
            )); ?>
        </h3>
        <?php if (empty($mealsdb_today_zones)) : ?>
            <p style="margin:0;"><em><?php esc_html_e('No zones deliver today.', 'meals-db'); ?></em></p>
        <?php else : ?>
            <ul style="margin:0; list-style:disc; padding-left:20px;">
                <?php foreach ($mealsdb_today_zones as $mealsdb_zone_name => $mealsdb_zone_config) : ?>
                    <li>
                        <strong><?php echo esc_html($mealsdb_zone_name); ?></strong>
                        <?php if ($mealsdb_zone_config['label'] !== '') : ?>
                            &mdash; <?php echo esc_html($mealsdb_zone_config['label']); ?>
                        <?php endif; ?>
                        <?php if ($mealsdb_can_slips) : ?>
                            <a style="margin-left:8px;" href="<?php echo esc_url(admin_url('admin.php?page=mealsdb-packing-slips&zone=' . rawurlencode($mealsdb_zone_name) . '&date=' . $mealsdb_today_date)); ?>">
                                <?php esc_html_e('Generate batch', 'meals-db'); ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php include MealsDB_Plugin::path('views/partials/dashboard-tasks-widget.php'); ?>
</div>
