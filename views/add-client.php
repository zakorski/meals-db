<?php
MealsDB_Permissions::enforce();

// On submission, validate and save
$errors = [];
$success = false;
$form_values = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_admin_referer('mealsdb_nonce', 'mealsdb_nonce_field');

    $is_resume = isset($_POST['resume_draft']) && $_POST['resume_draft'] === '1';

    $form_values = MealsDB_Client_Form::prepare_form_defaults($_POST);

    if (!$is_resume) {
        $validation = MealsDB_Client_Form::validate($_POST);
        $form_values = $validation['sanitized'] ?? $form_values;

        if ($validation['valid']) {
            $saved = MealsDB_Client_Form::save($_POST);
            if ($saved) {
                $success = true;
                $form_values = [];
            } else {
                $errors[] = 'Database error occurred.';
            }
        } else {
            $errors = $validation['errors'];
            if (!MealsDB_Client_Form::save_draft($form_values)) { // fallback save
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
                <th><label for="client_email">Email *</label></th>
                <td><input type="email" name="client_email" required class="regular-text" value="<?php echo esc_attr($form_values['client_email'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="phone_primary">Phone #1 *</label></th>
                <td><input type="text" name="phone_primary" required placeholder="(555)-555-5555" class="regular-text phone-mask" value="<?php echo esc_attr($form_values['phone_primary'] ?? ''); ?>" /></td>
            </tr>
            <tr>
                <th><label for="address_postal">Postal Code *</label></th>
                <td><input type="text" name="address_postal" required placeholder="A1A1A1" class="regular-text postal-mask" value="<?php echo esc_attr($form_values['address_postal'] ?? ''); ?>" /></td>
            </tr>

            <tr>
                <th><label for="customer_type">Customer Type *</label></th>
                <td>
                    <select name="customer_type" required>
                        <?php $selected_type = $form_values['customer_type'] ?? ''; ?>
                        <option value="" <?php selected($selected_type, ''); ?>>Select...</option>
                        <option value="SDNB" <?php selected($selected_type, 'SDNB'); ?>>SDNB</option>
                        <option value="Vet" <?php selected($selected_type, 'Vet'); ?>>Vet</option>
                        <option value="Private" <?php selected($selected_type, 'Private'); ?>>Private</option>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label for="birth_date">Date of Birth</label></th>
                <td><input type="text" name="birth_date" class="mealsdb-datepicker" placeholder="YYYY-MM-DD" value="<?php echo esc_attr($form_values['birth_date'] ?? ''); ?>" /></td>
            </tr>

            <!-- Additional required dropdowns can be added here similarly -->

        </table>

        <p class="submit">
            <button type="submit" class="button-primary">Submit</button>
            <button type="button" id="mealsdb-save-draft" class="button-secondary">Save to Draft</button>
        </p>
    </form>
</div>

