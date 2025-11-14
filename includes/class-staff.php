<?php
/**
 * Admin UI for managing staff directory records.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Staff {

    /**
     * Hook admin actions for the staff directory screens.
     */
    public static function init(): void {
        add_action('admin_post_mealsdb_save_staff', [__CLASS__, 'save_staff']);
    }

    /**
     * Render the staff directory admin page.
     */
    public static function render_admin_page(): void {
        MealsDB_Permissions::enforce();

        $action = $_GET['action'] ?? '';
        if (function_exists('wp_unslash')) {
            $action = wp_unslash($action);
        }
        if (function_exists('sanitize_key')) {
            $action = sanitize_key($action);
        } else {
            $action = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $action));
        }

        if ($action === 'add' || $action === 'edit') {
            self::render_staff_form($action);
            return;
        }

        self::render_staff_list();
    }

    /**
     * Display a table of all staff members.
     */
    public static function render_staff_list(): void {
        $staff_members = self::get_all_staff();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Staff Directory', 'meals-db') . '</h1>';
        echo ' <a href="' . esc_url(self::get_add_url()) . '" class="page-title-action">' . esc_html__('Add Staff Member', 'meals-db') . '</a>';
        echo '<hr class="wp-header-end" />';

        self::render_notices();

        if (empty($staff_members)) {
            echo '<p>' . esc_html__('No staff members found.', 'meals-db') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Name', 'meals-db') . '</th>';
        echo '<th scope="col">' . esc_html__('Email', 'meals-db') . '</th>';
        echo '<th scope="col">' . esc_html__('Phone', 'meals-db') . '</th>';
        echo '<th scope="col">' . esc_html__('WordPress User', 'meals-db') . '</th>';
        echo '<th scope="col" class="column-actions">' . esc_html__('Actions', 'meals-db') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($staff_members as $staff) {
            $name = trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));
            $email = $staff['email'] ?? '';
            $phone = $staff['phone'] ?? '';
            $user_display = '';

            if (!empty($staff['wordpress_user_id'])) {
                $user = get_user_by('id', (int) $staff['wordpress_user_id']);
                if ($user instanceof WP_User) {
                    $user_display = $user->display_name . ' (' . $user->user_login . ')';
                } else {
                    $user_display = sprintf(__('User #%d', 'meals-db'), (int) $staff['wordpress_user_id']);
                }
            } else {
                $user_display = __('—', 'meals-db');
            }

            $edit_url = add_query_arg(
                [
                    'page'      => 'meals-db-staff',
                    'action'    => 'edit',
                    'staff_id'  => (int) $staff['id'],
                ],
                admin_url('admin.php')
            );

            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            if ($email !== '') {
                echo '<td><a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a></td>';
            } else {
                echo '<td>' . esc_html__('—', 'meals-db') . '</td>';
            }
            $phone_display = $phone !== '' ? $phone : __('—', 'meals-db');
            echo '<td>' . esc_html($phone_display) . '</td>';
            echo '<td>' . esc_html($user_display) . '</td>';
            echo '<td><a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'meals-db') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    /**
     * Render the add/edit staff form.
     *
     * @param string $action Either 'add' or 'edit'.
     */
    public static function render_staff_form(string $action = 'add'): void {
        $staff_id = isset($_GET['staff_id']) ? absint($_GET['staff_id']) : 0;
        $staff = null;

        if ($action === 'edit') {
            if ($staff_id <= 0) {
                self::add_notice('error', __('Invalid staff member specified.', 'meals-db'));
                wp_safe_redirect(self::get_list_url());
                exit;
            }

            $staff = self::get_staff_member($staff_id);
            if ($staff === null) {
                self::add_notice('error', __('The requested staff member could not be found.', 'meals-db'));
                wp_safe_redirect(self::get_list_url());
                exit;
            }
        }

        $old_input = self::pull_old_input();

        $values = [
            'staff_id'           => $staff['id'] ?? $staff_id,
            'first_name'         => $old_input['first_name'] ?? $staff['first_name'] ?? '',
            'last_name'          => $old_input['last_name'] ?? $staff['last_name'] ?? '',
            'email'              => $old_input['email'] ?? $staff['email'] ?? '',
            'phone'              => $old_input['phone'] ?? $staff['phone'] ?? '',
            'wordpress_user_id'  => $old_input['wordpress_user_id'] ?? $staff['wordpress_user_id'] ?? '',
        ];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($action === 'edit' ? __('Edit Staff Member', 'meals-db') : __('Add Staff Member', 'meals-db')) . '</h1>';

        self::render_notices();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mealsdb_save_staff', 'mealsdb_staff_nonce');
        echo '<input type="hidden" name="action" value="mealsdb_save_staff" />';
        echo '<input type="hidden" name="staff_id" value="' . esc_attr((int) $values['staff_id']) . '" />';
        echo '<input type="hidden" name="mode" value="' . esc_attr($action) . '" />';

        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';

        self::render_text_input_row('first_name', __('First Name', 'meals-db'), $values['first_name'], true);
        self::render_text_input_row('last_name', __('Last Name', 'meals-db'), $values['last_name'], true);
        self::render_text_input_row('email', __('Email Address', 'meals-db'), $values['email'], true, 'email');
        self::render_text_input_row('phone', __('Phone Number', 'meals-db'), $values['phone'], false);

        echo '<tr>';
        echo '<th scope="row"><label for="wordpress_user_id">' . esc_html__('WordPress User', 'meals-db') . '</label></th>';
        echo '<td>';
        wp_dropdown_users([
            'name'              => 'wordpress_user_id',
            'show_option_none'  => __('— None —', 'meals-db'),
            'selected'          => $values['wordpress_user_id'] !== '' ? (int) $values['wordpress_user_id'] : 0,
            'include_selected'  => true,
            'class'             => 'regular-text',
        ]);
        echo '<p class="description">' . esc_html__('Associate this staff member with an optional WordPress user account.', 'meals-db') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '</tbody>';
        echo '</table>';

        submit_button($action === 'edit' ? __('Update Staff Member', 'meals-db') : __('Add Staff Member', 'meals-db'));

        echo '</form>';
        echo '</div>';
    }

    /**
     * Process add/edit staff submissions.
     */
    public static function save_staff(): void {
        MealsDB_Permissions::enforce();

        check_admin_referer('mealsdb_save_staff', 'mealsdb_staff_nonce');

        $mode = $_POST['mode'] ?? 'add';
        if (function_exists('wp_unslash')) {
            $mode = wp_unslash($mode);
        }
        if (function_exists('sanitize_key')) {
            $mode = sanitize_key($mode);
        } else {
            $mode = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $mode));
        }

        $staff_id = isset($_POST['staff_id']) ? absint($_POST['staff_id']) : 0;

        $first_name = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $last_name = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $wordpress_user_id = isset($_POST['wordpress_user_id']) ? absint($_POST['wordpress_user_id']) : 0;

        $form_data = [
            'first_name'        => $first_name,
            'last_name'         => $last_name,
            'email'             => $email,
            'phone'             => $phone,
            'wordpress_user_id' => $wordpress_user_id,
        ];

        if ($first_name === '' || $last_name === '' || $email === '') {
            self::set_old_input($form_data + ['staff_id' => $staff_id]);
            self::add_notice('error', __('First name, last name, and email are required.', 'meals-db'));
            wp_safe_redirect(self::get_form_redirect_url($mode, $staff_id));
            exit;
        }

        if (!is_email($email)) {
            self::set_old_input($form_data + ['staff_id' => $staff_id]);
            self::add_notice('error', __('Please provide a valid email address.', 'meals-db'));
            wp_safe_redirect(self::get_form_redirect_url($mode, $staff_id));
            exit;
        }

        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            self::set_old_input($form_data + ['staff_id' => $staff_id]);
            self::add_notice('error', __('Unable to connect to the Meals DB database.', 'meals-db'));
            wp_safe_redirect(self::get_form_redirect_url($mode, $staff_id));
            exit;
        }

        if ($mode === 'edit') {
            if ($staff_id <= 0) {
                self::set_old_input($form_data + ['staff_id' => $staff_id]);
                self::add_notice('error', __('Invalid staff member specified for update.', 'meals-db'));
                wp_safe_redirect(self::get_form_redirect_url('edit', $staff_id));
                exit;
            }

            $sql = 'UPDATE meals_staff SET first_name = ?, last_name = ?, email = ?, phone = NULLIF(?, \'\'), wordpress_user_id = NULLIF(?, 0) WHERE id = ?';
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                self::set_old_input($form_data + ['staff_id' => $staff_id]);
                self::add_notice('error', __('Failed to prepare staff update query.', 'meals-db'));
                wp_safe_redirect(self::get_form_redirect_url('edit', $staff_id));
                exit;
            }

            $phone_param = $phone;
            $user_param = $wordpress_user_id;
            $stmt->bind_param('ssssii', $first_name, $last_name, $email, $phone_param, $user_param, $staff_id);
        } else {
            $sql = 'INSERT INTO meals_staff (first_name, last_name, email, phone, wordpress_user_id) VALUES (?, ?, ?, NULLIF(?, \'\'), NULLIF(?, 0))';
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                self::set_old_input($form_data + ['staff_id' => $staff_id]);
                self::add_notice('error', __('Failed to prepare staff insert query.', 'meals-db'));
                wp_safe_redirect(self::get_form_redirect_url('add', $staff_id));
                exit;
            }

            $phone_param = $phone;
            $user_param = $wordpress_user_id;
            $stmt->bind_param('ssssi', $first_name, $last_name, $email, $phone_param, $user_param);
        }

        if (!$stmt->execute()) {
            self::set_old_input($form_data + ['staff_id' => $staff_id]);
            self::add_notice('error', __('Failed to save the staff member. Please try again.', 'meals-db'));
            $stmt->close();
            wp_safe_redirect(self::get_form_redirect_url($mode, $staff_id));
            exit;
        }

        $stmt->close();

        self::add_notice('success', $mode === 'edit' ? __('Staff member updated successfully.', 'meals-db') : __('Staff member added successfully.', 'meals-db'));
        wp_safe_redirect(self::get_list_url());
        exit;
    }

    /**
     * Retrieve all staff records.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function get_all_staff(): array {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            self::add_notice('error', __('Unable to connect to the Meals DB database.', 'meals-db'));
            return [];
        }

        $sql = 'SELECT id, first_name, last_name, email, phone, wordpress_user_id FROM meals_staff ORDER BY last_name ASC, first_name ASC';
        $result = $conn->query($sql);
        if (!$result instanceof mysqli_result) {
            self::add_notice('error', __('Failed to load staff records.', 'meals-db'));
            return [];
        }

        $staff = [];
        while ($row = $result->fetch_assoc()) {
            $staff[] = $row;
        }
        $result->free();

        return $staff;
    }

    /**
     * Fetch a single staff member by ID.
     */
    private static function get_staff_member(int $staff_id): ?array {
        $conn = MealsDB_DB::get_connection();
        if (!$conn) {
            return null;
        }

        $stmt = $conn->prepare('SELECT id, first_name, last_name, email, phone, wordpress_user_id FROM meals_staff WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $staff_id);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }

        $result = $stmt->get_result();
        $record = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();

        return $record ?: null;
    }

    /**
     * Render an admin table row for a text input field.
     */
    private static function render_text_input_row(string $field, string $label, string $value, bool $required = false, string $type = 'text'): void {
        echo '<tr>';
        echo '<th scope="row"><label for="' . esc_attr($field) . '">' . esc_html($label);
        if ($required) {
            echo ' <span class="description">(' . esc_html__('required', 'meals-db') . ')</span>';
        }
        echo '</label></th>';
        echo '<td>';
        echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($field) . '" id="' . esc_attr($field) . '" value="' . esc_attr($value) . '" class="regular-text"' . ($required ? ' required' : '') . ' />';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Output any stored notices.
     */
    private static function render_notices(): void {
        $notices = self::pull_notices();
        if (empty($notices)) {
            return;
        }

        foreach ($notices as $notice) {
            $class = $notice['type'] === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($notice['message']) . '</p></div>';
        }
    }

    /**
     * Store a notice to show on the next page load.
     */
    private static function add_notice(string $type, string $message): void {
        $key = self::get_notice_key();
        $notices = get_transient($key);
        if (!is_array($notices)) {
            $notices = [];
        }
        $notices[] = [
            'type'    => $type,
            'message' => $message,
        ];
        set_transient($key, $notices, 30);
    }

    /**
     * Retrieve and clear stored notices.
     *
     * @return array<int, array<string, string>>
     */
    private static function pull_notices(): array {
        $key = self::get_notice_key();
        $notices = get_transient($key);
        if (!is_array($notices)) {
            return [];
        }
        delete_transient($key);
        return $notices;
    }

    /**
     * Remember old form input between redirects.
     */
    private static function set_old_input(array $data): void {
        $key = self::get_old_input_key();
        set_transient($key, $data, 30);
    }

    /**
     * Retrieve previously stored form input.
     *
     * @return array<string, mixed>
     */
    private static function pull_old_input(): array {
        $key = self::get_old_input_key();
        $data = get_transient($key);
        if (!is_array($data)) {
            return [];
        }
        delete_transient($key);
        return $data;
    }

    private static function get_notice_key(): string {
        return 'mealsdb_staff_notice_' . get_current_user_id();
    }

    private static function get_old_input_key(): string {
        return 'mealsdb_staff_old_' . get_current_user_id();
    }

    private static function get_add_url(): string {
        return add_query_arg(
            [
                'page'   => 'meals-db-staff',
                'action' => 'add',
            ],
            admin_url('admin.php')
        );
    }

    private static function get_list_url(): string {
        return add_query_arg(['page' => 'meals-db-staff'], admin_url('admin.php'));
    }

    private static function get_form_redirect_url(string $mode, int $staff_id): string {
        $args = [
            'page'   => 'meals-db-staff',
            'action' => $mode === 'edit' ? 'edit' : 'add',
        ];

        if ($mode === 'edit' && $staff_id > 0) {
            $args['staff_id'] = $staff_id;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }
}
