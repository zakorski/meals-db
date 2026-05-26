<?php
/**
 * Plugin settings screen — encryption key and operational settings.
 */

defined('ABSPATH') || exit;

if ( ! current_user_can( 'manage_options' ) ) {
    return;
}

$opts = get_option( 'mealsdb_settings', [] );

// Shadow mode reflects the central flag's fail-safe interpretation (ON unless
// explicitly turned off), so the checkbox shows the true effective state.
$shadow_on = class_exists( 'MealsDB_Shadow_Mode' )
    ? MealsDB_Shadow_Mode::is_enabled()
    : true;

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
        <?php
        // AJAX submits carry their own mealsdb_settings_nonce; this field
        // is defence-in-depth for the no-JS / submit-on-Enter path.
        wp_nonce_field( 'mealsdb_settings_nonce', 'mealsdb_settings_nonce_field' );
        ?>
        <h2><?php echo esc_html__( 'Shadow Mode', 'meals-db' ); ?></h2>
        <p class="description">
            <?php echo esc_html__( 'While shadow mode is ON, the plugin runs alongside the existing system for comparison WITHOUT affecting live operations: Quick Order is disabled, order fees are not written to WooCommerce orders, and field changes are not pushed back to WordPress users. Reports, invoices, and allocations are still generated for comparison. Turn this OFF only at cutover.', 'meals-db' ); ?>
        </p>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Shadow mode', 'meals-db' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="shadow_mode" value="1" <?php checked( $shadow_on ); ?> />
                            <?php echo esc_html__( 'Run in shadow mode (suppress all changes visible to the existing system)', 'meals-db' ); ?>
                        </label>
                        <p class="description">
                            <?php echo esc_html__( 'Fail-safe: if this setting is ever missing or unreadable, the plugin behaves as if shadow mode is ON.', 'meals-db' ); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

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
                        <input type="number" id="mealsdb-overage-mains" name="overage_mains" value="<?php echo esc_attr( $overage_ids['mains'] ?? MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_MAIN ); ?>" class="small-text" min="0" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-overage-taxable-sides"><?php echo esc_html__( 'Overage Taxable Side Product ID', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="mealsdb-overage-taxable-sides" name="overage_taxable_sides" value="<?php echo esc_attr( $overage_ids['taxable_sides'] ?? MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_SIDE_TAX ); ?>" class="small-text" min="0" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mealsdb-overage-nontax-sides"><?php echo esc_html__( 'Overage Non-Taxable Side Product ID', 'meals-db' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="mealsdb-overage-nontax-sides" name="overage_nontax_sides" value="<?php echo esc_attr( $overage_ids['nontax_sides'] ?? MealsDB_Operational_Constants::PRODUCT_ID_OVERAGE_SIDE_NONTAX ); ?>" class="small-text" min="0" />
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
                    if ( ! is_array( $config ) ) {
                        // Defensive against a corrupted option value — skip
                        // rather than emit an "undefined index" warning
                        // when we reach $config['day'] below.
                        continue;
                    }
                    $zone_key      = sanitize_title( $zone_name );
                    $current_day   = isset( $config['day'] ) && in_array( $config['day'], $days, true ) ? $config['day'] : '';
                    $current_label = isset( $config['label'] ) ? (string) $config['label'] : '';
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $zone_name ); ?></strong></td>
                    <td>
                        <select name="zone_schedule[<?php echo esc_attr( $zone_name ); ?>][day]" class="mealsdb-zone-day">
                            <?php foreach ( $days as $d ) : ?>
                                <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $current_day, $d ); ?>>
                                    <?php echo esc_html( $d ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="zone_schedule[<?php echo esc_attr( $zone_name ); ?>][label]"
                               value="<?php echo esc_attr( $current_label ); ?>" class="regular-text" />
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>


        <p class="submit">
            <button type="submit" class="button button-primary" id="mealsdb-save-settings">
                <?php echo esc_html__( 'Save Settings', 'meals-db' ); ?>
            </button>
            <span id="mealsdb-save-result" style="margin-left:12px;"></span>
        </p>
    </form>
</div>
<?php
// Behaviour is in assets/js/settings.js, enqueued by
// MealsDB_Admin_UI::enqueue_report_scripts() when $tab === 'settings'.
// Config (AJAX URL + nonces) is attached via window.mealsdbSettings.
