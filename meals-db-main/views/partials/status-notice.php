<?php
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
