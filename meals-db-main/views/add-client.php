<?php
MealsDB_Permissions::enforce();

// On submission, validate and save
$errors = [];
$success = false;
$form_values = [];
$resumed_draft_id = 0;
$draft_saved_notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_admin_referer('mealsdb_nonce', 'mealsdb_nonce_field');

    $is_resume = isset($_POST['resume_draft']) && $_POST['resume_draft'] === '1';
    $resumed_draft_id = isset($_POST['draft_id']) ? intval($_POST['draft_id']) : 0;

    $form_values = MealsDB_Client_Form::prepare_form_defaults($_POST);

    if (!$is_resume) {
        $validation = MealsDB_Client_Form::validate($_POST);
        $form_values = $validation['sanitized'] ?? $form_values;

        $persist_failed_submission = function () use (&$form_values, &$resumed_draft_id, &$errors, &$draft_saved_notice) {
            $draft_id_for_retry = $resumed_draft_id > 0 ? $resumed_draft_id : null;
            $draft_saved_id = MealsDB_Client_Form::save_draft($form_values, $draft_id_for_retry);

            if ($draft_saved_id === false) {
                $errors[] = 'Unable to save draft copy. Please try again or contact an administrator.';
                return;
            }

            $saved_id = intval($draft_saved_id);
            if ($saved_id > 0) {
                $resumed_draft_id = $saved_id;
            }
            $draft_saved_notice = sprintf(
                'Your progress was saved as draft #%d so you can finish later. Review the issues below to complete the submission.',
                $resumed_draft_id
            );
        };

        if ($validation['valid']) {
            $saved = MealsDB_Client_Form::save($_POST);
            if ($saved) {
                $success = true;
                $form_values = [];
                $resumed_draft_id = 0;
            } else {
                $errors[] = 'Database error occurred.';
                $persist_failed_submission();
            }
        } else {
            $errors = $validation['errors'];
            $persist_failed_submission();

            if (!empty($validation['error_summary'])) {
                array_unshift($errors, $validation['error_summary']);
            }
        }

        if (!empty($draft_saved_notice)) {
            array_unshift($errors, $draft_saved_notice);
        }
    }
}

$form_mode = 'add';
include __DIR__ . '/partials/client-form.php';
