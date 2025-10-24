<?php
MealsDB_Permissions::enforce();

// On submission, validate and save
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_admin_referer('mealsdb_nonce', 'mealsdb_nonce_field');

    $form_data = $_POST;
    $validation = MealsDB_Client_Form::validate($form_data);

    if ($validation['valid']) {
        $saved = MealsDB_Client_Form::save($form_data);
        if ($saved) {
            $success = true;
        } else {
            $errors[] = 'Database error occurred.';
        }
    } else {
        $errors = $validation['errors'];
        MealsDB_Client_Form::save_draft($form_data); // fallback save
    }
}
?>

<div class="wrap">
    <h2>Add New Client</h2>

    <?php include __DIR__ . '/partials/status-notice.php'; ?>

    <form method="post" id="mealsdb-client-form">
        <?php wp_nonce_field('mealsdb_nonce', 'mealsdb_nonce_field'); ?>

        <table class="form-table">
            <tr>
                <th><label for="first_name">First Name *</label></th>
                <td><input type="text" name="first_name" required class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="last_name">Last Name *</label></th>
                <td><input type="text" name="last_name" required class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="client_email">Email *</label></th>
                <td><input type="email" name="client_email" required class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="phone_primary">Phone #1 *</label></th>
                <td><input type="text" name="phone_primary" required placeholder="(555)-555-5555" class="regular-text phone-mask" /></td>
            </tr>
            <tr>
                <th><label for="address_postal">Postal Code *</label></th>
                <td><input type="text" name="address_postal" required placeholder="A1A1A1" class="regular-text postal-mask" /></td>
            </tr>

            <tr>
                <th><label for="customer_type">Customer Type *</label></th>
                <td>
                    <select name="customer_type" required>
                        <option value="">Select...</option>
                        <option value="SDNB">SDNB</option>
                        <option value="Vet">Vet</option>
                        <option value="Private">Private</option>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label for="birth_date">Date of Birth</label></th>
                <td><input type="text" name="birth_date" class="mealsdb-datepicker" placeholder="YYYY-MM-DD" /></td>
            </tr>

            <!-- Additional required dropdowns can be added here similarly -->

        </table>

        <p class="submit">
            <button type="submit" class="button-primary">Submit</button>
            <button type="button" id="mealsdb-save-draft" class="button-secondary">Save to Draft</button>
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Mask phone and postal
    $('.phone-mask').on('input', function() {
        this.value = this.value.replace(/[^\d]/g, '')
            .replace(/(\d{3})(\d{3})(\d{4})/, '($1)-$2-$3')
            .substr(0, 14);
    });

    $('.postal-mask').on('input', function() {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '').replace(/(.{3})(.{3})/, '$1 $2').substr(0, 7);
    });

    $('.mealsdb-datepicker').datepicker({ dateFormat: 'yy-mm-dd' });

    $('#mealsdb-save-draft').on('click', function() {
        let formData = $('#mealsdb-client-form').serialize();

        $.post(ajaxurl, {
            action: 'mealsdb_save_draft',
            nonce: '<?php echo wp_create_nonce("mealsdb_nonce"); ?>',
            form_data: formData
        }, function(response) {
            if (response.success) {
                alert(response.data.message);
            } else {
                alert('Draft save failed.');
            }
        });
    });
});
</script>
