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
<tr>
    <th><label for="phone_primary">Phone #1 *</label></th>
    <td><input type="text" name="phone_primary" required placeholder="(555)-555-5555" class="regular-text phone-mask" value="<?= esc_attr($_POST['phone_primary'] ?? '') ?>" /></td>
</tr>
<tr>
    <th><label for="address_postal">Postal Code *</label></th>
    <td><input type="text" name="address_postal" required placeholder="A1A1A1" class="regular-text postal-mask" value="<?= esc_attr($_POST['address_postal'] ?? '') ?>" /></td>
</tr>
<tr>
    <th><label for="customer_type">Customer Type *</label></th>
    <td>
        <select name="customer_type" required>
            <option value="">Select...</option>
            <option value="SDNB" <?= selected($_POST['customer_type'] ?? '', 'SDNB') ?>>SDNB</option>
            <option value="Vet" <?= selected($_POST['customer_type'] ?? '', 'Vet') ?>>Vet</option>
            <option value="Private" <?= selected($_POST['customer_type'] ?? '', 'Private') ?>>Private</option>
        </select>
    </td>
</tr>
<tr>
    <th><label for="birth_date">Date of Birth</label></th>
    <td><input type="text" name="birth_date" class="mealsdb-datepicker" placeholder="YYYY-MM-DD" value="<?= esc_attr($_POST['birth_date'] ?? '') ?>" /></td>
</tr>
