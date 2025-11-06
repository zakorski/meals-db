<?php
$selected_type = strtoupper($_POST['customer_type'] ?? '');
$staff_selected = ($selected_type === 'STAFF');
?>
<tr>
    <th><label for="first_name">First Name *</label></th>
    <td><input type="text" name="first_name" required class="regular-text" value="<?= esc_attr($_POST['first_name'] ?? '') ?>" /></td>
</tr>
<tr>
    <th><label for="last_name">Last Name *</label></th>
    <td><input type="text" name="last_name" required class="regular-text" value="<?= esc_attr($_POST['last_name'] ?? '') ?>" /></td>
</tr>
<tr>
    <th><label for="client_email">Email *</label></th>
    <td><input type="email" name="client_email" required class="regular-text" value="<?= esc_attr($_POST['client_email'] ?? '') ?>" /></td>
</tr>
<?php if (!$staff_selected) : ?>
<tr>
    <th>
        <label for="wordpress_user_id">
            <?php esc_html_e('WordPress User ID', 'meals-db'); ?>
        </label>
    </th>
    <td>
        <input type="number" name="wordpress_user_id" min="1" step="1" class="regular-text" value="<?= esc_attr($_POST['wordpress_user_id'] ?? '') ?>" />
    </td>
</tr>
<tr>
    <th><label for="phone_primary">Phone #1 *</label></th>
    <td><input type="text" name="phone_primary" required placeholder="(555)-555-5555" class="regular-text phone-mask" value="<?= esc_attr($_POST['phone_primary'] ?? '') ?>" /></td>
</tr>
<tr>
    <th><label for="address_postal"><?php esc_html_e('Postal Code', 'meals-db'); ?> *</label></th>
    <td><input type="text" name="address_postal" required placeholder="A1A1A1" maxlength="6" class="regular-text postal-mask" value="<?= esc_attr($_POST['address_postal'] ?? '') ?>" /></td>
</tr>
<?php endif; ?>
<tr>
    <th><label for="customer_type">Customer Type *</label></th>
    <td>
        <select name="customer_type" required>
            <option value="">Select...</option>
            <option value="SDNB" <?= selected($_POST['customer_type'] ?? '', 'SDNB') ?>>SDNB</option>
            <option value="Veteran" <?= selected($_POST['customer_type'] ?? '', 'Veteran') ?>>Veteran</option>
            <option value="Private" <?= selected($_POST['customer_type'] ?? '', 'Private') ?>>Private</option>
            <option value="Staff" <?= selected($_POST['customer_type'] ?? '', 'Staff') ?>><?php esc_html_e('Staff', 'meals-db'); ?></option>
        </select>
    </td>
</tr>
<?php if (!$staff_selected) : ?>
<tr>
    <th><label for="birth_date">Date of Birth</label></th>
    <td><input type="text" name="birth_date" class="mealsdb-datepicker" placeholder="YYYY-MM-DD" value="<?= esc_attr($_POST['birth_date'] ?? '') ?>" /></td>
</tr>
<?php endif; ?>
