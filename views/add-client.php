<?php
MealsDB_Permissions::enforce();

// On submission, validate and save
$errors = [];
$success = false;
$form_values = [];
$resumed_draft_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_admin_referer('mealsdb_nonce', 'mealsdb_nonce_field');

    $is_resume = isset($_POST['resume_draft']) && $_POST['resume_draft'] === '1';
    $resumed_draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;

    $form_values = MealsDB_Client_Form::prepare_form_defaults($_POST);

    if (!$is_resume) {
        $validation = MealsDB_Client_Form::validate($_POST);
        $form_values = $validation['sanitized'] ?? $form_values;

        if ($validation['valid']) {
            $saved = MealsDB_Client_Form::save($_POST);
            if ($saved) {
                $success = true;
                $form_values = [];
                $resumed_draft_id = 0;
            } else {
                $errors[] = 'Database error occurred.';
            }
        } else {
            $errors = $validation['errors'];
            $draft_id_for_retry = $resumed_draft_id > 0 ? $resumed_draft_id : null;
            if (!MealsDB_Client_Form::save_draft($form_values, $draft_id_for_retry)) { // fallback save
                $errors[] = 'Unable to save draft copy. Please try again or contact an administrator.';
            }
        }
    }
}

$client_type = $form_values['customer_type'] ?? '';
$initial_step = $client_type ? 2 : 1;

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

