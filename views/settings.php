<?php
/**
 * Plugin settings screen — encryption key and operational settings.
 */

defined('ABSPATH') || exit;

if ( ! current_user_can( 'manage_options' ) ) {
    return;
}

$opts = get_option( 'mealsdb_settings', [] );

$enc_key       = isset( $opts['encryption_key'] ) ? (string) $opts['encryption_key'] : '';
$has_enc_key   = $enc_key !== '';

// Compute a short non-reversible fingerprint so an admin can confirm at a
// glance which key is configured without exposing the key itself.
$enc_key_fingerprint = '';
if ( $has_enc_key ) {
    $raw = strpos( $enc_key, 'base64:' ) === 0 ? base64_decode( substr( $enc_key, 7 ), true ) : false;
    if ( is_string( $raw ) && $raw !== '' ) {
        $enc_key_fingerprint = strtoupper( substr( hash( 'sha256', $raw ), 0, 12 ) );
    }
}
?>
<div id="mealsdb-settings" class="mealsdb-settings">
    <p class="description">
        <?php echo esc_html__( 'Configure the encryption key used to protect client PII and other plugin settings.', 'meals-db' ); ?>
    </p>

    <form id="mealsdb-settings-form" method="post">
        <h2><?php echo esc_html__( 'Encryption Key', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'AES-256 key used to encrypt client PII in the database. Once data has been encrypted with a key, changing it will make existing encrypted data unreadable.', 'meals-db' ); ?>
        </p>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-enc-key"><?php echo esc_html__( 'AES-256 Key', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <?php if ( $has_enc_key ) : ?>
                            <p>
                                <strong><?php echo esc_html__( 'Status:', 'meals-db' ); ?></strong>
                                <span style="color:#46b450;">&#9679; <?php echo esc_html__( 'Configured', 'meals-db' ); ?></span>
                                <?php if ( $enc_key_fingerprint !== '' ) : ?>
                                    <code style="margin-left:8px;"><?php echo esc_html( $enc_key_fingerprint ); ?></code>
                                <?php endif; ?>
                            </p>
                        <?php else : ?>
                            <p>
                                <strong><?php echo esc_html__( 'Status:', 'meals-db' ); ?></strong>
                                <span style="color:#dc3232;">&#9679; <?php echo esc_html__( 'Not configured', 'meals-db' ); ?></span>
                            </p>
                        <?php endif; ?>
                        <input type="password"
                               id="mealsdb-enc-key"
                               name="encryption_key"
                               value=""
                               class="large-text code"
                               autocomplete="new-password"
                               spellcheck="false"
                               data-1p-ignore="true"
                               placeholder="<?php echo $has_enc_key ? esc_attr__( 'Leave blank to keep current key, or paste a new base64: key to rotate.', 'meals-db' ) : esc_attr__( 'base64:...', 'meals-db' ); ?>" />
                        <p class="description">
                            <?php echo esc_html__( 'Must be a base64-encoded 256-bit key prefixed with "base64:". The current key is never displayed; submit a new one to rotate.', 'meals-db' ); ?>
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

    // Sync product display data
    $('#mealsdb-sync-products').on('click', function() {
        var $btn = $(this);
        var $result = $('#mealsdb-sync-products-result');
        $btn.prop('disabled', true);
        $result.text('Syncing...').css('color', '#666');

        $.post(ajaxurl, {
            action: 'mealsdb_sync_product_display',
            nonce: '<?php echo esc_js( wp_create_nonce( 'mealsdb_nonce' ) ); ?>',
        }, function(resp) {
            $btn.prop('disabled', false);
            if (resp.success) {
                $result.text(resp.message || 'Done.').css('color', '#46b450');
            } else {
                $result.text(resp.message || 'Sync failed.').css('color', '#dc3232');
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
