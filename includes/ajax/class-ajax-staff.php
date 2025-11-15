<?php
/**
 * AJAX handlers for staff directory management.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

class MealsDB_Ajax_Staff {

    public function __construct() {
        add_action('wp_ajax_mealsdb_add_staff', [$this, 'add_staff']);
        add_action('wp_ajax_mealsdb_update_staff', [$this, 'update_staff']);
        add_action('wp_ajax_mealsdb_deactivate_staff', [$this, 'deactivate_staff']);
    }

    public function add_staff(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        $this->deny_unimplemented();
    }

    public function update_staff(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        $this->deny_unimplemented();
    }

    public function deactivate_staff(): void {
        check_ajax_referer('mealsdb_nonce', 'nonce');
        $this->deny_unimplemented();
    }

    private function deny_unimplemented(): void {
        if (!MealsDB_Permissions::can_access_plugin()) {
            wp_send_json_error(['message' => __('Unauthorized', 'meals-db')]);
        }

        wp_send_json_error([
            'message' => __('This staff AJAX endpoint has not been implemented yet.', 'meals-db'),
        ]);
    }
}
