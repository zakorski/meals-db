<?php
/**
 * Central bootstrap for Meals DB AJAX handlers.
 *
 * Author: Fishhorn Design
 * Author URI: https://fishhorn.ca
 * Licensed under the GNU General Public License v3.0 or later.
 */

require_once __DIR__ . '/ajax/class-ajax-sync.php';
require_once __DIR__ . '/ajax/class-ajax-drafts.php';
require_once __DIR__ . '/ajax/class-ajax-clients.php';
require_once __DIR__ . '/ajax/class-ajax-staff.php';
require_once __DIR__ . '/ajax/class-ajax-initials.php';

class MealsDB_Ajax {

    /**
     * Instantiate all AJAX handlers.
     */
    public static function init(): void {
        new MealsDB_Ajax_Sync();
        new MealsDB_Ajax_Drafts();
        new MealsDB_Ajax_Clients();
        new MealsDB_Ajax_Staff();
        new MealsDB_Ajax_Initials();
    }
}
