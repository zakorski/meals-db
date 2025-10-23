php if (!empty($success)) 
    div class=notice notice-success is-dismissible
        p= esc_html($success) p
    div
php elseif (!empty($errors)) 
    div class=notice notice-error
        pstrongForm errorsstrongp
        ul
            php foreach ($errors as $error) 
                li= esc_html($error) li
            php endforeach; 
        ul
    div
php endif; 
