<?php
$form_mode = isset($form_mode) ? $form_mode : 'add';
$form_heading = $form_mode === 'edit' ? __('Edit Client', 'meals-db') : __('Add New Client', 'meals-db');
$submit_label = $form_mode === 'edit' ? __('Update Client', 'meals-db') : __('Submit', 'meals-db');
$show_draft_button = ($form_mode === 'add');
$resumed_draft_id = isset($resumed_draft_id) ? intval($resumed_draft_id) : 0;
$client_id = isset($client_id) ? intval($client_id) : 0;

$form_values = isset($form_values) && is_array($form_values) ? $form_values : [];
$client_type = $form_values['customer_type'] ?? '';
$initial_step = $client_type ? 2 : 1;

$delivery_day_options = MealsDB_Client_Form::get_allowed_options('delivery_day');
$ordering_contact_method_options = MealsDB_Client_Form::get_allowed_options('ordering_contact_method');
$service_zone_options = MealsDB_Client_Form::get_allowed_options('service_zone');
$format_enum_option_label = static function (string $value): string {
    $label = ucwords(strtolower($value));
    $label = str_ireplace(['Am', 'Pm'], ['AM', 'PM'], $label);

    return $label;
};

$alt_contact_name = $form_values['alt_contact_name'] ?? '';
$alt_contact_first = '';
$alt_contact_last = '';
if (!empty($alt_contact_name)) {
    $name_parts = preg_split('/\s+/', trim($alt_contact_name), 2);
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
?>

<div class="wrap mealsdb-client-form-wrap">
    <h2><?php echo esc_html($form_heading); ?></h2>

    <?php include __DIR__ . '/status-notice.php'; ?>

    <form method="post" id="mealsdb-client-form" data-initial-step="<?php echo esc_attr($initial_step); ?>">
        <?php wp_nonce_field('mealsdb_nonce', 'mealsdb_nonce_field'); ?>
        <?php if ($client_id > 0 && $form_mode === 'edit') : ?>
            <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>" />
        <?php endif; ?>

        <?php if ($show_draft_button && $resumed_draft_id > 0) : ?>
            <input type="hidden" name="draft_id" value="<?php echo esc_attr($resumed_draft_id); ?>" />
        <?php endif; ?>

        <ol class="mealsdb-step-indicator">
            <li data-step="1">Client Type</li>
            <li data-step="2">Client &amp; Case Details</li>
            <li data-step="3" data-client-type="sdnb,veteran,private">Addresses &amp; Contacts</li>
            <li data-step="4" data-client-type="sdnb,veteran,private">Service &amp; Delivery</li>
            <li data-step="5">Notes &amp; Submit</li>
        </ol>

        <div class="mealsdb-step" data-step="1" data-step-title="Client Type">
            <h3><?php esc_html_e('Step 1: Select Client Type', 'meals-db'); ?></h3>
            <p><?php esc_html_e('Select the client type to load only the fields required for that program.', 'meals-db'); ?></p>
            <table class="form-table">
                <tr>
                    <th><label for="mealsdb-client-type-initial"><?php esc_html_e('Client Type *', 'meals-db'); ?></label></th>
                    <td>
                        <?php $initial_type = $client_type; ?>
                        <select id="mealsdb-client-type-initial">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <option value="SDNB" <?php selected($initial_type, 'SDNB'); ?>>SDNB</option>
                            <option value="Veteran" <?php selected($initial_type, 'Veteran'); ?>>Veteran</option>
                            <option value="Private" <?php selected($initial_type, 'Private'); ?>>Private</option>
                            <option value="Staff" <?php selected($initial_type, 'Staff'); ?>><?php esc_html_e('Staff', 'meals-db'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Client fields will adjust automatically when the type changes.', 'meals-db'); ?></p>
                    </td>
                </tr>
            </table>
            <div class="mealsdb-step-controls">
                <button type="button" class="button button-primary mealsdb-next-step" data-step-target="2" <?php disabled(!$client_type); ?>><?php esc_html_e('Next', 'meals-db'); ?></button>
            </div>
        </div>

        <div class="mealsdb-step" data-step="2" data-step-title="Client &amp; Case Details">
            <h3><?php esc_html_e('Step 2: Client &amp; Case Details', 'meals-db'); ?></h3>
            <p class="description"><?php esc_html_e('Staff clients only require a first name, last name, and email address.', 'meals-db'); ?></p>
            <table class="form-table">
                <tr>
                    <th><label for="customer_type"><?php esc_html_e('Customer Type *', 'meals-db'); ?></label></th>
                    <td>
                        <?php $current_type = $client_type; ?>
                        <select name="customer_type" id="customer_type" required data-base-required="1">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <option value="SDNB" <?php selected($current_type, 'SDNB'); ?>>SDNB</option>
                            <option value="Veteran" <?php selected($current_type, 'Veteran'); ?>>Veteran</option>
                            <option value="Private" <?php selected($current_type, 'Private'); ?>>Private</option>
                            <option value="Staff" <?php selected($current_type, 'Staff'); ?>><?php esc_html_e('Staff', 'meals-db'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Changing this selection will update the fields shown in other steps.', 'meals-db'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="first_name"><?php esc_html_e('First Name *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="first_name" id="first_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['first_name'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="last_name"><?php esc_html_e('Last Name *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="last_name" id="last_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['last_name'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="staff">
                    <th>
                        <label for="client_email"><?php esc_html_e('Client Email *', 'meals-db'); ?></label>
                        <span class="description"><?php esc_html_e('Required for Staff clients.', 'meals-db'); ?></span>
                    </th>
                    <td><input type="email" name="client_email" id="client_email" class="regular-text" data-base-required="1" value="<?php echo esc_attr($form_values['client_email'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran,private">
                    <th>
                        <label for="wordpress_user_id"><?php esc_html_e('WordPress User ID', 'meals-db'); ?></label>
                        <span class="description"><?php esc_html_e('Optional link to the matching WordPress user account.', 'meals-db'); ?></span>
                    </th>
                    <td><input type="number" name="wordpress_user_id" id="wordpress_user_id" class="regular-text" min="1" step="1" value="<?php echo esc_attr($form_values['wordpress_user_id'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th>
                        <label for="phone_primary"><?php esc_html_e('Phone Number *', 'meals-db'); ?></label>
                        <span class="description" data-client-type="staff"><?php esc_html_e('Optional for Staff clients.', 'meals-db'); ?></span>
                    </th>
                    <td><input type="text" name="phone_primary" id="phone_primary" class="regular-text phone-mask" placeholder="(555)-555-5555" required data-base-required="1" value="<?php echo esc_attr($form_values['phone_primary'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="phone_secondary"><?php esc_html_e('Second Phone Number', 'meals-db'); ?></label></th>
                    <td><input type="text" name="phone_secondary" id="phone_secondary" class="regular-text phone-mask" placeholder="(555)-555-5555" value="<?php echo esc_attr($form_values['phone_secondary'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="do_not_call_client_phone"><?php esc_html_e("Do Not Call Client's Phone", 'meals-db'); ?></label></th>
                    <td><label><input type="checkbox" name="do_not_call_client_phone" id="do_not_call_client_phone" value="1" <?php checked($form_values['do_not_call_client_phone'] ?? '0', '1'); ?> /> <?php esc_html_e('Call alternate contact instead', 'meals-db'); ?></label></td>
                </tr>
                <tr data-client-type="sdnb,veteran" data-required-for="sdnb,veteran">
                    <th><label for="open_date"><?php esc_html_e('Open Date *', 'meals-db'); ?></label></th>
                    <td><input type="date" name="open_date" id="open_date" class="mealsdb-datepicker" data-base-required="1" value="<?php echo esc_attr($form_values['open_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="assigned_social_worker"><?php esc_html_e('Social Worker Name', 'meals-db'); ?></label></th>
                    <td><input type="text" name="assigned_social_worker" id="assigned_social_worker" class="regular-text" value="<?php echo esc_attr($form_values['assigned_social_worker'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="social_worker_email"><?php esc_html_e('Social Worker Email Address', 'meals-db'); ?></label></th>
                    <td><input type="email" name="social_worker_email" id="social_worker_email" class="regular-text" value="<?php echo esc_attr($form_values['social_worker_email'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="birth_date"><?php esc_html_e('Date of Birth', 'meals-db'); ?></label></th>
                    <td><input type="date" name="birth_date" id="birth_date" class="mealsdb-datepicker" value="<?php echo esc_attr($form_values['birth_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran" data-required-for="sdnb,veteran">
                    <th><label for="units"><?php esc_html_e('# of Units *', 'meals-db'); ?></label></th>
                    <td><input type="number" name="units" id="units" class="small-text" min="1" max="31" data-base-required="1" value="<?php echo esc_attr($form_values['units'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="veteran" data-required-for="veteran">
                    <th><label for="vet_health_card"><?php esc_html_e('Veteran Health Identification Card # *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="vet_health_card" id="vet_health_card" class="regular-text" data-base-required="1" value="<?php echo esc_attr($form_values['vet_health_card'] ?? ''); ?>" /></td>
                </tr>
            </table>
            <div class="mealsdb-step-controls">
                <button type="button" class="button mealsdb-prev-step" data-step-target="1"><?php esc_html_e('Back', 'meals-db'); ?></button>
                <button type="button" class="button button-primary mealsdb-next-step" data-step-target="3"><?php esc_html_e('Next', 'meals-db'); ?></button>
            </div>
        </div>

        <div class="mealsdb-step" data-step="3" data-step-title="Addresses &amp; Contacts" data-client-type="sdnb,veteran,private">
            <h3><?php esc_html_e('Step 3: Addresses &amp; Contacts', 'meals-db'); ?></h3>
            <table class="form-table">
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_street_number"><?php esc_html_e('Street # *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_street_number" id="address_street_number" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['address_street_number'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_street_name"><?php esc_html_e('Street Name *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_street_name" id="address_street_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['address_street_name'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_unit"><?php esc_html_e('Apt # *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_unit" id="address_unit" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['address_unit'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_city"><?php esc_html_e('City *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_city" id="address_city" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['address_city'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_province"><?php esc_html_e('Province *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_province" id="address_province" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['address_province'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="address_postal"><?php esc_html_e('Postal Code *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="address_postal" id="address_postal" class="regular-text postal-mask" maxlength="6" placeholder="A1A1A1" required data-base-required="1" value="<?php echo esc_attr($form_values['address_postal'] ?? ''); ?>" /></td>
                </tr>
            </table>

            <h4><?php esc_html_e('Delivery Address', 'meals-db'); ?></h4>
            <p><label><input type="checkbox" id="delivery-address-toggle" <?php checked($delivery_address_enabled); ?> /> <?php esc_html_e('Delivery address different from home address', 'meals-db'); ?></label></p>
            <div id="delivery-address-fields" class="mealsdb-collapsible" <?php if (!$delivery_address_enabled) echo 'style="display:none;"'; ?>>
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

            <h4><?php esc_html_e('Alternate Contact', 'meals-db'); ?></h4>
            <p><label><input type="checkbox" id="alternate-contact-toggle" <?php checked($alt_contact_enabled); ?> /> <?php esc_html_e('Add alternate contact', 'meals-db'); ?></label></p>
            <div id="alternate-contact-fields" class="mealsdb-collapsible" <?php if (!$alt_contact_enabled) echo 'style="display:none;"'; ?>>
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

            <div class="mealsdb-step-controls">
                <button type="button" class="button mealsdb-prev-step" data-step-target="2"><?php esc_html_e('Back', 'meals-db'); ?></button>
                <button type="button" class="button button-primary mealsdb-next-step" data-step-target="4"><?php esc_html_e('Next', 'meals-db'); ?></button>
            </div>
        </div>

        <div class="mealsdb-step" data-step="4" data-step-title="Service &amp; Delivery" data-client-type="sdnb,veteran,private">
            <h3><?php esc_html_e('Step 4: Service &amp; Delivery', 'meals-db'); ?></h3>
            <table class="form-table">
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="payment_method"><?php esc_html_e('Payment Method *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="payment_method" id="payment_method" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['payment_method'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="required_start_date"><?php esc_html_e('Required Start Date *', 'meals-db'); ?></label></th>
                    <td><input type="date" name="required_start_date" id="required_start_date" class="mealsdb-datepicker" required data-base-required="1" value="<?php echo esc_attr($form_values['required_start_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="rate"><?php esc_html_e('Rate *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="rate" id="rate" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['rate'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="delivery_fee"><?php esc_html_e('Delivery Fee', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_fee" id="delivery_fee" class="regular-text" value="<?php echo esc_attr($form_values['delivery_fee'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="freezer_capacity"><?php esc_html_e('Freezer Capacity', 'meals-db'); ?></label></th>
                    <td><input type="text" name="freezer_capacity" id="freezer_capacity" class="regular-text" value="<?php echo esc_attr($form_values['freezer_capacity'] ?? ''); ?>" /></td>
                </tr>
                <?php
                $delivery_initials_value = $form_values['delivery_initials'] ?? '';
                $is_staff_client = strtolower($client_type ?? '') === 'staff';
                ?>
                <?php if (!$is_staff_client) : ?>
                <tr data-required-for="sdnb,veteran,private">
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
                <?php endif; ?>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_day"><?php esc_html_e('Delivery Day *', 'meals-db'); ?></label></th>
                    <td>
                        <?php $delivery_day_value = strtoupper($form_values['delivery_day'] ?? ''); ?>
                        <select name="delivery_day" id="delivery_day" class="regular-text" required data-base-required="1">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <?php foreach ($delivery_day_options as $option) : ?>
                                <?php $label = $format_enum_option_label($option); ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($delivery_day_value, strtoupper($option)); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_area_name"><?php esc_html_e('Delivery Area Name *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_area_name" id="delivery_area_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['delivery_area_name'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_area_zone"><?php esc_html_e('Delivery Area Zone *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_area_zone" id="delivery_area_zone" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['delivery_area_zone'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="ordering_frequency"><?php esc_html_e('Ordering Frequency *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="ordering_frequency" id="ordering_frequency" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['ordering_frequency'] ?? ''); ?>" /></td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="ordering_contact_method"><?php esc_html_e('Ordering Contact Method *', 'meals-db'); ?></label></th>
                    <td>
                        <?php $ordering_contact_method_value = strtoupper($form_values['ordering_contact_method'] ?? ''); ?>
                        <select name="ordering_contact_method" id="ordering_contact_method" class="regular-text" required data-base-required="1">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <?php foreach ($ordering_contact_method_options as $option) : ?>
                                <?php $label = $format_enum_option_label($option); ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($ordering_contact_method_value, strtoupper($option)); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr data-required-for="sdnb,veteran,private">
                    <th><label for="delivery_frequency"><?php esc_html_e('Delivery Frequency *', 'meals-db'); ?></label></th>
                    <td><input type="text" name="delivery_frequency" id="delivery_frequency" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['delivery_frequency'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="client_contribution"><?php esc_html_e('Client Contributions', 'meals-db'); ?></label></th>
                    <td><input type="text" name="client_contribution" id="client_contribution" class="regular-text" value="<?php echo esc_attr($form_values['client_contribution'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="individual_id"><?php esc_html_e('Individual ID', 'meals-db'); ?></label></th>
                    <td><input type="text" name="individual_id" id="individual_id" class="regular-text" value="<?php echo esc_attr($form_values['individual_id'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><?php esc_html_e('Gender', 'meals-db'); ?></th>
                    <td>
                        <?php $gender = $form_values['gender'] ?? ''; ?>
                        <label><input type="radio" name="gender" value="Male" <?php checked($gender, 'Male'); ?> /> <?php esc_html_e('Male', 'meals-db'); ?></label>
                        <label><input type="radio" name="gender" value="Female" <?php checked($gender, 'Female'); ?> /> <?php esc_html_e('Female', 'meals-db'); ?></label>
                        <label><input type="radio" name="gender" value="Other" <?php checked($gender, 'Other'); ?> /> <?php esc_html_e('Other', 'meals-db'); ?></label>
                    </td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="service_center_charged"><?php esc_html_e('Service Center Charged', 'meals-db'); ?></label></th>
                    <td><input type="text" name="service_center_charged" id="service_center_charged" class="regular-text" value="<?php echo esc_attr($form_values['service_center_charged'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="vendor_number"><?php esc_html_e('Vendor #', 'meals-db'); ?></label></th>
                    <td><input type="text" name="vendor_number" id="vendor_number" class="regular-text" value="<?php echo esc_attr($form_values['vendor_number'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="service_id"><?php esc_html_e('Service ID', 'meals-db'); ?></label></th>
                    <td><input type="text" name="service_id" id="service_id" class="regular-text" value="<?php echo esc_attr($form_values['service_id'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="requisition_id"><?php esc_html_e('Requisition ID', 'meals-db'); ?></label></th>
                    <td><input type="text" name="requisition_id" id="requisition_id" class="regular-text" value="<?php echo esc_attr($form_values['requisition_id'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="service_zone"><?php esc_html_e('Service Name Zone', 'meals-db'); ?></label></th>
                    <td>
                        <?php $service_zone_value = strtoupper($form_values['service_zone'] ?? ''); ?>
                        <select name="service_zone" id="service_zone" class="regular-text">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <?php foreach ($service_zone_options as $option) : ?>
                                <?php $label = $format_enum_option_label($option); ?>
                                <option value="<?php echo esc_attr($option); ?>" <?php selected($service_zone_value, strtoupper($option)); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="meal_type"><?php esc_html_e('Meal Type', 'meals-db'); ?></label></th>
                    <td>
                        <?php $meal_type = $form_values['meal_type'] ?? ''; ?>
                        <select name="meal_type" id="meal_type">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <option value="1" <?php selected($meal_type, '1'); ?>>1 <?php esc_html_e('Course', 'meals-db'); ?></option>
                            <option value="2" <?php selected($meal_type, '2'); ?>>2 <?php esc_html_e('Course', 'meals-db'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="requisition_period"><?php esc_html_e('Requisition Period', 'meals-db'); ?></label></th>
                    <td>
                        <?php $requisition_period = $form_values['requisition_period'] ?? ''; ?>
                        <select name="requisition_period" id="requisition_period">
                            <option value=""><?php esc_html_e('Select…', 'meals-db'); ?></option>
                            <option value="Day" <?php selected($requisition_period, 'Day'); ?>><?php esc_html_e('Day', 'meals-db'); ?></option>
                            <option value="Week" <?php selected($requisition_period, 'Week'); ?>><?php esc_html_e('Week', 'meals-db'); ?></option>
                            <option value="Month" <?php selected($requisition_period, 'Month'); ?>><?php esc_html_e('Month', 'meals-db'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="service_commence_date"><?php esc_html_e('Service Commence Date', 'meals-db'); ?></label></th>
                    <td><input type="date" name="service_commence_date" id="service_commence_date" class="mealsdb-datepicker" value="<?php echo esc_attr($form_values['service_commence_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="expected_termination_date"><?php esc_html_e('Expected Termination Date', 'meals-db'); ?></label></th>
                    <td><input type="date" name="expected_termination_date" id="expected_termination_date" class="mealsdb-datepicker" value="<?php echo esc_attr($form_values['expected_termination_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="initial_renewal_date"><?php esc_html_e('Initial Renewal Termination Date', 'meals-db'); ?></label></th>
                    <td><input type="date" name="initial_renewal_date" id="initial_renewal_date" class="mealsdb-datepicker" value="<?php echo esc_attr($form_values['initial_renewal_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="most_recent_renewal_date"><?php esc_html_e('Most Recent Renewal Termination Date', 'meals-db'); ?></label></th>
                    <td><input type="date" name="most_recent_renewal_date" id="most_recent_renewal_date" class="mealsdb-datepicker" value="<?php echo esc_attr($form_values['most_recent_renewal_date'] ?? ''); ?>" /></td>
                </tr>
            </table>
            <div class="mealsdb-step-controls">
                <button type="button" class="button mealsdb-prev-step" data-step-target="3"><?php esc_html_e('Back', 'meals-db'); ?></button>
                <button type="button" class="button button-primary mealsdb-next-step" data-step-target="5"><?php esc_html_e('Next', 'meals-db'); ?></button>
            </div>
        </div>

        <div class="mealsdb-step" data-step-title="Notes &amp; Submit" data-step="5">
            <h3><?php esc_html_e('Step 5: Notes &amp; Submit', 'meals-db'); ?></h3>
            <table class="form-table">
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="diet_concerns"><?php esc_html_e('Dietary Concerns', 'meals-db'); ?></label></th>
                    <td><textarea name="diet_concerns" id="diet_concerns" rows="4" class="large-text"><?php echo esc_textarea($form_values['diet_concerns'] ?? ''); ?></textarea></td>
                </tr>
                <tr data-client-type="sdnb,veteran,private">
                    <th><label for="client_comments"><?php esc_html_e('Customer Comments', 'meals-db'); ?></label></th>
                    <td><textarea name="client_comments" id="client_comments" rows="4" class="large-text"><?php echo esc_textarea($form_values['client_comments'] ?? ''); ?></textarea></td>
                </tr>
            </table>

            <div class="mealsdb-step-controls">
                <button type="button" class="button mealsdb-prev-step" data-step-target="4"><?php esc_html_e('Back', 'meals-db'); ?></button>
                <button type="submit" class="button-primary"><?php echo esc_html($submit_label); ?></button>
                <?php if ($show_draft_button) : ?>
                    <button type="button" id="mealsdb-save-draft" class="button-secondary"><?php esc_html_e('Save to Draft', 'meals-db'); ?></button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>
