<?php
/**
 * Updates and maintenance screen.
 */

defined('ABSPATH') || exit;

MealsDB_Permissions::enforce();

$repo_path = dirname(MEALS_DB_PLUGIN_FILE);
?>
<div id="mealsdb-updates" class="mealsdb-updates">
    <p class="description">
        <?php echo esc_html__('Use this screen to check for plugin updates from the configured Git repository and to run database maintenance that adds any new columns or indexes introduced in recent releases.', 'meals-db'); ?>
    </p>

    <table class="form-table mealsdb-updates-meta">
        <tbody>
            <tr>
                <th scope="row"><?php echo esc_html__('Plugin Directory', 'meals-db'); ?></th>
                <td><code><?php echo esc_html($repo_path); ?></code></td>
            </tr>
        </tbody>
    </table>

    <div class="mealsdb-update-actions">
        <form method="post" class="mealsdb-update-schema">
            <?php wp_nonce_field('mealsdb_update_schema', 'mealsdb_update_schema_nonce'); ?>
            <input type="hidden" name="mealsdb_action" value="update_schema">
            <button class="button button-primary"><?php echo esc_html__('Update Database Schema', 'meals-db'); ?></button>
        </form>
        <button type="button" class="button" id="mealsdb-fetch-products">
            <?php echo esc_html__('Fetch Products', 'meals-db'); ?>
        </button>
    </div>

    <div class="mealsdb-force-rebuild">
        <h2><?php echo esc_html__('Force Rebuild (External Database Only)', 'meals-db'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Drops and recreates all Meals DB external tables using the canonical schema. This action is destructive and does not affect WordPress tables.', 'meals-db'); ?>
        </p>
        <form method="post" class="mealsdb-force-rebuild-form">
            <?php wp_nonce_field('mealsdb_force_rebuild', 'mealsdb_force_rebuild_nonce'); ?>
            <input type="hidden" name="mealsdb_action" value="force_rebuild">
            <p>
                <label for="mealsdb_rebuild_confirm">
                    <?php echo esc_html__('Type REBUILD to confirm:', 'meals-db'); ?>
                </label>
                <input type="text" id="mealsdb_rebuild_confirm" name="mealsdb_rebuild_confirm" pattern="REBUILD" required placeholder="REBUILD" autocomplete="off" />
            </p>
            <p>
                <button class="button button-secondary" type="submit"><?php echo esc_html__('Force Rebuild External Schema', 'meals-db'); ?></button>
            </p>
        </form>
    </div>


    <div class="mealsdb-db-sync">
        <h2><?php echo esc_html__('Complete DB Sync', 'meals-db'); ?></h2>
        <p class="description">
            <?php echo esc_html__('Scans existing WordPress users tagged as SDNB, Veterans, or Extra Mural (via the customer_group usermeta) and creates corresponding records in the external Meals DB — including encrypted client data, delivery initials, and default billing rates. Skips users that already have a meals_clients record.', 'meals-db'); ?>
        </p>
        <div class="mealsdb-db-sync-actions" style="margin-top:12px;">
            <label style="margin-right:12px;">
                <input type="checkbox" id="mealsdb-sync-dry-run" checked>
                <?php echo esc_html__('Dry run (preview only)', 'meals-db'); ?>
            </label>
            <button type="button" class="button button-primary" id="mealsdb-sync-start">
                <?php echo esc_html__('Start Sync', 'meals-db'); ?>
            </button>
            <button type="button" class="button" id="mealsdb-sync-reset" style="display:none;">
                <?php echo esc_html__('Reset', 'meals-db'); ?>
            </button>
        </div>
        <div id="mealsdb-sync-progress" style="display:none; margin-top:12px;">
            <div class="mealsdb-sync-phases"></div>
            <div id="mealsdb-sync-current" style="margin-top:8px; font-size:13px; color:#666;"></div>
        </div>
        <div id="mealsdb-sync-status" class="notice" style="display:none; margin-top:12px;"></div>
        <pre id="mealsdb-sync-log" style="display:none; max-height:200px; overflow:auto; background:#f5f5f5; padding:8px; margin-top:8px; font-size:12px;"></pre>
    </div>

    <div class="mealsdb-backfill-allowances">
        <h2><?php echo esc_html__('Backfill Allowance Data', 'meals-db'); ?></h2>
        <div style="padding: 0 0 12px;">
            <p class="description">
                <?php echo esc_html__('Reads mains, sides, and service from legacy WordPress user meta and writes them to allowance_mains, allowance_sides, and requisition_period on the corresponding meals_clients record.', 'meals-db'); ?>
            </p>
            <p style="margin-top: 12px;">
                <button type="button" class="button" id="backfill-dry-run">
                    <?php echo esc_html__('Dry Run', 'meals-db'); ?>
                </button>
                <button type="button" class="button button-primary" id="backfill-run" disabled>
                    <?php echo esc_html__('Run Backfill', 'meals-db'); ?>
                </button>
            </p>
            <div id="backfill-result" style="margin-top: 10px;"></div>
        </div>
    </div>

    <div class="mealsdb-backfill-addresses">
        <h2><?php echo esc_html__('Backfill Addresses & Rates', 'meals-db'); ?></h2>
        <div style="padding: 0 0 12px;">
            <p class="description">
                <?php echo esc_html__('Fixes remaining migration data gaps: moves zone data from apartment_number to delivery_area_name, writes full address strings to street_name/delivery_street_name from WordPress usermeta, and links default_rate_id from meals_client_rates.', 'meals-db'); ?>
            </p>
            <p style="margin-top: 12px;">
                <button type="button" class="button" id="backfill-addr-dry-run">
                    <?php echo esc_html__('Dry Run', 'meals-db'); ?>
                </button>
                <button type="button" class="button button-primary" id="backfill-addr-run" disabled>
                    <?php echo esc_html__('Run Backfill', 'meals-db'); ?>
                </button>
            </p>
            <div id="backfill-addr-result" style="margin-top: 10px;"></div>
        </div>
    </div>

    <div class="mealsdb-backfill-allocations">
        <h2><?php echo esc_html__( 'Allocation Engine Backfill', 'meals-db' ); ?></h2>
        <div style="padding: 0 0 12px;">
            <p class="description">
                <?php echo esc_html__( 'Populate the allocation tables from historical WooCommerce orders. Run this once after enabling the allocation engine.', 'meals-db' ); ?>
            </p>
            <div class="mealsdb-form-row" style="margin-top: 12px;">
                <label for="mealsdb_backfill_start"><?php echo esc_html__( 'Start Month', 'meals-db' ); ?></label>
                <input type="month" id="mealsdb_backfill_start" value="<?php echo esc_attr( gmdate( 'Y-m', strtotime( '-6 months' ) ) ); ?>" />
            </div>
            <div class="mealsdb-form-row" style="margin-top: 8px;">
                <label for="mealsdb_backfill_end"><?php echo esc_html__( 'End Month', 'meals-db' ); ?></label>
                <input type="month" id="mealsdb_backfill_end" value="<?php echo esc_attr( gmdate( 'Y-m' ) ); ?>" />
            </div>
            <p style="margin-top: 12px;">
                <button type="button" class="button" id="mealsdb_backfill_allocations_dry">
                    <?php echo esc_html__( 'Dry Run', 'meals-db' ); ?>
                </button>
                <button type="button" class="button button-primary" id="mealsdb_backfill_allocations_run" disabled>
                    <?php echo esc_html__( 'Run Backfill', 'meals-db' ); ?>
                </button>
                <span id="mealsdb_backfill_allocations_status"></span>
            </p>
            <div id="backfill-alloc-result" style="margin-top: 10px;"></div>
        </div>
    </div>

    <div id="mealsdb-updates-status" class="notice notice-info" style="display:none;"></div>
    <pre id="mealsdb-updates-log" class="mealsdb-updates-log" style="display:none;"></pre>
    <hr style="margin:24px 0;">
        <h2><?php echo esc_html__( 'Backfill Delivery Day', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'Populate the delivery_day field on client records based on their zone assignment and the schedule above. Only updates clients where delivery_day is currently empty.', 'meals-db' ); ?>
        </p>
        <p>
            <button type="button" class="button" id="mealsdb-backfill-delivery-day">
                <?php echo esc_html__( 'Populate delivery_day from Zone Schedule', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-backfill-result" style="margin-left:12px;"></span>
        </p>

        <h2><?php echo esc_html__( 'Backfill Next-Order / Next-Delivery Dates', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( "Populate next_order_date / next_delivery_date on each client using their last-order/last-delivery user meta plus the configured ordering / delivery frequencies. Only fills empty columns so existing values are preserved. Safe to re-run.", 'meals-db' ); ?>
        </p>
        <p>
            <button type="button" class="button" id="mealsdb-backfill-next-dates">
                <?php echo esc_html__( 'Backfill Next Dates', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-backfill-next-dates-result" style="margin-left:12px;"></span>
        </p>

        <h2><?php echo esc_html__( 'Recalculate Allocations', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( "Rebuild every dirty client-month using the delivery-month allowance fill. Invoices trigger this automatically scoped to their own clients/month; this manual run processes all pending dirty entries. Safe to re-run.", 'meals-db' ); ?>
        </p>
        <p>
            <button type="button" class="button" id="mealsdb-recalculate-allocations">
                <?php echo esc_html__( 'Recalculate Allocations', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-recalculate-allocations-result" style="margin-left:12px;"></span>
        </p>

        <h2><?php echo esc_html__( 'Private Customer Backfill', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'Promote existing WC users who placed active-status orders in the lookback window into meals_clients as Private customers. Preview first — the preview is read-only and shows exactly which users would be created.', 'meals-db' ); ?>
        </p>
        <p>
            <label for="mealsdb-private-backfill-lookback"><?php echo esc_html__( 'Lookback (months):', 'meals-db' ); ?></label>
            <input type="number" id="mealsdb-private-backfill-lookback" class="small-text" min="1" max="120" value="24" />
            <button type="button" class="button" id="mealsdb-private-backfill-preview">
                <?php echo esc_html__( 'Preview', 'meals-db' ); ?>
            </button>
            <button type="button" class="button button-primary" id="mealsdb-private-backfill-run" disabled>
                <?php echo esc_html__( 'Run Backfill', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-private-backfill-result" style="margin-left:12px;"></span>
        </p>
        <div id="mealsdb-private-backfill-rows" class="mealsdb-private-backfill-rows" style="display:none; margin-top:8px; max-height:240px; overflow:auto; border:1px solid #ccd0d4; padding:8px; background:#fff;"></div>

        <h2><?php echo esc_html__( 'Private Customer Deactivation Sweep', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'One-time cleanup: deactivates existing Private meals_clients records whose WC user has no active-status orders in the lookback window. Sets active=0; does not delete.', 'meals-db' ); ?>
        </p>
        <p>
            <label for="mealsdb-private-deact-lookback"><?php echo esc_html__( 'Lookback (months):', 'meals-db' ); ?></label>
            <input type="number" id="mealsdb-private-deact-lookback" class="small-text" min="1" max="120" value="24" />
            <button type="button" class="button" id="mealsdb-private-deact-preview">
                <?php echo esc_html__( 'Preview', 'meals-db' ); ?>
            </button>
            <button type="button" class="button" id="mealsdb-private-deact-run" disabled>
                <?php echo esc_html__( 'Deactivate Stale', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-private-deact-result" style="margin-left:12px;"></span>
        </p>
        <div id="mealsdb-private-deact-rows" class="mealsdb-private-deact-rows" style="display:none; margin-top:8px; max-height:240px; overflow:auto; border:1px solid #ccd0d4; padding:8px; background:#fff;"></div>

        <h2><?php echo esc_html__( 'Enrich Private Customer Skeletons', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'Refill blank columns (address, zone, service / ordering meta, notes) on existing Private meals_clients rows from WordPress usermeta and the user\'s most recent qualifying WC order. Admin-set values are never overwritten. Dry Run reports what would change without writing.', 'meals-db' ); ?>
        </p>
        <p>
            <button type="button" class="button" id="mealsdb-private-enrich-dry">
                <?php echo esc_html__( 'Dry Run', 'meals-db' ); ?>
            </button>
            <button type="button" class="button button-primary" id="mealsdb-private-enrich-run">
                <?php echo esc_html__( 'Enrich', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-private-enrich-result" style="margin-left:12px;"></span>
        </p>

        <h2><?php echo esc_html__( 'Sync Product Display Data', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'Rebuild the cached product display data (name, price, image, categories) used by the Quick Order page. This is done automatically when products are saved, but you can run a full sync here.', 'meals-db' ); ?>
        </p>
        <p>
            <button type="button" class="button" id="mealsdb-sync-products">
                <?php echo esc_html__( 'Sync Products', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-sync-products-result" style="margin-left:12px;"></span>
        </p>

        <h2><?php echo esc_html__( 'Case Count Sync', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'Fills in product case sizes from legacy data and writes them to the products table used by Purchase Orders. Safe to run repeatedly; it only fills missing (default) values and never overwrites, lowers, or deletes existing data.', 'meals-db' ); ?>
        </p>
        <p>
            <button type="button" class="button" id="mealsdb-case-count-sync">
                <?php echo esc_html__( 'Case Count Sync', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-case-count-sync-result" style="margin-left:12px;"></span>
        </p>
</div>

<style>
.mealsdb-sync-phase {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 0;
}
.mealsdb-sync-phase-icon {
    width: 20px;
    text-align: center;
    font-size: 14px;
}
.mealsdb-sync-phase-icon.running { color: #0073aa; }
.mealsdb-sync-phase-icon.done { color: #46b450; font-weight: bold; }
.mealsdb-sync-phase-icon.error { color: #dc3232; font-weight: bold; }
.mealsdb-sync-phase-bar-wrap {
    flex: 1;
    max-width: 300px;
    height: 12px;
    background: #e0e0e0;
    border-radius: 6px;
    overflow: hidden;
}
.mealsdb-sync-phase-bar {
    height: 100%;
    width: 0;
    background: #0073aa;
    border-radius: 6px;
    transition: width 0.3s;
}
.mealsdb-sync-phase-status {
    font-size: 12px;
    color: #666;
}
</style>

<script>
// Shared HTML escape helper used by every IIFE on this page so server
// JSON values flow through one defensive layer before reaching .html().
window.MealsDBUpdates = window.MealsDBUpdates || {};
window.MealsDBUpdates.escHtml = function (value) {
    if (value === null || value === undefined) return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};
window.MealsDBUpdates.intText = function (value) {
    return String(parseInt(value, 10) || 0);
};
</script>

<script>
(function($) {
    'use strict';

    var nonce     = '<?php echo esc_js(wp_create_nonce('mealsdb_db_sync_nonce')); ?>';
    var running   = false;
    var phase     = 0; // 0 = clients, 1 = rates
    var offset    = 0;
    var dryRun    = true;
    var stats     = {};

    var phases = [
        { key: 'clients', label: 'Create Meals Clients' },
        { key: 'rates',   label: 'Create Client Rates' },
    ];

    // Build phase UI
    var $phases = $('.mealsdb-sync-phases');
    $.each(phases, function(i, p) {
        $phases.append(
            '<div class="mealsdb-sync-phase" id="sync-phase-' + i + '">' +
                '<span class="mealsdb-sync-phase-icon">&#9711;</span>' +
                '<span><strong>' + p.label + '</strong></span>' +
                '<div class="mealsdb-sync-phase-bar-wrap"><div class="mealsdb-sync-phase-bar"></div></div>' +
                '<span class="mealsdb-sync-phase-status"></span>' +
            '</div>'
        );
    });

    function setIcon(i, state) {
        var map = { pending: '&#9711;', running: '&#9881;', done: '&#10003;', error: '&#10007;' };
        $('#sync-phase-' + i + ' .mealsdb-sync-phase-icon')
            .html(map[state]).removeClass('pending running done error').addClass(state);
    }

    function setBar(i, pct) {
        $('#sync-phase-' + i + ' .mealsdb-sync-phase-bar').css('width', pct + '%');
    }

    function setStatus(i, text) {
        $('#sync-phase-' + i + ' .mealsdb-sync-phase-status').text(text);
    }

    function logLine(text) {
        var $log = $('#mealsdb-sync-log');
        $log.show().append(text + "\n").scrollTop($log[0].scrollHeight);
    }

    function showNotice(msg, type) {
        $('#mealsdb-sync-status').show()
            .removeClass('notice-info notice-success notice-error')
            .addClass('notice-' + type)
            .empty()
            .append($('<p></p>').text(msg));
    }

    // Start
    $('#mealsdb-sync-start').on('click', function() {
        if (running) return;
        running  = true;
        dryRun   = $('#mealsdb-sync-dry-run').is(':checked');
        phase    = 0;
        offset   = 0;
        stats    = {};

        $(this).prop('disabled', true);
        $('#mealsdb-sync-dry-run').prop('disabled', true);
        $('#mealsdb-sync-reset').hide();
        $('#mealsdb-sync-progress').show();
        $('#mealsdb-sync-status').hide();
        $('#mealsdb-sync-log').empty().hide();

        for (var i = 0; i < phases.length; i++) {
            setIcon(i, 'pending');
            setBar(i, 0);
            setStatus(i, '');
        }

        logLine(dryRun ? 'Starting dry run...' : 'Starting live sync...');
        runPhase();
    });

    function runPhase() {
        if (!running) return;
        setIcon(phase, 'running');

        // Map phase index to migration phase number (4 = clients, 5 = rates)
        var migPhase = phase === 0 ? 4 : 5;

        $.post(ajaxurl, {
            action: 'mealsdb_db_sync_phase',
            nonce:  nonce,
            phase:  migPhase,
            offset: offset,
            dry_run: dryRun ? 1 : 0,
        }, function(resp) {
            if (!resp.success) {
                setIcon(phase, 'error');
                setStatus(phase, resp.data && resp.data.message ? resp.data.message : 'Error');
                showNotice('Sync failed: ' + (resp.data && resp.data.message || 'Unknown error'), 'error');
                finish();
                return;
            }

            var data = resp.data;

            // Accumulate stats
            if (data.stats) {
                if (!stats[phase]) stats[phase] = {};
                $.each(data.stats, function(k, v) {
                    stats[phase][k] = (stats[phase][k] || 0) + v;
                });
            }

            var pct = data.total > 0 ? Math.min(100, Math.round((data.offset / data.total) * 100)) : 100;
            setBar(phase, pct);
            setStatus(phase, pct + '%');

            // Log batch info
            if (data.stats) {
                var parts = [];
                $.each(data.stats, function(k, v) { parts.push(k + '=' + v); });
                logLine(phases[phase].label + ' batch: ' + parts.join(', '));
            }

            if (data.complete) {
                setIcon(phase, 'done');
                setBar(phase, 100);

                // Log phase totals
                if (stats[phase]) {
                    var totals = [];
                    $.each(stats[phase], function(k, v) { totals.push(k + ': ' + v); });
                    logLine(phases[phase].label + ' complete — ' + totals.join(', '));
                }

                if (phase < phases.length - 1) {
                    phase++;
                    offset = 0;
                    runPhase();
                } else {
                    var msg = dryRun
                        ? 'Dry run complete. No data was written. Uncheck "Dry run" and run again to sync.'
                        : 'Sync complete. All government clients and rates have been created.';
                    showNotice(msg, 'success');
                    finish();
                }
            } else {
                offset = data.offset;
                runPhase();
            }
        }).fail(function() {
            setIcon(phase, 'error');
            showNotice('Network error during sync.', 'error');
            finish();
        });
    }

    function finish() {
        running = false;
        $('#mealsdb-sync-start').prop('disabled', false);
        $('#mealsdb-sync-dry-run').prop('disabled', false);
        $('#mealsdb-sync-reset').show();
    }

    // Reset
    $('#mealsdb-sync-reset').on('click', function() {
        $('#mealsdb-sync-progress').hide();
        $('#mealsdb-sync-status').hide();
        $('#mealsdb-sync-log').hide().empty();
        $(this).hide();
        for (var i = 0; i < phases.length; i++) {
            setIcon(i, 'pending');
            setBar(i, 0);
            setStatus(i, '');
        }
    });

})(jQuery);
</script>

<script>
(function($) {
    'use strict';

    var backfillNonce = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';

    function showBackfillResult(msg, type) {
        var cls = type === 'error' ? 'notice-error' : 'notice-success';
        var $wrap = $('<div></div>')
            .attr('class', 'notice ' + cls + ' inline')
            .css('margin', 0)
            .append($('<p></p>').text(msg));
        $('#backfill-result').empty().append($wrap);
    }

    $('#backfill-dry-run').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Running...');
        $('#backfill-result').empty();

        $.post(ajaxurl, {
            action: 'mealsdb_backfill_allowances',
            nonce: backfillNonce,
            dry_run: 1
        }, function(resp) {
            $btn.prop('disabled', false).text('Dry Run');
            if (resp.success) {
                var d = resp.data;
                showBackfillResult(
                    'Dry run complete. Total: ' + d.total + ', Would update: ' + d.updated +
                    ', Skipped (no meta): ' + d.skipped + ', Errors: ' + d.errors,
                    'success'
                );
                $('#backfill-run').prop('disabled', false);
            } else {
                showBackfillResult(resp.data.message || 'Dry run failed.', 'error');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Dry Run');
            showBackfillResult('Request failed.', 'error');
        });
    });

    $('#backfill-run').on('click', function() {
        if (!confirm('This will update allowance_mains, allowance_sides, and requisition_period on all matching meals_clients records. Continue?')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Running...');
        $('#backfill-result').empty();

        $.post(ajaxurl, {
            action: 'mealsdb_backfill_allowances',
            nonce: backfillNonce
        }, function(resp) {
            $btn.prop('disabled', false).text('Run Backfill');
            if (resp.success) {
                var d = resp.data;
                showBackfillResult(
                    'Backfill complete. Total: ' + d.total + ', Updated: ' + d.updated +
                    ', Skipped: ' + d.skipped + ', Errors: ' + d.errors,
                    'success'
                );
            } else {
                showBackfillResult(resp.data.message || 'Backfill failed.', 'error');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Run Backfill');
            showBackfillResult('Request failed.', 'error');
        });
    });

})(jQuery);
</script>

<script>
(function($) {
    'use strict';

    var addrNonce = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';

    function showAddrResult(msg, type) {
        var cls = type === 'error' ? 'notice-error' : 'notice-success';
        var $wrap = $('<div></div>')
            .attr('class', 'notice ' + cls + ' inline')
            .css('margin', 0)
            .append($('<p></p>').text(msg));
        $('#backfill-addr-result').empty().append($wrap);
    }

    $('#backfill-addr-dry-run').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Running...');
        $('#backfill-addr-result').empty();

        $.post(ajaxurl, {
            action: 'mealsdb_backfill_addresses',
            nonce: addrNonce,
            dry_run: 1
        }, function(resp) {
            $btn.prop('disabled', false).text('Dry Run');
            if (resp.success) {
                var d = resp.data;
                showAddrResult(
                    'Dry run complete. Total: ' + d.total +
                    ', Zones fixed: ' + d.zones_fixed +
                    ', Addresses fixed: ' + d.addresses_fixed +
                    ', Rates linked: ' + d.rates_linked +
                    ', Skipped: ' + d.skipped +
                    ', Errors: ' + d.errors,
                    'success'
                );
                $('#backfill-addr-run').prop('disabled', false);
            } else {
                showAddrResult(resp.data.message || 'Dry run failed.', 'error');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Dry Run');
            showAddrResult('Request failed.', 'error');
        });
    });

    $('#backfill-addr-run').on('click', function() {
        if (!confirm('This will update delivery_area_name, street_name, delivery_street_name, apartment_number, delivery_apartment_number, and default_rate_id on matching meals_clients records. Continue?')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Running...');
        $('#backfill-addr-result').empty();

        $.post(ajaxurl, {
            action: 'mealsdb_backfill_addresses',
            nonce: addrNonce
        }, function(resp) {
            $btn.prop('disabled', false).text('Run Backfill');
            if (resp.success) {
                var d = resp.data;
                showAddrResult(
                    'Backfill complete. Total: ' + d.total +
                    ', Zones fixed: ' + d.zones_fixed +
                    ', Addresses fixed: ' + d.addresses_fixed +
                    ', Rates linked: ' + d.rates_linked +
                    ', Skipped: ' + d.skipped +
                    ', Errors: ' + d.errors,
                    'success'
                );
            } else {
                showAddrResult(resp.data.message || 'Backfill failed.', 'error');
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Run Backfill');
            showAddrResult('Request failed.', 'error');
        });
    });

})(jQuery);
</script>

<script>
(function($) {
    'use strict';

    var allocNonce = '<?php echo esc_js(wp_create_nonce('mealsdb_nonce')); ?>';

    function showAllocResult(msg, type) {
        var cls = type === 'error' ? 'notice-error' : 'notice-success';
        var $wrap = $('<div></div>')
            .attr('class', 'notice ' + cls + ' inline')
            .css('margin', 0)
            .append($('<p></p>').text(msg));
        $('#backfill-alloc-result').empty().append($wrap);
    }

    $('#mealsdb_backfill_allocations_dry').on('click', function() {
        var $btn = $(this);
        var startMonth = $('#mealsdb_backfill_start').val();
        var endMonth   = $('#mealsdb_backfill_end').val();

        if (!startMonth || !endMonth) {
            showAllocResult('Please select both start and end months.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Running...');
        $('#backfill-alloc-result').empty();

        $.post(ajaxurl, {
            action: 'mealsdb_backfill_allocation_engine',
            nonce: allocNonce,
            start_month: startMonth,
            end_month: endMonth,
            dry_run: '1'
        }, function(resp) {
            $btn.prop('disabled', false).text('Dry Run');
            if (resp.success && resp.stats) {
                var d = resp.stats;
                showAllocResult(
                    'Dry run complete. Months: ' + d.months_processed +
                    ', Clients: ' + d.clients_processed +
                    ', Orders: ' + d.orders_processed +
                    ', Allocations: ' + d.allocations_created,
                    'success'
                );
                $('#mealsdb_backfill_allocations_run').prop('disabled', false);
            } else {
                showAllocResult(
                    (resp.data && resp.data.message) || resp.message || 'Dry run failed.',
                    'error'
                );
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Dry Run');
            showAllocResult('Request failed.', 'error');
        });
    });

    $('#mealsdb_backfill_allocations_run').on('click', function() {
        if (!confirm('This will populate the allocation tables from historical WooCommerce orders. This cannot be easily undone. Continue?')) {
            return;
        }

        var $btn = $(this);
        var startMonth = $('#mealsdb_backfill_start').val();
        var endMonth   = $('#mealsdb_backfill_end').val();

        $btn.prop('disabled', true).text('Running...');
        $('#backfill-alloc-result').empty();

        $.post(ajaxurl, {
            action: 'mealsdb_backfill_allocation_engine',
            nonce: allocNonce,
            start_month: startMonth,
            end_month: endMonth
        }, function(resp) {
            $btn.prop('disabled', false).text('Run Backfill');
            if (resp.success && resp.stats) {
                var d = resp.stats;
                showAllocResult(
                    'Backfill complete. Months: ' + d.months_processed +
                    ', Clients: ' + d.clients_processed +
                    ', Orders: ' + d.orders_processed +
                    ', Allocations: ' + d.allocations_created,
                    'success'
                );
            } else {
                showAllocResult(
                    (resp.data && resp.data.message) || resp.message || 'Backfill failed.',
                    'error'
                );
            }
        }).fail(function() {
            $btn.prop('disabled', false).text('Run Backfill');
            showAllocResult('Request failed.', 'error');
        });
    });

})(jQuery);
</script>
