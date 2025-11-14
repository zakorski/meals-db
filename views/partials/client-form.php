<?php
$form_mode = isset($form_mode) ? $form_mode : 'add';
$form_heading = $form_mode === 'edit' ? __('Edit Client', 'meals-db') : __('Add New Client', 'meals-db');
$submit_label = $form_mode === 'edit' ? __('Update Client', 'meals-db') : __('Submit', 'meals-db');
$show_draft_button = ($form_mode === 'add');
$resumed_draft_id = isset($resumed_draft_id) ? intval($resumed_draft_id) : 0;
$client_id = isset($client_id) ? intval($client_id) : 0;
$form_values = isset($form_values) && is_array($form_values) ? $form_values : [];
?>

<div class="wrap mealsdb-client-form-wrap">
    <h2><?php echo esc_html($form_heading); ?></h2>

    <?php include __DIR__ . '/status-notice.php'; ?>

    <?php
    MealsDB_Admin_UI::render_client_form([
        'form_mode'         => $form_mode,
        'submit_label'      => $submit_label,
        'show_draft_button' => $show_draft_button,
        'resumed_draft_id'  => $resumed_draft_id,
        'client_id'         => $client_id,
        'form_values'       => $form_values,
    ]);
    ?>
</div>
