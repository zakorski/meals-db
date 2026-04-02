<?php
/**
 * Plugin settings screen — external database credentials and encryption key.
 */

if ( ! current_user_can( 'manage_options' ) ) {
    return;
}

$opts = get_option( 'mealsdb_settings', [] );

$db_host = $opts['db_host'] ?? '';
$db_name = $opts['db_name'] ?? '';
$db_user = $opts['db_user'] ?? '';
$db_pass = $opts['db_pass'] ?? '';
$enc_key = $opts['encryption_key'] ?? '';

$has_env_credentials = getenv( 'MEALS_DB_HOST' ) || ( defined( 'MEALS_DB_KEY' ) );
?>
<div id="mealsdb-settings" class="mealsdb-settings">
    <p class="description">
        <?php echo esc_html__( 'Configure the connection details for the external Meals DB database and the encryption key used to protect client PII.', 'meals-db' ); ?>
    </p>

    <?php if ( $has_env_credentials ) : ?>
        <div class="notice notice-info inline" style="margin:12px 0;">
            <p><?php echo esc_html__( 'Environment variables or wp-config.php constants were detected. Values saved here will take priority over those sources.', 'meals-db' ); ?></p>
        </div>
    <?php endif; ?>

    <form id="mealsdb-settings-form" method="post">
        <h2><?php echo esc_html__( 'External Database Connection', 'meals-db' ); ?></h2>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-db-host"><?php echo esc_html__( 'Database Host', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="mealsdb-db-host" name="db_host" value="<?php echo esc_attr( $db_host ); ?>" class="regular-text" placeholder="127.0.0.1" autocomplete="off" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-db-name"><?php echo esc_html__( 'Database Name', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="mealsdb-db-name" name="db_name" value="<?php echo esc_attr( $db_name ); ?>" class="regular-text" placeholder="mealsdb" autocomplete="off" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-db-user"><?php echo esc_html__( 'Database Username', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="mealsdb-db-user" name="db_user" value="<?php echo esc_attr( $db_user ); ?>" class="regular-text" placeholder="meals_user" autocomplete="off" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-db-pass"><?php echo esc_html__( 'Database Password', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <input type="password" id="mealsdb-db-pass" name="db_pass" value="<?php echo esc_attr( $db_pass ); ?>" class="regular-text" autocomplete="new-password" />
                    </td>
                </tr>
            </tbody>
        </table>

        <h2><?php echo esc_html__( 'Encryption Key', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'AES-256 key used to encrypt client PII in the external database. Once data has been encrypted with a key, changing it will make existing encrypted data unreadable.', 'meals-db' ); ?>
        </p>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-enc-key"><?php echo esc_html__( 'AES-256 Key', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="mealsdb-enc-key" name="encryption_key" value="<?php echo esc_attr( $enc_key ); ?>" class="large-text code" autocomplete="off" placeholder="base64:..." />
                        <p class="description">
                            <?php echo esc_html__( 'Must be a base64-encoded 256-bit key prefixed with "base64:".', 'meals-db' ); ?>
                        </p>
                        <p style="margin-top:8px;">
                            <button type="button" class="button" id="mealsdb-generate-key">
                                <?php echo esc_html__( 'Generate New Key', 'meals-db' ); ?>
                            </button>
                            <span id="mealsdb-key-warning" class="description" style="color:#dc3232; display:none; margin-left:8px;">
                                <?php echo esc_html__( 'Key generated. Save settings to apply. Existing encrypted data will become unreadable if you change the key.', 'meals-db' ); ?>
                            </span>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2><?php echo esc_html__( 'Overage Product IDs', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'WooCommerce product IDs used when creating overage orders. These must match products in your store.', 'meals-db' ); ?>
        </p>
        <?php $overage_ids = get_option( 'mealsdb_overage_product_ids', [] ); ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-overage-mains"><?php echo esc_html__( 'Overage Main Product ID', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="mealsdb-overage-mains" name="overage_mains" value="<?php echo esc_attr( $overage_ids['mains'] ?? 5056 ); ?>" class="small-text" min="0" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-overage-taxable-sides"><?php echo esc_html__( 'Overage Taxable Side Product ID', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="mealsdb-overage-taxable-sides" name="overage_taxable_sides" value="<?php echo esc_attr( $overage_ids['taxable_sides'] ?? 5180 ); ?>" class="small-text" min="0" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-overage-nontax-sides"><?php echo esc_html__( 'Overage Non-Taxable Side Product ID', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="mealsdb-overage-nontax-sides" name="overage_nontax_sides" value="<?php echo esc_attr( $overage_ids['nontax_sides'] ?? 5059 ); ?>" class="small-text" min="0" />
                    </td>
                </tr>
            </tbody>
        </table>

        <h2><?php echo esc_html__( 'Zone Delivery Schedule', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'Maps each delivery zone to a day of the week. Used for zone-based slip generation and to auto-populate the delivery_day field on client records.', 'meals-db' ); ?>
        </p>
        <?php $zone_schedule = get_option( 'mealsdb_zone_delivery_schedule', [] ); ?>
        <table class="form-table" id="mealsdb-zone-schedule-table">
            <thead>
                <tr>
                    <th><?php echo esc_html__( 'Zone', 'meals-db' ); ?></th>
                    <th><?php echo esc_html__( 'Delivery Day', 'meals-db' ); ?></th>
                    <th><?php echo esc_html__( 'Label', 'meals-db' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                foreach ( $zone_schedule as $zone_name => $config ) :
                    $zone_key = sanitize_title( $zone_name );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $zone_name ); ?></strong></td>
                    <td>
                        <select name="zone_schedule[<?php echo esc_attr( $zone_name ); ?>][day]" class="mealsdb-zone-day">
                            <?php foreach ( $days as $d ) : ?>
                                <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $config['day'], $d ); ?>>
                                    <?php echo esc_html( $d ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="zone_schedule[<?php echo esc_attr( $zone_name ); ?>][label]"
                               value="<?php echo esc_attr( $config['label'] ); ?>" class="regular-text" />
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

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

        <h2><?php echo esc_html__( 'Connection Test', 'meals-db' ); ?></h2>
        <p>
            <button type="button" class="button" id="mealsdb-test-connection">
                <?php echo esc_html__( 'Test Connection', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-test-result" style="margin-left:12px;"></span>
        </p>

        <p class="submit">
            <button type="submit" class="button button-primary" id="mealsdb-save-settings">
                <?php echo esc_html__( 'Save Settings', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-save-result" style="margin-left:12px;"></span>
        </p>
    </form>
</div>

<script>
(function($) {
    'use strict';

    var nonce = '<?php echo esc_js( wp_create_nonce( 'mealsdb_settings_nonce' ) ); ?>';

    // Generate key
    $('#mealsdb-generate-key').on('click', function() {
        $.post(ajaxurl, {
            action: 'mealsdb_generate_encryption_key',
            nonce: nonce,
        }, function(resp) {
            if (resp.success && resp.data.key) {
                $('#mealsdb-enc-key').val(resp.data.key);
                $('#mealsdb-key-warning').show();
            }
        });
    });

    // Test connection
    $('#mealsdb-test-connection').on('click', function() {
        var $result = $('#mealsdb-test-result');
        $result.text('Testing...').css('color', '#666');

        $.post(ajaxurl, {
            action: 'mealsdb_test_db_connection',
            nonce: nonce,
            db_host: $('#mealsdb-db-host').val(),
            db_name: $('#mealsdb-db-name').val(),
            db_user: $('#mealsdb-db-user').val(),
            db_pass: $('#mealsdb-db-pass').val(),
        }, function(resp) {
            if (resp.success) {
                $result.text(resp.data.message).css('color', '#46b450');
            } else {
                $result.text(resp.data.message || 'Connection failed.').css('color', '#dc3232');
            }
        }).fail(function() {
            $result.text('Request failed.').css('color', '#dc3232');
        });
    });

    // Backfill delivery_day
    $('#mealsdb-backfill-delivery-day').on('click', function() {
        var $btn = $(this);
        var $result = $('#mealsdb-backfill-result');
        $btn.prop('disabled', true);
        $result.text('Running...').css('color', '#666');

        $.post(ajaxurl, {
            action: 'mealsdb_backfill_delivery_day',
            nonce: '<?php echo esc_js( wp_create_nonce( 'mealsdb_nonce' ) ); ?>',
        }, function(resp) {
            $btn.prop('disabled', false);
            if (resp.success) {
                $result.text(resp.message || 'Done.').css('color', '#46b450');
            } else {
                $result.text(resp.message || 'Failed.').css('color', '#dc3232');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $result.text('Request failed.').css('color', '#dc3232');
        });
    });

    // Save settings
    $('#mealsdb-settings-form').on('submit', function(e) {
        e.preventDefault();

        var $result = $('#mealsdb-save-result');
        $result.text('Saving...').css('color', '#666');

        // Collect zone schedule data.
        var zoneSchedule = {};
        $('#mealsdb-zone-schedule-table tbody tr').each(function() {
            var zoneName = $(this).find('td:first strong').text();
            var day      = $(this).find('.mealsdb-zone-day').val();
            var label    = $(this).find('input[type="text"]').val();
            if (zoneName) {
                zoneSchedule[zoneName] = { day: day, label: label };
            }
        });

        $.post(ajaxurl, {
            action: 'mealsdb_save_settings',
            nonce: nonce,
            db_host: $('#mealsdb-db-host').val(),
            db_name: $('#mealsdb-db-name').val(),
            db_user: $('#mealsdb-db-user').val(),
            db_pass: $('#mealsdb-db-pass').val(),
            encryption_key: $('#mealsdb-enc-key').val(),
            overage_mains: $('#mealsdb-overage-mains').val(),
            overage_taxable_sides: $('#mealsdb-overage-taxable-sides').val(),
            overage_nontax_sides: $('#mealsdb-overage-nontax-sides').val(),
            zone_schedule: zoneSchedule,
        }, function(resp) {
            if (resp.success) {
                $result.text('Settings saved.').css('color', '#46b450');
                $('#mealsdb-key-warning').hide();
            } else {
                $result.text(resp.data.message || 'Save failed.').css('color', '#dc3232');
            }
        }).fail(function() {
            $result.text('Request failed.').css('color', '#dc3232');
        });
    });

})(jQuery);
</script>
