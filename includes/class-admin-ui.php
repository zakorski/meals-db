<?php
/**
 * Admin menu & tab routing for Meals DB plugin.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Admin_UI {

    /**
     * Initialize the admin UI: menu + routing.
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Register the Meals DB menu and subpage.
     */
    public static function register_menu() {
        if (!MealsDB_Permissions::can_access_plugin()) {
            return;
        }

        add_menu_page(
            'Meals DB',
            'Meals DB',
            MealsDB_Permissions::required_capability(),
            'meals-db',
            [__CLASS__, 'render_main_page'],
            'dashicons-clipboard',
            56
        );

        add_submenu_page(
            'meals-db',
            __('Staff Directory', 'meals-db'),
            __('Staff Directory', 'meals-db'),
            MealsDB_Permissions::required_capability(),
            'meals-db-staff',
            ['MealsDB_Staff', 'render_admin_page']
        );
    }

    /**
     * Enqueue admin scripts and styles for Meals DB screens.
     *
     * @param string $hook
     */
    public static function enqueue_assets(string $hook): void {
        $is_staff_page = ($hook === 'meals-db_page_meals-db-staff');

        if ($hook !== 'toplevel_page_meals-db' && !$is_staff_page) {
            return;
        }

        $style_path = MEALS_DB_PLUGIN_DIR . 'assets/css/admin.css';
        $style_version = file_exists($style_path) ? filemtime($style_path) : MEALS_DB_VERSION;
        wp_enqueue_style(
            'mealsdb-admin',
            MEALS_DB_PLUGIN_URL . 'assets/css/admin.css',
            [],
            $style_version
        );

        if ($is_staff_page) {
            return;
        }

        $tab = $_GET['tab'] ?? '';
        if (function_exists('wp_unslash')) {
            $tab = wp_unslash($tab);
        }
        if (function_exists('sanitize_key')) {
            $tab = sanitize_key($tab);
        } else {
            $tab = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $tab));
        }

        $action = $_GET['action'] ?? '';
        if (function_exists('wp_unslash')) {
            $action = wp_unslash($action);
        }
        if (function_exists('sanitize_key')) {
            $action = sanitize_key($action);
        } else {
            $action = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $action));
        }

        if ($tab === 'add' || ($tab === 'clients' && $action === 'edit')) {
            $client_style_path = MEALS_DB_PLUGIN_DIR . 'assets/css/client-form.css';
            $client_style_version = file_exists($client_style_path) ? filemtime($client_style_path) : MEALS_DB_VERSION;
            wp_enqueue_style(
                'mealsdb-client-form',
                MEALS_DB_PLUGIN_URL . 'assets/css/client-form.css',
                [],
                $client_style_version
            );
        }

        $script_path = MEALS_DB_PLUGIN_DIR . 'assets/js/admin.js';
        $script_version = file_exists($script_path) ? filemtime($script_path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-admin',
            MEALS_DB_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'jquery-ui-datepicker'],
            $script_version,
            true
        );

        $client_type_logic_path = MEALS_DB_PLUGIN_DIR . 'assets/js/client-type-logic.js';
        $client_type_logic_version = file_exists($client_type_logic_path) ? filemtime($client_type_logic_path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-client-type-logic',
            MEALS_DB_PLUGIN_URL . 'assets/js/client-type-logic.js',
            ['jquery', 'mealsdb-admin'],
            $client_type_logic_version,
            true
        );

        $initials_script_path = MEALS_DB_PLUGIN_DIR . 'assets/js/client-initials.js';
        $initials_script_version = file_exists($initials_script_path) ? filemtime($initials_script_path) : MEALS_DB_VERSION;
        wp_enqueue_script(
            'mealsdb-client-initials',
            MEALS_DB_PLUGIN_URL . 'assets/js/client-initials.js',
            ['jquery', 'mealsdb-admin', 'mealsdb-client-type-logic'],
            $initials_script_version,
            true
        );

        wp_localize_script('mealsdb-admin', 'mealsdb', [
            'nonce'   => wp_create_nonce('mealsdb_nonce'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]);

        wp_localize_script('mealsdb-client-initials', 'mealsdbInitials', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonces'  => [
                'generate' => wp_create_nonce('mealsdb_generate_initials'),
                'validate' => wp_create_nonce('mealsdb_validate_initials'),
            ],
            'messages' => [
                'success'       => __('Initials are valid.', 'meals-db'),
                'invalid'       => __('These initials are invalid or already in use.', 'meals-db'),
                'required'      => __('Please validate the initials before submitting.', 'meals-db'),
                'empty'         => __('Enter initials before validating.', 'meals-db'),
                'error'         => __('An unexpected error occurred. Please try again.', 'meals-db'),
                'generateError' => __('Unable to generate initials. Please try again.', 'meals-db'),
                'validating'    => __('Validating initials…', 'meals-db'),
            ],
        ]);
    }

    /**
     * Render the main admin page, routing to correct tab.
     */
    public static function render_main_page() {
        MealsDB_Permissions::enforce();

        $tab = $_GET['tab'] ?? 'sync';

        echo '<div class="wrap">';
        echo '<h1>Meals DB</h1>';

        self::render_tabs($tab);

        echo '<div class="mealsdb-tab-content">';

        switch ($tab) {
            case 'sync':
                include plugin_dir_path(__FILE__) . '/../views/dashboard.php';
                break;

            case 'add':
                include plugin_dir_path(__FILE__) . '/../views/add-client.php';
                break;

            case 'clients':
                $action = $_GET['action'] ?? '';
                if (function_exists('wp_unslash')) {
                    $action = wp_unslash($action);
                }
                if (function_exists('sanitize_key')) {
                    $action = sanitize_key($action);
                } else {
                    $action = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $action));
                }
                if ($action === 'edit') {
                    include plugin_dir_path(__FILE__) . '/../views/edit-client.php';
                } else {
                    include plugin_dir_path(__FILE__) . '/../views/view-clients.php';
                }
                break;

            case 'drafts':
                include plugin_dir_path(__FILE__) . '/../views/drafts.php';
                break;

            case 'ignored':
                include plugin_dir_path(__FILE__) . '/../views/ignored.php';
                break;

            case 'updates':
                include plugin_dir_path(__FILE__) . '/../views/updates.php';
                break;

            default:
                echo '<p>Invalid tab selected.</p>';
        }

        echo '</div></div>';
    }

    /**
     * Renders the tab navigation.
     *
     * @param string $active
     */
    private static function render_tabs(string $active = 'sync') {
        $active_tab = $active;
        $tabs = [
            'sync'    => __('Sync Dashboard', 'meals-db'),
            'add'     => __('Add New Client', 'meals-db'),
            'clients' => __('View Clients', 'meals-db'),
            'drafts'  => __('Drafts', 'meals-db'),
            'ignored' => __('Ignored Conflicts', 'meals-db'),
            'updates' => __('Updates', 'meals-db'),
        ];

        include MEALS_DB_PLUGIN_DIR . 'views/partials/tabs.php';
    }

    /**
     * Render the client form using a single-page, multi-column layout.
     *
     * @param array $args
     */
    public static function render_client_form(array $args = []): void {
        $defaults = [
            'form_mode'         => 'add',
            'submit_label'      => __('Submit', 'meals-db'),
            'show_draft_button' => false,
            'resumed_draft_id'  => 0,
            'client_id'         => 0,
            'form_values'       => [],
        ];

        $args = array_merge($defaults, $args);

        $form_mode = $args['form_mode'] === 'edit' ? 'edit' : 'add';
        $submit_label = (string) ($args['submit_label'] ?: ($form_mode === 'edit'
            ? __('Update Client', 'meals-db')
            : __('Submit', 'meals-db')));
        $show_draft_button = (bool) $args['show_draft_button'];
        $resumed_draft_id = intval($args['resumed_draft_id']);
        $client_id = intval($args['client_id']);
        $form_values = is_array($args['form_values']) ? $args['form_values'] : [];

        $client_type = strtoupper($form_values['customer_type'] ?? '');

        $delivery_day_options = MealsDB_Client_Form::get_allowed_options('delivery_day');
        $ordering_contact_method_options = MealsDB_Client_Form::get_allowed_options('ordering_contact_method');
        $service_zone_options = MealsDB_Client_Form::get_allowed_options('service_zone');

        $format_enum_option_label = static function (string $value): string {
            $label = ucwords(strtolower($value));
            return str_ireplace(['Am', 'Pm'], ['AM', 'PM'], $label);
        };

        $alt_contact_name = $form_values['alt_contact_name'] ?? '';
        $alt_contact_first = '';
        $alt_contact_last = '';
        if (!empty($alt_contact_name)) {
            $name_parts = preg_split('/\s+/', trim((string) $alt_contact_name), 2);
            $alt_contact_first = $name_parts[0] ?? '';
            $alt_contact_last = $name_parts[1] ?? '';
        }

        $delivery_address_fields = [
            'delivery_address_street_number',
            'delivery_address_street_name',
            'delivery_address_unit',
            'delivery_address_city',
            'delivery_address_province',
            'delivery_address_postal',
        ];
        $delivery_address_enabled = false;
        foreach ($delivery_address_fields as $field_key) {
            if (!empty($form_values[$field_key])) {
                $delivery_address_enabled = true;
                break;
            }
        }

        $alt_contact_enabled = (
            !empty($alt_contact_first) ||
            !empty($alt_contact_last) ||
            !empty($form_values['alt_contact_phone_primary'] ?? '') ||
            !empty($form_values['alt_contact_phone_secondary'] ?? '') ||
            !empty($form_values['alt_contact_email'] ?? '')
        );

        $delivery_initials_value = $form_values['delivery_initials'] ?? '';
        $delivery_day_value = strtoupper($form_values['delivery_day'] ?? '');
        $ordering_contact_method_value = strtoupper($form_values['ordering_contact_method'] ?? '');
        $service_zone_value = strtoupper($form_values['service_zone'] ?? '');
        $gender_value = $form_values['gender'] ?? '';
        $meal_type_value = $form_values['meal_type'] ?? '';
        $requisition_period_value = $form_values['requisition_period'] ?? '';
        $form_classes = ['mealsdb-client-form'];
        if ($client_type !== '') {
            $form_classes[] = 'mealsdb-client-type-selected';
        }
        $form_class_attr = implode(' ', $form_classes);

        $client = $form_values;

        $identity_fields = [
            '__before' => '<p class="description">' . esc_html__('Staff clients only require a first name, last name, and email address.', 'meals-db') . '</p>',
            static function (array $client) use ($client_type) {
                ?>
                <tr>
                    <th><label for="customer_type"><?php esc_html_e('Client Type *', 'meals-db'); ?></label></th>
                    <td>
                        <?php $current_type = $client_type; ?>
                        <select name="customer_type" id="customer_type" required data-base-required="1">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <option value="SDNB" <?php selected($current_type, 'SDNB'); ?>>SDNB</option>
                            <option value="Veteran" <?php selected($current_type, 'Veteran'); ?>>Veteran</option>
                            <option value="Private" <?php selected($current_type, 'Private'); ?>>Private</option>
                            <option value="Staff" <?php selected($current_type, 'Staff'); ?>><?php esc_html_e('Staff', 'meals-db'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Changing this selection updates which sections are shown below.', 'meals-db'); ?></p>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="first_name"><?php esc_html_e('First Name *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="first_name" id="first_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['first_name'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="last_name"><?php esc_html_e('Last Name *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="last_name" id="last_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['last_name'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="staff">
                    <th>
                        <label for="client_email"><?php esc_html_e('Client Email *', 'meals-db'); ?></label>
                        <span class="description"><?php esc_html_e('Required for Staff clients.', 'meals-db'); ?></span>
                    </th>
                    <td><input type="email" name="client_email" id="client_email" class="regular-text" data-base-required="1" value="<?php echo esc_attr($client['client_email'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th>
                        <label for="wordpress_user_id"><?php esc_html_e('WordPress User ID', 'meals-db'); ?></label>
                        <span class="description"><?php esc_html_e('Optional link to the matching WordPress user account.', 'meals-db'); ?></span>
                    </th>
                    <td><input type="number" name="wordpress_user_id" id="wordpress_user_id" class="regular-text" min="1" step="1" value="<?php echo esc_attr($client['wordpress_user_id'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private" data-required-for="sdnb,veteran,private">
                    <th><label for="open_date"><?php esc_html_e('Open Date *', 'meals-db'); ?></label></th>
                    <td><input type="date" name="open_date" id="open_date" class="mealsdb-datepicker" data-base-required="1" value="<?php echo esc_attr($client['open_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="assigned_social_worker"><?php esc_html_e('Social Worker Name', 'meals-db'); ?></label></th>
                    <td><input type="text" name="assigned_social_worker" id="assigned_social_worker" class="regular-text" value="<?php echo esc_attr($client['assigned_social_worker'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="social_worker_email"><?php esc_html_e('Social Worker Email Address', 'meals-db'); ?></label></th>
                    <td><input type="email" name="social_worker_email" id="social_worker_email" class="regular-text" value="<?php echo esc_attr($client['social_worker_email'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="birth_date"><?php esc_html_e('Date of Birth', 'meals-db'); ?></label></th>
                    <td><input type="date" name="birth_date" id="birth_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['birth_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran" data-required-for="sdnb,veteran">
                    <th><label for="units"><?php esc_html_e('# of Units *', 'meals-db'); ?></label></th>
                    <td><input type="number" name="units" id="units" class="small-text" min="1" max="31" data-base-required="1" value="<?php echo esc_attr($client['units'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
        ];

        $contact_fields = [
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="phone_primary"><?php esc_html_e('Phone Number *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="phone_primary" id="phone_primary" class="regular-text phone-mask" placeholder="(555)-555-5555" required data-base-required="1" value="<?php echo esc_attr($client['phone_primary'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="phone_secondary"><?php esc_html_e('Second Phone Number', 'meals-db'); ?></label></th>
                    <td><input type="text" name="phone_secondary" id="phone_secondary" class="regular-text phone-mask" placeholder="(555)-555-5555" value="<?php echo esc_attr($client['phone_secondary'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="do_not_call_client_phone"><?php esc_html_e("Do Not Call Client's Phone", 'meals-db'); ?></label></th>
                    <td><label><input type="checkbox" name="do_not_call_client_phone" id="do_not_call_client_phone" value="1" <?php checked($client['do_not_call_client_phone'] ?? '0', '1'); ?> /> <?php esc_html_e('Call alternate contact instead', 'meals-db'); ?></label></td>
                </tr>
                <?php
            },
            '__after' => static function () use ($alt_contact_enabled, $alt_contact_name, $alt_contact_first, $alt_contact_last, $form_values) {
                ?>
                <h4><?php esc_html_e('Alternate Contact', 'meals-db'); ?></h4>
                <p><label><input type="checkbox" id="alternate-contact-toggle" <?php checked($alt_contact_enabled); ?> /> <?php esc_html_e('Add alternate contact', 'meals-db'); ?></label></p>
                <div id="alternate-contact-fields" class="mealsdb-collapsible" <?php if (!$alt_contact_enabled) { echo 'style="display:none;"'; } ?>>
                    <input type="hidden" name="alt_contact_name" id="alt_contact_name" value="<?php echo esc_attr($alt_contact_name); ?>" />
                    <table class="form-table">
                        <tr>
                            <th><label for="alt_contact_first_name"><?php esc_html_e('First Name', 'meals-db'); ?></label></th>
                            <td><input type="text" id="alt_contact_first_name" class="regular-text" value="<?php echo esc_attr($alt_contact_first); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="alt_contact_last_name"><?php esc_html_e('Last Name', 'meals-db'); ?></label></th>
                            <td><input type="text" id="alt_contact_last_name" class="regular-text" value="<?php echo esc_attr($alt_contact_last); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="alt_contact_phone_primary"><?php esc_html_e('Phone Number', 'meals-db'); ?></label></th>
                            <td><input type="text" name="alt_contact_phone_primary" id="alt_contact_phone_primary" class="regular-text phone-mask" placeholder="(555)-555-5555" value="<?php echo esc_attr($form_values['alt_contact_phone_primary'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="alt_contact_phone_secondary"><?php esc_html_e('Second Phone Number', 'meals-db'); ?></label></th>
                            <td><input type="text" name="alt_contact_phone_secondary" id="alt_contact_phone_secondary" class="regular-text phone-mask" placeholder="(555)-555-5555" value="<?php echo esc_attr($form_values['alt_contact_phone_secondary'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="alt_contact_email"><?php esc_html_e('Contact Email', 'meals-db'); ?></label></th>
                            <td><input type="email" name="alt_contact_email" id="alt_contact_email" class="regular-text" value="<?php echo esc_attr($form_values['alt_contact_email'] ?? ''); ?>" /></td>
                        </tr>
                    </table>
                </div>
                <?php
            },
        ];

        $address_fields = [
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_street_number"><?php esc_html_e('Street # *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_street_number" id="address_street_number" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['address_street_number'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_street_name"><?php esc_html_e('Street Name *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_street_name" id="address_street_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['address_street_name'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_unit"><?php esc_html_e('Apt # *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_unit" id="address_unit" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['address_unit'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_city"><?php esc_html_e('City *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_city" id="address_city" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['address_city'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_province"><?php esc_html_e('Province *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_province" id="address_province" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['address_province'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_postal"><?php esc_html_e('Postal Code *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_postal" id="address_postal" class="regular-text postal-mask" maxlength="6" placeholder="A1A1A1" required data-base-required="1" value="<?php echo esc_attr($client['address_postal'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            '__after' => static function () use ($delivery_address_enabled, $form_values) {
                ?>
                <h4><?php esc_html_e('Delivery Address', 'meals-db'); ?></h4>
                <p><label><input type="checkbox" id="delivery-address-toggle" <?php checked($delivery_address_enabled); ?> /> <?php esc_html_e('Delivery address different from home address', 'meals-db'); ?></label></p>
                <div id="delivery-address-fields" class="mealsdb-collapsible" <?php if (!$delivery_address_enabled) { echo 'style="display:none;"'; } ?>>
                    <table class="form-table">
                        <tr>
                            <th><label for="delivery_address_street_number"><?php esc_html_e('Street #', 'meals-db'); ?></label></th>
                            <td><input type="text" name="delivery_address_street_number" id="delivery_address_street_number" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_street_number'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="delivery_address_street_name"><?php esc_html_e('Street Name', 'meals-db'); ?></label></th>
                            <td><input type="text" name="delivery_address_street_name" id="delivery_address_street_name" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_street_name'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="delivery_address_unit"><?php esc_html_e('Apt #', 'meals-db'); ?></label></th>
                            <td><input type="text" name="delivery_address_unit" id="delivery_address_unit" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_unit'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="delivery_address_city"><?php esc_html_e('City', 'meals-db'); ?></label></th>
                            <td><input type="text" name="delivery_address_city" id="delivery_address_city" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_city'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="delivery_address_province"><?php esc_html_e('Province', 'meals-db'); ?></label></th>
                            <td><input type="text" name="delivery_address_province" id="delivery_address_province" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_province'] ?? ''); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="delivery_address_postal"><?php esc_html_e('Postal Code', 'meals-db'); ?></label></th>
                            <td><input type="text" name="delivery_address_postal" id="delivery_address_postal" class="regular-text postal-mask" maxlength="6" placeholder="A1A1A1" value="<?php echo esc_attr($form_values['delivery_address_postal'] ?? ''); ?>" /></td>
                        </tr>
                    </table>
                </div>
                <?php
            },
        ];

        $service_delivery_fields = [
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="payment_method"><?php esc_html_e('Payment Method *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="payment_method" id="payment_method" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['payment_method'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="required_start_date"><?php esc_html_e('Required Start Date *', 'meals-db'); ?></label></th>
                    <td><input type="date" name="required_start_date" id="required_start_date" class="mealsdb-datepicker" required data-base-required="1" value="<?php echo esc_attr($client['required_start_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="rate"><?php esc_html_e('Rate *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="rate" id="rate" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['rate'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="delivery_fee"><?php esc_html_e('Delivery Fee', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_fee" id="delivery_fee" class="regular-text" value="<?php echo esc_attr($client['delivery_fee'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="freezer_capacity"><?php esc_html_e('Freezer Capacity', 'meals-db'); ?></label></th>
                    <td><input type="text" name="freezer_capacity" id="freezer_capacity" class="regular-text" value="<?php echo esc_attr($client['freezer_capacity'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) use ($delivery_day_options, $format_enum_option_label, $delivery_day_value) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_day"><?php esc_html_e('Delivery Day *', 'meals-db'); ?></label></th>
                    <td>
                        <select name="delivery_day" id="delivery_day" class="regular-text" required data-base-required="1">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <?php foreach ($delivery_day_options as $option) : ?>
                                <?php $label = $format_enum_option_label($option); ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($delivery_day_value, strtoupper($option)); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_area_name"><?php esc_html_e('Delivery Area Name *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_area_name" id="delivery_area_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['delivery_area_name'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_area_zone"><?php esc_html_e('Delivery Area Zone *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_area_zone" id="delivery_area_zone" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['delivery_area_zone'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) use ($ordering_contact_method_options, $format_enum_option_label, $ordering_contact_method_value) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="ordering_contact_method"><?php esc_html_e('Ordering Contact Method *', 'meals-db'); ?></label></th>
                    <td>
                        <select name="ordering_contact_method" id="ordering_contact_method" required data-base-required="1">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <?php foreach ($ordering_contact_method_options as $option) : ?>
                                <?php $label = $format_enum_option_label($option); ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($ordering_contact_method_value, strtoupper($option)); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="ordering_frequency"><?php esc_html_e('Ordering Frequency *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="ordering_frequency" id="ordering_frequency" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['ordering_frequency'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_frequency"><?php esc_html_e('Delivery Frequency *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_frequency" id="delivery_frequency" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($client['delivery_frequency'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
        ];

        $requisition_fields = [
            '__attributes' => 'data-client-type="sdnb"',
            static function (array $client) use ($requisition_period_value) {
                ?>
                <tr>
                    <th><label for="requisition_period"><?php esc_html_e('Requisition Period', 'meals-db'); ?></label></th>
                    <td>
                        <select name="requisition_period" id="requisition_period">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <option value="Day" <?php selected($requisition_period_value, 'Day'); ?>><?php esc_html_e('Day', 'meals-db'); ?></option>
                            <option value="Week" <?php selected($requisition_period_value, 'Week'); ?>><?php esc_html_e('Week', 'meals-db'); ?></option>
                            <option value="Month" <?php selected($requisition_period_value, 'Month'); ?>><?php esc_html_e('Month', 'meals-db'); ?></option>
                        </select>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="service_commence_date"><?php esc_html_e('Service Commence Date', 'meals-db'); ?></label></th>
                    <td><input type="date" name="service_commence_date" id="service_commence_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['service_commence_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="expected_termination_date"><?php esc_html_e('Expected Termination Date', 'meals-db'); ?></label></th>
                    <td><input type="date" name="expected_termination_date" id="expected_termination_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['expected_termination_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="initial_renewal_date"><?php esc_html_e('Initial Renewal Termination Date', 'meals-db'); ?></label></th>
                    <td><input type="date" name="initial_renewal_date" id="initial_renewal_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['initial_renewal_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="most_recent_renewal_date"><?php esc_html_e('Most Recent Renewal Termination Date', 'meals-db'); ?></label></th>
                    <td><input type="date" name="most_recent_renewal_date" id="most_recent_renewal_date" class="mealsdb-datepicker" value="<?php echo esc_attr($client['most_recent_renewal_date'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
        ];

        $sdnb_program_fields = [
            '__attributes' => 'data-client-type="sdnb"',
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="client_contribution"><?php esc_html_e('Client Contributions', 'meals-db'); ?></label></th>
                    <td><input type="text" name="client_contribution" id="client_contribution" class="regular-text" value="<?php echo esc_attr($client['client_contribution'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="individual_id"><?php esc_html_e('Individual ID', 'meals-db'); ?></label></th>
                    <td><input type="text" name="individual_id" id="individual_id" class="regular-text" value="<?php echo esc_attr($client['individual_id'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) use ($gender_value) {
                ?>
                <tr>
                    <th><?php esc_html_e('Gender', 'meals-db'); ?></th>
                    <td>
                        <label><input type="radio" name="gender" value="Male" <?php checked($gender_value, 'Male'); ?> /> <?php esc_html_e('Male', 'meals-db'); ?></label>
                        <label><input type="radio" name="gender" value="Female" <?php checked($gender_value, 'Female'); ?> /> <?php esc_html_e('Female', 'meals-db'); ?></label>
                        <label><input type="radio" name="gender" value="Other" <?php checked($gender_value, 'Other'); ?> /> <?php esc_html_e('Other', 'meals-db'); ?></label>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="service_center_charged"><?php esc_html_e('Service Center Charged', 'meals-db'); ?></label></th>
                    <td><input type="text" name="service_center_charged" id="service_center_charged" class="regular-text" value="<?php echo esc_attr($client['service_center_charged'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="vendor_number"><?php esc_html_e('Vendor #', 'meals-db'); ?></label></th>
                    <td><input type="text" name="vendor_number" id="vendor_number" class="regular-text" value="<?php echo esc_attr($client['vendor_number'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="service_id"><?php esc_html_e('Service ID', 'meals-db'); ?></label></th>
                    <td><input type="text" name="service_id" id="service_id" class="regular-text" value="<?php echo esc_attr($client['service_id'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr>
                    <th><label for="requisition_id"><?php esc_html_e('Requisition ID', 'meals-db'); ?></label></th>
                    <td><input type="text" name="requisition_id" id="requisition_id" class="regular-text" value="<?php echo esc_attr($client['requisition_id'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
            static function (array $client) use ($service_zone_options, $format_enum_option_label, $service_zone_value) {
                ?>
                <tr>
                    <th><label for="service_zone"><?php esc_html_e('Service Name Zone', 'meals-db'); ?></label></th>
                    <td>
                        <select name="service_zone" id="service_zone" class="regular-text">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <?php foreach ($service_zone_options as $option) : ?>
                                <?php $label = $format_enum_option_label($option); ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($service_zone_value, strtoupper($option)); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) use ($meal_type_value) {
                ?>
                <tr>
                    <th><label for="meal_type"><?php esc_html_e('Meal Type', 'meals-db'); ?></label></th>
                    <td>
                        <select name="meal_type" id="meal_type">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <option value="1" <?php selected($meal_type_value, '1'); ?>>1 <?php esc_html_e('Course', 'meals-db'); ?></option>
                            <option value="2" <?php selected($meal_type_value, '2'); ?>>2 <?php esc_html_e('Course', 'meals-db'); ?></option>
                        </select>
                    </td>
                </tr>
                <?php
            },
        ];

        $veteran_fields = [
            '__attributes' => 'data-client-type="veteran"',
            static function (array $client) {
                ?>
                <tr data-required-for="veteran">
                    <th><label for="vet_health_card"><?php esc_html_e('Veteran Health Identification Card # *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="vet_health_card" id="vet_health_card" class="regular-text" data-base-required="1" value="<?php echo esc_attr($client['vet_health_card'] ?? ''); ?>" /></td>
                </tr>
                <?php
            },
        ];

        $delivery_notes_fields = [
            static function (array $client) use ($delivery_initials_value) {
                ?>
                <tr data-client-type="sdnb,veteran,private" data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_initials"><?php esc_html_e('Initials for Delivery *', 'meals-db'); ?></label></th>
                    <td>
                        <div class="mealsdb-initials-tools">
                            <input type="text" name="delivery_initials" id="delivery_initials" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($delivery_initials_value); ?>" />
                            <div class="mealsdb-initials-buttons">
                                <button type="button" class="button mealsdb-initials-generate" id="mealsdb-generate-initials"><?php esc_html_e('Generate', 'meals-db'); ?></button>
                                <button type="button" class="button mealsdb-initials-validate" id="mealsdb-validate-initials"><?php esc_html_e('Validate', 'meals-db'); ?></button>
                            </div>
                            <div id="initials-validation-status"></div>
                            <div class="mealsdb-initials-message" aria-live="polite"></div>
                        </div>
                    </td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="diet_concerns"><?php esc_html_e('Dietary Concerns', 'meals-db'); ?></label></th>
                    <td><textarea name="diet_concerns" id="diet_concerns" rows="4" class="large-text"><?php echo esc_textarea($client['diet_concerns'] ?? ''); ?></textarea></td>
                </tr>
                <?php
            },
            static function (array $client) {
                ?>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="client_comments"><?php esc_html_e('Customer Comments', 'meals-db'); ?></label></th>
                    <td><textarea name="client_comments" id="client_comments" rows="4" class="large-text"><?php echo esc_textarea($client['client_comments'] ?? ''); ?></textarea></td>
                </tr>
                <?php
            },
            '__after' => static function () use ($submit_label, $show_draft_button) {
                ?>
                <div class="mealsdb-form-actions">
                    <button type="submit" class="button button-primary"><?php echo esc_html($submit_label); ?></button>
                    <?php if ($show_draft_button) : ?>
                        <button type="button" id="mealsdb-save-draft" class="button button-secondary"><?php esc_html_e('Save to Draft', 'meals-db'); ?></button>
                    <?php endif; ?>
                </div>
                <?php
            },
        ];
        ?>
        <form method="post" id="mealsdb-client-form" class="<?php echo esc_attr($form_class_attr); ?>">
            <?php wp_nonce_field('mealsdb_nonce', 'mealsdb_nonce_field'); ?>
            <?php if ($client_id > 0 && $form_mode === 'edit') : ?>
                <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>" />
            <?php endif; ?>

            <?php if ($show_draft_button && $resumed_draft_id > 0) : ?>
                <input type="hidden" name="draft_id" value="<?php echo esc_attr($resumed_draft_id); ?>" />
            <?php endif; ?>

            <div class="mealsdb-form-columns">
                <div class="mealsdb-column col-1">
                    <?php
                    self::render_field_group(__('Identity', 'meals-db'), $identity_fields, $client);
                    self::render_field_group(__('Contact Information', 'meals-db'), $contact_fields, $client);
                    ?>
                </div>

                <div class="mealsdb-column col-2">
                    <?php
                    self::render_field_group(__('Address', 'meals-db'), $address_fields, $client);
                    self::render_field_group(__('Service & Delivery', 'meals-db'), $service_delivery_fields, $client);
                    self::render_field_group(__('Requisition Details (SDNB)', 'meals-db'), $requisition_fields, $client);
                    ?>
                </div>

                <div class="mealsdb-column col-3">
                    <?php
                    self::render_field_group(__('SDNB Program Details', 'meals-db'), $sdnb_program_fields, $client);
                    self::render_field_group(__('Veteran Details', 'meals-db'), $veteran_fields, $client);
                    self::render_field_group(__('Delivery Initials & Notes', 'meals-db'), $delivery_notes_fields, $client);
                    ?>
                </div>
            </div>
        </form>
        <?php
    }

    /**
     * Render a field group within the client form.
     *
     * @param string $group_name
     * @param array  $fields
     * @param array  $client
     */
    private static function render_field_group(string $group_name, array $fields, array $client): void
    {
        if (empty($fields)) {
            return;
        }

        $before     = $fields['__before'] ?? '';
        $after      = $fields['__after'] ?? '';
        $attributes = $fields['__attributes'] ?? '';
        unset($fields['__before'], $fields['__after'], $fields['__attributes']);

        $attributes = $attributes !== '' ? ' ' . trim($attributes) : '';

        echo '<div class="mealsdb-section"' . $attributes . '>';
        echo '<h3>' . esc_html($group_name) . '</h3>';

        if ($before !== '') {
            if (is_callable($before)) {
                $before($client);
            } else {
                echo $before;
            }
        }

        echo '<table class="form-table">';
        foreach ($fields as $field_renderer) {
            if (is_callable($field_renderer)) {
                $field_renderer($client);
                continue;
            }

            echo $field_renderer;
        }
        echo '</table>';

        if ($after !== '') {
            if (is_callable($after)) {
                $after($client);
            } else {
                echo $after;
            }
        }

        echo '</div>';
    }
}
