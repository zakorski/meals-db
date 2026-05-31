<?php
defined('ABSPATH') || exit;

if (!empty($success)) :
    $success_message = is_string($success)
        ? $success
        : __('Client saved successfully.', 'meals-db');
    ?>
    <div class="notice notice-success is-dismissible">
        <p><?php echo esc_html($success_message); ?></p>
    </div>
<?php elseif (!empty($errors)) :
    $errors_list = is_array($errors) ? $errors : array($errors);
    ?>
    <div class="notice notice-error">
        <p><strong><?php echo esc_html__('Errors:', 'meals-db'); ?></strong></p>
        <ul>
            <?php foreach ($errors_list as $error) : ?>
                <li><?php echo esc_html($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif;

// Non-blocking warnings (directive GUI-SAVE-INDEX Part B): a duplicate
// individual_id / requisition_id is a legitimate dual-program enrollment, so it
// is surfaced — naming the other client — alongside any success/error notice
// rather than blocking the save.
if (!empty($warnings)) :
    $warnings_list = is_array($warnings) ? $warnings : array($warnings);
    ?>
    <div class="notice notice-warning">
        <p><strong><?php echo esc_html__('Please confirm:', 'meals-db'); ?></strong></p>
        <ul>
            <?php foreach ($warnings_list as $warning) : ?>
                <li><?php echo esc_html($warning); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif;