<div class="wrap">
    <h2>Add New Client</h2>

    <?php include __DIR__ . '/partials/status-notice.php'; ?>

    <form method="post" id="mealsdb-client-form" data-initial-step="<?php echo esc_attr($initial_step); ?>">
        <?php wp_nonce_field('mealsdb_nonce', 'mealsdb_nonce_field'); ?>

        <?php if ($resumed_draft_id > 0) : ?>
            <input type="hidden" name="draft_id" value="<?php echo esc_attr($resumed_draft_id); ?>" />
        <?php endif; ?>

        <ol class="mealsdb-step-indicator">
            <li data-step="1">Client Type</li>
            <li data-step="2">Client &amp; Case Details</li>
            <li data-step="3">Addresses &amp; Contacts</li>
            <li data-step="4">Service &amp; Delivery</li>
            <li data-step="5">Notes &amp; Submit</li>
        </ol>

        <div class="mealsdb-step" data-step="1" data-step-title="Client Type">
            <h3>Step 1: Select Client Type</h3>
            <p>Select the client type to load only the fields required for that program.</p>
            <table class="form-table">
                <tr>
                    <th><label for="mealsdb-client-type-initial">Client Type *</label></th>
                    <td>
                        <?php $initial_type = $client_type; ?>
                        <select id="mealsdb-client-type-initial">
                            <option value="">Select…</option>
                            <option value="SDNB" <?php selected($initial_type, 'SDNB'); ?>>SDNB</option>
                            <option value="Veteran" <?php selected($initial_type, 'Veteran'); ?>>Veteran</option>
                            <option value="Private" <?php selected($initial_type, 'Private'); ?>>Private</option>
                        </select>
                        <p class="description">Client fields will adjust automatically when the type changes.</p>
                    </td>
                </tr>
            </table>
            <div class="mealsdb-step-controls">
                <button type="button" class="button button-primary mealsdb-next-step" data-step-target="2" disabled>Next</button>
            </div>
        </div>

        <div class="mealsdb-step" data-step="2" data-step-title="Client &amp; Case Details">
            <h3>Step 2: Client &amp; Case Details</h3>
            <table class="form-table">
                <tr>
                    <th><label for="customer_type">Customer Type *</label></th>
                    <td>
                        <?php $current_type = $client_type; ?>
                        <select name="customer_type" id="customer_type" required data-base-required="1">
                            <option value="">Select…</option>
                            <option value="SDNB" <?php selected($current_type, 'SDNB'); ?>>SDNB</option>
                            <option value="Veteran" <?php selected($current_type, 'Veteran'); ?>>Veteran</option>
                            <option value="Private" <?php selected($current_type, 'Private'); ?>>Private</option>
                        </select>
                        <p class="description">Changing this selection will update the fields shown in other steps.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="first_name">First Name *</label></th>
                    <td><input type="text" name="first_name" id="first_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['first_name'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="last_name">Last Name *</label></th>
                    <td><input type="text" name="last_name" id="last_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['last_name'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="client_email">Client Email</label></th>
                    <td><input type="email" name="client_email" id="client_email" class="regular-text" value="<?php echo esc_attr($form_values['client_email'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="phone_primary">Phone Number *</label></th>
                    <td><input type="text" name="phone_primary" id="phone_primary" class="regular-text phone-mask" placeholder="(555)-555-5555" required data-base-required="1" value="<?php echo esc_attr($form_values['phone_primary'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="phone_secondary">Second Phone Number</label></th>
                    <td><input type="text" name="phone_secondary" id="phone_secondary" class="regular-text phone-mask" placeholder="(555)-555-5555" value="<?php echo esc_attr($form_values['phone_secondary'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="do_not_call_client_phone">Do Not Call Client's Phone</label></th>
                    <td><label><input type="checkbox" name="do_not_call_client_phone" id="do_not_call_client_phone" value="1" <?php checked($form_values['do_not_call_client_phone'] ?? '0', '1'); ?> /> Call alternate contact instead</label></td>
                </tr>
                <tr data-client-type="sdnb,veteran" data-required-for="sdnb,veteran">
                    <th><label for="open_date">Open Date *</label></th>
                    <td><input type="date" name="open_date" id="open_date" class="mealsdb-datepicker" data-base-required="1" value="<?php echo esc_attr($form_values['open_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="assigned_social_worker">Social Worker Name</label></th>
                    <td><input type="text" name="assigned_social_worker" id="assigned_social_worker" class="regular-text" value="<?php echo esc_attr($form_values['assigned_social_worker'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="social_worker_email">Social Worker Email Address</label></th>
                    <td><input type="email" name="social_worker_email" id="social_worker_email" class="regular-text" value="<?php echo esc_attr($form_values['social_worker_email'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran">
                    <th><label for="birth_date">Date of Birth</label></th>
                    <td><input type="date" name="birth_date" id="birth_date" class="mealsdb-datepicker" value="<?php echo esc_attr($form_values['birth_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb,veteran" data-required-for="sdnb,veteran">
                    <th><label for="units"># of Units *</label></th>
                    <td><input type="number" name="units" id="units" class="small-text" min="1" max="31" data-base-required="1" value="<?php echo esc_attr($form_values['units'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="veteran" data-required-for="veteran">
                    <th><label for="vet_health_card">Veteran Health Identification Card # *</label></th>
                    <td><input type="text" name="vet_health_card" id="vet_health_card" class="regular-text" data-base-required="1" value="<?php echo esc_attr($form_values['vet_health_card'] ?? ''); ?>" /></td>
                </tr>
            </table>
            <div class="mealsdb-step-controls">
                <button type="button" class="button mealsdb-prev-step" data-step-target="1">Back</button>
                <button type="button" class="button button-primary mealsdb-next-step" data-step-target="3">Next</button>
            </div>
        </div>

        <div class="mealsdb-step" data-step="3" data-step-title="Addresses &amp; Contacts">
            <h3>Step 3: Addresses &amp; Contacts</h3>
            <table class="form-table">
                <tr>
                    <th><label for="address_street_number">Street # *</label></th>
                    <td><input type="text" name="address_street_number" id="address_street_number" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['address_street_number'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="address_street_name">Street Name *</label></th>
                    <td><input type="text" name="address_street_name" id="address_street_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['address_street_name'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="address_unit">Apt # *</label></th>
                    <td><input type="text" name="address_unit" id="address_unit" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['address_unit'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="address_city">City *</label></th>
                    <td><input type="text" name="address_city" id="address_city" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['address_city'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="address_province">Province *</label></th>
                    <td><input type="text" name="address_province" id="address_province" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['address_province'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="address_postal">Postal Code *</label></th>
                    <td><input type="text" name="address_postal" id="address_postal" class="regular-text postal-mask" maxlength="7" placeholder="A1A 1A1" required data-base-required="1" value="<?php echo esc_attr($form_values['address_postal'] ?? ''); ?>" /></td>
                </tr>
            </table>

            <h4>Delivery Address</h4>
            <p><label><input type="checkbox" id="delivery-address-toggle" <?php checked($delivery_address_enabled); ?> /> Delivery address different from home address</label></p>
            <div id="delivery-address-fields" class="mealsdb-collapsible" <?php if (!$delivery_address_enabled) echo 'style="display:none;"'; ?>>
                <table class="form-table">
                    <tr>
                        <th><label for="delivery_address_street_number">Street #</label></th>
                        <td><input type="text" name="delivery_address_street_number" id="delivery_address_street_number" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_street_number'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="delivery_address_street_name">Street Name</label></th>
                        <td><input type="text" name="delivery_address_street_name" id="delivery_address_street_name" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_street_name'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="delivery_address_unit">Apt #</label></th>
                        <td><input type="text" name="delivery_address_unit" id="delivery_address_unit" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_unit'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="delivery_address_city">City</label></th>
                        <td><input type="text" name="delivery_address_city" id="delivery_address_city" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_city'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="delivery_address_province">Province</label></th>
                        <td><input type="text" name="delivery_address_province" id="delivery_address_province" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_province'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="delivery_address_postal">Postal Code</label></th>
                        <td><input type="text" name="delivery_address_postal" id="delivery_address_postal" class="regular-text postal-mask" maxlength="7" placeholder="A1A 1A1" value="<?php echo esc_attr($form_values['delivery_address_postal'] ?? ''); ?>" /></td>
                    </tr>
                </table>
            </div>

            <h4>Alternate Contact</h4>
            <p><label><input type="checkbox" id="alternate-contact-toggle" <?php checked($alt_contact_enabled); ?> /> Add alternate contact</label></p>
            <div id="alternate-contact-fields" class="mealsdb-collapsible" <?php if (!$alt_contact_enabled) echo 'style="display:none;"'; ?>>
                <input type="hidden" name="alt_contact_name" id="alt_contact_name" value="<?php echo esc_attr($alt_contact_name); ?>" />
                <table class="form-table">
                    <tr>
                        <th><label for="alt_contact_first_name">First Name</label></th>
                        <td><input type="text" id="alt_contact_first_name" class="regular-text" value="<?php echo esc_attr($alt_contact_first); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="alt_contact_last_name">Last Name</label></th>
                        <td><input type="text" id="alt_contact_last_name" class="regular-text" value="<?php echo esc_attr($alt_contact_last); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="alt_contact_phone_primary">Phone Number</label></th>
                        <td><input type="text" name="alt_contact_phone_primary" id="alt_contact_phone_primary" class="regular-text phone-mask" placeholder="(555)-555-5555" value="<?php echo esc_attr($form_values['alt_contact_phone_primary'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="alt_contact_phone_secondary">Second Phone Number</label></th>
                        <td><input type="text" name="alt_contact_phone_secondary" id="alt_contact_phone_secondary" class="regular-text phone-mask" placeholder="(555)-555-5555" value="<?php echo esc_attr($form_values['alt_contact_phone_secondary'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="alt_contact_email">Contact Email</label></th>
                        <td><input type="email" name="alt_contact_email" id="alt_contact_email" class="regular-text" value="<?php echo esc_attr($form_values['alt_contact_email'] ?? ''); ?>" /></td>
                    </tr>
                </table>
            </div>

            <div class="mealsdb-step-controls">
                <button type="button" class="button mealsdb-prev-step" data-step-target="2">Back</button>
                <button type="button" class="button button-primary mealsdb-next-step" data-step-target="4">Next</button>
            </div>
        </div>

        <div class="mealsdb-step" data-step="4" data-step-title="Service &amp; Delivery">
            <h3>Step 4: Service &amp; Delivery</h3>
            <table class="form-table">
                <tr>
                    <th><label for="payment_method">Payment Method *</label></th>
                    <td><input type="text" name="payment_method" id="payment_method" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['payment_method'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="required_start_date">Required Start Date *</label></th>
                    <td><input type="date" name="required_start_date" id="required_start_date" class="mealsdb-datepicker" required data-base-required="1" value="<?php echo esc_attr($form_values['required_start_date'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="rate">Rate *</label></th>
                    <td><input type="text" name="rate" id="rate" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['rate'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="delivery_fee">Delivery Fee</label></th>
                    <td><input type="text" name="delivery_fee" id="delivery_fee" class="regular-text" value="<?php echo esc_attr($form_values['delivery_fee'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="freezer_capacity">Freezer Capacity</label></th>
                    <td><input type="text" name="freezer_capacity" id="freezer_capacity" class="regular-text" value="<?php echo esc_attr($form_values['freezer_capacity'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="delivery_initials">Initials for Delivery *</label></th>
                    <td><input type="text" name="delivery_initials" id="delivery_initials" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['delivery_initials'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="delivery_day">Delivery Day *</label></th>
                    <td><input type="text" name="delivery_day" id="delivery_day" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['delivery_day'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="delivery_area_name">Delivery Area Name *</label></th>
                    <td><input type="text" name="delivery_area_name" id="delivery_area_name" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['delivery_area_name'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="delivery_area_zone">Delivery Area Zone *</label></th>
                    <td><input type="text" name="delivery_area_zone" id="delivery_area_zone" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['delivery_area_zone'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="ordering_frequency">Ordering Frequency *</label></th>
                    <td><input type="text" name="ordering_frequency" id="ordering_frequency" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['ordering_frequency'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="ordering_contact_method">Ordering Contact Method *</label></th>
                    <td><input type="text" name="ordering_contact_method" id="ordering_contact_method" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['ordering_contact_method'] ?? ''); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="delivery_frequency">Delivery Frequency *</label></th>
                    <td><input type="text" name="delivery_frequency" id="delivery_frequency" class="regular-text" required data-base-required="1" value="<?php echo esc_attr($form_values['delivery_frequency'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="client_contribution">Client Contributions</label></th>
                    <td><input type="text" name="client_contribution" id="client_contribution" class="regular-text" value="<?php echo esc_attr($form_values['client_contribution'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="individual_id">Individual ID</label></th>
                    <td><input type="text" name="individual_id" id="individual_id" class="regular-text" value="<?php echo esc_attr($form_values['individual_id'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th>Gender</th>
                    <td>
                        <?php $gender = $form_values['gender'] ?? ''; ?>
                        <label><input type="radio" name="gender" value="Male" <?php checked($gender, 'Male'); ?> /> Male</label>
                        <label><input type="radio" name="gender" value="Female" <?php checked($gender, 'Female'); ?> /> Female</label>
                        <label><input type="radio" name="gender" value="Other" <?php checked($gender, 'Other'); ?> /> Other</label>
                    </td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="service_center_charged">Service Center Charged</label></th>
                    <td><input type="text" name="service_center_charged" id="service_center_charged" class="regular-text" value="<?php echo esc_attr($form_values['service_center_charged'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="vendor_number">Vendor #</label></th>
                    <td><input type="text" name="vendor_number" id="vendor_number" class="regular-text" value="<?php echo esc_attr($form_values['vendor_number'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="service_id">Service ID</label></th>
                    <td><input type="text" name="service_id" id="service_id" class="regular-text" value="<?php echo esc_attr($form_values['service_id'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="requisition_id">Requisition ID</label></th>
                    <td><input type="text" name="requisition_id" id="requisition_id" class="regular-text" value="<?php echo esc_attr($form_values['requisition_id'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="service_zone">Service Name Zone</label></th>
                    <td><input type="text" name="service_zone" id="service_zone" class="regular-text" value="<?php echo esc_attr($form_values['service_zone'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="meal_type">Meal Type</label></th>
                    <td>
                        <?php $meal_type = $form_values['meal_type'] ?? ''; ?>
                        <select name="meal_type" id="meal_type">
                            <option value="">Select…</option>
                            <option value="1" <?php selected($meal_type, '1'); ?>>1 Course</option>
                            <option value="2" <?php selected($meal_type, '2'); ?>>2 Course</option>
                        </select>
                    </td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="requisition_period">Requisition Period</label></th>
                    <td>
                        <?php $requisition_period = $form_values['requisition_period'] ?? ''; ?>
                        <select name="requisition_period" id="requisition_period">
                            <option value="">Select…</option>
                            <option value="Day" <?php selected($requisition_period, 'Day'); ?>>Day</option>
                            <option value="Week" <?php selected($requisition_period, 'Week'); ?>>Week</option>
                            <option value="Month" <?php selected($requisition_period, 'Month'); ?>>Month</option>
                        </select>
                    </td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="service_commence_date">Service Commence Date</label></th>
                    <td><input type="date" name="service_commence_date" id="service_commence_date" class="mealsdb-datepicker" value="<?php echo esc_attr($form_values['service_commence_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="expected_termination_date">Expected Termination Date</label></th>
                    <td><input type="date" name="expected_termination_date" id="expected_termination_date" class="mealsdb-datepicker" value="<?php echo esc_attr($form_values['expected_termination_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="initial_renewal_date">Initial Renewal Termination Date</label></th>
                    <td><input type="date" name="initial_renewal_date" id="initial_renewal_date" class="mealsdb-datepicker" value="<?php echo esc_attr($form_values['initial_renewal_date'] ?? ''); ?>" /></td>
                </tr>
                <tr data-client-type="sdnb">
                    <th><label for="most_recent_renewal_date">Most Recent Renewal Termination Date</label></th>
                    <td><input type="date" name="most_recent_renewal_date" id="most_recent_renewal_date" class="mealsdb-datepicker" value="<?php echo esc_attr($form_values['most_recent_renewal_date'] ?? ''); ?>" /></td>
                </tr>
            </table>
            <div class="mealsdb-step-controls">
                <button type="button" class="button mealsdb-prev-step" data-step-target="3">Back</button>
                <button type="button" class="button button-primary mealsdb-next-step" data-step-target="5">Next</button>
            </div>
        </div>

        <div class="mealsdb-step" data-step="5" data-step-title="Notes &amp; Submit">
            <h3>Step 5: Notes &amp; Submit</h3>
            <table class="form-table">
                <tr>
                    <th><label for="diet_concerns">Dietary Concerns</label></th>
                    <td><textarea name="diet_concerns" id="diet_concerns" rows="4" class="large-text"><?php echo esc_textarea($form_values['diet_concerns'] ?? ''); ?></textarea></td>
                </tr>
                <tr>
                    <th><label for="client_comments">Customer Comments</label></th>
                    <td><textarea name="client_comments" id="client_comments" rows="4" class="large-text"><?php echo esc_textarea($form_values['client_comments'] ?? ''); ?></textarea></td>
                </tr>
            </table>

            <div class="mealsdb-step-controls">
                <button type="button" class="button mealsdb-prev-step" data-step-target="4">Back</button>
                <button type="submit" class="button-primary">Submit</button>
                <button type="button" id="mealsdb-save-draft" class="button-secondary">Save to Draft</button>
            </div>
        </div>
    </form>
</div>
