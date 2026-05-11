<?php
defined('ABSPATH') || exit;

$mealsdb_posted = [
    'first_name'        => isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '',
    'last_name'         => isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '',
    'client_email'      => isset($_POST['client_email']) ? sanitize_email(wp_unslash($_POST['client_email'])) : '',
    'wordpress_user_id' => isset($_POST['wordpress_user_id']) ? absint($_POST['wordpress_user_id']) : '',
    'phone_primary'     => isset($_POST['phone_primary']) ? sanitize_text_field(wp_unslash($_POST['phone_primary'])) : '',
    'address_postal'    => isset($_POST['address_postal']) ? sanitize_text_field(wp_unslash($_POST['address_postal'])) : '',
    'client_type'       => isset($_POST['client_type']) ? sanitize_text_field(wp_unslash($_POST['client_type'])) : '',
    'birth_date'        => isset($_POST['birth_date']) ? sanitize_text_field(wp_unslash($_POST['birth_date'])) : '',
];
?>
<tr>
    <th><label for="first_name">First Name *</label></th>
    <td><input type="text" name="first_name" required class="regular-text" value="<?= esc_attr($mealsdb_posted['first_name']) ?>" /></td>
</tr>
<tr>
    <th><label for="last_name">Last Name *</label></th>
    <td><input type="text" name="last_name" required class="regular-text" value="<?= esc_attr($mealsdb_posted['last_name']) ?>" /></td>
</tr>
<tr>
    <th><label for="client_email">Email *</label></th>
    <td><input type="email" name="client_email" required class="regular-text" value="<?= esc_attr($mealsdb_posted['client_email']) ?>" /></td>
</tr>
<tr>
    <th>
        <label for="wordpress_user_id">
            <?php esc_html_e('WordPress User ID', 'meals-db'); ?>
        </label>
    </th>
    <td>
        <input type="number" name="wordpress_user_id" min="1" step="1" class="regular-text" value="<?= esc_attr($mealsdb_posted['wordpress_user_id']) ?>" />
    </td>
</tr>
<tr>
    <th><label for="phone_primary">Phone #1 *</label></th>
    <td><input type="text" name="phone_primary" required placeholder="(555)-555-5555" class="regular-text phone-mask" value="<?= esc_attr($mealsdb_posted['phone_primary']) ?>" /></td>
</tr>
<tr>
    <th><label for="address_postal"><?php esc_html_e('Postal Code', 'meals-db'); ?> *</label></th>
    <td><input type="text" name="address_postal" required placeholder="A1A1A1" maxlength="6" class="regular-text postal-mask" value="<?= esc_attr($mealsdb_posted['address_postal']) ?>" /></td>
</tr>
<tr>
    <th><label for="client_type">Client Type *</label></th>
    <td>
        <select name="client_type" required>
            <option value="">Select...</option>
            <option value="SDNB" <?= selected($mealsdb_posted['client_type'], 'SDNB') ?>>SDNB</option>
            <option value="Veteran" <?= selected($mealsdb_posted['client_type'], 'Veteran') ?>>Veteran</option>
            <option value="Private" <?= selected($mealsdb_posted['client_type'], 'Private') ?>>Private</option>
        </select>
    </td>
</tr>
<tr>
    <th><label for="birth_date">Date of Birth</label></th>
    <td><input type="date" name="birth_date" class="mealsdb-datepicker" value="<?= esc_attr($mealsdb_posted['birth_date']) ?>" /></td>
</tr>
