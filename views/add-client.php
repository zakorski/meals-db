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
?>

<div class="wrap">
    <h2>Add New Client</h2>

    <?php include __DIR__ . '/partials/status-notice.php'; ?>

    <form method="post" id="mealsdb-client-form">
        <?php wp_nonce_field('mealsdb_nonce', 'mealsdb_nonce_field'); ?>

        <?php if ($resumed_draft_id > 0) : ?>
            <input type="hidden" name="draft_id" value="<?php echo esc_attr($resumed_draft_id); ?>" />
        <?php endif; ?>

        <h3>Client Details</h3>
        <table class="form-table">
            <tr>
                <th><label for="first_name">First Name *</label></th>
                <td><input type="text" name="first_name" required class="regular-text" value="<?php echo esc_attr($form_values['first_name'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="last_name">Last Name *</label></th>
                <td><input type="text" name="last_name" required class="regular-text" value="<?php echo esc_attr($form_values['last_name'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="customer_type">Customer Type *</label></th>
                <td>
                    <?php $customer_type = $form_values['customer_type'] ?? ''; ?>
                    <select name="customer_type" required>
                        <option value="" <?php selected($customer_type, ''); ?>>Select…</option>
                        <option value="SDNB" <?php selected($customer_type, 'SDNB'); ?>>SDNB</option>
                        <option value="Veteran" <?php selected($customer_type, 'Veteran'); ?>>Veteran</option>
                        <option value="Private" <?php selected($customer_type, 'Private'); ?>>Private</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="open_date">Open Date *</label></th>
                <td><input type="text" name="open_date" required class="regular-text mealsdb-datepicker" value="<?php echo esc_attr($form_values['open_date'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="birth_date">Date of Birth</label></th>
                <td><input type="text" name="birth_date" class="regular-text mealsdb-datepicker" value="<?php echo esc_attr($form_values['birth_date'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="gender">Gender</label></th>
                <td>
                    <?php $selected_gender = $form_values['gender'] ?? ''; ?>
                    <label><input type="radio" name="gender" value="Male" <?php checked($selected_gender, 'Male'); ?> /> Male</label>
                    <label><input type="radio" name="gender" value="Female" <?php checked($selected_gender, 'Female'); ?> /> Female</label>
                    <label><input type="radio" name="gender" value="Other" <?php checked($selected_gender, 'Other'); ?> /> Other</label>
                </td>
            </tr>
            <tr>
                <th><label for="client_email">Client Email Address</label></th>
                <td><input type="text" name="client_email" class="regular-text" value="<?php echo esc_attr($form_values['client_email'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="phone_primary">Client Phone #1 *</label></th>
                <td><input type="text" name="phone_primary" required placeholder="(555)-555-5555" class="regular-text phone-mask" value="<?php echo esc_attr($form_values['phone_primary'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="phone_secondary">Client Phone #2</label></th>
                <td><input type="text" name="phone_secondary" placeholder="(555)-555-5555" class="regular-text phone-mask" value="<?php echo esc_attr($form_values['phone_secondary'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="do_not_call_client_phone">Do Not Call Client's Phone (call alt.)</label></th>
                <td>
                    <?php $do_not_call = $form_values['do_not_call_client_phone'] ?? '1'; ?>
                    <label><input type="radio" name="do_not_call_client_phone" value="1" <?php checked($do_not_call, '1'); ?> /> Yes</label>
                    <label><input type="radio" name="do_not_call_client_phone" value="0" <?php checked($do_not_call, '0'); ?> /> No</label>
                </td>
            </tr>
            <tr>
                <th><label for="assigned_social_worker">Assigned Social Worker</label></th>
                <td><input type="text" name="assigned_social_worker" class="regular-text" value="<?php echo esc_attr($form_values['assigned_social_worker'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="social_worker_email">Social Worker Email</label></th>
                <td><input type="text" name="social_worker_email" class="regular-text" value="<?php echo esc_attr($form_values['social_worker_email'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="individual_id">Individual ID</label></th>
                <td><input type="text" name="individual_id" class="regular-text" value="<?php echo esc_attr($form_values['individual_id'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="requisition_id">Requisition ID</label></th>
                <td><input type="text" name="requisition_id" class="regular-text" value="<?php echo esc_attr($form_values['requisition_id'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="vet_health_card">Vet Health Identification Card #</label></th>
                <td><input type="text" name="vet_health_card" class="regular-text" value="<?php echo esc_attr($form_values['vet_health_card'] ?? ''); ?>" /></td>
            </tr>
        </table>

        <h3>Primary Address</h3>
        <table class="form-table">
            <tr>
                <th><label for="address_street_number">Street # *</label></th>
                <td><input type="text" name="address_street_number" required class="regular-text" value="<?php echo esc_attr($form_values['address_street_number'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="address_street_name">Street Name *</label></th>
                <td><input type="text" name="address_street_name" required class="regular-text" value="<?php echo esc_attr($form_values['address_street_name'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="address_unit">Apt # *</label></th>
                <td><input type="text" name="address_unit" required class="regular-text" value="<?php echo esc_attr($form_values['address_unit'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="address_city">City *</label></th>
                <td><input type="text" name="address_city" required class="regular-text" value="<?php echo esc_attr($form_values['address_city'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="address_province">Province *</label></th>
                <td><input type="text" name="address_province" required class="regular-text" value="<?php echo esc_attr($form_values['address_province'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="address_postal">Postal Code *</label></th>
                <td><input type="text" name="address_postal" required placeholder="A1A1A1" maxlength="6" pattern="[A-Za-z][0-9][A-Za-z][0-9][A-Za-z][0-9]" class="regular-text postal-mask" value="<?php echo esc_attr($form_values['address_postal'] ?? ''); ?>" /></td>
            </tr>
        </table>

        <h3>Delivery Address</h3>
        <table class="form-table">
            <tr>
                <th><label for="delivery_address_street_number">Street #</label></th>
                <td><input type="text" name="delivery_address_street_number" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_street_number'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="delivery_address_street_name">Street Name</label></th>
                <td><input type="text" name="delivery_address_street_name" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_street_name'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="delivery_address_unit">Apt #</label></th>
                <td><input type="text" name="delivery_address_unit" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_unit'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="delivery_address_city">City</label></th>
                <td><input type="text" name="delivery_address_city" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_city'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="delivery_address_province">Province</label></th>
                <td><input type="text" name="delivery_address_province" class="regular-text" value="<?php echo esc_attr($form_values['delivery_address_province'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="delivery_address_postal">Postal Code</label></th>
                <td><input type="text" name="delivery_address_postal" placeholder="A1A1A1" maxlength="6" pattern="[A-Za-z][0-9][A-Za-z][0-9][A-Za-z][0-9]" class="regular-text postal-mask" value="<?php echo esc_attr($form_values['delivery_address_postal'] ?? ''); ?>" /></td>
            </tr>
        </table>

        <h3>Alternate Contact</h3>
        <table class="form-table">
            <tr>
                <th><label for="alt_contact_name">Alternate Contact Name</label></th>
                <td><input type="text" name="alt_contact_name" class="regular-text" value="<?php echo esc_attr($form_values['alt_contact_name'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="alt_contact_phone_primary">Alternate Contact Phone #1</label></th>
                <td><input type="text" name="alt_contact_phone_primary" placeholder="(555)-555-5555" class="regular-text phone-mask" value="<?php echo esc_attr($form_values['alt_contact_phone_primary'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="alt_contact_phone_secondary">Alternate Contact Phone #2</label></th>
                <td><input type="text" name="alt_contact_phone_secondary" placeholder="(555)-555-5555" class="regular-text phone-mask" value="<?php echo esc_attr($form_values['alt_contact_phone_secondary'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="alt_contact_email">Alternate Contact Email</label></th>
                <td><input type="text" name="alt_contact_email" class="regular-text" value="<?php echo esc_attr($form_values['alt_contact_email'] ?? ''); ?>" /></td>
            </tr>
        </table>

        <h3>Service Details</h3>
        <table class="form-table">
            <tr>
                <th><label for="service_center">Service Center</label></th>
                <td><input type="text" name="service_center" class="regular-text" value="<?php echo esc_attr($form_values['service_center'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="service_center_charged">Service Center Charged</label></th>
                <td><input type="text" name="service_center_charged" class="regular-text" value="<?php echo esc_attr($form_values['service_center_charged'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="vendor_number">Vendor #</label></th>
                <td><input type="text" name="vendor_number" class="regular-text" value="<?php echo esc_attr($form_values['vendor_number'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="service_id">Service ID</label></th>
                <td><input type="text" name="service_id" class="regular-text" value="<?php echo esc_attr($form_values['service_id'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="service_zone">Service Name Zone (A or B)</label></th>
                <td>
                    <?php $service_zone = $form_values['service_zone'] ?? ''; ?>
                    <label><input type="radio" name="service_zone" value="A" <?php checked($service_zone, 'A'); ?> /> A</label>
                    <label><input type="radio" name="service_zone" value="B" <?php checked($service_zone, 'B'); ?> /> B</label>
                </td>
            </tr>
            <tr>
                <th><label for="service_course">Service Name Course (1 or 2)</label></th>
                <td>
                    <?php $service_course = $form_values['service_course'] ?? ''; ?>
                    <label><input type="radio" name="service_course" value="1" <?php checked($service_course, '1'); ?> /> 1</label>
                    <label><input type="radio" name="service_course" value="2" <?php checked($service_course, '2'); ?> /> 2</label>
                </td>
            </tr>
            <tr>
                <th><label for="payment_method">Payment Method *</label></th>
                <td>
                    <?php $payment_method = $form_values['payment_method'] ?? ''; ?>
                    <select name="payment_method" required>
                        <option value="" <?php selected($payment_method, ''); ?>>Select…</option>
                        <option value="Invoice" <?php selected($payment_method, 'Invoice'); ?>>Invoice</option>
                        <option value="E-Transfer" <?php selected($payment_method, 'E-Transfer'); ?>>E-Transfer</option>
                        <option value="Cash" <?php selected($payment_method, 'Cash'); ?>>Cash</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="required_start_date">Required Start Date</label></th>
                <td><input type="text" name="required_start_date" class="regular-text mealsdb-datepicker" value="<?php echo esc_attr($form_values['required_start_date'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="service_commence_date">Service Commence Date</label></th>
                <td><input type="text" name="service_commence_date" class="regular-text mealsdb-datepicker" value="<?php echo esc_attr($form_values['service_commence_date'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="expected_termination_date">Expected Termination Date</label></th>
                <td><input type="text" name="expected_termination_date" class="regular-text mealsdb-datepicker" value="<?php echo esc_attr($form_values['expected_termination_date'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="initial_renewal_date">Initial Renewal</label></th>
                <td><input type="text" name="initial_renewal_date" class="regular-text mealsdb-datepicker" value="<?php echo esc_attr($form_values['initial_renewal_date'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="termination_date">Termination Date</label></th>
                <td><input type="text" name="termination_date" class="regular-text mealsdb-datepicker" value="<?php echo esc_attr($form_values['termination_date'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="most_recent_renewal_date">Most Recent Renewal</label></th>
                <td><input type="text" name="most_recent_renewal_date" class="regular-text mealsdb-datepicker" value="<?php echo esc_attr($form_values['most_recent_renewal_date'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="units"># of units (1 - 31)</label></th>
                <td><input type="text" name="units" class="small-text" value="<?php echo esc_attr($form_values['units'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="meal_type">Meal Type</label></th>
                <td>
                    <?php $meal_type = $form_values['meal_type'] ?? ''; ?>
                    <select name="meal_type">
                        <option value="" <?php selected($meal_type, ''); ?>>Select…</option>
                        <option value="1" <?php selected($meal_type, '1'); ?>>Main</option>
                        <option value="2" <?php selected($meal_type, '2'); ?>>Main &amp; Side</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="requisition_period">Requisition Time Period</label></th>
                <td>
                    <?php $requisition_period = $form_values['requisition_period'] ?? ''; ?>
                    <select name="requisition_period">
                        <option value="" <?php selected($requisition_period, ''); ?>>Select…</option>
                        <option value="day" <?php selected(strtolower($requisition_period), 'day'); ?>>Day</option>
                        <option value="week" <?php selected(strtolower($requisition_period), 'week'); ?>>Week</option>
                        <option value="month" <?php selected(strtolower($requisition_period), 'month'); ?>>Month</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="rate">Rate *</label></th>
                <td><input type="text" name="rate" required class="regular-text" value="<?php echo esc_attr($form_values['rate'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="client_contribution">Client Contribution</label></th>
                <td><input type="text" name="client_contribution" class="regular-text" value="<?php echo esc_attr($form_values['client_contribution'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="delivery_fee">Delivery Fee</label></th>
                <td><input type="text" name="delivery_fee" class="regular-text" value="<?php echo esc_attr($form_values['delivery_fee'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="freezer_capacity">Freezer Capacity</label></th>
                <td><input type="text" name="freezer_capacity" class="regular-text" value="<?php echo esc_attr($form_values['freezer_capacity'] ?? ''); ?>" /></td>
            </tr>
        </table>

        <h3>Delivery Preferences</h3>
        <table class="form-table">
            <tr>
                <th><label for="delivery_day">Delivery Day *</label></th>
                <td>
                    <?php $delivery_day = $form_values['delivery_day'] ?? ''; ?>
                    <select name="delivery_day" required>
                        <option value="" <?php selected($delivery_day, ''); ?>>Select…</option>
                        <option value="Wednesday AM" <?php selected($delivery_day, 'Wednesday AM'); ?>>Wednesday AM</option>
                        <option value="Wednesday PM" <?php selected($delivery_day, 'Wednesday PM'); ?>>Wednesday PM</option>
                        <option value="Thursday AM" <?php selected($delivery_day, 'Thursday AM'); ?>>Thursday AM</option>
                        <option value="Thursday PM" <?php selected($delivery_day, 'Thursday PM'); ?>>Thursday PM</option>
                        <option value="Friday AM" <?php selected($delivery_day, 'Friday AM'); ?>>Friday AM</option>
                        <option value="Friday PM" <?php selected($delivery_day, 'Friday PM'); ?>>Friday PM</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="delivery_initials">Initials for delivery *</label></th>
                <td><input type="text" name="delivery_initials" required class="regular-text" value="<?php echo esc_attr($form_values['delivery_initials'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="delivery_area_name">Delivery Area *</label></th>
                <td><input type="text" name="delivery_area_name" required class="regular-text" value="<?php echo esc_attr($form_values['delivery_area_name'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="delivery_area_zone">Delivery Area Zone</label></th>
                <td><input type="text" name="delivery_area_zone" class="regular-text" value="<?php echo esc_attr($form_values['delivery_area_zone'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="ordering_frequency">Ordering Frequency *</label></th>
                <td><input type="text" name="ordering_frequency" required class="regular-text" value="<?php echo esc_attr($form_values['ordering_frequency'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="ordering_contact_method">Ordering Contact Method *</label></th>
                <td>
                    <?php $ordering_contact_method = $form_values['ordering_contact_method'] ?? ''; ?>
                    <select name="ordering_contact_method" required>
                        <option value="" <?php selected($ordering_contact_method, ''); ?>>Select…</option>
                        <option value="Phone" <?php selected($ordering_contact_method, 'Phone'); ?>>Phone</option>
                        <option value="Bulk Email" <?php selected($ordering_contact_method, 'Bulk Email'); ?>>Bulk Email</option>
                        <option value="Auto-Renew" <?php selected($ordering_contact_method, 'Auto-Renew'); ?>>Auto-Renew</option>
                        <option value="Client Email" <?php selected($ordering_contact_method, 'Client Email'); ?>>Client Email</option>
                        <option value="Client Call" <?php selected($ordering_contact_method, 'Client Call'); ?>>Client Call</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="delivery_frequency">Delivery Frequency *</label></th>
                <td><input type="text" name="delivery_frequency" required class="regular-text" value="<?php echo esc_attr($form_values['delivery_frequency'] ?? ''); ?>" /></td>
            </tr>
        </table>

        <h3>Notes</h3>
        <table class="form-table">
            <tr>
                <th><label for="diet_concerns">Diet Concerns</label></th>
                <td><textarea name="diet_concerns" rows="4" class="large-text"><?php echo esc_textarea($form_values['diet_concerns'] ?? ''); ?></textarea></td>
            </tr>
            <tr>
                <th><label for="client_comments">Customer Comments</label></th>
                <td><textarea name="client_comments" rows="4" class="large-text"><?php echo esc_textarea($form_values['client_comments'] ?? ''); ?></textarea></td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button-primary">Submit</button>
            <button type="button" id="mealsdb-save-draft" class="button-secondary">Save to Draft</button>
        </p>
    </form>
</div>

