<?php
MealsDB_Permissions::enforce();

$errors = [];
$success = false;
$form_values = [];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = isset($_POST['client_id']) ? intval($_POST['client_id']) : $client_id;
}

if ($client_id <= 0) {
    echo '<div class="wrap"><h2>' . esc_html__('Edit Client', 'meals-db') . '</h2><p>' . esc_html__('Invalid client specified.', 'meals-db') . '</p></div>';
    return;
}

$existing_record = MealsDB_Client_Form::load_client($client_id);
if (!is_array($existing_record) || empty($existing_record)) {
    echo '<div class="wrap"><h2>' . esc_html__('Edit Client', 'meals-db') . '</h2><p>' . esc_html__('Client not found or may have been removed.', 'meals-db') . '</p></div>';
    return;
}

$form_values = $existing_record;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_admin_referer('mealsdb_nonce', 'mealsdb_nonce_field');

    $form_values = MealsDB_Client_Form::prepare_form_defaults($_POST);
    $validation = MealsDB_Client_Form::validate($_POST, $client_id);
    $form_values = $validation['sanitized'] ?? $form_values;

    if ($validation['valid']) {
        $updated = MealsDB_Client_Form::update($client_id, $_POST);
        if ($updated) {
            $success = __('Client updated successfully.', 'meals-db');
            $refreshed = MealsDB_Client_Form::load_client($client_id);
            if (is_array($refreshed) && !empty($refreshed)) {
                $form_values = $refreshed;
            }
        } else {
            $errors[] = __('Database error occurred.', 'meals-db');
        }
    } else {
        $errors = $validation['errors'];
        if (!empty($validation['error_summary'])) {
            array_unshift($errors, $validation['error_summary']);
        }
    }
}

$form_mode = 'edit';
include __DIR__ . '/partials/client-form.php';
